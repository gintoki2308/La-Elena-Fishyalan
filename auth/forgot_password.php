<?php
require_once '../config/config.php';
require_once '../config/Database.php';
require_once '../config/Mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$message = '';
$messageType = '';
$step = 'email'; // 'email' or 'choose_role'
$matchedRoles = [];
$submittedEmail = '';

// Handle Step 2: Role chosen, now send OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['chosen_role']) && !empty($_POST['chosen_email'])) {
    $email = strtolower(trim($_POST['chosen_email']));
    $chosenRole = $_POST['chosen_role'];
    $searchMap = getRoleSearchMap();

    if (!isset($searchMap[$chosenRole])) {
        $message = 'Invalid role selected.';
        $messageType = 'error';
    } else {
        $database = new Database();
        $db = $database->connect();

        if ($db) {
            $meta = $searchMap[$chosenRole];
            $stmt = $db->prepare("SELECT * FROM {$meta['table']} WHERE email = :email LIMIT 1");
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Block accounts with restricted statuses (archived, disabled, rejected)
                $accountStatus = strtoupper((string)($user['account_status'] ?? 'ACTIVE'));
                if (in_array($accountStatus, ['DISABLED', 'REJECTED'])) {
                    $message = getAccountStatusBlockMessage($chosenRole, $user) ?? 'Your account has been restricted. Please contact support.';
                    $messageType = 'error';
                // Block unverified customer accounts from resetting password
                } elseif ($chosenRole === ROLE_CUSTOMER && !isCustomerVerifiedRecord($user)) {
                    $_SESSION['unverified_email'] = $email;
                    $message = 'Your account has not been verified yet. Please verify your account before resetting your password.';
                    $messageType = 'error';
                    $step = 'unverified';
                } else {
                    $otp = sprintf("%06d", mt_rand(100000, 999999));
                    $expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                    $updateStmt = $db->prepare("UPDATE {$meta['table']} SET otp_token = :otp, otp_expiry = :expiry WHERE email = :email");
                    $updateStmt->bindParam(':otp', $otp);
                    $updateStmt->bindParam(':expiry', $expiry);
                    $updateStmt->bindParam(':email', $email);

                    if ($updateStmt->execute()) {
                        $subject = "Your Login OTP - La Elena Fishyalan Restaurant";
                        $htmlBody = "
                            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;'>
                                <h2 style='color: #D4AF37;'>One-Time Password Verification</h2>
                                <p>Hello,</p>
                                <p>You requested to log in without a password. Please use the following One-Time Password (OTP) to securely access your account.</p>
                                <div style='background-color: #f4f4f4; padding: 20px; text-align: center; border-radius: 8px; margin: 20px 0;'>
                                    <span style='font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #1a1a1a;'>{$otp}</span>
                                </div>
                                <div style='text-align: center; margin: 30px 0;'>
                                    <a href='" . BASE_URL . "auth/verify_otp.php' style='background-color: #D4AF37; color: #000000; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block;'>Verify OTP Now</a>
                                </div>
                                <p>This code will expire in <strong>15 minutes</strong> for security reasons.</p>
                                <p>If you did not request this OTP, please ignore this email.</p>
                                <p>Best regards,<br>La Elena Fishyalan Restaurant Team</p>
                            </div>
                        ";

                        $name = $user['first_name'] ?? 'User';
                        if (sendMail($email, $name, $subject, $htmlBody)) {
                            $_SESSION['otp_email'] = $email;
                            $_SESSION['otp_role'] = $chosenRole;
                            header("Location: verify_otp.php");
                            exit;
                        } else {
                            $message = 'Failed to send OTP email. Please try again.';
                            $messageType = 'error';
                        }
                    } else {
                        $message = 'Database error. Please try again later.';
                        $messageType = 'error';
                    }
                }
            } else {
                $message = 'No account found with that email address.';
                $messageType = 'error';
            }
        } else {
            $message = 'Database connection failed.';
            $messageType = 'error';
        }
    }
}

// Handle Step 1: Email submitted, check all tables
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['chosen_role'])) {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!empty($email)) {
        $database = new Database();
        $db = $database->connect();

        if ($db) {
            $searchMap = getRoleSearchMap();
            $matches = [];
            $unverifiedCustomerBlocked = false;
            $restrictedAccountBlocked = false;
            $restrictedBlockMessage = '';

            foreach ($searchMap as $role => $meta) {
                $stmt = $db->prepare("SELECT * FROM {$meta['table']} WHERE email = :email LIMIT 1");
                $stmt->bindParam(':email', $email);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    // Skip accounts with restricted statuses (archived, disabled, rejected)
                    $accountStatus = strtoupper((string)($user['account_status'] ?? 'ACTIVE'));
                    if (in_array($accountStatus, ['DISABLED', 'REJECTED'])) {
                        $restrictedAccountBlocked = true;
                        $restrictedBlockMessage = getAccountStatusBlockMessage($role, $user) ?? 'Your account has been restricted. Please contact support.';
                        continue;
                    }

                    // Skip unverified customer accounts — they must verify before resetting password
                    if ($role === ROLE_CUSTOMER && !isCustomerVerifiedRecord($user)) {
                        $_SESSION['unverified_email'] = $email;
                        $unverifiedCustomerBlocked = true;
                        continue;
                    }

                    $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                    $matches[] = [
                        'role'  => $role,
                        'label' => $meta['label'],
                        'icon'  => $meta['icon'],
                        'name'  => $fullName !== '' ? $fullName : 'Account',
                    ];
                }
            }

            if (count($matches) === 0) {
                if (!empty($restrictedAccountBlocked)) {
                    // Account found but restricted (disabled or rejected)
                    $message = $restrictedBlockMessage;
                    $messageType = 'error';
                } elseif (!empty($unverifiedCustomerBlocked)) {
                    // Only unverified customer found — prompt to verify
                    $message = 'Your account has not been verified yet. Please verify your account before resetting your password.';
                    $messageType = 'error';
                    $step = 'unverified';
                } else {
                    // No account found for this email
                    $message = 'No account found with that email address.';
                    $messageType = 'error';
                }
            } elseif (!empty($unverifiedCustomerBlocked) && count($matches) >= 1) {
                // Unverified customer account exists for this email, BUT there are also one or more
                // verified staff/admin accounts. Show the role chooser for the verified roles only.
                // The customer role is intentionally excluded from $matches — the user must verify
                // their customer account separately before it becomes eligible here.
                $step           = 'choose_role';
                $matchedRoles   = $matches;
                $submittedEmail = $email;
                // Surface a notice so the user understands their customer account needs verification.
                $message     = 'Your Customer account for this email is not yet verified. You may reset the password for your other account(s) below, or verify your Customer account first.';
                $messageType = 'warning';
            } elseif (count($matches) === 1) {
                // Only one match — send OTP directly
                $match = $matches[0];
                $meta = $searchMap[$match['role']];
                $stmt = $db->prepare("SELECT * FROM {$meta['table']} WHERE email = :email LIMIT 1");
                $stmt->bindParam(':email', $email);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);


                // Block accounts with restricted statuses (archived, disabled, rejected)
                $singleAccountStatus = strtoupper((string)($user['account_status'] ?? 'ACTIVE'));
                if (in_array($singleAccountStatus, ['DISABLED', 'REJECTED'])) {
                    $message = getAccountStatusBlockMessage($match['role'], $user) ?? 'Your account has been restricted. Please contact support.';
                    $messageType = 'error';
                // Block unverified customer accounts
                } elseif ($match['role'] === ROLE_CUSTOMER && $user && !isCustomerVerifiedRecord($user)) {
                    $_SESSION['unverified_email'] = $email;
                    $message = 'Your account has not been verified yet. Please verify your account before resetting your password.';
                    $messageType = 'error';
                    $step = 'unverified';
                } else {
                $otp = sprintf("%06d", mt_rand(100000, 999999));
                $expiry = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                $updateStmt = $db->prepare("UPDATE {$meta['table']} SET otp_token = :otp, otp_expiry = :expiry WHERE email = :email");
                $updateStmt->bindParam(':otp', $otp);
                $updateStmt->bindParam(':expiry', $expiry);
                $updateStmt->bindParam(':email', $email);

                if ($updateStmt->execute()) {
                    $subject = "Your Login OTP - La Elena Fishyalan Restaurant";
                    $htmlBody = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;'>
                            <h2 style='color: #D4AF37;'>One-Time Password Verification</h2>
                            <p>Hello,</p>
                            <p>You requested to log in without a password. Please use the following One-Time Password (OTP) to securely access your account.</p>
                            <div style='background-color: #f4f4f4; padding: 20px; text-align: center; border-radius: 8px; margin: 20px 0;'>
                                <span style='font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #1a1a1a;'>{$otp}</span>
                            </div>
                            <div style='text-align: center; margin: 30px 0;'>
                                <a href='" . BASE_URL . "auth/verify_otp.php' style='background-color: #D4AF37; color: #000000; padding: 12px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block;'>Verify OTP Now</a>
                            </div>
                            <p>This code will expire in <strong>15 minutes</strong> for security reasons.</p>
                            <p>If you did not request this OTP, please ignore this email.</p>
                            <p>Best regards,<br>La Elena Fishyalan Restaurant Team</p>
                        </div>
                    ";

                    $name = $user['first_name'] ?? 'User';
                    if (sendMail($email, $name, $subject, $htmlBody)) {
                        $_SESSION['otp_email'] = $email;
                        $_SESSION['otp_role'] = $match['role'];
                        header("Location: verify_otp.php");
                        exit;
                    } else {
                        $message = 'Failed to send OTP email. Please try again.';
                        $messageType = 'error';
                    }
                } else {
                    $message = 'Database error. Please try again later.';
                    $messageType = 'error';
                }
                } // end else (verified)
            } else {
                // Multiple matches — show role chooser
                $step = 'choose_role';
                $matchedRoles = $matches;
                $submittedEmail = $email;
            }
        } else {
            $message = 'Database connection failed.';
            $messageType = 'error';
        }
    } else {
        $message = 'Please enter your email address.';
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --gold-light: #f8e7a6;
            --gold-main: #b8860b;
            --gold-dark: #8f6507;
            --bg-deep: #f8f8f6;
            --bg-radial: radial-gradient(circle at 50% 0%, #ffffff 0%, #f3f2ee 100%);
            --card-bg: rgba(255, 255, 255, 0.96);
            --text-main: #24211b;
            --text-muted: #6f6a60;
            --border-gold: rgba(184, 134, 11, 0.28);
            --gold-gradient: linear-gradient(135deg, #a87508, #d7ae45);
            --input-bg: #ffffff;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

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

        @keyframes shine { to { background-position: 200% center; } }

        .login-container {
            width: 100%;
            max-width: 440px;
            background: var(--card-bg);
            backdrop-filter: blur(15px);
            border: 1px solid var(--border-gold);
            border-radius: 8px;
            box-shadow: 0 20px 50px rgba(61, 49, 25, 0.12), inset 0 1px 0 #fff;
            padding: 48px 40px;
            animation: slideUp 0.6s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
        }

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
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: 1px;
        }

        .login-header p {
            font-size: 14px;
            font-style: italic;
            font-family: 'Playfair Display', serif;
            color: var(--text-muted);
            margin: 0;
        }

        .form-group { margin-bottom: 24px; }

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

        .input-wrapper i.input-icon {
            position: absolute;
            left: 16px;
            color: #8a857b;
            font-size: 16px;
            transition: color 0.3s ease;
        }

        .form-control {
            width: 100%;
            padding: 14px 16px 14px 48px;
            background-color: var(--input-bg);
            border: 1px solid #dedbd3;
            border-radius: 4px;
            font-size: 14px;
            color: var(--text-main);
            font-family: 'Montserrat', sans-serif;
            transition: all 0.3s ease;
        }

        .form-control:focus {
            border-color: var(--gold-main);
            background-color: #fff;
            box-shadow: 0 0 0 3px rgba(184, 134, 11, 0.12);
            outline: none;
            color: var(--text-main);
        }

        .form-control:focus + i.input-icon { color: var(--gold-main); }
        .form-control::placeholder { color: #99958d; font-weight: 400; }

        input:-webkit-autofill,
        input:-webkit-autofill:hover,
        input:-webkit-autofill:focus,
        input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 30px #ffffff inset !important;
            -webkit-text-fill-color: var(--text-main) !important;
            transition: background-color 5000s ease-in-out 0s;
        }

        .btn-gold {
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
            margin: 16px 0 24px 0;
            box-shadow: 0 10px 20px rgba(143, 101, 7, 0.2);
        }

        .btn-gold:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 25px rgba(212, 175, 55, 0.2);
        }

        .back-link {
            display: block;
            text-align: center;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .back-link:hover {
            color: var(--gold-dark);
            text-shadow: none;
        }

        .alert-message {
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 24px;
            font-size: 13px;
            text-align: center;
            background: #fff3f3;
            color: #b42318;
            border: 1px solid #f4c7c3;
        }

        .alert-message.alert-warning {
            background: #fff8e7;
            color: #8f6507;
            border: 1px solid #efd58e;
        }

        /* Role chooser cards */
        .role-chooser-label {
            display: block;
            margin-bottom: 16px;
            color: var(--text-muted);
            font-weight: 500;
            font-size: 12px;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }

        .email-pill {
            display: block;
            text-align: center;
            margin-bottom: 24px;
            padding: 8px 16px;
            border-radius: 999px;
            background: #f8f7f4;
            border: 1px solid #e3e0d9;
            color: var(--text-main);
            font-size: 13px;
        }

        .role-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 24px;
        }

        .role-option input[type="radio"] { display: none; }

        .role-card {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px 20px;
            border: 1px solid #e0ddd5;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            background-color: var(--input-bg);
        }

        .role-card:hover {
            border-color: rgba(184, 134, 11, 0.5);
            background-color: #fffaf0;
            transform: translateX(4px);
        }

        .role-card .role-icon-wrap {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff7df;
            border: 1px solid rgba(184, 134, 11, 0.22);
            flex-shrink: 0;
        }

        .role-card .role-icon-wrap i {
            font-size: 18px;
            color: #806f48;
            transition: all 0.3s ease;
        }

        .role-card .role-info { flex: 1; }

        .role-card .role-info .role-title {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-main);
            margin-bottom: 2px;
            transition: all 0.3s ease;
        }

        .role-card .role-info .role-name {
            font-size: 12px;
            color: var(--text-muted);
            transition: all 0.3s ease;
        }

        .role-card .role-arrow {
            color: #938d80;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .role-option input[type="radio"]:checked + .role-card {
            background: #fff8e6;
            border-color: var(--gold-main);
            box-shadow: 0 0 0 3px rgba(184, 134, 11, 0.1);
        }

        .role-option input[type="radio"]:checked + .role-card .role-icon-wrap {
            background: var(--gold-gradient);
        }

        .role-option input[type="radio"]:checked + .role-card .role-icon-wrap i {
            color: #000;
        }

        .role-option input[type="radio"]:checked + .role-card .role-title {
            color: var(--gold-dark);
        }

        .role-option input[type="radio"]:checked + .role-card .role-arrow {
            color: var(--gold-main);
        }

        /* Match the bright coastal design used on the sign-in page. */
        :root {
            --gold-main: #e56b4f;
            --gold-light: #f7b486;
            --gold-dark: #d8573d;
            --gold-gradient: linear-gradient(90deg, #e56b4f, #f08462);
            --card-bg: rgba(255, 253, 248, 0.92);
            --input-bg: #eaf2ff;
            --text-main: #18333b;
            --text-muted: #63777a;
            --border-gold: rgba(20, 61, 74, 0.12);
        }

        body {
            background: linear-gradient(135deg, #f8f1e5 0%, #e5f2ef 50%, #f9e5d8 100%);
            padding: 0 20px;
        }

        .login-container {
            max-width: 460px;
            min-height: 0;
            padding: 42px 38px 34px;
            border-color: rgba(20, 61, 74, 0.09);
            border-radius: 5px;
            box-shadow: 0 22px 55px rgba(20, 61, 74, 0.14);
        }

        .login-container::before,
        .login-container::after {
            border-color: var(--gold-main);
        }

        .login-header { margin-bottom: 30px; }

        .login-header h2 {
            font-size: clamp(31px, 6vw, 40px);
            letter-spacing: 0.4px;
        }

        .metallic-text {
            background: none;
            color: #143d4a;
            -webkit-text-fill-color: #143d4a;
            animation: none;
        }

        .form-group { margin-bottom: 18px; }

        .form-group label {
            margin-bottom: 11px;
            font-size: 15px;
            letter-spacing: 1.35px;
        }

        .input-wrapper i.input-icon { left: 20px; font-size: 19px; color: #5d777c; }

        .form-control {
            height: 64px;
            padding: 14px 20px 14px 61px;
            border-color: rgba(20, 61, 74, 0.18);
            border-radius: 5px;
            font-size: 17px;
            color: #18333b;
        }

        .form-control:focus {
            background: #fff;
            color: #18333b;
            border-color: #5daeb1;
            box-shadow: 0 0 0 3px rgba(93, 174, 177, 0.17);
        }

        .form-control::placeholder { color: #91a0a2; }

        .btn-gold {
            padding: 16px 15px;
            margin: 26px 0 22px;
            border-radius: 5px;
            font-size: 17px;
            color: #fff;
            background: #ed7058;
            box-shadow: 0 6px 14px rgba(237, 112, 88, 0.18);
        }

        .btn-gold:hover {
            background: #df624b;
            box-shadow: 0 8px 16px rgba(237, 112, 88, 0.25);
        }

        .back-link { color: #63777a; font-size: 14px; }
        .back-link:hover { color: #d8573d; }

        .email-pill { background: #f4f8ff; border-color: rgba(20, 61, 74, 0.14); }
        .role-card { background: #f4f8ff; border-color: rgba(20, 61, 74, 0.16); }
        .role-card:hover { background: #fff7f3; border-color: rgba(229, 107, 79, 0.5); }
        .role-card .role-icon-wrap { background: #eaf2ff; border-color: rgba(20, 61, 74, 0.12); }
        .role-card .role-icon-wrap i { color: #5d777c; }
        .role-option input[type="radio"]:checked + .role-card { background: #fff4ef; border-color: var(--gold-main); }
        .role-option input[type="radio"]:checked + .role-card .role-title { color: #143d4a; }

        @media (max-width: 480px) {
            body { align-items: stretch; padding: 0; }
            .login-container { min-height: 100vh; padding: 62px 32px 40px; border: none; box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h2 class="metallic-text">Forgot Password</h2>
            <?php if ($step === 'email'): ?>
                <p>Enter your email to receive a secure OTP code</p>
            <?php elseif ($step === 'unverified'): ?>
                <p>Account verification required</p>
            <?php else: ?>
                <p>Multiple accounts found — select which one</p>
            <?php endif; ?>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert-message <?php echo ($step === 'unverified' || $messageType === 'warning') ? 'alert-warning' : ''; ?>">
                <i class="fas <?php echo ($step === 'unverified' || $messageType === 'warning') ? 'fa-shield-exclamation' : 'fa-exclamation-circle'; ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
            <?php if ($messageType === 'warning' && !empty($_SESSION['unverified_email'])): ?>
                <a href="verify_account.php?email=<?php echo urlencode($_SESSION['unverified_email']); ?>"
                   style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;margin-bottom:20px;padding:12px 16px;border-radius:6px;background:rgba(212,175,55,0.08);border:1px solid rgba(212,175,55,0.35);color:#D4AF37;text-decoration:none;font-size:13px;font-weight:600;letter-spacing:0.5px;transition:all 0.3s ease;"
                   onmouseover="this.style.background='rgba(212,175,55,0.15)'"
                   onmouseout="this.style.background='rgba(212,175,55,0.08)'">
                    <i class="fas fa-envelope-circle-check"></i>
                    Verify Customer Account Instead
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($step === 'email'): ?>
            <!-- STEP 1: Enter email -->
            <form action="forgot_password.php" method="POST">
                <div class="form-group">
                    <label>Email Address</label>
                    <div class="input-wrapper">
                        <input type="email" class="form-control" name="email" placeholder="Enter your registered email" required>
                        <i class="far fa-envelope input-icon"></i>
                    </div>
                </div>

                <button type="submit" class="btn-gold">
                    CONTINUE <i class="fas fa-arrow-right ms-2" style="font-size: 0.8rem;"></i>
                </button>
            </form>
        <?php elseif ($step === 'unverified'): ?>
            <!-- UNVERIFIED: Prompt user to verify their account first -->
            <div class="email-pill">
                <i class="far fa-envelope me-2"></i><?php echo htmlspecialchars($_SESSION['unverified_email'] ?? ''); ?>
            </div>
            <div style="text-align:center; padding: 8px 0 24px;">
                <div style="width:64px;height:64px;border-radius:50%;background:rgba(212,175,55,0.1);border:1px solid rgba(212,175,55,0.3);display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
                    <i class="fas fa-envelope-open-text" style="font-size:26px;color:#D4AF37;"></i>
                </div>
                <p style="color:#a3a3a3;font-size:13px;line-height:1.7;margin-bottom:24px;">
                    You need to verify your email address before you can reset your password.<br>
                    Please check your inbox for the verification email we sent when you registered.
                </p>
                <a href="verify_account.php?email=<?php echo urlencode($_SESSION['unverified_email'] ?? ''); ?>" class="btn-gold" style="display:inline-block;text-decoration:none;text-align:center;width:100%;">
                    VERIFY MY ACCOUNT <i class="fas fa-envelope-circle-check ms-2" style="font-size:0.8rem;"></i>
                </a>
            </div>
        <?php else: ?>
            <!-- STEP 2: Choose which account to send OTP to -->
            <div class="email-pill">
                <i class="far fa-envelope me-2"></i><?php echo htmlspecialchars($submittedEmail); ?>
            </div>

            <form action="forgot_password.php" method="POST" id="roleForm">
                <input type="hidden" name="chosen_email" value="<?php echo htmlspecialchars($submittedEmail); ?>">
                <input type="hidden" name="chosen_role" id="chosenRoleInput" value="">

                <label class="role-chooser-label">Select Account</label>
                <div class="role-options">
                    <?php foreach ($matchedRoles as $i => $match): ?>
                        <div class="role-option">
                            <input type="radio" id="role_<?php echo $i; ?>" name="role_select" value="<?php echo htmlspecialchars($match['role']); ?>" <?php echo $i === 0 ? 'checked' : ''; ?>>
                            <label for="role_<?php echo $i; ?>" class="role-card">
                                <div class="role-icon-wrap">
                                    <i class="fas <?php echo htmlspecialchars($match['icon']); ?>"></i>
                                </div>
                                <div class="role-info">
                                    <div class="role-title"><?php echo htmlspecialchars($match['label']); ?></div>
                                    <div class="role-name"><?php echo htmlspecialchars($match['name']); ?></div>
                                </div>
                                <i class="fas fa-chevron-right role-arrow"></i>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="btn-gold">
                    SEND OTP <i class="fas fa-paper-plane ms-2" style="font-size: 0.8rem;"></i>
                </button>
            </form>

            <a href="forgot_password.php" class="back-link" style="margin-bottom: 16px;">
                <i class="fas fa-arrow-left me-1"></i> Use a different email
            </a>
        <?php endif; ?>

        <a href="login.php" class="back-link">
            <i class="fas fa-arrow-left me-1"></i> Back to Sign In
        </a>
    </div>

    <script>
        // Sync the radio selection to the hidden input on submit
        const roleForm = document.getElementById('roleForm');
        if (roleForm) {
            roleForm.addEventListener('submit', function(e) {
                const selected = document.querySelector('input[name="role_select"]:checked');
                if (selected) {
                    document.getElementById('chosenRoleInput').value = selected.value;
                } else {
                    e.preventDefault();
                    alert('Please select an account.');
                }
            });
        }
    </script>
</body>
</html>
