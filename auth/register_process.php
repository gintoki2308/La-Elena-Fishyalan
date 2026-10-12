<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

while (ob_get_level()) {
    ob_end_clean();
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/config.php';
require_once '../config/Database.php';
require_once '../config/Mailer.php';

header('Content-Type: application/json');

function sendResponse($success, $message, $extraData = [])
{
    $response = array_merge(['success' => $success, 'message' => $message], $extraData);
    echo json_encode($response);
    exit;
}

function generateUniqueMembershipNumber($db)
{
    $prefix = 'MEM';
    $attempts = 0;

    // A four-digit suffix only provides 9,000 values per year. A collision on
    // this unique field was being incorrectly reported as a duplicate email.
    while ($attempts < 10) {
        $membershipNumber = $prefix . date('Y') . strtoupper(bin2hex(random_bytes(6)));
        $checkStmt = $db->prepare("SELECT member_id FROM customer WHERE membership_number = :membership_number LIMIT 1");
        $checkStmt->execute([':membership_number' => $membershipNumber]);

        if ($checkStmt->fetchColumn() === false) {
            return $membershipNumber;
        }

        $attempts++;
    }

    throw new RuntimeException('Unable to generate a unique membership number.');
}

function generateVerificationOtp()
{
    return sprintf('%06d', mt_rand(100000, 999999));
}

function prepareCustomerVerificationSession($memberId, $email)
{
    $_SESSION['customer_verification_member_id'] = (int)$memberId;
    $_SESSION['customer_verification_email'] = $email;
    $_SESSION['customer_verification_created_at'] = time();
}

function buildCustomerVerificationEmail($fullName, $otp, $expiryLabel, $verificationUrl)
{
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

      <h2 style="margin:0 0 12px 0;color:#ffffff;">Verify Your Account</h2>
      <p style="margin:0 0 12px 0;color:#d1d5db;line-height:1.7;">Hi {$fullName},</p>
      <p style="margin:0 0 20px 0;color:#d1d5db;line-height:1.7;">
        Welcome to La Elena Fishyalan. Enter the verification code below to activate your customer account and complete sign in.
      </p>

      <div style="background:linear-gradient(135deg,#f7e7a9,#d4af37);border-radius:16px;padding:22px;margin-bottom:18px;text-align:center;">
        <div style="font-size:12px;font-weight:700;letter-spacing:1.6px;text-transform:uppercase;color:#5c460d;margin-bottom:10px;">Your Verification Code</div>
        <div style="font-size:36px;font-weight:800;letter-spacing:8px;color:#121212;">{$otp}</div>
      </div>

      <p style="margin:0 0 18px 0;color:#d1d5db;line-height:1.7;">
        This code expires in <strong style="color:#d4af37;">{$expiryLabel}</strong>.
      </p>

      <div style="margin-bottom:24px;">
        <a href="{$verificationUrl}" style="display:inline-block;background:#d4af37;color:#121212;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:bold;letter-spacing:0.5px;text-transform:uppercase;">
          Verify Account
        </a>
      </div>

      <div style="margin-top:24px;padding-top:16px;border-top:1px solid #333;">
        <p style="margin:0;color:#8f949b;font-size:13px;line-height:1.6;">
          If you did not create this account, you can safely ignore this email.
        </p>
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

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if ($input === null && json_last_error() !== JSON_ERROR_NONE) {
        sendResponse(false, 'Invalid JSON input: ' . json_last_error_msg());
    }

    $requiredFields = [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'Email',
        'password' => 'Password',
    ];

    $missingFields = [];
    foreach ($requiredFields as $field => $label) {
        if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
            $missingFields[] = $label;
        }
    }

    $purok = isset($input['purok']) ? trim((string)$input['purok']) : '';
    $barangay = isset($input['barangay']) ? trim((string)$input['barangay']) : '';
    $municipality = isset($input['municipality']) ? trim((string)$input['municipality']) : '';
    $province = isset($input['province']) ? trim((string)$input['province']) : '';
    $providedAddress = isset($input['address']) ? trim((string)$input['address']) : '';

    if ($purok === '' || $barangay === '' || $municipality === '' || $province === '') {
        if ($providedAddress === '') {
            $missingFields[] = 'Purok/Sitio';
            $missingFields[] = 'Barangay';
            $missingFields[] = 'Municipality/City';
            $missingFields[] = 'Province';
        }
    }

    if (!empty($missingFields)) {
        sendResponse(false, implode(', ', array_unique($missingFields)) . ' is required.');
    }

    $firstName = trim((string)$input['first_name']);
    $lastName = trim((string)$input['last_name']);
    $email = trim(strtolower((string)$input['email']));
    $password = (string)$input['password'];
    $phone = isset($input['phone_number']) ? trim((string)$input['phone_number']) : '';
    $address = $providedAddress !== ''
        ? $providedAddress
        : trim(implode(', ', array_filter([$purok, $barangay, $municipality, $province], function ($value) {
            return $value !== '';
        })));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendResponse(false, 'Please enter a valid email address.');
    }

    if (strlen($password) < 6) {
        sendResponse(false, 'Password must be at least 6 characters long.');
    }

    if (strlen($firstName) < 2 || strlen($lastName) < 2) {
        sendResponse(false, 'First name and last name must be at least 2 characters long.');
    }

    if (!preg_match('/^[A-Za-z]+$/', $firstName) || !preg_match('/^[A-Za-z]+$/', $lastName)) {
        sendResponse(false, 'First name and last name must contain letters only.');
    }

    if (mb_strlen($address) > 500) {
        sendResponse(false, 'Address must not exceed 500 characters.');
    }

    foreach ([
        'Purok/Sitio' => $purok,
        'Barangay' => $barangay,
        'Municipality/City' => $municipality,
        'Province' => $province,
    ] as $label => $value) {
        if (mb_strlen($value) > 100) {
            sendResponse(false, $label . ' must not exceed 100 characters.');
        }
    }

    $database = new Database();
    $db = $database->connect();

    if (!$db) {
        sendResponse(false, 'Database connection failed. Please try again later.');
    }

    $supportsIsVerified = dbTableHasColumn($db, 'customer', 'is_verified');
    $supportsAddressComponents = dbTableHasColumn($db, 'customer', 'purok')
        && dbTableHasColumn($db, 'customer', 'barangay')
        && dbTableHasColumn($db, 'customer', 'municipality')
        && dbTableHasColumn($db, 'customer', 'province');
    $registrationStatus = 'PENDING'; // Always PENDING until email is verified
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $otp = generateVerificationOtp();
    $otpExpiry = date('Y-m-d H:i:s', time() + (15 * 60));
    $verificationUrl = app_url('auth/verify_account.php') . '?email=' . urlencode($email);
    $fullName = trim($firstName . ' ' . $lastName);

    $checkStmt = $db->prepare("SELECT * FROM customer WHERE email = :email LIMIT 1");
    $checkStmt->execute([':email' => $email]);
    $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($existingUser) {
        $existingStatus = strtoupper((string)($existingUser['account_status'] ?? 'ACTIVE'));
        $existingVerified = $supportsIsVerified
            ? ((int)($existingUser['is_verified'] ?? 0) === 1)
            : ($existingStatus === 'ACTIVE');

        if ($existingStatus === 'REJECTED') {
            $deleteStmt = $db->prepare("DELETE FROM customer WHERE member_id = :member_id");
            $deleteStmt->execute([':member_id' => $existingUser['member_id']]);
            $existingUser = null;
        } elseif ($existingStatus === 'DISABLED') {
            sendResponse(false, 'This account is not available for self-registration. Please contact support.');
        } elseif ($existingVerified) {
            sendResponse(false, 'This email is already registered as a customer account. Please sign in instead.', [
                'redirect' => app_url('auth/login.php') . '?email=' . urlencode($email),
                'action_label' => 'Sign In Instead',
            ]);
        } else {
            $membershipNumber = !empty($existingUser['membership_number'])
                ? $existingUser['membership_number']
                : generateUniqueMembershipNumber($db);

            $updateParts = [
                'first_name = :first_name',
                'last_name = :last_name',
                'password = :password',
                'phone = :phone',
                'address = :address',
                'account_status = :account_status',
                'membership_number = :membership_number',
                'otp_token = :otp_token',
                'otp_expiry = :otp_expiry',
                'updated_at = :updated_at',
            ];

            if ($supportsIsVerified) {
                $updateParts[] = 'is_verified = 0';
            }

            if ($supportsAddressComponents) {
                $updateParts[] = 'purok = :purok';
                $updateParts[] = 'barangay = :barangay';
                $updateParts[] = 'municipality = :municipality';
                $updateParts[] = 'province = :province';
            }

            $updateSql = "UPDATE customer SET " . implode(', ', $updateParts) . " WHERE member_id = :member_id";
            $updateStmt = $db->prepare($updateSql);
            $updateParams = [
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':password' => $hashedPassword,
                ':phone' => $phone,
                ':address' => $address,
                ':account_status' => $registrationStatus,
                ':membership_number' => $membershipNumber,
                ':otp_token' => $otp,
                ':otp_expiry' => $otpExpiry,
                ':updated_at' => date('Y-m-d H:i:s'),
                ':member_id' => $existingUser['member_id'],
            ];

            if ($supportsAddressComponents) {
                $updateParams[':purok'] = $purok;
                $updateParams[':barangay'] = $barangay;
                $updateParams[':municipality'] = $municipality;
                $updateParams[':province'] = $province;
            }

            $updateStmt->execute($updateParams);

            $memberId = (int)$existingUser['member_id'];
            $emailSent = sendMail(
                $email,
                $fullName,
                'Your Verification Code - La Elena Fishyalan',
                buildCustomerVerificationEmail($fullName, $otp, '15 minutes', $verificationUrl)
            );

            if (!$emailSent) {
                sendResponse(false, 'We created your account, but the verification email could not be sent. Please try again.');
            }

            prepareCustomerVerificationSession($memberId, $email);
            sendResponse(true, 'We sent a fresh verification code to your email.', [
                'redirect' => $verificationUrl,
                'email' => $email,
                'member_id' => $memberId,
            ]);
        }
    }

    $membershipNumber = generateUniqueMembershipNumber($db);
    $now = date('Y-m-d H:i:s');

    $insertColumns = [
        'email',
        'first_name',
        'last_name',
        'password',
        'phone',
        'address',
        'account_status',
        'membership_number',
        'current_points',
        'lifetime_points',
        'membership_date',
        'created_at',
        'updated_at',
        'otp_token',
        'otp_expiry',
    ];

    $insertValues = [
        ':email',
        ':first_name',
        ':last_name',
        ':password',
        ':phone',
        ':address',
        ':account_status',
        ':membership_number',
        '0',
        '0',
        ':membership_date',
        ':created_at',
        ':updated_at',
        ':otp_token',
        ':otp_expiry',
    ];

    if ($supportsIsVerified) {
        $insertColumns[] = 'is_verified';
        $insertValues[] = '0';
    }

    if ($supportsAddressComponents) {
        $insertColumns[] = 'purok';
        $insertColumns[] = 'barangay';
        $insertColumns[] = 'municipality';
        $insertColumns[] = 'province';
        $insertValues[] = ':purok';
        $insertValues[] = ':barangay';
        $insertValues[] = ':municipality';
        $insertValues[] = ':province';
    }

    $insertSql = "INSERT INTO customer (" . implode(', ', $insertColumns) . ")
                  VALUES (" . implode(', ', $insertValues) . ")";

    $insertStmt = $db->prepare($insertSql);
    $insertParams = [
        ':email' => $email,
        ':first_name' => $firstName,
        ':last_name' => $lastName,
        ':password' => $hashedPassword,
        ':phone' => $phone,
        ':address' => $address,
        ':account_status' => $registrationStatus,
        ':membership_number' => $membershipNumber,
        ':membership_date' => $now,
        ':created_at' => $now,
        ':updated_at' => $now,
        ':otp_token' => $otp,
        ':otp_expiry' => $otpExpiry,
    ];

    if ($supportsAddressComponents) {
        $insertParams[':purok'] = $purok;
        $insertParams[':barangay'] = $barangay;
        $insertParams[':municipality'] = $municipality;
        $insertParams[':province'] = $province;
    }

    $insertStmt->execute($insertParams);

    $memberId = (int)$db->lastInsertId();
    $emailSent = sendMail(
        $email,
        $fullName,
        'Verify Your Account - La Elena Fishyalan',
        buildCustomerVerificationEmail($fullName, $otp, '15 minutes', $verificationUrl)
    );

    if (!$emailSent) {
        sendResponse(false, 'Your account was created, but we could not send the verification email. Please try registering again.');
    }

    prepareCustomerVerificationSession($memberId, $email);
    sendResponse(true, 'Your account was created. Enter the OTP we sent to your email to verify it.', [
        'redirect' => $verificationUrl,
        'email' => $email,
        'member_id' => $memberId,
        'membership_number' => $membershipNumber,
    ]);
} catch (PDOException $e) {
    error_log("Database error in register_process.php: " . $e->getMessage());

    if ($e->getCode() == 23000) {
        // SQLSTATE 23000 covers each unique field. Tell the user to sign in
        // only when this exact email actually exists.
        try {
            $emailExistsStmt = $db->prepare("SELECT member_id FROM customer WHERE email = :email LIMIT 1");
            $emailExistsStmt->execute([':email' => $email ?? '']);

            if ($emailExistsStmt->fetchColumn() !== false) {
                sendResponse(false, 'This email is already registered as a customer account. Please sign in instead.', [
                    'redirect' => app_url('auth/login.php') . '?email=' . urlencode($email ?? ''),
                    'action_label' => 'Sign In Instead',
                ]);
            }
        } catch (PDOException $lookupError) {
            error_log("Duplicate-email lookup failed in register_process.php: " . $lookupError->getMessage());
        }

        sendResponse(false, 'We could not create the account because one of its details is already in use. Please try again.');
    }

    sendResponse(false, 'A database error occurred. Please try again later.');
} catch (Exception $e) {
    error_log("General error in register_process.php: " . $e->getMessage());
    sendResponse(false, 'An unexpected error occurred. Please try again.');
}
