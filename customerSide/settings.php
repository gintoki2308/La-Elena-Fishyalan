<?php
require_once '../config/config.php';
require_once '../config/Database.php';
require_once '../config/Mailer.php';

// Check if user is logged in and is customer
if (!isLoggedIn() || !isCustomer()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$user = getCurrentUser();
$database = new Database();
$db = $database->connect();

$success_msg = null;
$error = null;
$active_tab = 'security'; // Default tab to show

// ---------- HANDLE FORM SUBMISSIONS ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Password Update
    if (isset($_POST['action']) && $_POST['action'] === 'update_password') {
        $active_tab = 'security'; // Stay on security tab
        try {
            $current_pw = $_POST['current_password'];
            $new_pw = $_POST['new_password'];
            $confirm_pw = $_POST['confirm_password'];

            if ($new_pw !== $confirm_pw) {
                throw new Exception("New password and confirmation do not match.");
            }
            if (strlen($new_pw) < 6) {
                throw new Exception("New password must be at least 6 characters long.");
            }

            $stmt = $db->prepare("SELECT password, email, first_name FROM customer WHERE member_id = :id");
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && password_verify($current_pw, $row['password'])) {
                $hashed_pw = password_hash($new_pw, PASSWORD_DEFAULT);
                $updateStmt = $db->prepare("UPDATE customer SET password = :new_pw WHERE member_id = :id");
                $updateStmt->execute([
                    ':new_pw' => $hashed_pw,
                    ':id' => $_SESSION['user_id']
                ]);
                $success_msg = "Password updated successfully!";

                // Send Email Notification
                $subject = "Security Alert: Password Changed - La Elena Fishyalan";
                $htmlBody = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #333;'>
                        <h2 style='color: #d4af37;'>Security Alert</h2>
                        <p>Hello {$row['first_name']},</p>
                        <p>Your password for La Elena Fishyalan account was recently changed.</p>
                        <p>If you made this change, you can safely ignore this email.</p>
                        <p style='color: red;'><strong>If you did not make this change, please contact our support team immediately.</strong></p>
                    </div>
                ";
                sendMail($row['email'], $row['first_name'], $subject, $htmlBody);

            } else {
                throw new Exception("The current password you entered is incorrect.");
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

}

// ---------- FETCH CURRENT DATA TO PREFILL FORMS ----------
$currentPreferences = [
    'email_notifications' => 1,
    'sms_notifications' => 0,
    'promotional_emails' => 0,
    'newsletter' => 0
];
$profilePicPath = getProfileAvatar($user['profile_picture'] ?? '', $user['first_name'] ?? '', $user['last_name'] ?? '');
$navAvatarSrc = $profilePicPath;

// Basic Login Info
$userAgent = $_SERVER['HTTP_USER_AGENT'];
$ipAddress = $_SERVER['REMOTE_ADDR'];
$os = "Unknown OS";
$browser = "Unknown Browser";

if (preg_match('/windows nt/i', $userAgent)) $os = 'Windows';
elseif (preg_match('/macintosh|mac os x/i', $userAgent)) $os = 'macOS';
elseif (preg_match('/linux/i', $userAgent)) $os = 'Linux';
elseif (preg_match('/android/i', $userAgent)) $os = 'Android';
elseif (preg_match('/iphone/i', $userAgent)) $os = 'iOS';

if (preg_match('/chrome|crios|crmo/i', $userAgent)) $browser = 'Chrome';
elseif (preg_match('/safari/i', $userAgent)) $browser = 'Safari';
elseif (preg_match('/firefox|fxios/i', $userAgent)) $browser = 'Firefox';
elseif (preg_match('/msie|trident/i', $userAgent)) $browser = 'Internet Explorer';
elseif (preg_match('/edg/i', $userAgent)) $browser = 'Edge';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="customer-dashboard.css">
    <style>
        :root { --gold: #d4af37; }
        
        /* Premium Tabs Layout */
        .settings-nav {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            scrollbar-width: thin;
            border-bottom: 2px solid #333;
            margin-bottom: 30px;
            justify-content: center;
        }
        .settings-nav .nav-link { 
            color: #888; 
            border-radius: 0; 
            padding: 15px 25px; 
            font-weight: 600; 
            transition: all 0.3s ease; 
            background: transparent;
            border-bottom: 3px solid transparent;
            white-space: nowrap;
        }
        .settings-nav .nav-link:hover { color: var(--gold); background: rgba(212, 175, 55, 0.05); }
        .settings-nav .nav-link.active { 
            color: var(--gold); 
            background: transparent; 
            border-bottom: 3px solid var(--gold); 
        }

        /* Form Switch Overrides */
        .form-switch .form-check-input { width: 3em; height: 1.5em; background-color: #444; border-color: #333; cursor: pointer; }
        .form-switch .form-check-input:checked { background-color: var(--gold); border-color: var(--gold); }
        .form-switch .form-check-input:focus { box-shadow: 0 0 0 0.15rem rgba(212, 175, 55, 0.25); border-color: var(--gold); }
        .switch-label { font-weight: 500; font-size: 1.05rem; display: block; }
        .switch-desc { font-size: 0.85rem; color: #aaa; margin-top: 2px; }
        
        /* Password Field Enhancements */
        .password-toggle { cursor: pointer; color: #888; background-color: #212529 !important; border-color: #333; transition: color 0.2s;}
        .password-toggle:hover { color: var(--gold); }
        .form-control:focus { box-shadow: 0 0 0 0.15rem rgba(212, 175, 55, 0.25); border-color: var(--gold); }
        .input-group:focus-within .input-group-text { border-color: var(--gold); }
        
        /* Password Strength Meter */
        .strength-meter { height: 6px; background-color: #333; border-radius: 4px; overflow: hidden; margin-top: 8px;}
        .strength-bar { height: 100%; width: 0; transition: all 0.3s ease; }
        .strength-weak { background-color: #ff4d4d; width: 33.33%; }
        .strength-medium { background-color: #ffd633; width: 66.66%; }
        .strength-strong { background-color: #2ecc71; width: 100%; }
        
        .premium-card { background: #1a1a1a; border: 1px solid #333; border-radius: 12px; }
        .premium-card-body { padding: 40px; }
        .form-container { max-width: 600px; margin: 0 auto; } 

        @media (max-width: 767.98px) {
            .premium-card-body { padding: 24px 18px; }
            .settings-nav { margin-bottom: 24px; padding-bottom: 6px; }
            .settings-nav .nav-link { padding: 12px 18px; }
            .form-container { max-width: none; }
        }
</style>
    <link rel="stylesheet" href="customer-theme.css">
</head>
<body class="customer-unified-layout">
    <?php
    $customerNavPage = 'settings.php';
    require 'customer-navbar.php';
    ?>
    <nav class="navbar navbar-dark bg-dark sticky-top">
        <div class="container-fluid px-3">
            <button class="btn btn-link text-light p-0 me-2 d-md-none" id="sidebarToggleBtn" aria-label="Open menu">
                <i class="fas fa-bars fa-lg"></i>
            </button>

            <a class="navbar-brand me-auto" href="customer-dashboard.php">
                <i class="fas fa-utensils me-2 text-warning"></i>La Elena Fishyalan
            </a>

            <div class="dropdown">
                <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 py-0"
                   href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <img src="<?php echo htmlspecialchars($navAvatarSrc); ?>"
                         alt="avatar" class="rounded-circle"
                         width="32" height="32" style="object-fit:cover;border:1.5px solid #d4af37;">
                    <span class="d-none d-sm-inline text-light" style="font-size:0.9rem;">
                        <?php echo htmlspecialchars($user['first_name'] ?? 'Customer'); ?>
                    </span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow" aria-labelledby="userDropdown">
                    <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2 text-secondary"></i>My Profile</a></li>
                    <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2 text-secondary"></i>Account Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="logout-link dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="layout-wrap">
        <aside class="sidebar" id="mainSidebar">
            <button class="sidebar-close d-md-none" id="sidebarCloseBtn" aria-label="Close menu">
                <i class="fas fa-times"></i>
            </button>

            <div class="sidebar-header">
                <span class="text-gold fw-bold" style="font-family:'Playfair Display',serif;">
                    <i class="fas fa-home me-2"></i>My Account
                </span>
            </div>

            <nav class="nav flex-column mt-2">
                <a class="nav-link" href="customer-dashboard.php"><i class="fas fa-home"></i>Dashboard</a>
                <a class="nav-link" href="menu.php"><i class="fas fa-utensils"></i>Browse Menu</a>
                <a class="nav-link" href="orders.php"><i class="fas fa-shopping-cart"></i>My Orders</a>
                <a class="nav-link" href="cart.php"><i class="fas fa-shopping-basket"></i>Cart</a>
                <a class="nav-link" href="favorites.php"><i class="fas fa-heart"></i>Favorites</a>
                <a class="nav-link" href="reservations.php"><i class="fas fa-calendar"></i>Reservations</a>
                <a class="nav-link" href="feedback.php"><i class="fas fa-comment-dots"></i>Leave Feedback</a>
                <div class="sidebar-divider"></div>
                <a class="nav-link" href="profile.php"><i class="fas fa-user"></i>My Profile</a>
                <a class="nav-link active" href="settings.php"><i class="fas fa-cog"></i>Settings</a>
            </nav>
        </aside>

        <main class="main-content">
                
                <div class="dashboard-header mb-4">
                    <h1 class="display-6"><i class="fas fa-cog me-2 text-gold"></i>Account Settings</h1>
                    <p class="text-gold">Manage your account security in one place.</p>
                </div>

                <ul class="nav nav-pills settings-nav" id="settings-tab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $active_tab === 'security' ? 'active' : ''; ?>" id="security-tab" data-bs-toggle="pill" data-bs-target="#tab-security" type="button" role="tab">
                            <i class="fas fa-shield-alt me-2"></i>Privacy & Security
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="settings-tabContent">
                    
                    <div class="tab-pane fade <?php echo $active_tab === 'security' ? 'show active' : ''; ?>" id="tab-security" role="tabpanel">
                        <div class="premium-card">
                            <div class="premium-card-body">
                                <div class="form-container">
                                    <h4 class="text-white mb-4">Change Password</h4>
                                    <form action="settings.php" method="POST" id="passwordForm" class="mb-5">
                                        <input type="hidden" name="action" value="update_password">
                                        
                                        <div class="mb-3">
                                            <label class="form-label text-light">Current Password</label>
                                            <div class="input-group">
                                                <input type="password" name="current_password" id="current_password" class="form-control bg-dark border-secondary text-light" required>
                                                <span class="input-group-text password-toggle" onclick="togglePassword('current_password', this)"><i class="fas fa-eye"></i></span>
                                            </div>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label text-light">New Password</label>
                                            <div class="input-group">
                                                <input type="password" id="new_password" name="new_password" class="form-control bg-dark border-secondary text-light" required>
                                                <span class="input-group-text password-toggle" onclick="togglePassword('new_password', this)"><i class="fas fa-eye"></i></span>
                                            </div>
                                            <div class="strength-meter"><div id="strength_bar" class="strength-bar"></div></div>
                                            <small id="strength_text" class="text-secondary mt-1 d-block">Minimum 6 characters required.</small>
                                        </div>
                                        
                                        <div class="mb-4">
                                            <label class="form-label text-light">Confirm New Password</label>
                                            <div class="input-group">
                                                <input type="password" id="confirm_password" name="confirm_password" class="form-control bg-dark border-secondary text-light" required>
                                                <span class="input-group-text password-toggle" onclick="togglePassword('confirm_password', this)"><i class="fas fa-eye"></i></span>
                                            </div>
                                            <small id="match_text" class="mt-1 d-block"></small>
                                        </div>
                                        
                                        <button type="submit" id="btnSubmitPassword" class="btn btn-warning fw-bold px-4" disabled><i class="fas fa-key me-2"></i>Update Password</button>
                                    </form>

                                    <hr class="border-secondary my-5">

                                    <h4 class="text-white mb-4">Login Security</h4>
                                    <div class="d-flex align-items-center bg-dark p-4 rounded border border-secondary">
                                        <div class="me-4">
                                            <i class="fas fa-desktop fa-2x text-info"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <h6 class="mb-1 text-white">Current Active Session</h6>
                                            <p class="mb-0 text-gold small"><?php echo $browser; ?> on <?php echo $os; ?></p>
                                            <p class="mb-0 text-secondary small"><i class="fas fa-map-marker-alt me-1 text-danger"></i>IP Address: <?php echo $ipAddress; ?></p>
                                        </div>
                                        <span class="badge bg-success py-2 px-3">Active Now</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>
        </main>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // --- Sidebar Logic ---
        const sidebar = document.getElementById('mainSidebar');
        const backdrop = document.getElementById('sidebarBackdrop');
        const toggleBtn = document.getElementById('sidebarToggleBtn');
        const closeBtn = document.getElementById('sidebarCloseBtn');

        function openSidebar() {
            if (!sidebar || !backdrop) return;
            sidebar.classList.add('show');
            backdrop.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeSidebar() {
            if (!sidebar || !backdrop) return;
            sidebar.classList.remove('show');
            backdrop.classList.remove('show');
            document.body.style.overflow = '';
        }

        if (toggleBtn) toggleBtn.addEventListener('click', (e) => { e.stopPropagation(); openSidebar(); });
        if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
        if (backdrop) backdrop.addEventListener('click', closeSidebar);

        if (sidebar) {
            sidebar.querySelectorAll('a.nav-link').forEach(link => {
                link.addEventListener('click', () => { if (window.innerWidth < 768) closeSidebar(); });
            });
        }

        // --- Notifications ---
        <?php if ($success_msg): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: <?php echo json_encode($success_msg); ?>,
                background: '#1a1a1a',
                color: '#ffffff',
                confirmButtonColor: '#d4af37',
                iconColor: '#2ecc71'
            });
        <?php endif; ?>

        <?php if ($error): ?>
            Swal.fire({
                icon: 'error',
                title: 'Oops...',
                text: <?php echo json_encode($error); ?>,
                background: '#1a1a1a',
                color: '#ffffff',
                confirmButtonColor: '#d4af37',
                iconColor: '#ff4d4d'
            });
        <?php endif; ?>

        // --- Password Show/Hide Toggle ---
        function togglePassword(inputId, toggleElement) {
            const input = document.getElementById(inputId);
            const icon = toggleElement.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
                toggleElement.style.color = '#d4af37';
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
                toggleElement.style.color = '#888';
            }
        }

        // --- Password Validation & Strength ---
        const newPwInput = document.getElementById('new_password');
        const confirmPwInput = document.getElementById('confirm_password');
        const btnSubmitPw = document.getElementById('btnSubmitPassword');
        const matchText = document.getElementById('match_text');

        function validatePasswordMatch() {
            const val1 = newPwInput.value;
            const val2 = confirmPwInput.value;
            
            if (val2.length === 0) {
                matchText.innerText = '';
                btnSubmitPw.disabled = true;
                return;
            }

            if (val1 === val2 && val1.length >= 6) {
                matchText.innerText = 'Passwords match!';
                matchText.className = 'mt-1 d-block text-success small';
                btnSubmitPw.disabled = false;
            } else {
                matchText.innerText = 'Passwords do not match or are too short.';
                matchText.className = 'mt-1 d-block text-danger small';
                btnSubmitPw.disabled = true;
            }
        }

        newPwInput.addEventListener('input', function() {
            var val = this.value;
            var result = 0;
            if (val.length >= 8) result += 1;
            if (val.match(/[A-Z]/)) result += 1;
            if (val.match(/[0-9]/)) result += 1;
            if (val.match(/[^A-Za-z0-9]/)) result += 1;

            var bar = document.getElementById('strength_bar');
            var text = document.getElementById('strength_text');
            bar.className = 'strength-bar';
            
            if (val.length === 0) {
                bar.style.width = '0%';
                text.innerText = 'Minimum 6 characters required.';
                text.className = 'text-secondary mt-1 d-block small';
            } else if (val.length < 6) {
                bar.classList.add('strength-weak');
                text.innerText = 'Too short.';
                text.className = 'text-danger mt-1 d-block small';
            } else if (result <= 1) {
                bar.classList.add('strength-weak');
                text.innerText = 'Weak - include numbers and uppercase letters.';
                text.className = 'text-warning mt-1 d-block small';
            } else if (result == 2 || result == 3) {
                bar.classList.add('strength-medium');
                text.innerText = 'Medium - add special characters to improve security.';
                text.className = 'text-warning mt-1 d-block small';
            } else {
                bar.classList.add('strength-strong');
                text.innerText = 'Strong - looking good!';
                text.className = 'text-success mt-1 d-block small';
            }
            validatePasswordMatch();
        });

        confirmPwInput.addEventListener('input', validatePasswordMatch);

        // --- Logout Prompt ---
        document.addEventListener('click', function(e) {
            const link = e.target.closest('.logout-link');
            if (link) {
                e.preventDefault();
                const logoutUrl = link.getAttribute('href');
                Swal.fire({
                    title: 'Ready to Leave?',
                    text: 'Are you sure you want to log out?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#d4af37',
                    cancelButtonColor: '#333',
                    confirmButtonText: 'Yes, log me out',
                    cancelButtonText: 'Cancel',
                    background: '#1e1e1e',
                    color: '#fff'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = logoutUrl;
                    }
                });
            }
        });
    </script>
</body>
</html>
