<?php
session_start();
require_once __DIR__ . '/../config/config.php';

// Log the logout if session exists
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/../config/Database.php';
    try {
        $database = new Database();
        $db = $database->connect();
        
        // Determine correct table for actor
        $tableAffected = 'customer';
        if (isset($_SESSION['user_role'])) {
            if ($_SESSION['user_role'] === 'admin') $tableAffected = 'admins';
            elseif ($_SESSION['user_role'] === 'staff') $tableAffected = 'staffs';
        }

        $logoutDetails = [
            'summary' => 'User logout',
            'role' => $_SESSION['user_role'] ?? null,
            'email' => $_SESSION['email'] ?? null,
        ];

        $name = trim((string) ($_SESSION['first_name'] ?? '') . ' ' . (string) ($_SESSION['last_name'] ?? ''));
        if ($name !== '') {
            $logoutDetails['name'] = $name;
        }

        writeAuditLog(
            $db,
            'LOGOUT',
            $tableAffected,
            $_SESSION['user_id'],
            $logoutDetails,
            [
                'actor_role' => $_SESSION['user_role'] ?? null,
                'actor_user_id' => $_SESSION['user_id'] ?? null,
            ]
        );

        // Remove session from Staff_Sessions if staff
        if (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'staff') {
            $sessQuery = "DELETE FROM staff_sessions WHERE php_session_id = :psid AND staff_id = :sid";
            $sessStmt = $db->prepare($sessQuery);
            $sessStmt->execute([':psid' => session_id(), ':sid' => $_SESSION['user_id']]);
        }
        
    } catch (Exception $e) {
        // Log failure won't prevent logout
    }
}

// Destroy session
session_unset();
session_destroy();

// Clear session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to login with the existing success notice.
header('Location: ' . app_url('auth/login.php?logout=success'));
exit;
?>


