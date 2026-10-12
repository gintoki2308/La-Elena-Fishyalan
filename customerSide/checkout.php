<?php
require_once '../config/config.php';
require_once '../config/Database.php';
require_once 'stock_availability.php';
require_once __DIR__ . '/../config/recipe_inventory_helper.php';

if (!isLoggedIn() || !isCustomer()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

if (empty($_SESSION['checkout_items'])) {
    header('Location: cart.php');
    exit;
}

$database = new Database();
$db = $database->connect();

$error = null;
$checkoutCsrf = $_SESSION['checkout_csrf'] ?? ($_SESSION['checkout_csrf'] = bin2hex(random_bytes(32)));

function ensureStockDeductedColumn(PDO $db) {
    $colStmt = $db->query("SHOW COLUMNS FROM online_orders LIKE 'stock_deducted'");
    if (!$colStmt->fetch(PDO::FETCH_ASSOC)) {
        $db->exec("ALTER TABLE online_orders ADD COLUMN stock_deducted TINYINT(1) DEFAULT 0 AFTER metrics_added");
    }
}

// Pre-fill from customer accounts
$member = [];
$current_points = 0;
try {
    $s = $db->prepare("SELECT first_name, last_name, email, phone FROM customer WHERE member_id = :id");
    $s->execute([':id' => $_SESSION['user_id']]);
    $member = $s->fetch(PDO::FETCH_ASSOC) ?? [];
} catch(Exception $e) {}

// Pre-fill address fields: Profile address > Last order address > Blank
$prefill_house = '';
$prefill_street = '';
$prefill_barangay = '';
$prefill_city = '';
$prefill_province = '';
$prefill_zip = '';

try {
    // Priority 1: Profile address
    $addrStmt = $db->prepare("SELECT address FROM customer WHERE member_id = :id");
    $addrStmt->execute([':id' => $_SESSION['user_id']]);
    $profileAddr = trim($addrStmt->fetchColumn() ?? '');

    if (!empty($profileAddr)) {
        $parts = array_map('trim', explode(',', $profileAddr));
        if (count($parts) >= 6) {
            $prefill_house    = $parts[0];
            $prefill_street   = $parts[1];
            $prefill_barangay = $parts[2];
            $prefill_city     = $parts[3];
            $prefill_province = $parts[4];
            $prefill_zip      = $parts[5];
        } else {
            // Freeform address — put it all in house field for the customer to redistribute
            $prefill_house = $profileAddr;
        }
    } else {
        // Priority 2: Most recent order address
        $orderStmt = $db->prepare("SELECT delivery_address FROM online_orders WHERE member_id = :id ORDER BY order_date DESC LIMIT 1");
        $orderStmt->execute([':id' => $_SESSION['user_id']]);
        $lastAddr = trim($orderStmt->fetchColumn() ?? '');

        if (!empty($lastAddr)) {
            $parts = array_map('trim', explode(',', $lastAddr));
            if (count($parts) >= 6) {
                $prefill_house    = $parts[0];
                $prefill_street   = $parts[1];
                $prefill_barangay = $parts[2];
                $prefill_city     = $parts[3];
                $prefill_province = $parts[4];
                $prefill_zip      = $parts[5];
            }
        }
    }
    // Priority 3: All remain blank (default)
} catch(Exception $e) {}

// Compute cart items + total
$cart_items = [];
$grand = 0;
foreach ($_SESSION['checkout_items'] as $item_id => $qty) {
    $s = $db->prepare("SELECT * FROM menu_items WHERE item_id = :iid");
    $s->execute([':iid' => $item_id]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $row['quantity'] = $qty;
        $row['subtotal'] = $qty * $row['price'];
        $grand += $row['subtotal'];
        $cart_items[] = $row;
    }
}
$tax = $grand * 0.10;
$total_with_tax = $grand + $tax;

// Handle form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($checkoutCsrf, inventoryText($_POST, 'csrf_token'))) throw new InvalidArgumentException('Your checkout form expired. Refresh and try again.');
        ensureStockDeductedColumn($db);
        $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $db->beginTransaction();

        $delivery_name    = trim($_POST['delivery_name'] ?? '');
        $delivery_email   = trim($_POST['delivery_email'] ?? '');
        $delivery_phone   = trim($_POST['delivery_phone'] ?? '');
        
        // Fetch separate address fields
        $addr_house       = trim($_POST['addr_house'] ?? '');
        $addr_street      = trim($_POST['addr_street'] ?? '');
        $addr_barangay    = trim($_POST['addr_barangay'] ?? '');
        $addr_city        = trim($_POST['addr_city'] ?? '');
        $addr_province    = trim($_POST['addr_province'] ?? '');
        $addr_zip         = trim($_POST['addr_zip'] ?? '');
        
        $payment_method   = $_POST['payment_method'] ?? 'COD';
        $applied_promo    = trim($_POST['applied_promo'] ?? '');
        $points_to_redeem = 0;
        $discount_amount  = 0;
        $promo_discount   = 0;
        $promo_code_to_save = null;

        if (!empty($applied_promo)) {
            $s = $db->prepare("SELECT * FROM promotions WHERE promotion_name = :code AND is_active = 1");
            $s->execute([':code' => $applied_promo]);
            $promo = $s->fetch(PDO::FETCH_ASSOC);
            if ($promo) {
                $curr = date('Y-m-d');
                if ((!$promo['start_date'] || $curr >= $promo['start_date']) && (!$promo['end_date'] || $curr <= $promo['end_date'])) {
                    if (!isset($promo['minimum_order_value']) || $promo['minimum_order_value'] <= 0 || $total_with_tax >= $promo['minimum_order_value']) {
                        
                        $can_use = true;
                        if (!empty($promo['is_single_use'])) {
                            $chk = $db->prepare("SELECT COUNT(*) FROM online_orders WHERE member_id = :mid AND promo_code = :code");
                            $chk->execute([':mid' => $_SESSION['user_id'], ':code' => $promo['promotion_name']]);
                            if ($chk->fetchColumn() > 0) {
                                $can_use = false;
                            }
                        }

                        if ($can_use) {
                            $eligible_subtotal = 0;
                            $allowed_categories = empty($promo['applicable_category']) ? [] : array_map('strtolower', array_map('trim', explode(',', $promo['applicable_category'])));
                            
                            foreach ($cart_items as $item) {
                                if (empty($allowed_categories) || in_array(strtolower(trim($item['category'])), $allowed_categories)) {
                                    $eligible_subtotal += $item['subtotal'];
                                }
                            }
                            $eligible_total = $eligible_subtotal * 1.10;

                            if ($eligible_total > 0) {
                                if ($promo['discount_type'] === 'PERCENTAGE') {
                                    $promo_discount = $eligible_total * ($promo['discount_value'] / 100);
                                } else {
                                    $promo_discount = min(floatval($promo['discount_value']), $eligible_total);
                                }
                                $promo_code_to_save = $promo['promotion_name'];
                            }
                        }
                    }
                }
            }
        }

        $temp_total = $total_with_tax - $promo_discount;
        if ($temp_total < 0) $temp_total = 0;

        if (false) {
            // 1 point = ₱1 discount. Max redemption = temp_total
            $points_to_redeem = 0;
        }

        $discount_amount = $promo_discount;
        $final_total = $total_with_tax - $discount_amount;

        // Check if any personal details OR any address fields are empty
        if (empty($delivery_name) || empty($delivery_email) || empty($delivery_phone) || 
            empty($addr_house) || empty($addr_street) || empty($addr_barangay) || 
            empty($addr_city) || empty($addr_province) || empty($addr_zip)) {
            throw new Exception('Please fill in all delivery and address details.');
        }
        
        if (!filter_var($delivery_email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address.');
        }
        if (!in_array($payment_method, ['COD', 'PAYPAL', 'GCASH'])) {
            throw new Exception('Invalid payment method selected.');
        }

        // Combine the separate fields into one complete address string for the database
        $delivery_address = "$addr_house, $addr_street, $addr_barangay, $addr_city, $addr_province, $addr_zip";

        // Generate a unique order number
        $order_number = 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

        // Define payment status
        $payment_status = ($payment_method !== 'COD') ? 'PAID' : 'PENDING';

        // Calculate New Points Earned (10% of total amount spent after discount)
        $points_earned = 0;

        $metrics_added = ($payment_method !== 'COD') ? 1 : 0;

        // Create order
        $ins = $db->prepare("INSERT INTO online_orders
            (member_id, order_number, order_date, total_amount, points_redeemed, points_earned, discount_amount, promo_code, status, payment_method, payment_status,
             delivery_name, delivery_email, delivery_phone, delivery_address, metrics_added, stock_deducted)
            VALUES (:mid, :onum, NOW(), :total, :pr, :pe, :discount, :promo, 'PENDING', :pm, :ps, :dn, :de, :dphone, :address, :metrics, 0)");
        $ins->execute([
            ':mid'      => $_SESSION['user_id'],
            ':onum'     => $order_number,
            ':total'    => $final_total,
            ':pr'       => 0,
            ':pe'       => $points_earned,
            ':discount' => $discount_amount,
            ':promo'    => $promo_code_to_save,
            ':pm'       => $payment_method,
            ':ps'       => $payment_status,
            ':dn'       => $delivery_name,
            ':de'       => $delivery_email,
            ':dphone'   => $delivery_phone,
            ':address'  => $delivery_address,
            ':metrics'  => $metrics_added,
        ]);
        $order_id = $db->lastInsertId();

        // Create the pending reservation. Physical stock is deducted later by staff/admin status flow.
        $itemStmt = $db->prepare("INSERT INTO online_order_items (order_id, item_id, quantity, item_price) VALUES (:oid, :iid, :qty, :price)");
        $stockQuery = $db->prepare("SELECT price, stock_quantity, item_name FROM menu_items WHERE item_id = :iid AND is_available = TRUE FOR UPDATE");
        
        $ingredientQuantities = $_SESSION['checkout_items'];
        ksort($ingredientQuantities, SORT_NUMERIC);
        foreach ($ingredientQuantities as $item_id => $qty) {
            $item_id = (int) $item_id;
            $qty = (int) $qty;
            if ($item_id <= 0 || $qty <= 0) {
                throw new Exception('This order contains an invalid item quantity.');
            }

            $stockQuery->execute([':iid' => $item_id]);
            $stockData = $stockQuery->fetch(PDO::FETCH_ASSOC);
            if (!$stockData) {
                throw new Exception("Insufficient stock for item: Unknown Item");
            }

            $availability = getOrderableStock($db, $item_id, (int)$_SESSION['user_id'], (int)($stockData['stock_quantity'] ?? 0));
            if ($qty > (int)$availability['available']) {
                throw new Exception(stockReservationMessage($stockData['item_name'] ?? 'Unknown Item', $availability));
            }
            
            $itemStmt->execute([':oid' => $order_id, ':iid' => $item_id, ':qty' => $qty, ':price' => $stockData['price'] ?? 0]);
        }

        deductRecipeInventory($db, 'ONLINE', (int)$order_id, $ingredientQuantities);
        $db->commit();

        // Clear cart and redirect to confirmation
        if (isset($_SESSION['checkout_items'])) {
            $is_buy_now = isset($_SESSION['is_buy_now']) && $_SESSION['is_buy_now'];
            if (!$is_buy_now) {
                foreach ($_SESSION['checkout_items'] as $item_id => $qty) {
                    unset($_SESSION['cart'][$item_id]);
                }
            }
            unset($_SESSION['checkout_items']);
            unset($_SESSION['is_buy_now']);
        }
        $_SESSION['last_order'] = [
            'order_id'       => $order_id,
            'payment_method' => $payment_method,
            'payment_status' => $payment_status,
            'total'          => $final_total,
            'discount'       => $discount_amount,
            'points_earned'  => $points_earned,
            'delivery_name'  => $delivery_name,
            'delivery_address' => $delivery_address,
        ];
        header('Location: order_confirmation.php');
        exit;

    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Montserrat:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        .nav-link { color: rgba(255,255,255,0.8) !important; font-weight: 500; border-radius: 8px; transition: all 0.3s; }
        .nav-link:hover { background: rgba(255,255,255,0.1); color: var(--gold) !important; }

        .checkout-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            padding: 2rem;
        }
        .section-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--gold);
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
        }
        .section-title i { color: var(--gold); margin-right: 8px; }

        /* Payment Method Cards */
        .payment-methods { display: flex; gap: 1rem; flex-wrap: wrap; }
        .payment-card {
            flex: 1;
            min-width: 130px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 1.2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: var(--input-bg);
            position: relative;
        }
        .payment-card:hover { border-color: var(--gold); transform: translateY(-3px); box-shadow: 0 8px 20px rgba(212, 175, 55, 0.15); }
        .payment-card.selected {
            border-color: var(--gold);
            background: rgba(212, 175, 55, 0.08);
            box-shadow: 0 8px 20px rgba(212, 175, 55, 0.2);
        }
        .payment-card .check-icon {
            position: absolute; top: 8px; right: 10px;
            color: var(--gold); font-size: 1rem;
            display: none;
        }
        .payment-card.selected .check-icon { display: block; }
        .payment-card .pay-logo { font-size: 2.5rem; margin-bottom: 0.5rem; }
        .payment-card .pay-name { font-weight: 600; font-size: 0.9rem; color: var(--text-main); }
        .payment-card .pay-desc { font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem; }

        .cod-icon { color: #2ecc71; }
        .paypal-icon { color: #0070ba; }
        .gcash-icon { color: #007DFF; }

        /* Order Summary */
        .summary-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            padding: 1.5rem;
            position: sticky;
            top: 80px;
        }
        .order-item { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid var(--border-color); font-size: 0.9rem; }
        .order-item:last-child { border-bottom: none; }
        .total-row { display: flex; justify-content: space-between; margin-top: 0.5rem; font-size: 1rem; color: var(--text-main); }
        .total-row .text-muted { color: var(--text-muted) !important; }
        .total-row.grand { font-size: 1.3rem; font-weight: 700; padding-top: 0.75rem; border-top: 1px solid var(--border-color); color: white; }
        .total-row.grand span:last-child { color: var(--gold); }

        /* Form styling */
        .form-label { font-weight: 600; font-size: 0.85rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .form-control { background-color: var(--input-bg); border: 1px solid #444; color: white; border-radius: 8px; padding: 0.75rem 1rem; font-size: 0.95rem; transition: all 0.3s; }
        .form-control:focus { background-color: var(--input-bg); border-color: var(--gold); color: white; box-shadow: 0 0 0 0.2rem rgba(212, 175, 55, 0.25); }
        .form-control::placeholder { color: #666; }

        .btn-checkout {
            background: var(--gold);
            border: none; color: #121212;
            padding: 1rem 2rem; border-radius: 8px;
            font-weight: 700; font-size: 1.1rem;
            letter-spacing: 0.5px; width: 100%;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.2);
        }
        .btn-checkout:hover { background: var(--gold-hover); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(212, 175, 55, 0.4); color: #121212; }

        /* Steps indicator */
        .steps { display: flex; align-items: center; margin-bottom: 2rem; }
        .step { display: flex; align-items: center; gap: 0.5rem; }
        .step-num { width: 30px; height: 30px; border-radius: 50%; background: var(--gold); color: #121212; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.85rem; }
        .step-label { font-weight: 600; color: var(--text-main); font-size: 0.9rem; }
        .step-divider { flex: 1; height: 1px; background: var(--border-color); margin: 0 0.75rem; }

        /* Simulation Panels */
        .sim-panel { background: #1a1a1a; border: 1px solid #444; }

        @media(max-width: 768px) {
            .payment-methods { flex-direction: column; }
        }
</style>
    <link rel="stylesheet" href="customer-theme.css">
</head>
<body>
    <?php
    $customerNavPage = 'cart.php';
    require 'customer-navbar.php';
    ?>

    <div class="container py-4">
        <div class="text-center mb-4">
            <h1 style="font-family:'Playfair Display',serif; font-size:2.5rem; font-weight:700; color: white;">
                <i class="fas fa-lock me-2" style="color: var(--gold);"></i>Secure Checkout
            </h1>
            <p class="text-gold">Complete your order in just a few steps</p>
        </div>

        <div class="steps mb-4 px-2">
            <div class="step"><div class="step-num">1</div><div class="step-label">Delivery</div></div>
            <div class="step-divider"></div>
            <div class="step"><div class="step-num">2</div><div class="step-label">Payment</div></div>
            <div class="step-divider"></div>
            <div class="step"><div class="step-num">3</div><div class="step-label" style="color:var(--text-muted);">Confirm</div></div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger rounded-3 mb-4" style="background:#2c0b0e; border-color:#842029; color:#ea868f;">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="checkout-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($checkoutCsrf) ?>">
            <div class="row g-4">
                <div class="col-lg-7">

                    <div class="checkout-card mb-4">
                        <h4 class="section-title"><i class="fas fa-map-marker-alt"></i>Delivery Details</h4>
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" name="delivery_name" class="form-control" required
                                    value="<?php echo htmlspecialchars(trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))); ?>"
                                    placeholder="e.g. John Doe">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email Address <span class="text-danger">*</span></label>
                                <input type="email" name="delivery_email" class="form-control" required
                                    value="<?php echo htmlspecialchars($member['email'] ?? ''); ?>"
                                    placeholder="you@example.com">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phone Number <span class="text-danger">*</span></label>
                                <input type="tel" name="delivery_phone" class="form-control" required
                                    value="<?php echo htmlspecialchars($member['phone'] ?? ''); ?>"
                                    placeholder="+63 9XX XXX XXXX">
                            </div>

                            <div class="col-12 mt-4">
                                <h5 class="text-white" style="font-size: 1rem; border-bottom: 1px solid #333; padding-bottom: 8px;">Address Information</h5>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label">House/Unit No. <span class="text-danger">*</span></label>
                                <input type="text" name="addr_house" class="form-control" required placeholder="e.g. Blk 1 Lot 2"
                                    value="<?php echo htmlspecialchars($prefill_house); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Street <span class="text-danger">*</span></label>
                                <input type="text" name="addr_street" class="form-control" required placeholder="e.g. Main Street"
                                    value="<?php echo htmlspecialchars($prefill_street); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Barangay <span class="text-danger">*</span></label>
                                <input type="text" name="addr_barangay" class="form-control" required placeholder="e.g. Labuyo"
                                    value="<?php echo htmlspecialchars($prefill_barangay); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">City/Municipality <span class="text-danger">*</span></label>
                                <input type="text" name="addr_city" class="form-control" required placeholder="e.g. Bonifacio"
                                    value="<?php echo htmlspecialchars($prefill_city); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Province <span class="text-danger">*</span></label>
                                <input type="text" name="addr_province" class="form-control" required placeholder="e.g. Miss.Occ"
                                    value="<?php echo htmlspecialchars($prefill_province); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">ZIP Code <span class="text-danger">*</span></label>
                                <input type="text" name="addr_zip" class="form-control" required placeholder="e.g. 1000"
                                    value="<?php echo htmlspecialchars($prefill_zip); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="checkout-card">
                        <h4 class="section-title"><i class="fas fa-credit-card"></i>Payment Method</h4>
                        <input type="hidden" name="payment_method" id="payment_method_input" value="COD">
                        <input type="hidden" name="applied_promo" id="applied_promo_input" value="">

                        <div class="payment-methods">
                            <div class="payment-card selected" data-method="COD" onclick="selectPayment(this,'COD')">
                                <i class="check-icon fas fa-check-circle"></i>
                                <div class="pay-logo cod-icon"><i class="fas fa-money-bill-wave"></i></div>
                                <div class="pay-name">Cash on Delivery</div>
                                <div class="pay-desc">Pay when you receive your order</div>
                            </div>
                            <div class="payment-card" data-method="PAYPAL" onclick="selectPayment(this,'PAYPAL')">
                                <i class="check-icon fas fa-check-circle"></i>
                                <div class="pay-logo paypal-icon"><i class="fab fa-paypal"></i></div>
                                <div class="pay-name">PayPal</div>
                                <div class="pay-desc">Simulated — no real charge</div>
                            </div>
                            <div class="payment-card" data-method="GCASH" onclick="selectPayment(this,'GCASH')">
                                <i class="check-icon fas fa-check-circle"></i>
                                <div class="pay-logo gcash-icon"><i class="fas fa-mobile-alt"></i></div>
                                <div class="pay-name">GCash</div>
                                <div class="pay-desc">Simulated — no real charge</div>
                            </div>
                        </div>

                        <div id="paypal-panel" class="mt-4 p-3 rounded-3 sim-panel" style="display:none;">
                            <div class="text-center mb-2"><i class="fab fa-paypal fa-2x" style="color:#0070ba;"></i></div>
                            <p class="text-center fw-bold text-white">PayPal (Demo Mode)</p>
                            <input type="text" class="form-control mb-2" placeholder="PayPal Email" value="demo@paypal.com">
                            <input type="password" class="form-control" placeholder="Password" value="••••••••">
                            <small class="text-gold d-block mt-2 text-center">This is a simulation. No real payment will be processed.</small>
                        </div>

                        <div id="gcash-panel" class="mt-4 p-3 rounded-3 sim-panel" style="display:none;">
                            <div class="text-center mb-2">
                                <span style="font-size:2rem; font-weight:800; color:#007DFF;">G</span>
                                <span style="font-size:2rem; font-weight:800; color:#007DFF;">Cash</span>
                            </div>
                            <p class="text-center fw-bold text-white">GCash (Demo Mode)</p>
                            <input type="text" class="form-control mb-2" placeholder="GCash Number" value="09123456789">
                            <input type="password" class="form-control" placeholder="4-digit MPIN" value="1234">
                            <small class="text-gold d-block mt-2 text-center">This is a simulation. No real payment will be processed.</small>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="summary-card">
                        <h4 class="section-title"><i class="fas fa-receipt"></i> Order Summary</h4>

                        <?php foreach ($cart_items as $it): ?>
                            <div class="order-item">
                                <span>
                                    <span class="badge bg-secondary text-white me-2" style="background-color: #444 !important;"><?php echo $it['quantity']; ?>×</span>
                                    <?php echo htmlspecialchars($it['item_name']); ?>
                                </span>
                                <span class="fw-bold" style="color: #ccc;">₱<?php echo number_format($it['subtotal'], 2); ?></span>
                            </div>
                        <?php endforeach; ?>

                        <div class="total-row mt-3">
                            <span class="text-muted">Subtotal</span>
                            <span>₱<?php echo number_format($grand, 2); ?></span>
                        </div>
                        <div class="total-row">
                            <span class="text-muted">Tax (10%)</span>
                            <span>₱<?php echo number_format($tax, 2); ?></span>
                        </div>
                        <div class="total-row" id="promo-discount-row" style="display:none;">
                            <span class="text-success">Promo Discount (<span id="promo-code-display"></span>)</span>
                            <span id="promo-discount-display" class="text-success">-₱0.00</span>
                        </div>
                        <div class="total-row grand">
                            <span>Total</span>
                            <span id="display-total">₱<?php echo number_format($total_with_tax, 2); ?></span>
                        </div>

                        <!-- Promo Code Section -->
                        <div class="mt-4 p-3 rounded-3" style="background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);">
                            <label class="form-label text-white mb-2"><i class="fas fa-tag me-2" style="color: var(--gold);"></i>Promo Code</label>
                            <div class="input-group">
                                <input type="text" id="promo_code_field" class="form-control" placeholder="Enter code here" style="text-transform: uppercase;">
                                <button type="button" id="apply_promo_btn" class="btn" style="background: var(--gold); color: #121212; font-weight: 600;">Apply</button>
                            </div>
                            <div id="promo_message" class="small mt-2" style="display:none;"></div>
                        </div>
<button type="submit" class="btn-checkout mt-3" id="place-order-btn">
                            <i class="fas fa-lock me-2"></i>Place Order — <span id="btn-total">₱<?php echo number_format($total_with_tax, 2); ?></span>
                        </button>

                        <div class="text-center mt-3">
                            <small class="text-white"><i class="fas fa-shield-alt me-1"></i>Your information is secure and encrypted</small>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function selectPayment(el, method) {
            document.querySelectorAll('.payment-card').forEach(c => c.classList.remove('selected'));
            el.classList.add('selected');
            document.getElementById('payment_method_input').value = method;

            document.getElementById('paypal-panel').style.display = (method === 'PAYPAL') ? 'block' : 'none';
            document.getElementById('gcash-panel').style.display  = (method === 'GCASH')  ? 'block' : 'none';
        }

        document.getElementById('checkout-form').addEventListener('submit', function(e) {
            e.preventDefault();

            if (!this.checkValidity()) {
                this.reportValidity();
                return;
            }

            const method = document.getElementById('payment_method_input').value;
            const totals = calculateTotals();
            
            let total = totals.total;
            let promoDiscount = totals.promoDiscount;

            const icons  = { COD: '💵', PAYPAL: '🅿️', GCASH: '📱' };
            const labels = { COD: 'Cash on Delivery', PAYPAL: 'PayPal (Simulated)', GCASH: 'GCash (Simulated)' };

            if (typeof Swal === 'undefined') {
                if (confirm(`Place order using ${labels[method]} for ₱${total.toFixed(2)}?`)) {
                    this.submit();
                }
                return;
            }

            Swal.fire({
                title: 'Confirm Order',
                html: `
                    <div class="text-start px-3">
                        <p class="mb-2">Payment: <strong>${labels[method]}</strong></p>
                        ${promoDiscount > 0 ? `<p class="mb-2 text-success">Promo Discount: <strong>-₱${promoDiscount.toFixed(2)}</strong></p>` : ''}
                        <p class="mb-0 fs-5">Final Total: <strong style="color:#D4AF37;">₱${total.toFixed(2)}</strong></p>
                    </div>
                `,
                icon: 'question',
                background: '#1e1e1e',
                color: '#fff',
                showCancelButton: true,
                confirmButtonColor: '#D4AF37',
                cancelButtonColor: '#d33',
                confirmButtonText: `<span style="color:#121212; font-weight:bold;">${icons[method]} Place Order</span>`,
                cancelButtonText: 'Review Again'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Processing...',
                        text: 'Please wait while we confirm your order.',
                        background: '#1e1e1e',
                        color: '#fff',
                        allowOutsideClick: false,
                        showConfirmButton: false,
                        didOpen: () => { Swal.showLoading(); document.getElementById('checkout-form').submit(); }
                    });
                }
            });
        });

        // Unified Totals Calculation
        const baseTotal = <?php echo $total_with_tax; ?>;
        let appliedPromoDiscount = 0;

        function calculateTotals() {
            let discount = appliedPromoDiscount;
            let tempTotal = baseTotal - discount;
            if (tempTotal < 0) tempTotal = 0;
// Update UI
            document.getElementById('display-total').textContent = '₱' + tempTotal.toFixed(2);
            document.getElementById('btn-total').textContent = '₱' + tempTotal.toFixed(2);
            return { total: tempTotal, promoDiscount: discount };
        }
// Promo Code Logic
        document.getElementById('apply_promo_btn')?.addEventListener('click', async function() {
            const code = document.getElementById('promo_code_field').value.trim();
            const msgEl = document.getElementById('promo_message');
            
            if (!code) {
                msgEl.style.display = 'block';
                msgEl.className = 'small mt-2 text-danger';
                msgEl.textContent = 'Please enter a promo code.';
                return;
            }

            msgEl.style.display = 'block';
            msgEl.className = 'small mt-2 text-warning';
            msgEl.textContent = 'Validating...';

            try {
                const response = await fetch('../api/apply_promo.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ promo_code: code, cart_total: baseTotal })
                });
                const result = await response.json();

                if (result.success) {
                    msgEl.className = 'small mt-2 text-success';
                    msgEl.textContent = result.message;
                    
                    document.getElementById('applied_promo_input').value = result.promo_code;
                    document.getElementById('promo-code-display').textContent = result.promo_code;
                    document.getElementById('promo_code_field').readOnly = true;
                    this.disabled = true;

                    appliedPromoDiscount = result.calculated_discount;
                    
                    document.getElementById('promo-discount-display').textContent = '-₱' + appliedPromoDiscount.toFixed(2);
                    document.getElementById('promo-discount-row').style.display = 'flex';

                    calculateTotals();
                } else {
                    msgEl.className = 'small mt-2 text-danger';
                    msgEl.textContent = result.message;
                    document.getElementById('applied_promo_input').value = '';
                    appliedPromoDiscount = 0;
                    document.getElementById('promo-discount-row').style.display = 'none';
                    calculateTotals();
                }
            } catch (err) {
                msgEl.className = 'small mt-2 text-danger';
                msgEl.textContent = 'An error occurred. Please try again.';
            }
        });
    </script>
    
    <script>
        document.addEventListener('click', function(e) {
            const link = e.target.closest('.logout-link');
            if (link) {
                e.preventDefault();
                const logoutUrl = link.getAttribute('href');
                Swal.fire({
                    title: 'Ready to Leave?',
                    text: 'Are you sure you want to log out?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#d4af37',
                    cancelButtonColor: '#333',
                    confirmButtonText: 'Yes, log me out',
                    cancelButtonText: 'Cancel',
                    background: '#1e1e1e',
                    color: '#fff'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = logoutUrl;
                    }
                });
            }
        });
    </script>
    <script src="../assets/js/phone-validator.js"></script>
</body>
</html>
