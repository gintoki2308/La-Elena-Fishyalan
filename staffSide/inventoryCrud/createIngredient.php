<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (!isLoggedIn() || (!isStaff() && !isAdmin())) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

// Keep existing links and previously opened forms working with the inventory modal.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST['action'] = 'add_ingredient';
    require __DIR__ . '/viewInventory.php';
    exit;
}

header('Location: viewInventory.php?add=1');
exit;
