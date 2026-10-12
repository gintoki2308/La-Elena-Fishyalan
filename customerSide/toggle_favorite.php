<?php
require_once '../config/config.php';
require_once '../config/Database.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'redirect' => BASE_URL . 'auth/login.php']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['item_id'])) {
    $item_id = intval($_POST['item_id']);
    $user_id = $_SESSION['user_id'];
    
    $database = new Database();
    $db = $database->connect();
    
    try {
        // 1. Toggle the favorite in the database
        $checkStmt = $db->prepare("SELECT favorite_id FROM favorites WHERE member_id = :mid AND item_id = :iid");
        $checkStmt->execute([':mid' => $user_id, ':iid' => $item_id]);
        
        $action = '';
        if ($checkStmt->rowCount() > 0) {
            $delStmt = $db->prepare("DELETE FROM favorites WHERE member_id = :mid AND item_id = :iid");
            $delStmt->execute([':mid' => $user_id, ':iid' => $item_id]);
            $action = 'removed';
        } else {
            $insStmt = $db->prepare("INSERT INTO favorites (member_id, item_id) VALUES (:mid, :iid)");
            $insStmt->execute([':mid' => $user_id, ':iid' => $item_id]);
            $action = 'added';
        }
        
        // 2. Count the total favorites for this specific DISH (updates the button)
        $countStmt = $db->prepare("SELECT COUNT(*) as total FROM favorites WHERE item_id = :iid");
        $countStmt->execute([':iid' => $item_id]);
        $new_count = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // 3. Count the total favorites for this specific USER (updates the Navbar Badge)
        $userTotalStmt = $db->prepare("SELECT COUNT(*) as user_total FROM favorites WHERE member_id = :mid");
        $userTotalStmt->execute([':mid' => $user_id]);
        $user_total = $userTotalStmt->fetch(PDO::FETCH_ASSOC)['user_total'];
        
        // 4. Send it all back to the JavaScript
        echo json_encode([
            'success' => true,
            'action' => $action,
            'new_count' => $new_count,
            'user_total' => $user_total
        ]);
        
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error occurred.']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
}
?>