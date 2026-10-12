<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/pos_helper.php';

header('Cache-Control: no-store');
$db = (new Database())->connect();
$posError = '';
$posStatus = 200;
$cashier = null;
try {
    if (!$db) throw new RuntimeException('Database unavailable.');
    if (!isLoggedIn()) {
        $posStatus = 401;
        $posError = 'Please sign in again to use the POS.';
    } elseif (!isStaff() || !($cashier = posCashier($db, (int)$_SESSION['user_id']))) {
        $posStatus = 403;
        $posError = 'The Point of Sale is available to active Cashier staff only.';
    }
} catch (Throwable $error) {
    error_log('POS access: ' . $error->getMessage());
    $posStatus = 503;
    $posError = 'The POS is temporarily unavailable. Please try again shortly.';
}
if ($posError !== '') {
    http_response_code($posStatus);
    if (basename($_SERVER['SCRIPT_NAME']) === 'api.php') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $posError]);
    } else {
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Point of Sale</title><p>'
            . htmlspecialchars($posError) . '</p><a href="../../auth/login.php">Sign in</a></html>';
    }
    exit;
}
$staffId = (int)$cashier['staff_id'];
