<?php

require_once '../config/config.php';
require_once '../config/Database.php';
require_once '../config/Mailer.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

$success_msg = null;
$error = null;

// ---------- HANDLE POST ACTIONS ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];

        // 1. Password Update
        if ($action === 'update_password') {
            $current_pw = $_POST['current_password'];
            $new_pw = $_POST['new_password'];
            $confirm_pw = $_POST['confirm_password'];

            if ($new_pw !== $confirm_pw) throw new Exception("New passwords do not match.");
            if (strlen($new_pw) < 6) throw new Exception("Password must be at least 6 characters.");

            $stmt = $db->prepare("SELECT password, email, first_name FROM admins WHERE admin_id = :id");
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // Handle legacy unhashed demo passwords
            $pw_match = false;
            if ($row) {
                $pw_match = passwordMatchesStoredValue($current_pw, $row['password'] ?? null);
            }

            if ($pw_match) {
                $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
                $upd = $db->prepare("UPDATE admins SET password = :pw WHERE admin_id = :id");
                $upd->execute([':pw' => $hashed, ':id' => $_SESSION['user_id']]);
                
                // Try updating last_password_change if column exists
                try {
                    $db->exec("UPDATE admins SET last_password_change = NOW() WHERE admin_id = " . (int)$_SESSION['user_id']);
                } catch(Exception $e) {}

                $success_msg = "Password updated successfully.";
                
                // Mail Confirmation
                $subject = "Security Alert: Admin Password Changed - La Elena Fishyalan";
                $body = "Hello {$row['first_name']}, your admin portal password was recently changed. If you did not do this, contact the IT administrator immediately.";
                sendMail($row['email'], $row['first_name'], $subject, "<h2>Security Alert</h2><p>$body</p>");
            } else {
                throw new Exception("Incorrect current password.");
            }
        }

        // 2. Email Change Verification
        if ($action === 'request_email_change') {
            $new_email = trim($_POST['new_email']);
            if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) throw new Exception("Invalid email format.");
            
            // Check uniqueness across system
            $chk = $db->prepare("
                SELECT admin_id FROM admins WHERE email = :e AND admin_id != :id 
                UNION SELECT staff_id FROM staffs WHERE email = :e 
                UNION SELECT member_id FROM customer WHERE email = :e
            ");
            $chk->execute([':e' => $new_email, ':id' => $_SESSION['user_id']]);
            if ($chk->rowCount() > 0) throw new Exception("Email already exists in the system.");
            
            $token = sprintf("%06d", mt_rand(100000, 999999));
            $upd = $db->prepare("UPDATE admins SET pending_email = :e, email_token = :t WHERE admin_id = :id");
            $upd->execute([':e' => $new_email, ':t' => $token, ':id' => $_SESSION['user_id']]);
            
            $subject = "Admin Profile: Email Change OTP";
            sendMail($new_email, $_SESSION['first_name'] ?? 'Admin', $subject, "<h3>Verify Your Email</h3><p>Your verification code is: <strong>$token</strong></p>");
            
            header('Location: verify_email_change.php');
            exit;
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ---------- FETCH DATA ----------
$stmt = $db->prepare("SELECT * FROM admins WHERE admin_id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

$displayName = $admin['display_name'] ?? ($admin['first_name'] . ' ' . $admin['last_name']);
$avatarSrc = getProfileAvatar($admin['profile_picture'] ?? '', $admin['first_name'] ?? '', $admin['last_name'] ?? '');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin-dashboard.css">
    <style>
        :root { --gold: #d4af37; --dark-bg: #121212; --card-bg: #1e1e1e; }
        body { background-color: var(--dark-bg); color: #fff; font-family: 'Montserrat', sans-serif; }
        .text-gold { color: var(--gold); }
        .btn-gold { background-color: var(--gold); color: #000; font-weight: 600; border: none; }
        .premium-card { background: var(--card-bg); border: 1px solid #333; border-radius: 12px; }
        .form-control { background: #2a2a2a; border: 1px solid #444; color: #fff; }
        .form-control:focus { background: #333; border-color: var(--gold); color: #fff; box-shadow: none; }

        .password-input-group .form-control { border-right: 0; }
        .password-toggle {
            background: #2a2a2a;
            border: 1px solid #444;
            border-left: 0;
            color: #aaa;
            transition: color 0.2s, border-color 0.2s, background-color 0.2s;
        }
        .password-toggle:hover,
        .password-toggle:focus {
            background: #333;
            border-color: var(--gold);
            color: var(--gold);
            box-shadow: none;
        }
        .password-input-group:focus-within .password-toggle {
            border-color: var(--gold);
            color: var(--gold);
        }

        .back-button {
            color: #17323a;
            background: #fff;
            border: 1px solid #17323a;
            font-weight: 600;
        }
        .back-button:hover,
        .back-button:focus {
            color: #fff;
            background: #17323a;
            border-color: #17323a;
            box-shadow: none;
        }
        
        .strength-meter { height: 6px; background: #333; border-radius: 3px; overflow: hidden; margin-top: 8px;}
        .strength-bar { height: 100%; transition: 0.3s; width: 0; }
        .weak { background: #ff4757; width: 30%; }
        .medium { background: #ffa502; width: 60%; }
        .strong { background: #2ed573; width: 100%; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top" style="border-bottom: 1px solid #333;">
        <div class="container-fluid">
            <a class="navbar-brand" href="admin-dashboard.php">Admin Panel</a>
            <div class="ms-auto d-flex align-items-center">
                <div class="dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown">
                        <img src="<?php echo $avatarSrc; ?>" alt="Avatar" class="rounded-circle me-2" width="35" height="35" style="object-fit: cover; border: 1.5px solid var(--gold);">
                        <span><?php echo htmlspecialchars($displayName); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark shadow" aria-labelledby="adminDropdown">
                        <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-tie me-2"></i>Executive Profile</a></li>
                        <li><a class="dropdown-item active text-gold" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="logout-link dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="container my-5" style="max-width: 900px;">
        <div class="mb-5">
            <a href="admin-dashboard.php" class="btn back-button mb-4">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
            <h1 class="display-6"><i class="fas fa-cog me-3 text-gold"></i>Account Settings</h1>
            <p class="text-gold opacity-75">Manage your credentials and security preferences.</p>
        </div>

        <?php if($success_msg): ?>
            <div class="alert alert-success alert-dismissible bg-success text-white border-0 py-3 shadow" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo $success_msg; ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if($error): ?>
            <div class="alert alert-danger alert-dismissible bg-danger text-white border-0 py-3 shadow" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Account Info / Email -->
            <div class="col-md-6 mb-4">
                <div class="premium-card p-4 h-100">
                    <h4 class="text-gold border-bottom border-secondary pb-3 mb-4"><i class="fas fa-envelope me-2"></i>Email Address</h4>
                    <p class="mb-1 text-secondary small text-uppercase">Primary Login</p>
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <span class="fs-5 fw-bold"><?php echo htmlspecialchars($admin['email']); ?></span>
                    </div>

                    <?php if(!empty($admin['pending_email'])): ?>
                        <div class="alert alert-warning py-2 mb-3 bg-transparent border-warning text-warning">
                            <i class="fas fa-spinner fa-spin me-2"></i>Change pending to: <br>
                            <strong><?php echo htmlspecialchars($admin['pending_email']); ?></strong>
                            <div class="mt-2">
                                <a href="verify_email_change.php" class="btn btn-sm btn-outline-warning">Enter OTP</a>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form action="settings.php" method="POST" class="mt-4 border-top border-secondary pt-4">
                        <input type="hidden" name="action" value="request_email_change">
                        <p class="small text-gold mb-3">Change your login email address. We will verify your new address via OTP before applying the change.</p>
                        <div class="mb-3">
                            <label class="form-label text-gold small text-uppercase">New Email Address</label>
                            <input type="email" name="new_email" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-outline-light w-100"><i class="fas fa-paper-plane me-2"></i>Request Change</button>
                    </form>
                </div>
            </div>

            <!-- Security / Password -->
            <div class="col-md-6 mb-4">
                <div class="premium-card p-4 h-100">
                    <h4 class="text-gold border-bottom border-secondary pb-3 mb-4"><i class="fas fa-key me-2"></i>Change Password</h4>
                    <form action="settings.php" method="POST">
                        <input type="hidden" name="action" value="update_password">
                        <div class="mb-3">
                            <label class="form-label text-gold small text-uppercase">Current Password</label>
                            <div class="input-group password-input-group">
                                <input type="password" name="current_password" id="current_password" class="form-control" required>
                                <button type="button" class="btn password-toggle" onclick="togglePassword('current_password', this)" aria-label="Show current password" title="Show current password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-gold small text-uppercase">New Password</label>
                            <div class="input-group password-input-group">
                                <input type="password" name="new_password" id="new_pw" class="form-control" required>
                                <button type="button" class="btn password-toggle" onclick="togglePassword('new_pw', this)" aria-label="Show new password" title="Show new password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="strength-meter"><div id="pw-bar" class="strength-bar"></div></div>
                            <small id="pw-text" class="text-secondary small d-block mt-1">At least 8 characters.</small>
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-gold small text-uppercase">Confirm New Password</label>
                            <div class="input-group password-input-group">
                                <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                                <button type="button" class="btn password-toggle" onclick="togglePassword('confirm_password', this)" aria-label="Show confirm password" title="Show confirm password">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-gold w-100"><i class="fas fa-shield-alt me-2"></i>Update Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(inputId, button) {
            const input = document.getElementById(inputId);
            if (!input) return;

            const icon = button.querySelector('i');
            const isHidden = input.type === 'password';

            input.type = isHidden ? 'text' : 'password';
            button.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            button.setAttribute('title', isHidden ? 'Hide password' : 'Show password');

            if (icon) {
                icon.classList.toggle('fa-eye', !isHidden);
                icon.classList.toggle('fa-eye-slash', isHidden);
            }
        }

        document.getElementById('new_pw').addEventListener('input', function() {
            var val = this.value;
            var bar = document.getElementById('pw-bar');
            var txt = document.getElementById('pw-text');
            bar.className = 'strength-bar';
            
            if(val.length === 0) { bar.style.width = '0%'; txt.innerText = 'At least 8 characters.'; }
            else if(val.length < 8) { bar.classList.add('weak'); txt.innerText = 'Too short.'; }
            else if(val.match(/[0-9]/) && val.match(/[!@#$%^&*]/)) { bar.classList.add('strong'); txt.innerText = 'Excellent security.'; }
            else { bar.classList.add('medium'); txt.innerText = 'Good, but could be stronger.'; }
        });
    </script>
<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.1/dist/sweetalert2.all.min.js"></script>
<script>
document.addEventListener('click', function(e) {
    const link = e.target.closest('.logout-link');
    if (link) {
        e.preventDefault();
        const targetHref = link.href || link.getAttribute('data-href') || '../auth/login.php';
        Swal.fire({
            title: 'Ready to leave?',
            text: 'You will be logged out of your session.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, logout',
            background: '#2d2d2d',
            color: '#fff'
        }).then((result) => {
            if (result.isConfirmed) {
                if (link.tagName.toUpperCase() === 'BUTTON' && link.closest('form')) {
                    link.closest('form').submit();
                } else {
                    window.location.href = targetHref;
                }
            }
        });
    }
});
</script>
</body>
</html>

