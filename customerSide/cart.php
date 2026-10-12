<?php
require_once '../config/config.php';
require_once '../config/Database.php';
require_once 'stock_availability.php';

if (!isLoggedIn() || !isCustomer()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

$error = null;
$success_msg = null;
$order_id = null;

// Handle quantity updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_cart') {
        $item_id = intval($_POST['item_id']);
        $quantity = max(0, intval($_POST['quantity']));

        $stockStmt = $db->prepare("SELECT item_name, stock_quantity FROM menu_items WHERE item_id = :iid");
        $stockStmt->execute([':iid' => $item_id]);
        $itemData = $stockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$itemData) {
            unset($_SESSION['cart'][$item_id]);
            $error = "This item is no longer available and was removed from your cart.";
        } else {
            $availability = getOrderableStock($db, $item_id, (int)$_SESSION['user_id'], (int)($itemData['stock_quantity'] ?? 0));
            $availableStock = (int)$availability['available'];

            if ($quantity > $availableStock) {
                if ($availableStock > 0) {
                    $_SESSION['cart'][$item_id] = $availableStock;
                    $error = stockReservationMessage($itemData['item_name'], $availability);
                } else {
                    unset($_SESSION['cart'][$item_id]);
                    $error = stockReservationMessage($itemData['item_name'], $availability);
                }
            } elseif ($quantity > 0) {
                $_SESSION['cart'][$item_id] = $quantity;
                $success_msg = "Cart updated successfully!";
            } else {
                unset($_SESSION['cart'][$item_id]);
                $success_msg = "Item removed from cart.";
            }
        }
        // Don't redirect, let the page show SweetAlert
    } elseif ($_POST['action'] === 'remove_item') {
        $item_id = intval($_POST['item_id']);
        unset($_SESSION['cart'][$item_id]);
        $success_msg = "Item removed from your cart.";
    } elseif ($_POST['action'] === 'clear_cart') {
        $_SESSION['cart'] = [];
        $success_msg = "Your cart has been cleared.";
    } elseif ($_POST['action'] === 'prepare_checkout') {
        if (!empty($_POST['selected_items'])) {
            $_SESSION['checkout_items'] = [];
            foreach ($_POST['selected_items'] as $item_id) {
                $item_id = intval($item_id);
                if (isset($_SESSION['cart'][$item_id])) {
                    $qty = (int)$_SESSION['cart'][$item_id];
                    $stockStmt = $db->prepare("SELECT item_name, stock_quantity FROM menu_items WHERE item_id = :iid AND is_available = TRUE LIMIT 1");
                    $stockStmt->execute([':iid' => $item_id]);
                    $itemData = $stockStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$itemData) {
                        unset($_SESSION['cart'][$item_id]);
                        $error = 'One of your selected items is no longer available and was removed from your cart.';
                        break;
                    }

                    $availability = getOrderableStock($db, $item_id, (int)$_SESSION['user_id'], (int)($itemData['stock_quantity'] ?? 0));
                    if ($qty > (int)$availability['available']) {
                        if ((int)$availability['available'] > 0) {
                            $_SESSION['cart'][$item_id] = (int)$availability['available'];
                        } else {
                            unset($_SESSION['cart'][$item_id]);
                        }
                        $error = stockReservationMessage($itemData['item_name'], $availability);
                        break;
                    }

                    $_SESSION['checkout_items'][$item_id] = $qty;
                }
            }
            if (!$error && !empty($_SESSION['checkout_items'])) {
                $_SESSION['is_buy_now'] = false;
                header('Location: checkout.php');
                exit;
            } else {
                $error = $error ?: "None of the selected items are in your cart.";
            }
        } else {
            $error = "Please select at least one item to checkout.";
        }
    } elseif ($_POST['action'] === 'checkout') {
        $error = "Unsupported checkout action. Please use the Proceed to Checkout button.";
    } elseif ($_POST['action'] === 'old_checkout') {
        $error = "Unsupported checkout action. Please use the Proceed to Checkout button.";
    }
}

// load cart details
$cart_items = [];
$cart_adjusted_to_stock = false;
foreach ($_SESSION['cart'] as $item_id => $qty) {
    $stmt = $db->prepare("SELECT * FROM menu_items WHERE item_id = :iid");
    $stmt->execute([':iid' => $item_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $availability = getOrderableStock($db, (int)$item_id, (int)$_SESSION['user_id'], (int)($row['stock_quantity'] ?? 0));
        $availableStock = (int)$availability['available'];
        if ($availableStock === 0) {
            unset($_SESSION['cart'][$item_id]);
            $cart_adjusted_to_stock = true;
            if (!$error) {
                $error = stockReservationMessage($row['item_name'], $availability);
            }
            continue;
        }

        if ($qty > $availableStock) {
            $qty = $availableStock;
            $_SESSION['cart'][$item_id] = $qty;
            $cart_adjusted_to_stock = true;
        }

        $row['quantity'] = $qty;
        $row['stock_quantity'] = $availableStock;
        $cart_items[] = $row;
    }
}

if ($cart_adjusted_to_stock && !$error && !$success_msg) {
    $success_msg = "Some cart quantities were adjusted to match the currently available stock.";
}

$cart_count = array_sum($_SESSION['cart']);

// Get favorites count for the badge
$favStmt = $db->prepare("SELECT COUNT(*) FROM favorites WHERE member_id = :mid");
$favStmt->execute([':mid' => $_SESSION['user_id']]);
$fav_count = $favStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping Cart - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Montserrat:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Selective Checkout Calculation
        document.addEventListener('DOMContentLoaded', function() {
            const selectors = document.querySelectorAll('.item-selector');
            const subtotalEl = document.getElementById('display-subtotal');
            const taxEl = document.getElementById('display-tax');
            const totalEl = document.getElementById('display-total');
            const countEl = document.getElementById('checkout-count');
            const btnCheckout = document.getElementById('btn-proceed-checkout');

            if (!subtotalEl) return;

            function recalculateTotals() {
                let subtotal = 0;
                let count = 0;

                selectors.forEach(cb => {
                    if (cb.checked) {
                        const price = parseFloat(cb.dataset.price);
                        const qty = parseInt(cb.dataset.qty);
                        subtotal += (price * qty);
                        count++;
                    }
                });

                const tax = subtotal * 0.10;
                const total = subtotal + tax;

                subtotalEl.textContent = '₱' + subtotal.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                taxEl.textContent = '₱' + tax.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                totalEl.textContent = '₱' + total.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });

                if (countEl) countEl.textContent = count;

                if (count === 0) {
                    btnCheckout.disabled = true;
                    btnCheckout.innerHTML = '<i class="fas fa-lock me-2"></i>Select items to checkout';
                } else {
                    btnCheckout.disabled = false;
                    btnCheckout.innerHTML = `<i class="fas fa-lock me-2"></i>Proceed to Checkout (<span id="checkout-count">${count}</span> items)`;
                }
            }

            selectors.forEach(cb => {
                cb.addEventListener('change', recalculateTotals);
            });

            // Initial calculation
            recalculateTotals();
        });
    </script>
    <style>
        :root {
            --gold: #D4AF37;
            --dark-gold: #B4941F;
            --deep-charcoal: #121212;
            --charcoal: #1E1E1E;
            --light-gray: #F8F9FA;
            --text-muted: #A0A0A0;
            --white: #FFFFFF;
            --gold-gradient: linear-gradient(135deg, #D4AF37 0%, #B4941F 100%);
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background: linear-gradient(135deg, #1a1a1a 0%, #0a0a0a 100%);
            min-height: 100vh;
            color: var(--white);
        }

        /* Navbar Styling */
        .navbar {
            background: rgba(26, 26, 26, 0.95);
            backdrop-filter: blur(10px);
            padding: 1rem 2rem;
            box-shadow: 0 4px 30px rgba(0, 0, 0, 0.5);
            border-bottom: 1px solid rgba(212, 175, 55, 0.1);
        }

        .navbar-brand {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--white) !important;
            letter-spacing: 1px;
        }

        .navbar-brand i {
            color: var(--gold);
            margin-right: 10px;
        }

        .nav-link {
            color: rgba(255, 255, 255, 0.8) !important;
            font-weight: 500;
            padding: 0.5rem 1rem !important;
            margin: 0 0.2rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            color: var(--gold) !important;
            background: rgba(212, 175, 55, 0.1);
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: var(--gold);
            color: var(--deep-charcoal) !important;
        }

        .cart-badge {
            background: var(--gold);
            color: var(--deep-charcoal);
            font-weight: 700;
            padding: 0.25rem 0.6rem;
            border-radius: 50px;
            margin-left: 5px;
            font-size: 0.85rem;
        }

        /* Hero Section */
        .cart-hero {
            background: linear-gradient(rgba(0, 0, 0, 0.8), rgba(0, 0, 0, 0.8)), url('https://images.unsplash.com/photo-1551632436-cbf8dd35adfa?ixlib=rb-1.2.1&auto=format&fit=crop&w=1350&q=80');
            background-size: cover;
            background-position: center;
            padding: 4rem 2rem;
            border-radius: 20px;
            margin: 2rem 0;
            color: white;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(212, 175, 55, 0.2);
        }

        .cart-hero h1 {
            font-family: 'Playfair Display', serif;
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.8);
            color: var(--gold);
        }

        .cart-hero p {
            font-size: 1.2rem;
            opacity: 0.9;
            max-width: 600px;
            margin: 0 auto;
        }

        /* Cart Container */
        .cart-container {
            background: var(--charcoal);
            border-radius: 20px;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.4);
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        /* Cart Items */
        .cart-item {
            display: flex;
            align-items: center;
            padding: 1.5rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
        }

        .cart-item:hover {
            background: rgba(212, 175, 55, 0.05);
            transform: translateX(5px);
            border-radius: 12px;
        }

        .cart-item:last-child {
            border-bottom: none;
        }

        .item-image {
            width: 80px;
            height: 80px;
            border-radius: 12px;
            margin-right: 1.5rem;
            overflow: hidden;
            flex-shrink: 0;
            border: 1px solid rgba(212, 175, 55, 0.3);
            background: rgba(212, 175, 55, 0.08);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.35s ease;
        }

        .cart-item:hover .item-image {
            border-color: rgba(212, 175, 55, 0.7);
            box-shadow: 0 0 12px rgba(212, 175, 55, 0.25);
        }

        .cart-item:hover .item-image img {
            transform: scale(1.08);
        }

        .item-details {
            flex: 2;
        }

        .item-details h4 {
            font-family: 'Playfair Display', serif;
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--white);
            margin-bottom: 0.3rem;
        }

        .item-category {
            color: var(--text-muted);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .item-price-info {
            flex: 1;
            text-align: right;
            margin-right: 2rem;
        }

        .item-unit-price {
            color: var(--text-muted);
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }

        .item-unit-price span {
            color: rgba(255, 255, 255, 0.9);
            font-weight: 500;
            font-size: 1rem;
        }

        .item-subtotal {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--gold);
        }

        .quantity-control {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-right: 2rem;
        }

        .quantity-input {
            width: 70px;
            text-align: center;
            background: rgba(0, 0, 0, 0.3);
            color: var(--white);
            border: 1px solid rgba(212, 175, 55, 0.5);
            border-radius: 8px;
            padding: 0.4rem;
            font-weight: 600;
        }

        .quantity-input:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 0 2px rgba(212, 175, 55, 0.2);
        }

        .btn-update {
            background: none;
            border: none;
            color: var(--gold);
            font-size: 1.2rem;
            padding: 0.3rem 0.8rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-update:hover {
            background: rgba(212, 175, 55, 0.1);
            transform: scale(1.1);
        }

        .btn-remove {
            background: none;
            border: none;
            color: #ff4757;
            font-size: 1.2rem;
            padding: 0.3rem 0.8rem;
            border-radius: 8px;
            transition: all 0.3s ease;
        }

        .btn-remove:hover {
            background: rgba(255, 71, 87, 0.1);
            transform: scale(1.1);
        }

        /* Cart Summary */
        .cart-summary {
            background: #252525;
            border-radius: 16px;
            padding: 2rem;
            margin-top: 2rem;
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .summary-title {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--gold);
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid rgba(212, 175, 55, 0.3);
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1rem;
            font-size: 1.1rem;
            color: #17445b;
        }

        .summary-row.total {
            font-size: 1.5rem;
            font-weight: 700;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: #17445b;
        }

        .summary-row.total .amount {
            color: #eb684f;
        }

        /* Buttons */
        .btn-gold {
            background: var(--gold-gradient);
            border: none;
            color: var(--deep-charcoal);
            padding: 1rem 2rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1.1rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.2);
            width: 100%;
        }

        .btn-gold:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(212, 175, 55, 0.4);
            color: var(--deep-charcoal);
        }

        .btn-gold:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-outline-gold {
            background: transparent;
            border: 1px solid var(--gold);
            color: var(--gold);
            padding: 0.8rem 1.5rem;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-outline-gold:hover {
            background: var(--gold);
            color: var(--deep-charcoal);
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        /* Empty Cart */
        .empty-cart {
            text-align: center;
            padding: 4rem 2rem;
        }

        .empty-cart i {
            font-size: 5rem;
            color: var(--gold);
            margin-bottom: 1.5rem;
            opacity: 0.7;
        }

        .empty-cart h3 {
            font-family: 'Playfair Display', serif;
            color: var(--white);
            margin-bottom: 1rem;
        }

        .empty-cart p {
            color: var(--text-muted);
            margin-bottom: 2rem;
        }

        /* Alert Styling - Hidden by default since we use SweetAlert */
        .alert {
            display: none;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .navbar-brand {
                font-size: 1.4rem;
            }

            .cart-hero h1 {
                font-size: 2.2rem;
            }

            .cart-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .item-image {
                margin-right: 0;
            }

            .item-price-info {
                text-align: left;
                margin-right: 0;
            }

            .quantity-control {
                margin-right: 0;
            }

            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
    <link rel="stylesheet" href="customer-theme.css">
</head>

<body>
    <?php
    $customerNavPage = 'cart.php';
    $favorite_count = $fav_count;
    require 'customer-navbar.php';
    ?>

    <div class="container">
        <div class="cart-hero">
            <h1>
                <i class="fas fa-shopping-cart me-3"></i>
                Your Cart
            </h1>
            <p>Review your selections and proceed to checkout for a delightful dining experience</p>
        </div>

        <div class="cart-container">
            <?php if (!empty($cart_items)): ?>
                <?php $grand = 0; ?>
                <?php foreach ($cart_items as $it): ?>
                    <?php $sub = $it['price'] * $it['quantity'];
                    $grand += $sub; ?>
                    <div class="cart-item">
                        <div class="item-selector-wrapper" style="margin-right: 1.5rem; display: flex; align-items: center;">
                            <input type="checkbox" form="checkoutForm" name="selected_items[]" value="<?php echo $it['item_id']; ?>"
                                class="form-check-input item-selector" checked
                                data-price="<?php echo $it['price']; ?>"
                                data-qty="<?php echo $it['quantity']; ?>"
                                style="width: 1.5rem; height: 1.5rem; cursor: pointer; border-color: var(--gold); background-color: rgba(212, 175, 55, 0.1);">
                        </div>
                        <div class="item-image">
                            <?php
                            $imgSrc = !empty($it['image_url'])
                                ? '../' . htmlspecialchars($it['image_url'])
                                : '../images/placeholder.png';
                            ?>
                            <img src="<?php echo $imgSrc; ?>"
                                alt="<?php echo htmlspecialchars($it['item_name']); ?>"
                                onerror="this.onerror=null;this.src='../images/placeholder.png'">
                        </div>

                        <div class="item-details">
                            <h4><?php echo htmlspecialchars($it['item_name']); ?></h4>
                            <span class="item-category"><?php echo htmlspecialchars($it['category'] ?? 'Menu Item'); ?></span>
                        </div>

                        <div class="item-price-info">
                            <div class="item-unit-price">Unit Price: <span>₱<?php echo number_format($it['price'], 2); ?></span></div>
                            <div class="item-subtotal">₱<?php echo number_format($sub, 2); ?></div>
                        </div>

                        <form method="POST" class="quantity-control" onsubmit="return confirmUpdate(event, this)">
                            <input type="hidden" name="action" value="update_cart">
                            <input type="hidden" name="item_id" value="<?php echo $it['item_id']; ?>">
                            <input type="number"
                                name="quantity"
                                class="quantity-input"
                                value="<?php echo $it['quantity']; ?>"
                                min="0"
                                max="<?php echo max(0, (int) ($it['stock_quantity'] ?? 0)); ?>"
                                title="Available stock: <?php echo max(0, (int) ($it['stock_quantity'] ?? 0)); ?>">
                            <button type="submit" class="btn-update" title="Update quantity">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </form>

                        <form method="POST" class="remove-form" onsubmit="return confirmRemove(event, this)">
                            <input type="hidden" name="action" value="remove_item">
                            <input type="hidden" name="item_id" value="<?php echo $it['item_id']; ?>">
                            <button type="submit" class="btn-remove" title="Remove item">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>

                <div class="cart-summary">
                    <h3 class="summary-title">Order Summary</h3>

                    <div class="summary-row">
                        <span>Subtotal:</span>
                        <span class="amount" id="display-subtotal">₱<?php echo number_format($grand, 2); ?></span>
                    </div>

                    <div class="summary-row">
                        <span>Tax (10%):</span>
                        <span class="amount" id="display-tax">₱<?php echo number_format($grand * 0.10, 2); ?></span>
                    </div>

                    <div class="summary-row total">
                        <span>Total:</span>
                        <span class="amount" id="display-total">₱<?php echo number_format($grand * 1.10, 2); ?></span>
                    </div>

                    <form id="checkoutForm" method="POST" action="cart.php">
                        <input type="hidden" name="action" value="prepare_checkout">
                        <button type="submit" class="btn-gold d-block w-100" id="btn-proceed-checkout">
                            <i class="fas fa-lock me-2"></i>Proceed to Checkout (<span id="checkout-count"><?php echo count($cart_items); ?></span> items)
                        </button>
                    </form>

                    <div class="action-buttons">
                        <a href="menu.php" class="btn-outline-gold text-decoration-none text-center flex-grow-1">
                            <i class="fas fa-utensils me-2"></i>Continue Shopping
                        </a>

                        <form method="POST" class="clear-cart-form flex-grow-1" onsubmit="return confirmClearCart(event, this)">
                            <input type="hidden" name="action" value="clear_cart">
                            <button type="submit" class="btn-outline-gold w-100 h-100">
                                <i class="fas fa-trash-alt me-2"></i>Clear Cart
                            </button>
                        </form>
                    </div>
                </div>

            <?php else: ?>
                <div class="empty-cart">
                    <i class="fas fa-shopping-cart"></i>
                    <h3>Your Cart is Empty</h3>
                    <p>Looks like you haven't added any items to your cart yet.</p>
                    <p class="mb-4">Explore our delicious menu and discover your new favorite dish!</p>
                    <a href="menu.php" class="btn-gold text-decoration-none" style="display: inline-block; width: auto; padding: 1rem 3rem;">
                        <i class="fas fa-utensils me-2"></i>Browse Menu
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Global SweetAlert configuration for Dark Theme
        const swalDarkConfig = {
            background: '#1E1E1E',
            color: '#FFFFFF',
            confirmButtonColor: '#D4AF37',
            cancelButtonColor: '#ff4757',
        };

        // Show success message if exists
        <?php if ($success_msg): ?>
            Swal.fire({
                ...swalDarkConfig,
                icon: 'success',
                title: 'Success!',
                text: <?php echo json_encode($success_msg); ?>,

                showConfirmButton: true,
                iconColor: '#D4AF37'
            });
        <?php endif; ?>

        <?php if ($error): ?>
            Swal.fire({
                ...swalDarkConfig,
                icon: 'error',
                title: 'Oops...',
                text: <?php echo json_encode($error); ?>,
                iconColor: '#ff4757'
            });
        <?php endif; ?>

        // Confirm update quantity
        function confirmUpdate(event, form) {
            event.preventDefault();
            const quantityInput = form.querySelector('.quantity-input');
            const quantity = parseInt(quantityInput.value, 10);
            const maxStock = parseInt(quantityInput.getAttribute('max') || '0', 10);
            const itemName = form.closest('.cart-item').querySelector('h4').textContent;

            if (Number.isNaN(quantity) || quantity < 0) {
                quantityInput.value = 0;
                quantityInput.focus();
                return false;
            }

            if (quantity > maxStock) {
                quantityInput.value = maxStock;
                Swal.fire({
                    ...swalDarkConfig,
                    icon: 'warning',
                    title: 'Stock Limit Reached',
                    text: maxStock > 0 ?
                        `Only ${maxStock} item(s) of ${itemName} are currently available.` :
                        `${itemName} is currently out of stock.`,
                    iconColor: '#D4AF37'
                }).then(() => {
                    quantityInput.focus();
                });
                return false;
            }

            if (quantity == 0) {
                Swal.fire({
                    ...swalDarkConfig,
                    title: 'Remove Item?',
                    text: `Are you sure you want to remove ${itemName} from your cart?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, remove it',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            } else {
                Swal.fire({
                    ...swalDarkConfig,
                    title: 'Update Quantity',
                    text: `Update ${itemName} quantity to ${quantity}?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, update',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            }
            return false;
        }

        // Confirm remove item
        function confirmRemove(event, form) {
            event.preventDefault();
            const itemName = form.closest('.cart-item').querySelector('h4').textContent;

            Swal.fire({
                ...swalDarkConfig,
                title: 'Remove Item',
                text: `Are you sure you want to remove ${itemName} from your cart?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, remove it',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
            return false;
        }

        // Confirm clear cart
        function confirmClearCart(event, form) {
            event.preventDefault();

            Swal.fire({
                ...swalDarkConfig,
                title: 'Clear Cart',
                text: 'Are you sure you want to remove all items from your cart?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, clear cart',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
            return false;
        }

        document.addEventListener('DOMContentLoaded', function() {
            const checkoutForm = document.getElementById('checkoutForm');
            if (!checkoutForm || checkoutForm._confirmBound) return;
            checkoutForm._confirmBound = true;

            checkoutForm.addEventListener('submit', function(event) {
                event.preventDefault();

                const selectedItems = Array.from(document.querySelectorAll('.item-selector:checked'));
                if (selectedItems.length === 0) {
                    if (typeof Swal === 'undefined') {
                        alert('Please select at least one item to checkout.');
                    } else {
                        Swal.fire({
                            ...swalDarkConfig,
                            icon: 'warning',
                            title: 'No Items Selected',
                            text: 'Please select at least one item to checkout.',
                            iconColor: '#D4AF37'
                        });
                    }
                    return;
                }

                const totalText = document.getElementById('display-total')?.textContent.trim() || 'your selected total';
                const itemLabel = selectedItems.length === 1 ? 'item' : 'items';

                if (typeof Swal === 'undefined') {
                    if (confirm(`Proceed to checkout with ${selectedItems.length} ${itemLabel} for ${totalText}?`)) {
                        checkoutForm.submit();
                    }
                    return;
                }

                Swal.fire({
                    ...swalDarkConfig,
                    title: 'Proceed to Checkout?',
                    html: `
                        <div class="text-start px-2">
                            <p class="mb-2">Selected: <strong>${selectedItems.length} ${itemLabel}</strong></p>
                            <p class="mb-0">Estimated total: <strong style="color:#D4AF37;">${totalText}</strong></p>
                        </div>
                    `,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, continue',
                    cancelButtonText: 'Review Cart',
                    iconColor: '#D4AF37'
                }).then((result) => {
                    if (result.isConfirmed) {
                        checkoutForm.submit();
                    }
                });
            });
        });

        // Quantity input validation
        function clampCartQuantityInput(input) {
            const maxStock = parseInt(input.getAttribute('max') || '0', 10);
            const quantity = parseInt(input.value, 10);

            if (input.value === '') return;
            if (Number.isNaN(quantity) || quantity < 0) {
                input.value = 0;
                return;
            }
            if (!Number.isNaN(maxStock) && quantity > maxStock) {
                input.value = maxStock;
            }
        }

        document.querySelectorAll('.quantity-input').forEach(input => {
            input.addEventListener('input', function() {
                clampCartQuantityInput(this);
            });
            input.addEventListener('change', function() {
                clampCartQuantityInput(this);
            });
        });

        // Selective Checkout Calculation
        document.addEventListener('DOMContentLoaded', function() {
            const selectors = document.querySelectorAll('.item-selector');
            const subtotalEl = document.getElementById('display-subtotal');
            const taxEl = document.getElementById('display-tax');
            const totalEl = document.getElementById('display-total');
            const countEl = document.getElementById('checkout-count');
            const btnCheckout = document.getElementById('btn-proceed-checkout');

            if (!subtotalEl) return;

            function recalculateTotals() {
                let subtotal = 0;
                let count = 0;

                selectors.forEach(cb => {
                    if (cb.checked) {
                        const price = parseFloat(cb.dataset.price);
                        const qty = parseInt(cb.dataset.qty);
                        subtotal += (price * qty);
                        count++;
                    }
                });

                const tax = subtotal * 0.10;
                const total = subtotal + tax;

                subtotalEl.textContent = '₱' + subtotal.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                taxEl.textContent = '₱' + tax.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                totalEl.textContent = '₱' + total.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });

                if (countEl) countEl.textContent = count;

                if (count === 0) {
                    btnCheckout.disabled = true;
                    btnCheckout.innerHTML = '<i class="fas fa-lock me-2"></i>Select items to checkout';
                } else {
                    btnCheckout.disabled = false;
                    btnCheckout.innerHTML = `<i class="fas fa-lock me-2"></i>Proceed to Checkout (<span id="checkout-count">${count}</span> items)`;
                }
            }

            selectors.forEach(cb => {
                cb.addEventListener('change', recalculateTotals);
            });

            // Initial calculation
            recalculateTotals();
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

        // Selective Checkout Calculation
        document.addEventListener('DOMContentLoaded', function() {
            const selectors = document.querySelectorAll('.item-selector');
            const subtotalEl = document.getElementById('display-subtotal');
            const taxEl = document.getElementById('display-tax');
            const totalEl = document.getElementById('display-total');
            const countEl = document.getElementById('checkout-count');
            const btnCheckout = document.getElementById('btn-proceed-checkout');

            if (!subtotalEl) return;

            function recalculateTotals() {
                let subtotal = 0;
                let count = 0;

                selectors.forEach(cb => {
                    if (cb.checked) {
                        const price = parseFloat(cb.dataset.price);
                        const qty = parseInt(cb.dataset.qty);
                        subtotal += (price * qty);
                        count++;
                    }
                });

                const tax = subtotal * 0.10;
                const total = subtotal + tax;

                subtotalEl.textContent = '₱' + subtotal.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                taxEl.textContent = '₱' + tax.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                totalEl.textContent = '₱' + total.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });

                if (countEl) countEl.textContent = count;

                if (count === 0) {
                    btnCheckout.disabled = true;
                    btnCheckout.innerHTML = '<i class="fas fa-lock me-2"></i>Select items to checkout';
                } else {
                    btnCheckout.disabled = false;
                    btnCheckout.innerHTML = `<i class="fas fa-lock me-2"></i>Proceed to Checkout (<span id="checkout-count">${count}</span> items)`;
                }
            }

            selectors.forEach(cb => {
                cb.addEventListener('change', recalculateTotals);
            });

            // Initial calculation
            recalculateTotals();
        });
    </script>

    <script>
        // Selective Checkout Calculation
        document.addEventListener('DOMContentLoaded', function() {
            const selectors = document.querySelectorAll('.item-selector');
            const subtotalEl = document.getElementById('display-subtotal');
            const taxEl = document.getElementById('display-tax');
            const totalEl = document.getElementById('display-total');
            const countEl = document.getElementById('checkout-count');
            const btnCheckout = document.getElementById('btn-proceed-checkout');

            if (!subtotalEl) return;

            function recalculateTotals() {
                let subtotal = 0;
                let count = 0;

                selectors.forEach(cb => {
                    if (cb.checked) {
                        const price = parseFloat(cb.dataset.price);
                        const qty = parseInt(cb.dataset.qty);
                        subtotal += (price * qty);
                        count++;
                    }
                });

                const tax = subtotal * 0.10;
                const total = subtotal + tax;

                subtotalEl.textContent = '₱' + subtotal.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                taxEl.textContent = '₱' + tax.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });
                totalEl.textContent = '₱' + total.toLocaleString('en-US', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                });

                if (countEl) countEl.textContent = count;

                if (count === 0) {
                    btnCheckout.disabled = true;
                    btnCheckout.innerHTML = '<i class="fas fa-lock me-2"></i>Select items to checkout';
                } else {
                    btnCheckout.disabled = false;
                    btnCheckout.innerHTML = `<i class="fas fa-lock me-2"></i>Proceed to Checkout (<span id="checkout-count">${count}</span> items)`;
                }
            }

            selectors.forEach(cb => {
                cb.addEventListener('change', recalculateTotals);
            });

            // Initial calculation
            recalculateTotals();
        });
    </script>
</body>

</html>