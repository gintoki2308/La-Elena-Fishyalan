<?php
// Run: C:\xampp\php\php.exe tests/expenses.php
// Fixtures live only in a connection-local temporary copy of the real schema.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
session_save_path(sys_get_temp_dir());
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/expense_helper.php';
$db = (new Database())->connect();
if (!$db) throw new RuntimeException('Start MySQL before running expense tests.');
$checks = 0;
function verifyExpense(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

$schema = $db->query('SHOW CREATE TABLE expenses')->fetch(PDO::FETCH_NUM)[1];
verifyExpense(str_contains($schema, 'AUTO_INCREMENT'), 'Apply the expense ID migration first.');
$db->exec('CREATE TEMPORARY TABLE expense_schema_fixture LIKE expenses');
$db->exec('CREATE TEMPORARY TABLE expenses LIKE expense_schema_fixture');
$valid = ['expense_type' => '  Office supplies  ', 'amount' => '125.50', 'category' => 'SUPPLIES', 'description' => 'Paper & pens'];
recordExpense($db, $valid, 7);
recordExpense($db, array_replace($valid, ['amount' => '0.01', 'description' => '']), 8);
$rows = $db->query('SELECT * FROM expenses ORDER BY expense_date DESC, expense_id DESC')->fetchAll();
verifyExpense(count($rows) === 2, 'Repeated saves succeed.');
verifyExpense($rows[0]['expense_id'] > $rows[1]['expense_id'], 'IDs are unique and newest expense appears first.');
verifyExpense((int)$rows[1]['recorded_by'] === 7 && (int)$rows[0]['recorded_by'] === 8, 'The recording admin is saved in recorded_by.');
verifyExpense($rows[1]['expense_type'] === 'Office supplies' && $rows[1]['description'] === 'Paper & pens', 'Expense details are saved.');
verifyExpense($rows[1]['amount'] === '125.50' && $rows[0]['amount'] === '0.01', 'Amounts retain exact cents.');
verifyExpense($rows[0]['expense_date'] === $db->query('SELECT CURDATE()')->fetchColumn(), 'Expenses use the current date.');
verifyExpense($db->query('SELECT SUM(amount) FROM expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)')->fetchColumn() === '125.51', 'The summary includes new expenses immediately.');

foreach ([
    ['amount' => '0'], ['amount' => '-10'], ['amount' => '1.234'],
    ['amount' => '100000000'], ['amount' => 'abc'], ['amount' => '1e3'],
    ['amount' => []], ['amount' => ''], ['category' => 'UNKNOWN'],
    ['category' => []], ['expense_type' => '   '], ['expense_type' => []],
    ['expense_type' => str_repeat('x', 101)], ['description' => str_repeat('x', 65536)],
] as $invalid) {
    $rejected = false;
    try {
        recordExpense($db, array_replace($valid, $invalid), 7);
    } catch (InvalidArgumentException $e) {
        $rejected = true;
    }
    verifyExpense($rejected, 'Invalid expense must be rejected: ' . json_encode(array_keys($invalid)));
}
verifyExpense((int)$db->query('SELECT COUNT(*) FROM expenses')->fetchColumn() === 2, 'Invalid requests do not add expenses.');
recordExpense($db, array_replace($valid, ['amount' => '99999999.99']), 7);
verifyExpense($db->query('SELECT amount FROM expenses ORDER BY expense_id DESC LIMIT 1')->fetchColumn() === '99999999.99', 'Maximum supported amount saves without truncation.');
echo "Passed {$checks} expense checks; no real expense records were changed.\n";
