<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (!isLoggedIn() || (!isStaff() && !isAdmin())) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

$item_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($item_id > 0) {
    // FIXED: Menu_Items -> menu_items
    $toggle_query = "UPDATE menu_items SET is_available = NOT is_available, updated_at = NOW() WHERE item_id = :item_id";
    $toggle_stmt = $db->prepare($toggle_query);
    $toggle_stmt->bindParam(':item_id', $item_id);
    if ($toggle_stmt->execute()) {
        // Log action
        // FIXED: Audit_Logs -> audit_logs AND 'Menu_Items' -> 'menu_items'
        $audit_query = "INSERT INTO audit_logs (staff_id, action, table_affected, record_id, ip_address, timestamp) 
                        VALUES (:staff_id, 'TOGGLE_MENU_ITEM', 'menu_items', :item_id, :ip, NOW())";
        $audit_stmt = $db->prepare($audit_query);
        $audit_stmt->bindParam(':staff_id', $_SESSION['user_id']);
        $audit_stmt->bindParam(':item_id', $item_id);
        $audit_stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
        $audit_stmt->execute();
        
        header('Location: viewMenuItems.php');
        exit;
    }
}

header('Location: viewMenuItems.php?error=toggle_failed');
exit;
?>
