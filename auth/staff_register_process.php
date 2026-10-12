<?php
// Reject legacy signup submissions before loading services or accepting uploads.
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'success' => false,
    'message' => 'Staff registration is no longer available. Please contact an administrator for a staff account.',
]);
exit;
