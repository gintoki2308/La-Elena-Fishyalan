<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';
require_once '../config/Database.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    header('Location: login.php');
    exit;
}

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($new_password) || empty($confirm_password)) {
        $message = "Please fill in all fields.";
        $messageType = "error";
    } elseif ($new_password !== $confirm_password) {
        $message = "Passwords do not match.";
        $messageType = "error";
    } elseif (strlen($new_password) < 6) {
        $message = "Password must be at least 6 characters long.";
        $messageType = "error";
    } else {
        $database = new Database();
        $db = $database->connect();
        
        $user_id = $_SESSION['user_id'];
        $user_role = $_SESSION['user_role'];
        
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        // FIXED FOR HOSTINGER: Strict lowercase table names
        if ($user_role === 'admin') {
            $table = 'admins';
            $id_col = 'admin_id';
            $redirect = '../adminSide/admin-dashboard.php';
        } elseif ($user_role === 'staff') {
            $table = 'staffs';
            $id_col = 'staff_id';
            $redirect = '../staffSide/staff-dashboard.php';
        } else {
            $table = 'customer';
            $id_col = 'member_id';
            $redirect = '../customerSide/customer-dashboard.php';
        }
        
        $query = "UPDATE $table SET password = :password WHERE $id_col = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':password', $hashed_password);
        $stmt->bindParam(':id', $user_id);
        
        if ($stmt->execute()) {
            $success = true;
        } else {
            $message = "Failed to update password. Please try again.";
            $messageType = "error";
        }
    }
} else {
    // Determine redirect loop based on session role if they choose to skip
    if ($_SESSION['user_role'] === 'admin') {
        $redirect = '../adminSide/admin-dashboard.php';
    } elseif ($_SESSION['user_role'] === 'staff') {
        $redirect = '../staffSide/staff-dashboard.php';
    } else {
        $redirect = '../customerSide/customer-dashboard.php';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - La Elena Fishyalan Restaurant</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            /* Matched luxury design system variables */
            --gold-light: #FCF6BA;
            --gold-main: #D4AF37;
            --gold-dark: #BF953F;
            --bg-deep: #050505;
            --bg-radial: radial-gradient(circle at 50% 0%, #1a1a1a 0%, #050505 80%);
            --card-bg: rgba(20, 20, 20, 0.7);
            --text-main: #f5f5f5;
            --text-muted: #a3a3a3;
            --border-gold: rgba(212, 175, 55, 0.3);
            --gold-gradient: linear-gradient(to right, #BF953F, #FCF6BA, #B38728, #FBF5B7, #AA771C);
            --input-bg: rgba(0, 0, 0, 0.4);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Montserrat', sans-serif;
            background: var(--bg-radial);
            background-attachment: fixed;
            color: var(--text-main);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 40px 20px;
        }

        .metallic-text {
            background: var(--gold-gradient);
            background-size: 200% auto;
            color: #000;
            background-clip: text;
            text-fill-color: transparent;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: shine 5s linear infinite;
        }

        @keyframes shine {
            to { background-position: 200% center; }
        }

        .login-container {
            width: 100%;
            max-width: 440px;
            background: var(--card-bg);
            backdrop-filter: blur(15px);
            border: 1px solid var(--border-gold);
            border-radius: 8px;
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.8), inset 0 1px 0 rgba(255, 255, 255, 0.05);
            padding: 48px 40px;
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
        }

        /* Corner Accents */
        .login-container::before, .login-container::after {
            content: ''; position: absolute; width: 15px; height: 15px; border: 1px solid var(--gold-main); opacity: 0.5;
        }
        .login-container::before { top: 15px; left: 15px; border-right: none; border-bottom: none; }
        .login-container::after { bottom: 15px; right: 15px; border-left: none; border-top: none; }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .login-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .login-header h2 {
            font-family: 'Playfair Display', serif;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: 1px;
        }

        .login-header p {
            font-size: 14px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-muted);
            font-weight: 500;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 16px;
            color: #666;
            font-size: 16px;
            transition: color 0.3s ease;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px 14px 48px;
            background-color: var(--input-bg);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            font-size: 14px;
            color: var(--text-main);
            font-family: 'Montserrat', sans-serif;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--gold-main);
            background-color: rgba(0, 0, 0, 0.6);
            box-shadow: 0 0 10px rgba(212, 175, 55, 0.2);
            outline: none;
            color: white;
        }

        .form-control:focus + i {
            color: var(--gold-main);
        }

        .form-control::placeholder {
            color: #555;
            font-weight: 400;
        }

        .btn-submit {
            width: 100%;
            padding: 15px;
            background: var(--gold-gradient);
            color: #000;
            border: none;
            border-radius: 4px;
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 2px;
            cursor: pointer;
            transition: all 0.3s ease;
            margin: 16px 0;
            box-shadow: 0 10px 20px rgba(0,0,0,0.5);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 25px rgba(212, 175, 55, 0.2);
        }
        
        .btn-outline {
            background: transparent;
            color: var(--gold-main);
            border: 1px solid var(--gold-main);
            box-shadow: none;
        }
        
        .btn-outline:hover {
            background: var(--gold-main);
            color: #121212;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.3);
            transform: translateY(-2px);
        }

        .alert-message {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 24px;
            font-size: 13px;
            text-align: center;
            border: 1px solid transparent;
        }
        
        .alert-error {
            background: rgba(220, 53, 69, 0.1);
            color: #ff6b6b;
            border-color: rgba(220, 53, 69, 0.3);
        }
        
        .alert-success {
            background: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
            border-color: rgba(46, 204, 113, 0.3);
        }
    </style>
</head>
<body>
    <div class="login-container">
        <?php if (isset($success) && $success): ?>
            <div class="login-header">
                <h2 class="metallic-text">Password Updated!</h2>
                <p>Your password has been successfully reset.</p>
            </div>
            
            <div class="alert-message alert-success">
                <i class="fas fa-check-circle me-2"></i>
                You can now use your new password for future logins.
            </div>

            <a href="<?= htmlspecialchars($redirect) ?>" class="btn-submit text-center text-decoration-none" style="display: block;">
                CONTINUE TO DASHBOARD
            </a>
            
        <?php else: ?>
            <div class="login-header">
                <h2 class="metallic-text">Set New Password</h2>
                <p>Please enter a strong new password below.</p>
            </div>

            <?php if (!empty($message)): ?>
                <div class="alert-message <?php echo $messageType === 'error' ? 'alert-error' : 'alert-success'; ?>">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form action="reset_password.php" method="POST">
                <div class="form-group">
                    <label>New Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" class="form-control" name="new_password" placeholder="Enter new password" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Confirm Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" class="form-control" name="confirm_password" placeholder="Confirm new password" required>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    SAVE PASSWORD
                </button>
            </form>
            
            <a href="<?= htmlspecialchars($redirect) ?>" class="btn-submit btn-outline text-center text-decoration-none" style="display: block; padding: 14px;">
                SKIP FOR NOW
            </a>
        <?php endif; ?>
    </div>
</body>
</html>
