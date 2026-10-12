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

// Creating administrator accounts is reserved for Super Admins.  This check
// must remain here (not only in the UI) to prevent direct POST requests.
if (!isSuperAdmin()) {
    header('Location: viewUsers.php?error=' . urlencode('Only Super Admins can create administrator accounts.'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: viewUsers.php?create_admin=1#admins');
    exit;
}

$database = new Database();
$db       = $database->connect();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name']  ?? '');
    $email      = strtolower(trim($_POST['email'] ?? ''));
    $phone      = trim($_POST['phone']    ?? '');
    $role       = $_POST['role']          ?? 'Admin';
    $password   = trim($_POST['password'] ?? '');

    if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email format.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $stmt = $db->prepare("SELECT email FROM admins WHERE email = :email");
        $stmt->execute([':email' => $email]);

        if ($stmt->rowCount() > 0) {
            $error = 'An admin account with this email already exists.';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmtInsert = $db->prepare("INSERT INTO admins (first_name, last_name, email, phone, role, password, created_at)
                                        VALUES (:fname, :lname, :email, :phone, :role, :password, NOW())");
            $stmtInsert->bindParam(':fname',    $first_name);
            $stmtInsert->bindParam(':lname',    $last_name);
            $stmtInsert->bindParam(':email',    $email);
            $stmtInsert->bindParam(':phone',    $phone);
            $stmtInsert->bindParam(':role',     $role);
            $stmtInsert->bindParam(':password', $hashedPassword);

            if ($stmtInsert->execute()) {
                $fullName = $first_name . ' ' . $last_name;
                // Resolve URL before heredoc so it actually evaluates
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
      <p style="margin:0 0 18px 0;color:#d1d5db;line-height:1.6;">An administrative account has been created for you. You have been assigned the role of <strong style="color:#d4af37;">$role</strong>.</p>
      <div style="background:#2a2a2a;border:1px solid #333;border-radius:12px;padding:16px 20px;margin-bottom:24px;">
        <div style="font-size:12px;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px;">Your Login Credentials</div>
        <p style="margin:0 0 8px 0;color:#fff;font-size:15px;"><strong>Email:</strong> <span style="color:#d1d5db;">$email</span></p>
        <p style="margin:0;color:#fff;font-size:15px;"><strong>Temporary Password:</strong> <span style="color:#d4af37;font-family:monospace;font-size:16px;">$password</span></p>
      </div>
      <p style="margin:0 0 24px 0;color:#d1d5db;line-height:1.6;">Please log in and update your password immediately to secure your account.</p>
      <div style="margin-bottom:24px;">
        <a href="$loginUrl" style="display:inline-block;background:#d4af37;color:#000;text-decoration:none;padding:12px 28px;border-radius:8px;font-size:14px;font-weight:bold;text-transform:uppercase;letter-spacing:0.5px;">Sign In Here</a>
      </div>
      <div style="margin-top:24px;padding-top:16px;border-top:1px solid #333;">
        <p style="margin:0 0 4px 0;color:#9ca3af;font-size:14px;">System Administrator Contact:</p>
        <p style="margin:0;color:#d4af37;font-size:14px;font-weight:600;">johncine.martil@nmsc.edu.ph</p>
      </div>
    </div>
    <div style="text-align:center;color:#6b7280;font-size:12px;margin-top:16px;">This email was sent automatically. Please do not reply.</div>
  </div>
</body>
</html>
HTML;
                try {
                    sendMail($email, $fullName, "Welcome to La Elena Fishyalan - Account Created", $emailHtml);
                } catch (Exception $e) {
                    error_log("Mailer Error: " . $e->getMessage());
                }

                $success  = 'Admin account created successfully! A welcome email has been sent.';
                $_POST    = [];
            } else {
                $error = 'Failed to create admin account. Please try again.';
            }
        }
    }
}

if ($success !== '') {
    header('Location: viewUsers.php?success=' . urlencode($success) . '#admins');
    exit;
}

if ($error !== '') {
    $_SESSION['create_admin_error'] = $error;
    $_SESSION['create_admin_form'] = [
        'first_name' => $first_name ?? '', 'last_name' => $last_name ?? '',
        'email' => $email ?? '', 'phone' => $phone ?? '', 'role' => $role ?? 'Admin',
    ];
    header('Location: viewUsers.php?create_admin=1#admins');
    exit;
}

$savedRole = $_POST['role'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Admin | Admin Panel</title>
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
            max-width: 660px;
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

        /* Eye toggle / suffix button */
        .btn-suffix {
            background: #333;
            border: 1px solid var(--border);
            border-left: none;
            color: #aaa;
            border-radius: 0 8px 8px 0;
            padding: 0 14px;
            transition: color .2s;
            cursor: pointer;
        }
        .btn-suffix:hover { color: var(--gold); background: #333; }
        .input-group .form-control.has-suffix { border-radius: 0; }

        /* ── ROLE BADGE SELECTOR ──────────────────────────── */
        .role-options { display: flex; gap: 10px; flex-wrap: wrap; }
        .role-option  { flex: 1; min-width: 120px; }
        .role-option input[type="radio"] { display: none; }
        .role-option label {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 14px 10px;
            border: 2px solid var(--border);
            border-radius: 10px;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            font-size: 0.88rem;
            font-weight: 600;
            color: #aaa;
            background: var(--input-bg);
            text-align: center;
            width: 100%;
        }
        .role-option label i { font-size: 1.3rem; }
        .role-option input[type="radio"]:checked + label {
            border-color: var(--gold);
            background: rgba(212,175,55,.1);
            color: var(--gold);
        }
        .role-option label:hover { border-color: #888; color: #ddd; }

        /* ── INFO / WARN BANNERS ──────────────────────────── */
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
            background: rgba(220,53,69,.07);
            border: 1px solid rgba(220,53,69,.25);
            border-radius: 8px;
            padding: .6rem .9rem;
            font-size: 0.82rem;
            color: #f08080;
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

        /* ── RESPONSIVE ───────────────────────────────────── */
        @media (max-width: 480px) {
            .form-card    { padding: 1.25rem 1rem; border-radius: 12px; }
            .page-wrapper { padding: 1rem .75rem 2rem; }
            .card-title-row { flex-direction: column; align-items: flex-start; }
            .back-btn { align-self: flex-end; }
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
                <i class="fas fa-user-shield me-1"></i>Super Admin Access
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
            <h2 class="text-gold">
                <i class="fas fa-user-shield me-2"></i>Create New Admin
            </h2>
            <a href="viewUsers.php" class="btn btn-outline-secondary btn-sm back-btn">
                <i class="fas fa-arrow-left me-1"></i>Back to Users
            </a>
        </div>

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="alert alert-danger bg-danger text-white border-0 py-2 d-flex align-items-center mb-3" role="alert">
                <i class="fas fa-exclamation-triangle me-2 flex-shrink-0"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success bg-success text-white border-0 py-2 d-flex align-items-center mb-3" role="alert">
                <i class="fas fa-check-circle me-2 flex-shrink-0"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Info banner -->
        <div class="info-banner mb-4">
            <i class="fas fa-info-circle"></i>
            <span>A welcome email with login credentials will be sent to the new admin immediately upon account creation.</span>
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

            <p class="section-label">Contact Details</p>

            <div class="mb-3">
                <label class="form-label" for="email">Email Address <span class="text-danger">*</span></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                    <input type="email" id="email" name="email" class="form-control"
                           placeholder="admin@example.com" required autocomplete="email"
                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                <small class="text-muted d-block mt-1">Must be unique. This will be the login email.</small>
            </div>

            <div class="mb-3">
                <label class="form-label" for="phone">Phone Number</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-phone"></i></span>
                    <input type="tel" id="phone" name="phone" class="form-control"
                           placeholder="+63 912 345 6789" autocomplete="tel"
                           value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                </div>
            </div>

            <p class="section-label">Role</p>

            <!-- Visual role selector -->
            <div class="role-options mb-4">
                <div class="role-option">
                    <input type="radio" id="role_admin" name="role" value="Admin"
                           <?php echo ($savedRole === 'Admin') ? 'checked' : ''; ?>>
                    <label for="role_admin">
                        <i class="fas fa-user-cog"></i>
                        Admin
                        <span style="font-size:.75rem;font-weight:400;color:#888;">Standard access</span>
                    </label>
                </div>
                <div class="role-option">
                    <input type="radio" id="role_superadmin" name="role" value="Super Admin"
                           <?php echo ($savedRole === 'Super Admin') ? 'checked' : ''; ?>>
                    <label for="role_superadmin">
                        <i class="fas fa-user-shield"></i>
                        Super Admin
                        <span style="font-size:.75rem;font-weight:400;color:#888;">Full privileges</span>
                    </label>
                </div>
            </div>

            <p class="section-label">Security</p>

            <div class="mb-4">
                <label class="form-label" for="password">
                    Password <span class="text-danger">*</span>
                </label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" id="password" name="password" class="form-control has-suffix"
                           placeholder="Min. 6 characters" required minlength="6"
                           value="<?php echo htmlspecialchars($_POST['password'] ?? ''); ?>">
                    <button type="button" class="btn-suffix" id="togglePwd" title="Show / hide password">
                        <i class="fas fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
                <div class="warn-banner">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>This is a sensitive action. The new admin will receive this password by email and must change it immediately after first login.</span>
                </div>
            </div>

            <!-- Submit -->
            <button type="submit" class="btn-submit">
                <i class="fas fa-user-plus me-2"></i>Create Administrator Account
            </button>

        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // ── Password visibility toggle ─────────────────────────
    document.getElementById('togglePwd').addEventListener('click', function () {
        const input   = document.getElementById('password');
        const icon    = document.getElementById('eyeIcon');
        const isHidden = input.type === 'password';
        input.type = isHidden ? 'text' : 'password';
        icon.classList.toggle('fa-eye',      !isHidden);
        icon.classList.toggle('fa-eye-slash', isHidden);
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
