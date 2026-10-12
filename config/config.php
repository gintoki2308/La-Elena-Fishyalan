<?php
// ==========================================
// 1. SMART ENVIRONMENT DETECTION
// ==========================================
if (!function_exists('detectBasePath')) {
    function detectBasePath() {
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/');
        $rootFolders = ['/adminSide/', '/staffSide/', '/customerSide/', '/auth/', '/config/', '/api/', '/uploads/', '/images/'];

        foreach ($rootFolders as $folder) {
            $position = strpos($scriptName, $folder);
            if ($position !== false) {
                $basePath = substr($scriptName, 0, $position + 1);
                return $basePath === '' ? '/' : rtrim($basePath, '/') . '/';
            }
        }

        $scriptDirectory = str_replace('\\', '/', dirname($scriptName));
        if ($scriptDirectory === '.' || $scriptDirectory === DIRECTORY_SEPARATOR) {
            return '/';
        }

        return rtrim($scriptDirectory, '/') . '/';
    }
}

if (!function_exists('detectBaseUrl')) {
    function detectBaseUrl() {
        $httpsEnabled = (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (($_SERVER['SERVER_PORT'] ?? '') === '443') ||
            (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        );
        $scheme = $httpsEnabled ? 'https' : 'http';
        $host = getServerHost();

        return $scheme . '://' . $host . detectBasePath();
    }
}

if (!function_exists('getServerHost')) {
    function getServerHost() {
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        
        // If there are multiple hosts in X-Forwarded-Host (comma-separated), get the first one
        if (strpos($host, ',') !== false) {
            $host = explode(',', $host)[0];
        }
        
        $host = strtolower(trim((string)$host));

        if ($host === '') {
            return 'localhost';
        }

        if ($host[0] === '[') {
            $endBracket = strpos($host, ']');
            return $endBracket === false ? trim($host, '[]') : substr($host, 1, $endBracket - 1);
        }

        $hostParts = explode(':', $host, 2);
        return $hostParts[0];
    }
}

if (!function_exists('isLocalEnvironment')) {
    function isLocalEnvironment($host) {
        $host = strtolower(trim((string)$host));

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        if (preg_match('/\.(local|test)$/', $host)) {
            return true;
        }

        if (preg_match('/^10\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $host)) {
            return true;
        }

        if (preg_match('/^192\.168\.\d{1,3}\.\d{1,3}$/', $host)) {
            return true;
        }

        if (preg_match('/^172\.(1[6-9]|2\d|3[0-1])\.\d{1,3}\.\d{1,3}$/', $host)) {
            return true;
        }

        return false;
    }
}

if (!function_exists('getEnvValue')) {
    function getEnvValue($keys, $default = null) {
        foreach ((array)$keys as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                return $value;
            }

            if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
                return $_ENV[$key];
            }

            if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
                return $_SERVER[$key];
            }
        }

        return $default;
    }
}

$serverHost = getServerHost();
$isLocalhost = isLocalEnvironment($serverHost);

if ($isLocalhost) {
    // --- LOCAL XAMPP SETTINGS ---
    define('DB_HOST', getEnvValue(['GILDED_SPOON_DB_HOST', 'DB_HOST'], 'localhost'));
    define('DB_USER', getEnvValue(['GILDED_SPOON_DB_USER', 'DB_USER'], 'root'));
    define('DB_PASSWORD', getEnvValue(['GILDED_SPOON_DB_PASSWORD', 'DB_PASSWORD'], ''));
    define('DB_NAME', getEnvValue(['GILDED_SPOON_DB_NAME', 'DB_NAME'], 'Fishyalan_db'));
} else {
    // --- HOSTINGER LIVE SETTINGS ---
    define('DB_HOST', getEnvValue(['GILDED_SPOON_DB_HOST', 'DB_HOST'], 'localhost'));
    define('DB_USER', getEnvValue(['GILDED_SPOON_DB_USER', 'DB_USER'], ''));
    define('DB_PASSWORD', getEnvValue(['GILDED_SPOON_DB_PASSWORD', 'DB_PASSWORD'], ''));
    define('DB_NAME', getEnvValue(['GILDED_SPOON_DB_NAME', 'DB_NAME'], 'Fishyalan_db'));
}

define('APP_BASE_PATH', detectBasePath());
define('BASE_URL', detectBaseUrl());

// ==========================================
// 2. CORE CONSTANTS & SETTINGS
// ==========================================
// Timezone — use Philippine Standard Time (UTC+8)
date_default_timezone_set('Asia/Manila');

// 2.1 RESERVATION & LOYALTY SETTINGS
define('RESTAURANT_MAX_CAPACITY', 50);          // Max guests per hour
define('RESERVATION_POINTS_PER_GUEST', 10);     // Points awarded per guest upon completion
define('RESTAURANT_OPEN_HOUR', 11);             // 11:00 AM
define('RESTAURANT_CLOSE_HOUR', 22);            // 10:00 PM
define('RESERVATION_MIN_PARTY', 1);             // Min guests per reservation
define('RESERVATION_MAX_PARTY', 20);            // Max guests per reservation

// Session Settings
define('SESSION_TIMEOUT', 3600); // 1 hour
define('ROLE_SELECTION_TIMEOUT', 600); // 10 minutes

// User Roles
define('ROLE_ADMIN', 'admin');
define('ROLE_STAFF', 'staff');
define('ROLE_CUSTOMER', 'customer');
define('PROTECTED_SUPERADMIN_EMAIL', 'signarfermar@gmail.com');

/**
 * Roles an administrator can assign to a staff account.
 *
 * Keep this list in one place so the available choices and the server-side
 * validation cannot get out of sync.
 */
function getStaffRoleOptions() {
    return [
        'Cashier',
        'Kitchen Staff',
        'Utility Staff',
    ];
}

// Security Configuration
define('SECURITY_EMAIL', getEnvValue(['GILDED_SPOON_SECURITY_EMAIL', 'SECURITY_EMAIL'], 'signarfermar@gmail.com'));
define('SMTP_HOST', getEnvValue(['GILDED_SPOON_SMTP_HOST', 'SMTP_HOST'], 'smtp.gmail.com'));
define('SMTP_PORT', (int)getEnvValue(['GILDED_SPOON_SMTP_PORT', 'SMTP_PORT'], 587));
define('SMTP_USER', getEnvValue(['GILDED_SPOON_SMTP_USER', 'SMTP_USER'], ''));
define('SMTP_PASSWORD', getEnvValue(['GILDED_SPOON_SMTP_PASSWORD', 'SMTP_PASSWORD'], ''));
define('SMTP_SECURE', getEnvValue(['GILDED_SPOON_SMTP_SECURE', 'SMTP_SECURE'], 'tls'));
define('SMTP_FROM_EMAIL', getEnvValue(['GILDED_SPOON_SMTP_FROM_EMAIL', 'SMTP_FROM_EMAIL'], SMTP_USER));
define('SMTP_FROM_NAME', getEnvValue(['GILDED_SPOON_SMTP_FROM_NAME', 'SMTP_FROM_NAME'], 'La Elena Fishyalan Restaurant'));

// Session Start
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


 require_once __DIR__ . '/Database.php'; 


// ==========================================
// 3. AUTHENTICATION & SECURITY FUNCTIONS
// ==========================================

function app_url($path = '') {
    return BASE_URL . ltrim($path, '/');
}

// Load the shared light theme after each admin page's local styles.  Keeping
// this here also lets older CRUD pages use the same visual system without
// duplicating a stylesheet link in every file.
if (strpos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/adminSide/') !== false) {
    ob_start(static function ($output) {
        if (stripos($output, '</head>') === false) {
            return $output;
        }

        $themeVersion = @filemtime(__DIR__ . '/../adminSide/admin-theme.css') ?: time();
        $themeUrl = app_url('adminSide/admin-theme.css') . '?v=' . $themeVersion;
        $logoutVersion = @filemtime(__DIR__ . '/../adminSide/admin-logout.js') ?: time();
        $logoutUrl = app_url('auth/logout.php');
        $themeLink = '<link rel="stylesheet" href="' . htmlspecialchars($themeUrl, ENT_QUOTES, 'UTF-8') . '">';
        $logoutScript = '<script>window.ADMIN_LOGOUT_URL = ' . json_encode($logoutUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>'
            . '<script src="' . htmlspecialchars(app_url('adminSide/admin-logout.js') . '?v=' . $logoutVersion, ENT_QUOTES, 'UTF-8') . '" defer></script>';
        return preg_replace('/<\\/head>/i', $themeLink . $logoutScript . '</head>', $output, 1);
    });
}

/**
 * Check if a database table has a specific column.
 *
 * @param PDO $db
 * @param string $table
 * @param string $column
 * @return bool
 */
function dbTableHasColumn(PDO $db, string $table, string $column): bool {
    /** @var array<string, bool> */
    static $cache = [];

    $cacheKey = strtolower($table . '.' . $column);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
        ");
        $stmt->execute([
            ':table_name' => $table,
            ':column_name' => $column,
        ]);

        $cache[$cacheKey] = ((int)$stmt->fetchColumn() > 0);
    } catch (Exception $e) {
        $cache[$cacheKey] = false;
    }

    return $cache[$cacheKey];
}

function isCustomerVerifiedRecord(array $user) {
    if (array_key_exists('is_verified', $user)) {
        return (int)$user['is_verified'] === 1;
    }

    return strtoupper((string)($user['account_status'] ?? 'ACTIVE')) === 'ACTIVE';
}

function getRoleSearchMap() {
    return [
        ROLE_ADMIN => [
            'table' => 'admins',
            'id' => 'admin_id',
            'redirect' => app_url('adminSide/admin-dashboard.php'),
            'label' => 'Admin',
            'icon' => 'fa-user-shield',
        ],
        ROLE_STAFF => [
            'table' => 'staffs',
            'id' => 'staff_id',
            'redirect' => app_url('staffSide/staff-dashboard.php'),
            'label' => 'Staff',
            'icon' => 'fa-user-tie',
        ],
        ROLE_CUSTOMER => [
            'table' => 'customer',
            'id' => 'member_id',
            'redirect' => app_url('customerSide/customer-dashboard.php'),
            'label' => 'Customer',
            'icon' => 'fa-user',
        ],
    ];
}

function getRoleLabel($role) {
    $map = getRoleSearchMap();
    return $map[$role]['label'] ?? ucfirst((string)$role);
}

function getRoleIcon($role) {
    $map = getRoleSearchMap();
    return $map[$role]['icon'] ?? 'fa-user-circle';
}

function getAccountStatusBlockMessage($role, array $user) {
    if ($role !== ROLE_CUSTOMER && $role !== ROLE_STAFF) {
        return null;
    }

    $accountStatus = strtoupper((string)($user['account_status'] ?? 'ACTIVE'));

    if ($accountStatus === 'PENDING') {
        if ($role === ROLE_CUSTOMER) {
            return 'Please verify your email address before logging in.';
        }
        return 'Your account is pending Admin approval.';
    }

    if ($accountStatus === 'REJECTED') {
        return 'Your registration was rejected. Please contact Admin.';
    }

    if ($accountStatus === 'DISABLED') {
        return 'Your account has been disabled. Please contact Admin.';
    }

    if ($role === ROLE_CUSTOMER && !isCustomerVerifiedRecord($user)) {
        return 'Please verify your email address before logging in.';
    }

    return null;
}

function clearAuthenticationSessionState() {
    $keys = [
        'user_id',
        'username',
        'email',
        'first_name',
        'last_name',
        'display_name',
        'profile_picture',
        'admin_role',
        'staff_id',
        'user_role',
        'login_time',
        'logged_in',
        'authenticated_identity',
        'pending_role_selection',
        'customer_verification_member_id',
        'customer_verification_email',
        'customer_verification_created_at',
    ];

    foreach ($keys as $key) {
        unset($_SESSION[$key]);
    }
}

function seedAuthenticatedSession($role, array $user) {
    clearAuthenticationSessionState();

    if ($role === ROLE_ADMIN) {
        $_SESSION['user_id']    = $user['admin_id'];
        $_SESSION['username']   = $user['admin_id'];
        $_SESSION['email']      = $user['email'];
        $_SESSION['first_name'] = $user['first_name'] ?? '';
        $_SESSION['last_name']  = $user['last_name'] ?? '';
        $_SESSION['admin_role'] = isProtectedSuperadminEmail($user['email'] ?? null)
            ? 'Super Admin'
            : ($user['role'] ?? 'Admin');
    } elseif ($role === ROLE_STAFF) {
        $_SESSION['user_id']         = $user['staff_id'];
        $_SESSION['staff_id']        = $user['staff_id'];
        $_SESSION['username']        = $user['staff_id'];
        $_SESSION['email']           = $user['email'];
        $_SESSION['first_name']      = $user['first_name'] ?? '';
        $_SESSION['last_name']       = $user['last_name'] ?? '';
        $_SESSION['profile_picture'] = $user['profile_picture'] ?? null;
    } else {
        $_SESSION['user_id']         = $user['member_id'];
        $_SESSION['username']        = $user['membership_number'] ?? $user['member_id'];
        $_SESSION['email']           = $user['email'];
        $_SESSION['first_name'] = $user['first_name'] ?? '';
        $_SESSION['last_name'] = $user['last_name'] ?? '';
        $_SESSION['display_name']    = $user['display_name'] ?? null;
        $_SESSION['profile_picture'] = $user['profile_picture'] ?? null;
    }

    $_SESSION['user_role'] = $role;
    $_SESSION['login_time'] = time();
    $_SESSION['logged_in'] = true;
    $_SESSION['authenticated_identity'] = [
        'role' => $role,
        'user_id' => $_SESSION['user_id'],
        'email' => $user['email'] ?? null,
    ];

    return $_SESSION['user_id'];
}

function issueRememberMeCookie($db, $role, $userId) {
    $searchMap = getRoleSearchMap();

    if (!isset($searchMap[$role])) {
        throw new Exception('Cannot issue remember-me cookie for unknown role.');
    }

    $token = bin2hex(random_bytes(32));
    $hashedToken = password_hash($token, PASSWORD_DEFAULT);
    $meta = $searchMap[$role];

    $tokenQuery = "UPDATE {$meta['table']} SET remember_token = :token WHERE {$meta['id']} = :id";
    $tokenStmt = $db->prepare($tokenQuery);
    $tokenStmt->execute([':token' => $hashedToken, ':id' => $userId]);

    $cookieValue = base64_encode($role . ':' . $userId . ':' . $token);

    setcookie(
        'gilded_spoon_remember',
        $cookieValue,
        time() + (86400 * 30),
        '/',
        '',
        isset($_SERVER['HTTPS']),
        true
    );
}

function registerStaffLoginSession($db, array $user) {
    $staffId = $user['staff_id'] ?? null;
    if (!$staffId) {
        return;
    }

    $updateLastLogin = $db->prepare("UPDATE staffs SET last_login = NOW() WHERE staff_id = :staff_id");
    try {
        $updateLastLogin->execute([':staff_id' => $staffId]);
    } catch (Exception $e) {
    }

    $sessionId = session_id();
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';

    $deleteSessionStmt = $db->prepare("DELETE FROM staff_sessions WHERE staff_id = :sid OR php_session_id = :psid");
    $deleteSessionStmt->execute([':sid' => $staffId, ':psid' => $sessionId]);

    $sessStmt = $db->prepare(
        "INSERT INTO staff_sessions (staff_id, php_session_id, user_agent, ip_address)
         VALUES (:sid, :psid, :ua, :ip)"
    );
    $sessStmt->execute([':sid' => $staffId, ':psid' => $sessionId, ':ua' => $userAgent, ':ip' => $ipAddress]);
}

function getCurrentAuditActorSnapshot(array $overrides = []) {
    $role = $overrides['role'] ?? ($_SESSION['user_role'] ?? null);
    $userId = $overrides['user_id'] ?? ($_SESSION['user_id'] ?? null);
    $email = $overrides['email'] ?? ($_SESSION['email'] ?? null);
    $firstName = $overrides['first_name'] ?? ($_SESSION['first_name'] ?? null);
    $lastName = $overrides['last_name'] ?? ($_SESSION['last_name'] ?? null);

    $name = trim((string) $firstName . ' ' . (string) $lastName);

    return [
        'role' => $role,
        'user_id' => $userId !== null ? (int) $userId : null,
        'email' => $email,
        'name' => $name !== '' ? $name : null,
    ];
}

function normalizeAuditLogDetails($details) {
    if ($details === null) {
        return null;
    }

    if (is_array($details) || is_object($details)) {
        $json = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false && $json !== 'null') {
            return $json;
        }

        return json_encode(['raw_details' => print_r($details, true)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    $details = trim((string) $details);
    return $details === '' ? null : $details;
}

function writeAuditLog($db, $action, $tableAffected = null, $recordId = null, $details = null, array $options = []) {
    $action = trim((string) $action);
    if ($action === '') {
        return false;
    }

    $actorRole = $options['actor_role'] ?? ($_SESSION['user_role'] ?? null);
    $actorUserId = $options['actor_user_id'] ?? ($_SESSION['user_id'] ?? null);
    $adminId = $options['admin_id'] ?? null;
    $staffId = $options['staff_id'] ?? null;

    if ($adminId === null && $staffId === null && $actorUserId !== null) {
        if ($actorRole === ROLE_ADMIN) {
            $adminId = (int) $actorUserId;
        } elseif ($actorRole === ROLE_STAFF) {
            $staffId = (int) $actorUserId;
        }
    }

    $actionDetails = normalizeAuditLogDetails($details);
    $ipAddress = $options['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');

    $auditStmt = $db->prepare(
        "INSERT INTO audit_logs (staff_id, admin_id, action, action_details, table_affected, record_id, ip_address, timestamp)
         VALUES (:staff_id, :admin_id, :action, :action_details, :table_affected, :record_id, :ip_address, NOW())"
    );

    $auditStmt->bindValue(':staff_id', $staffId, $staffId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $auditStmt->bindValue(':admin_id', $adminId, $adminId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $auditStmt->bindValue(':action', $action);
    $auditStmt->bindValue(':action_details', $actionDetails, $actionDetails === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $auditStmt->bindValue(':table_affected', $tableAffected, $tableAffected === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $auditStmt->bindValue(':record_id', $recordId, $recordId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $auditStmt->bindValue(':ip_address', $ipAddress, $ipAddress === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);

    try {
        return $auditStmt->execute();
    } catch (Exception $e) {
        error_log("Audit Log Error: " . $e->getMessage());
        return false;
    }
}

function writeLoginAuditLog($db, $role, $table, $userId, $email) {
    $actor = getCurrentAuditActorSnapshot([
        'role' => $role,
        'user_id' => $userId,
        'email' => $email,
    ]);

    $details = [
        'summary' => 'User login',
        'role' => $actor['role'],
        'email' => $actor['email'],
    ];

    if (!empty($actor['name'])) {
        $details['name'] = $actor['name'];
    }

    writeAuditLog($db, 'LOGIN', $table, $userId, $details, [
        'actor_role' => $role,
        'actor_user_id' => $userId,
    ]);
}

function completeAuthenticatedSession($db, $role, array $user, $keepSignedIn = false) {
    $searchMap = getRoleSearchMap();

    if (!isset($searchMap[$role])) {
        throw new Exception('Unknown role selection.');
    }

    $statusBlockMessage = getAccountStatusBlockMessage($role, $user);
    if ($statusBlockMessage !== null) {
        throw new Exception($statusBlockMessage);
    }

    session_regenerate_id(true);

    $userId = seedAuthenticatedSession($role, $user);

    if ($keepSignedIn) {
        try {
            issueRememberMeCookie($db, $role, $userId);
        } catch (Exception $e) {
            error_log("Remember Me Error: " . $e->getMessage());
        }
    }

    if ($role === ROLE_STAFF) {
        registerStaffLoginSession($db, $user);
    }

    writeLoginAuditLog($db, $role, $searchMap[$role]['table'], $userId, $user['email'] ?? '');

    return $searchMap[$role]['redirect'];
}

// Check if user is logged in
function isLoggedIn() {
    if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
        if (isset($_SESSION['authenticated_identity'])) {
            $identity = $_SESSION['authenticated_identity'];
            if (
                ($identity['role'] ?? null) !== $_SESSION['user_role'] ||
                (int)($identity['user_id'] ?? 0) !== (int)$_SESSION['user_id']
            ) {
                session_unset();
                session_destroy();
                return false;
            }
        }

        // Additional security check for staff: verify session still exists in DB
        if ($_SESSION['user_role'] === 'staff') {
            global $db;
            if (!isset($db)) {
                $database = new Database(); // This requires your database class to be included above!
                $db = $database->connect();
            }
            $stmt = $db->prepare("SELECT session_id FROM staff_sessions WHERE php_session_id = :psid AND staff_id = :sid");
            $stmt->execute([':psid' => session_id(), ':sid' => $_SESSION['user_id']]);
            if ($stmt->rowCount() === 0) {
                // Session was revoked or doesn't exist
                session_unset();
                session_destroy();
                return false;
            }
        }
        return true;
    }
    return false;
}

// Check user role
function isAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === ROLE_ADMIN;
}

function isSuperAdmin() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === ROLE_ADMIN && 
           (
               (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'Super Admin') ||
               isProtectedSuperadminEmail($_SESSION['email'] ?? null)
           );
}

function canManageBackOffice() {
    // RBAC parity: Admin and Super Admin share management permissions.
    return isAdmin();
}

function isStaff() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === ROLE_STAFF;
}

function isCustomer() {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === ROLE_CUSTOMER;
}

// Redirect based on role
function redirectByRole() {
    if (!isLoggedIn()) {
        header('Location: ' . app_url('auth/login.php'));
        exit;
    }

    $role = $_SESSION['user_role'];
    
    if ($role === ROLE_ADMIN) {
        header('Location: ' . app_url('adminSide/admin-dashboard.php'));
    } elseif ($role === ROLE_STAFF) {
        header('Location: ' . app_url('staffSide/staff-dashboard.php'));
    } elseif ($role === ROLE_CUSTOMER) {
        header('Location: ' . app_url('customerSide/customer-dashboard.php'));
    }
    exit;
}

// Get current user
function getCurrentUser() {
    return [
        'user_id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'email' => $_SESSION['email'] ?? null,
        'role' => $_SESSION['user_role'] ?? null,
        'first_name' => $_SESSION['first_name'] ?? null,
        'last_name' => $_SESSION['last_name'] ?? null,
        'display_name' => $_SESSION['display_name'] ?? null,
        'profile_picture' => $_SESSION['profile_picture'] ?? null
    ];
}

// Smart Fallback Logic for Profile Avatars
function getProfileAvatar($profilePicPath, $firstName, $lastName) {
    if (!empty($profilePicPath)) {
        // Clean up relative paths or root slashes
        $cleanPath = preg_replace('/^(\.\.\/|\.\/|\/)+/', '', $profilePicPath);
        // Existing login sessions may still hold paths from before the folder move.
        $cleanPath = preg_replace([
            '~^images/profiles/([^/]+)$~',
            '~^uploads/admin/avatars/([^/]+)$~',
            '~^uploads/staff/avatars/([^/]+)$~',
        ], [
            'images/profiles/customer/$1',
            'images/profiles/admin/$1',
            'images/profiles/staff/$1',
        ], $cleanPath);
        $rootDir = realpath(__DIR__ . '/..');
        $absolutePath = $rootDir . '/' . $cleanPath;
        
        // If it's a full external URL
        if (filter_var($profilePicPath, FILTER_VALIDATE_URL)) {
            return $profilePicPath;
        }
        
        // If we can find the file on the server
        if (file_exists($absolutePath)) {
            return BASE_URL . $cleanPath;
        } 
        // Or if fallback local path exists
        elseif (file_exists($profilePicPath)) {
            return $profilePicPath;
        }
    }
    
    // Fallback to initials if missing or no valid file found
    $initials = '';
    if (!empty($firstName)) {
        $initials .= strtoupper(substr(trim($firstName), 0, 1));
    }
    if (!empty($lastName)) {
        $initials .= strtoupper(substr(trim($lastName), 0, 1));
    }
    if (empty($initials)) {
        $initials = 'U';
    }
    
    // Generate a deterministic color based on initials
    $hash = md5($firstName . $lastName);
    $hue = hexdec(substr($hash, 0, 3)) % 360;
    
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" width="128" height="128">
                <rect width="128" height="128" fill="hsl('.$hue.', 60%, 50%)"/>
                <text x="50%" y="54%" text-anchor="middle" dominant-baseline="middle" font-family="Arial, sans-serif" font-size="55" font-weight="bold" fill="#ffffff">'.$initials.'</text>
            </svg>';
            
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/**
 * Verifies a plain-text input against either a hashed or legacy plain-text stored password.
 */
function passwordMatchesStoredValue($password, $storedPassword) {
    if ($storedPassword === null || $storedPassword === '') {
        return false;
    }

    $info = password_get_info($storedPassword);
    $isHashedPassword = ($info['algoName'] ?? 'unknown') !== 'unknown';

    if ($isHashedPassword) {
        return password_verify($password, $storedPassword);
    }

    return hash_equals((string)$storedPassword, (string)$password);
}

/**
 * Re-authenticates an admin by verifying the provided password against the database.
 */
function verifyAdminPassword($db, $admin_id, $password) {
    $adminStmt = $db->prepare("SELECT password FROM admins WHERE admin_id = :admin_id");
    $adminStmt->execute([':admin_id' => $admin_id]);
    $adminData = $adminStmt->fetch(PDO::FETCH_ASSOC);

    if ($adminData && passwordMatchesStoredValue($password, $adminData['password'] ?? null)) {
        return true;
    }
    
    if ($adminData) {
        $storedPassword = $adminData['password'];
        $info = password_get_info($storedPassword);
        if ($info['algo'] !== 0) {
            // Password is hashed — verify with password_verify
            if (password_verify($password, $storedPassword)) {
                return true;
            }
        } else {
            // Legacy plain-text password — direct comparison
            if ($password === $storedPassword) {
                return true;
            }
        }
    }
    
    throw new Exception("Incorrect admin password. Authentication failed.");
}

/**
 * Backward-compatible alias. Prefer verifyAdminPassword() for new code.
 */
function verifySuperadminPassword($db, $admin_id, $password) {
    return verifyAdminPassword($db, $admin_id, $password);
}

/**
 * Returns true for the hard-protected Super Admin identity.
 */
function isProtectedSuperadminEmail($email) {
    return strtolower(trim((string)$email)) === strtolower(PROTECTED_SUPERADMIN_EMAIL);
}

/**
 * Returns true when the target admin account has protected Super Admin status.
 */
function isImmutableSuperadminAccount($db, $adminId) {
    $stmt = $db->prepare("SELECT role, email FROM admins WHERE admin_id = :admin_id LIMIT 1");
    $stmt->execute([':admin_id' => $adminId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception("Target admin account was not found.");
    }

    return ($row['role'] ?? '') === 'Super Admin' || isProtectedSuperadminEmail($row['email'] ?? null);
}

/**
 * Enforces immutable protection for Super Admin account(s).
 * By policy, a Super Admin account cannot be deleted or demoted.
 */
function enforceImmutableSuperadminPolicy($db, $targetAdminId, $action, $newRole = null, $newEmail = null) {
    $stmt = $db->prepare("SELECT role, email FROM admins WHERE admin_id = :admin_id LIMIT 1");
    $stmt->execute([':admin_id' => $targetAdminId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception("Target admin account was not found.");
    }

    $isSuperAdminRole = ($row['role'] ?? '') === 'Super Admin';
    $isProtectedEmail = isProtectedSuperadminEmail($row['email'] ?? null);

    if (!$isSuperAdminRole && !$isProtectedEmail) {
        return;
    }

    if ($action === 'delete') {
        throw new Exception("Protected Super Admin accounts cannot be deleted.");
    }

    if ($action === 'change_role' && $newRole !== 'Super Admin') {
        throw new Exception("Protected Super Admin status cannot be removed.");
    }

    if ($newEmail !== null && $isProtectedEmail && !isProtectedSuperadminEmail($newEmail)) {
        throw new Exception("Protected Super Admin email cannot be changed.");
    }
}

/**
 * Sends an automated security notice to the designated security email detailing the administrative action.
 */
function sendAdminSecurityNotice($target_name, $action_nature) {
    if (!function_exists('sendMail')) {
        // Try to load Mailer if not already loaded
        $mailerPath = realpath(__DIR__ . '/Mailer.php');
        if (file_exists($mailerPath)) {
            require_once $mailerPath;
        } else {
            return false;
        }
    }

    $adminName = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
    $adminId = $_SESSION['user_id'] ?? 'Unknown ID';
    $timestamp = date('Y-m-d H:i:s T');

    $subject = "Security Logging: Administrative Action Performed";
    $htmlBody = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;'>
            <h2 style='color: #d4af37;'>Security Audit Log</h2>
            <p>An administrative action requiring Superadmin verification has been performed.</p>
            <table border='1' cellpadding='10' cellspacing='0' style='border-collapse: collapse; width: 100%; border-color: #ddd;'>
                <tr>
                    <td style='background-color: #f9f9f9; width: 30%;'><strong>Action Nature</strong></td>
                    <td>" . htmlspecialchars($action_nature) . "</td>
                </tr>
                <tr>
                    <td style='background-color: #f9f9f9;'><strong>Target Subject</strong></td>
                    <td>" . htmlspecialchars($target_name) . "</td>
                </tr>
                <tr>
                    <td style='background-color: #f9f9f9;'><strong>Performed By</strong></td>
                    <td>" . htmlspecialchars($adminName) . " (ID: " . htmlspecialchars((string)$adminId) . ")</td>
                </tr>
                <tr>
                    <td style='background-color: #f9f9f9;'><strong>Timestamp</strong></td>
                    <td>" . htmlspecialchars($timestamp) . "</td>
                </tr>
            </table>
            <p style='margin-top: 20px; font-size: 12px; color: #777;'>This is a system-generated security notice. No response is required.</p>
        </div>
    ";

    return sendMail(SECURITY_EMAIL, 'Security Auditing', $subject, $htmlBody);
}

/**
 * Sends a safe, generic account-change notification to a user.
 *
 * User-management pages use this helper after edits, email changes, and
 * password resets.  Keep mail delivery best-effort: a mail configuration or
 * network problem must not turn a completed account update into a PHP fatal
 * error.
 */
function sendUserNotification($email, $name, array $changes = [], $message = null) {
    $email = trim((string)$email);
    $name = trim((string)$name);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_log('User notification skipped: invalid recipient email.');
        return false;
    }

    if (!function_exists('sendMail')) {
        $mailerPath = __DIR__ . '/Mailer.php';
        if (!is_file($mailerPath)) {
            error_log('User notification skipped: Mailer.php was not found.');
            return false;
        }
        require_once $mailerPath;
    }

    $safeName = htmlspecialchars($name !== '' ? $name : 'User', ENT_QUOTES, 'UTF-8');
    $safeMessage = trim((string)$message);
    $htmlBody = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#333">'
        . '<h2 style="color:#173f42">Account notification</h2>'
        . '<p>Hello ' . $safeName . ',</p>';

    if ($safeMessage !== '') {
        $htmlBody .= '<p>' . nl2br(htmlspecialchars($safeMessage, ENT_QUOTES, 'UTF-8')) . '</p>';
    } elseif (!empty($changes)) {
        $htmlBody .= '<p>Your account information was updated:</p><ul>';
        foreach ($changes as $field => $value) {
            $htmlBody .= '<li><strong>' . htmlspecialchars((string)$field, ENT_QUOTES, 'UTF-8') . ':</strong> '
                . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $htmlBody .= '</ul>';
    } else {
        $htmlBody .= '<p>Your account was updated by an administrator.</p>';
    }

    $htmlBody .= '<p>If you did not expect this change, please contact support immediately.</p></div>';

    try {
        return sendMail($email, $name !== '' ? $name : 'User', 'Account update notification', $htmlBody);
    } catch (Throwable $e) {
        error_log('User notification failed: ' . $e->getMessage());
        return false;
    }
}
