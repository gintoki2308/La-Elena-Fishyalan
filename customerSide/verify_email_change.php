<?php
require_once '../config/config.php';
require_once '../config/Database.php';

// Check if user is logged in and is a customer
if (!isLoggedIn() || !isCustomer()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$user = getCurrentUser();
$database = new Database();
$db = $database->connect();

$error = null;
$success = null;

// Check if there is actually a pending email
$stmt = $db->prepare("SELECT pending_email, email_token FROM customer WHERE member_id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record || empty($record['pending_email'])) {
    header('Location: profile.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_email') {
    $submittedCode = trim($_POST['otp']);

    if (empty($submittedCode)) {
        $error = "Please enter the verification code.";
    } elseif ($submittedCode === $record['email_token']) {
        // Success!
        $updateStmt = $db->prepare("
            UPDATE customer 
            SET email = :new_email, 
                pending_email = NULL, 
                email_token = NULL 
            WHERE member_id = :id
        ");
        
        $updateStmt->execute([
            ':new_email' => $record['pending_email'],
            ':id' => $_SESSION['user_id']
        ]);

        $_SESSION['email'] = $record['pending_email'];
        $success = "Email successfully verified and updated! Redirecting to profile...";
    } else {
        $error = "Invalid verification code. Please try again or resend.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email Change - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #121212; color: #fff; font-family: 'Montserrat', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .feature-box { background-color: #1e1e1e; border: 1px solid #333; border-radius: 12px; padding: 40px; width: 100%; max-width: 500px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .text-gold { color: #d4af37; }
        .form-control { background-color: #2a2a2a; color: white; border: 1px solid #444; border-radius: 6px; letter-spacing: 5px; font-size: 24px; text-align: center; }
        .form-control:focus { background-color: #333; color: white; border-color: #d4af37; box-shadow: none; }
        .btn-gold { background-color: #d4af37; color: #000; font-weight: bold; border-radius: 6px; }
        .btn-gold:hover { background-color: #c19b2e; }
    </style>
    <link rel="stylesheet" href="customer-theme.css">
</head>
<body>

    <div class="feature-box">
        <div class="text-center mb-4">
            <i class="fas fa-envelope-open-text text-gold mb-3" style="font-size: 48px;"></i>
            <h3 class="text-gold" style="font-family: 'Playfair Display', serif;">Verify Your Email</h3>
            <p class="text-muted">We sent a 6-digit code to <strong><?php echo htmlspecialchars($record['pending_email']); ?></strong>. Enter it below to confirm your new email address.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?></div>
            <script>
                setTimeout(function() {
                    window.location.href = 'profile.php';
                }, 2000);
            </script>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="verify_email">
                <div class="mb-4">
                    <input type="text" name="otp" class="form-control text-uppercase" placeholder="000000" maxlength="6" required autofocus>
                </div>
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-gold py-2">Verify and Update</button>
                    <a href="profile.php" class="btn btn-outline-secondary mt-2">Cancel</a>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

