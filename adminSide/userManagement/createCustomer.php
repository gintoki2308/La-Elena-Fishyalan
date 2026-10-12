<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';
require_once '../../config/Mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn() || !canManageBackOffice()) {
    header('Location: /adminSide/admin-dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: viewUsers.php?create_customer=1#customers');
    exit;
}

$database = new Database();
$db = $database->connect();

$error   = '';
$success = '';

function generateUniqueMembershipNumber($db) {
    $prefix      = 'MEM';
    $unique      = false;
    $attempts    = 0;
    $maxAttempts = 10;

    while (!$unique && $attempts < $maxAttempts) {
        $year              = date('Y');
        $random            = rand(1000, 9999);
        $membership_number = $prefix . $year . $random;

        $checkStmt = $db->prepare("SELECT member_id FROM customer WHERE membership_number = :membership_number LIMIT 1");
        $checkStmt->bindParam(':membership_number', $membership_number, PDO::PARAM_STR);
        $checkStmt->execute();

        if ($checkStmt->rowCount() == 0) $unique = true;
        $attempts++;
    }

    if (!$unique) $membership_number = $prefix . date('YmdHis') . rand(100, 999);
    return $membership_number;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name']);
    $lastName  = trim($_POST['last_name']);
    $email     = trim(strtolower($_POST['email']));
    $phone     = trim($_POST['phone']);

    if (empty($firstName) || empty($lastName) || empty($email)) {
        $error = "First Name, Last Name, and Email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } else {
        try {
            $checkStmt = $db->prepare("SELECT member_id FROM customer WHERE email = :email LIMIT 1");
            $checkStmt->execute([':email' => $email]);

            if ($checkStmt->rowCount() > 0) {
                $error = "A customer account with this email already exists.";
            } else {
                $rawPassword      = substr(bin2hex(random_bytes(8)), 0, 8);
                $hashedPassword   = password_hash($rawPassword, PASSWORD_DEFAULT);
                $membershipNumber = generateUniqueMembershipNumber($db);
                $now              = date('Y-m-d H:i:s');
                $status           = 'ACTIVE';

                $supportsIsVerified = dbTableHasColumn($db, 'customer', 'is_verified');
                $insertColumns = [
                    'email','first_name','last_name','password','phone','account_status',
                    'membership_number','current_points','lifetime_points',
                    'membership_date','created_at','updated_at'
                ];
                $insertValues = [
                    ':email',':first_name',':last_name',':password',':phone',':account_status',
                    ':membership_number','0','0',':membership_date',':created_at',':updated_at'
                ];
                if ($supportsIsVerified) { $insertColumns[] = 'is_verified'; $insertValues[] = '1'; }

                $db->prepare("INSERT INTO customer (" . implode(', ', $insertColumns) . ") VALUES (" . implode(', ', $insertValues) . ")")
                   ->execute([
                       ':email' => $email, ':first_name' => $firstName, ':last_name' => $lastName,
                       ':password' => $hashedPassword, ':phone' => $phone, ':account_status' => $status,
                       ':membership_number' => $membershipNumber, ':membership_date' => $now,
                       ':created_at' => $now, ':updated_at' => $now
                   ]);

                $fullName = $firstName . ' ' . $lastName;
                $loginUrl = htmlspecialchars(app_url('auth/login.php'), ENT_QUOTES, 'UTF-8');

                $welcomeHtml = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Welcome to La Elena Fishyalan</title></head>
<body style="margin:0;padding:0;background:#121212;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:600px;margin:0 auto;padding:24px;">
    <div style="background:#1e1e1e;border-radius:16px;padding:28px;box-shadow:0 10px 30px rgba(0,0,0,0.5);">
      <div style="margin-bottom:16px;">
        <span style="display:inline-block;background:#ffc107;color:#121212;padding:8px 14px;border-radius:999px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:0.5px;">♛ La Elena Fishyalan</span>
      </div>
      <h2 style="margin:8px 0 12px 0;color:#fff;">Welcome to Our Family!</h2>
      <p style="margin:0 0 12px 0;color:#d1d5db;line-height:1.6;">Hi $fullName,</p>
      <p style="margin:0 0 18px 0;color:#d1d5db;line-height:1.6;">An administrator has successfully created an exclusive membership account for you at <strong>La Elena Fishyalan</strong>. You can now log in, explore our menu, and start earning loyalty points!</p>
      <div style="background:#2a2a2a;padding:16px;border-radius:8px;margin-bottom:24px;border-left:4px solid #ffc107;">
        <p style="margin:0 0 8px 0;color:#fff;"><strong>Login Credentials:</strong></p>
        <p style="margin:0 0 4px 0;color:#d1d5db;">Email: <strong style="color:#fff;">$email</strong></p>
        <p style="margin:0;color:#d1d5db;">Temporary Password: <strong style="color:#ffc107;font-family:monospace;font-size:16px;">$rawPassword</strong></p>
      </div>
      <p style="margin:0 0 24px 0;color:#d1d5db;line-height:1.6;"><em>Please log in and change your password from your profile settings as soon as possible.</em></p>
      <div style="text-align:center;">
        <a href="$loginUrl" style="display:inline-block;background:#ffc107;color:#000;text-decoration:none;font-weight:bold;padding:12px 24px;border-radius:8px;font-size:16px;">Log Into Your Account</a>
      </div>
    </div>
    <div style="text-align:center;color:#6b7280;font-size:12px;margin-top:16px;">This email was sent automatically. Please do not reply.</div>
  </div>
</body>
</html>
HTML;

                sendMail($email, $fullName, "Welcome to La Elena Fishyalan!", $welcomeHtml);
                header('Location: viewUsers.php?success=' . urlencode('Customer account created successfully. Welcome email sent.') . '#customers');
                exit;
            }
        } catch (Exception $e) {
            error_log("Customer Creation Error: " . $e->getMessage());
            $error = "A database error occurred. Please try again.";
        }
    }
}

if ($error !== '') {
    $_SESSION['create_customer_error'] = $error;
    $_SESSION['create_customer_form'] = [
        'first_name' => $firstName ?? '', 'last_name' => $lastName ?? '',
        'email' => $email ?? '', 'phone' => $phone ?? '',
    ];
    header('Location: viewUsers.php?create_customer=1#customers');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Customer | Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --gold: #d4af37;
            --gold-hover: #b5952f;
            --dark-bg: #121212;
            --card-bg: #1e1e1e;
            --input-bg: #2a2a2a;
            --border: #444;
        }

        /* ── BASE ─────────────────────────────────────────── */
        body {
            background-color: var(--dark-bg);
            color: #e0e0e0;
            font-family: 'Segoe UI', sans-serif;
            min-height: 100vh;
        }

        .text-gold  { color: var(--gold) !important; }
        .btn-gold   { background-color: var(--gold); color: #000; font-weight: 600; border: none; transition: background .2s; }
        .btn-gold:hover { background-color: var(--gold-hover); color: #000; }

        /* ── NAVBAR ───────────────────────────────────────── */
        .navbar {
            background-color: #000 !important;
            border-bottom: 1px solid #333;
            min-height: 56px;
            padding: 0 1rem;
        }
        .navbar-brand {
            font-size: clamp(0.85rem, 2.5vw, 1rem);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: calc(100vw - 160px);
        }
        .navbar .btn-sm { font-size: 0.78rem; padding: 5px 10px; white-space: nowrap; }
        .navbar .role-badge { font-size: 0.78rem; white-space: nowrap; }

        /* ── PAGE WRAPPER ─────────────────────────────────── */
        .page-wrapper {
            min-height: calc(100vh - 56px);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 2rem 1rem 3rem;
        }

        /* ── FORM CARD ────────────────────────────────────── */
        .creation-card {
            background-color: var(--card-bg);
            border: 1px solid #333;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            width: 100%;
            max-width: 600px;
            padding: 2rem;
        }

        /* Card header */
        .card-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .75rem;
            margin-bottom: 1.5rem;
        }
        .card-title-row h2 {
            font-size: clamp(1.1rem, 4vw, 1.5rem);
            margin: 0;
        }

        /* ── INPUTS ───────────────────────────────────────── */
        .form-label {
            color: #aaa;
            font-weight: 500;
            font-size: 0.88rem;
            margin-bottom: 0.3rem;
        }
        .form-control {
            background-color: var(--input-bg);
            border: 1px solid var(--border);
            color: #fff;
            border-radius: 8px;
            padding: .55rem .9rem;
            font-size: 0.95rem;
            transition: border-color .2s, box-shadow .2s;
        }
        .form-control:focus {
            background-color: #333;
            border-color: var(--gold);
            color: #fff;
            box-shadow: 0 0 0 3px rgba(212,175,55,.2);
        }
        .form-control::placeholder { color: #666; }
        .input-group-text {
            background-color: #333;
            border: 1px solid var(--border);
            color: var(--gold);
            border-radius: 8px 0 0 8px;
        }
        .input-group .form-control { border-radius: 0 8px 8px 0; }

        /* ── INFO BANNER ──────────────────────────────────── */
        .info-banner {
            background: rgba(13,110,253,.08);
            border: 1px solid rgba(13,110,253,.3);
            border-radius: 10px;
            padding: .75rem 1rem;
            font-size: 0.85rem;
            color: #74b8f7;
            display: flex;
            align-items: flex-start;
            gap: .6rem;
        }
        .info-banner i { margin-top: 2px; flex-shrink: 0; }

        /* ── SUBMIT BUTTON ────────────────────────────────── */
        .btn-submit {
            background: var(--gold);
            color: #000;
            font-weight: 700;
            font-size: 1rem;
            border: none;
            border-radius: 10px;
            padding: .75rem 1.5rem;
            width: 100%;
            transition: background .2s, transform .15s;
            letter-spacing: .3px;
        }
        .btn-submit:hover   { background: var(--gold-hover); color: #000; transform: translateY(-1px); }
        .btn-submit:active  { transform: none; }

        /* ── SECTION DIVIDER ──────────────────────────────── */
        .section-label {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #666;
            margin-bottom: .75rem;
            margin-top: 1.25rem;
            padding-bottom: .4rem;
            border-bottom: 1px solid #2d2d2d;
        }

        /* ── RESPONSIVE TWEAKS ────────────────────────────── */
        @media (max-width: 480px) {
            .creation-card { padding: 1.25rem 1rem; border-radius: 12px; }
            .page-wrapper  { padding: 1rem .75rem 2rem; }

            /* Stack name fields on very small screens */
            .name-row { flex-direction: column; }
            .name-row > div { width: 100% !important; }

            .card-title-row { flex-direction: column; align-items: flex-start; }
            .back-btn { align-self: flex-end; }
        }

        @media (min-width: 481px) and (max-width: 575px) {
            .creation-card { padding: 1.5rem 1.25rem; }
        }
    </style>
</head>
<body>

<!-- ── NAVBAR ─────────────────────────────────────────────── -->
<nav class="navbar navbar-expand-lg navbar-dark sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand text-gold fw-bold" href="../admin-dashboard.php">
            <span class="d-none d-sm-inline">LA ELENA FISHYALAN ADMIN</span>
            <span class="d-sm-none">ADMIN</span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <span class="role-badge text-warning d-none d-md-inline">
                <i class="fas fa-user-shield me-1"></i>Admin Access
            </span>
            <a href="../../auth/logout.php" class="logout-link btn btn-sm btn-outline-danger">
                <i class="fas fa-sign-out-alt me-1 d-none d-sm-inline"></i>Logout
            </a>
        </div>
    </div>
</nav>

<!-- ── PAGE ───────────────────────────────────────────────── -->
<div class="page-wrapper">
    <div class="creation-card">

        <!-- Card header -->
        <div class="card-title-row">
            <h2 class="text-white">
                <i class="fas fa-user-plus me-2 text-gold"></i>Create Customer
            </h2>
            <a href="viewUsers.php#customers" class="btn btn-outline-secondary btn-sm back-btn">
                <i class="fas fa-arrow-left me-1"></i>Back to Users
            </a>
        </div>

        <!-- Alerts -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger bg-danger text-white border-0 py-2 d-flex align-items-center mb-3" role="alert">
                <i class="fas fa-exclamation-circle me-2 flex-shrink-0"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Info banner -->
        <div class="info-banner mb-4">
            <i class="fas fa-info-circle"></i>
            <span>A secure temporary password will be auto-generated and sent to the customer's email. They can change it after logging in.</span>
        </div>

        <!-- Form -->
        <form method="POST" action="createCustomer.php" novalidate>

            <p class="section-label">Personal Information</p>

            <!-- Name row — 2 columns on sm+, stacked on xs -->
            <div class="row g-3 mb-3 name-row">
                <div class="col-12 col-sm-6">
                    <label class="form-label" for="first_name">First Name <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" id="first_name" name="first_name" class="form-control"
                               placeholder="John" required autocomplete="given-name"
                               value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>">
                    </div>
                </div>
                <div class="col-12 col-sm-6">
                    <label class="form-label" for="last_name">Last Name <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" id="last_name" name="last_name" class="form-control"
                               placeholder="Doe" required autocomplete="family-name"
                               value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>">
                    </div>
                </div>
            </div>

            <p class="section-label">Contact Details</p>

            <!-- Email -->
            <div class="mb-3">
                <label class="form-label" for="email">Email Address <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                    <input type="email" id="email" name="email" class="form-control"
                           placeholder="customer@example.com" required autocomplete="email"
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                </div>
                <small class="text-muted d-block mt-1">This will be their login email. Must be unique.</small>
            </div>

            <!-- Phone -->
            <div class="mb-4">
                <label class="form-label" for="phone">
                    Phone Number <span class="text-muted fw-normal">(Optional)</span>
                </label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-phone"></i></span>
                    <input type="tel" id="phone" name="phone" class="form-control"
                           placeholder="+63 912 345 6789" autocomplete="tel"
                           value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                </div>
            </div>

            <!-- Submit -->
            <button type="submit" class="btn-submit">
                <i class="fas fa-paper-plane me-2"></i>Create Customer &amp; Send Invite
            </button>

        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // Logout confirmation
    document.querySelector('.logout-link')?.addEventListener('click', function(e) {
        e.preventDefault();
        const href = this.getAttribute('href');
        Swal.fire({
            title: 'Ready to leave?',
            text: 'You will be logged out of your session.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d4af37',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, logout',
            cancelButtonText: 'Cancel',
            background: '#1e1e1e',
            color: '#fff'
        }).then(result => { if (result.isConfirmed) window.location.href = href; });
    });
</script>
<script src="../../assets/js/phone-validator.js"></script>
</body>
</html>
