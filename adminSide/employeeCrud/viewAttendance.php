<?php
require_once __DIR__ . '/../../config/config.php';
if (!isLoggedIn() || !canManageBackOffice()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}
require __DIR__ . '/../../config/workforce_attendance_admin_page.php';