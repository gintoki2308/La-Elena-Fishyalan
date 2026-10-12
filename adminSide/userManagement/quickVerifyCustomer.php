<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Authentication check
if (!isLoggedIn() || !canManageBackOffice()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$memberId = (int)($_POST['member_id'] ?? 0);
if ($memberId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid member ID.']);
    exit;
}

try {
    $database = new Database();
    $db = $database->connect();

    // Check if is_verified column exists
    $hasVerifiedCol = dbTableHasColumn($db, 'customer', 'is_verified');

    // Check if member exists and is unverified
    $selectCols = "member_id, first_name, last_name, account_status";
    if ($hasVerifiedCol) {
        $selectCols .= ", is_verified";
    }
    $checkStmt = $db->prepare("SELECT {$selectCols} FROM customer WHERE member_id = :id");
    $checkStmt->execute([':id' => $memberId]);
    $member = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$member) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Customer not found.']);
        exit;
    }

    $isAlreadyActive = strtoupper($member['account_status']) === 'ACTIVE';
    $isAlreadyVerified = $hasVerifiedCol ? ((int)($member['is_verified'] ?? 0) === 1) : $isAlreadyActive;

    if ($isAlreadyVerified && $isAlreadyActive) {
        echo json_encode(['success' => false, 'message' => 'This account is already verified and active.']);
        exit;
    }

    // Verify and activate
    if ($hasVerifiedCol) {
        $stmt = $db->prepare("UPDATE customer SET is_verified = 1, account_status = 'ACTIVE' WHERE member_id = :id");
    } else {
        $stmt = $db->prepare("UPDATE customer SET account_status = 'ACTIVE' WHERE member_id = :id");
    }
    $stmt->execute([':id' => $memberId]);

    // Write audit log
    $customerName = trim($member['first_name'] . ' ' . $member['last_name']);
    writeAuditLog($db, 'MANUAL_VERIFY_CUSTOMER', 'customer', $memberId, [
        'summary' => "Admin manually verified customer account (bypassed OTP)",
        'customer_name' => $customerName,
        'previous_status' => $member['account_status'],
    ]);

    echo json_encode([
        'success' => true,
        'message' => $customerName . ' has been verified and activated successfully.'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}
