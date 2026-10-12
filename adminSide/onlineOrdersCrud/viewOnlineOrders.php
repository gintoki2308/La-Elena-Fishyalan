<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (!isLoggedIn() || (!isAdmin() && !isStaff())) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();
$orders = [];
$error = $_SESSION['order_update_error'] ?? null;
unset($_SESSION['order_update_error']);

try {
    $statusOrderSql = "CASE oo.status
                        WHEN 'PENDING' THEN 1
                        WHEN 'CONFIRMED' THEN 2
                        WHEN 'PREPARING' THEN 3
                        WHEN 'READY' THEN 4
                        WHEN 'OUT_FOR_DELIVERY' THEN 5
                        WHEN 'DELIVERED' THEN 6
                        WHEN 'COMPLETED' THEN 7
                        WHEN 'CANCELLED' THEN 8
                        ELSE 9
                    END";

    $query = "SELECT oo.*,
                     COALESCE(m.first_name, CONCAT('[Deleted] ', COALESCE(oo.delivery_name, 'Customer'))) AS first_name,
                     COALESCE(m.last_name, '') AS last_name
              FROM online_orders oo
              LEFT JOIN customer m ON oo.member_id = m.member_id
              ORDER BY {$statusOrderSql}, oo.order_date DESC";
    $stmt = $db->query($query);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $e->getMessage();
}

function getStatusColor($status)
{
    $colors = [
        'PENDING'          => 'warning',
        'CONFIRMED'        => 'info',
        'PREPARING'        => 'primary',
        'READY'            => 'success',
        'OUT_FOR_DELIVERY' => 'info',
        'DELIVERED'        => 'success',
        'COMPLETED'        => 'success',
        'CANCELLED'        => 'danger'
    ];
    return $colors[$status] ?? 'secondary';
}

function getOrderTransitions($status, $paymentMethod = 'COD')
{
    switch ($status) {
        case 'PENDING':
            return ['CONFIRMED' => 'Confirm', 'CANCELLED' => 'Cancel'];
        case 'CONFIRMED':
            return ['PREPARING' => 'Preparing'];
        case 'PREPARING':
            return ['READY' => 'Ready'];
        case 'READY':
            return ['OUT_FOR_DELIVERY' => 'Ship'];
        case 'OUT_FOR_DELIVERY':
            return ['DELIVERED' => 'Mark Delivered'];
        case 'DELIVERED':
            return ($paymentMethod === 'COD') ? ['COMPLETED' => 'Complete Order'] : [];
        default:
            return [];
    }
}

function isOrderFinalized($status, $paymentMethod = 'COD')
{
    return in_array($status, ['COMPLETED', 'CANCELLED'], true)
        || ($status === 'DELIVERED' && $paymentMethod !== 'COD');
}

$totalDelivered = count(array_filter($orders, fn($o) => $o['status'] === 'DELIVERED'));
$totalPending   = count(array_filter($orders, fn($o) => $o['status'] === 'PENDING'));
$totalRevenue   = array_sum(array_column(
    array_filter($orders, fn($o) => $o['status'] === 'DELIVERED'),
    'total_amount'
));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Orders - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="orders.css?v=<?= filemtime(__DIR__ . '/orders.css') ?>">
    <script src="orders.js?v=<?= filemtime(__DIR__ . '/orders.js') ?>" defer></script>
</head>
<body class="orders-page">
    <nav class="orders-topbar" aria-label="Main navigation">
        <div class="orders-topbar-inner">
            <a class="orders-brand" href="../admin-dashboard.php"><span class="brand-mark"><i class="fas fa-utensils" aria-hidden="true"></i></span> LA ELENA FISHYALAN</a>
            <span class="orders-role">ADMIN WORKSPACE</span>
        </div>
    </nav>
    <main class="orders-main">
        <a class="orders-back" href="../admin-dashboard.php"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Dashboard</a>
        <header class="orders-heading">
            <div>
                <p class="orders-eyebrow">Order management</p>
                <h1>Online Orders<span>.</span></h1>
                <p class="orders-description">Keep every order moving, from your kitchen to their doorstep.</p>
            </div>
            <button type="button" class="order-button" id="btn-print"><i class="fas fa-print" aria-hidden="true"></i> Print report</button>
        </header>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <section class="orders-stats" aria-label="Order summary" id="summary-stats">
            <div class="orders-stat featured">
                <div class="orders-stat-top"><span class="orders-stat-label">Total orders</span><span class="orders-stat-icon"><i class="fas fa-bag-shopping" aria-hidden="true"></i></span></div>
                <strong id="stat-total"><?= count($orders) ?></strong><span class="orders-stat-caption">Across your current selection</span>
            </div>
            <div class="orders-stat">
                <div class="orders-stat-top"><span class="orders-stat-label">Pending orders</span><span class="orders-stat-icon amber"><i class="far fa-clock" aria-hidden="true"></i></span></div>
                <strong id="stat-pending"><?= $totalPending ?></strong><span class="orders-stat-caption">Waiting for confirmation</span>
            </div>
            <div class="orders-stat">
                <div class="orders-stat-top"><span class="orders-stat-label">Delivered</span><span class="orders-stat-icon green"><i class="fas fa-check" aria-hidden="true"></i></span></div>
                <strong id="stat-delivered"><?= $totalDelivered ?></strong><span class="orders-stat-caption">Orders marked delivered</span>
            </div>
            <div class="orders-stat">
                <div class="orders-stat-top"><span class="orders-stat-label">Delivered revenue</span><span class="orders-stat-icon coral"><i class="fas fa-peso-sign" aria-hidden="true"></i></span></div>
                <strong id="stat-revenue">₱<?= number_format($totalRevenue, 2) ?></strong><span class="orders-stat-caption">From delivered orders</span>
            </div>
        </section>
        <section class="orders-panel" aria-labelledby="orders-list-title">
            <div class="orders-panel-heading">
                <div><h2 id="orders-list-title">Order overview <span class="orders-count" id="orders-count"><?= count($orders) ?></span></h2><p>View details and manage your incoming orders.</p></div>
                <span class="orders-sort"><i class="fas fa-arrow-down-short-wide" aria-hidden="true"></i> Active orders first</span>
            </div>
            <div class="orders-filters" role="search" aria-label="Filter orders">
                <div class="orders-filter"><label for="order-search">Search orders</label><div class="orders-search"><i class="fas fa-search" aria-hidden="true"></i><input type="search" id="order-search" placeholder="Order ID, customer, or address" autocomplete="off"></div></div>
                <div class="orders-filter"><label for="status-filter">Order status</label><select id="status-filter"><option value="">All statuses</option><?php foreach (['PENDING', 'CONFIRMED', 'PREPARING', 'READY', 'OUT_FOR_DELIVERY', 'DELIVERED', 'COMPLETED', 'CANCELLED'] as $status): ?><option value="<?= $status ?>"><?= ucwords(strtolower(str_replace('_', ' ', $status))) ?></option><?php endforeach; ?></select></div>
                <div class="orders-filter"><label for="date-from">From date</label><input type="date" id="date-from" aria-describedby="date-error"></div>
                <div class="orders-filter"><label for="date-to">To date</label><input type="date" id="date-to" aria-describedby="date-error"></div>
                <button type="button" class="order-button orders-reset" id="reset-filters"><i class="fas fa-rotate-left" aria-hidden="true"></i> Reset</button>
            </div>
            <div class="orders-filter-summary"><p id="filter-summary" role="status">Showing all <?= count($orders) ?> orders.</p><span class="orders-filter-error" id="date-error" role="alert" hidden>From date must be before or equal to To date.</span></div>
            <div class="print-header"><div class="ph-title">LA ELENA FISHYALAN</div><div class="ph-subtitle">Online Orders Report</div><div class="ph-meta" id="print-meta"></div></div>
            <div class="orders-table-wrap">
                <table class="orders-table" id="orders-table">
                    <caption class="visually-hidden">Online orders with customer, payment, delivery, and status information</caption>
                    <thead><tr><th scope="col">Order ID</th><th scope="col">Customer</th><th scope="col">Amount</th><th scope="col">Payment</th><th scope="col">Deliver to</th><th scope="col">Status</th><th scope="col">Date</th><th scope="col">Actions</th></tr></thead>
                    <tbody id="orders-tbody">
                        <?php foreach ($orders as $order):
                            $pm = $order['payment_method'] ?? 'COD';
                            $name = trim($order['first_name'] . ' ' . $order['last_name']);
                            $initials = mb_strtoupper(mb_substr($order['first_name'], 0, 1) . mb_substr($order['last_name'], 0, 1));
                            $transitions = getOrderTransitions($order['status'], $pm);
                            $searchText = $order['order_id'] . ' ' . $name . ' ' . ($order['delivery_address'] ?? '');
                        ?>
                        <tr data-status="<?= htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8') ?>" data-date="<?= htmlspecialchars(substr($order['order_date'], 0, 10), ENT_QUOTES, 'UTF-8') ?>" data-amount="<?= htmlspecialchars((string)$order['total_amount'], ENT_QUOTES, 'UTF-8') ?>" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>">
                            <td><a class="order-number" href="viewOrderDetails.php?id=<?= urlencode($order['order_id']) ?>">#<?= htmlspecialchars($order['order_id']) ?></a></td>
                            <td><div class="order-customer"><span class="customer-avatar" aria-hidden="true"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span><span class="customer-name"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span></div></td>
                            <td class="order-amount">₱<?= number_format($order['total_amount'], 2) ?></td>
                            <td><span class="payment-tag"><i class="fas <?= $pm === 'COD' ? 'fa-money-bill-wave' : 'fa-wallet' ?>" aria-hidden="true"></i> <?= htmlspecialchars($pm, ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><div class="order-address"><?= htmlspecialchars($order['delivery_address'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div></td>
                            <td><span class="order-status" data-status="<?= htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $order['status']))), ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td class="order-date"><?= date('M d, Y', strtotime($order['order_date'])) ?><span class="order-time"><?= date('h:i A', strtotime($order['order_date'])) ?></span></td>
                            <td><div class="order-actions">
                                <a class="order-button view-order" href="viewOrderDetails.php?id=<?= urlencode($order['order_id']) ?>" aria-label="View order #<?= htmlspecialchars($order['order_id']) ?>"><i class="far fa-eye" aria-hidden="true"></i> View</a>
                                <?php foreach ($transitions as $value => $label): ?>
                                    <form method="POST" action="manageOrder.php"><input type="hidden" name="order_id" value="<?= htmlspecialchars($order['order_id']) ?>"><input type="hidden" name="new_status" value="<?= $value ?>"><button type="submit" name="action" value="update_status" class="order-button <?= $value === 'CANCELLED' ? 'cancel' : 'primary' ?>"><?= $label ?></button></form>
                                <?php endforeach; ?>
                                <?php if (isOrderFinalized($order['status'], $pm)): ?><span class="order-finalized"><i class="fas fa-lock" aria-hidden="true"></i> Finalized</span><?php endif; ?>
                            </div></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mobile-cards" id="mobile-cards-wrap">
                <?php foreach ($orders as $order):
                    $pm = $order['payment_method'] ?? 'COD';
                    $name = trim($order['first_name'] . ' ' . $order['last_name']);
                    $initials = mb_strtoupper(mb_substr($order['first_name'], 0, 1) . mb_substr($order['last_name'], 0, 1));
                    $transitions = getOrderTransitions($order['status'], $pm);
                    $searchText = $order['order_id'] . ' ' . $name . ' ' . ($order['delivery_address'] ?? '');
                ?>
                <article class="order-card" aria-label="Order #<?= htmlspecialchars($order['order_id']) ?>" data-status="<?= htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8') ?>" data-date="<?= htmlspecialchars(substr($order['order_date'], 0, 10), ENT_QUOTES, 'UTF-8') ?>" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="oc-header"><a class="order-number" href="viewOrderDetails.php?id=<?= urlencode($order['order_id']) ?>">Order #<?= htmlspecialchars($order['order_id']) ?></a><span class="order-status" data-status="<?= htmlspecialchars($order['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $order['status']))), ENT_QUOTES, 'UTF-8') ?></span></div>
                    <div class="oc-customer"><div class="order-customer"><span class="customer-avatar" aria-hidden="true"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></span><span class="customer-name"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span></div><span class="order-amount">₱<?= number_format($order['total_amount'], 2) ?></span></div>
                    <div class="oc-address"><i class="fas fa-location-dot" aria-hidden="true"></i><span><?= htmlspecialchars($order['delivery_address'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span></div>
                    <div class="oc-meta"><span class="payment-tag"><?= htmlspecialchars($pm, ENT_QUOTES, 'UTF-8') ?></span><span class="order-date"><?= date('M d, Y · h:i A', strtotime($order['order_date'])) ?></span></div>
                    <div class="order-actions oc-actions">
                        <a class="order-button view-order" href="viewOrderDetails.php?id=<?= urlencode($order['order_id']) ?>"><i class="far fa-eye" aria-hidden="true"></i> View details</a>
                        <?php foreach ($transitions as $value => $label): ?>
                            <form method="POST" action="manageOrder.php"><input type="hidden" name="order_id" value="<?= htmlspecialchars($order['order_id']) ?>"><input type="hidden" name="new_status" value="<?= $value ?>"><button type="submit" name="action" value="update_status" class="order-button <?= $value === 'CANCELLED' ? 'cancel' : 'primary' ?>"><?= $label ?></button></form>
                        <?php endforeach; ?>
                        <?php if (isOrderFinalized($order['status'], $pm)): ?><span class="order-finalized"><i class="fas fa-lock" aria-hidden="true"></i> Finalized</span><?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <div class="orders-empty" id="orders-empty" <?= count($orders) ? 'hidden' : '' ?>>
                <span class="empty-icon"><i class="fas fa-bag-shopping" aria-hidden="true"></i></span>
                <h3 id="empty-title"><?= isset($error) ? 'Orders could not be loaded' : 'No orders yet' ?></h3><p id="empty-description"><?= isset($error) ? 'Please reload the page to try again.' : 'New online orders will appear here when customers check out.' ?></p>
            </div>
            <?php foreach (['desktop' => [10, 20, 50], 'mobile' => [5, 10, 20]] as $mode => $sizes): ?>
            <div class="orders-pagination" id="<?= $mode ?>-pagination-wrapper">
                <div class="pagination-info" id="<?= $mode ?>-pagination-info"></div><div class="pagination-controls" id="<?= $mode ?>-pagination-controls" role="group" aria-label="<?= ucfirst($mode) ?> order pages"></div>
                <div class="per-page-wrapper"><label for="<?= $mode ?>-per-page">Rows per page</label><select class="per-page-select" id="<?= $mode ?>-per-page"><?php foreach ($sizes as $size): ?><option value="<?= $size ?>"><?= $size ?></option><?php endforeach; ?><option value="9999">All</option></select></div>
            </div>
            <?php endforeach; ?>
        </section>
        <p class="orders-footnote"><i class="fas fa-circle-info" aria-hidden="true"></i> Summary reflects your filters. Print reports include all matching orders.</p>
    </main>
</body>
</html>
