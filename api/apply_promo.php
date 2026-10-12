<?php
require_once '../config/config.php';
require_once '../config/Database.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Please log in to apply a promo code.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$promo_code = trim($input['promo_code'] ?? '');
$cart_total = floatval($input['cart_total'] ?? 0);

if (empty($promo_code)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a promo code.']);
    exit;
}

try {
    $database = new Database();
    $db = $database->connect();

    // The admin script uses "promotion_name" as the promo code identifier
    $stmt = $db->prepare("SELECT * FROM promotions WHERE promotion_name = :code AND is_active = 1");
    $stmt->execute([':code' => $promo_code]);
    $promo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$promo) {
        echo json_encode(['success' => false, 'message' => 'Invalid or inactive promo code.']);
        exit;
    }

    // Check dates
    $current_date = date('Y-m-d');
    if ($promo['start_date'] && $current_date < $promo['start_date']) {
        echo json_encode(['success' => false, 'message' => 'This promo code is not yet active.']);
        exit;
    }
    if ($promo['end_date'] && $current_date > $promo['end_date']) {
        echo json_encode(['success' => false, 'message' => 'This promo code has expired.']);
        exit;
    }

    // Single-use check
    if (!empty($promo['is_single_use'])) {
        $chk = $db->prepare("SELECT COUNT(*) FROM online_orders WHERE member_id = :mid AND promo_code = :code");
        $chk->execute([':mid' => $_SESSION['user_id'], ':code' => $promo['promotion_name']]);
        if ($chk->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'message' => 'You have already used this promo code.']);
            exit;
        }
    }

    // Calculate eligible total based on category restrictions
    $eligible_subtotal = 0;
    if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
        foreach ($_SESSION['cart'] as $item_id => $qty) {
            $s = $db->prepare("SELECT price, category FROM menu_items WHERE item_id = :id");
            $s->execute([':id' => $item_id]);
            $item = $s->fetch(PDO::FETCH_ASSOC);
            if ($item) {
                // Parse comma-separated applicable categories
                $allowed_categories = empty($promo['applicable_category']) ? [] : array_map('strtolower', array_map('trim', explode(',', $promo['applicable_category'])));
                
                // If applicable_category is empty, it applies to all. Else, check if item's category is in the array.
                if (empty($allowed_categories) || in_array(strtolower(trim($item['category'])), $allowed_categories)) {
                    $eligible_subtotal += ($item['price'] * $qty);
                }
            }
        }
    }

    // Include tax to match frontend's logic where discount applies to final total
    $eligible_total = $eligible_subtotal * 1.10;

    if ($eligible_total <= 0) {
        $catMsg = !empty($promo['applicable_category']) ? " applies only to {$promo['applicable_category']} items." : " cannot be applied to your current cart.";
        echo json_encode(['success' => false, 'message' => 'This promo code' . $catMsg]);
        exit;
    }

    // Check minimum order value if any (checked against total cart, not just eligible portion)
    if (isset($promo['minimum_order_value']) && $promo['minimum_order_value'] > 0 && $cart_total < $promo['minimum_order_value']) {
        echo json_encode(['success' => false, 'message' => 'Minimum order value of ₱' . number_format($promo['minimum_order_value'], 2) . ' required.']);
        exit;
    }

    // Compute the exact discount to apply
    $calculated_discount = 0;
    if ($promo['discount_type'] === 'PERCENTAGE') {
        $calculated_discount = $eligible_total * ($promo['discount_value'] / 100);
    } else {
        $calculated_discount = min(floatval($promo['discount_value']), $eligible_total);
    }

    // Success
    echo json_encode([
        'success' => true,
        'promo_code' => $promo['promotion_name'],
        'discount_type' => $promo['discount_type'],
        'discount_value' => floatval($promo['discount_value']),
        'calculated_discount' => floatval($calculated_discount),
        'message' => 'Promo code applied successfully!'
    ]);

} catch (Exception $e) {
    error_log("Promo Code API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error while validating promo code.']);
}
