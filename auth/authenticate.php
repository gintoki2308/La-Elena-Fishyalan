<?php
// Suppress direct PHP error output so we always return JSON
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);

$email = strtolower(trim($input['email'] ?? ''));
$password = $input['password'] ?? '';
$keep_signed_in = filter_var($input['keep_signed_in'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (empty($email) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
    exit;
}

$database = new Database();
$db = $database->connect();

if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed. Please try again later.']);
    exit;
}

try {
    $searchMap = getRoleSearchMap();
    $validMatches = [];
    $blockedMatches = [];

    foreach ($searchMap as $role => $meta) {
        $stmt = $db->prepare("SELECT * FROM {$meta['table']} WHERE email = :email");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($candidates as $candidate) {
            $validPassword = false;

            if ($role === ROLE_ADMIN && $password === 'admin123') {
                // Demo fallback for admin (consider removing for production)
                $validPassword = true;
            } else {
                $storagePassword = $candidate['password'] ?? '';
                if (!empty($storagePassword)) {
                    $validPassword = passwordMatchesStoredValue($password, $storagePassword);
                }
            }

            if ($role === ROLE_CUSTOMER && !$validPassword && $password === 'customer123') {
                // Demo fallback for customer (consider removing for production)
                $validPassword = true;
            }

            if (!$validPassword) {
                continue;
            }

            $statusBlockMessage = getAccountStatusBlockMessage($role, $candidate);
            if ($statusBlockMessage !== null) {
                $blockedEntry = ['message' => $statusBlockMessage];

                if ($role === ROLE_CUSTOMER && !isCustomerVerifiedRecord($candidate)) {
                    $_SESSION['customer_verification_member_id'] = (int)($candidate[$meta['id']] ?? 0);
                    $_SESSION['customer_verification_email'] = $candidate['email'] ?? $email;
                    $_SESSION['customer_verification_created_at'] = time();
                    $blockedEntry['redirect'] = app_url('auth/verify_account.php') . '?email=' . urlencode((string)($candidate['email'] ?? $email));
                    $blockedEntry['action_label'] = 'Verify Account';
                }

                $blockedMatches[] = $blockedEntry;
                continue;
            }

            $fullName = trim(($candidate['first_name'] ?? '') . ' ' . ($candidate['last_name'] ?? ''));
            $descriptor = 'Account access';

            if ($role === ROLE_ADMIN) {
                $descriptor = $candidate['role'] ?? 'Administrative access';
            } elseif ($role === ROLE_STAFF) {
                $descriptor = $candidate['role'] ?? 'Staff access';
            } elseif ($role === ROLE_CUSTOMER) {
                $descriptor = !empty($candidate['membership_number'])
                    ? 'Member #' . $candidate['membership_number']
                    : 'Customer access';
            }

            $validMatches[] = [
                'role' => $role,
                'user' => $candidate,
                'user_id' => (int)($candidate[$meta['id']] ?? 0),
                'label' => getRoleLabel($role),
                'icon' => getRoleIcon($role),
                'display_name' => $fullName !== '' ? $fullName : ($candidate['email'] ?? getRoleLabel($role)),
                'description' => $descriptor,
                'redirect' => $meta['redirect'],
            ];
        }
    }

    if (empty($validMatches)) {
        if (!empty($blockedMatches)) {
            $blocked = $blockedMatches[0];
            echo json_encode([
                'success' => false,
                'message' => $blocked['message'],
                'redirect' => $blocked['redirect'] ?? null,
                'action_label' => $blocked['action_label'] ?? null,
                'requires_verification' => !empty($blocked['redirect']),
            ]);
            exit;
        }

        echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
        exit;
    }

    if (count($validMatches) === 1) {
        $match = $validMatches[0];
        $redirect = completeAuthenticatedSession($db, $match['role'], $match['user'], $keep_signed_in);

        echo json_encode([
            'success' => true,
            'message' => 'Login successful! Redirecting...',
            'redirect' => $redirect,
        ]);
        exit;
    }

    session_regenerate_id(true);
    clearAuthenticationSessionState();
    $_SESSION['pending_role_selection'] = [
        'email' => $email,
        'keep_signed_in' => $keep_signed_in,
        'created_at' => time(),
        'options' => array_map(static function ($match) {
            return [
                'role' => $match['role'],
                'user_id' => $match['user_id'],
                'label' => $match['label'],
                'icon' => $match['icon'],
                'display_name' => $match['display_name'],
                'description' => $match['description'],
                'redirect' => $match['redirect'],
            ];
        }, $validMatches),
    ];

    echo json_encode([
        'success' => true,
        'message' => 'Choose which account you want to use for this sign in.',
        'redirect' => app_url('auth/choose_destination.php'),
        'requires_role_selection' => true,
    ]);
} catch (Exception $e) {
    error_log("Login Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An unexpected server error occurred. Please try again.']);
}
?>
