<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

// Get item ID
$item_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get current availability status
$query = "SELECT item_name, category, price, is_available FROM menu_items WHERE item_id = :item_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':item_id', $item_id);
$stmt->execute();
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if ($result) {
    // Toggle the status
    $new_status = $result['is_available'] ? 0 : 1;
    
    $update_query = "UPDATE menu_items SET is_available = :is_available, updated_at = NOW() WHERE item_id = :item_id";
    $update_stmt = $db->prepare($update_query);
    $update_stmt->bindParam(':is_available', $new_status);
    $update_stmt->bindParam(':item_id', $item_id);
    
    if ($update_stmt->execute()) {
        writeAuditLog($db, 'TOGGLE_MENU_ITEM_AVAILABILITY', 'menu_items', $item_id, [
            'summary' => 'Toggled menu item availability',
            'item_name' => $result['item_name'],
            'category' => $result['category'],
            'price' => $result['price'],
            'previous_is_available' => $result['is_available'],
            'new_is_available' => $new_status,
        ]);
    }
}

header('Location: viewMenuItems.php');
exit;
?>

