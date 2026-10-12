<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';
require_once '../../config/menu_image_helper.php';

// Only staff or admin may access
if (!isLoggedIn() || (!isStaff() && !isAdmin())) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

$item_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($item_id > 0) {
    try {
        $item = deleteMenuItemWithImage($db, $item_id);
        if ($item) {
            writeAuditLog($db, 'DELETE_MENU_ITEM', 'menu_items', $item_id, [
                'summary' => 'Deleted menu item',
                'item_name' => $item['item_name'],
                'image_url' => $item['image_url'] ?? null,
            ]);

            header('Location: viewMenuItems.php?success=deleted');
            exit;
        }
    } catch (Exception $error) {
        header('Location: viewMenuItems.php?error=delete_failed');
        exit;
    }
}

header('Location: viewMenuItems.php?error=delete_failed');
exit;
?>
