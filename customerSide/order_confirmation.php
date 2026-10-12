<?php

require_once __DIR__ . '/../config/config.php';

if (!isLoggedIn() || !isCustomer()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

if (empty($_SESSION['last_order'])) {
    header('Location: customer-dashboard.php');
    exit;
}

$order = $_SESSION['last_order'];
unset($_SESSION['last_order']); // consume it so a page refresh doesn't break things
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed! - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        * { box-sizing: border-box; }
        body { 
            font-family: 'Montserrat', sans-serif; 
            background-color: var(--bg-dark); 
            color: var(--text-main);
            min-height: 100vh; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            margin: 0;
            padding: 16px;
        }
        
        .text-gold { color: var(--gold) !important; }

        .confirmation-card {
            background: var(--card-bg); 
            border: 1px solid var(--border-color);
            border-radius: 24px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.5);
            max-width: 600px; 
            width: 100%; 
            padding: 3rem; 
            text-align: center;
        }
        .success-icon { 
            width: 90px; 
            height: 90px; 
            background: rgba(212, 175, 55, 0.1); 
            border: 2px solid var(--gold);
            border-radius: 50%; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            margin: 0 auto 1.5rem; 
            box-shadow: 0 0 25px rgba(212, 175, 55, 0.25); 
            animation: pop 0.5s ease; 
        }
        .success-icon i { color: var(--gold); font-size: 2.5rem; }
        @keyframes pop { 0% { transform: scale(0); } 80% { transform: scale(1.1); } 100% { transform: scale(1); } }
        
        h1 { font-family: 'Playfair Display', serif; font-size: 2.2rem; color: white; margin-bottom: 0.5rem; }
        .order-id { font-size: 1.4rem; font-weight: 700; color: var(--gold); }
        
        .detail-box { 
            background: var(--input-bg); 
            border: 1px solid var(--border-color);
            border-radius: 14px; 
            padding: 1.5rem; 
            margin: 1.5rem 0; 
            text-align: left; 
        }
        .detail-row { 
            display: flex; 
            justify-content: space-between; 
            align-items: flex-start; 
            padding: 0.75rem 0; 
            border-bottom: 1px solid var(--border-color); 
            font-size: 0.95rem; 
            gap: 12px;
        }
        .detail-row:last-child { border-bottom: none; }
        .detail-label { color: var(--text-muted); font-weight: 500; white-space: nowrap; }
        .detail-value { 
            font-weight: 600; 
            color: white; 
            text-align: right; 
            word-break: break-word; 
            max-width: 280px;
        }
        
        /* Badges */
        .payment-badge { padding: 5px 16px; border-radius: 50px; font-size: 0.85rem; font-weight: 700; white-space: nowrap; }
        .payment-COD { background: rgba(46, 204, 113, 0.15); color: #2ecc71; border: 1px solid rgba(46, 204, 113, 0.3); }
        .payment-PAYPAL { background: rgba(0, 112, 186, 0.15); color: #4da6ff; border: 1px solid rgba(0, 112, 186, 0.3); }
        .payment-GCASH { background: rgba(0, 125, 255, 0.15); color: #4db8ff; border: 1px solid rgba(0, 125, 255, 0.3); }
        .badge.bg-warning { background-color: rgba(212, 175, 55, 0.15) !important; color: var(--gold) !important; border: 1px solid rgba(212, 175, 55, 0.3); font-weight: 600; }
        
        /* Buttons */
        .button-group {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 12px;
            margin-top: 1.5rem;
        }
        .btn-home { 
            background: var(--gold); 
            color: #121212; 
            border: none; 
            border-radius: 12px; 
            padding: 0.8rem 2rem; 
            font-weight: 700; 
            font-size: 1rem; 
            text-decoration: none; 
            transition: all 0.3s; 
            flex: 1 1 auto;
            min-width: 180px;
            text-align: center;
        }
        .btn-home:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(212, 175, 55, 0.3); color: #121212; background: var(--gold-hover); }
        
        .btn-outline-home { 
            background: transparent; 
            color: var(--gold); 
            border: 2px solid var(--gold); 
            border-radius: 12px; 
            padding: 0.75rem 2rem; 
            font-weight: 600; 
            font-size: 1rem; 
            text-decoration: none; 
            transition: all 0.3s; 
            flex: 1 1 auto;
            min-width: 180px;
            text-align: center;
        }
        .btn-outline-home:hover { background: var(--gold); color: #121212; box-shadow: 0 8px 25px rgba(212, 175, 55, 0.3); }

        /* ── RESPONSIVE ADJUSTMENTS ───────────────────── */
        @media (max-width: 576px) {
            .confirmation-card {
                padding: 2rem 1.25rem;
                border-radius: 20px;
            }
            h1 {
                font-size: 1.8rem;
            }
            .order-id {
                font-size: 1.2rem;
            }
            .success-icon {
                width: 70px;
                height: 70px;
                margin-bottom: 1rem;
            }
            .success-icon i {
                font-size: 2rem;
            }
            .detail-box {
                padding: 1rem;
            }
            .detail-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
                padding: 0.6rem 0;
                font-size: 0.9rem;
            }
            .detail-label {
                white-space: normal;
            }
            .detail-value {
                text-align: left;
                max-width: 100%;
            }
            .payment-badge {
                font-size: 0.8rem;
                padding: 4px 12px;
            }
            .button-group {
                flex-direction: column;
                gap: 10px;
            }
            .btn-home, .btn-outline-home {
                width: 100%;
                min-width: auto;
            }
        }
</style>
    <link rel="stylesheet" href="customer-theme.css">
</head>
<body>
    <div class="confirmation-card">
        <div class="success-icon"><i class="fas fa-check"></i></div>

        <h1>Order Confirmed!</h1>
        <p class="text-gold mb-1">Thank you for your order at La Elena Fishyalan.</p>
        <p class="order-id">#<?php echo htmlspecialchars($order['order_id']); ?></p>

        <div class="detail-box">
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-user me-2"></i>Name</span>
                <span class="detail-value"><?php echo htmlspecialchars($order['delivery_name']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-map-marker-alt me-2"></i>Deliver to</span>
                <span class="detail-value"><?php echo htmlspecialchars($order['delivery_address']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-credit-card me-2"></i>Payment</span>
                <span class="payment-badge payment-<?php echo htmlspecialchars($order['payment_method']); ?>">
                    <?php
                    $labels = ['COD' => '💵 Cash on Delivery', 'PAYPAL' => '🅿️ PayPal', 'GCASH' => '📱 GCash'];
                    echo htmlspecialchars($labels[$order['payment_method']] ?? $order['payment_method']);
                    ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-receipt me-2"></i>Total Paid</span>
                <span class="detail-value" style="font-size:1.1rem; color: var(--gold);">₱<?php echo number_format($order['total'], 2); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-info-circle me-2"></i>Status</span>
                <span class="badge bg-warning px-3 py-2">Pending Preparation</span>
            </div>
        </div>

        <p class="text-gold" style="font-size:0.9rem;">
            <?php if ($order['payment_method'] === 'COD'): ?>
                <i class="fas fa-info-circle me-1" style="color: #2ecc71;"></i>Please have cash ready when your order arrives.
            <?php else: ?>
                <i class="fas fa-check-circle me-1" style="color: #2ecc71;"></i>Your payment has been recorded. Thank you!
            <?php endif; ?>
        </p>

        <div class="button-group">
            <a href="orders.php" class="btn-home"><i class="fas fa-list me-2"></i>View My Orders</a>
            <a href="menu.php" class="btn-outline-home"><i class="fas fa-utensils me-2"></i>Continue Shopping</a>
        </div>
    </div>
</body>
</html>
