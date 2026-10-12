<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$staffId = $_GET['id'] ?? '';

if (empty($staffId)) {
    header('Location: ../userManagement/viewUsers.php#staff');
    exit;
}

// Don't allow an admin to delete their own staff record if they happened to be logged in as one
if ($staffId == $_SESSION['user_id'] && $_SESSION['user_role'] === 'staff') {
    header('Location: ../userManagement/viewUsers.php?error=self_delete#staff');
    exit;
}

$database = new Database();
$db = $database->connect();

try {
    $db->beginTransaction();

    // Log the action first before deleting (because audit log may need to refer to something or just text)
    $audit_query = "INSERT INTO audit_logs (admin_id, action, table_affected, record_id, ip_address, timestamp) 
                    VALUES (:admin_id, 'DELETE_STAFF', 'staffs', :record_id, :ip, NOW())";
    $audit_stmt = $db->prepare($audit_query);
    $audit_stmt->execute([
        ':admin_id' => $_SESSION['user_id'],
        ':record_id' => $staffId,
        ':ip' => $_SERVER['REMOTE_ADDR']
    ]);

    $deleteQuery = "DELETE FROM staffs WHERE staff_id = :id";
    $deleteStmt = $db->prepare($deleteQuery);
    $deleteStmt->bindParam(':id', $staffId);
    $deleteStmt->execute();

    $db->commit();
    header('Location: ../userManagement/viewUsers.php?success=' . urlencode('Staff deleted successfully.') . '#staff');
} catch (Exception $e) {
    $db->rollBack();
    header('Location: ../userManagement/viewUsers.php?error=delete_failed#staff');
}
exit;

