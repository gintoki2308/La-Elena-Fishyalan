<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../config/Mailer.php';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    redirectByRole();
}

function buildVerificationEmailBody($fullName, $otp, $expiryLabel, $verificationUrl) {
    return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Verify Your Account</title>
</head>
<body style="margin:0;padding:0;background:#121212;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:600px;margin:0 auto;padding:24px;">
    <div style="background:#1e1e1e;border-radius:18px;padding:30px;box-shadow:0 14px 36px rgba(0,0,0,0.45);border:1px solid rgba(212,175,55,0.18);">
      <div style="margin-bottom:18px;">
        <span style="display:inline-block;background:#d4af37;color:#121212;padding:8px 14px;border-radius:999px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:0.5px;">
          La Elena Fishyalan
        </span>
      </div>

      <h2 style="margin:0 0 12px 0;color:#ffffff;">Your Verification Code</h2>
      <p style="margin:0 0 12px 0;color:#d1d5db;line-height:1.7;">Hi {$fullName},</p>
      <p style="margin:0 0 20px 0;color:#d1d5db;line-height:1.7;">
        Use the code below to verify your customer account and unlock your dashboard.
      </p>

      <div style="background:linear-gradient(135deg,#f7e7a9,#d4af37);border-radius:16px;padding:22px;margin-bottom:18px;text-align:center;">
        <div style="font-size:12px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:#5c460d;margin-bottom:10px;">Verification Code</div>
        <div style="font-size:36px;font-weight:800;letter-spacing:8px;color:#121212;">{$otp}</div>
      </div>

      <p style="margin:0 0 18px 0;color:#d1d5db;line-height:1.7;">
        This code expires in <strong style="color:#d4af37;">{$expiryLabel}</strong>.
      </p>

      <div style="margin-bottom:24px;">
        <a href="{$verificationUrl}" style="display:inline-block;background:#d4af37;color:#121212;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:bold;letter-spacing:0.5px;text-transform:uppercase;">
          Open Verification Page
        </a>
      </div>
    </div>
    <div style="text-align:center;color:#6b7280;font-size:12px;margin-top:16px;">
      This email was sent automatically. Please do not reply.
    </div>
  </div>
</body>
</html>
HTML;
}

function findCustomerForVerification($db, $email) {
    $stmt = $db->prepare("SELECT * FROM customer WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$verificationEmail = trim((string)($_GET['email'] ?? $_POST['email'] ?? $_SESSION['customer_verification_email'] ?? ''));
if ($verificationEmail !== '') {
    $_SESSION['customer_verification_email'] = $verificationEmail;
}

if ($verificationEmail === '') {
    header('Location: ' . app_url('auth/register.php'));
    exit;
}

$message = '';
$messageType = '';
$success = false;
$redirect = app_url('customerSide/customer-dashboard.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'verify';

    try {
        $database = new Database();
        $db = $database->connect();

        if (!$db) {
            throw new Exception('Database connection failed. Please try again later.');
        }

        $supportsIsVerified = dbTableHasColumn($db, 'customer', 'is_verified');
        $customer = findCustomerForVerification($db, $verificationEmail);

        if (!$customer) {
            throw new Exception('We could not find an account for that email.');
        }

        $status = strtoupper((string)($customer['account_status'] ?? 'ACTIVE'));
        if (in_array($status, ['DISABLED', 'REJECTED'], true)) {
            throw new Exception('This account cannot be verified automatically. Please contact support.');
        }

        if ($action === 'resend') {
            if (isCustomerVerifiedRecord($customer)) {
                throw new Exception('This account is already verified. Please sign in.');
            }

            $otp = sprintf('%06d', mt_rand(100000, 999999));
            $otpExpiry = date('Y-m-d H:i:s', time() + (15 * 60));
            $updateStmt = $db->prepare("
                UPDATE customer
                SET otp_token = :otp_token, otp_expiry = :otp_expiry, updated_at = NOW()
                WHERE member_id = :member_id
            ");
            $updateStmt->execute([
                ':otp_token' => $otp,
                ':otp_expiry' => $otpExpiry,
                ':member_id' => $customer['member_id'],
            ]);

            $fullName = trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''));
            $verificationUrl = app_url('auth/verify_account.php') . '?email=' . urlencode($verificationEmail);
            $mailSent = sendMail(
                $verificationEmail,
                $fullName !== '' ? $fullName : 'Customer',
                'Your Verification Code - La Elena Fishyalan',
                buildVerificationEmailBody($fullName !== '' ? $fullName : 'Customer', $otp, '15 minutes', $verificationUrl)
            );

            if (!$mailSent) {
                throw new Exception('We could not resend your OTP right now. Please try again.');
            }

            $_SESSION['customer_verification_member_id'] = (int)$customer['member_id'];
            $_SESSION['customer_verification_created_at'] = time();
            $message = 'A new verification code has been sent to your email.';
            $messageType = 'success';
        } else {
            $otp = trim((string)($_POST['otp'] ?? ''));

            if ($otp === '') {
                throw new Exception('Please enter the 6-digit OTP sent to your email.');
            }

            // --- NEW STRICT NUMERIC VALIDATION ---
            if (!ctype_digit($otp)) {
                throw new Exception('Security Alert: OTP must contain only numbers.');
            }

            if (strlen($otp) !== 6) {
                throw new Exception('Invalid Format: OTP must be exactly 6 digits.');
            }

            if (isCustomerVerifiedRecord($customer)) {
                $redirect = completeAuthenticatedSession($db, ROLE_CUSTOMER, $customer, false);
                unset(
                    $_SESSION['customer_verification_email'],
                    $_SESSION['customer_verification_member_id'],
                    $_SESSION['customer_verification_created_at']
                );
                $success = true;
            } else {
                $storedOtp = (string)($customer['otp_token'] ?? '');
                $expiryTime = strtotime((string)($customer['otp_expiry'] ?? ''));

                if ($storedOtp === '' || $storedOtp !== $otp || $expiryTime <= time()) {
                    throw new Exception('Invalid or expired OTP. Please try again or resend a new one.');
                }

                $updateParts = [
                    'otp_token = NULL',
                    'otp_expiry = NULL',
                    "account_status = 'ACTIVE'",
                    'updated_at = NOW()',
                ];

                if ($supportsIsVerified) {
                    $updateParts[] = 'is_verified = 1';
                }

                $verifyStmt = $db->prepare(
                    "UPDATE customer SET " . implode(', ', $updateParts) . " WHERE member_id = :member_id"
                );
                $verifyStmt->execute([':member_id' => $customer['member_id']]);

                $customer['account_status'] = 'ACTIVE';
                if ($supportsIsVerified) {
                    $customer['is_verified'] = 1;
                }

                $redirect = completeAuthenticatedSession($db, ROLE_CUSTOMER, $customer, false);
                unset(
                    $_SESSION['customer_verification_email'],
                    $_SESSION['customer_verification_member_id'],
                    $_SESSION['customer_verification_created_at']
                );
                $success = true;
            }
        }
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Account - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=Playfair+Display:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --gold-light: #fff1b8;
            --gold-main: #d7ad45;
            --gold-dark: #9a6e1a;
            --card-bg: rgba(22, 20, 16, 0.94);
            --text-main: #f8f5ed;
            --text-muted: #b4aea1;
            --border-gold: rgba(215, 173, 69, 0.32);
            --gold-gradient: linear-gradient(105deg, #b77d1c 0%, #e6c465 42%, #fff0af 52%, #d5a83a 100%);
            --input-bg: #100f0d;
        }

        * {
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
            background: #0a0908;
        }

        body {
            min-height: 100vh;
            margin: 0;
            padding: 32px 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Montserrat', sans-serif;
            background:
                radial-gradient(circle at 50% 8%, rgba(169, 119, 31, 0.17), transparent 28rem),
                radial-gradient(circle at 10% 90%, rgba(111, 76, 22, 0.12), transparent 26rem),
                #0a0908;
            color: var(--text-main);
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 18px;
            border: 1px solid rgba(215, 173, 69, 0.08);
            pointer-events: none;
        }

        .verify-card {
            width: 100%;
            max-width: 510px;
            background: var(--card-bg);
            backdrop-filter: blur(18px);
            border: 1px solid var(--border-gold);
            border-radius: 20px;
            box-shadow: 0 28px 80px rgba(0, 0, 0, 0.62), inset 0 1px 0 rgba(255, 255, 255, 0.04);
            padding: 44px;
            position: relative;
        }

        .verify-card::before,
        .verify-card::after {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            border: 1px solid var(--gold-main);
            opacity: 0.5;
        }

        .verify-card::before {
            top: 16px;
            left: 16px;
            border-right: none;
            border-bottom: none;
        }

        .verify-card::after {
            right: 16px;
            bottom: 16px;
            border-left: none;
            border-top: none;
        }

        .brand {
            text-align: center;
            margin-bottom: 28px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            color: var(--gold-light);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.8px;
            text-transform: uppercase;
        }

        .eyebrow::before {
            content: '';
            width: 22px;
            height: 1px;
            background: var(--gold-main);
        }

        .brand h1 {
            margin: 0 0 10px;
            font-family: 'Playfair Display', serif;
            font-size: clamp(30px, 7vw, 38px);
            font-weight: 700;
            background: var(--gold-gradient);
            background-size: 200% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand p {
            margin: 0;
            color: var(--text-muted);
            font-size: 14px;
            line-height: 1.65;
        }

        .email-pill {
            margin-top: 16px;
            display: inline-block;
            max-width: 100%;
            overflow-wrap: anywhere;
            padding: 9px 15px;
            border-radius: 999px;
            background: rgba(215, 173, 69, 0.1);
            border: 1px solid rgba(215, 173, 69, 0.22);
            color: var(--gold-light);
            font-size: 13px;
        }

        .journey-note {
            margin: 0 0 24px;
            padding: 18px 20px;
            border-radius: 12px;
            background: linear-gradient(135deg, rgba(215, 173, 69, 0.12), rgba(215, 173, 69, 0.045));
            border: 1px solid rgba(215, 173, 69, 0.2);
            text-align: left;
        }

        .journey-note h3 {
            margin: 0 0 7px;
            font-size: 13px;
            color: var(--gold-light);
            text-transform: uppercase;
            letter-spacing: 1.2px;
        }

        .journey-note p {
            margin: 0;
            color: var(--text-muted);
            font-size: 12px;
            line-height: 1.65;
        }

        .alert {
            border: none;
            border-radius: 10px;
        }

        .alert-success {
            background: rgba(25, 135, 84, 0.18);
            color: #d1f7e4;
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.18);
            color: #ffd7dc;
        }

        .form-label {
            color: var(--text-muted);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: 8px;
        }

        .form-control {
            background: var(--input-bg);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 10px;
            color: var(--text-main);
            text-align: center;
            font-size: clamp(24px, 6vw, 30px);
            letter-spacing: 0.42em;
            padding: 15px 14px 15px 0.42em;
            font-weight: 700;
            transition: border-color 160ms ease, box-shadow 160ms ease;
        }

        .form-control:focus {
            background: rgba(0, 0, 0, 0.58);
            border-color: var(--gold-main);
            box-shadow: 0 0 0 0.2rem rgba(212, 175, 55, 0.15);
            color: #fff;
        }

        .form-control::placeholder {
            letter-spacing: 6px;
            color: #666;
        }

        .btn-gold {
            width: 100%;
            border: none;
            border-radius: 10px;
            padding: 15px 18px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.8px;
            color: #111;
            background: var(--gold-gradient);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.28);
            transition: transform 160ms ease, box-shadow 160ms ease;
        }

        .btn-gold:hover {
            color: #111;
            transform: translateY(-1px);
            box-shadow: 0 16px 28px rgba(0, 0, 0, 0.38);
        }

        .secondary-actions {
            display: flex;
            gap: 10px;
            margin-top: 12px;
        }

        .secondary-actions form,
        .secondary-actions a {
            flex: 1;
        }

        .btn-secondary-dark {
            width: 100%;
            border-radius: 10px;
            padding: 12px 14px;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.16);
            color: var(--text-main);
            text-decoration: none;
            text-align: center;
            display: inline-block;
            font-size: 13px;
            font-weight: 600;
            transition: color 160ms ease, border-color 160ms ease, background 160ms ease;
        }

        .btn-secondary-dark:hover {
            color: var(--gold-light);
            border-color: var(--border-gold);
            background: rgba(215, 173, 69, 0.08);
        }

        .success-note {
            text-align: center;
        }

        .success-note h2 {
            font-family: 'Playfair Display', serif;
            color: var(--gold-light);
            margin-bottom: 10px;
        }

        .success-note p {
            color: var(--text-muted);
            line-height: 1.7;
        }

        @media (max-width: 480px) {
            .verify-card {
                padding: 38px 24px;
                border-radius: 16px;
            }

            body {
                padding: 16px;
                align-items: flex-start;
            }

            body::before { inset: 8px; }

            .secondary-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="verify-card">
        <?php if ($success): ?>
            <div class="success-note">
                <h2>Account Verified</h2>
                <p>Your email has been confirmed and your customer account is now active. We’re signing you in and sending you to your dashboard.</p>
                <a href="<?php echo htmlspecialchars($redirect); ?>" class="btn btn-gold mt-3">Continue to Dashboard</a>
            </div>
            <script>
                setTimeout(function () {
                    window.location.href = <?php echo json_encode($redirect); ?>;
                }, 1400);
            </script>
        <?php else: ?>
            <div class="brand">
                <div class="eyebrow">La Elena Fishyalan</div>
                <h1>Verify your email</h1>
                <p>Enter the six-digit code we sent to activate your customer account.</p>
                <div class="email-pill"><?php echo htmlspecialchars($verificationEmail); ?></div>
            </div>

            <div class="journey-note">
                <h3>Check your inbox</h3>
                <p>Your code expires after 15 minutes. Once verified, we’ll sign you in automatically. Need a new one? Use <strong>Resend Code</strong> below.</p>
            </div>

            <?php if ($message !== ''): ?>
                <div class="alert alert-<?php echo $messageType === 'success' ? 'success' : 'danger'; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="verify_account.php">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($verificationEmail); ?>">
                <input type="hidden" name="action" value="verify">
                <div class="mb-3">
                    <label for="otp" class="form-label">Verification Code</label>
                    <input
                        type="text"
                        class="form-control"
                        id="otp"
                        name="otp"
                        maxlength="6"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        autocomplete="one-time-code"
                        placeholder="000000"
                        required
                        autofocus
                    >
                </div>
                <button type="submit" class="btn btn-gold">Verify Account</button>
            </form>

            <div class="secondary-actions">
                <form method="POST" action="verify_account.php">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($verificationEmail); ?>">
                    <input type="hidden" name="action" value="resend">
                    <button type="submit" class="btn-secondary-dark">Resend Code</button>
                </form>
                <a href="login.php?email=<?php echo urlencode($verificationEmail); ?>" class="btn-secondary-dark">Back to Login</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
