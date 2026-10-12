<?php
// Run with C:\xampp\php\php.exe tests/pos.php after applying the POS migration.
// Connection-local temporary tables shadow real tables; no real sales or stock change.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
session_save_path(sys_get_temp_dir());
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/pos_helper.php';
putenv('POS_TAX_BASIS_POINTS=1000');
$db = (new Database())->connect();
if (!$db) throw new RuntimeException('Start MySQL before running POS tests.');
foreach (['staffs', 'menu_items', 'online_orders', 'online_order_items', 'pos_sales', 'pos_sale_items', 'audit_logs', 'ingredients', 'menu_ingredients', 'inventory_transactions', 'inventory_order_deductions', 'inventory_order_usage'] as $table) {
    $ddl = $db->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1];
    $ddl = str_replace('CREATE TABLE', 'CREATE TEMPORARY TABLE', $ddl);
    $ddl = preg_replace('/^\s*CONSTRAINT .*FOREIGN KEY.*\n?/m', '', $ddl);
    $ddl = preg_replace('/,\s*\)/', ')', $ddl);
    $ddl = preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl);
    $db->exec($ddl);
}
$db->exec("INSERT INTO staffs (staff_id,email,first_name,last_name,role,account_status) VALUES
    (1,'cashier@example.invalid','Test','Cashier','Cashier','ACTIVE'),
    (2,'kitchen@example.invalid','Test','Kitchen','Kitchen Staff','ACTIVE'),
    (3,'disabled@example.invalid','Disabled','Cashier','Cashier','DISABLED'),
    (4,'other@example.invalid','Other','Cashier','Cashier','ACTIVE')");
$db->exec("INSERT INTO menu_items (item_id,item_name,price,stock_quantity,is_available) VALUES
    (1,'Test meal',100.05,10,1),(2,'Test side',20.00,1,1),(3,'Hidden item',30,20,0)");
$db->exec("INSERT INTO online_orders (order_id,status) VALUES (1,'PENDING')");
$db->exec('INSERT INTO online_order_items (order_id,item_id,quantity,item_price) VALUES (1,1,3,100.05)');
$checks = 0;
function check($condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function rejects(callable $fn, string $message): void {
    try { $fn(); } catch (InvalidArgumentException $e) { check(true, $message); return; }
    throw new RuntimeException($message);
}
function order(array $overrides = []): array {
    return array_replace(['request_key' => bin2hex(random_bytes(16)), 'items' => [['item_id' => 1, 'quantity' => 2, 'unit_price' => '100.05']],
        'service_type' => 'DINE_IN', 'payment_method' => 'CASH', 'amount_tendered' => '250.00', 'discount_amount' => '10.00', 'discount_reason' => 'Service recovery'], $overrides);
}
function stock(int $id = 1): int { global $db; return (int)$db->query('SELECT stock_quantity FROM menu_items WHERE item_id = ' . $id)->fetchColumn(); }
check(posMoney('0.01') === 1 && posMoney('100.05') === 10005, 'Money uses exact cents.');
foreach (['-1', '1.001', '1e2', [], 'NaN'] as $value) rejects(fn() => posMoney($value), 'Invalid amount must fail.');
rejects(fn() => posValidateCsrf(null), 'Missing CSRF must fail.');
rejects(fn() => posValidateCsrf('bad'), 'Incorrect CSRF must fail.');
posValidateCsrf(posCsrfToken()); check(true, 'Valid CSRF passes.');
check(posCashier($db, 1) !== null && posCashier($db, 2) === null && posCashier($db, 3) === null, 'Only active cashiers can access POS.');
rejects(fn() => posCheckout($db, 2, order()), 'Kitchen cannot sell.');
rejects(fn() => posCheckout($db, 3, order()), 'Disabled cashier cannot sell.');
rejects(fn() => posCheckout($db, 1, order(['items' => []])), 'Empty cart fails.');
rejects(fn() => posCheckout($db, 1, order(['amount_tendered' => '1'])), 'Underpayment fails.');
rejects(fn() => posCheckout($db, 1, order(['discount_amount' => '300'])), 'Excess discount fails.');
rejects(fn() => posCheckout($db, 1, order(['discount_reason' => ''])), 'Discount needs reason.');
rejects(fn() => posCheckout($db, 1, order(['items' => [['item_id' => 1, 'quantity' => 8, 'unit_price' => '100.05']]])), 'Pending online stock stays reserved.');
rejects(fn() => posCheckout($db, 1, order(['items' => [['item_id' => 1, 'quantity' => 1, 'unit_price' => '0.01']]])), 'Tampered/stale price fails.');
rejects(fn() => posCheckout($db, 1, order(['items' => [['item_id' => 1, 'quantity' => 1.5, 'unit_price' => '100.05']]])), 'Fractional quantity fails.');
rejects(fn() => posCheckout($db, 1, order(['items' => [['item_id' => 3, 'quantity' => 1, 'unit_price' => '30.00']]])), 'Unavailable item fails.');
rejects(fn() => posCheckout($db, 1, order(['payment_method' => 'CARD'])), 'Unverified external payment fails.');
check(stock() === 10 && (int)$db->query('SELECT COUNT(*) FROM pos_sales')->fetchColumn() === 0, 'Rejected sales change nothing.');
$request = order();
$sale = posCheckout($db, 1, $request);
check($sale['subtotal'] === '200.10' && $sale['tax_amount'] === '19.01' && $sale['total_amount'] === '209.11' && $sale['change_amount'] === '40.89', 'Checkout discounts, tax rounding and change are correct.');
check(stock() === 8 && count($sale['items']) === 1, 'Sale saves lines and deducts stock.');
check(posCheckout($db, 1, $request)['sale_id'] === $sale['sale_id'] && stock() === 8, 'Retry returns receipt without double deduction.');
rejects(fn() => posCheckout($db, 1, array_replace($request, ['customer_name' => 'Changed'])), 'Reusing checkout key for changed order fails.');
rejects(fn() => posReceipt($db, (int)$sale['sale_id'], 4), 'Another cashier cannot see this receipt.');
rejects(fn() => posRefund($db, 4, (int)$sale['sale_id'], 'Wrong cashier', true), 'Another cashier cannot refund this sale.');
$db->exec("UPDATE menu_items SET item_name = 'Renamed', price = 150 WHERE item_id = 1");
$historical = posReceipt($db, (int)$sale['sale_id'], 1);
check($historical['items'][0]['item_name'] === 'Test meal' && $historical['items'][0]['unit_price'] === '100.05', 'Receipts retain original names and prices.');
$refund = posRefund($db, 1, (int)$sale['sale_id'], 'Order cancelled before preparation', true);
check($refund['status'] === 'REFUNDED' && stock() === 10, 'Refund restores stock when selected.');
posRefund($db, 1, (int)$sale['sale_id'], 'Retry', true);
check(stock() === 10 && (int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === 2, 'Refund retry does not restock or audit twice.');
$lastUnit = order(['items' => [['item_id' => 2, 'quantity' => 1, 'unit_price' => '20.00']], 'discount_amount' => '0', 'payment_method' => 'GCASH', 'payment_reference' => 'TEST-123', 'payment_confirmed' => true]);
$gcash = posCheckout($db, 1, $lastUnit);
check($gcash['total_amount'] === '22.00' && $gcash['amount_tendered'] === '22.00' && $gcash['change_amount'] === '0.00', 'External payment records exact total.');
check(stock(2) === 0 && (int)$db->query('SELECT is_available FROM menu_items WHERE item_id = 2')->fetchColumn() === 0, 'Last unit marks item unavailable.');
posRefund($db, 1, (int)$gcash['sale_id'], 'Prepared food refund', false);
check(stock(2) === 0, 'Refund of prepared food does not restock.');
// A required audit failure must roll back the sale, lines and stock together.
$db->exec('DELETE FROM audit_logs');
$db->exec("ALTER TABLE audit_logs ADD CONSTRAINT test_reject_sale CHECK (action <> 'POS_SALE_COMPLETED')");
$failed = false;
try { posCheckout($db, 1, order(['items' => [['item_id' => 1, 'quantity' => 1, 'unit_price' => '150.00']]])); } catch (PDOException $e) { $failed = true; }
check($failed && stock() === 10 && (int)$db->query('SELECT COUNT(*) FROM pos_sales')->fetchColumn() === 2, 'Audit failure rolls back entire sale.');
echo "POS tests passed: $checks checks. No real records changed.\n";
