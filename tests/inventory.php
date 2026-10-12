<?php
// Run: C:\xampp\php\php.exe tests/inventory.php
// Uses an isolated, randomly named database, including real foreign keys.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
session_save_path(sys_get_temp_dir());
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/inventory_helper.php';
$db = (new Database())->connect();
if (!$db) throw new RuntimeException('Start MySQL before running inventory checks.');
$checks = 0;
function checkInventory(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function rejectedInventory(callable $fn): bool {
    try { $fn(); } catch (InvalidArgumentException $e) { return true; }
    return false;
}
$schema = [];
foreach (['ingredients', 'inventory_transactions', 'menu_ingredients', 'audit_logs', 'inventory_order_deductions', 'inventory_order_usage'] as $table) {
    $schema[$table] = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
}
checkInventory(str_contains($schema['ingredients'], 'AUTO_INCREMENT') && str_contains($schema['inventory_transactions'], 'AUTO_INCREMENT'), 'Apply the inventory ID migration first.');
$fixture = 'inventory_test_' . bin2hex(random_bytes(6));
$db->exec("CREATE DATABASE `$fixture`");
try {
    $db->exec("USE `$fixture`");
    $db->exec('CREATE TABLE suppliers (supplier_id INT PRIMARY KEY, supplier_name VARCHAR(255)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE staffs (staff_id INT PRIMARY KEY) ENGINE=InnoDB');
    $db->exec('CREATE TABLE staff_sessions (session_id INT AUTO_INCREMENT PRIMARY KEY, staff_id INT, php_session_id VARCHAR(255)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE admins (admin_id INT PRIMARY KEY) ENGINE=InnoDB');
    $db->exec('CREATE TABLE menu_items (item_id INT PRIMARY KEY, item_name VARCHAR(255)) ENGINE=InnoDB');
    foreach ($schema as $ddl) $db->exec($ddl);
    $db->exec("INSERT INTO suppliers VALUES (0, 'Legacy supplier'), (1, 'Test supplier')");
    $db->exec('INSERT INTO staffs VALUES (101)');
    $db->exec('INSERT INTO admins VALUES (101)');
    $db->exec("INSERT INTO menu_items VALUES (1, 'Test recipe')");
    $valid = ['ingredient_name' => '  Test salt  ', 'unit' => 'kg', 'unit_price' => '10.25', 'current_stock' => '6.00', 'reorder_level' => '6.00', 'supplier_id' => '0'];
    inventoryAction($db, 'create_ingredient', $valid);
    $id = (int)$db->query('SELECT ingredient_id FROM ingredients')->fetchColumn();
    inventoryAction($db, 'add_ingredient', $valid + ['cost_per_unit' => '5.50'], 101);
    $second = (int)$db->query('SELECT MAX(ingredient_id) FROM ingredients')->fetchColumn();
    checkInventory($id > 0 && $second > $id, 'Repeated adds generate unique IDs.');
    $row = $db->query("SELECT * FROM ingredients WHERE ingredient_id = $id")->fetch();
    checkInventory($row['ingredient_name'] === 'Test salt' && (int)$row['supplier_id'] === 0, 'Names are trimmed and supplier ID 0 is retained.');
    checkInventory((int)$db->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn() === 2, 'Opening stock is recorded.');
    checkInventory((int)$db->query("SELECT staff_id FROM inventory_transactions WHERE ingredient_id = $second")->fetchColumn() === 101, 'Staff stock history identifies staff.');
    inventoryAction($db, 'edit_ingredient', array_replace($valid, ['ingredient_id' => (string)$id, 'ingredient_name' => 'Sea salt', 'supplier_id' => '', 'unit_price' => '12.50']));
    $row = $db->query("SELECT * FROM ingredients WHERE ingredient_id = $id")->fetch();
    checkInventory($row['ingredient_name'] === 'Sea salt' && $row['supplier_id'] === null && $row['unit_price'] === '12.50', 'Edit saves details and clears supplier.');
    foreach (['8.25', '3.00', '3.00'] as $stock) inventoryAction($db, 'update_stock', ['ingredient_id' => (string)$id, 'new_stock' => $stock]);
    $quantities = $db->query("SELECT quantity FROM inventory_transactions WHERE ingredient_id = $id ORDER BY transaction_id")->fetchAll(PDO::FETCH_COLUMN);
    checkInventory($quantities === ['6.00', '2.25', '-5.25'], 'Adjustments store signed deltas and unchanged stock adds no movement.');

    foreach ([['ingredient_name' => ' '], ['unit' => ''], ['unit_price' => '-1'], ['unit_price' => '1e3'], ['unit_price' => []], ['current_stock' => '1.234'], ['reorder_level' => '100000000'], ['supplier_id' => '999']] as $invalid) {
        checkInventory(rejectedInventory(fn() => inventoryAction($db, 'create_ingredient', array_replace($valid, $invalid))), 'Invalid ingredient rejected: ' . json_encode($invalid));
    }
    foreach (['', '-1', 'bad', '1.234', '100000000', []] as $bad) {
        checkInventory(rejectedInventory(fn() => inventoryAction($db, 'update_stock', ['ingredient_id' => (string)$id, 'new_stock' => $bad])), 'Invalid stock rejected.');
    }
    checkInventory(rejectedInventory(fn() => inventoryAction($db, 'delete_ingredient', [])), 'Missing ID rejected.');
    checkInventory(rejectedInventory(fn() => inventoryAction($db, 'edit_ingredient', $valid + ['ingredient_id' => '999999'])), 'Stale ID rejected.');
    checkInventory((int)$db->query('SELECT COUNT(*) FROM ingredients')->fetchColumn() === 2, 'Validation failures leave inventory unchanged.');
    $db->exec("CREATE TRIGGER inventory_fail_insert BEFORE INSERT ON inventory_transactions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated history failure'");
    foreach (['update_stock', 'create_ingredient'] as $action) {
        $failed = false;
        try { inventoryAction($db, $action, $valid + ['ingredient_id' => (string)$id, 'new_stock' => '9']); } catch (PDOException $e) { $failed = true; }
        checkInventory($failed && !$db->inTransaction(), 'Failed history insert rolls back transaction.');
    }
    checkInventory($db->query("SELECT current_stock FROM ingredients WHERE ingredient_id = $id")->fetchColumn() === '3.00' && (int)$db->query('SELECT COUNT(*) FROM ingredients')->fetchColumn() === 2, 'History failures leave neither partial stock updates nor new ingredients.');
    $db->exec('DROP TRIGGER inventory_fail_insert');
    $db->exec("INSERT INTO menu_ingredients (menu_ingredient_id, item_id, ingredient_id, quantity_required) VALUES (1, 1, $id, 1)");
    $db->exec("CREATE TRIGGER inventory_fail_delete BEFORE DELETE ON ingredients FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Simulated deletion failure'");
    try { inventoryAction($db, 'delete_ingredient', ['ingredient_id' => (string)$id]); } catch (PDOException $e) { }
    checkInventory((int)$db->query("SELECT COUNT(*) FROM inventory_transactions WHERE ingredient_id = $id")->fetchColumn() === 3 && (int)$db->query('SELECT COUNT(*) FROM menu_ingredients')->fetchColumn() === 1, 'Failed delete restores history and recipe links.');
    $db->exec('DROP TRIGGER inventory_fail_delete');
    inventoryAction($db, 'delete_ingredient', ['ingredient_id' => (string)$id]);
    checkInventory((int)$db->query("SELECT COUNT(*) FROM ingredients WHERE ingredient_id = $id")->fetchColumn() === 0, 'Ingredient with history deletes successfully.');
    checkInventory((int)$db->query('SELECT COUNT(*) FROM menu_ingredients')->fetchColumn() === 0 && (int)$db->query('SELECT COUNT(*) FROM inventory_transactions')->fetchColumn() === 1, 'Delete removes only the target history and recipe links.');
    checkInventory((int)$db->query('SELECT COUNT(*) FROM menu_items')->fetchColumn() === 1, 'Deleting an ingredient preserves menu items.');
    $db->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO')");
    $db->exec("INSERT INTO ingredients (ingredient_id, ingredient_name, unit, current_stock, reorder_level, unit_price) VALUES (0, 'Legacy salt', 'kg', 6, 6, 10)");
    inventoryAction($db, 'update_stock', ['ingredient_id' => '0', 'new_stock' => '7']);
    checkInventory($db->query('SELECT current_stock FROM ingredients WHERE ingredient_id = 0')->fetchColumn() === '7.00', 'Legacy ID 0 remains editable.');

    // Exercise actual page controllers with isolated sessions and the fixture DB.
    $runner = <<<'PHP'
    putenv('DB_NAME=' . $argv[1]);
    putenv('GILDED_SPOON_DB_NAME=' . $argv[1]);
    session_save_path(sys_get_temp_dir());
    require 'config/config.php';
    $_SESSION = ['user_id' => 101, 'user_role' => $argv[2], 'first_name' => 'Inventory Test', 'last_name' => 'User', 'inventory_csrf' => 'test-token'];
    if ($argv[2] === 'staff') {
        $db = (new Database())->connect();
        $db->prepare('INSERT INTO staff_sessions (staff_id, php_session_id) VALUES (101, ?)')->execute([session_id()]);
    }
    $_POST = json_decode($argv[3], true);
    $_GET = ['edit' => '0'];
    $_SERVER['REQUEST_METHOD'] = $_POST ? 'POST' : 'GET';
    chdir($argv[2] === 'admin' ? 'adminSide/inventoryCrud' : 'staffSide/inventoryCrud');
    require 'viewInventory.php';
    PHP;
    foreach (['admin', 'staff'] as $role) {
        foreach ([[], ['action' => 'delete_ingredient', 'ingredient_id' => '0'], ['action' => 'update_stock', 'ingredient_id' => '0', 'new_stock' => '-1', 'csrf_token' => 'test-token'], ['action' => 'update_stock', 'ingredient_id' => '0', 'new_stock' => '8.00', 'csrf_token' => 'test-token']] as $post) {
            $process = proc_open([PHP_BINARY, '-r', $runner, $fixture, $role, json_encode($post)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
            $html = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            checkInventory(proc_close($process) === 0 && $stderr === '', "$role page renders without errors: $stderr");
            if (!$post) {
                $dom = new DOMDocument();
                @$dom->loadHTML($html);
                $xpath = new DOMXPath($dom);
                $forms = $xpath->query('//form[translate(@method, "post", "POST") = "POST"]');
                foreach ($forms as $form) checkInventory($xpath->query('.//input[@name="csrf_token"]', $form)->length === 1, 'Every inventory form contains a CSRF token.');
                checkInventory(str_contains($html, 'const editIngredientId = 0;') && str_contains($html, 'stat-value text-primary-gold'), 'Legacy edit links and visible total count render.');
            } elseif (!isset($post['csrf_token'])) {
                checkInventory(str_contains($html, 'Your form has expired.'), 'Missing CSRF prevents delete.');
            } elseif ($post['new_stock'] === '-1') {
                checkInventory(str_contains($html, 'Stock must be between'), 'Page shows actionable validation errors.');
            } else {
                checkInventory($html === '' && $db->query('SELECT current_stock FROM ingredients WHERE ingredient_id = 0')->fetchColumn() === '8.00', 'Successful page POST redirects without rendering/resubmitting.');
            }
        }
    }
    inventoryAction($db, 'delete_ingredient', ['ingredient_id' => '0']);
    checkInventory((int)$db->query('SELECT COUNT(*) FROM ingredients WHERE ingredient_id = 0')->fetchColumn() === 0, 'Legacy ID 0 deletes with history.');
    echo "Passed $checks inventory checks; no real inventory records were changed.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    // Only this randomly generated test database is ever removed.
    if (preg_match('/^inventory_test_[a-f0-9]{12}$/D', $fixture)) $db->exec("DROP DATABASE `$fixture`");
}
