<?php
require_once 'session-check.php';

header('Content-Type: application/json');

// Return user login status
echo getLoginStatus();
?>
