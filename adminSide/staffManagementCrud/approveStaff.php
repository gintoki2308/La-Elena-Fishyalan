<?php
require_once __DIR__ . '/../../config/config.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

// The retired application approval endpoint must not change staff accounts.
header('Location: ../userManagement/viewUsers.php#staff', true, 303);
exit;
