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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../userManagement/viewUsers.php#staff');
    exit;
}

$staffId = $_POST['id'] ?? '';
$action = $_POST['action'] ?? '';
$admin_password = $_POST['admin_password'] ?? '';

if (empty($staffId) || !in_array($action, ['enable', 'disable']) || empty($admin_password)) {
    header('Location: ../userManagement/viewUsers.php?error=Missing parameters or password#staff');
    exit;
}

$database = new Database();
$db = $database->connect();

$newStatus = ($action === 'enable') ? 'ACTIVE' : 'DISABLED';

try {
    // 1. Verify Admin Password
    verifyAdminPassword($db, $_SESSION['user_id'], $admin_password);

    // Fetch staff details for notification
    $stmtName = $db->prepare("SELECT first_name, last_name, email FROM staffs WHERE staff_id = :id");
    $stmtName->execute([':id' => $staffId]);
    $row = $stmtName->fetch(PDO::FETCH_ASSOC);
    $targetName = $row ? trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) : "Unknown Staff";
    $targetEmail = $row ? $row['email'] : "";

    $db->beginTransaction();

    $updateQuery = "UPDATE staffs SET account_status = :status WHERE staff_id = :id";;
    $updateStmt = $db->prepare($updateQuery);
    $updateStmt->bindParam(':status', $newStatus);
    $updateStmt->bindParam(':id', $staffId);
    $updateStmt->execute();

    $logAction = ($action === 'enable') ? 'ENABLE_STAFF' : 'DISABLE_STAFF';
    $actionDetails = "Staff status set to {$newStatus}";
    $audit_query = "INSERT INTO audit_logs (admin_id, action, action_details, table_affected, record_id, ip_address, timestamp) 
                    VALUES (:admin_id, :log_action, :action_details, 'staffs', :record_id, :ip, NOW())";
    $audit_stmt = $db->prepare($audit_query);
    $audit_stmt->execute([
        ':admin_id' => $_SESSION['user_id'],
        ':log_action' => $logAction,
        ':action_details' => $actionDetails,
        ':record_id' => $staffId,
        ':ip' => $_SERVER['REMOTE_ADDR']
    ]);

    $db->commit();

    // Send Security Notice
    sendAdminSecurityNotice($targetName, "Staff status set to " . ucfirst(strtolower($newStatus)));

    // Send User Notification
    if ($targetEmail) {
        $message = "Your staff account status has been updated to: " . ucfirst(strtolower($newStatus)) . ".";
        if ($newStatus === 'DISABLED') {
            $message .= " You will no longer be able to access the system. Please contact an administrator if you believe this is an error.";
        } else {
            $message .= " You can now log in and access the system.";
        }
        sendUserNotification($targetEmail, $targetName, [], $message);
    }

    header('Location: ../userManagement/viewUsers.php?success=' . $action . '#staff');
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    header('Location: ../userManagement/viewUsers.php?error=' . urlencode($e->getMessage()) . '#staff');
}
exit;

