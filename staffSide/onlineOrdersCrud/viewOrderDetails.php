<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (!isLoggedIn() || (!isStaff() && !isAdmin())) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

// If an order id is provided, show detailed view and items; otherwise list recent orders
$orders = [];
$detailedOrder = null;
$orderItems = [];
$error = $_SESSION['order_update_error'] ?? null;
unset($_SESSION['order_update_error']);

try {
    if (isset($_GET['id'])) {
        $oid = intval($_GET['id']);
        // FIXED: Online_Orders -> online_orders AND customer accounts -> customer
        $stmt = $db->prepare("SELECT oo.*, COALESCE(m.first_name, CONCAT('[Deleted] ', COALESCE(oo.delivery_name, 'Customer'))) AS first_name, COALESCE(m.last_name, '') AS last_name FROM online_orders oo LEFT JOIN customer m ON oo.member_id = m.member_id WHERE oo.order_id = :oid LIMIT 1");
        $stmt->execute([':oid' => $oid]);
        $detailedOrder = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($detailedOrder) {
            // FIXED: Online_Order_Items -> online_order_items AND Menu_Items -> menu_items
            $itemsStmt = $db->prepare("SELECT oi.*, mi.item_name FROM online_order_items oi LEFT JOIN menu_items mi ON oi.item_id = mi.item_id WHERE oi.order_id = :oid");
            $itemsStmt->execute([':oid' => $oid]);
            $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        // FIXED: Online_Orders -> online_orders AND customer accounts -> customer
        $query = "SELECT oo.*,
                     COALESCE(m.first_name, CONCAT('[Deleted] ', COALESCE(oo.delivery_name, 'Customer'))) AS first_name,
                     COALESCE(m.last_name, '') AS last_name
                  FROM online_orders oo
                  LEFT JOIN customer m ON oo.member_id = m.member_id
                  ORDER BY oo.order_date DESC LIMIT 20";
        $stmt = $db->query($query);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

function getStatusColor($status) {
    $colors = [
        'PENDING' => 'warning',
        'CONFIRMED' => 'info',
        'PREPARING' => 'primary',
        'READY' => 'success',
        'OUT_FOR_DELIVERY' => 'info',
        'DELIVERED' => 'success',
        'COMPLETED' => 'success',
        'CANCELLED' => 'danger'
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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Order Details - Staff Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #0d0d0d; color: #f0f0f0; }
        .table { background-color: #1a1a1a; }
        .table th { color: #d4af37; background-color: #2d2d2d; }
        .card { background-color: #1a1a1a; border-color: #404040; }
        .text-gold { color: #d4af37; }
        .btn-outline-gold { border-color: #d4af37; color: #d4af37; }
        .btn-outline-gold:hover { background-color: #d4af37; color: #000; }
        
        .receipt-print-area { display: none; }
        @media print {
            @page { margin: 12mm; }
            body { background: #ffffff; color: #111111; min-height: auto; }
            body > :not(.receipt-print-area) { display: none !important; }
            .receipt-print-area { display: block; }

            .receipt-sheet { max-width: 190mm; margin: 0 auto; padding: 10mm; border: 1px solid #d8d0be; border-radius: 18px; background: #ffffff; color: #111111; box-shadow: none; }
            .receipt-brand { text-align: center; padding-bottom: 14px; margin-bottom: 18px; border-bottom: 2px solid #d4af37; }
            .receipt-brand h1 { font-family: 'Playfair Display', serif; font-size: 1.9rem; margin: 0; }
            .receipt-brand p, .receipt-footer, .receipt-note { color: #5e584d; }
            .receipt-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 16px; }
            .receipt-panel { border: 1px solid #ddd4bf; border-radius: 12px; padding: 12px 14px; margin-bottom: 16px;}
            .receipt-panel h2 { margin: 0 0 10px; font-size: 0.78rem; font-weight: 700; letter-spacing: 0.12em; text-transform: uppercase; color: #8a6d1f; }
            .receipt-line { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 8px; font-size: 0.9rem; }
            .receipt-line:last-child { margin-bottom: 0; }
            .receipt-line span:first-child { color: #5e584d; }
            .receipt-line span:last-child { text-align: right; font-weight: 600; }
            .receipt-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            .receipt-table th, .receipt-table td { padding: 10px 8px; border-bottom: 1px dashed #cfc6b2; font-size: 0.92rem; }
            .receipt-table th { text-transform: uppercase; font-size: 0.72rem; letter-spacing: 0.1em; color: #5e584d; }
            .receipt-table th:nth-child(2), .receipt-table td:nth-child(2) { text-align: center; }
            .receipt-table th:nth-child(3), .receipt-table td:nth-child(3), .receipt-table th:nth-child(4), .receipt-table td:nth-child(4) { text-align: right; }
            .receipt-summary { margin-top: 16px; margin-left: auto; max-width: 300px; }
            .receipt-total { font-size: 1rem; font-weight: 700; border-top: 1px solid #111111; padding-top: 10px; margin-top: 10px; }
            .receipt-note { margin-top: 16px; font-size: 0.82rem; text-align: center; }
            .receipt-footer { margin-top: 20px; padding-top: 12px; text-align: center; font-size: 0.8rem; border-top: 1px solid #ddd4bf; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="../../staffSide/onlineOrdersCrud/viewOnlineOrders.php"><i class="fas fa-arrow-left me-2"></i>Back</a>
        </div>
    </nav>

    <div class="container">
        <h1 class="mb-4"><i class="fas fa-shopping-cart text-gold me-2"></i>Order Details</h1>

        <?php if (isset($error) && $error): ?>
            <div class="alert alert-danger mb-3"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <?php if ($detailedOrder): ?>
                        <div class="p-4 text-light">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h4 class="mb-0">Order #<?php echo htmlspecialchars($detailedOrder['order_id']); ?> - <?php echo htmlspecialchars($detailedOrder['first_name'] . ' ' . $detailedOrder['last_name']); ?></h4>
                                <button type="button" class="btn btn-warning" id="btnPrintReceipt"><i class="fas fa-print me-2"></i>Print Receipt</button>
                            </div>
                            <p>
                                <strong>Date:</strong> <?php echo date('M d, Y H:i', strtotime($detailedOrder['order_date'])); ?> &nbsp; 
                                <strong>Payment Method:</strong> <span class="text-info"><?php echo htmlspecialchars($detailedOrder['payment_method'] ?? 'N/A'); ?></span> &nbsp;
                                <strong>Total:</strong> ₱<?php echo number_format($detailedOrder['total_amount'],2); ?> &nbsp; 
                                <strong>Status:</strong> <span class="badge bg-<?php echo getStatusColor($detailedOrder['status']); ?>"><?php echo htmlspecialchars($detailedOrder['status']); ?></span>
                            </p>

                            <div class="card mb-4" style="background-color: #222; border-color: #444;">
                                <div class="card-body">
                                    <h5 class="text-warning mb-3"><i class="fas fa-truck me-2"></i>Delivery Information</h5>
                                    <p class="text-light mb-1"><strong>Name:</strong> <?php echo htmlspecialchars($detailedOrder['delivery_name'] ?? 'N/A'); ?></p>
                                    <p class="text-light mb-1"><strong>Phone:</strong> <?php echo htmlspecialchars($detailedOrder['delivery_phone'] ?? 'N/A'); ?></p>
                                    <p class="text-light mb-0"><strong>Address:</strong> 
                                        <?php 
                                        // If using the separate columns method:
                                        if (!empty($detailedOrder['delivery_house']) || !empty($detailedOrder['delivery_street'])) {
                                            $full_address = trim(
                                                ($detailedOrder['delivery_house'] ?? '') . ' ' . 
                                                ($detailedOrder['delivery_street'] ?? '') . ', ' . 
                                                ($detailedOrder['delivery_barangay'] ?? '') . ', ' . 
                                                ($detailedOrder['delivery_city'] ?? '') . ', ' . 
                                                ($detailedOrder['delivery_province'] ?? '') . ' ' . 
                                                ($detailedOrder['delivery_zip'] ?? ''), ", "
                                            );
                                            echo htmlspecialchars($full_address);
                                        } 
                                        // If using the single column string method:
                                        elseif (!empty($detailedOrder['delivery_address'])) {
                                            echo htmlspecialchars($detailedOrder['delivery_address']);
                                        } else {
                                            echo '<em>No address provided</em>';
                                        }
                                        ?>
                                    </p>
                                </div>
                            </div>
                            <?php $transitions = getOrderTransitions($detailedOrder['status'], $detailedOrder['payment_method'] ?? 'COD'); ?>
                            <?php if (!empty($transitions)): ?>
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <?php foreach ($transitions as $value => $label): ?>
                                <form method="POST" action="manageOrder.php" class="m-0">
                                    <input type="hidden" name="order_id" value="<?php echo $detailedOrder['order_id']; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $value; ?>">
                                    <button type="submit" name="action" value="update_status" class="btn btn-<?php echo getStatusColor($value); ?>">
                                        <?php echo $label; ?>
                                    </button>
                                </form>
                                <?php endforeach; ?>
                            </div>
                            <?php elseif (isOrderFinalized($detailedOrder['status'], $detailedOrder['payment_method'] ?? 'COD')): ?>
                            <div class="alert mb-4" style="background-color: #2a2a2a; border: 1px solid #555; color: #aaa;">
                                <i class="fas fa-lock me-2"></i>This order has been <strong class="text-light"><?php echo htmlspecialchars($detailedOrder['status']); ?></strong> and can no longer be modified.
                            </div>
                            <?php endif; ?>

                            <h5 class="mt-4 border-bottom border-secondary pb-2">Order Items</h5>
                            <table class="table table-dark table-striped">
                                <thead>
                                    <tr>
                                        <th>Item</th>
                                        <th>Qty</th>
                                        <th>Price</th>
                                        <th>Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orderItems as $it): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($it['item_name'] ?? 'Unknown'); ?></td>
                                            <td><?php echo intval($it['quantity']); ?></td>
                                            <td>₱<?php echo number_format($it['item_price'] ?? 0,2); ?></td>
                                            <td>₱<?php echo number_format(($it['item_price'] ?? 0) * intval($it['quantity']),2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <table class="table table-dark table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Payment Method</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($order['order_id']); ?></td>
                                    <td><?php echo htmlspecialchars($order['first_name'] . ' ' . $order['last_name']); ?></td>
                                    <td>₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($order['payment_method'] ?? 'N/A'); ?></td>
                                    <td><span class="badge bg-<?php echo getStatusColor($order['status']); ?>"><?php echo htmlspecialchars($order['status']); ?></span></td>
                                    <td><?php echo date('M d, Y H:i', strtotime($order['order_date'])); ?></td>
                                    <td>
                                        <a href="viewOrderDetails.php?id=<?php echo urlencode($order['order_id']); ?>" class="btn btn-sm btn-outline-gold">View</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($detailedOrder): ?>
    <?php
        $items_subtotal = 0.00;
        foreach ($orderItems as $it) {
            $items_subtotal += ((float) $it['quantity']) * ((float) $it['item_price']);
        }
        $discount_amount = (float) ($detailedOrder['discount_amount'] ?? 0);
        $grand_total = (float) ($detailedOrder['total_amount'] ?? 0);
        $tax_amount = max(0, ($grand_total + $discount_amount) - $items_subtotal);
    ?>
    <div class="receipt-print-area">
        <div class="receipt-sheet">
            <div class="receipt-brand">
                <h1>La Elena Fishyalan</h1>
                <p>Order Receipt</p>
            </div>
            <div class="receipt-grid">
                <div class="receipt-panel">
                    <h2>Order Info</h2>
                    <div class="receipt-line"><span>Order ID</span><span>#<?php echo htmlspecialchars($detailedOrder['order_id']); ?></span></div>
                    <div class="receipt-line"><span>Date</span><span><?php echo date('M d, Y h:i A', strtotime($detailedOrder['order_date'])); ?></span></div>
                    <div class="receipt-line"><span>Status</span><span><?php echo htmlspecialchars($detailedOrder['status']); ?></span></div>
                </div>
                <div class="receipt-panel">
                    <h2>Payment</h2>
                    <div class="receipt-line"><span>Method</span><span><?php echo htmlspecialchars($detailedOrder['payment_method'] ?? 'N/A'); ?></span></div>
                    <div class="receipt-line"><span>Payment Status</span><span><?php echo htmlspecialchars($detailedOrder['payment_status'] ?? 'N/A'); ?></span></div>
                    <div class="receipt-line"><span>Printed On</span><span><?php echo date('M d, Y h:i A'); ?></span></div>
                </div>
            </div>
            <div class="receipt-panel">
                <h2>Customer Details</h2>
                <div class="receipt-line"><span>Name</span><span><?php echo htmlspecialchars($detailedOrder['first_name'] . ' ' . $detailedOrder['last_name']); ?></span></div>
                <div class="receipt-line"><span>Delivery Name</span><span><?php echo htmlspecialchars($detailedOrder['delivery_name'] ?? 'N/A'); ?></span></div>
                <div class="receipt-line"><span>Phone</span><span><?php echo htmlspecialchars($detailedOrder['delivery_phone'] ?? 'N/A'); ?></span></div>
                <div class="receipt-line"><span>Address</span><span><?php echo nl2br(htmlspecialchars($detailedOrder['delivery_address'] ?? 'N/A')); ?></span></div>
            </div>
            <div class="receipt-panel">
                <h2>Items Ordered</h2>
                <table class="receipt-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Unit Price</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orderItems as $it): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($it['item_name'] ?? 'Unknown'); ?></td>
                                <td><?php echo intval($it['quantity']); ?></td>
                                <td>₱<?php echo number_format($it['item_price'] ?? 0, 2); ?></td>
                                <td>₱<?php echo number_format(($it['item_price'] ?? 0) * intval($it['quantity']), 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="receipt-summary">
                    <div class="receipt-line"><span>Subtotal</span><span>₱<?php echo number_format($items_subtotal, 2); ?></span></div>
                    <div class="receipt-line"><span>Tax</span><span>₱<?php echo number_format($tax_amount, 2); ?></span></div>
                    <?php if ($discount_amount > 0): ?>
                        <div class="receipt-line"><span>Discount</span><span>-₱<?php echo number_format($discount_amount, 2); ?></span></div>
                    <?php endif; ?>
                    <div class="receipt-line receipt-total"><span>Total</span><span>₱<?php echo number_format($grand_total, 2); ?></span></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const btnPrintReceipt = document.getElementById('btnPrintReceipt');
        if (btnPrintReceipt) {
            btnPrintReceipt.addEventListener('click', function() {
                window.print();
            });
        }
    </script>
</body>
</html>
