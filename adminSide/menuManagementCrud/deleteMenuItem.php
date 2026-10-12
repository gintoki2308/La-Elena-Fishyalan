<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';
require_once '../../config/menu_image_helper.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

// Get item ID
$item_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($item_id > 0) {
    try {
        $item = deleteMenuItemWithImage($db, $item_id);

        if (!$item) {
            header('Location: viewMenuItems.php?error=not_found');
            exit;
        }

        writeAuditLog($db, 'DELETE_MENU_ITEM', 'menu_items', $item_id, [
            'summary' => 'Deleted menu item',
            'item_name' => $item['item_name'],
            'category' => $item['category'],
            'price' => $item['price'],
            'is_available' => $item['is_available'],
            'image_url' => $item['image_url'] ?? null,
        ]);

        header('Location: viewMenuItems.php?success=deleted');
        exit;
    } catch (Exception $e) {
        header('Location: viewMenuItems.php?error=delete_failed');
        exit;
    }
}

header('Location: viewMenuItems.php?error=not_found');
exit;
?>

