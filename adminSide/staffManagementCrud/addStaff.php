<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/Mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../userManagement/viewUsers.php?add_staff=1#staff');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name    = trim($_POST['first_name'] ?? '');
    $last_name     = trim($_POST['last_name'] ?? '');
    $email         = trim(strtolower($_POST['email'] ?? ''));
    $phone         = trim($_POST['phone'] ?? '');
    $role          = trim($_POST['role'] ?? '');
    $temp_password = $_POST['temp_password'] ?? '';
    $allowedRoles  = getStaffRoleOptions();

    if (empty($first_name) || empty($last_name) || empty($email) || empty($role) || empty($temp_password)) {
        $error = 'Please fill in all required fields.';
    } elseif (!in_array($role, $allowedRoles, true)) {
        $error = 'Please select a valid role.';
    } elseif (strlen($temp_password) < 6) {
        $error = 'Temporary password must be at least 6 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        $database = new Database();
        $db       = $database->connect();

        $checkStmt = $db->prepare("SELECT staff_id FROM staffs WHERE email = :email LIMIT 1");
        $checkStmt->execute([':email' => $email]);

        if ($checkStmt->rowCount() > 0) {
            $error = 'An account with this email already exists.';
        } else {
            $hashedPassword = password_hash($temp_password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO staffs (first_name, last_name, email, phone, role, password, account_status, hire_date)
                                  VALUES (:first_name, :last_name, :email, :phone, :role, :password, 'ACTIVE', CURDATE())");
            $stmt->bindParam(':first_name', $first_name);
            $stmt->bindParam(':last_name',  $last_name);
            $stmt->bindParam(':email',      $email);
            $stmt->bindParam(':phone',      $phone);
            $stmt->bindParam(':role',       $role);
            $stmt->bindParam(':password',   $hashedPassword);

            if ($stmt->execute()) {
                $newStaffId = $db->lastInsertId();

                $db->prepare("INSERT INTO audit_logs (admin_id, action, table_affected, record_id, ip_address, timestamp)
                              VALUES (:admin_id, 'ADD_STAFF', 'staffs', :record_id, :ip, NOW())")
                   ->execute([':admin_id' => $_SESSION['user_id'], ':record_id' => $newStaffId, ':ip' => $_SERVER['REMOTE_ADDR']]);

                $fullName = $first_name . ' ' . $last_name;
                // Resolve URL before heredoc — PHP tags don't execute inside heredoc strings
                $loginUrl = htmlspecialchars(app_url('auth/login.php'), ENT_QUOTES, 'UTF-8');

                $emailHtml = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>Welcome to La Elena Fishyalan</title></head>
<body style="margin:0;padding:0;background:#121212;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:600px;margin:0 auto;padding:24px;">
    <div style="background:#1e1e1e;border-radius:16px;padding:28px;box-shadow:0 10px 30px rgba(0,0,0,0.5);">
      <div style="margin-bottom:16px;">
        <span style="display:inline-block;background:#d4af37;color:#000;padding:8px 14px;border-radius:999px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:0.5px;">♛ La Elena Fishyalan</span>
      </div>
      <h2 style="margin:8px 0 12px 0;color:#fff;">Welcome to the Team!</h2>
      <p style="margin:0 0 12px 0;color:#d1d5db;line-height:1.6;">Hello $fullName,</p>
      <p style="margin:0 0 18px 0;color:#d1d5db;line-height:1.6;">An administrative account has been created for you as a <strong style="color:#fff;">$role</strong> at La Elena Fishyalan.</p>
      <div style="background:#2a2a2a;padding:16px;border-radius:8px;margin-bottom:24px;border-left:4px solid #d4af37;">
        <p style="margin:0 0 8px 0;color:#fff;"><strong>Login Credentials:</strong></p>
        <p style="margin:0 0 4px 0;color:#d1d5db;">Email: <strong style="color:#fff;">$email</strong></p>
        <p style="margin:0;color:#d1d5db;">Temporary Password: <strong style="color:#d4af37;font-family:monospace;font-size:16px;">$temp_password</strong></p>
      </div>
      <p style="margin:0 0 24px 0;color:#d1d5db;line-height:1.6;"><em>Please log in and update your password from your profile settings immediately.</em></p>
      <div style="text-align:center;">
        <a href="$loginUrl" style="display:inline-block;background:#d4af37;color:#000;text-decoration:none;font-weight:bold;padding:12px 28px;border-radius:8px;font-size:15px;">Sign In to Your Account</a>
      </div>
    </div>
    <div style="text-align:center;color:#6b7280;font-size:12px;margin-top:16px;">This email was sent automatically. Please do not reply.</div>
  </div>
</body>
</html>
HTML;

                sendMail($email, $fullName, "Welcome to La Elena Fishyalan - Account Created", $emailHtml);
                header('Location: ../userManagement/viewUsers.php?success=' . urlencode('Staff added successfully.') . '#staff');
                exit;
            } else {
                $error = 'Failed to add staff member. Please try again.';
            }
        }
    }
}

// Return validation errors to the staff-directory modal.
if ($error !== '') {
    $_SESSION['add_staff_error'] = $error;
    $_SESSION['add_staff_form'] = [
        'first_name' => $first_name ?? '',
        'last_name' => $last_name ?? '',
        'email' => $email ?? '',
        'phone' => $phone ?? '',
        'role' => $role ?? '',
        'temp_password' => $temp_password ?? '',
    ];
    header('Location: ../userManagement/viewUsers.php?add_staff=1#staff');
    exit;
}

// Pre-generate a password for the form default value
$generatedPassword = substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$"), 0, 8);
$savedPassword     = htmlspecialchars($_POST['temp_password'] ?? $generatedPassword);
$savedRole         = $_POST['role'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Staff | Admin Panel</title>
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

        .text-gold { color: var(--gold) !important; }

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
        .navbar .btn-sm { font-size: 0.78rem; padding: 5px 10px; }

        /* ── PAGE WRAPPER ─────────────────────────────────── */
        .page-wrapper {
            min-height: calc(100vh - 56px);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 2rem 1rem 3rem;
        }

        /* ── FORM CARD ────────────────────────────────────── */
        .form-card {
            background: var(--card-bg);
            border: 1px solid var(--gold);
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
            width: 100%;
            max-width: 620px;
            padding: 2rem;
        }

        /* Card header row */
        .card-title-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .75rem;
            margin-bottom: 1.5rem;
        }
        .card-title-row h2 { font-size: clamp(1.1rem, 4vw, 1.4rem); margin: 0; }

        /* ── SECTION LABEL ────────────────────────────────── */
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

        /* ── INPUTS ───────────────────────────────────────── */
        .form-label {
            font-weight: 600;
            color: #aaa;
            font-size: 0.88rem;
            margin-bottom: 0.3rem;
        }
        .form-control, .form-select {
            background-color: var(--input-bg);
            border: 1px solid var(--border);
            color: #fff;
            border-radius: 8px;
            padding: .55rem .9rem;
            font-size: 0.95rem;
            transition: border-color .2s, box-shadow .2s;
        }
        .form-control:focus, .form-select:focus {
            background-color: #333;
            border-color: var(--gold);
            color: #fff;
            box-shadow: 0 0 0 3px rgba(212,175,55,.2);
        }
        .form-control::placeholder { color: #666; }
        .form-select option { background: #2a2a2a; }

        .input-group-text {
            background-color: #333;
            border: 1px solid var(--border);
            color: var(--gold);
            border-radius: 8px 0 0 8px;
        }
        .input-group .form-control,
        .input-group .form-select { border-radius: 0 8px 8px 0; }

        /* Password toggle button */
        .input-group .btn-eye {
            background: #333;
            border: 1px solid var(--border);
            border-left: none;
            color: #aaa;
            border-radius: 0 8px 8px 0;
            padding: 0 12px;
            transition: color .2s;
        }
        .input-group .btn-eye:hover { color: var(--gold); }
        .input-group .form-control.has-eye { border-radius: 0; }

        /* ── INFO / WARNING BANNERS ───────────────────────── */
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

        .warn-banner {
            background: rgba(212,175,55,.07);
            border: 1px solid rgba(212,175,55,.25);
            border-radius: 8px;
            padding: .6rem .9rem;
            font-size: 0.82rem;
            color: #c8a84b;
            display: flex;
            align-items: flex-start;
            gap: .5rem;
            margin-top: .5rem;
        }
        .warn-banner i { margin-top: 2px; flex-shrink: 0; }

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
        .btn-submit:hover  { background: var(--gold-hover); color: #000; transform: translateY(-1px); }
        .btn-submit:active { transform: none; }

        /* ── RESPONSIVE TWEAKS ────────────────────────────── */
        @media (max-width: 480px) {
            .form-card     { padding: 1.25rem 1rem; border-radius: 12px; }
            .page-wrapper  { padding: 1rem .75rem 2rem; }
            .card-title-row { flex-direction: column; align-items: flex-start; }
            .back-btn { align-self: flex-end; }

            /* Stack two-column rows on xs */
            .row-2col > div { flex: 0 0 100%; max-width: 100%; }
        }

        @media (min-width: 481px) and (max-width: 575px) {
            .form-card { padding: 1.5rem 1.25rem; }
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
            <span class="text-warning d-none d-md-inline" style="font-size:.78rem;">
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
    <div class="form-card">

        <!-- Header -->
        <div class="card-title-row">
            <h2 class="text-white">
                <i class="fas fa-user-plus me-2 text-gold"></i>Add Staff Member
            </h2>
            <a href="../userManagement/viewUsers.php#staff" class="btn btn-outline-secondary btn-sm back-btn">
                <i class="fas fa-arrow-left me-1"></i>Back to Directory
            </a>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-danger bg-danger text-white border-0 py-2 d-flex align-items-center mb-3" role="alert">
                <i class="fas fa-exclamation-triangle me-2 flex-shrink-0"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Info banner -->
        <div class="info-banner mb-4">
            <i class="fas fa-info-circle"></i>
            <span>An email with the staff member's login credentials will be sent immediately upon account creation.</span>
        </div>

        <!-- Form -->
        <form method="POST" novalidate>

            <p class="section-label">Personal Information</p>

            <div class="row g-3 mb-3 row-2col">
                <div class="col-12 col-sm-6">
                    <label class="form-label" for="first_name">First Name <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" id="first_name" name="first_name" class="form-control"
                               placeholder="John" required autocomplete="given-name"
                               value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                    </div>
                </div>
                <div class="col-12 col-sm-6">
                    <label class="form-label" for="last_name">Last Name <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" id="last_name" name="last_name" class="form-control"
                               placeholder="Doe" required autocomplete="family-name"
                               value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <p class="section-label">Contact &amp; Role</p>

            <div class="mb-3">
                <label class="form-label" for="email">Email Address <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                    <input type="email" id="email" name="email" class="form-control"
                           placeholder="staff@example.com" required autocomplete="email"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                <small class="text-muted d-block mt-1">This will be their login email. Must be unique.</small>
            </div>

            <div class="row g-3 mb-3 row-2col">
                <div class="col-12 col-sm-6">
                    <label class="form-label" for="phone">Phone Number</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-phone"></i></span>
                        <input type="tel" id="phone" name="phone" class="form-control"
                               placeholder="+63 912 345 6789" autocomplete="tel"
                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                    </div>
                </div>
                <div class="col-12 col-sm-6">
                    <label class="form-label" for="role">Role <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-id-badge"></i></span>
                        <select id="role" name="role" class="form-select" required>
                            <?php
                            $roles = getStaffRoleOptions();
                            foreach ($roles as $r):
                            ?>
                            <option value="<?php echo htmlspecialchars($r); ?>"
                                <?php echo ($savedRole === $r) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <p class="section-label">Security</p>

            <div class="mb-4">
                <label class="form-label" for="temp_password">
                    Temporary Password <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="text" id="temp_password" name="temp_password" class="form-control has-eye"
                           value="<?php echo $savedPassword; ?>" required minlength="6">
                    <button type="button" class="btn-eye" id="togglePwdBtn" title="Regenerate password">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
                <div class="warn-banner">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>You may keep the auto-generated password or set a custom one (min. 6 characters). The staff member will receive it by email and should change it immediately after logging in.</span>
                </div>
            </div>

            <!-- Submit -->
            <button type="submit" class="btn-submit">
                <i class="fas fa-user-plus me-2"></i>Create Staff Account
            </button>

        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // ── Regenerate password button ─────────────────────────
    document.getElementById('togglePwdBtn').addEventListener('click', function () {
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$';
        let pwd = '';
        for (let i = 0; i < 8; i++) pwd += chars.charAt(Math.floor(Math.random() * chars.length));
        document.getElementById('temp_password').value = pwd;

        // Brief visual confirmation
        const icon = this.querySelector('i');
        icon.classList.replace('fa-sync-alt', 'fa-check');
        icon.style.color = '#2ecc71';
        setTimeout(() => { icon.classList.replace('fa-check', 'fa-sync-alt'); icon.style.color = ''; }, 1200);
    });

    // ── Logout confirmation ────────────────────────────────
    document.querySelector('.logout-link')?.addEventListener('click', function (e) {
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
