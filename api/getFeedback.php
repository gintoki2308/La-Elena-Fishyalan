<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../config/feedback_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
session_write_close();

try {
    $db = (new Database())->connect();
    if (!$db) throw new RuntimeException('Feedback database unavailable.');
    echo json_encode(['success' => true, 'data' => publicCustomerFeedback($db)], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('Public feedback: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Guest reviews are temporarily unavailable. Please try again.']);
}
