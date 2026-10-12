<?php
require_once '../config/config.php';
require_once '../config/Database.php';
require_once '../config/Mailer.php';
require_once '../config/pagination_helper.php';

if (!isLoggedIn() || !isStaff()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$user = getCurrentUser();
$database = new Database();
$db = $database->connect();

$success_msg = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];

        if ($action === 'update_password') {
            $current_pw = $_POST['current_password'];
            $new_pw     = $_POST['new_password'];
            $confirm_pw = $_POST['confirm_password'];

            if ($new_pw !== $confirm_pw) throw new Exception("New passwords do not match.");
            if (strlen($new_pw) < 6)      throw new Exception("Password must be at least 6 characters.");

            $stmt = $db->prepare("SELECT password, email, first_name FROM staffs WHERE staff_id = :id");
            $stmt->execute([':id' => $_SESSION['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && password_verify($current_pw, $row['password'])) {
                $hashed = password_hash($new_pw, PASSWORD_DEFAULT);
                $upd = $db->prepare("UPDATE staffs SET password = :pw, last_password_change = NOW() WHERE staff_id = :id");
                $upd->execute([':pw' => $hashed, ':id' => $_SESSION['user_id']]);
                $success_msg = "Password updated successfully.";
                $subject = "Security Alert: Staff Password Changed - La Elena Fishyalan";
                $body    = "Hello {$row['first_name']}, your staff portal password was recently changed.";
                sendMail($row['email'], $row['first_name'], $subject, "<h2>Security Alert</h2><p>$body</p>");
            } else throw new Exception("Incorrect current password.");
        }

        if ($action === 'request_email_change') {
            $new_email = trim($_POST['new_email']);
            if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) throw new Exception("Invalid email format.");
            $chk = $db->prepare("SELECT staff_id FROM staffs WHERE email = :e AND staff_id != :id UNION SELECT member_id FROM customer WHERE email = :e");
            $chk->execute([':e' => $new_email, ':id' => $_SESSION['user_id']]);
            if ($chk->rowCount() > 0) throw new Exception("Email already exists in system.");
            $token = sprintf("%06d", mt_rand(100000, 999999));
            $upd = $db->prepare("UPDATE staffs SET pending_email = :e, email_token = :t WHERE staff_id = :id");
            $upd->execute([':e' => $new_email, ':t' => $token, ':id' => $_SESSION['user_id']]);
            sendMail($new_email, $_SESSION['first_name'], "Staff Profile: Email Change OTP", "<h3>Your code: <strong>$token</strong></h3>");
            header('Location: verify_email_change.php');
            exit;
        }

        if ($action === 'update_contact') {
            $num = trim($_POST['phone_number']);
            $upd = $db->prepare("UPDATE staffs SET phone_number = :n WHERE staff_id = :id");
            $upd->execute([':id' => $_SESSION['user_id'], ':n' => $num]);
            $success_msg = "Phone number updated.";
        }

        if ($action === 'update_address') {
            $upd = $db->prepare("UPDATE staffs SET address_street=:s, address_city=:c, address_state=:st, address_zip=:z WHERE staff_id=:id");
            $upd->execute([':id'=>$_SESSION['user_id'], ':s'=>trim($_POST['address_street']),
                           ':c'=>trim($_POST['address_city']), ':st'=>trim($_POST['address_state']),
                           ':z'=>trim($_POST['address_zip'])]);
            $success_msg = "Address updated.";
        }

        if ($action === 'update_emergency') {
            $upd = $db->prepare("UPDATE staffs SET emergency_contact_name=:n, emergency_contact_relation=:r, emergency_contact_phone=:p WHERE staff_id=:id");
            $upd->execute([':id'=>$_SESSION['user_id'], ':n'=>trim($_POST['emergency_contact_name']),
                           ':r'=>trim($_POST['emergency_contact_relation']), ':p'=>trim($_POST['emergency_contact_phone'])]);
            $success_msg = "Emergency contact updated.";
        }

        if ($action === 'revoke_session') {
            $del = $db->prepare("DELETE FROM staff_sessions WHERE session_id = :sid AND staff_id = :uid");
            $del->execute([':sid' => $_POST['session_id'], ':uid' => $_SESSION['user_id']]);
            $success_msg = "Session revoked successfully.";
        }

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$stmt = $db->prepare("SELECT * FROM staffs WHERE staff_id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

$sessions = $db->prepare("SELECT * FROM staff_sessions WHERE staff_id = :id ORDER BY created_at DESC");
$sessions->execute([':id' => $_SESSION['user_id']]);
$sess_list = $sessions->fetchAll(PDO::FETCH_ASSOC);
$sessionsPagination = paginate_array($sess_list, 6);
$sess_list = $sessionsPagination['items'];

$avatarSrc = getProfileAvatar($staff['profile_picture'] ?? '', $staff['first_name'] ?? '', $staff['last_name'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Settings - Staff Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="staff-dashboard.css">
    <style>
        /* ─── Base ─────────────────────────────────────────── */
        :root {
            --gold: #d4af37;
            --gold-hover: #c19b2e;
            --dark-bg: #121212;
            --card-bg: #1e1e1e;
            --border-subtle: #333;
        }

        body {
            background-color: var(--dark-bg);
            color: #fff;
            font-family: 'Montserrat', sans-serif;
        }

        .text-gold  { color: var(--gold) !important; }
        .btn-gold   { background-color: var(--gold); color: #000; font-weight: 600; border: none; }
        .btn-gold:hover { background-color: var(--gold-hover); color: #000; }

        /* ─── Navbar ────────────────────────────────────────── */
        .site-navbar {
            background: #111 !important;
            border-bottom: 1px solid #2a2a2a;
            padding: 0 16px;
            height: 60px;
            display: flex;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1050;
            gap: 12px;
        }

        .site-navbar .brand {
            font-family: 'Playfair Display', serif;
            font-size: clamp(0.95rem, 2.5vw, 1.25rem);
            font-weight: 700;
            color: var(--gold) !important;
            text-decoration: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            flex: 1;
            min-width: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .staff-portal-badge {
            background: var(--gold);
            color: #000;
            font-size: 0.62rem;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            white-space: nowrap;
            flex-shrink: 0;
        }

        .nav-avatar-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #fff !important;
            text-decoration: none;
            flex-shrink: 0;
        }

        .nav-avatar-btn span {
            font-size: 0.85rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100px;
        }

        .dropdown-menu-dark-custom {
            background: #1a1a1a;
            border: 1px solid #333;
        }
        .dropdown-menu-dark-custom .dropdown-item { color: #f0f0f0; }
        .dropdown-menu-dark-custom .dropdown-item:hover { background: var(--gold); color: #000; }
        .dropdown-menu-dark-custom .dropdown-divider { border-color: #333; }

        /* ─── Page header ────────────────────────────────────── */
        .page-header {
            margin-bottom: 28px;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--gold);
        }

        .page-header h1 {
            font-size: clamp(1.3rem, 4vw, 2rem);
            font-family: 'Playfair Display', serif;
        }

        /* ─── Premium card ───────────────────────────────────── */
        .premium-card {
            background: var(--card-bg);
            border: 1px solid var(--border-subtle);
            border-radius: 12px;
            transition: border-color 0.2s;
        }

        /* ─── Vertical pills (sidebar tabs) ─────────────────── */
        .settings-nav {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .settings-nav .nav-link {
            color: #aaa;
            border-radius: 8px;
            padding: 12px 16px;
            font-size: 0.88rem;
            text-align: left;
            border: 1px solid transparent;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .settings-nav .nav-link:hover {
            background: rgba(212,175,55,0.08);
            color: var(--gold);
        }

        .settings-nav .nav-link.active {
            background: var(--gold);
            color: #1a1a1a;
            font-weight: 700;
            border-color: var(--gold);
        }

        /* ─── Mobile: horizontal scrollable tab row ──────────── */
        .settings-nav-mobile {
            display: none;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            gap: 8px;
            padding-bottom: 4px;
            margin-bottom: 20px;
            flex-wrap: nowrap;
            scrollbar-width: none;
        }

        .settings-nav-mobile::-webkit-scrollbar { display: none; }

        .settings-nav-mobile .nav-link {
            color: #aaa;
            border-radius: 999px;
            padding: 8px 16px;
            font-size: 0.8rem;
            white-space: nowrap;
            border: 1px solid #404040;
            flex-shrink: 0;
            transition: all 0.2s;
        }

        .settings-nav-mobile .nav-link.active {
            background: var(--gold);
            color: #000;
            font-weight: 700;
            border-color: var(--gold);
        }

        /* ─── Form controls ──────────────────────────────────── */
        .form-control, .form-select {
            background: #2a2a2a;
            border: 1px solid #444;
            color: #fff;
        }

        .form-control:focus, .form-select:focus {
            background: #333;
            border-color: var(--gold);
            color: #fff;
            box-shadow: none;
        }

        .password-input-group .form-control {
            border-right: 0;
        }

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

        /* ─── Password strength ──────────────────────────────── */
        .strength-meter {
            height: 6px;
            background: #333;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 8px;
        }

        .strength-bar { height: 100%; transition: 0.3s; width: 0; }
        .weak   { background: #ff4757; width: 30%; }
        .medium { background: #ffa502; width: 60%; }
        .strong { background: #2ed573; width: 100%; }

        /* ─── Session items ──────────────────────────────────── */
        .session-item {
            border: 1px solid #333;
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 10px;
            background: #1a1a1a;
            transition: border-color 0.2s;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .session-item:hover { border-color: var(--gold); }

        .session-device {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
            min-width: 0;
        }

        .session-icon {
            width: 44px;
            height: 44px;
            border-radius: 8px;
            background: #111;
            border: 1px solid #333;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        /* ─── Info display rows ──────────────────────────────── */
        .info-row {
            background: #111;
            border-radius: 8px;
            padding: 14px 16px;
        }

        /* ─── Alerts ─────────────────────────────────────────── */
        .alert-success-custom {
            background: rgba(46,204,113,0.1);
            border: 1px solid #2ecc71;
            color: #5dde97;
            border-radius: 8px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-danger-custom {
            background: rgba(255,107,107,0.1);
            border: 1px solid #ff6b6b;
            color: #ff8787;
            border-radius: 8px;
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ─── Modal ──────────────────────────────────────────── */
        .modal-content {
            background: #1e1e1e;
            border: 1px solid #333;
            color: #fff;
        }

        .modal-header { border-bottom-color: #333; }
        .modal-footer { border-top-color: #333; }

        /* Light inputs inside modals */
        .modal-content .form-control,
        .modal-content .form-select {
            background: #f8f9fa;
            color: #212529;
            border-color: #ced4da;
        }

        .modal-content .form-label  { color: #f0f0f0; }
        .modal-content .modal-title { color: #f0f0f0; }

        /* ─── Responsive ─────────────────────────────────────── */
        /* Light coastal staff portal theme */
        :root { --gold: #e56b4f; --gold-hover: #d8573d; --dark-bg: #f4f7f5; --card-bg: #fffdf8; --border-subtle: rgba(20, 61, 74, 0.13); }
        body { background: linear-gradient(135deg, #f8f1e5 0%, #e5f2ef 50%, #f9e5d8 100%); color: #18333b; }
        .text-gold { color: #d8573d !important; }
        .btn-gold { background: #ed7058; color: #fff; }
        .btn-gold:hover { background: #df624b; color: #fff; }
        .site-navbar { background: #fffdf8 !important; border-bottom-color: rgba(20, 61, 74, 0.12); box-shadow: 0 2px 10px rgba(20, 61, 74, 0.06); }
        .site-navbar .brand { color: #143d4a !important; }
        .staff-portal-badge { background: #ed7058; color: #fff; }
        .nav-avatar-btn { color: #18333b !important; }
        .dropdown-menu-dark-custom { background: #fffdf8; border-color: rgba(20, 61, 74, 0.14); }
        .dropdown-menu-dark-custom .dropdown-item { color: #18333b; }
        .dropdown-menu-dark-custom .dropdown-item:hover { background: #fff0eb; color: #d8573d; }
        .dropdown-menu-dark-custom .dropdown-divider { border-color: rgba(20, 61, 74, 0.12); }
        .page-header { border-bottom-color: #ed7058; }
        .page-header h1 { color: #143d4a !important; }
        .page-header p { color: #63777a !important; }
        .premium-card { background: #fffdf8; border-color: rgba(20, 61, 74, 0.12); box-shadow: 0 14px 32px rgba(20, 61, 74, 0.10); }
        .premium-card .text-white { color: #18333b !important; }
        .premium-card .text-gold { color: #d8573d !important; }
        .settings-nav .nav-link { color: #537075; }
        .settings-nav .nav-link:hover { background: #fff0eb; color: #d8573d; }
        .settings-nav .nav-link.active { background: #fff0eb; color: #143d4a; border-left: 3px solid #ed7058; border-top-color: rgba(20, 61, 74, 0.12); border-right-color: rgba(20, 61, 74, 0.12); border-bottom-color: rgba(20, 61, 74, 0.12); }
        .settings-nav-mobile .nav-link { color: #537075; border-color: rgba(20, 61, 74, 0.15); }
        .settings-nav-mobile .nav-link.active { background: #ed7058; color: #fff; border-color: #ed7058; }
        .form-control, .form-select { background: #fff; border-color: rgba(20, 61, 74, 0.18); color: #18333b; }
        .form-control:focus, .form-select:focus { background: #fff; border-color: #5daeb1; color: #18333b; box-shadow: 0 0 0 3px rgba(93, 174, 177, 0.17); }
        .password-toggle { background: #fff; border-color: rgba(20, 61, 74, 0.18); color: #63777a; }
        .password-toggle:hover, .password-toggle:focus, .password-input-group:focus-within .password-toggle { background: #fff7f3; border-color: #ed7058; color: #d8573d; }
        .strength-meter { background: #e5eceb; }
        .info-row { background: #f4f8ff; border: 1px solid rgba(20, 61, 74, 0.08); }
        .session-item { background: #fff; border-color: rgba(20, 61, 74, 0.14); }
        .session-item:hover { border-color: #ed7058; }
        .session-icon { background: #fff0eb; border-color: rgba(229, 107, 79, 0.22); }
        .modal-content { background: #fffdf8; border-color: rgba(20, 61, 74, 0.14); color: #18333b; }
        .modal-header, .modal-footer { border-color: rgba(20, 61, 74, 0.12); }
        .modal-content .form-label, .modal-content .modal-title { color: #143d4a; }

        @media (max-width: 767px) {
            .settings-nav         { display: none; }
            .settings-nav-mobile  { display: flex; }

            .session-item { flex-direction: column; align-items: flex-start; }

            .page-header h1 { font-size: 1.2rem; }

            .premium-card { padding: 16px !important; }

            /* Email row: stack on mobile */
            .email-row {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 10px;
            }
        }

        @media (max-width: 400px) {
            .site-navbar .brand { font-size: 0.85rem; }
            .staff-portal-badge { display: none; }
        }
    </style>
    <link rel="stylesheet" href="staff-page-theme.css">
</head>
<body>

    <!-- ═══ NAVBAR ════════════════════════════════════════════════ -->
    <nav class="site-navbar">
        <a class="brand" href="staff-dashboard.php">
            <i class="fas fa-utensils flex-shrink-0"></i>
            <span>La Elena Fishyalan</span>
            <span class="staff-portal-badge">Staff Portal</span>
        </a>

        <div class="dropdown ms-auto">
            <a class="nav-avatar-btn dropdown-toggle"
               href="#" id="staffDropdown" role="button"
               data-bs-toggle="dropdown" aria-expanded="false">
                <img src="<?php echo $avatarSrc; ?>" alt="Avatar"
                     class="rounded-circle flex-shrink-0"
                     width="34" height="34"
                     style="object-fit:cover;border:1.5px solid var(--gold);">
                <span class="d-none d-sm-inline">Settings</span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark-custom">
                <li>
                    <a class="dropdown-item" href="profile.php">
                        <i class="fas fa-user-tie me-2"></i>Professional Profile
                    </a>
                </li>
                <li>
                    <a class="dropdown-item active" href="settings.php">
                        <i class="fas fa-cog me-2"></i>Account Settings
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <a class="logout-link dropdown-item text-danger" href="../auth/logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- ═══ MAIN ══════════════════════════════════════════════════ -->
    <div class="container-fluid px-3 px-md-4 py-4" style="max-width:1100px;">

        <!-- Page header -->
        <div class="page-header">
            <h1><i class="fas fa-sliders-h me-2 text-gold"></i>Account &amp; Security Settings</h1>
            <p class="text-gold opacity-75 mb-0">Maintain your contact points and keep your account secure.</p>
        </div>

        <!-- Alerts -->
        <?php if ($success_msg): ?>
            <div class="alert-success-custom mb-4 alert alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle flex-shrink-0"></i>
                <span><?php echo htmlspecialchars($success_msg); ?></span>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-danger-custom mb-4 alert alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle flex-shrink-0"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">

            <!-- ── Mobile horizontal tab strip ──────────────── -->
            <div class="col-12 d-md-none">
                <div class="settings-nav-mobile d-flex" id="mobileTabNav" role="tablist">
                    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-contact">
                        <i class="fas fa-phone me-1"></i>Contact
                    </button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-address">
                        <i class="fas fa-map-marker-alt me-1"></i>Address
                    </button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-emergency">
                        <i class="fas fa-heartbeat me-1"></i>Emergency
                    </button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-security">
                        <i class="fas fa-user-shield me-1"></i>Security
                    </button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-sessions">
                        <i class="fas fa-laptop-code me-1"></i>Sessions
                    </button>
                </div>
            </div>

            <!-- ── Desktop sidebar nav ──────────────────────── -->
            <div class="col-md-3 d-none d-md-block">
                <div class="settings-nav nav flex-column nav-pills" role="tablist">
                    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#tab-contact">
                        <i class="fas fa-phone me-2"></i>Contact Details
                    </button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-address">
                        <i class="fas fa-map-marker-alt me-2"></i>Addresses
                    </button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-emergency">
                        <i class="fas fa-heartbeat me-2"></i>Emergency Contacts
                    </button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-security">
                        <i class="fas fa-user-shield me-2"></i>Security Hub
                    </button>
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#tab-sessions">
                        <i class="fas fa-laptop-code me-2"></i>Active Sessions
                    </button>
                </div>
            </div>

            <!-- ── Tab panes ─────────────────────────────────── -->
            <div class="col-12 col-md-9">
                <div class="tab-content">

                    <!-- CONTACT DETAILS -->
                    <div class="tab-pane fade show active" id="tab-contact">
                        <div class="premium-card p-4">
                            <h4 class="text-gold mb-4">Contact Management</h4>

                            <!-- Email -->
                            <div class="border-bottom border-secondary pb-4 mb-4">
                                <h6 class="text-gold text-uppercase small mb-3">Login Email</h6>
                                <div class="info-row d-flex email-row justify-content-between align-items-center gap-3 mb-3">
                                    <div class="min-width-0">
                                        <span class="fs-6 text-white" style="word-break:break-all;">
                                            <?php echo htmlspecialchars($staff['email']); ?>
                                        </span>
                                        <?php if (!empty($staff['pending_email'])): ?>
                                            <div class="mt-1">
                                                <span class="badge bg-warning text-dark">
                                                    <i class="fas fa-spinner fa-spin me-1"></i>
                                                    Pending: <?php echo htmlspecialchars($staff['pending_email']); ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <button class="btn btn-sm btn-outline-warning flex-shrink-0"
                                            data-bs-toggle="modal" data-bs-target="#emailModal">
                                        Change Email
                                    </button>
                                </div>
                            </div>

                            <!-- Phone -->
                            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                                <h6 class="text-gold text-uppercase small mb-0">Primary Phone Number</h6>
                                <button class="btn btn-sm btn-gold"
                                        data-bs-toggle="modal" data-bs-target="#phoneModal">
                                    <i class="fas fa-edit me-1"></i>Update Number
                                </button>
                            </div>
                            <div class="info-row">
                                <?php if (empty($staff['phone_number'])): ?>
                                    <span class="text-gold small">No phone number added.</span>
                                <?php else: ?>
                                    <span class="text-light">
                                        <i class="fas fa-mobile-alt me-2 text-gold opacity-50"></i>
                                        <?php echo htmlspecialchars($staff['phone_number']); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- ADDRESSES -->
                    <div class="tab-pane fade" id="tab-address">
                        <div class="premium-card p-4">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                                <h4 class="text-gold mb-0">Manage Address</h4>
                                <button class="btn btn-gold"
                                        data-bs-toggle="modal" data-bs-target="#addressModal">
                                    <i class="fas fa-edit me-1"></i>Update Address
                                </button>
                            </div>
                            <?php if (empty($staff['address_street'])): ?>
                                <p class="text-gold">No address saved.</p>
                            <?php else: ?>
                                <div class="info-row border border-secondary">
                                    <p class="mb-0 text-white">
                                        <?php echo htmlspecialchars($staff['address_street']); ?>
                                    </p>
                                    <p class="mb-0 text-gold small mt-1">
                                        <?php echo htmlspecialchars($staff['address_city'] ?? ''); ?>,
                                        <?php echo htmlspecialchars($staff['address_state'] ?? ''); ?>
                                        <?php echo htmlspecialchars($staff['address_zip'] ?? ''); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- EMERGENCY CONTACTS -->
                    <div class="tab-pane fade" id="tab-emergency">
                        <div class="premium-card p-4">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                                <h4 class="text-gold mb-0">Emergency Contact</h4>
                                <button class="btn btn-gold"
                                        data-bs-toggle="modal" data-bs-target="#emergencyModal">
                                    <i class="fas fa-edit me-1"></i>Update Contact
                                </button>
                            </div>
                            <?php if (empty($staff['emergency_contact_name'])): ?>
                                <p class="text-gold">Stay safe! Add an emergency contact.</p>
                            <?php else: ?>
                                <div class="info-row border border-secondary">
                                    <h6 class="mb-1 fw-bold text-white">
                                        <?php echo htmlspecialchars($staff['emergency_contact_name']); ?>
                                        <span class="text-gold opacity-75 small fw-normal ms-2">
                                            (<?php echo htmlspecialchars($staff['emergency_contact_relation'] ?? ''); ?>)
                                        </span>
                                    </h6>
                                    <p class="mb-0 small">
                                        <i class="fas fa-phone-alt me-2 text-gold opacity-50"></i>
                                        <?php echo htmlspecialchars($staff['emergency_contact_phone']); ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- SECURITY HUB -->
                    <div class="tab-pane fade" id="tab-security">
                        <div class="premium-card p-4">
                            <h4 class="text-gold mb-4">Change Password</h4>
                            <form action="settings.php" method="POST">
                                <input type="hidden" name="action" value="update_password">
                                <div class="mb-3">
                                    <label class="form-label">Current Password</label>
                                    <div class="input-group password-input-group">
                                        <input type="password" name="current_password" id="current_password" class="form-control" required>
                                        <button type="button" class="btn password-toggle" onclick="togglePassword('current_password', this)" aria-label="Show current password" title="Show current password">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">New Password</label>
                                    <div class="input-group password-input-group">
                                        <input type="password" name="new_password" id="new_pw" class="form-control" required>
                                        <button type="button" class="btn password-toggle" onclick="togglePassword('new_pw', this)" aria-label="Show new password" title="Show new password">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <div class="strength-meter">
                                        <div id="pw-bar" class="strength-bar"></div>
                                    </div>
                                    <small id="pw-text" class="text-gold small d-block mt-1">
                                        Include symbols and numbers for a stronger password.
                                    </small>
                                </div>
                                <div class="mb-4">
                                    <label class="form-label">Confirm New Password</label>
                                    <div class="input-group password-input-group">
                                        <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                                        <button type="button" class="btn password-toggle" onclick="togglePassword('confirm_password', this)" aria-label="Show confirm password" title="Show confirm password">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-gold px-4">
                                    <i class="fas fa-key me-2"></i>Update Password
                                </button>
                            </form>
                            <div class="mt-4 pt-4 border-top border-secondary">
                                <h6 class="text-gold text-uppercase small mb-2">Last Changed</h6>
                                <p class="mb-0 text-white">
                                    <?php echo $staff['last_password_change']
                                        ? date('F d, Y \a\t H:i', strtotime($staff['last_password_change']))
                                        : 'Never'; ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- ACTIVE SESSIONS -->
                    <div class="tab-pane fade" id="tab-sessions">
                        <div class="premium-card p-4">
                            <h4 class="text-gold mb-2">Active Sessions &amp; Devices</h4>
                            <p class="text-gold small mb-4 opacity-75">
                                You can revoke any session to log out from that device remotely.
                            </p>

                            <?php foreach ($sess_list as $s):
                                $isCurrent = ($s['php_session_id'] === session_id());
                                $ua = $s['user_agent'];
                                if      (strpos($ua, 'Windows')   !== false) { $device = 'Windows PC';    $icon = 'fa-desktop'; }
                                elseif  (strpos($ua, 'Macintosh') !== false) { $device = 'Mac';           $icon = 'fa-desktop'; }
                                elseif  (strpos($ua, 'Android')   !== false) { $device = 'Android Phone'; $icon = 'fa-mobile-alt'; }
                                elseif  (strpos($ua, 'iPhone')    !== false) { $device = 'iPhone';        $icon = 'fa-mobile-alt'; }
                                else                                          { $device = 'Unknown Device';$icon = 'fa-laptop'; }
                            ?>
                            <div class="session-item">
                                <div class="session-device">
                                    <div class="session-icon">
                                        <i class="fas <?php echo $icon; ?> fs-5 text-gold"></i>
                                    </div>
                                    <div class="min-width-0">
                                        <h6 class="mb-0 text-white">
                                            <?php echo $device; ?>
                                            <?php if ($isCurrent): ?>
                                                <span class="badge ms-1 px-2 py-1"
                                                      style="background:var(--gold);color:#000;font-size:0.7rem;">
                                                    This Device
                                                </span>
                                            <?php endif; ?>
                                        </h6>
                                        <small class="text-gold opacity-75" style="word-break:break-all;">
                                            <?php echo htmlspecialchars($s['ip_address']); ?> &bull;
                                            Signed in <?php echo date('M d, H:i', strtotime($s['created_at'])); ?>
                                        </small>
                                    </div>
                                </div>
                                <?php if (!$isCurrent): ?>
                                    <form action="settings.php" method="POST" class="flex-shrink-0">
                                        <input type="hidden" name="action" value="revoke_session">
                                        <input type="hidden" name="session_id"
                                               value="<?php echo htmlspecialchars($s['session_id']); ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            Log out
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>

                            <?php if (empty($sess_list)): ?>
                                <p class="text-muted text-center py-4">No active sessions found.</p>
                            <?php endif; ?>

                            <?php echo render_pagination($sessionsPagination); ?>
                        </div>
                    </div>

                </div><!-- /tab-content -->
            </div>
        </div><!-- /row -->
    </div><!-- /container -->

    <!-- ═══ MODALS ════════════════════════════════════════════════ -->

    <!-- Email -->
    <div class="modal fade" id="emailModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Change Login Email</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="settings.php" method="POST">
                    <input type="hidden" name="action" value="request_email_change">
                    <div class="modal-body">
                        <p class="small text-gold mb-3">
                            We'll send a 6-digit OTP to your new email address to verify ownership.
                        </p>
                        <div class="mb-3">
                            <label class="form-label">New Email Address</label>
                            <input type="email" name="new_email" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-gold w-100">Request Verification</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Phone -->
    <div class="modal fade" id="phoneModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Contact Number</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="settings.php" method="POST">
                    <input type="hidden" name="action" value="update_contact">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone_number" class="form-control" required
                                   placeholder="+63 000 000 0000"
                                   value="<?php echo htmlspecialchars($staff['phone_number'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-gold w-100">Update Number</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Address -->
    <div class="modal fade" id="addressModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Physical Address</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="settings.php" method="POST">
                    <input type="hidden" name="action" value="update_address">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Street Address</label>
                            <input type="text" name="address_street" class="form-control" required
                                   value="<?php echo htmlspecialchars($staff['address_street'] ?? ''); ?>">
                        </div>
                        <div class="row g-2">
                            <div class="col-12 col-sm-6 mb-3">
                                <label class="form-label">City</label>
                                <input type="text" name="address_city" class="form-control" required
                                       value="<?php echo htmlspecialchars($staff['address_city'] ?? ''); ?>">
                            </div>
                            <div class="col-12 col-sm-6 mb-3">
                                <label class="form-label">State/Province</label>
                                <input type="text" name="address_state" class="form-control" required
                                       value="<?php echo htmlspecialchars($staff['address_state'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Postal/Zip Code</label>
                            <input type="text" name="address_zip" class="form-control" required
                                   value="<?php echo htmlspecialchars($staff['address_zip'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-gold w-100">Save Address</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Emergency -->
    <div class="modal fade" id="emergencyModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Emergency Contact</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="settings.php" method="POST">
                    <input type="hidden" name="action" value="update_emergency">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Contact Name</label>
                            <input type="text" name="emergency_contact_name" class="form-control" required
                                   value="<?php echo htmlspecialchars($staff['emergency_contact_name'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Relationship</label>
                            <input type="text" name="emergency_contact_relation" class="form-control"
                                   placeholder="e.g. Spouse, Parent"
                                   value="<?php echo htmlspecialchars($staff['emergency_contact_relation'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="emergency_contact_phone" class="form-control" required
                                   value="<?php echo htmlspecialchars($staff['emergency_contact_phone'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-gold w-100">Save Contact</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ═══ SCRIPTS ═══════════════════════════════════════════════ -->
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

        // Password strength meter
        document.getElementById('new_pw').addEventListener('input', function () {
            const val = this.value;
            const bar = document.getElementById('pw-bar');
            const txt = document.getElementById('pw-text');
            bar.className = 'strength-bar';
            if (!val.length) {
                bar.style.width = '0%';
                txt.textContent = 'Include symbols and numbers for a stronger password.';
            } else if (val.length < 8) {
                bar.classList.add('weak');
                txt.textContent = 'Too short.';
            } else if (val.match(/[0-9]/) && val.match(/[!@#$%^&*]/)) {
                bar.classList.add('strong');
                txt.textContent = 'Excellent security.';
            } else {
                bar.classList.add('medium');
                txt.textContent = 'Good, but could be stronger.';
            }
        });

        // Keep mobile + desktop tab strips in sync
        document.querySelectorAll('[data-bs-toggle="pill"]').forEach(btn => {
            btn.addEventListener('shown.bs.tab', function (e) {
                const target = e.target.getAttribute('data-bs-target');
                document.querySelectorAll('[data-bs-toggle="pill"]').forEach(other => {
                    other.classList.toggle('active', other.getAttribute('data-bs-target') === target);
                });
            });
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.1/dist/sweetalert2.all.min.js"></script>
    <script>
        document.addEventListener('click', function (e) {
            const link = e.target.closest('.logout-link');
            if (!link) return;
            e.preventDefault();
            const href = link.getAttribute('href') || '../auth/login.php';
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
            }).then(result => {
                if (result.isConfirmed) {
                    if (link.tagName === 'BUTTON' && link.closest('form')) {
                        link.closest('form').submit();
                    } else {
                        window.location.href = href;
                    }
                }
            });
        });
    </script>
    <script src="../assets/js/phone-validator.js"></script>

</body>
</html>
