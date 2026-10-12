<?php

/** Use the application's timezone and inclusive calendar dates for every report section. */
function financialReportRange(array $input, ?DateTimeImmutable $today = null): array
{
    $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
    $parse = static function ($value): DateTimeImmutable {
        if (!is_string($value)) throw new InvalidArgumentException('Enter valid start and end dates.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Enter valid start and end dates.');
        }
        return $date;
    };
    $start = isset($input['start']) ? $parse($input['start']) : $today->modify('-89 days');
    $end = isset($input['end']) ? $parse($input['end']) : $today;
    if ($start > $end) throw new InvalidArgumentException('The start date must be on or before the end date.');
    if ($end > $today) throw new InvalidArgumentException('The end date cannot be in the future.');
    if ($start < $end->modify('-5 years')) throw new InvalidArgumentException('Choose a date range of five years or less.');
    return ['start' => $start, 'end' => $end, 'until' => $end->modify('+1 day')];
}

function financialReportHasPos(PDO $db): bool
{
    try {
        $db->query('SELECT sale_id FROM pos_sales LIMIT 0');
        return true;
    } catch (PDOException $e) {
        // Older installations may not have the optional POS module yet.
        if ($e->getCode() === '42S02') return false;
        throw $e;
    }
}

/** Read only: no schema changes or generated sales are needed to view a report. */
function loadFinancialReport(PDO $db, array $range): array
{
    $hasPos = financialReportHasPos($db);
    $sales = "SELECT 'online' AS source, order_id AS id, order_date AS sold_at, total_amount
        FROM online_orders WHERE status IN ('DELIVERED', 'COMPLETED')";
    $lines = "SELECT 'online' AS source, oi.order_id AS sale_id, oi.item_id,
        COALESCE(m.item_name, 'Unavailable menu item') AS item_name, oi.quantity, oi.item_price AS unit_price
        FROM online_order_items oi LEFT JOIN menu_items m ON m.item_id = oi.item_id";
    if ($hasPos) {
        $sales .= " UNION ALL SELECT 'pos', sale_id, sold_at, total_amount FROM pos_sales WHERE status = 'COMPLETED'";
        $lines .= " UNION ALL SELECT 'pos', sale_id, item_id, item_name, quantity, unit_price FROM pos_sale_items";
    }
    $params = [$range['start']->format('Y-m-d H:i:s'), $range['until']->format('Y-m-d H:i:s')];
    $query = static function (string $sql) use ($db, $params): array {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    $dailySales = $query("SELECT DATE(sold_at) AS day, SUM(total_amount) AS amount, COUNT(*) AS count
        FROM ($sales) s WHERE sold_at >= ? AND sold_at < ? GROUP BY DATE(sold_at)");
    $dailyExpenses = $query('SELECT DATE(expense_date) AS day, SUM(amount) AS amount, COUNT(*) AS count
        FROM expenses WHERE expense_date >= ? AND expense_date < ? GROUP BY DATE(expense_date)');
    $categories = $query('SELECT category, SUM(amount) AS total FROM expenses
        WHERE expense_date >= ? AND expense_date < ? GROUP BY category ORDER BY total DESC, category');
    $items = $query("SELECT l.item_name, SUM(l.quantity) AS quantity, SUM(l.quantity * l.unit_price) AS amount
        FROM ($sales) s JOIN ($lines) l ON l.source = s.source AND l.sale_id = s.id
        WHERE s.sold_at >= ? AND s.sold_at < ?
        GROUP BY l.item_id, l.item_name ORDER BY quantity DESC, amount DESC, l.item_name LIMIT 5");

    $revenue = round(array_sum(array_column($dailySales, 'amount')), 2);
    $expenses = round(array_sum(array_column($dailyExpenses, 'amount')), 2);
    $salesByDay = array_column($dailySales, 'amount', 'day');
    $expensesByDay = array_column($dailyExpenses, 'amount', 'day');
    $buckets = ['daily' => [], 'monthly' => [], 'yearly' => []];
    foreach (new DatePeriod($range['start'], new DateInterval('P1D'), $range['until']) as $day) {
        $date = $day->format('Y-m-d');
        foreach (['daily' => 'Y-m-d', 'monthly' => 'Y-m', 'yearly' => 'Y'] as $period => $format) {
            $key = $day->format($format);
            if (!isset($buckets[$period][$key])) {
                $buckets[$period][$key] = ['revenue' => 0, 'expenses' => 0];
            }
            $buckets[$period][$key]['revenue'] += (float)($salesByDay[$date] ?? 0);
            $buckets[$period][$key]['expenses'] += (float)($expensesByDay[$date] ?? 0);
        }
    }
    $charts = [];
    foreach ($buckets as $period => $values) {
        $charts[$period] = [
            'labels' => array_map('strval', array_keys($values)),
            'revenue' => array_map(static fn($v) => round($v['revenue'], 2), array_values($values)),
            'expenses' => array_map(static fn($v) => round($v['expenses'], 2), array_values($values)),
        ];
    }
    foreach ($items as &$item) {
        $item['quantity'] = (int)$item['quantity'];
        $item['amount'] = (float)$item['amount'];
    }
    unset($item);
    foreach ($categories as &$category) {
        $category['category'] = $category['category'] ?? 'Uncategorized';
        $category['total'] = (float)$category['total'];
        $category['percentage'] = $expenses > 0 ? round($category['total'] / $expenses * 100, 1) : 0;
    }
    unset($category);
    return [
        'start' => $range['start']->format('Y-m-d'), 'end' => $range['end']->format('Y-m-d'),
        'revenue' => $revenue, 'expenses' => $expenses, 'profit' => round($revenue - $expenses, 2),
        'margin' => $revenue > 0 ? round(($revenue - $expenses) / $revenue * 100, 2) : null,
        'saleCount' => (int)array_sum(array_column($dailySales, 'count')),
        'expenseCount' => (int)array_sum(array_column($dailyExpenses, 'count')),
        'hasPos' => $hasPos, 'charts' => $charts, 'items' => $items, 'categories' => $categories,
    ];
}
