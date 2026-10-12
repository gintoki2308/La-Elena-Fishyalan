<?php
require_once __DIR__ . '/../config/config.php';

// Function to check if user is logged in
function isUserLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

// Function to get user role
function getUserRole() {
    return $_SESSION['user_role'] ?? null;
}

// Function to get user ID
function getUserId() {
    return $_SESSION['user_id'] ?? null;
}

// Function to get user email
function getUserEmail() {
    return $_SESSION['email'] ?? null;
}

// Function to redirect to login if not authenticated
function requireLogin() {
    if (!isUserLoggedIn()) {
        $redirectTarget = $_SERVER['REQUEST_URI'] ?? app_url();
        header('Location: ' . app_url('auth/login.php') . '?redirect=' . urlencode($redirectTarget));
        exit;
    }
}

// Function to get login status as JSON (for AJAX)
function getLoginStatus() {
    return json_encode([
        'is_logged_in' => isUserLoggedIn(),
        'user_role' => getUserRole(),
        'user_id' => getUserId(),
        'email' => getUserEmail(),
        'first_name' => $_SESSION['first_name'] ?? null,
        'last_name' => $_SESSION['last_name'] ?? null,
        'app_base_path' => APP_BASE_PATH,
        'base_url' => BASE_URL
    ]);
}
?>
