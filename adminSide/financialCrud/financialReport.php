<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/financial_report_helper.php';
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}
$today = new DateTimeImmutable('today');
$range = financialReportRange([], $today);
$error = '';
$report = null;
try {
    $range = financialReportRange($_GET, $today);
    $db = (new Database())->connect();
    if (!$db) throw new RuntimeException('Database unavailable');
    $report = loadFinancialReport($db, $range);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    $error = $e->getMessage();
} catch (Throwable $e) {
    http_response_code(503);
    error_log('Financial report: ' . $e->getMessage());
    $error = 'The report could not be loaded. Please try again once the database is available.';
}
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$money = static fn($value) => '₱' . number_format($value, 2);
$rangeLabel = $range['start']->format('M j, Y') . ' – ' . $range['end']->format('M j, Y');
$grouping = $_GET['group'] ?? 'daily';
if (!is_string($grouping) || !in_array($grouping, ['daily', 'monthly', 'yearly'], true)) $grouping = 'daily';
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales &amp; Financial Analytics - La Elena</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="financialReport.css?v=1">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js" defer></script>
    <script src="financialReport.js?v=1" defer></script>
</head>
<body>
<nav class="top-nav"><a href="../admin-dashboard.php">← Dashboard</a></nav>
<main class="container-fluid px-3 px-md-4 py-4">
    <div class="report-header">
        <div>
            <h1>Sales &amp; Financial Analytics</h1>
            <p class="report-muted">Track sales, recorded expenses, and your best-selling menu items.</p>
        </div>
        <div class="report-actions">
            <a class="btn btn-outline-gold btn-sm" href="recordExpense.php">Record expense</a>
            <?php if ($report): ?>
            <button type="button" class="btn btn-outline-gold btn-sm" id="btn-print">Print</button>
            <button type="button" class="btn btn-gold btn-sm" id="btn-export-pdf">Save PDF</button>
            <?php endif; ?>
        </div>
    </div>
    <form method="get" class="report-filters card p-3 mb-4">
        <div class="filter-fields">
            <div><label for="start">From</label><input type="date" class="form-control" id="start" name="start" required max="<?= $today->format('Y-m-d') ?>" value="<?= $range['start']->format('Y-m-d') ?>"></div>
            <div><label for="end">Through</label><input type="date" class="form-control" id="end" name="end" required max="<?= $today->format('Y-m-d') ?>" value="<?= $range['end']->format('Y-m-d') ?>"></div>
            <input type="hidden" name="group" id="selected-group" value="<?= $escape($grouping) ?>">
            <button class="btn btn-gold" type="submit">Apply dates</button>
            <a class="btn btn-outline-gold" href="financialReport.php">Reset</a>
        </div>
        <div class="preset-links">
            <span>Quick ranges:</span>
            <?php foreach (['Last 30 days' => $today->modify('-29 days'), 'Last 90 days' => $today->modify('-89 days'), 'This year' => $today->modify('first day of January this year')] as $label => $start): ?>
            <a href="?<?= $escape(http_build_query(['start' => $start->format('Y-m-d'), 'end' => $today->format('Y-m-d'), 'group' => $grouping])) ?>"><?= $escape($label) ?></a>
            <?php endforeach; ?>
        </div>
    </form>
    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><?= $escape($error) ?></div>
    <?php else: ?>
    <div id="export-message" class="alert alert-warning" role="alert" hidden></div>
    <h2 class="summary-heading">Summary <span><?= $escape($rangeLabel) ?></span></h2>
    <p class="report-muted report-definition">Revenue includes delivered/completed online orders<?= $report['hasPos'] ? ' and completed POS sales' : '' ?>, using final totals after discounts and tax. Pending, cancelled, and refunded sales are excluded. Dates use Philippine time.</p>
    <div class="row g-3 mb-4" id="summary-row">
        <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-label">Total revenue</div><div class="stat-value positive"><?= $money($report['revenue']) ?></div><div class="stat-detail"><?= $report['saleCount'] ?> completed/delivered sales</div></div></div>
        <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-label">Recorded expenses</div><div class="stat-value negative"><?= $money($report['expenses']) ?></div><div class="stat-detail"><?= $report['expenseCount'] ?> expense records</div></div></div>
        <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-label">Net profit</div><div class="stat-value <?= $report['profit'] < 0 ? 'negative' : 'positive' ?>"><?= $money($report['profit']) ?></div><div class="stat-detail">Revenue less recorded expenses</div></div></div>
        <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-label">Profit margin</div><div class="stat-value"><?= $report['margin'] === null ? 'N/A' : number_format($report['margin'], 2) . '%' ?></div><div class="stat-detail"><?= $report['margin'] === null ? 'Requires sales revenue' : 'Net profit ÷ revenue' ?></div></div></div>
    </div>
    <?php if (!$report['saleCount'] && !$report['expenseCount']): ?>
    <div class="alert alert-info" role="status">No completed sales or expenses in this date range. Choose another range, complete a sale, or <a href="recordExpense.php">record an expense</a> to see activity here.</div>
    <?php endif; ?>
    <div class="row g-4">
        <section class="col-12 col-lg-8">
            <div class="card h-100">
                <div class="card-header chart-header"><h2>Revenue vs expenses</h2>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Chart grouping" id="chart-group">
                        <?php foreach (['daily' => 'Daily', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $key => $label): ?>
                        <button type="button" class="btn btn-outline-gold <?= $grouping === $key ? 'active' : '' ?>" aria-pressed="<?= $grouping === $key ? 'true' : 'false' ?>" data-period="<?= $key ?>"><?= $label ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="card-body">
                    <p class="report-muted" id="chart-period-label">Grouped <?= $escape($grouping) ?> · <?= $escape($rangeLabel) ?></p>
                    <p class="report-muted" id="chart-unavailable" hidden>Charts could not load. The totals and tables below are still available.</p>
                    <div class="chart-container" id="financial-chart-wrap" hidden><canvas id="financialChart" role="img" aria-label="Revenue and expenses over the selected dates"></canvas></div>
                    <noscript><p>Enable JavaScript to view charts. Report totals and tables remain available.</p></noscript>
                    <details class="mt-3" id="financial-data"><summary>View chart data</summary>
                        <div class="table-responsive report-data-scroll"><table class="table table-sm mb-0"><thead><tr><th>Period</th><th class="text-end">Revenue</th><th class="text-end">Expenses</th><th class="text-end">Net profit</th></tr></thead>
                        <tbody id="financial-table-body">
                        <?php $series = $report['charts'][$grouping]; foreach ($series['labels'] as $i => $label): ?>
                        <tr><td><?= $escape($label) ?></td><td class="text-end"><?= $money($series['revenue'][$i]) ?></td><td class="text-end"><?= $money($series['expenses'][$i]) ?></td><td class="text-end"><?= $money($series['revenue'][$i] - $series['expenses'][$i]) ?></td></tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                    </details>
                </div>
            </div>
        </section>
        <section class="col-12 col-lg-4"><div class="card h-100">
            <div class="card-header"><h2>Top 5 selling items</h2></div>
            <div class="card-body">
                <p class="report-muted">Ranked by quantity · selected date range</p>
                <?php if (!$report['items']): ?>
                    <div class="empty-state">No items from completed sales in this date range.</div>
                <?php else: ?>
                    <div class="doughnut-container" id="items-chart-wrap" hidden><canvas id="topItemsChart" role="img" aria-label="Top five items by quantity sold"></canvas></div>
                    <div class="table-responsive"><table class="table table-sm" id="items-table"><thead><tr><th>Item</th><th class="text-end">Sold</th><th class="text-end">Item sales</th></tr></thead><tbody>
                    <?php foreach ($report['items'] as $item): ?>
                    <tr><td><?= $escape($item['item_name']) ?></td><td class="text-end"><?= $item['quantity'] ?></td><td class="text-end"><?= $money($item['amount']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                    <p class="report-muted report-definition">Item sales are quantity × item price, before order discounts and tax.</p>
                <?php endif; ?>
            </div>
        </div></section>
    </div>
    <section class="card mt-4"><div class="card-header chart-header"><h2>Expenses by category</h2><a href="viewExpenses.php">Manage expenses</a></div>
        <div class="table-responsive"><table class="table mb-0" id="expenses-table"><thead><tr><th>Category</th><th class="text-end">Amount</th><th class="text-end">Share of expenses</th></tr></thead><tbody>
        <?php if (!$report['categories']): ?>
        <tr><td colspan="3" class="empty-state">No expenses recorded in this date range.</td></tr>
        <?php else: foreach ($report['categories'] as $category): ?>
        <tr><td><?= $escape($category['category']) ?></td><td class="text-end"><?= $money($category['total']) ?></td><td class="text-end"><?= number_format($category['percentage'], 1) ?>%</td></tr>
        <?php endforeach; endif; ?>
        </tbody></table></div>
    </section>
    <p class="report-muted mt-3">Updated <?= $escape((new DateTimeImmutable())->format('M j, Y g:i A')) ?> (Philippine time). Apply dates or refresh to load newly recorded activity.</p>
    <script type="application/json" id="report-data"><?= json_encode($report, $jsonFlags) ?></script>
    <?php endif; ?>
</main>
</body>
</html>