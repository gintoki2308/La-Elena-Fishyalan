<?php
// Shared controller; admin/staff entry points enforce their respective roles first.
require_once __DIR__ . '/inventory_helper.php';
require_once __DIR__ . '/recipe_inventory_helper.php';
$inventoryTab = inventoryText($_GET, 'tab');
if (!in_array($inventoryTab, ['recipes', 'stock_in', 'stock_out', 'total_stock'], true)) $inventoryTab = 'ingredients';
$selectedMenu = inventoryText($_POST, 'item_id') ?: inventoryText($_GET, 'item_id');
$db = (new Database())->connect();
$ingredients = $suppliers = [];
$error = $addError = '';
$success = $_SESSION['inventory_success'] ?? '';
unset($_SESSION['inventory_success']);
$inventoryCsrf = $_SESSION['inventory_csrf'] ?? ($_SESSION['inventory_csrf'] = bin2hex(random_bytes(32)));
$addValues = array_fill_keys(['ingredient_name', 'unit', 'cost_per_unit', 'current_stock', 'reorder_level', 'supplier_id'], '');
$addValues['unit'] = 'pieces';
$ingredientUnits = ['pieces' => 'Pieces', 'kg' => 'Kilogram (kg)', 'g' => 'Gram (g)', 'liter' => 'Liter', 'ml' => 'Milliliter (ml)', 'boxes' => 'Boxes'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = inventoryText($_POST, 'action');
    foreach ($addValues as $field => $value) $addValues[$field] = inventoryText($_POST, $field);
    try {
        if (!hash_equals($inventoryCsrf, inventoryText($_POST, 'csrf_token'))) {
            throw new InvalidArgumentException('Your form has expired. Refresh the page and try again.');
        }
        if (!$db) throw new RuntimeException('Database unavailable.');
        $success = $action === 'save_recipe' ? saveInventoryRecipe($db, $_POST) : inventoryAction($db, $action, $_POST, isStaff() ? (int)$_SESSION['user_id'] : null);
        $_SESSION['inventory_success'] = $success;
        header('Location: viewInventory.php?' . http_build_query(['tab' => $inventoryTab, 'item_id' => $selectedMenu]), true, 303);
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Inventory action failed: ' . $e->getMessage());
        $error = 'Unable to save the inventory change. Please try again.';
    }
    if ($action === 'add_ingredient') {
        $addError = $error;
        $error = '';
    }
}

try {
    if (!$db) throw new RuntimeException('Database unavailable.');
    $suppliers = $db->query('SELECT supplier_id, supplier_name FROM suppliers ORDER BY supplier_name')->fetchAll(PDO::FETCH_ASSOC);
    $ingredients = $db->query("SELECT i.*, s.supplier_name, COALESCE(t.quantity_in, 0) AS quantity_in, COALESCE(t.quantity_out, 0) AS quantity_out
        FROM ingredients i LEFT JOIN suppliers s ON i.supplier_id = s.supplier_id
        LEFT JOIN (SELECT ingredient_id,
            SUM(CASE WHEN transaction_type = 'IN' OR (transaction_type = 'ADJUSTMENT' AND quantity > 0) THEN quantity ELSE 0 END) AS quantity_in,
            SUM(CASE WHEN transaction_type = 'OUT' THEN quantity WHEN transaction_type = 'ADJUSTMENT' AND quantity < 0 THEN -quantity ELSE 0 END) AS quantity_out
            FROM inventory_transactions GROUP BY ingredient_id) t ON t.ingredient_id = i.ingredient_id
        ORDER BY i.current_stock, i.ingredient_id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Inventory fetch failed: ' . $e->getMessage());
    $error = 'Unable to load inventory. Please try again.';
}
$totalItems = count($ingredients);
$lowStock = count(array_filter($ingredients, fn($i) => $i['current_stock'] <= $i['reorder_level']));
$totalValue = array_sum(array_map(fn($i) => ($i['unit_price'] ?? 0) * ($i['current_stock'] ?? 0), $ingredients));
if ($inventoryTab !== 'ingredients') {
    require __DIR__ . '/inventory_workspace.php';
    exit;
}
