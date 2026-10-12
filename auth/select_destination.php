<?php
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$pendingSelection = $_SESSION['pending_role_selection'] ?? null;
if (!$pendingSelection) {
    echo json_encode(['success' => false, 'message' => 'Your role selection has expired. Please sign in again.']);
    exit;
}

$selectionAge = time() - (int)($pendingSelection['created_at'] ?? 0);
if ($selectionAge > ROLE_SELECTION_TIMEOUT) {
    unset($_SESSION['pending_role_selection']);
    echo json_encode(['success' => false, 'message' => 'Your role selection has expired. Please sign in again.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$selectedRole = trim((string)($input['role'] ?? ''));
$selectedUserId = (int)($input['user_id'] ?? 0);

if ($selectedRole === '' || $selectedUserId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Please choose a valid account.']);
    exit;
}

$matchedOption = null;
foreach (($pendingSelection['options'] ?? []) as $option) {
    if (($option['role'] ?? '') === $selectedRole && (int)($option['user_id'] ?? 0) === $selectedUserId) {
        $matchedOption = $option;
        break;
    }
}

if (!$matchedOption) {
    echo json_encode(['success' => false, 'message' => 'That account is not available for this sign-in session.']);
    exit;
}

$searchMap = getRoleSearchMap();
if (!isset($searchMap[$selectedRole])) {
    echo json_encode(['success' => false, 'message' => 'Unknown role selection.']);
    exit;
}

$database = new Database();
$db = $database->connect();

if (!$db) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed. Please try again later.']);
    exit;
}

try {
    $meta = $searchMap[$selectedRole];
    $stmt = $db->prepare(
        "SELECT * FROM {$meta['table']} WHERE {$meta['id']} = :id AND email = :email LIMIT 1"
    );
    $stmt->execute([
        ':id' => $selectedUserId,
        ':email' => $pendingSelection['email'] ?? '',
    ]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        unset($_SESSION['pending_role_selection']);
        echo json_encode(['success' => false, 'message' => 'We could not confirm that account anymore. Please sign in again.']);
        exit;
    }

    $redirect = completeAuthenticatedSession(
        $db,
        $selectedRole,
        $user,
        !empty($pendingSelection['keep_signed_in'])
    );

    echo json_encode([
        'success' => true,
        'redirect' => $redirect,
    ]);
} catch (Exception $e) {
    error_log("Role Selection Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage() ?: 'Unable to complete sign in.']);
}
?>
