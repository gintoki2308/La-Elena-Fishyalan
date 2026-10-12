<?php
require_once __DIR__ . '/../../config/config.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

// Staff applications have been retired; keep existing bookmarks usable.
header('Location: ../admin-dashboard.php', true, 303);
exit;
