<?php
require_once '../config/config.php';
require_once '../config/Database.php';
require_once '../config/pagination_helper.php';

if (!isLoggedIn() || !isCustomer()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

$orders = [];
$error = null;
$totalOrders = $totalSpent = $pendingOrders = 0;

$filter_status = $_GET['status'] ?? 'ALL';

try {
    // Keep the summary consistent with the dashboard, independent of table filters.
    $summaryStmt = $db->prepare("SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(CASE WHEN status IN ('DELIVERED', 'COMPLETED') THEN total_amount ELSE 0 END), 0) AS total_spent,
        COALESCE(SUM(CASE WHEN status IN ('PENDING', 'CONFIRMED', 'PREPARING', 'READY', 'OUT_FOR_DELIVERY') THEN 1 ELSE 0 END), 0) AS pending_orders
        FROM online_orders WHERE member_id = :mid");
    $summaryStmt->execute([':mid' => $_SESSION['user_id']]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    $totalOrders = (int) $summary['total_orders'];
    $totalSpent = (float) $summary['total_spent'];
    $pendingOrders = (int) $summary['pending_orders'];

    $statusOrderSql = "CASE status
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
                    
    $params = [':mid' => $_SESSION['user_id']];
    $statusWhere = "";
    if ($filter_status !== 'ALL') {
        $statusWhere = " AND status = :status";
        $params[':status'] = $filter_status;
    }

    $stmt = $db->prepare("SELECT * FROM online_orders WHERE member_id = :mid {$statusWhere} ORDER BY {$statusOrderSql}, order_date DESC");
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = $e->getMessage();
}

$ordersPagination = paginate_array($orders, 10);
$orders = $ordersPagination['items'];

function getStatusBadge($status) {
    $badges = [
        'PENDING'          => '<span class="badge" style="background: rgba(212, 175, 55, 0.15); color: #D4AF37; border: 1px solid rgba(212, 175, 55, 0.3);">Pending</span>',
        'CONFIRMED'        => '<span class="badge" style="background: rgba(13, 202, 240, 0.15); color: #0dcaf0; border: 1px solid rgba(13, 202, 240, 0.3);">Confirmed</span>',
        'PREPARING'        => '<span class="badge" style="background: rgba(13, 110, 253, 0.15); color: #0d6efd; border: 1px solid rgba(13, 110, 253, 0.3);">Preparing</span>',
        'READY'            => '<span class="badge" style="background: rgba(13, 110, 253, 0.15); color: #0d6efd; border: 1px solid rgba(13, 110, 253, 0.3);">Ready</span>',
        'OUT_FOR_DELIVERY' => '<span class="badge" style="background: rgba(13, 202, 240, 0.15); color: #0dcaf0; border: 1px solid rgba(13, 202, 240, 0.3);">Out for Delivery</span>',
        'DELIVERED'        => '<span class="badge" style="background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3);">Delivered</span>',
        'COMPLETED'        => '<span class="badge" style="background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3);">Completed</span>',
        'CANCELLED'        => '<span class="badge" style="background: rgba(220, 53, 69, 0.15); color: #dc3545; border: 1px solid rgba(220, 53, 69, 0.3);">Cancelled</span>'
    ];
    return $badges[$status] ?? '<span class="badge bg-secondary">Unknown</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Orders - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Montserrat:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.1/dist/sweetalert2.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../customerSide/customer-dashboard.css">
    <style>
        :root {
            --gold: #D4AF37;
            --gold-hover: #b5952f;
            --bg-dark: #121212;
            --card-bg: #1e1e1e;
            --input-bg: #2a2a2a;
            --text-main: #f5f5f5;
            --text-muted: #a0a0a0;
            --border-color: #333;
        }
        body {
            font-family: 'Montserrat', sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-main);
            min-height: 100vh;
        }
        
        /* Navbar styling synced with checkout */
        .navbar {
            background-color: #1a1a1a !important;
            border-bottom: 1px solid var(--border-color);
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
        }
        .navbar-brand {
            font-family: 'Playfair Display', serif;
            font-size: 1.6rem;
            font-weight: 700;
            color: #fff !important;
        }
        .navbar-brand i { color: var(--gold); }
        .nav-link { color: rgba(255,255,255,0.8) !important; font-weight: 500; border-radius: 8px; transition: all 0.3s; padding: 0.5rem 1rem !important; margin: 0 0.2rem;}
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: var(--gold) !important; }

        .page-header {
            font-family: 'Playfair Display', serif;
            font-size: 2.2rem;
            font-weight: 700;
            color: white;
            margin-bottom: 2rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 1rem;
        }
        .page-header i { color: var(--gold); margin-right: 12px; }

        .orders-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .orders-summary-card {
            display: flex;
            align-items: center;
            gap: 1.1rem;
            min-height: 104px;
            padding: 1.5rem;
            background: #fffdf9;
            border: 1px solid var(--border-color);
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(20, 61, 74, .07);
        }
        .orders-summary-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            font-size: 1.1rem;
            background: #fff0e9;
            color: #d94f3b;
        }
        .orders-summary-icon.spent { background: #fff4df; color: #ac7630; }
        .orders-summary-icon.pending { background: #f6e9e5; color: #b5684e; }
        .orders-summary-info { min-width: 0; }
        .orders-summary-label { color: #63777a; font-size: .75rem; margin-bottom: .2rem; }
        .orders-summary-value {
            color: #d94f3b;
            font-size: 1.8rem;
            font-weight: 600;
            line-height: 1.25;
            overflow-wrap: anywhere;
        }
        @media (max-width: 767.98px) {
            .orders-summary { grid-template-columns: 1fr; }
        }

        /* Table Styling */
        .table-responsive {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            overflow: hidden;
            padding: 1rem;
        }
        .table {
            color: var(--text-main);
            margin-bottom: 0;
            --bs-table-bg: transparent; /* Override BS default */
        }
        .table thead th {
            font-family: 'Montserrat', sans-serif;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            border-bottom: 2px solid var(--border-color);
            padding: 1rem;
            background-color: rgba(0,0,0,0.2);
        }
        .table tbody td {
            vertical-align: middle;
            border-bottom: 1px solid var(--border-color);
            padding: 1.2rem 1rem;
            font-size: 0.95rem;
            background-color: transparent;
            color: var(--text-main);
        }
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        .table tbody tr:hover td {
            background-color: rgba(212, 175, 55, 0.03); /* Subtle gold hover */
        }

        .order-id-col { font-weight: 600; font-family: monospace; font-size: 1rem; color: white;}
        .total-col { font-weight: 600; color: var(--gold); }

        /* Button Styling */
        .btn-outline-gold {
            color: var(--gold);
            border: 1px solid var(--gold);
            background: transparent;
            border-radius: 8px;
            padding: 0.4rem 1rem;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-outline-gold:hover {
            background: var(--gold);
            color: #121212;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3);
        }

        .empty-state {
            padding: 4rem 2rem;
            text-align: center;
        }
        .empty-state i {
            font-size: 3rem;
            color: var(--border-color);
            margin-bottom: 1rem;
        }
        .empty-state p {
            color: var(--text-muted);
            font-size: 1.1rem;
        }
</style>
    <link rel="stylesheet" href="customer-theme.css">
</head>
<body>

<?php
$customerNavPage = 'orders.php';
require 'customer-navbar.php';
?>

<div class="container py-5">
    <?php if (!$error): ?>
        <section class="orders-summary" aria-label="Order summary">
            <div class="orders-summary-card">
                <div class="orders-summary-icon"><i class="fas fa-shopping-bag" aria-hidden="true"></i></div>
                <div class="orders-summary-info">
                    <div class="orders-summary-label">Total orders</div>
                    <div class="orders-summary-value"><?php echo number_format($totalOrders); ?></div>
                </div>
            </div>
            <div class="orders-summary-card">
                <div class="orders-summary-icon spent"><i class="fas fa-peso-sign" aria-hidden="true"></i></div>
                <div class="orders-summary-info">
                    <div class="orders-summary-label">Total spent</div>
                    <div class="orders-summary-value">&#8369;<?php echo number_format($totalSpent, $totalSpent == floor($totalSpent) ? 0 : 2); ?></div>
                </div>
            </div>
            <div class="orders-summary-card">
                <div class="orders-summary-icon pending"><i class="fas fa-box" aria-hidden="true"></i></div>
                <div class="orders-summary-info">
                    <div class="orders-summary-label">Pending orders</div>
                    <div class="orders-summary-value"><?php echo number_format($pendingOrders); ?></div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2" style="border-bottom: 1px solid var(--border-color);">
        <h2 class="page-header mb-0 border-0 pb-0"><i class="fas fa-receipt"></i> Order History</h2>
        <form method="GET" class="d-flex align-items-center mt-3 mt-md-0">
            <label for="statusFilter" class="me-2 text-gold fw-bold" style="white-space: nowrap;">Filter Status:</label>
            <select name="status" id="statusFilter" class="form-select" style="background:#2a2a2a; color:#f5f5f5; border:1px solid #333; width:auto; cursor:pointer;" onchange="this.form.submit()">
                <option value="ALL" <?php echo $filter_status === 'ALL' ? 'selected' : ''; ?>>All Orders</option>
                <option value="PENDING" <?php echo $filter_status === 'PENDING' ? 'selected' : ''; ?>>Pending</option>
                <option value="CONFIRMED" <?php echo $filter_status === 'CONFIRMED' ? 'selected' : ''; ?>>Confirmed</option>
                <option value="PREPARING" <?php echo $filter_status === 'PREPARING' ? 'selected' : ''; ?>>Preparing</option>
                <option value="READY" <?php echo $filter_status === 'READY' ? 'selected' : ''; ?>>Ready</option>
                <option value="OUT_FOR_DELIVERY" <?php echo $filter_status === 'OUT_FOR_DELIVERY' ? 'selected' : ''; ?>>Out for Delivery</option>
                <option value="DELIVERED" <?php echo $filter_status === 'DELIVERED' ? 'selected' : ''; ?>>Delivered</option>
                <option value="COMPLETED" <?php echo $filter_status === 'COMPLETED' ? 'selected' : ''; ?>>Completed</option>
                <option value="CANCELLED" <?php echo $filter_status === 'CANCELLED' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </form>
    </div>
    
    <?php if ($error): ?>
        <div class="alert alert-danger rounded-3 mb-4" style="background:#2c0b0e; border-color:#842029; color:#ea868f;">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Date Placed</th>
                    <th>Status</th>
                    <th>Total</th>
                    <th class="text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($orders)): ?>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td class="order-id-col">#<?php echo htmlspecialchars($o['order_id']); ?></td>
                            <td>
                                <i class="far fa-calendar-alt me-2 text-muted"></i>
                                <?php echo date('M d, Y', strtotime($o['order_date'])); ?>
                            </td>
                            <td><?php echo getStatusBadge($o['status']); ?></td>
                            <td class="total-col">₱<?php echo number_format($o['total_amount'],2); ?></td>
                            <td class="text-end">
                                <a href="order-details.php?id=<?php echo urlencode($o['order_id']); ?>" class="btn-outline-gold">
                                    <i class="fas fa-eye me-1"></i> View Details
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5">
                            <div class="empty-state">
                                <i class="fas fa-box-open"></i>
                                <h4>No Orders Yet</h4>
                                <p>You haven't placed any orders with us. Time to explore the menu!</p>
                                <a href="menu.php" class="btn-outline-gold mt-2">Browse Menu</a>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php echo render_pagination($ordersPagination); ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.1/dist/sweetalert2.all.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const logoutLinks = document.querySelectorAll('.logout-link');

    logoutLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault(); 
            const logoutUrl = this.getAttribute('href'); 

            Swal.fire({
                background: '#1a1a1a', 
                color: '#f8f9fa',
                title: 'Ready to leave?',
                text: "You are about to log out of your account.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#e74a3b', 
                cancelButtonColor: '#404040',
                confirmButtonText: '<i class="fas fa-sign-out-alt me-2"></i>Yes, log out'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = logoutUrl;
                }
            });
        });
    });
});
</script>
</body>
</html>
