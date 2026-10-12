<?php
require_once __DIR__ . '/../../config/config.php';
if (!isLoggedIn() || !isAdmin()) { header('Location: ' . app_url('auth/login.php')); exit; }
require __DIR__ . '/../../config/payroll_admin_page.php';
