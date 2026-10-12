<?php
require_once '../config/config.php';
require_once '../config/Database.php';
require_once 'stock_availability.php';

header('Content-Type: application/json');

// Security check: Must be logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'redirect' => BASE_URL . 'auth/login.php']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item_id'])) {
    $database = new Database();
    $db = $database->connect();

    // Ensure the cart session array exists
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
    
    $itemId = intval($_POST['item_id']);
    // If quantity isn't sent, default to 1. Also ensure it's never less than 1.
    $qty = max(1, intval($_POST['quantity'] ?? 1));

    if ($itemId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid menu item selected.']);
        exit;
    }

    $itemStmt = $db->prepare("SELECT item_name, stock_quantity, is_available FROM menu_items WHERE item_id = :iid LIMIT 1");
    $itemStmt->execute([':iid' => $itemId]);
    $item = $itemStmt->fetch(PDO::FETCH_ASSOC);

    $currentCartQty = max(0, (int) ($_SESSION['cart'][$itemId] ?? 0));

    if (!$item || empty($item['is_available'])) {
        unset($_SESSION['cart'][$itemId]);
        echo json_encode([
            'success' => false,
            'error' => 'This item is no longer available.',
            'cart_count' => array_sum($_SESSION['cart']),
            'item_cart_qty' => 0,
            'available_stock' => 0,
            'remaining_stock' => 0
        ]);
        exit;
    }

    $availability = getOrderableStock($db, $itemId, (int)$_SESSION['user_id'], (int)($item['stock_quantity'] ?? 0));
    $availableStock = (int)$availability['available'];
    $remainingStock = max(0, $availableStock - $currentCartQty);

    if ($availableStock <= 0) {
        unset($_SESSION['cart'][$itemId]);
        echo json_encode([
            'success' => false,
            'error' => stockReservationMessage($item['item_name'], $availability),
            'cart_count' => array_sum($_SESSION['cart']),
            'item_cart_qty' => 0,
            'available_stock' => (int)($item['stock_quantity'] ?? 0),
            'remaining_stock' => 0
        ]);
        exit;
    }

    if ($remainingStock <= 0) {
        $_SESSION['cart'][$itemId] = $availableStock;
        echo json_encode([
            'success' => false,
            'error' => stockReservationMessage($item['item_name'], $availability),
            'cart_count' => array_sum($_SESSION['cart']),
            'item_cart_qty' => $availableStock,
            'available_stock' => (int)($item['stock_quantity'] ?? 0),
            'remaining_stock' => 0
        ]);
        exit;
    }

    $qtyToAdd = min($qty, $remainingStock);
    $newCartQty = $currentCartQty + $qtyToAdd;
    $_SESSION['cart'][$itemId] = $newCartQty;

    // Calculate the total number of items across the whole cart
    $cart_count = array_sum($_SESSION['cart']);

    $response = [
        'success' => true,
        'cart_count' => $cart_count,
        'item_cart_qty' => $newCartQty,
        'added_quantity' => $qtyToAdd,
        'available_stock' => (int)($item['stock_quantity'] ?? 0),
        'remaining_stock' => max(0, $availableStock - $newCartQty)
    ];

    if ($qtyToAdd < $qty) {
        $response['adjusted'] = true;
        $response['message'] = "Only {$remainingStock} more item(s) of {$item['item_name']} can be added right now. Your cart was adjusted to the available stock.";
    }

    echo json_encode($response);
    
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
}
?>
