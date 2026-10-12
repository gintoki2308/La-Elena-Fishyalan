<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';
require_once '../../config/Mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// AUTHENTICATION (Admin + Super Admin)
if (!isLoggedIn() || !canManageBackOffice()) {
    header('Location: /adminSide/admin-dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: viewUsers.php?error=Invalid request method.');
    exit;
}

$id = $_POST['id'] ?? null;
$type = $_POST['type'] ?? null;
$admin_password = $_POST['admin_password'] ?? $_POST['password'] ?? '';

$id = is_numeric($id) ? (int)$id : null;
$type = is_string($type) ? trim($type) : null;
$admin_password = is_string($admin_password) ? trim($admin_password) : '';

if ($id === null || $type === null || $type === '' || $admin_password === '') {
    header('Location: viewUsers.php?error=' . urlencode('Missing parameters or password.'));
    exit;
}

// Ensure Super Admin doesn't delete themselves
if ($type === 'admin' && $id == $_SESSION['user_id']) {
    header('Location: viewUsers.php?error=You cannot delete your own account.');
    exit;
}

// Only Super Admins can delete admin accounts.
if ($type === 'admin' && !isSuperAdmin()) {
    header('Location: viewUsers.php?error=' . urlencode('Only Super Admins can delete admin accounts.'));
    exit;
}

$database = new Database();
$db = $database->connect();

try {
    // 1. Verify Admin Password
    verifyAdminPassword($db, $_SESSION['user_id'], $admin_password);

    // 2. Fetch target name and email for notification
    $targetName = "Unknown User";
    $targetEmail = "";
    if ($type === 'admin') {
        $stmtName = $db->prepare("SELECT first_name, last_name, email FROM admins WHERE admin_id = :id");
    } elseif ($type === 'staff') {
        $stmtName = $db->prepare("SELECT first_name, last_name, email FROM staffs WHERE staff_id = :id");
    } elseif ($type === 'customer') {
        $stmtName = $db->prepare("SELECT first_name, last_name, email FROM customer WHERE member_id = :id");
    }
    if (isset($stmtName)) {
        $stmtName->execute([':id' => $id]);
        $row = $stmtName->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $targetName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $targetEmail = $row['email'] ?? '';
        }
    }

    // 3. Delete from DB
    if ($type === 'admin') {
        enforceImmutableSuperadminPolicy($db, $id, 'delete');
        $stmt = $db->prepare("DELETE FROM admins WHERE admin_id = :id");
        $table = 'admins';
    } elseif ($type === 'staff') {
        $stmt = $db->prepare("DELETE FROM staffs WHERE staff_id = :id");
        $table = 'staffs';
    } elseif ($type === 'customer') {
        // Block deletion if customer has any active/pending orders
        // Terminal statuses:
        //   COD  → COMPLETED or CANCELLED
        //   Other → DELIVERED, COMPLETED, or CANCELLED
        $pendingCheck = $db->prepare("
            SELECT COUNT(*) FROM online_orders
            WHERE member_id = ?
              AND status != 'CANCELLED'
              AND status != 'COMPLETED'
              AND NOT (payment_method != 'COD' AND status = 'DELIVERED')
        ");
        $pendingCheck->execute([$id]);
        $pendingCount = (int)$pendingCheck->fetchColumn();

        if ($pendingCount > 0) {
            throw new Exception("Cannot delete this customer — they have {$pendingCount} active/pending order(s) that must be resolved first.");
        }

        $stmt = $db->prepare("DELETE FROM customer WHERE member_id = :id");
        $table = 'customer';
    } else {
        throw new Exception("Invalid user type.");
    }

    $stmt->execute([':id' => $id]);

    // Send notification to the user only after successful deletion
    if (!empty($targetEmail)) {
        $subject = "Account Deletion - La Elena Fishyalan";
        $body = "<h2>Hello " . htmlspecialchars($targetName) . ",</h2>";
        $body .= "<p>Your La Elena Fishyalan account has been deleted by an administrator. If you believe this is an error, please contact support.</p>";
        $body .= "<p>Regards,<br>La Elena Fishyalan Team</p>";

        sendMail($targetEmail, $targetName, $subject, $body);
    }

    // 4. Send Security Notice
    sendAdminSecurityNotice($targetName, "Deleted $type account");

    // 5. Log the action
    $details = "Deleted $type account for $targetName ({$targetEmail})";
    $audit_query = "INSERT INTO audit_logs (admin_id, action, action_details, table_affected, record_id, ip_address, timestamp) 
                    VALUES (:admin_id, 'DELETE_USER', :details, :table_affected, :record_id, :ip, NOW())";
    $audit_stmt = $db->prepare($audit_query);
    $audit_stmt->execute([
        ':admin_id' => $_SESSION['user_id'],
        ':details' => $details,
        ':table_affected' => $table,
        ':record_id' => $id,
        ':ip' => $_SERVER['REMOTE_ADDR']
    ]);

    header("Location: viewUsers.php?success=" . urlencode(ucfirst($type) . " deleted successfully."));
    exit;
} catch (PDOException $e) {
    header("Location: viewUsers.php?error=Database Error: " . urlencode($e->getMessage()));
    exit;
} catch (Exception $e) {
    header("Location: viewUsers.php?error=" . urlencode($e->getMessage()));
    exit;
}
