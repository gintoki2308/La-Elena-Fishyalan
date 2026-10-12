<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../config/recipe_inventory_helper.php';

if (!isLoggedIn() || !isCustomer()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

if (!isset($_GET['id'])) {
    header('Location: orders.php');
    exit;
}

$order_id = $_GET['id'];
$database = new Database();
$db = $database->connect();
$cancelCsrf = $_SESSION['cancel_order_csrf'] ?? ($_SESSION['cancel_order_csrf'] = bin2hex(random_bytes(32)));

function ensureStockDeductedColumn(PDO $db) {
    $colStmt = $db->query("SHOW COLUMNS FROM online_orders LIKE 'stock_deducted'");
    if (!$colStmt->fetch(PDO::FETCH_ASSOC)) {
        $db->exec("ALTER TABLE online_orders ADD COLUMN stock_deducted TINYINT(1) DEFAULT 0 AFTER metrics_added");
    }

    $db->exec("UPDATE online_orders SET stock_deducted = 1 WHERE status IN ('DELIVERED', 'COMPLETED') AND COALESCE(stock_deducted, 0) = 0");
}

// Handle Cancel Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order_id']) && hash_equals($cancelCsrf, inventoryText($_POST, 'csrf_token'))) {
    ensureStockDeductedColumn($db);
    $cancel_id = $_POST['cancel_order_id'];
    $chk = $db->prepare("SELECT * FROM online_orders WHERE order_id=:oid AND member_id=:mid AND status='PENDING'");
    $chk->execute([':oid' => $cancel_id, ':mid' => $_SESSION['user_id']]);
    $cancel_order = $chk->fetch(PDO::FETCH_ASSOC);

    if ($cancel_order) {
        $db->beginTransaction();
        try {
            // Recheck under lock: confirmation or a second cancel may have won the race.
            $locked = $db->prepare("SELECT * FROM online_orders WHERE order_id = ? AND member_id = ? AND status = 'PENDING' FOR UPDATE");
            $locked->execute([$cancel_id, $_SESSION['user_id']]);
            $cancel_order = $locked->fetch(PDO::FETCH_ASSOC);
            if (!$cancel_order) throw new InvalidArgumentException('This order can no longer be cancelled.');
            $db->prepare("UPDATE online_orders SET status='CANCELLED', updated_at=NOW() WHERE order_id=:oid")->execute([':oid' => $cancel_id]);
            
            // Restore inventory only if it was deducted, then reinstate cart.
            $itemStmt = $db->prepare("SELECT item_id, quantity FROM online_order_items WHERE order_id=:oid");
            $itemStmt->execute([':oid' => $cancel_id]);
            $itemsToRestore = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            $restoreStock = $db->prepare("UPDATE menu_items SET stock_quantity = stock_quantity + :qty1, is_available = IF(stock_quantity + :qty2 > 0, 1, is_available) WHERE item_id = :iid");

            foreach ($itemsToRestore as $i) {
                if (!empty($cancel_order['stock_deducted'])) {
                    $restoreStock->execute([
                        ':qty1' => $i['quantity'],
                        ':qty2' => $i['quantity'],
                        ':iid'  => $i['item_id']
                    ]);
                }
                
            }

            if (!empty($cancel_order['stock_deducted'])) {
                $db->prepare("UPDATE online_orders SET stock_deducted=0 WHERE order_id=:oid")->execute([':oid'=>$cancel_id]);
            }

            restoreRecipeInventory($db, 'ONLINE', (int)$cancel_id);
            $db->commit();
            foreach ($itemsToRestore as $i) {
                $_SESSION['cart'][$i['item_id']] = ($_SESSION['cart'][$i['item_id']] ?? 0) + (int)$i['quantity'];
            }
            header("Location: order-details.php?id=".$cancel_id);
            exit;
        } catch (Exception $e) { 
            $db->rollBack(); 
            error_log("Cancel Order Error: " . $e->getMessage());
        }
    }
}

// Fetch Order
try {
    $stmt = $db->prepare("SELECT * FROM online_orders WHERE order_id=:id AND member_id=:mid");
    $stmt->execute([':id'=>$order_id,':mid'=>$_SESSION['user_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) { header('Location: orders.php'); exit; }

    $cancelled_by_management = false;
    if ($order['status'] === 'CANCELLED') {
        $auditStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE table_affected = 'online_orders' AND record_id = :oid AND action = 'UPDATE_ORDER_STATUS_CANCELLED'");
        $auditStmt->execute([':oid' => $order_id]);
        $cancelled_by_management = $auditStmt->fetchColumn() > 0;
    }

    $itemStmt = $db->prepare("SELECT oi.*, m.item_name FROM online_order_items oi JOIN menu_items m ON oi.item_id=m.item_id WHERE oi.order_id=:oid");
    $itemStmt->execute([':oid'=>$order_id]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { die("Error loading order details: " . htmlspecialchars($e->getMessage())); }

function getStatusBadge($status) {
    $map = [
        'PENDING'          => ['rgba(212,175,55,.15)',  '#D4AF37', 'rgba(212,175,55,.3)',  'PENDING'],
        'CONFIRMED'        => ['rgba(13,202,240,.15)',  '#0dcaf0', 'rgba(13,202,240,.3)',  'CONFIRMED'],
        'PREPARING'        => ['rgba(13,110,253,.15)',  '#0d6efd', 'rgba(13,110,253,.3)',  'PREPARING'],
        'READY'            => ['rgba(13,110,253,.15)',  '#0d6efd', 'rgba(13,110,253,.3)',  'READY'],
        'OUT_FOR_DELIVERY' => ['rgba(13,202,240,.15)',  '#0dcaf0', 'rgba(13,202,240,.3)',  'OUT FOR DELIVERY'],
        'DELIVERED'        => ['rgba(46,204,113,.15)',  '#2ecc71', 'rgba(46,204,113,.3)',  'DELIVERED'],
        'COMPLETED'        => ['rgba(46,204,113,.15)',  '#2ecc71', 'rgba(46,204,113,.3)',  'COMPLETED'],
        'CANCELLED'        => ['rgba(220,53,69,.15)',   '#dc3545', 'rgba(220,53,69,.3)',   'CANCELLED'],
    ];
    if (isset($map[$status])) {
        [$bg, $color, $border, $label] = $map[$status];
        return "<span class='badge' style='background:{$bg};color:{$color};border:1px solid {$border};padding:.4rem .9rem;font-size:.8rem;'>{$label}</span>";
    }
    return "<span class='badge bg-secondary'>UNKNOWN</span>";
}

function formatOrderLabel($v) {
    $v = trim((string)$v);
    if ($v==='' || strtoupper($v)==='N/A') return 'N/A';
    return ucwords(strtolower(str_replace('_',' ',$v)));
}
function getPaymentMethodLabel($pm) {
    return ['COD'=>'Cash on Delivery','GCASH'=>'GCash','PAYPAL'=>'PayPal'][$pm] ?? formatOrderLabel($pm);
}

$items_subtotal = array_sum(array_map(fn($i) => $i['quantity'] * $i['item_price'], $items));
$discount_amount  = (float)($order['discount_amount'] ?? 0);
$grand_total      = (float)($order['total_amount'] ?? 0);
$tax_amount       = max(0, ($grand_total + $discount_amount) - $items_subtotal);
$order_reference  = !empty($order['order_number']) ? $order['order_number'] : ('#'.$order['order_id']);
$payment_method_label = getPaymentMethodLabel($order['payment_method'] ?? 'N/A');
$status_label     = formatOrderLabel($order['status'] ?? 'UNKNOWN');
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
$cart_count = array_sum($_SESSION['cart']);

$favStmt = $db->prepare("SELECT COUNT(*) FROM favorites WHERE member_id = :mid");
$favStmt->execute([':mid' => $_SESSION['user_id']]);
$fav_count = $favStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order #<?php echo htmlspecialchars($order['order_id']); ?> — La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Montserrat:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ── VARIABLES ──────────────────────────────────────── */
        :root {
            --gold: #D4AF37;
            --gold-hover: #b5952f;
            --bg-dark: #121212;
            --card-bg: #1e1e1e;
            --card-bg-2: #252525;
            --input-bg: #2a2a2a;
            --text-main: #f5f5f5;
            --text-muted: #a0a0a0;
            --border: #333;
            --navbar-h: 60px;
        }

        *, *::before, *::after { box-sizing: border-box; }

        body {
            font-family: 'Montserrat', sans-serif;
            background: var(--bg-dark);
            color: var(--text-main);
            min-height: 100vh;
        }

        /* ── NAVBAR ─────────────────────────────────────────── */
        .navbar {
            height: var(--navbar-h);
            background: #1a1a1a !important;
            border-bottom: 1px solid var(--border);
            box-shadow: 0 4px 20px rgba(0,0,0,.5);
            padding: 0 16px;
        }
        .navbar-brand {
            font-family: 'Playfair Display', serif;
            font-size: clamp(1rem, 3vw, 1.45rem);
            font-weight: 700;
            color: #fff !important;
            white-space: nowrap;
        }
        .navbar-brand i { color: var(--gold); }

        /* Horizontal nav links (desktop) */
        .nav-link {
            color: rgba(255,255,255,.8) !important;
            font-weight: 500;
            border-radius: 8px;
            transition: all .25s;
            padding: .45rem .85rem !important;
            font-size: .85rem;
            white-space: nowrap;
        }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,.1); color: var(--gold) !important; }

        /* Mobile nav collapse */
        .navbar-collapse {
            background: #1a1a1a;
            border-top: 1px solid var(--border);
            padding: 8px 0 12px;
            margin-top: 2px;
        }
        @media (min-width: 992px) {
            .navbar-collapse { background: transparent; border: none; padding: 0; margin: 0; }
        }

        /* ── PAGE WRAPPER ───────────────────────────────────── */
        .page-wrap {
            max-width: 1200px;
            margin: 0 auto;
            padding: clamp(16px, 3vw, 36px) clamp(12px, 3vw, 24px);
        }

        /* ── HEADER ROW ─────────────────────────────────────── */
        .header-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 18px;
            margin-bottom: 24px;
        }
        .header-bar-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .header-bar-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        .page-title {
            font-family: 'Playfair Display', serif;
            font-size: clamp(1.1rem, 4vw, 1.9rem);
            font-weight: 700;
            color: #fff;
            margin: 0;
        }

        /* ── BUTTONS ────────────────────────────────────────── */
        .btn-back {
            background: transparent;
            color: var(--text-muted);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: .45rem 1rem;
            font-weight: 600;
            font-size: .82rem;
            text-decoration: none;
            transition: all .25s;
            white-space: nowrap;
        }
        .btn-back:hover { background: var(--input-bg); color: #fff; border-color: var(--gold); }

        .btn-print {
            background: linear-gradient(135deg, var(--gold) 0%, #f0cb67 100%);
            color: #121212;
            border: 1px solid var(--gold);
            border-radius: 8px;
            padding: .48rem 1.1rem;
            font-weight: 700;
            font-size: .82rem;
            white-space: nowrap;
            transition: all .2s;
            box-shadow: 0 6px 18px rgba(212,175,55,.18);
        }
        .btn-print:hover { filter: brightness(.97); transform: translateY(-1px); color: #121212; }

        /* ── DETAIL CARDS ───────────────────────────────────── */
        .detail-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: clamp(14px, 3vw, 22px);
            box-shadow: 0 8px 28px rgba(0,0,0,.3);
            /* height: 100%;  ← REMOVED – caused content clipping on mobile */
        }
        .card-section-title {
            font-family: 'Playfair Display', serif;
            font-size: clamp(.9rem, 2.5vw, 1.15rem);
            color: var(--gold);
            margin-bottom: 1rem;
            padding-bottom: .5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── INFO ROWS ──────────────────────────────────────── */
        .info-grid {
            display: flex;
            flex-direction: column;
            gap: 10px;
            font-size: .88rem;
        }
        .info-row-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
        }
        .info-label {
            color: var(--text-muted);
            font-weight: 500;
            white-space: nowrap;
            flex-shrink: 0;
            min-width: 72px;
        }
        .info-value {
            color: var(--text-light, var(--text-main));
            font-weight: 600;
            word-break: break-word;
            overflow-wrap: anywhere;
            text-align: right;
            flex: 1;
        }

        /* ── STATUS TRACKER ─────────────────────────────────── */
        .status-tracker {
            display: flex;
            align-items: center;
            gap: 0;
            margin-bottom: 20px;
            overflow-x: auto;
            padding-bottom: 4px;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .status-tracker::-webkit-scrollbar { display: none; }
        .tracker-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            flex-shrink: 0;
            min-width: 70px;
        }
        .tracker-dot {
            width: 28px; height: 28px;
            border-radius: 50%;
            background: var(--input-bg);
            border: 2px solid var(--border);
            display: flex; align-items: center; justify-content: center;
            font-size: .7rem;
            color: var(--text-muted);
            position: relative;
            z-index: 1;
            transition: all .3s;
        }
        .tracker-dot.done  { background: var(--gold); border-color: var(--gold); color: #111; }
        .tracker-dot.active { background: var(--gold); border-color: var(--gold); color: #111; box-shadow: 0 0 0 4px rgba(212,175,55,.2); }
        .tracker-label { font-size: .62rem; color: var(--text-muted); margin-top: 5px; text-align: center; max-width: 66px; line-height: 1.2; }
        .tracker-label.done, .tracker-label.active { color: var(--gold); font-weight: 600; }
        .tracker-line {
            flex: 1;
            height: 2px;
            background: var(--border);
            min-width: 18px;
            margin-top: -14px; /* align with dot center */
        }
        .tracker-line.done { background: var(--gold); }

        /* ── ITEMS TABLE ────────────────────────────────────── */
        .items-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 8px 28px rgba(0,0,0,.3);
        }
        .items-card-header {
            padding: 16px 20px 0;
        }

        .table { color: var(--text-main); margin-bottom: 0; --bs-table-bg: transparent; }
        .table thead th {
            color: var(--text-muted);
            border-bottom: 2px solid var(--border);
            font-weight: 600;
            text-transform: uppercase;
            font-size: .75rem;
            letter-spacing: .4px;
            background: rgba(0,0,0,.2);
            padding: .85rem 1rem;
            white-space: nowrap;
        }
        .table tbody td {
            vertical-align: middle;
            border-bottom: 1px solid var(--border);
            padding: .9rem 1rem;
            font-weight: 500;
            font-size: .88rem;
        }
        .table tbody tr:last-child td { border-bottom: none; }

        /* Mobile item cards (replaces table on xs) */
        .item-mobile-card {
            background: var(--card-bg-2);
            border-bottom: 1px solid var(--border);
            padding: 12px 16px;
        }
        .item-mobile-card:last-child { border-bottom: none; }

        /* ── TOTALS ─────────────────────────────────────────── */
        .totals-section {
            background: rgba(0,0,0,.2);
            border-top: 2px solid var(--border);
            padding: clamp(12px, 2.5vw, 20px) clamp(14px, 3vw, 24px);
        }
        .total-row {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            margin-bottom: 6px;
            gap: 16px;
        }
        .total-label { color: var(--text-muted); text-align: right; font-size: .88rem; min-width: 110px; }
        .total-amount { color: #fff; text-align: right; font-weight: 600; font-size: .88rem; min-width: 90px; }
        .grand-total .total-label { color: #fff; }
        .grand-total .total-amount { color: var(--gold); font-size: 1.1rem; font-weight: 700; }
        .grand-total { margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border); }

        /* ── PRINT RECEIPT ──────────────────────────────────── */
        .receipt-print-area { display: none; }

        /* ── RESPONSIVE ─────────────────────────────────────── */
        @media (max-width: 575px) {
            .header-bar-right { width: 100%; justify-content: flex-start; }
            .desktop-items-table { display: none !important; }
            .mobile-items-list   { display: block !important; }
        }
        @media (min-width: 576px) {
            .mobile-items-list { display: none !important; }
        }

        /* ── PRINT STYLES ───────────────────────────────────── */
        @media print {
            @page { margin: 12mm; }
            body { background: #fff; color: #111; min-height: auto; }
            body > :not(.receipt-print-area) { display: none !important; }
            .receipt-print-area { display: block; }
            .receipt-sheet { max-width: 190mm; margin: 0 auto; padding: 10mm; border: 1px solid #d8d0be; border-radius: 18px; background: #fff; color: #111; }
            .receipt-brand { text-align: center; padding-bottom: 14px; margin-bottom: 18px; border-bottom: 2px solid #d4af37; }
            .receipt-brand h1 { font-family: 'Playfair Display', serif; font-size: 1.9rem; margin: 0; }
            .receipt-brand p, .receipt-footer, .receipt-note { color: #5e584d; }
            .receipt-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 12px; margin-bottom: 16px; }
            .receipt-panel { border: 1px solid #ddd4bf; border-radius: 12px; padding: 12px 14px; margin-bottom: 12px; }
            .receipt-panel h2 { margin: 0 0 10px; font-size: .78rem; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: #8a6d1f; }
            .receipt-line { display: flex; justify-content: space-between; gap: 16px; margin-bottom: 8px; font-size: .9rem; }
            .receipt-line span:first-child { color: #5e584d; }
            .receipt-line span:last-child  { font-weight: 600; text-align: right; }
            .receipt-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            .receipt-table th, .receipt-table td { padding: 10px 8px; border-bottom: 1px dashed #cfc6b2; font-size: .9rem; }
            .receipt-table th { font-size: .72rem; letter-spacing: .1em; color: #5e584d; text-transform: uppercase; }
            .receipt-table th:nth-child(n+2), .receipt-table td:nth-child(n+2) { text-align: right; }
            .receipt-summary { margin-top: 16px; margin-left: auto; max-width: 280px; }
            .receipt-total { font-size: 1rem; font-weight: 700; border-top: 1px solid #111; padding-top: 10px; margin-top: 10px; }
            .receipt-note { margin-top: 16px; font-size: .82rem; text-align: center; color: #5e584d; }
            .receipt-footer { margin-top: 20px; padding-top: 12px; text-align: center; font-size: .8rem; border-top: 1px solid #ddd4bf; color: #5e584d; }
            .receipt-sheet { position: relative; overflow: hidden; }
            .receipt-watermark {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%) rotate(-30deg);
                font-size: 3.2rem;
                font-weight: 900;
                color: rgba(220, 53, 69, 0.12); /* transparent red */
                text-transform: uppercase;
                border: 8px solid rgba(220, 53, 69, 0.12);
                padding: 15px 30px;
                border-radius: 16px;
                white-space: nowrap;
                pointer-events: none;
                z-index: 100;
            }
        }
</style>
    <link rel="stylesheet" href="customer-theme.css">
</head>
<body>

<!-- ── NAVBAR ──────────────────────────────────────────────── -->
<?php
$customerNavPage = 'orders.php';
require 'customer-navbar.php';
?>

<!-- ── PAGE ────────────────────────────────────────────────── -->
<div class="page-wrap">

    <!-- Header Bar -->
    <div class="header-bar">
        <div class="header-bar-left">
            <a href="orders.php" class="btn-back"><i class="fas fa-arrow-left me-1"></i>My Orders</a>
            <h2 class="page-title">Order Details</h2>
        </div>
        <div class="header-bar-right">
            <button type="button" class="btn btn-print" id="btnPrintReceipt">
                <i class="fas fa-print me-1"></i>Print Receipt
            </button>
            <?php if ($order['status'] === 'PENDING'): ?>
               <form method="POST" id="cancelOrderForm" class="m-0"
                     action="order-details.php?id=<?php echo htmlspecialchars($order['order_id']); ?>">
                    <input type="hidden" name="cancel_order_id" value="<?php echo htmlspecialchars($order['order_id']); ?>">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($cancelCsrf) ?>">
                    <button type="button" id="btnCancelOrder"
                            class="btn btn-outline-danger btn-sm fw-bold px-3"
                            style="border-width:2px;font-size:.82rem;">
                        <i class="fas fa-times me-1"></i>Cancel Order
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // Determine tracker state
    $statusOrder = ['PENDING','CONFIRMED','PREPARING','READY','OUT_FOR_DELIVERY','DELIVERED','COMPLETED'];
    $cancelledOrUnknown = !in_array($order['status'], $statusOrder);
    $currentIdx = array_search($order['status'], $statusOrder);
    $trackerLabels = ['Pending','Confirmed','Preparing','Ready','Out for Delivery','Delivered','Completed'];
    $trackerIcons  = ['fa-clock','fa-check','fa-fire','fa-bell','fa-truck','fa-flag-checkered','fa-check-double'];
    ?>

    <!-- Status Tracker (non-cancelled orders) -->
    <?php if (!$cancelledOrUnknown): ?>
    <div class="status-tracker mb-4">
        <?php foreach ($statusOrder as $idx => $step): ?>
            <?php
            $isDone   = $idx < $currentIdx;
            $isActive = $idx === $currentIdx;
            $dotClass = $isDone ? 'done' : ($isActive ? 'active' : '');
            ?>
            <?php if ($idx > 0): ?>
                <div class="tracker-line <?php echo $isDone ? 'done' : ''; ?>"></div>
            <?php endif; ?>
            <div class="tracker-step">
                <div class="tracker-dot <?php echo $dotClass; ?>">
                    <i class="fas <?php echo $trackerIcons[$idx]; ?>"></i>
                </div>
                <div class="tracker-label <?php echo $dotClass; ?>"><?php echo $trackerLabels[$idx]; ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Cancelled banner -->
    <?php if ($order['status'] === 'CANCELLED'): ?>
    <div class="alert mb-4" style="background:rgba(220,53,69,.1);border:1px solid rgba(220,53,69,.3);color:#ff6b6b;border-radius:10px;">
        <i class="fas fa-ban me-2"></i>This order has been <strong>cancelled <?php echo $cancelled_by_management ? 'by management' : 'by you'; ?></strong>.
    </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Left column: Summary + Delivery -->
        <div class="col-12 col-lg-4">

            <!-- Summary Card -->
            <div class="detail-card mb-4">
                <div class="card-section-title">
                    <i class="fas fa-receipt"></i>Order Summary
                </div>
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="text-muted" style="font-size:.82rem;">Status</span>
                    <?php echo getStatusBadge($order['status']); ?>
                </div>
                <div class="info-grid">
                    <div class="info-row-item">
                        <span class="info-label">Order ID</span>
                        <span class="info-value text-gold">#<?php echo htmlspecialchars($order['order_id']); ?></span>
                    </div>
                    <div class="info-row-item">
                        <span class="info-label">Reference</span>
                        <span class="info-value"><?php echo htmlspecialchars($order_reference); ?></span>
                    </div>
                    <div class="info-row-item">
                        <span class="info-label">Date</span>
                        <span class="info-value"><?php echo date('M d, Y', strtotime($order['order_date'])); ?></span>
                    </div>
                    <div class="info-row-item">
                        <span class="info-label">Time</span>
                        <span class="info-value"><?php echo date('h:i A', strtotime($order['order_date'])); ?></span>
                    </div>
                    <div class="info-row-item">
                        <span class="info-label">Payment</span>
                        <span class="info-value"><?php echo htmlspecialchars($payment_method_label); ?></span>
                    </div>
                    <div class="info-row-item">
                        <span class="info-label">Paid</span>
                        <span class="info-value"><?php echo htmlspecialchars(formatOrderLabel($order['payment_status'] ?? 'N/A')); ?></span>
                    </div>
                </div>
            </div>

            <!-- Delivery Card -->
            <div class="detail-card">
                <div class="card-section-title">
                    <i class="fas fa-map-marker-alt"></i>Delivery Details
                </div>
                <div class="info-grid">
                    <div class="info-row-item">
                        <span class="info-label">Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($order['delivery_name'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row-item">
                        <span class="info-label">Phone</span>
                        <span class="info-value"><?php echo htmlspecialchars($order['delivery_phone'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row-item">
                        <span class="info-label">Email</span>
                        <span class="info-value" style="word-break:break-all;overflow-wrap:anywhere;"><?php echo htmlspecialchars($order['delivery_email'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="info-row-item" style="flex-direction:column;gap:4px;">
                        <span class="info-label">Address</span>
                        <span class="info-value" style="text-align:left;line-height:1.6;background:rgba(255,255,255,.04);border-radius:8px;padding:8px 10px;display:block;width:100%;font-weight:500;">
                            <?php echo nl2br(htmlspecialchars($order['delivery_address'] ?? 'N/A')); ?>
                        </span>
                    </div>
                </div>
            </div>

        </div>

        <!-- Right column: Items -->
        <div class="col-12 col-lg-8">
            <div class="items-card">
                <div class="items-card-header">
                    <div class="card-section-title">
                        <i class="fas fa-utensils"></i>Items Ordered
                        <span class="badge bg-secondary ms-auto " style="font-size:.75rem;">
                            <?php echo count($items); ?> item<?php echo count($items) !== 1 ? 's' : ''; ?>
                        </span>
                    </div>
                </div>

                <!-- Desktop table (≥576px) -->
                <div class="desktop-items-table table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="ps-4">Item</th>
                                <th class="text-center">Qty</th>
                                <th class="text-end">Unit Price</th>
                                <th class="text-end pe-4">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($items)): ?>
                                <?php foreach ($items as $item): ?>
                                    <?php $sub = $item['quantity'] * $item['item_price']; ?>
                                    <tr>
                                        <td class="ps-4" style="color:var(--gold);font-weight:600;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                                        <td class="text-center" style="color:var(--gold);font-weight:600;">×<?php echo $item['quantity']; ?></td>
                                        <td class="text-end" style="color:var(--gold);font-weight:600;">₱<?php echo number_format($item['item_price'],2); ?></td>
                                        <td class="text-end pe-4" style="color:var(--gold);">₱<?php echo number_format($sub,2); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center py-4 text-muted">No items found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile item cards (<576px) -->
                <div class="mobile-items-list">
                    <?php if (!empty($items)): ?>
                        <?php foreach ($items as $item): ?>
                            <?php $sub = $item['quantity'] * $item['item_price']; ?>
                            <div class="item-mobile-card">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <span style="color:var(--gold);font-weight:600;font-size:.9rem;">
                                        <?php echo htmlspecialchars($item['item_name']); ?>
                                    </span>
                                    <span style="color:var(--gold);font-weight:700;white-space:nowrap;">
                                        ₱<?php echo number_format($sub,2); ?>
                                    </span>
                                </div>
                                <div class="d-flex gap-3 mt-1">
                                    <small class="text-muted">×<?php echo $item['quantity']; ?></small>
                                    <small class="text-muted">@ ₱<?php echo number_format($item['item_price'],2); ?> each</small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-4 text-center text-muted">No items found.</div>
                    <?php endif; ?>
                </div>

                <!-- Totals -->
                <div class="totals-section">
                    <div class="total-row">
                        <span class="total-label">Subtotal</span>
                        <span class="total-amount">₱<?php echo number_format($items_subtotal,2); ?></span>
                    </div>
                    <?php if ($tax_amount > 0): ?>
                    <div class="total-row">
                        <span class="total-label">Tax</span>
                        <span class="total-amount">₱<?php echo number_format($tax_amount,2); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($discount_amount > 0): ?>
                    <div class="total-row">
                        <span class="total-label">Discount</span>
                        <span class="total-amount" style="color:#2ecc71;">−₱<?php echo number_format($discount_amount,2); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="total-row grand-total">
                        <span class="total-label">Grand Total</span>
                        <span class="total-amount">₱<?php echo number_format($grand_total,2); ?></span>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div><!-- /page-wrap -->

<!-- ── PRINT RECEIPT ────────────────────────────────────────── -->
<div class="receipt-print-area">
    <div class="receipt-sheet">
        <?php if ($order['status'] === 'CANCELLED'): ?>
        <div class="receipt-watermark">
            <?php echo $cancelled_by_management ? 'CANCELLED BY MANAGEMENT' : 'CANCELLED BY YOU'; ?>
        </div>
        <?php endif; ?>
        <div class="receipt-brand">
            <h1>La Elena Fishyalan</h1>
            <p>Customer Receipt</p>
        </div>
        <div class="receipt-grid">
            <div class="receipt-panel">
                <h2>Order Info</h2>
                <div class="receipt-line"><span>Order ID</span><span>#<?php echo htmlspecialchars($order['order_id']); ?></span></div>
                <div class="receipt-line"><span>Reference</span><span><?php echo htmlspecialchars($order_reference); ?></span></div>
                <div class="receipt-line"><span>Date</span><span><?php echo date('M d, Y h:i A', strtotime($order['order_date'])); ?></span></div>
                <div class="receipt-line"><span>Status</span><span><?php echo htmlspecialchars($status_label); ?></span></div>
            </div>
            <div class="receipt-panel">
                <h2>Payment</h2>
                <div class="receipt-line"><span>Method</span><span><?php echo htmlspecialchars($payment_method_label); ?></span></div>
                <div class="receipt-line"><span>Status</span><span><?php echo htmlspecialchars(formatOrderLabel($order['payment_status'] ?? 'N/A')); ?></span></div>
                <div class="receipt-line"><span>Printed</span><span><?php echo date('M d, Y h:i A'); ?></span></div>
            </div>
        </div>
        <div class="receipt-panel" style="margin-bottom:12px;">
            <h2>Delivery Details</h2>
            <div class="receipt-line"><span>Name</span><span><?php echo htmlspecialchars($order['delivery_name'] ?? 'N/A'); ?></span></div>
            <div class="receipt-line"><span>Phone</span><span><?php echo htmlspecialchars($order['delivery_phone'] ?? 'N/A'); ?></span></div>
            <div class="receipt-line"><span>Email</span><span><?php echo htmlspecialchars($order['delivery_email'] ?? 'N/A'); ?></span></div>
            <div class="receipt-line"><span>Address</span><span><?php echo nl2br(htmlspecialchars($order['delivery_address'] ?? 'N/A')); ?></span></div>
        </div>
        <div class="receipt-panel">
            <h2>Items Ordered</h2>
            <table class="receipt-table">
                <thead><tr><th>Item</th><th>Qty</th><th>Price</th><th>Total</th></tr></thead>
                <tbody>
                    <?php foreach ($items as $ri): ?>
                        <?php $rs = $ri['quantity'] * $ri['item_price']; ?>
                        <tr>
                            <td><?php echo htmlspecialchars($ri['item_name']); ?></td>
                            <td><?php echo $ri['quantity']; ?></td>
                            <td>₱<?php echo number_format($ri['item_price'],2); ?></td>
                            <td>₱<?php echo number_format($rs,2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="receipt-summary">
                <div class="receipt-line"><span>Subtotal</span><span>₱<?php echo number_format($items_subtotal,2); ?></span></div>
                <?php if ($tax_amount > 0): ?><div class="receipt-line"><span>Tax</span><span>₱<?php echo number_format($tax_amount,2); ?></span></div><?php endif; ?>
                <?php if ($discount_amount > 0): ?><div class="receipt-line"><span>Discount</span><span>−₱<?php echo number_format($discount_amount,2); ?></span></div><?php endif; ?>
                <div class="receipt-line receipt-total"><span>Total Paid</span><span>₱<?php echo number_format($grand_total,2); ?></span></div>
            </div>
        </div>
        <p class="receipt-note">Please keep this receipt for your records.</p>
        <div class="receipt-footer">Thank you for dining with La Elena Fishyalan.</div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.1/dist/sweetalert2.all.min.js"></script>
<script>
    // ── LOGOUT ────────────────────────────────────────────────
    document.addEventListener('click', function(e) {
        const link = e.target.closest('.logout-link');
        if (!link) return;
        e.preventDefault();
        const href = link.href || '../auth/logout.php';
        Swal.fire({
            title: 'Ready to leave?',
            text: 'You will be logged out of your session.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#D4AF37',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, logout',
            cancelButtonText: 'Stay',
            background: '#2d2d2d',
            color: '#fff',
            reverseButtons: true,
            focusCancel: true,
        }).then(r => { if (r.isConfirmed) window.location.href = href; });
    });

    // ── CANCEL ORDER ──────────────────────────────────────────
    const btnCancel = document.getElementById('btnCancelOrder');
    if (btnCancel) {
        btnCancel.addEventListener('click', function() {
            Swal.fire({
                title: 'Cancel this order?',
                text: "This action cannot be undone.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, cancel it',
                cancelButtonText: 'Keep my order',
                background: '#2d2d2d',
                color: '#fff',
                reverseButtons: true,
                focusCancel: true,
            }).then(r => { if (r.isConfirmed) document.getElementById('cancelOrderForm').submit(); });
        });
    }

    // ── PRINT ─────────────────────────────────────────────────
    document.getElementById('btnPrintReceipt')?.addEventListener('click', () => window.print());
</script>
</body>
</html>
