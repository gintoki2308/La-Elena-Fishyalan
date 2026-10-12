<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';

$message = '';
$messageType = '';
$success = false;
$redirect = app_url('auth/login.php');

if (!isset($_SESSION['otp_email']) || !isset($_SESSION['otp_role'])) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $otp = trim($_POST['otp'] ?? '');

if ($otp === '') {
        $message = 'Please enter the 6-digit OTP sent to your email.';
        $messageType = 'error';
    } elseif (!ctype_digit($otp)) {
        $message = 'Security Alert: OTP must contain only numbers.';
        $messageType = 'error';
    } elseif (strlen($otp) !== 6) {
        $message = 'Invalid Format: OTP must be exactly 6 digits.';
        $messageType = 'error';
    } else {
        try {
            $database = new Database();
            $db = $database->connect();

            $email = $_SESSION['otp_email'];
            $user_role = $_SESSION['otp_role'];

            $table = 'customer';
            $idColumn = 'member_id';
            if ($user_role === 'admin') {
                $table = 'admins';
                $idColumn = 'admin_id';
            } elseif ($user_role === 'staff') {
                $table = 'staffs';
                $idColumn = 'staff_id';
            }

            $query = "SELECT * FROM $table WHERE email = :email LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                throw new Exception('User not found.');
            }

            if (($user['otp_token'] ?? null) !== $otp || strtotime((string) ($user['otp_expiry'] ?? '')) <= time()) {
                throw new Exception('Invalid or expired OTP. Please try again or request a new one.');
            }

            $clearQuery = "UPDATE $table SET otp_token = NULL, otp_expiry = NULL WHERE email = :email";
            $clearStmt = $db->prepare($clearQuery);
            $clearStmt->execute([':email' => $email]);

            // SECURE FIX: Use the completeAuthenticatedSession core function to enforce all safety
            // validations, audit logging, session rotations, and account block checking safely.
            $redirect = completeAuthenticatedSession($db, $user_role, $user);

            unset($_SESSION['otp_email'], $_SESSION['otp_role']);
            $success = true;
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #0d0d0d; color: #f5f5f5; font-family: Arial, sans-serif; }
        .card-wrap { width: 100%; max-width: 420px; background: #1a1a1a; border: 1px solid rgba(212, 175, 55, 0.3); border-radius: 14px; padding: 28px; box-shadow: 0 12px 40px rgba(0,0,0,0.45); }
        .title { color: #d4af37; font-weight: 700; margin-bottom: 8px; }
        .subtitle { color: #c9c9c9; font-size: 14px; margin-bottom: 22px; }
        .form-control { background: #111; border-color: #444; color: #fff; }
        .form-control:focus { background: #111; color: #fff; border-color: #d4af37; box-shadow: 0 0 0 0.2rem rgba(212,175,55,0.15); }
        .btn-gold { background: #d4af37; border-color: #d4af37; color: #111; font-weight: 700; }
        .btn-gold:hover { background: #c39f2f; border-color: #c39f2f; color: #111; }
        .link-muted { color: #c9c9c9; text-decoration: none; }
        .link-muted:hover { color: #fff; }
    </style>
</head>
<body>
    <div class="card-wrap">
        <?php if ($success): ?>
            <h1 class="title h3">Login Successful</h1>
            <p class="subtitle">Welcome back, <?php echo htmlspecialchars($_SESSION['first_name'] ?: 'User'); ?>.</p>
            <div class="alert alert-success">You have securely logged in using your one-time password.</div>
            <a href="<?php echo htmlspecialchars($redirect); ?>" class="btn btn-gold w-100 mb-2">Continue to Dashboard</a>
            <a href="reset_password.php" class="btn btn-outline-light w-100">Set a New Password</a>
        <?php else: ?>
            <h1 class="title h3">Verify OTP</h1>
            <p class="subtitle">Enter the 6-digit code sent to <strong><?php echo htmlspecialchars($_SESSION['otp_email']); ?></strong>.</p>
            <?php if ($message !== ''): ?>
                <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : 'success'; ?>"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <form method="POST" action="verify_otp.php">
                <div class="mb-3">
                    <label for="otp" class="form-label">One-Time Password</label>
                    <input type="text" class="form-control" id="otp" name="otp" maxlength="6" required autofocus>
                </div>
                <button type="submit" class="btn btn-gold w-100">Verify Code</button>
            </form>
            <div class="mt-3 d-flex justify-content-between">
                <a href="forgot_password.php" class="link-muted">Request a new OTP</a>
                <a href="login.php" class="link-muted">Back to Login</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
