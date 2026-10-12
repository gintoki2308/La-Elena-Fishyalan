<?php
// Run: C:\xampp\php\php.exe tests/recipe-inventory.php
// All writes, including real controller requests, use a disposable test database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
session_save_path(sys_get_temp_dir());
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/pos_helper.php';
$db = (new Database())->connect();
if (!$db) throw new RuntimeException('Start MySQL before running these checks.');
$checks = 0;
function recipeCheck(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function recipeReject(callable $fn): void {
    try { $fn(); } catch (InvalidArgumentException $e) { recipeCheck(true, 'Validation rejected invalid change.'); return; }
    throw new RuntimeException('Expected change to be rejected.');
}
function recipeStock(int $id = 1): string {
    global $db;
    return (string)$db->query('SELECT current_stock FROM ingredients WHERE ingredient_id = ' . $id)->fetchColumn();
}
function captureRecipe(string $source, int $id, array $items): void {
    global $db;
    $db->beginTransaction();
    try { deductRecipeInventory($db, $source, $id, $items); $db->commit(); }
    catch (Throwable $e) { $db->rollBack(); throw $e; }
}
function returnRecipe(string $source, int $id): void {
    global $db;
    $db->beginTransaction();
    try { restoreRecipeInventory($db, $source, $id); $db->commit(); }
    catch (Throwable $e) { $db->rollBack(); throw $e; }
}
$schema = [];
foreach (['staffs','staff_sessions','admins','customer','favorites','suppliers','menu_items','ingredients','menu_ingredients','inventory_transactions','inventory_order_deductions','inventory_order_usage','online_orders','online_order_items','pos_sales','pos_sale_items','audit_logs'] as $table) {
    $ddl = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    // The inventory regression suite separately exercises real foreign keys.
    $ddl = preg_replace('/^\s*CONSTRAINT .*FOREIGN KEY.*\n?/m', '', $ddl);
    $schema[] = preg_replace('/,\s*\)/', ')', $ddl);
}
$fixture = 'recipe_test_' . bin2hex(random_bytes(6));
$db->exec("CREATE DATABASE `$fixture`");
function recipePage(string $path, string $role, array $post = [], array $get = [], array $session = []): string {
    global $fixture;
    $runner = <<<'PHP'
    putenv('DB_NAME=' . $argv[1]); putenv('GILDED_SPOON_DB_NAME=' . $argv[1]);
    session_save_path(sys_get_temp_dir());
    $_SERVER['SCRIPT_NAME'] = '/La_Elena/' . $argv[2];
    require 'config/config.php';
    $_SESSION = array_merge(['user_id'=>101, 'user_role'=>$argv[3], 'first_name'=>'Test', 'last_name'=>'User', 'inventory_csrf'=>'test-token', 'checkout_csrf'=>'test-token', 'cancel_order_csrf'=>'test-token'], json_decode($argv[6], true));
    if ($argv[3] === 'staff') {
        $db = (new Database())->connect();
        $db->prepare('INSERT INTO staff_sessions (staff_id, php_session_id) VALUES (101, ?)')->execute([session_id()]);
    }
    $_POST = json_decode($argv[4], true); $_GET = json_decode($argv[5], true);
    $_SERVER['REQUEST_METHOD'] = $_POST ? 'POST' : 'GET';
    chdir(dirname($argv[2])); require basename($argv[2]);
    PHP;
    $process = proc_open([PHP_BINARY, '-r', $runner, $fixture, $path, $role, json_encode($post), json_encode($get), json_encode($session)], [1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__));
    $html = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    recipeCheck(proc_close($process) === 0 && $stderr === '', "$path renders without errors: $stderr");
    return $html;
}
try {
    $db->exec("USE `$fixture`");
    foreach ($schema as $ddl) $db->exec($ddl);
    $db->exec("INSERT INTO staffs (staff_id,email,first_name,last_name,role,account_status) VALUES (101,'recipe@example.invalid','Recipe','Cashier','Cashier','ACTIVE')");
    $db->exec("INSERT INTO customer (member_id,first_name,last_name,email) VALUES (101,'Recipe','Customer','customer@example.invalid')");
    $db->exec("INSERT INTO menu_items (item_id,item_name,price,stock_quantity,is_available) VALUES (1,'Sinigang',100,100,1),(2,'Soup',50,100,1),(3,'Packaged item',10,100,1)");
    $db->exec("INSERT INTO ingredients (ingredient_id,ingredient_name,unit,current_stock,reorder_level,unit_price) VALUES (1,'Onion','g',1000,100,1),(2,'Salt','g',100,10,1)");
    $recipe = ['item_id'=>'1','recipe'=>[['ingredient_id'=>'1','quantity'=>'200'],['ingredient_id'=>'2','quantity'=>'0.25']]];
    saveInventoryRecipe($db, $recipe);
    saveInventoryRecipe($db, ['item_id'=>'2','recipe'=>[['ingredient_id'=>'1','quantity'=>'100']]]);
    foreach ([['quantity'=>'0'], ['quantity'=>'-1'], ['quantity'=>'1.001'], ['ingredient_id'=>'999']] as $bad) {
        recipeReject(fn() => saveInventoryRecipe($db, ['item_id'=>'1','recipe'=>[array_replace($recipe['recipe'][0], $bad)]]));
    }
    recipeReject(fn() => saveInventoryRecipe($db, ['item_id'=>'1','recipe'=>[$recipe['recipe'][0],$recipe['recipe'][0]]]));
    recipeCheck((int)$db->query('SELECT COUNT(*) FROM menu_ingredients WHERE item_id=1')->fetchColumn() === 2, 'Invalid saves preserve the old recipe.');
    captureRecipe('ONLINE', 900, [1=>2,2=>1]);
    recipeCheck(recipeStock() === '500.00' && recipeStock(2) === '99.50', 'Shared ingredients aggregate across food and serving counts, including decimals.');
    captureRecipe('ONLINE', 900, [1=>2,2=>1]);
    recipeCheck(recipeStock() === '500.00', 'Duplicate capture cannot deduct twice.');
    recipeReject(fn() => captureRecipe('POS', 900, [1=>3]));
    recipeCheck(recipeStock() === '500.00' && (int)$db->query("SELECT COUNT(*) FROM inventory_order_deductions WHERE source='POS'")->fetchColumn() === 0, 'Insufficient stock rolls back capture and quantities.');
    saveInventoryRecipe($db, ['item_id'=>'1','recipe'=>[['ingredient_id'=>'1','quantity'=>'300']]]);
    returnRecipe('ONLINE',900); returnRecipe('ONLINE',900);
    recipeCheck(recipeStock() === '1000.00' && recipeStock(2) === '100.00', 'Cancellation restores original snapshot once despite recipe edits.');
    captureRecipe('ONLINE',900,[1=>1]);
    recipeCheck(recipeStock() === '1000.00', 'Cancelled capture cannot be deducted again.');
    captureRecipe('ONLINE',901,[3=>1]);
    saveInventoryRecipe($db, ['item_id'=>'3','recipe'=>[['ingredient_id'=>'1','quantity'=>'10']]]);
    captureRecipe('ONLINE',901,[3=>1]); returnRecipe('ONLINE',901);
    recipeCheck(recipeStock() === '1000.00', 'An originally empty recipe stays empty on retries and returns.');
    saveInventoryRecipe($db, $recipe);

    $movement = ['ingredient_id'=>'1','quantity'=>'20.25','movement_date'=>date('Y-m-d'),'notes'=>'Delivery'];
    inventoryAction($db,'stock_in',$movement,101);
    inventoryAction($db,'stock_out',array_replace($movement,['quantity'=>'10.25','notes'=>'Spoilage']),101);
    recipeCheck(recipeStock() === '1010.00', 'Manual stock movements update quantities.');
    recipeReject(fn() => inventoryAction($db,'stock_out',array_replace($movement,['quantity'=>'2000']),101));
    recipeReject(fn() => inventoryAction($db,'stock_in',array_replace($movement,['movement_date'=>'2030-02-31']),101));
    recipeReject(fn() => inventoryAction($db,'delete_ingredient',['ingredient_id'=>'1']));
    recipeReject(fn() => inventoryAction($db,'edit_ingredient',['ingredient_id'=>'1','ingredient_name'=>'Onion','unit'=>'kg','unit_price'=>'1','reorder_level'=>'1']));

    // Real POS checkout, retry, refund and insufficient-stock rollback.
    $saleInput = ['request_key'=>bin2hex(random_bytes(16)), 'items'=>[['item_id'=>1,'quantity'=>2,'unit_price'=>'100.00']], 'service_type'=>'TAKEAWAY','payment_method'=>'CASH','amount_tendered'=>'500'];
    $sale = posCheckout($db,101,$saleInput);
    recipeCheck(recipeStock() === '610.00', 'POS checkout consumes recipe ingredients.');
    posCheckout($db,101,$saleInput);
    recipeCheck(recipeStock() === '610.00', 'POS request retry preserves ingredient balance.');
    posRefund($db,101,(int)$sale['sale_id'],'Not prepared',true);
    posRefund($db,101,(int)$sale['sale_id'],'Retry',true);
    recipeCheck(recipeStock() === '1010.00', 'POS restock refund restores ingredients exactly once.');
    $saleInput['request_key'] = bin2hex(random_bytes(16));
    $sale = posCheckout($db,101,$saleInput);
    posRefund($db,101,(int)$sale['sale_id'],'Already prepared',false);
    recipeCheck(recipeStock() === '610.00', 'Prepared-food refund does not restore ingredients.');
    $count = (int)$db->query('SELECT COUNT(*) FROM pos_sales')->fetchColumn();
    $portions = $db->query('SELECT stock_quantity FROM menu_items WHERE item_id=1')->fetchColumn();
    recipeReject(fn() => posCheckout($db,101,array_replace($saleInput,['request_key'=>bin2hex(random_bytes(16)), 'items'=>[['item_id'=>1,'quantity'=>4,'unit_price'=>'100.00']]])));
    recipeCheck(recipeStock() === '610.00' && (int)$db->query('SELECT COUNT(*) FROM pos_sales')->fetchColumn() === $count && $db->query('SELECT stock_quantity FROM menu_items WHERE item_id=1')->fetchColumn() === $portions, 'Failed POS ingredient check rolls back sale, lines and portions.');
    $db->exec("CREATE TRIGGER fail_recipe_history BEFORE INSERT ON inventory_transactions FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test history failure'");
    try { captureRecipe('ONLINE',999,[1=>1]); throw new RuntimeException('Expected history failure.'); } catch (PDOException $e) { }
    recipeCheck(recipeStock() === '610.00' && (int)$db->query('SELECT COUNT(*) FROM inventory_order_deductions WHERE source_id=999')->fetchColumn() === 0, 'History failure rolls back stock and snapshot.');
    $db->exec('DROP TRIGGER fail_recipe_history');

    // Customer checkout and all three cancellation/status controllers.
    $checkout = ['csrf_token'=>'test-token','delivery_name'=>'Test Customer','delivery_email'=>'customer@example.invalid','delivery_phone'=>'09123456789','addr_house'=>'1','addr_street'=>'Test Street','addr_barangay'=>'Test','addr_city'=>'Test','addr_province'=>'Test','addr_zip'=>'1234','payment_method'=>'COD'];
    $customerSession = ['checkout_items'=>[1=>1],'cart'=>[1=>1]];
    $html = recipePage('customerSide/checkout.php','customer',$checkout,[],$customerSession);
    $orderId = (int)$db->query('SELECT MAX(order_id) FROM online_orders')->fetchColumn();
    recipeCheck($html === '' && recipeStock() === '410.00', 'Customer placement deducts ingredients before confirmation.');
    recipePage('customerSide/order-details.php','customer',['cancel_order_id'=>$orderId,'csrf_token'=>'test-token'],['id'=>$orderId]);
    recipeCheck(recipeStock() === '610.00', 'Customer cancellation returns ingredients.');
    recipePage('customerSide/order-details.php','customer',['cancel_order_id'=>$orderId,'csrf_token'=>'test-token'],['id'=>$orderId]);
    recipeCheck(recipeStock() === '610.00', 'Repeated customer cancellation cannot restock again.');
    foreach (['admin','staff'] as $role) {
        recipePage('customerSide/checkout.php','customer',$checkout,[],$customerSession);
        $id = (int)$db->query('SELECT MAX(order_id) FROM online_orders')->fetchColumn();
        recipePage($role . 'Side/onlineOrdersCrud/manageOrder.php',$role,['action'=>'update_status','order_id'=>$id,'new_status'=>'CANCELLED']);
        recipeCheck(recipeStock() === '610.00', "$role cancellation restores customer ingredient deduction.");
    }
    recipePage('customerSide/checkout.php','customer',$checkout,[],$customerSession);
    $id = (int)$db->query('SELECT MAX(order_id) FROM online_orders')->fetchColumn();
    recipePage('adminSide/onlineOrdersCrud/manageOrder.php','admin',['action'=>'update_status','order_id'=>$id,'new_status'=>'CONFIRMED']);
    recipePage('staffSide/onlineOrdersCrud/manageOrder.php','staff',['action'=>'update_status','order_id'=>$id,'new_status'=>'PREPARING']);
    recipeCheck(recipeStock() === '410.00', 'Confirming and preparing an online order never deducts ingredients twice.');
    $beforeCount = (int)$db->query('SELECT COUNT(*) FROM online_orders')->fetchColumn();
    $html = recipePage('customerSide/checkout.php','customer',$checkout,[],['checkout_items'=>[1=>3]]);
    recipeCheck(str_contains($html,'Insufficient ingredient stock') && recipeStock() === '410.00' && (int)$db->query('SELECT COUNT(*) FROM online_orders')->fetchColumn() === $beforeCount, 'Insufficient online ingredients reject the order without partial writes.');

    saveInventoryRecipe($db, ['item_id'=>'3','recipe'=>[]]);
    inventoryAction($db, 'create_ingredient', ['ingredient_name'=>'Stock total test', 'unit'=>'pieces', 'unit_price'=>'1', 'current_stock'=>'0', 'reorder_level'=>'5', 'supplier_id'=>'']);
    $totalStockId = (int)$db->query("SELECT ingredient_id FROM ingredients WHERE ingredient_name='Stock total test'")->fetchColumn();
    $totalMovement = ['ingredient_id'=>(string)$totalStockId, 'quantity'=>'100', 'movement_date'=>date('Y-m-d')];
    inventoryAction($db, 'stock_in', $totalMovement, 101);
    inventoryAction($db, 'stock_out', array_replace($totalMovement, ['quantity'=>'10']), 101);
    recipeCheck(recipeStock($totalStockId) === '90.00', '100 pieces in minus 10 pieces out leaves 90 pieces.');
    foreach (['admin','staff'] as $role) {
        $inventoryPath = $role . 'Side/inventoryCrud/viewInventory.php';
        $otherRecipes = $db->query('SELECT * FROM menu_ingredients WHERE item_id <> 2 ORDER BY menu_ingredient_id')->fetchAll();
        recipePage($inventoryPath, $role, ['action'=>'save_recipe','csrf_token'=>'test-token','item_id'=>'2','recipe'=>[['ingredient_id'=>'2','quantity'=>'1.50']]], ['tab'=>'recipes','item_id'=>'1']);
        recipeCheck($db->query('SELECT ingredient_id, quantity_required FROM menu_ingredients WHERE item_id=2')->fetchAll() === [['ingredient_id'=>2,'quantity_required'=>'1.50']], "$role saves the submitted food's own ingredients and quantities.");
        recipeCheck($db->query('SELECT * FROM menu_ingredients WHERE item_id <> 2 ORDER BY menu_ingredient_id')->fetchAll() === $otherRecipes, "$role saving another food leaves existing and empty recipes unchanged.");
        recipePage($inventoryPath, $role, ['action'=>'save_recipe','csrf_token'=>'test-token','item_id'=>'2'], ['tab'=>'recipes','item_id'=>'2']);
        recipeCheck((int)$db->query('SELECT COUNT(*) FROM menu_ingredients WHERE item_id=2')->fetchColumn() === 0 && $db->query('SELECT * FROM menu_ingredients WHERE item_id <> 2 ORDER BY menu_ingredient_id')->fetchAll() === $otherRecipes, "$role clearing a recipe affects only that food.");
        saveInventoryRecipe($db, ['item_id'=>'2','recipe'=>[['ingredient_id'=>'1','quantity'=>'100']]]);
        if (($argv[1] ?? '') !== '') {
            foreach (['2','3'] as $itemId) {
                file_put_contents($argv[1] . '/' . $role . '-recipes-' . $itemId . '.html', recipePage($inventoryPath,$role,[],['tab'=>'recipes','item_id'=>$itemId]));
            }
            file_put_contents($argv[1] . '/' . $role . '-ingredients.html', recipePage($inventoryPath,$role));
        }
        foreach (['recipes','stock_in','stock_out'] as $tab) {
            $html = recipePage($role . 'Side/inventoryCrud/viewInventory.php',$role,[],['tab'=>$tab,'item_id'=>'1']);
            recipeCheck(str_contains($html,'name="csrf_token"') && !str_contains($html,'Unable to load'), "$role $tab page has a protected form and loads its records.");
            if (($argv[1] ?? '') !== '') file_put_contents($argv[1] . '/' . $role . '-' . $tab . '.html', $html);
        }
        $html = recipePage($inventoryPath, $role, [], ['tab'=>'total_stock']);
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new DOMXPath($dom);
        $cells = $xpath->query('//table[@id="total-stock-table"]//tr[@data-ingredient-id="' . $totalStockId . '"]/*');
        recipeCheck(array_map(static fn($cell) => trim($cell->textContent), iterator_to_array($cells)) === ['Stock total test','pieces','100.00','10.00','90.00','In stock'], "$role total stock displays the ingredient's incoming, outgoing and remaining quantities.");
        recipeCheck($xpath->query('//a[@aria-current="page" and contains(@href,"tab=total_stock")]')->length === 1 && !str_contains($html, 'Unable to load'), "$role total stock tab is active and loads successfully.");
        if (($argv[1] ?? '') !== '') file_put_contents($argv[1] . '/' . $role . '-total_stock.html', $html);
        $html = recipePage($role . 'Side/inventoryCrud/viewInventory.php',$role,['action'=>'save_recipe','item_id'=>'1','recipe'=>$recipe['recipe']],['tab'=>'recipes']);
        recipeCheck(str_contains($html,'Your form has expired'), 'Recipe save requires CSRF.');
        $html = recipePage($role . 'Side/inventoryCrud/viewInventory.php',$role,['action'=>'save_recipe','csrf_token'=>'test-token'] + $recipe,['tab'=>'recipes']);
        recipeCheck($html === '', 'Valid recipe save redirects.');
        $html = recipePage($role . 'Side/inventoryCrud/viewInventory.php',$role,['action'=>'stock_in','csrf_token'=>'test-token'] + $movement,['tab'=>'stock_in']);
        recipeCheck($html === '', 'Valid movement save redirects.');
    }
    // Independent transactions contend for the same last ingredient stock.
    $db->exec('UPDATE ingredients SET current_stock = 200 WHERE ingredient_id = 1');
    $worker = <<<'PHP'
    putenv('DB_NAME=' . $argv[1]); putenv('GILDED_SPOON_DB_NAME=' . $argv[1]);
    session_save_path(sys_get_temp_dir()); require 'config/config.php'; require 'config/recipe_inventory_helper.php';
    $db = (new Database())->connect(); $db->beginTransaction();
    try {
        deductRecipeInventory($db, 'ONLINE', (int)$argv[2], [1=>1]);
        usleep(250000); $db->commit(); echo 'SOLD';
    } catch (InvalidArgumentException $e) { $db->rollBack(); echo 'REJECTED'; }
    PHP;
    $workers = [];
    foreach ([8001,8002] as $workerId) {
        $process = proc_open([PHP_BINARY,'-r',$worker,$fixture,(string)$workerId], [1=>['pipe','w'],2=>['pipe','w']], $pipes, dirname(__DIR__));
        $workers[] = [$process,$pipes];
    }
    $results = [];
    foreach ($workers as [$process,$pipes]) {
        $results[] = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        recipeCheck(proc_close($process) === 0 && $stderr === '', 'Concurrent inventory worker completed cleanly.');
    }
    sort($results);
    recipeCheck($results === ['REJECTED','SOLD'] && recipeStock() === '0.00', 'Concurrent orders cannot oversell the last ingredient stock.');
    echo "Passed $checks recipe inventory checks; no real orders or stock changed.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if (preg_match('/^recipe_test_[a-f0-9]{12}$/D', $fixture)) $db->exec("DROP DATABASE `$fixture`");
}
