<?php
// Run: C:\xampp\php\php.exe tests/financial_report.php
// All fixture writes use connection-local temporary tables, never real records.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
session_save_path(sys_get_temp_dir());
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/financial_report_helper.php';
$db = (new Database())->connect();
if (!$db) throw new RuntimeException('Start MySQL before running financial report tests.');
$checks = 0;
function verifyReport(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$today = new DateTimeImmutable('2026-10-04');
$default = financialReportRange([], $today);
verifyReport($default['start']->format('Y-m-d') === '2026-07-07', 'Default includes exactly 90 calendar dates.');
verifyReport($default['until']->format('Y-m-d H:i:s') === '2026-10-05 00:00:00', 'End date includes the entire day.');
foreach ([['start' => '2026-02-30'], ['start' => []], ['start' => '2026-10-04', 'end' => '2026-10-03'], ['end' => '2026-10-05'], ['start' => '2020-01-01'], ['start' => '2026-1-01']] as $invalid) {
    $rejected = false;
    try { financialReportRange($invalid, $today); } catch (InvalidArgumentException $e) { $rejected = true; }
    verifyReport($rejected, 'Invalid report dates must be rejected.');
}
// Minimal schemas also exercise report compatibility independently of unrelated migrations.
$db->exec('CREATE TEMPORARY TABLE online_orders (order_id INT, order_date DATETIME, status VARCHAR(20), total_amount DECIMAL(10,2))');
$db->exec('CREATE TEMPORARY TABLE online_order_items (order_id INT, item_id INT, quantity INT, item_price DECIMAL(10,2))');
$db->exec('CREATE TEMPORARY TABLE menu_items (item_id INT, item_name VARCHAR(255))');
$db->exec('CREATE TEMPORARY TABLE pos_sales (sale_id INT, sold_at DATETIME, status VARCHAR(20), total_amount DECIMAL(10,2))');
$db->exec('CREATE TEMPORARY TABLE pos_sale_items (sale_id INT, item_id INT NULL, item_name VARCHAR(255), quantity INT, unit_price DECIMAL(10,2))');
$db->exec('CREATE TEMPORARY TABLE expenses (expense_date DATETIME, category VARCHAR(100), amount DECIMAL(10,2))');
$range = financialReportRange(['start' => '2026-02-01', 'end' => '2026-03-01'], $today);
$empty = loadFinancialReport($db, $range);
verifyReport($empty['revenue'] === 0.0 && $empty['expenses'] === 0.0 && $empty['margin'] === null, 'Empty data has zero totals and no fabricated margin.');
verifyReport(count($empty['charts']['daily']['labels']) === 29, 'Daily chart fills every selected date.');
verifyReport(count($empty['charts']['monthly']['labels']) === 2, 'Monthly chart contains both partial months.');
verifyReport($empty['items'] === [] && $empty['categories'] === [], 'Empty breakdowns remain empty.');
$db->exec("INSERT INTO menu_items VALUES (1, 'Fish & chips <special>')");
$db->exec("INSERT INTO online_orders VALUES
    (1,'2026-02-01 00:00:00','COMPLETED',110),
    (2,'2026-03-01 23:59:59','DELIVERED',220),
    (3,'2026-02-03','PENDING',999), (4,'2026-02-03','CANCELLED',999),
    (5,'2026-01-31 23:59:59','COMPLETED',999), (6,'2026-03-02','COMPLETED',999)");
$db->exec('INSERT INTO online_order_items VALUES (1,1,1,100), (2,1,2,100), (3,1,10,100), (4,1,10,100), (5,1,10,100), (6,1,10,100)');
$db->exec("INSERT INTO pos_sales VALUES (1,'2026-02-01','COMPLETED',49.5), (2,'2026-02-02','REFUNDED',100), (3,'2026-03-02','COMPLETED',100)");
$db->exec("INSERT INTO pos_sale_items VALUES (1,1,'Fish & chips <special>',1,50), (1,NULL,'Deleted menu item',1,5), (2,1,'Fish & chips <special>',10,10), (3,1,'Fish & chips <special>',10,10)");
$db->exec("INSERT INTO expenses VALUES ('2026-02-01','Supplies',0.20), ('2026-03-01 23:59:59','Supplies',0.30), ('2026-03-02','Future',999), ('2026-01-31','Earlier',999)");
$report = loadFinancialReport($db, $range);
verifyReport($report['revenue'] === 379.5, 'Only completed/delivered sales inside exact date boundaries count.');
verifyReport($report['saleCount'] === 3, 'Overlapping online and POS identifiers do not collide.');
verifyReport($report['expenses'] === 0.5 && $report['expenseCount'] === 2, 'Expenses include both date boundaries.');
verifyReport($report['profit'] === 379.0 && $report['margin'] === 99.87, 'Profit and margin use final sale totals.');
verifyReport($report['categories'][0]['percentage'] === 100.0, 'Category percentage works for totals below one peso.');
verifyReport($report['items'][0]['quantity'] === 4 && $report['items'][0]['amount'] === 350.0, 'Top items combine sources without duplicated joins, excluding pending/refunded lines.');
verifyReport($report['items'][1]['item_name'] === 'Deleted menu item', 'POS historical item names survive deleted menu items.');
verifyReport($report['charts']['monthly']['revenue'] === [159.5, 220.0], 'Monthly values include the first day and partial final month.');
foreach (['daily', 'monthly', 'yearly'] as $group) {
    verifyReport(array_sum($report['charts'][$group]['revenue']) === $report['revenue'], 'Chart revenue reconciles with summary for ' . $group);
    verifyReport(array_sum($report['charts'][$group]['expenses']) === $report['expenses'], 'Chart expenses reconcile with summary for ' . $group);
}
$db->exec("INSERT INTO expenses VALUES ('2026-02-05','Repairs',500)");
$loss = loadFinancialReport($db, $range);
verifyReport($loss['profit'] === -121.0 && $loss['margin'] < 0, 'Losses produce negative profit and margin.');
$single = loadFinancialReport($db, financialReportRange(['start' => '2026-02-05', 'end' => '2026-02-05'], $today));
verifyReport($single['profit'] === -500.0 && $single['margin'] === null, 'Expense-only periods keep margin undefined.');
verifyReport(count($single['charts']['daily']['labels']) === 1, 'One-day report has one bucket.');
$db->exec('ALTER TABLE pos_sale_items DROP COLUMN quantity');
$failed = false;
try { loadFinancialReport($db, $range); } catch (PDOException $e) { $failed = true; }
verifyReport($failed, 'A broken source fails explicitly instead of returning a misleading partial report.');
echo "Financial report tests passed: $checks checks. No real records changed.\n";
