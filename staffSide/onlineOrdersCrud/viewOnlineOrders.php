<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (!isLoggedIn() || (!isStaff() && !isAdmin())) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$user = getCurrentUser();
$navAvatarSrc = getProfileAvatar($user['profile_picture'] ?? '', $user['first_name'] ?? '', $user['last_name'] ?? '');
$database = new Database();
$db = $database->connect();
$orders = [];
$error = $_SESSION['order_update_error'] ?? null;
unset($_SESSION['order_update_error']);

// Filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$date_from = getValidDate($_GET['date_from'] ?? '');
$date_to = getValidDate($_GET['date_to'] ?? '');

if ($date_from && $date_to && $date_from > $date_to) {
    [$date_from, $date_to] = [$date_to, $date_from];
}

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
              WHERE 1=1";
    $params = [];
    if ($status_filter !== 'all') {
        $query .= " AND oo.status = :status";
        $params[':status'] = $status_filter;
    }
    if ($date_from) {
        $query .= " AND oo.order_date >= :date_from";
        $params[':date_from'] = $date_from . ' 00:00:00';
    }
    if ($date_to) {
        $query .= " AND oo.order_date < :date_to";
        $params[':date_to'] = date('Y-m-d', strtotime($date_to . ' +1 day')) . ' 00:00:00';
    }
    $query .= " ORDER BY {$statusOrderSql}, oo.order_date DESC";

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $e->getMessage();
}

function getStatusColor($status) {
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

function getOrderTransitions($status, $paymentMethod = 'COD') {
    switch ($status) {
        case 'PENDING':
            return ['CONFIRMED' => 'Confirm', 'CANCELLED' => 'Cancel'];
        case 'CONFIRMED':
            return ['PREPARING' => 'Preparing'];
        case 'PREPARING':
            return ['READY' => 'Mark Ready'];
        case 'READY':
            return ['OUT_FOR_DELIVERY' => 'Out for Delivery'];
        case 'OUT_FOR_DELIVERY':
            return ['DELIVERED' => 'Mark Delivered'];
        case 'DELIVERED':
            return ($paymentMethod === 'COD') ? ['COMPLETED' => 'Complete Order'] : [];
        default:
            return [];
    }
}

function isOrderFinalized($status, $paymentMethod = 'COD') {
    return in_array($status, ['COMPLETED', 'CANCELLED'], true)
        || ($status === 'DELIVERED' && $paymentMethod !== 'COD');
}

$all_statuses = ['PENDING','CONFIRMED','PREPARING','READY','OUT_FOR_DELIVERY','DELIVERED','COMPLETED','CANCELLED'];

function getValidDate($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

function orderFilterQuery($overrides = []) {
    global $status_filter, $date_from, $date_to;

    $params = array_merge([
        'status' => $status_filter,
        'date_from' => $date_from,
        'date_to' => $date_to,
    ], $overrides);

    return http_build_query(array_filter($params, static function ($value) {
        return $value !== '' && $value !== null;
    }));
}

function formatDateRangeLabel($date_from, $date_to) {
    if ($date_from && $date_to) {
        return date('M d, Y', strtotime($date_from)) . ' - ' . date('M d, Y', strtotime($date_to));
    }
    if ($date_from) {
        return 'From ' . date('M d, Y', strtotime($date_from));
    }
    if ($date_to) {
        return 'Until ' . date('M d, Y', strtotime($date_to));
    }

    return 'All dates';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Orders - Staff Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../staff-dashboard.css">
    <style>
        :root {
            --gold: #e56b4f;
            --gold-hover: #c9533b;
            --dark: #fffdf8;
            --darker: #f3eee4;
            --surface: #ffffff;
            --border: rgba(20, 61, 74, 0.14);
            --text: #143d4a;
            --muted: #63777a;
        }

        * { box-sizing: border-box; }

        body {
            background: linear-gradient(135deg, #f8f1e5 0%, #e5f2ef 52%, #f9e5d8 100%);
            color: var(--text);
            font-family: 'Montserrat', sans-serif;
            min-height: 100vh;
        }

        .navbar {
            background-color: #fff !important;
            border-bottom: 1px solid var(--border);
        }

        .navbar-brand {
            color: var(--text) !important;
            font-family: 'Playfair Display', serif;
            font-size: 1.4rem;
        }

        .navbar-brand:hover { color: var(--gold-hover) !important; }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 1.25rem;
        }

        .page-header h1 {
            font-size: clamp(1.2rem, 4vw, 1.75rem);
            margin: 0;
            color: var(--text);
            font-family: 'Playfair Display', serif;
            font-weight: 700;
        }

        .header-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-gold {
            background-color: var(--gold);
            border-color: var(--gold);
            color: #000 !important;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .btn-gold:hover {
            background-color: var(--gold-hover);
            border-color: var(--gold-hover);
            color: #000 !important;
            box-shadow: 0 0 10px rgba(212,175,55,0.4);
        }

        .btn-gold:disabled { opacity: 0.7; cursor: not-allowed; }

        .text-gold { color: var(--gold) !important; }

        .main-content { background: transparent; }
        .orders-header {
            border-bottom: 2px solid var(--gold);
            padding-bottom: 16px;
            margin-bottom: 1.5rem;
        }

        /* ── Filter bar ───────────────────────────────────────── */
        .filter-bar {
            background: var(--dark);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            box-shadow: 0 4px 14px rgba(31, 35, 40, 0.04);
        }

        .filter-bar label {
            color: var(--muted);
            font-size: 0.82rem;
            white-space: nowrap;
            margin: 0;
        }

        .filter-status-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .filter-pill {
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 0.76rem;
            font-weight: 600;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--muted);
            cursor: pointer;
            text-decoration: none;
            transition: all 0.18s;
            white-space: nowrap;
        }

        .filter-pill:hover {
            border-color: var(--gold);
            color: #856b14;
            background: #fffaf0;
        }

        .filter-pill.active {
            background: var(--gold);
            border-color: var(--gold);
            color: #000;
        }

        .filter-count {
            font-size: 0.82rem;
            color: var(--muted);
            margin-left: auto;
            white-space: nowrap;
        }

        .filter-count span { color: var(--gold); font-weight: 700; }

        .date-filter {
            width: 100%;
            display: flex;
            align-items: end;
            flex-wrap: wrap;
            gap: 10px;
            padding-top: 10px;
            border-top: 1px solid var(--border);
        }

        .date-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .date-field input {
            height: 34px;
            min-width: 160px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface);
            color: var(--text);
            padding: 5px 10px;
            font-size: 0.82rem;
        }

        .date-field input:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 0 0.15rem rgba(212,175,55,0.2);
        }

        .date-filter-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .print-report-heading {
            display: none;
        }

        /* ── Card / table ─────────────────────────────────────── */
        .card {
            background-color: var(--dark);
            border-color: var(--border);
            box-shadow: 0 6px 18px rgba(31, 35, 40, 0.06) !important;
        }

        .table,
        .table.table-dark {
            background-color: var(--dark);
            margin-bottom: 0;
            --bs-table-bg: #fff;
            --bs-table-color: var(--text);
            --bs-table-border-color: var(--border);
            --bs-table-hover-bg: #fffaf0;
            --bs-table-hover-color: var(--text);
        }

        .table th {
            color: #856b14;
            background-color: var(--surface);
            border-bottom: 2px solid var(--border);
            white-space: nowrap;
            font-size: 0.85rem;
            padding: 12px 14px;
        }

        .table td {
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
            font-size: 0.88rem;
            padding: 10px 14px;
            color: var(--text);
        }

        .table tbody tr:hover { background-color: #fffaf0; }
        .table tbody tr.hidden { display: none !important; }

        .form-select-sm {
            background-color: var(--surface);
            color: var(--text);
            border: 1px solid var(--border);
            font-size: 0.75rem;
        }

        /* ── Pagination ───────────────────────────────────────── */
        .pagination-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }

        .pagination-info {
            font-size: 0.82rem;
            color: var(--muted);
        }

        .pagination-info span { color: var(--gold); font-weight: 600; }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 5px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .page-btn {
            min-width: 38px;
            height: 38px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: var(--dark);
            color: var(--text);
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 10px;
            transition: all 0.2s;
        }

        .page-btn:hover:not(:disabled) {
            border-color: var(--gold);
            color: var(--gold);
            background: rgba(212,175,55,0.08);
        }

        .page-btn.active {
            background: var(--gold);
            border-color: var(--gold);
            color: #000;
        }

        .page-btn:disabled { opacity: 0.35; cursor: not-allowed; }

        .page-btn.ellipsis {
            border: none;
            background: transparent;
            cursor: default;
            color: var(--muted);
        }

        .per-page-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.82rem;
            color: var(--muted);
        }

        .per-page-select {
            background: var(--dark);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 8px;
            padding: 4px 10px;
            font-size: 0.82rem;
            cursor: pointer;
        }

        .per-page-select:focus { outline: none; border-color: var(--gold); }

        /* ── Mobile order cards ───────────────────────────────── */
        .order-card {
            background: var(--dark);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 16px;
            margin-bottom: 12px;
            transition: border-color 0.2s;
        }

        .order-card:hover { border-color: var(--gold); }

        .order-card.hidden { display: none !important; }

        .order-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
            margin-bottom: 10px;
        }

        .order-id { font-weight: 700; color: var(--gold); font-size: 1rem; }

        .order-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 12px;
            margin-bottom: 12px;
            font-size: 0.82rem;
        }

        .order-meta-item { display: flex; flex-direction: column; gap: 2px; }
        .order-meta-label { color: var(--muted); font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; }
        .order-meta-value { color: var(--text); font-weight: 500; }

        .order-actions { display: flex; flex-direction: column; gap: 8px; }

        .order-action-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        /* ── Empty state ──────────────────────────────────────── */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 3rem;
            color: var(--gold);
            opacity: 0.35;
            margin-bottom: 1rem;
            display: block;
        }

        .empty-state h5 { color: var(--text) !important; }

        .btn-outline-light {
            --bs-btn-color: var(--text);
            --bs-btn-border-color: #c9ced6;
            --bs-btn-hover-color: var(--text);
            --bs-btn-hover-bg: var(--surface);
            --bs-btn-hover-border-color: #aeb5bf;
            --bs-btn-active-color: var(--text);
            --bs-btn-active-bg: var(--surface);
            --bs-btn-active-border-color: #aeb5bf;
        }

        /* ── Print ────────────────────────────────────────────── */
        @media print {
            @page { margin: 10mm; size: landscape; }
            body { background: #fff !important; color: #000 !important; }
            .navbar, .sidebar, .btn, .form-select, .filter-bar, .pagination-wrapper,
            td:last-child, th:last-child,
            .order-actions, .header-actions { display: none !important; }
            .desktop-table { display: block !important; }
            .mobile-cards { display: none !important; }

            /* Only show rows that are NOT hidden (i.e. visible in current page/filter) */
            .table tbody tr.hidden { display: none !important; }
            /* Also show all non-hidden rows when printing — remove pagination hiding */
            .table tbody tr.order-row:not(.print-exclude) { display: table-row !important; }

            .print-report-heading {
                display: block !important;
                margin-bottom: 12px;
                color: #000 !important;
            }
            .print-report-heading h2 {
                font-size: 18px;
                margin: 0 0 4px;
                color: #000 !important;
            }
            .print-report-heading p {
                margin: 0;
                font-size: 11px;
                color: #333 !important;
            }
            .card { background: #fff !important; border: 1px solid #ccc !important; }
            .table { background: #fff !important; color: #000 !important; }
            .table th {
                background-color: #2d2d2d !important;
                color: #d4af37 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .table td { color: #000 !important; background: #fff !important; }
            .badge { border: 1px solid #555 !important; color: #000 !important; background: transparent !important; }
        }

        /* ── Responsive ───────────────────────────────────────── */
        .desktop-table { display: block; }
        .mobile-cards  { display: none; }

        @media (max-width: 767px) {
            .desktop-table { display: none; }
            .mobile-cards  { display: block; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .header-actions { width: 100%; }
            .header-actions .btn { flex: 1; justify-content: center; }
            .date-field, .date-field input, .date-filter-actions { width: 100%; }
            .date-filter-actions .btn { flex: 1; }
            .page-btn { min-width: 34px; height: 34px; font-size: 0.78rem; }
            body { padding-bottom: 16px; }
        }

        @media (max-width: 991px) and (min-width: 768px) {
            .table th, .table td { padding: 9px 10px; font-size: 0.83rem; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark sticky-top">
    <div class="container-fluid">
        <button class="navbar-toggler me-2 d-lg-none" type="button" id="sidebarToggle" aria-label="Toggle sidebar" aria-controls="mainSidebar" aria-expanded="false">
            <span class="navbar-toggler-icon"></span>
        </button>
        <a class="navbar-brand" href="../staff-dashboard.php">
            <i class="fas fa-user-tie me-2"></i>Staff Panel
        </a>
        <div class="ms-auto">
            <div class="nav-item dropdown">
                <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <img src="<?php echo htmlspecialchars($navAvatarSrc); ?>" alt="" class="rounded-circle me-2" width="35" height="35" style="object-fit:cover;border:1.5px solid var(--gold);">
                    <span class="d-none d-sm-inline"><?php echo htmlspecialchars($user['first_name'] ?? 'Staff'); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="../profile.php"><i class="fas fa-user-tie me-2"></i>My Profile</a></li>
                    <li><a class="dropdown-item" href="../settings.php"><i class="fas fa-cog me-2"></i>Account Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<div class="layout-shell">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <aside class="sidebar" id="mainSidebar" aria-label="Staff navigation">
        <div class="sidebar-header">
            <h5 class="text-gold mb-3"><i class="fas fa-tasks me-2"></i>Dashboard</h5>
            <button class="sidebar-close d-lg-none" type="button" aria-label="Close sidebar">&times;</button>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="../staff-dashboard.php"><i class="fas fa-home me-2"></i>Overview</a>
            <a class="nav-link active" href="viewOnlineOrders.php"><i class="fas fa-shopping-cart me-2"></i>Orders</a>
            <a class="nav-link" href="../viewSchedule.php"><i class="fas fa-calendar me-2"></i>My Shifts</a>
            <a class="nav-link" href="../viewAttendance.php"><i class="fas fa-clock me-2"></i>Attendance</a>
            <a class="nav-link" href="../viewReservations.php"><i class="fas fa-calendar-alt me-2"></i>Reservations</a>
            <a class="nav-link" href="../menuManagementCrud/viewMenuItems.php"><i class="fas fa-utensils me-2"></i>Menu Management</a>
            <a class="nav-link" href="../inventoryCrud/viewInventory.php"><i class="fas fa-boxes me-2"></i>Inventory</a>
            <a class="nav-link" href="../profile.php"><i class="fas fa-user me-2"></i>My Profile</a>
            <a class="nav-link" href="../settings.php"><i class="fas fa-cog me-2"></i>Settings</a>
        </nav>
    </aside>

    <main class="main-content" id="mainContent">

    <!-- Page header -->
    <div class="page-header orders-header">
        <h1>
            <i class="fas fa-shopping-cart text-gold me-2"></i>Online Orders
        </h1>
        <div class="header-actions">
            <button class="btn btn-outline-light btn-sm" onclick="printOrders()">
                <i class="fas fa-print me-1"></i>Print
            </button>
        </div>
    </div>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- ── Status Filter Bar ─────────────────────────────────────── -->
    <div class="filter-bar">
        <label><i class="fas fa-filter me-1"></i>Filter by status:</label>
        <div class="filter-status-pills">
            <a href="?<?php echo htmlspecialchars(orderFilterQuery(['status' => 'all'])); ?>"
               class="filter-pill <?php echo $status_filter === 'all' ? 'active' : ''; ?>">
                All
            </a>
            <?php foreach ($all_statuses as $s): ?>
                <a href="?<?php echo htmlspecialchars(orderFilterQuery(['status' => $s])); ?>"
                   class="filter-pill <?php echo $status_filter === $s ? 'active' : ''; ?>">
                    <?php echo $s; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="filter-count">
            Showing <span><?php echo count($orders); ?></span>
            <?php echo $status_filter !== 'all' ? htmlspecialchars($status_filter) : ''; ?> orders
        </div>
        <form method="GET" class="date-filter">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
            <div class="date-field">
                <label for="date_from"><i class="fas fa-calendar-day me-1"></i>From</label>
                <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="date-field">
                <label for="date_to"><i class="fas fa-calendar-day me-1"></i>To</label>
                <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
            </div>
            <div class="date-filter-actions">
                <button type="submit" class="btn btn-sm btn-gold">
                    <i class="fas fa-check me-1"></i>Apply
                </button>
                <a href="?<?php echo htmlspecialchars(orderFilterQuery(['date_from' => '', 'date_to' => ''])); ?>" class="btn btn-sm btn-outline-light">
                    <i class="fas fa-rotate-left me-1"></i>Clear Dates
                </a>
            </div>
        </form>
    </div>

    <div class="print-report-heading">
        <h2>Online Orders Report</h2>
        <p>
            Date range: <?php echo htmlspecialchars(formatDateRangeLabel($date_from, $date_to)); ?>
            &nbsp;|&nbsp;
            Status: <?php echo $status_filter !== 'all' ? htmlspecialchars($status_filter) : 'All statuses'; ?>
            &nbsp;|&nbsp;
            Total orders: <span id="printTotalOrders"><?php echo count($orders); ?></span>
        </p>
    </div>

    <!-- ── Desktop table ─────────────────────────────────────────── -->
    <div class="desktop-table">
        <div class="card shadow-lg">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover" style="min-width:860px;" id="desktopTable">
                        <thead>
                            <tr>
                                <th class="ps-3">Order ID</th>
                                <th>Customer</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="desktopTbody">
                            <?php foreach ($orders as $order): ?>
                            <tr class="order-row">
                                <td class="ps-3 fw-bold text-gold">
                                    #<?php echo htmlspecialchars($order['order_id']); ?>
                                </td>
                                <td><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></td>
                                <td>₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                <td>
                                    <span class="badge rounded-pill bg-<?php echo getStatusColor($order['status']); ?>">
                                        <?php echo htmlspecialchars($order['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M d, Y H:i', strtotime($order['order_date'])); ?></td>
                                <td class="pe-3">
                                    <div class="d-flex flex-column gap-2 align-items-start">
                                        <a href="viewOrderDetails.php?id=<?php echo urlencode($order['order_id']); ?>"
                                           class="btn btn-sm btn-gold w-100">
                                            <i class="fas fa-eye me-1"></i>View Details
                                        </a>
                                        <?php $transitions = getOrderTransitions($order['status'], $order['payment_method'] ?? 'COD'); ?>
                                        <?php if (!empty($transitions)): ?>
                                        <div class="d-flex flex-wrap gap-1">
                                            <?php foreach ($transitions as $value => $label): ?>
                                            <form method="POST" action="manageOrder.php" class="m-0">
                                                <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                                <input type="hidden" name="new_status" value="<?php echo $value; ?>">
                                                <button type="submit" name="action" value="update_status"
                                                        class="btn btn-<?php echo getStatusColor($value); ?> py-0 px-2" style="font-size:0.72rem;">
                                                    <?php echo $label; ?>
                                                </button>
                                            </form>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php elseif (isOrderFinalized($order['status'], $order['payment_method'] ?? 'COD')): ?>
                                        <span class="text-gold" style="font-size:0.78rem;"><i class="fas fa-lock me-1"></i>Finalized</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>

                            <?php if (empty($orders)): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <h5 style="color:#ccc;">No orders found</h5>
                                        <p>There are no orders matching the selected status.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Desktop Pagination -->
        <?php if (!empty($orders)): ?>
        <div class="pagination-wrapper" id="desktopPaginationWrapper">
            <div class="pagination-info" id="desktopPaginationInfo"></div>
            <div class="pagination-controls" id="desktopPaginationControls"></div>
            <div class="per-page-wrapper">
                <label for="desktopPerPage">Rows per page:</label>
                <select id="desktopPerPage" class="per-page-select">
                    <option value="10" selected>10</option>
                    <option value="20">20</option>
                    <option value="50">50</option>
                    <option value="999">All</option>
                </select>
            </div>
        </div>
        <?php endif; ?>
    </div><!-- /desktop-table -->

    <!-- ── Mobile cards ───────────────────────────────────────────── -->
    <div class="mobile-cards">
        <div id="mobileCardsContainer">
            <?php foreach ($orders as $order): ?>
            <div class="order-card mobile-order-card">
                <div class="order-card-header">
                    <span class="order-id">#<?php echo htmlspecialchars($order['order_id']); ?></span>
                    <span class="badge rounded-pill bg-<?php echo getStatusColor($order['status']); ?>">
                        <?php echo htmlspecialchars($order['status']); ?>
                    </span>
                </div>

                <div class="order-meta">
                    <div class="order-meta-item">
                        <span class="order-meta-label">Customer</span>
                        <span class="order-meta-value">
                            <?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?>
                        </span>
                    </div>
                    <div class="order-meta-item">
                        <span class="order-meta-label">Amount</span>
                        <span class="order-meta-value text-gold fw-bold">
                            ₱<?php echo number_format($order['total_amount'], 2); ?>
                        </span>
                    </div>
                    <div class="order-meta-item" style="grid-column: span 2;">
                        <span class="order-meta-label">Date</span>
                        <span class="order-meta-value">
                            <?php echo date('M d, Y H:i', strtotime($order['order_date'])); ?>
                        </span>
                    </div>
                </div>

                <div class="order-actions">
                    <a href="viewOrderDetails.php?id=<?php echo urlencode($order['order_id']); ?>"
                       class="btn btn-sm btn-gold w-100">
                        <i class="fas fa-eye me-1"></i>View Details
                    </a>

                    <?php $transitions = getOrderTransitions($order['status'], $order['payment_method'] ?? 'COD'); ?>
                    <?php if (!empty($transitions)): ?>
                    <div class="order-action-row">
                        <?php foreach ($transitions as $value => $label): ?>
                            <form method="POST" action="manageOrder.php" class="m-0 flex-grow-1">
                                <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>">
                                <input type="hidden" name="new_status" value="<?php echo $value; ?>">
                                <button type="submit" name="action" value="update_status"
                                        class="btn btn-sm btn-<?php echo getStatusColor($value); ?> w-100" style="font-size:0.78rem;">
                                    <?php echo $label; ?>
                                </button>
                            </form>
                        <?php endforeach; ?>
                    </div>
                    <?php elseif (isOrderFinalized($order['status'], $order['payment_method'] ?? 'COD')): ?>
                    <span class="text-muted" style="font-size:0.78rem;"><i class="fas fa-lock me-1"></i>Finalized</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (empty($orders)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <h5 style="color:#ccc;">No orders found</h5>
                    <p>There are no orders matching the selected status.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Mobile Pagination -->
        <?php if (!empty($orders)): ?>
        <div class="pagination-wrapper" id="mobilePaginationWrapper">
            <div class="pagination-info" id="mobilePaginationInfo"></div>
            <div class="pagination-controls" id="mobilePaginationControls"></div>
            <div class="per-page-wrapper">
                <label for="mobilePerPage">Per page:</label>
                <select id="mobilePerPage" class="per-page-select">
                    <option value="5" selected>5</option>
                    <option value="10">10</option>
                    <option value="20">20</option>
                    <option value="999">All</option>
                </select>
            </div>
        </div>
        <?php endif; ?>
    </div><!-- /mobile-cards -->

    </main><!-- /main-content -->
</div><!-- /layout-shell -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="../staff-sidebar.js"></script>
<script>
// ── Pagination (shared logic) ────────────────────────────────────────
function buildPaginator({ items, infoEl, controlsEl, wrapperEl, perPageEl, scrollTarget }) {
    let currentPage  = 1;
    let itemsPerPage = parseInt(perPageEl.value);

    // Expose current page info for print
    wrapperEl._getPaginatorState = () => ({ currentPage, itemsPerPage, items });

    function render() {
        const total      = items.length;
        const perPage    = itemsPerPage >= 999 ? Infinity : itemsPerPage;
        const totalPages = perPage === Infinity ? 1 : Math.ceil(total / perPage);
        if (currentPage > totalPages) currentPage = totalPages;

        const start = perPage === Infinity ? 0 : (currentPage - 1) * perPage;
        const end   = perPage === Infinity ? total : Math.min(start + perPage, total);

        items.forEach((el, i) => {
            el.classList.toggle('hidden', i < start || i >= end);
            // Mark rows outside the current filtered date range so print can exclude them
            el.classList.toggle('print-exclude', i < start || i >= end);
        });

        // Info text
        if (perPage === Infinity) {
            infoEl.innerHTML = `Showing all <span>${total}</span> orders`;
        } else {
            infoEl.innerHTML = `Page <span>${currentPage}</span> of <span>${totalPages}</span> &nbsp;·&nbsp; Showing <span>${start + 1}–${end}</span> of <span>${total}</span>`;
        }

        // Controls
        if (totalPages <= 1) {
            controlsEl.innerHTML = '';
            wrapperEl.style.display = perPage === Infinity ? 'flex' : 'none';
            return;
        }

        wrapperEl.style.display = 'flex';
        controlsEl.innerHTML    = '';

        const btn = (label, page, disabled, active, ellipsis) => {
            const b = document.createElement('button');
            b.className = 'page-btn' + (active ? ' active' : '') + (ellipsis ? ' ellipsis' : '');
            b.innerHTML = label;
            b.disabled  = disabled;
            if (!ellipsis && !disabled) {
                b.addEventListener('click', () => {
                    currentPage = page;
                    render();
                    if (scrollTarget) scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            }
            controlsEl.appendChild(b);
        };

        btn('<i class="fas fa-chevron-left"></i>', currentPage - 1, currentPage === 1);

        // Smart ellipsis
        const delta = 2;
        const range = [];
        const rangeWithDots = [];
        let l;
        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= currentPage - delta && i <= currentPage + delta)) range.push(i);
        }
        for (let i of range) {
            if (l) {
                if (i - l === 2) rangeWithDots.push(l + 1);
                else if (i - l !== 1) rangeWithDots.push('...');
            }
            rangeWithDots.push(i);
            l = i;
        }
        rangeWithDots.forEach(p => {
            if (p === '...') btn('&hellip;', null, true, false, true);
            else btn(p, p, false, p === currentPage);
        });

        btn('<i class="fas fa-chevron-right"></i>', currentPage + 1, currentPage === totalPages);
    }

    perPageEl.addEventListener('change', () => {
        itemsPerPage = parseInt(perPageEl.value);
        currentPage  = 1;
        render();
    });

    render();
    return { render };
}

// Desktop table rows
const desktopRows = Array.from(document.querySelectorAll('#desktopTbody .order-row'));
if (desktopRows.length) {
    buildPaginator({
        items:       desktopRows,
        infoEl:      document.getElementById('desktopPaginationInfo'),
        controlsEl:  document.getElementById('desktopPaginationControls'),
        wrapperEl:   document.getElementById('desktopPaginationWrapper'),
        perPageEl:   document.getElementById('desktopPerPage'),
        scrollTarget: document.getElementById('desktopTable')
    });
}

// Mobile cards
const mobileCards = Array.from(document.querySelectorAll('.mobile-order-card'));
if (mobileCards.length) {
    buildPaginator({
        items:       mobileCards,
        infoEl:      document.getElementById('mobilePaginationInfo'),
        controlsEl:  document.getElementById('mobilePaginationControls'),
        wrapperEl:   document.getElementById('mobilePaginationWrapper'),
        perPageEl:   document.getElementById('mobilePerPage'),
        scrollTarget: document.getElementById('mobileCardsContainer')
    });
}

// ── Print: show all filtered rows (ignore pagination), then restore ──
function printOrders() {
    // Temporarily un-hide all order rows so the full filtered set prints
    const allRows = Array.from(document.querySelectorAll('#desktopTbody .order-row'));
    const hiddenRows = allRows.filter(r => r.classList.contains('hidden'));

    // Remove hidden/print-exclude temporarily
    hiddenRows.forEach(r => {
        r.classList.remove('hidden', 'print-exclude');
        r._wasHidden = true;
    });

    // Update the printed total count to reflect the full filtered set
    const printTotal = document.getElementById('printTotalOrders');
    if (printTotal) printTotal.textContent = allRows.length;

    // Print
    window.print();

    // Restore hidden rows after print dialog closes
    hiddenRows.forEach(r => {
        if (r._wasHidden) {
            r.classList.add('hidden', 'print-exclude');
            delete r._wasHidden;
        }
    });
}
</script>

</body>
</html>
