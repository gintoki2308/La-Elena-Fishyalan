<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. AUTHENTICATION (Admin + Super Admin)
if (!isLoggedIn() || !canManageBackOffice()) {
    header('Location: /adminSide/admin-dashboard.php');
    exit;
}

$addStaffError = $_SESSION['add_staff_error'] ?? '';
$addStaffForm = $_SESSION['add_staff_form'] ?? [];
$createCustomerError = $_SESSION['create_customer_error'] ?? '';
$createCustomerForm = $_SESSION['create_customer_form'] ?? [];
$createAdminError = $_SESSION['create_admin_error'] ?? '';
$createAdminForm = $_SESSION['create_admin_form'] ?? [];
unset($_SESSION['add_staff_error'], $_SESSION['add_staff_form'], $_SESSION['create_customer_error'], $_SESSION['create_customer_form'], $_SESSION['create_admin_error'], $_SESSION['create_admin_form']);
$openAddStaffModal = $addStaffError !== '' || isset($_GET['add_staff']);
$openCreateCustomerModal = $createCustomerError !== '' || isset($_GET['create_customer']);
$openCreateAdminModal = $createAdminError !== '' || isset($_GET['create_admin']);
$editStaffError = $_SESSION['edit_staff_error'] ?? '';
$editStaffForm = $_SESSION['edit_staff_form'] ?? [];
$editStaffId = $_SESSION['edit_staff_id'] ?? '';
unset($_SESSION['edit_staff_error'], $_SESSION['edit_staff_form'], $_SESSION['edit_staff_id']);
$openEditStaffModal = $editStaffError !== '' || isset($_GET['edit_staff']);
$generatedStaffPassword = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$'), 0, 8);

// 2. DATABASE CONNECTION
$database = new Database();
$db = $database->connect();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_staff_id'])) {
    $staffId = (int)($_POST['edit_staff_id'] ?? 0);
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $adminPassword = $_POST['admin_password'] ?? '';
    $allowedRoles = getStaffRoleOptions();
    $editStaffForm = ['first_name' => $firstName, 'last_name' => $lastName, 'phone' => $phone, 'role' => $role];
    $error = '';

    if (!$staffId || empty($firstName) || empty($lastName) || empty($role) || empty($adminPassword)) {
        $error = 'Please fill in all required fields, including your Admin password.';
    } elseif (!in_array($role, $allowedRoles, true)) {
        $error = 'Please select a valid role.';
    }

    if ($error === '') {
        $staffStmt = $db->prepare('SELECT * FROM staffs WHERE staff_id = :id');
        $staffStmt->execute([':id' => $staffId]);
        $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);

        if (!$staff) {
            $error = 'Staff member not found.';
        } else {
            try {
                verifyAdminPassword($db, $_SESSION['user_id'], $adminPassword);
                $updateStmt = $db->prepare('UPDATE staffs SET first_name = :first_name, last_name = :last_name, phone = :phone, role = :role WHERE staff_id = :id');
                $updateStmt->execute([':first_name' => $firstName, ':last_name' => $lastName, ':phone' => $phone, ':role' => $role, ':id' => $staffId]);

                $changes = [];
                if ($firstName != $staff['first_name']) $changes['First Name'] = "Changed from '{$staff['first_name']}' to '{$firstName}'";
                if ($lastName != $staff['last_name']) $changes['Last Name'] = "Changed from '{$staff['last_name']}' to '{$lastName}'";
                if ($phone != $staff['phone']) $changes['Phone'] = "Changed from '{$staff['phone']}' to '{$phone}'";
                if ($role != $staff['role']) $changes['Role'] = "Changed from '{$staff['role']}' to '{$role}'";
                $detailsString = empty($changes) ? 'No actual changes made.' : implode(' | ', array_map(function ($key, $value) {
                    return "$key: $value";
                }, array_keys($changes), $changes));

                sendAdminSecurityNotice("$firstName $lastName", 'Updated Staff account details. ' . $detailsString);
                $auditStmt = $db->prepare("INSERT INTO audit_logs (admin_id, action, action_details, table_affected, record_id, ip_address, timestamp) VALUES (:admin_id, 'EDIT_STAFF', :details, 'staffs', :record_id, :ip, NOW())");
                $auditStmt->execute([':admin_id' => $_SESSION['user_id'], ':details' => $detailsString, ':record_id' => $staffId, ':ip' => $_SERVER['REMOTE_ADDR']]);

                if (!empty($changes) && function_exists('sendUserNotification')) {
                    sendUserNotification($staff['email'], "$firstName $lastName", $changes);
                }

                header('Location: viewUsers.php?success=' . urlencode('Staff updated successfully.') . '#staff');
                exit;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }

    $_SESSION['edit_staff_error'] = $error;
    $_SESSION['edit_staff_form'] = $editStaffForm;
    $_SESSION['edit_staff_id'] = $staffId;
    header('Location: viewUsers.php?edit_staff=' . $staffId . '#staff');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['customer_action'], $_POST['member_id'], $_POST['admin_password'])) {
    $customerAction = $_POST['customer_action'];
    $memberId = (int)($_POST['member_id'] ?? 0);
    $adminPassword = $_POST['admin_password'] ?? '';

    try {
        verifyAdminPassword($db, $_SESSION['user_id'], $adminPassword);



        if ($customerAction === 'verify') {
            $stmt = $db->prepare("UPDATE customer SET is_verified = 1, account_status = 'ACTIVE' WHERE member_id = :id");
            $stmt->execute([':id' => $memberId]);
            header('Location: viewUsers.php?success=' . urlencode('Customer account verified and activated successfully.') . '#customers');
            exit;
        }

        header('Location: viewUsers.php?error=' . urlencode('Invalid customer action.') . '#customers');
        exit;
    } catch (Exception $e) {
        header('Location: viewUsers.php?error=' . urlencode($e->getMessage()) . '#customers');
        exit;
    }
}

// Fetch Admins
$adminsStmt = $db->query("SELECT * FROM admins ORDER BY first_name ASC");
$admins = $adminsStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Staff
$staffStmt = $db->query("SELECT staff_id as id, first_name, last_name, email, phone, role, account_status as status, last_login, profile_picture FROM staffs ORDER BY first_name ASC");
$staffs = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Customers (with aggregations)
$supportsCustomerVerification = dbTableHasColumn($db, 'customer', 'is_verified');
$customerStatusExpr = $supportsCustomerVerification
    ? "CASE WHEN COALESCE(m.is_verified, 0) = 0 THEN 'UNVERIFIED' ELSE m.account_status END"
    : "m.account_status";

// Show ALL customers including unverified — admins can manually verify below
$verifiedWhereClause = "";

$cQuery = "SELECT 
            m.member_id as id,
            m.first_name,
            m.last_name,
            m.email,
            m.phone,
            m.address,
            m.profile_picture,
            m.membership_date,
            {$customerStatusExpr} as status,
            COUNT(DISTINCT o.order_id) AS total_orders,
            COALESCE(SUM(CASE WHEN o.status IN ('DELIVERED','COMPLETED') THEN o.total_amount ELSE 0 END), 0) AS total_spent
          FROM customer m
          LEFT JOIN online_orders o ON o.member_id = m.member_id
          WHERE m.account_status IN ('ACTIVE', 'PENDING', 'DISABLED', 'REJECTED')
          {$verifiedWhereClause}
          GROUP BY m.member_id
          ORDER BY m.membership_date DESC";
$custStmt = $db->query($cQuery);
$customers = $custStmt->fetchAll(PDO::FETCH_ASSOC);

function getStatusBadgeClass($status)
{
    switch (strtoupper($status)) {
        case 'ACTIVE':
            return 'bg-success';
        case 'PENDING':
            return 'bg-warning text-dark';
        case 'DISABLED':
            return 'bg-danger';

        case 'REJECTED':
            return 'bg-secondary';
        case 'UNVERIFIED':
            return 'bg-warning text-dark';
        default:
            return 'bg-primary';
    }
}

// Stat Calculations
$totalAdmins         = count($admins);
$superAdmins         = count(array_filter($admins, fn($a) => $a['role'] === 'Super Admin'));
$totalStaff          = count($staffs);
$activeStaff         = count(array_filter($staffs, fn($s) => strtoupper($s['status']) === 'ACTIVE'));
$inactiveStaff       = count(array_filter($staffs, fn($s) => strtoupper($s['status']) !== 'ACTIVE'));
$totalCustomers      = count($customers);
$unverifiedCustomers = count(array_filter($customers, fn($c) => strtoupper($c['status']) === 'UNVERIFIED'));
$totalCustomerSpent  = array_sum(array_column($customers, 'total_spent'));
$currentAdminRole    = (($_SESSION['admin_role'] ?? 'Admin') === 'Super Admin') ? 'Super Admin' : 'Admin';
$isSuperAdmin        = isSuperAdmin();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($currentAdminRole); ?> User Hub | La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-dark@4/dark.css">

    <style>
        :root {
            --gold: #d4af37;
            --gold-light: #f0d060;
            --dark-bg: #121212;
            --card-bg: #1e1e1e;
            --card-bg-2: #252525;
            --border-color: #333;
        }

        /* ── BASE ─────────────────────────────────────────────── */
        body {
            background-color: var(--dark-bg);
            color: #e0e0e0;
            font-family: 'Segoe UI', sans-serif;
            -webkit-text-size-adjust: 100%;
        }

        .text-primary-gold {
            color: var(--gold) !important;
        }

        .text-gold {
            color: var(--gold);
        }

        /* Account creation modals: flatten the form's visual wrapper so Bootstrap
           can keep the header/actions fixed and make only the form content scroll. */
        .add-staff-modal .modal-dialog {
            max-width: 760px;
        }

        .add-staff-modal .modal-content {
            background: #fff;
            border: 1px solid #dbe8e5;
            border-radius: 18px;
            color: #183b3d;
            overflow: hidden;
            box-shadow: 0 24px 70px rgba(12, 48, 50, .24);
        }

        .add-staff-modal .modal-content>form {
            display: contents;
        }

        .add-staff-modal .modal-header {
            flex: 0 0 auto;
            background: linear-gradient(135deg, #f0f8f6, #fbfdfc);
            border-color: #dbe8e5;
            min-height: 68px;
            padding: 1rem 1.35rem;
        }

        .add-staff-modal .modal-footer {
            flex: 0 0 auto;
            background: #f7fbfa;
            border-color: #dbe8e5;
            padding: .85rem 1.35rem;
            gap: .6rem;
        }

        .add-staff-modal .modal-title {
            color: #173f42;
            font-size: 1.12rem;
            font-weight: 750;
            letter-spacing: -.01em;
        }

        .add-staff-modal .modal-title-icon {
            color: #d5643f;
        }

        .add-staff-modal .btn-close {
            opacity: .55;
        }

        .add-staff-modal .modal-body {
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overscroll-behavior: contain;
            padding: 1.25rem 1.35rem;
        }

        .add-staff-modal .form-label {
            color: #36585a;
            font-size: .82rem;
            font-weight: 700;
            margin-bottom: .4rem;
        }

        .add-staff-modal .form-control,
        .add-staff-modal .form-select {
            background: #fff;
            border-color: #bfd2cf;
            color: #183b3d;
            min-height: 43px;
        }

        .add-staff-modal .form-control:focus,
        .add-staff-modal .form-select:focus {
            background: #fff;
            border-color: #4d9a92;
            box-shadow: 0 0 0 .2rem rgba(77, 154, 146, .14);
            color: #183b3d;
        }

        .add-staff-modal .form-select option {
            background: #fff;
            color: #243d45;
        }

        .add-staff-modal .input-group-text {
            background: #173f42;
            border-color: #173f42;
            color: #f7ca53;
            min-width: 43px;
            justify-content: center;
        }

        .add-staff-modal .input-group .form-control,
        .add-staff-modal .input-group .form-select {
            border-left: 0;
        }

        .add-staff-modal .section-label {
            color: #5a7778;
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            border-bottom: 1px solid #dbe8e5;
            padding-bottom: .55rem;
        }

        .add-staff-modal .info-banner,
        .add-staff-modal .warn-banner {
            border-radius: 10px;
            display: flex;
            align-items: flex-start;
            gap: .65rem;
            font-size: .86rem;
            line-height: 1.45;
            padding: .75rem .9rem;
        }

        .add-staff-modal .info-banner {
            background: #edf8f7;
            border: 1px solid #b9e0da;
            color: #276d68;
        }

        .add-staff-modal .warn-banner {
            background: #fff8e9;
            border: 1px solid #f0d79b;
            color: #926a14;
        }

        .add-staff-modal .text-muted {
            color: #607b7c !important;
        }

        .add-staff-modal #regenerateStaffPassword {
            background: #fff;
            border-color: #aabfbd;
            color: #38696a;
        }

        .add-staff-modal .btn-cancel {
            color: #426667;
            border-color: #9ab5b2;
        }

        .add-staff-modal .btn-create-staff {
            background: #d5643f;
            border-color: #d5643f;
            color: #fff;
        }

        .add-staff-modal .btn-create-staff:hover {
            background: #b94e2e;
            border-color: #b94e2e;
            color: #fff;
        }

        .add-staff-modal .btn {
            min-height: 40px;
        }

        .add-staff-modal .account-target {
            display: grid;
            gap: .2rem;
            padding: .8rem .9rem;
            border: 1px solid #b9e0da;
            border-radius: 10px;
            background: #edf8f7;
            color: #183b3d;
        }

        .add-staff-modal .account-target-label {
            color: #5a7778;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .add-staff-modal .account-target strong {
            font-size: 1rem;
        }

        .add-staff-modal .account-target span:last-child {
            color: #426667;
            font-size: .86rem;
            overflow-wrap: anywhere;
        }

        @media (max-width: 575px) {
            .add-staff-modal .modal-dialog {
                margin: .5rem;
            }

            .add-staff-modal .modal-header,
            .add-staff-modal .modal-body {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .add-staff-modal .modal-footer {
                padding: .75rem 1rem;
            }

            .add-staff-modal .modal-footer .btn {
                flex: 1 1 100%;
            }
        }

        /* ── NAVBAR ───────────────────────────────────────────── */
        .navbar {
            background-color: #000 !important;
            border-bottom: 1px solid var(--border-color);
            padding: 0.6rem 1rem;
        }

        .navbar-brand {
            font-size: clamp(0.85rem, 2.5vw, 1.1rem);
            white-space: nowrap;
        }

        .navbar .logout-link {
            font-size: 0.8rem;
            padding: 4px 10px;
        }

        .navbar .super-label {
            font-size: 0.8rem;
            white-space: nowrap;
        }

        /* ── STAT CARDS ───────────────────────────────────────── */
        .stat-card {
            background: #1e1e1e;
            border-radius: 10px;
            padding: 0.9rem 1.1rem;
            height: 100%;
        }

        .stat-card h6 {
            font-size: 0.7rem;
            letter-spacing: 0.5px;
            margin-bottom: 0.3rem;
        }

        .stat-card h3 {
            font-size: clamp(1.3rem, 4vw, 1.75rem);
            margin-bottom: 0;
        }

        /* ── TABS ─────────────────────────────────────────────── */
        .nav-tabs {
            flex-wrap: nowrap;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            border-bottom: none;
        }

        .nav-tabs::-webkit-scrollbar {
            display: none;
        }

        .nav-tabs .nav-item {
            flex-shrink: 0;
        }

        .nav-tabs .nav-link {
            color: var(--gold);
            background: #000;
            border: 1px solid var(--border-color);
            border-bottom: none;
            font-size: clamp(0.78rem, 2.5vw, 0.95rem);
            padding: 0.5rem 0.85rem;
            white-space: nowrap;
        }

        .nav-tabs .nav-link i {
            margin-right: 4px;
        }

        .nav-tabs .nav-link.active {
            color: #fff;
            background: var(--card-bg);
            border-color: var(--gold) var(--gold) transparent var(--gold);
            font-weight: 700;
        }

        .tab-content {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-top: none;
            padding: clamp(12px, 3vw, 20px);
            border-radius: 0 0 10px 10px;
        }

        /* ── TABLE (desktop) ──────────────────────────────────── */
        .table-dark-custom {
            --bs-table-bg: var(--card-bg);
            --bs-table-color: #e0e0e0;
            --bs-table-border-color: var(--border-color);
            --bs-table-hover-bg: #2a2a2a;
            --bs-table-hover-color: #fff;
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 0;
        }

        .table-dark-custom thead th {
            background-color: #000;
            color: var(--gold);
            border-bottom: 2px solid var(--gold);
            padding: 14px 16px;
            font-size: 0.85rem;
            white-space: nowrap;
        }

        .table-dark-custom tbody td {
            padding: 13px 16px;
            vertical-align: middle;
        }

        /* ── MOBILE CARD LIST (hidden on desktop) ─────────────── */
        .user-cards {
            display: none;
        }

        @media (max-width: 767px) {
            .desktop-table {
                display: none !important;
            }

            .user-cards {
                display: block;
            }
        }

        .user-card {
            background: var(--card-bg-2);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 10px;
        }

        .user-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .user-card-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            margin-bottom: 10px;
        }

        .user-card-meta-item {
            background: #121212;
            border-radius: 6px;
            padding: 6px 8px;
        }

        .user-card-meta-item .meta-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #888;
            display: block;
        }

        .user-card-meta-item .meta-value {
            font-size: 0.85rem;
            font-weight: 600;
            color: #e0e0e0;
        }

        .user-card-meta-item.full-width {
            grid-column: 1 / -1;
        }

        .user-card-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .user-card-actions .action-btn {
            flex: 1 1 auto;
            justify-content: center;
            min-width: 40px;
        }

        /* ── ACTION BUTTONS ───────────────────────────────────── */
        .action-btn {
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 0.82rem;
            border: none;
            cursor: pointer;
            transition: opacity 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-edit {
            background: #0d6efd;
            color: white;
        }

        .btn-reset {
            background: #ffc107;
            color: #000;
        }

        .btn-toggle-disable {
            background: #dc3545;
            color: white;
        }

        .btn-toggle-enable {
            background: #28a745;
            color: white;
        }

        .btn-eye {
            background: #17a2b8;
            color: white;
        }

        .btn-email {
            background: #0dcaf0;
            color: #000;
        }

        .btn-verify {
            background: #6f42c1;
            color: white;
        }

        .action-btn:hover {
            opacity: 0.85;
            color: inherit;
        }

        /* ── AVATAR ───────────────────────────────────────────── */
        .client-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1rem;
            flex-shrink: 0;
        }

        /* ── BADGE ────────────────────────────────────────────── */
        /* ── UNVERIFIED ROW HIGHLIGHT ─────────────────────────── */
        tr.row-unverified td {
            background-color: rgba(111, 66, 193, 0.07) !important;
        }

        .user-card.card-unverified {
            border-color: rgba(111, 66, 193, 0.45);
            background: rgba(111, 66, 193, 0.06);
        }

        /* ── MODAL ────────────────────────────────────────────── */
        .modal-header {
            background: linear-gradient(135deg, #2a2a2a, #111);
            color: white;
            border-bottom: 1px solid #333;
        }

        .modal-content {
            border-radius: 16px;
            overflow: hidden;
            background-color: var(--card-bg);
            color: #fff;
        }

        .stat-mini {
            background: #121212;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            border: 1px solid #333;
        }

        .info-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #888;
        }

        .info-value {
            font-weight: 600;
            color: #e0e0e0;
        }

        /* ── SEARCH BOX ───────────────────────────────────────── */
        .search-box {
            background: #121212;
            border: 1px solid var(--border-color);
            color: #e0e0e0;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 0.88rem;
            width: 100%;
            max-width: 260px;
        }

        .search-box:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 0 2px rgba(212, 175, 55, 0.15);
        }

        .search-box::placeholder {
            color: #666;
        }

        /* ── TAB TOOLBAR ──────────────────────────────────────── */
        .tab-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 14px;
        }

        /* ── PAGE HEADER ──────────────────────────────────────── */
        .page-header h1 {
            font-size: clamp(1.2rem, 4vw, 1.75rem);
        }

        /* ── PAGINATION ───────────────────────────────────────── */
        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 14px;
        }

        .pagination-info {
            font-size: 0.8rem;
            color: #888;
        }

        .pagination-controls {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }

        .page-btn {
            background: #121212;
            border: 1px solid #333;
            color: #e0e0e0;
            border-radius: 6px;
            padding: 5px 10px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
            min-width: 32px;
            text-align: center;
        }

        .page-btn:hover {
            border-color: var(--gold);
            color: var(--gold);
        }

        .page-btn.active {
            background: var(--gold);
            border-color: var(--gold);
            color: #000;
            font-weight: 700;
        }

        .page-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .page-btn.dots {
            cursor: default;
            border-color: transparent;
            background: transparent;
        }

        .page-btn.dots:hover {
            color: #e0e0e0;
            border-color: transparent;
        }

        /* ── RESPONSIVE TWEAKS ────────────────────────────────── */
        @media (max-width: 575px) {
            .tab-content {
                padding: 12px;
            }

            .stat-card {
                padding: 0.75rem;
            }
        }

        /* Page-specific light surface.  This intentionally overrides the old
           dark tokens above so the cards, tabs and table read as one system. */
        body.user-management-page {
            background: #f6f8f8;
            color: #18333b;
            min-height: 100vh;
        }

        .user-management-page .navbar {
            background: #fff !important;
            border-bottom: 1px solid #dfe8e8;
            box-shadow: 0 2px 12px rgba(20, 61, 74, .08);
            padding: .75rem 1rem;
        }

        .user-management-page .navbar-brand {
            color: #18333b !important;
            letter-spacing: .01em;
        }

        .user-management-page .text-primary-gold,
        .user-management-page .text-gold {
            color: #c15c37 !important;
        }

        .user-management-shell {
            max-width: 1440px;
            margin: 0 auto;
        }

        .user-management-page .page-header {
            padding: .75rem 0 .25rem;
        }

        .user-management-page .page-header h1 {
            color: #18333b !important;
            font-weight: 700;
        }

        .user-management-page .btn-outline-warning {
            border-color: #d9784f;
            color: #b94e2e;
        }

        .user-management-page .btn-outline-warning:hover {
            background: #d9784f;
            border-color: #d9784f;
            color: #fff;
        }

        .user-management-page .stat-card {
            background: #fff;
            border: 1px solid #e1eaea;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(20, 61, 74, .06);
            min-height: 102px;
        }

        .user-management-page .stat-card h6 {
            color: #63777a !important;
            font-weight: 700;
        }

        .user-management-page .stat-card h3,
        .user-management-page .stat-card .text-white {
            color: #18333b !important;
        }

        .user-management-page .nav-tabs {
            gap: .35rem;
            border: 0;
        }

        .user-management-page .nav-tabs .nav-link {
            background: transparent;
            border: 1px solid transparent;
            border-radius: 9px 9px 0 0;
            color: #63777a;
            padding: .7rem 1rem;
            font-weight: 600;
        }

        .user-management-page .nav-tabs .nav-link:hover {
            background: #edf5f3;
            color: #18333b;
        }

        .user-management-page .nav-tabs .nav-link.active {
            background: #fff;
            border-color: #dfe8e8 #dfe8e8 #fff;
            color: #18333b;
            box-shadow: 0 -2px 8px rgba(20, 61, 74, .04);
        }

        .user-management-page .tab-content {
            background: #fff;
            border: 1px solid #dfe8e8;
            border-radius: 0 12px 12px 12px;
            box-shadow: 0 6px 20px rgba(20, 61, 74, .06);
            padding: clamp(1rem, 2vw, 1.5rem);
        }

        .user-management-page .search-box {
            background: #fff;
            border-color: #cbdada;
            color: #18333b;
            max-width: 320px;
            min-height: 42px;
        }

        .user-management-page .search-box::placeholder {
            color: #839496;
        }

        .user-management-page .table-dark-custom {
            --bs-table-bg: #fff;
            --bs-table-color: #18333b;
            --bs-table-border-color: #e1eaea;
            --bs-table-hover-bg: #f4f9f8;
            --bs-table-hover-color: #18333b;
            border: 1px solid #e1eaea;
        }

        .user-management-page .table-dark-custom thead th {
            background: #f3f8f7;
            color: #385257;
            border-bottom: 1px solid #d7e4e2;
            padding: .9rem 1rem;
        }

        .user-management-page .table-dark-custom tbody td {
            padding: 1rem;
        }

        .user-management-page .client-avatar {
            box-shadow: inset 0 0 0 1px #e1eaea;
        }

        /* Customer profile: a compact, light information sheet that matches the
           rest of the User Hub while keeping the high-contrast data cards. */
        #clientModal .modal-dialog {
            max-width: 640px;
        }

        #clientModal .modal-content {
            background: #fff;
            border: 1px solid #dce8e6;
            border-radius: 20px;
            box-shadow: 0 24px 70px rgba(12, 48, 50, .28);
            color: #183b3d;
        }

        #clientModal .modal-header {
            min-height: 108px;
            padding: 1.4rem 1.25rem;
            background: linear-gradient(135deg, #f4faf9, #eef6f5);
            color: #183b3d;
            border-bottom: 1px solid #dbe8e5;
        }

        #clientModal .modal-title {
            color: #183b3d;
            font-size: 1.5rem;
            font-weight: 750;
            letter-spacing: -.02em;
        }

        #clientModal .text-gold {
            color: #d15d38 !important;
            font-size: .98rem;
        }

        #clientModal .client-avatar {
            width: 64px !important;
            height: 64px !important;
            font-size: 1.45rem !important;
            box-shadow: none;
        }

        #clientModal .btn-close {
            margin: 0;
            opacity: .42;
        }

        #clientModal .modal-body {
            padding: 1.8rem;
            background: #fff;
        }

        #clientModal .stat-mini {
            min-height: 84px;
            padding: 1.05rem 1.25rem;
            background: #121212;
            border: 1px solid #252525;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        #clientModal .info-label {
            margin-bottom: .24rem;
            color: #929292;
            font-size: .78rem;
            letter-spacing: .025em;
        }

        #clientModal .info-value {
            color: #e2e2e2;
            font-size: 1.03rem;
            line-height: 1.25;
        }

        #clientModal .info-value.text-info {
            color: #00c9ef !important;
        }

        #clientModal .info-value.text-success {
            color: #00a76f !important;
        }

        #clientModal #modal-status .badge {
            padding: .32rem .55rem;
            font-size: .8rem;
            font-weight: 700;
        }

        #clientModal #modal-unverified-notice {
            color: #543b78;
        }

        @media (max-width: 575px) {
            #clientModal .modal-dialog {
                margin: .75rem;
            }

            #clientModal .modal-header {
                min-height: 92px;
                padding: 1rem;
            }

            #clientModal .modal-title {
                font-size: 1.22rem;
            }

            #clientModal .client-avatar {
                width: 52px !important;
                height: 52px !important;
            }

            #clientModal .modal-body {
                padding: 1rem;
            }

            #clientModal .stat-mini {
                min-height: 78px;
                padding: .85rem 1rem;
            }
        }

        .user-management-page .pagination-info {
            color: #63777a;
        }

        .user-management-page .page-btn {
            background: #fff;
            border-color: #d7e4e2;
            color: #385257;
        }

        .user-management-page .page-btn.active {
            background: #d9784f;
            border-color: #d9784f;
            color: #fff;
        }

        .user-management-page .btn-success {
            background: #d9784f;
            border-color: #d9784f;
        }

        .user-management-page .btn-success:hover {
            background: #bd5736;
            border-color: #bd5736;
        }

        @media (max-width: 767px) {
            .user-management-page .tab-content {
                border-radius: 0 0 12px 12px;
            }

            .user-management-page .user-card {
                background: #fff;
                border-color: #e1eaea;
            }

            .user-management-page .user-card-meta-item {
                background: #f6f8f8;
            }

            .user-management-page .user-card-meta-item .meta-value {
                color: #18333b;
            }
        }
    </style>
</head>

<body class="user-management-page">

    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand text-primary-gold fw-bold" href="../admin-dashboard.php">
                LA ELENA FISHYALAN
            </a>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <span class="text-warning super-label d-none d-sm-inline">
                    <i class="fas fa-user-shield me-1"></i><?php echo htmlspecialchars($currentAdminRole); ?>
                </span>
                <button id="logout-btn" class="btn btn-sm btn-outline-danger">
                    <i class="fas fa-sign-out-alt d-sm-none"></i>
                    <span class="d-none d-sm-inline">Logout</span>
                </button>
            </div>
        </div>
    </nav>

    <div class="container-fluid user-management-shell py-3 px-3 px-lg-5">

        <!-- PAGE HEADER -->
        <div class="page-header mb-3">
            <a href="../admin-dashboard.php" class="btn btn-sm btn-outline-warning mb-2">
                <i class="fas fa-arrow-left"></i> <span class="d-none d-sm-inline">Admin Home</span>
            </a>
            <h1 class="mb-0 text-warning">
                <i class="fas fa-users-cog me-2"></i>User Management Hub
            </h1>
        </div>

        <!-- ALERTS (Converted to SweetAlert Toasts) -->
        <?php if (isset($_GET['success'])): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const Toast = Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 3000,
                        timerProgressBar: true,
                        background: '#1e1e1e',
                        color: '#fff',
                        didOpen: (toast) => {
                            toast.addEventListener('mouseenter', Swal.stopTimer);
                            toast.addEventListener('mouseleave', Swal.resumeTimer);
                        }
                    });
                    Toast.fire({
                        icon: 'success',
                        title: <?php echo json_encode(htmlspecialchars($_GET['success'])); ?>
                    });

                    // Clean URL
                    const url = new URL(window.location);
                    url.searchParams.delete('success');
                    window.history.replaceState({}, document.title, url);
                });
            </script>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const Toast = Swal.mixin({
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 4000,
                        timerProgressBar: true,
                        background: '#1e1e1e',
                        color: '#fff',
                        didOpen: (toast) => {
                            toast.addEventListener('mouseenter', Swal.stopTimer);
                            toast.addEventListener('mouseleave', Swal.resumeTimer);
                        }
                    });
                    Toast.fire({
                        icon: 'error',
                        title: <?php echo json_encode(htmlspecialchars($_GET['error'])); ?>
                    });

                    // Clean URL
                    const url = new URL(window.location);
                    url.searchParams.delete('error');
                    window.history.replaceState({}, document.title, url);
                });
            </script>
        <?php endif; ?>

        <!-- ── STATS (dynamic) ───────────────────────────────── -->
        <div id="stats-admins" class="stats-section mb-3">
            <div class="row g-2">
                <div class="col-6 col-md-4">
                    <div class="stat-card" style="border-left:4px solid var(--gold);">
                        <h6 class="text-gold text-uppercase">Total Admins</h6>
                        <h3 class="text-white">
                            <?php echo $totalAdmins; ?>
                            <small class="text-gold" style="font-size:0.9rem;">/ <?php echo $superAdmins; ?> Super</small>
                        </h3>
                    </div>
                </div>
            </div>
        </div>

        <div id="stats-staff" class="stats-section mb-3" style="display:none;">
            <div class="row g-2">
                <div class="col-6 col-md-4">
                    <div class="stat-card" style="border-left:4px solid #17a2b8;">
                        <h6 class="text-gold text-uppercase">Total Staff</h6>
                        <h3 class="text-white"><?php echo $totalStaff; ?></h3>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="stat-card" style="border-left:4px solid #28a745;">
                        <h6 class="text-gold text-uppercase">Active</h6>
                        <h3 class="text-success"><?php echo $activeStaff; ?></h3>
                    </div>
                </div>
                <div class="col-6 col-md-4 mt-2 mt-md-0">
                    <div class="stat-card" style="border-left:4px solid #dc3545;">
                        <h6 class="text-gold text-uppercase">Inactive / Pending</h6>
                        <h3 class="text-warning"><?php echo $inactiveStaff; ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <div id="stats-customers" class="stats-section mb-3" style="display:none;">
            <div class="row g-2">
                <div class="col-6 col-md-4">
                    <div class="stat-card" style="border-left:4px solid #667eea;">
                        <h6 class="text-gold text-uppercase">Total Customers</h6>
                        <h3 class="text-white">
                            <?php echo $totalCustomers; ?>
                            <?php if ($unverifiedCustomers > 0): ?>
                                <small style="font-size:0.75rem;color:#a78bfa;">/ <?php echo $unverifiedCustomers; ?> unverified</small>
                            <?php endif; ?>
                        </h3>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="stat-card" style="border-left:4px solid #2ecc71;">
                        <h6 class="text-gold text-uppercase">Lifetime Spend</h6>
                        <h3 class="text-success">₱<?php echo number_format($totalCustomerSpent, 2); ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── TABS ──────────────────────────────────────────── -->
        <ul class="nav nav-tabs" id="userManagementTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="admins-tab" data-bs-toggle="tab" data-bs-target="#admins" type="button" role="tab">
                    <i class="fas fa-crown"></i><span class="d-none d-sm-inline"> Admins</span>
                    <span class="d-sm-none"> Admins</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="staff-tab" data-bs-toggle="tab" data-bs-target="#staff" type="button" role="tab">
                    <i class="fas fa-user-tie"></i><span class="d-none d-sm-inline"> Staff</span>
                    <span class="d-sm-none"> Staff</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="customers-tab" data-bs-toggle="tab" data-bs-target="#customers" type="button" role="tab">
                    <i class="fas fa-users"></i><span class="d-none d-sm-inline"> Customers</span>
                    <span class="d-sm-none"> Members</span>
                    <?php if ($unverifiedCustomers > 0): ?>
                        <span class="badge rounded-pill ms-1" style="background:#6f42c1;font-size:0.65rem;"><?php echo $unverifiedCustomers; ?></span>
                    <?php endif; ?>
                </button>
            </li>
        </ul>

        <div class="tab-content" id="userManagementTabsContent">

            <!-- ══ ADMINS TAB ══════════════════════════════════ -->
            <div class="tab-pane fade show active" id="admins" role="tabpanel">
                <div class="tab-toolbar">
                    <input type="text" class="search-box" id="search-admins" placeholder="🔍 Search admins…" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
                    <?php if ($isSuperAdmin): ?>
                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#createAdminModal">
                            <i class="fas fa-user-plus me-1"></i>New Admin
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Desktop Table -->
                <div class="desktop-table table-responsive">
                    <table class="table table-dark-custom table-hover" id="table-admins">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admins as $admin): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="client-avatar" style="width:30px;height:30px;">
                                                <img src="<?php echo getProfileAvatar($admin['profile_picture'] ?? '', $admin['first_name'], $admin['last_name']); ?>" alt="Avatar" class="rounded-circle" style="width:100%;height:100%;object-fit:cover;">
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']); ?></strong><br>
                                                <small class="text-gold"><?php echo htmlspecialchars($admin['phone'] ?? ''); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($admin['email']); ?></td>
                                    <td>
                                        <?php if ($admin['role'] === 'Super Admin'): ?>
                                            <span class="badge bg-warning text-dark"><i class="fas fa-star me-1"></i>Super Admin</span>
                                        <?php else: ?>
                                            <span class="badge bg-info text-dark">Admin</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <?php if ($isSuperAdmin || $admin['admin_id'] == $_SESSION['user_id']): ?>
                                            <button type="button" class="action-btn btn-edit me-1 edit-admin-trigger" title="Edit admin profile" aria-label="Edit admin profile" data-bs-toggle="modal" data-bs-target="#editAdminModal"
                                                data-id="<?php echo $admin['admin_id']; ?>" data-first-name="<?php echo htmlspecialchars($admin['first_name'], ENT_QUOTES); ?>" data-last-name="<?php echo htmlspecialchars($admin['last_name'], ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($admin['email'], ENT_QUOTES); ?>" data-phone="<?php echo htmlspecialchars($admin['phone'] ?? '', ENT_QUOTES); ?>" data-role="<?php echo htmlspecialchars($admin['role'], ENT_QUOTES); ?>"><i class="fas fa-edit"></i></button>
                                            <button type="button" class="action-btn btn-email me-1 change-email-trigger" title="Change admin email" aria-label="Change admin email" data-bs-toggle="modal" data-bs-target="#changeEmailModal"
                                                data-type="admin" data-id="<?php echo $admin['admin_id']; ?>" data-name="<?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name'], ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($admin['email'], ENT_QUOTES); ?>"><i class="fas fa-envelope"></i></button>
                                            <button type="button" class="action-btn btn-reset me-1 reset-password-trigger" title="Reset admin password" aria-label="Reset admin password" data-bs-toggle="modal" data-bs-target="#resetPasswordModal"
                                                data-type="admin" data-id="<?php echo $admin['admin_id']; ?>" data-name="<?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name'], ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($admin['email'], ENT_QUOTES); ?>"><i class="fas fa-key"></i></button>
                                        <?php endif; ?>
                                        <?php if ($isSuperAdmin && $admin['admin_id'] != $_SESSION['user_id'] && $admin['role'] !== 'Super Admin'): ?>
                                            <button type="button" class="action-btn btn-toggle-disable btn-delete"
                                                data-type="admin"
                                                data-id="<?php echo $admin['admin_id']; ?>"
                                                data-name="<?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']); ?>"
                                                title="Delete admin account" aria-label="Delete admin account"><i class="fas fa-trash"></i></button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Cards -->
                <div class="user-cards" id="cards-admins">
                    <?php foreach ($admins as $admin): ?>
                        <div class="user-card" data-search="<?php echo strtolower(htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name'] . ' ' . $admin['email'])); ?>">
                            <div class="user-card-header">
                                <div class="client-avatar" style="width:38px;height:38px;">
                                    <img src="<?php echo getProfileAvatar($admin['profile_picture'] ?? '', $admin['first_name'], $admin['last_name']); ?>" alt="Avatar" class="rounded-circle" style="width:100%;height:100%;object-fit:cover;">
                                </div>
                                <div class="flex-grow-1 overflow-hidden">
                                    <strong class="d-block text-truncate"><?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']); ?></strong>
                                    <small class="text-gold"><?php echo htmlspecialchars($admin['phone'] ?? '—'); ?></small>
                                </div>
                                <?php if ($admin['role'] === 'Super Admin'): ?>
                                    <span class="badge bg-warning text-dark ms-auto flex-shrink-0"><i class="fas fa-star"></i></span>
                                <?php else: ?>
                                    <span class="badge bg-info text-dark ms-auto flex-shrink-0">Admin</span>
                                <?php endif; ?>
                            </div>
                            <div class="user-card-meta">
                                <div class="user-card-meta-item full-width">
                                    <span class="meta-label">Email</span>
                                    <span class="meta-value" style="font-size:0.78rem;word-break:break-all;"><?php echo htmlspecialchars($admin['email']); ?></span>
                                </div>
                            </div>
                            <div class="user-card-actions">
                                <?php if ($isSuperAdmin || $admin['admin_id'] == $_SESSION['user_id']): ?>
                                    <button type="button" class="action-btn btn-edit edit-admin-trigger" title="Edit" data-bs-toggle="modal" data-bs-target="#editAdminModal"
                                        data-id="<?php echo $admin['admin_id']; ?>" data-first-name="<?php echo htmlspecialchars($admin['first_name'], ENT_QUOTES); ?>" data-last-name="<?php echo htmlspecialchars($admin['last_name'], ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($admin['email'], ENT_QUOTES); ?>" data-phone="<?php echo htmlspecialchars($admin['phone'] ?? '', ENT_QUOTES); ?>" data-role="<?php echo htmlspecialchars($admin['role'], ENT_QUOTES); ?>"><i class="fas fa-edit"></i> Edit</button>
                                    <button type="button" class="action-btn btn-email change-email-trigger" title="Change admin email" data-bs-toggle="modal" data-bs-target="#changeEmailModal"
                                        data-type="admin" data-id="<?php echo $admin['admin_id']; ?>" data-name="<?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name'], ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($admin['email'], ENT_QUOTES); ?>"><i class="fas fa-envelope"></i> Email</button>
                                    <button type="button" class="action-btn btn-reset reset-password-trigger" title="Reset admin password" data-bs-toggle="modal" data-bs-target="#resetPasswordModal"
                                        data-type="admin" data-id="<?php echo $admin['admin_id']; ?>" data-name="<?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name'], ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($admin['email'], ENT_QUOTES); ?>"><i class="fas fa-key"></i> Reset</button>
                                <?php endif; ?>
                                <?php if ($isSuperAdmin && $admin['admin_id'] != $_SESSION['user_id'] && $admin['role'] !== 'Super Admin'): ?>
                                    <button type="button" class="action-btn btn-toggle-disable btn-delete"
                                        data-type="admin"
                                        data-id="<?php echo $admin['admin_id']; ?>"
                                        data-name="<?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']); ?>"
                                        title="Delete admin account"><i class="fas fa-trash"></i> Delete</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Admins Pagination -->
                <div class="pagination-bar" id="pagination-admins"></div>
            </div>

            <!-- ══ STAFF TAB ═══════════════════════════════════ -->
            <div class="tab-pane fade" id="staff" role="tabpanel">
                <div class="tab-toolbar">
                    <input type="text" class="search-box" id="search-staff" placeholder="🔍 Search staff…" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
                    <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addStaffModal">
                        <i class="fas fa-user-plus me-1"></i>New Staff
                    </button>
                </div>

                <!-- Desktop Table -->
                <div class="desktop-table table-responsive">
                    <table class="table table-dark-custom table-hover" id="table-staff">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Last Login</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staffs as $s): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="client-avatar" style="width:30px;height:30px;">
                                                <img src="<?php echo getProfileAvatar($s['profile_picture'] ?? '', $s['first_name'], $s['last_name']); ?>" alt="Avatar" class="rounded-circle" style="width:100%;height:100%;object-fit:cover;">
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?></strong><br>
                                                <small class="text-gold"><?php echo htmlspecialchars($s['phone'] ?? ''); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($s['email']); ?></td>
                                    <td><?php echo htmlspecialchars($s['role']); ?></td>
                                    <td><span class="badge <?php echo getStatusBadgeClass($s['status']); ?>"><?php echo htmlspecialchars($s['status']); ?></span></td>
                                    <td><small><?php echo $s['last_login'] ? date('M d, Y H:i', strtotime($s['last_login'])) : '<span class="text-gold">Never</span>'; ?></small></td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="action-btn btn-edit me-1 edit-staff-trigger" title="Edit staff profile" aria-label="Edit staff profile"
                                            data-bs-toggle="modal" data-bs-target="#editStaffModal"
                                            data-id="<?php echo $s['id']; ?>" data-first-name="<?php echo htmlspecialchars($s['first_name'], ENT_QUOTES); ?>"
                                            data-last-name="<?php echo htmlspecialchars($s['last_name'], ENT_QUOTES); ?>" data-phone="<?php echo htmlspecialchars($s['phone'] ?? '', ENT_QUOTES); ?>"
                                            data-role="<?php echo htmlspecialchars($s['role'], ENT_QUOTES); ?>" data-status="<?php echo htmlspecialchars($s['status'], ENT_QUOTES); ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button type="button" class="action-btn btn-email me-1 change-email-trigger" title="Change staff email" aria-label="Change staff email" data-bs-toggle="modal" data-bs-target="#changeEmailModal" data-type="staff" data-id="<?php echo $s['id']; ?>" data-name="<?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name'], ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($s['email'], ENT_QUOTES); ?>"><i class="fas fa-envelope"></i></button>
                                        <button type="button" class="action-btn btn-reset me-1 reset-password-trigger" title="Reset staff password" aria-label="Reset staff password" data-bs-toggle="modal" data-bs-target="#resetPasswordModal" data-type="staff" data-id="<?php echo $s['id']; ?>" data-name="<?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name'], ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($s['email'], ENT_QUOTES); ?>"><i class="fas fa-key"></i></button>
                                        <?php if (strtoupper($s['status']) === 'DISABLED'): ?>
                                            <button type="button" class="action-btn btn-toggle-enable btn-status-toggle me-1"
                                                data-id="<?php echo $s['id']; ?>" data-action="enable"
                                                data-name="<?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?>"
                                                title="Enable staff account" aria-label="Enable staff account"><i class="fas fa-play"></i></button>
                                        <?php elseif (strtoupper($s['status']) === 'ACTIVE'): ?>
                                            <button type="button" class="action-btn btn-toggle-disable btn-status-toggle me-1"
                                                data-id="<?php echo $s['id']; ?>" data-action="disable"
                                                data-name="<?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?>"
                                                title="Suspend staff account" aria-label="Suspend staff account"><i class="fas fa-pause"></i></button>
                                        <?php endif; ?>
                                        <button type="button" class="action-btn btn-toggle-disable btn-delete"
                                            data-type="staff" data-id="<?php echo $s['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?>"
                                            title="Delete staff account" aria-label="Delete staff account"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Cards -->
                <div class="user-cards" id="cards-staff">
                    <?php foreach ($staffs as $s): ?>
                        <div class="user-card" data-search="<?php echo strtolower(htmlspecialchars($s['first_name'] . ' ' . $s['last_name'] . ' ' . $s['email'] . ' ' . $s['role'])); ?>">
                            <div class="user-card-header">
                                <div class="client-avatar" style="width:38px;height:38px;">
                                    <img src="<?php echo getProfileAvatar($s['profile_picture'] ?? '', $s['first_name'], $s['last_name']); ?>" alt="Avatar" class="rounded-circle" style="width:100%;height:100%;object-fit:cover;">
                                </div>
                                <div class="flex-grow-1 overflow-hidden">
                                    <strong class="d-block text-truncate"><?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?></strong>
                                    <small class="text-gold"><?php echo htmlspecialchars($s['role']); ?></small>
                                </div>
                                <span class="badge <?php echo getStatusBadgeClass($s['status']); ?> ms-auto flex-shrink-0"><?php echo htmlspecialchars($s['status']); ?></span>
                            </div>
                            <div class="user-card-meta">
                                <div class="user-card-meta-item full-width">
                                    <span class="meta-label">Email</span>
                                    <span class="meta-value" style="font-size:0.78rem;word-break:break-all;"><?php echo htmlspecialchars($s['email']); ?></span>
                                </div>
                                <div class="user-card-meta-item">
                                    <span class="meta-label">Phone</span>
                                    <span class="meta-value"><?php echo htmlspecialchars($s['phone'] ?? '—'); ?></span>
                                </div>
                                <div class="user-card-meta-item">
                                    <span class="meta-label">Last Login</span>
                                    <span class="meta-value" style="font-size:0.78rem;"><?php echo $s['last_login'] ? date('M d, Y', strtotime($s['last_login'])) : 'Never'; ?></span>
                                </div>
                            </div>
                            <div class="user-card-actions">
                                <button type="button" class="action-btn btn-edit edit-staff-trigger" title="Edit" data-bs-toggle="modal" data-bs-target="#editStaffModal"
                                    data-id="<?php echo $s['id']; ?>" data-first-name="<?php echo htmlspecialchars($s['first_name'], ENT_QUOTES); ?>"
                                    data-last-name="<?php echo htmlspecialchars($s['last_name'], ENT_QUOTES); ?>" data-phone="<?php echo htmlspecialchars($s['phone'] ?? '', ENT_QUOTES); ?>"
                                    data-role="<?php echo htmlspecialchars($s['role'], ENT_QUOTES); ?>" data-status="<?php echo htmlspecialchars($s['status'], ENT_QUOTES); ?>">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button type="button" class="action-btn btn-email change-email-trigger" data-bs-toggle="modal" data-bs-target="#changeEmailModal" data-type="staff" data-id="<?php echo $s['id']; ?>" data-name="<?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name'], ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($s['email'], ENT_QUOTES); ?>"><i class="fas fa-envelope"></i> Email</button>
                                <button type="button" class="action-btn btn-reset reset-password-trigger" data-bs-toggle="modal" data-bs-target="#resetPasswordModal" data-type="staff" data-id="<?php echo $s['id']; ?>" data-name="<?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name'], ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($s['email'], ENT_QUOTES); ?>"><i class="fas fa-key"></i> Reset</button>
                                <?php if (strtoupper($s['status']) === 'DISABLED'): ?>
                                    <button type="button" class="action-btn btn-toggle-enable btn-status-toggle"
                                        data-id="<?php echo $s['id']; ?>" data-action="enable"
                                        data-name="<?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?>"><i class="fas fa-play"></i> Enable</button>
                                <?php elseif (strtoupper($s['status']) === 'ACTIVE'): ?>
                                    <button type="button" class="action-btn btn-toggle-disable btn-status-toggle"
                                        data-id="<?php echo $s['id']; ?>" data-action="disable"
                                        data-name="<?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?>"><i class="fas fa-pause"></i> Suspend</button>
                                <?php endif; ?>
                                <button type="button" class="action-btn btn-toggle-disable btn-delete"
                                    data-type="staff" data-id="<?php echo $s['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($s['first_name'] . ' ' . $s['last_name']); ?>"><i class="fas fa-trash"></i> Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Staff Pagination -->
                <div class="pagination-bar" id="pagination-staff"></div>
            </div>

            <!-- ══ CUSTOMERS TAB ═══════════════════════════════ -->
            <div class="tab-pane fade" id="customers" role="tabpanel">
                <div class="tab-toolbar">
                    <input type="text" class="search-box" id="search-customers" placeholder="🔍 Search customers…" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">
                    <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#createCustomerModal">
                        <i class="fas fa-user-plus me-1"></i>New Customer
                    </button>
                </div>

                <?php if ($unverifiedCustomers > 0): ?>
                    <div class="alert border-0 mb-3 d-flex align-items-center gap-2" style="background:rgba(111,66,193,0.15);border-left:4px solid #6f42c1 !important;border-radius:8px;">
                        <i class="fas fa-user-clock" style="color:#a78bfa;font-size:1.1rem;"></i>
                        <div>
                            <strong style="color:#a78bfa;"><?php echo $unverifiedCustomers; ?> unverified account<?php echo $unverifiedCustomers > 1 ? 's' : ''; ?></strong>
                            <span style="color:#ccc;font-size:0.88rem;"> — click the <i class="fas fa-user-check"></i> button to manually verify without OTP.</span>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Desktop Table -->
                <div class="desktop-table table-responsive">
                    <table class="table table-dark-custom table-hover" id="table-customers">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Orders</th>
                                <th>Total Spent</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($customers as $c): ?>
                                <tr class="<?php echo strtoupper($c['status']) === 'UNVERIFIED' ? 'row-unverified' : ''; ?>">
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="client-avatar" style="width:30px;height:30px;background:none;">
                                                <img src="<?php echo getProfileAvatar($c['profile_picture'] ?? '', $c['first_name'], $c['last_name']); ?>" alt="Avatar" class="rounded-circle" style="width:100%;height:100%;object-fit:cover;">
                                            </div>
                                            <div>
                                                <strong><?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name']); ?></strong><br>
                                                <small class="text-gold"><?php echo htmlspecialchars($c['email']); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo getStatusBadgeClass($c['status']); ?>">
                                            <?php if (strtoupper($c['status']) === 'UNVERIFIED'): ?>
                                                <i class="fas fa-clock me-1"></i>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($c['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo $c['membership_date'] ? date('M d, Y', strtotime($c['membership_date'])) : '—'; ?></td>
                                    <td><span class="badge bg-secondary rounded-pill"><?php echo $c['total_orders']; ?></span></td>
                                    <td class="text-success fw-bold">₱<?php echo number_format($c['total_spent'], 2); ?></td>
                                    <td class="text-end text-nowrap">
                                        <button class="action-btn btn-eye me-1" title="View customer profile" aria-label="View customer profile" data-bs-toggle="modal" data-bs-target="#clientModal"
                                            data-name="<?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name']); ?>"
                                            data-email="<?php echo htmlspecialchars($c['email']); ?>"
                                            data-phone="<?php echo htmlspecialchars($c['phone'] ?? '—'); ?>"
                                            data-address="<?php echo htmlspecialchars($c['address'] ?? '—'); ?>"
                                            data-joined="<?php echo $c['membership_date'] ? date('M d, Y', strtotime($c['membership_date'])) : '—'; ?>"
                                            data-orders="<?php echo $c['total_orders']; ?>"
                                            data-spent="₱<?php echo number_format($c['total_spent'], 2); ?>"
                                            data-status="<?php echo htmlspecialchars($c['status']); ?>"
                                            data-avatar="<?php echo getProfileAvatar($c['profile_picture'] ?? '', $c['first_name'], $c['last_name']); ?>">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <?php if (strtoupper($c['status']) === 'UNVERIFIED' || strtoupper($c['status']) === 'PENDING'): ?>
                                            <button type="button" class="action-btn btn-verify me-1 btn-verify-customer"
                                                data-id="<?php echo $c['id']; ?>"
                                                data-name="<?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name']); ?>"
                                                title="Manually verify this account" aria-label="Manually verify this account">
                                                <i class="fas fa-user-check"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="action-btn btn-email me-1 change-email-trigger" title="Change customer email" aria-label="Change customer email" data-bs-toggle="modal" data-bs-target="#changeEmailModal" data-type="customer" data-id="<?php echo $c['id']; ?>" data-name="<?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name'], ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($c['email'], ENT_QUOTES); ?>"><i class="fas fa-envelope"></i></button>
                                        <button type="button" class="action-btn btn-reset me-1 reset-password-trigger" title="Reset customer password" aria-label="Reset customer password" data-bs-toggle="modal" data-bs-target="#resetPasswordModal" data-type="customer" data-id="<?php echo $c['id']; ?>" data-name="<?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name'], ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($c['email'], ENT_QUOTES); ?>"><i class="fas fa-key"></i></button>

                                        <button type="button" class="action-btn btn-toggle-disable btn-delete"
                                            data-type="customer" data-id="<?php echo $c['id']; ?>"
                                            data-name="<?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name']); ?>"
                                            title="Delete customer account" aria-label="Delete customer account"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile Cards -->
                <div class="user-cards" id="cards-customers">
                    <?php foreach ($customers as $c): ?>
                        <div class="user-card <?php echo strtoupper($c['status']) === 'UNVERIFIED' ? 'card-unverified' : ''; ?>"
                            data-search="<?php echo strtolower(htmlspecialchars($c['first_name'] . ' ' . $c['last_name'] . ' ' . $c['email'])); ?>">
                            <div class="user-card-header">
                                <div class="client-avatar" style="width:38px;height:38px;background:none;">
                                    <img src="<?php echo getProfileAvatar($c['profile_picture'] ?? '', $c['first_name'], $c['last_name']); ?>" alt="Avatar" class="rounded-circle" style="width:100%;height:100%;object-fit:cover;">
                                </div>
                                <div class="flex-grow-1 overflow-hidden">
                                    <strong class="d-block text-truncate"><?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name']); ?></strong>
                                    <small class="text-gold" style="font-size:0.72rem;word-break:break-all;"><?php echo htmlspecialchars($c['email']); ?></small>
                                </div>
                                <span class="badge <?php echo getStatusBadgeClass($c['status']); ?> ms-auto flex-shrink-0">
                                    <?php if (strtoupper($c['status']) === 'UNVERIFIED'): ?><i class="fas fa-clock me-1"></i><?php endif; ?>
                                    <?php echo htmlspecialchars($c['status']); ?>
                                </span>
                            </div>
                            <div class="user-card-meta">
                                <div class="user-card-meta-item">
                                    <span class="meta-label">Joined</span>
                                    <span class="meta-value"><?php echo $c['membership_date'] ? date('M d, Y', strtotime($c['membership_date'])) : '—'; ?></span>
                                </div>
                                <div class="user-card-meta-item">
                                    <span class="meta-label">Orders</span>
                                    <span class="meta-value"><?php echo $c['total_orders']; ?></span>
                                </div>
                                <div class="user-card-meta-item">
                                    <span class="meta-label">Total Spent</span>
                                    <span class="meta-value text-success">₱<?php echo number_format($c['total_spent'], 2); ?></span>
                                </div>
                            </div>
                            <div class="user-card-actions">
                                <button class="action-btn btn-eye" data-bs-toggle="modal" data-bs-target="#clientModal"
                                    data-name="<?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name']); ?>"
                                    data-email="<?php echo htmlspecialchars($c['email']); ?>"
                                    data-phone="<?php echo htmlspecialchars($c['phone'] ?? '—'); ?>"
                                    data-address="<?php echo htmlspecialchars($c['address'] ?? '—'); ?>"
                                    data-joined="<?php echo $c['membership_date'] ? date('M d, Y', strtotime($c['membership_date'])) : '—'; ?>"
                                    data-orders="<?php echo $c['total_orders']; ?>"
                                    data-spent="₱<?php echo number_format($c['total_spent'], 2); ?>"
                                    data-status="<?php echo htmlspecialchars($c['status']); ?>"
                                    data-avatar="<?php echo getProfileAvatar($c['profile_picture'] ?? '', $c['first_name'], $c['last_name']); ?>">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <?php if (strtoupper($c['status']) === 'UNVERIFIED'): ?>
                                    <button type="button" class="action-btn btn-verify btn-verify-customer"
                                        data-id="<?php echo $c['id']; ?>"
                                        data-name="<?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name']); ?>">
                                        <i class="fas fa-user-check"></i> Verify
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="action-btn btn-email change-email-trigger" data-bs-toggle="modal" data-bs-target="#changeEmailModal" data-type="customer" data-id="<?php echo $c['id']; ?>" data-name="<?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name'], ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($c['email'], ENT_QUOTES); ?>"><i class="fas fa-envelope"></i> Email</button>
                                <button type="button" class="action-btn btn-reset reset-password-trigger" data-bs-toggle="modal" data-bs-target="#resetPasswordModal" data-type="customer" data-id="<?php echo $c['id']; ?>" data-name="<?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name'], ENT_QUOTES); ?>" data-email="<?php echo htmlspecialchars($c['email'], ENT_QUOTES); ?>"><i class="fas fa-key"></i> Reset</button>

                                <button type="button" class="action-btn btn-toggle-disable btn-delete"
                                    data-type="customer" data-id="<?php echo $c['id']; ?>"
                                    data-name="<?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name']); ?>"><i class="fas fa-trash"></i> Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Customers Pagination -->
                <div class="pagination-bar" id="pagination-customers"></div>
            </div>

        </div><!-- /tab-content -->
    </div><!-- /container -->

    <div class="modal fade add-staff-modal" id="addStaffModal" tabindex="-1" aria-labelledby="addStaffModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="../staffManagementCrud/addStaff.php">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addStaffModalLabel"><i class="fas fa-user-plus me-2 modal-title-icon"></i>Add Staff Member</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if ($addStaffError): ?>
                            <div class="alert alert-danger py-2"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($addStaffError); ?></div>
                        <?php endif; ?>
                        <div class="info-banner mb-3"><i class="fas fa-info-circle"></i><span>An email with the staff member's login credentials will be sent immediately upon account creation.</span></div>

                        <p class="section-label mb-3">Personal Information</p>
                        <div class="row g-3 mb-3">
                            <div class="col-12 col-sm-6"><label class="form-label" for="add_staff_first_name">First Name <span class="text-danger">*</span></label>
                                <div class="input-group"><span class="input-group-text"><i class="fas fa-user"></i></span><input type="text" id="add_staff_first_name" name="first_name" class="form-control" required autocomplete="given-name" value="<?php echo htmlspecialchars($addStaffForm['first_name'] ?? ''); ?>"></div>
                            </div>
                            <div class="col-12 col-sm-6"><label class="form-label" for="add_staff_last_name">Last Name <span class="text-danger">*</span></label>
                                <div class="input-group"><span class="input-group-text"><i class="fas fa-user"></i></span><input type="text" id="add_staff_last_name" name="last_name" class="form-control" required autocomplete="family-name" value="<?php echo htmlspecialchars($addStaffForm['last_name'] ?? ''); ?>"></div>
                            </div>
                        </div>

                        <p class="section-label mb-3">Contact &amp; Role</p>
                        <div class="mb-3"><label class="form-label" for="add_staff_email">Email Address <span class="text-danger">*</span></label>
                            <div class="input-group"><span class="input-group-text"><i class="fas fa-envelope"></i></span><input type="email" id="add_staff_email" name="email" class="form-control" required autocomplete="email" value="<?php echo htmlspecialchars($addStaffForm['email'] ?? ''); ?>"></div><small class="text-muted">This will be their login email. Must be unique.</small>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-12 col-sm-6"><label class="form-label" for="add_staff_phone">Phone Number</label>
                                <div class="input-group"><span class="input-group-text"><i class="fas fa-phone"></i></span><input type="tel" id="add_staff_phone" name="phone" class="form-control" autocomplete="tel" value="<?php echo htmlspecialchars($addStaffForm['phone'] ?? ''); ?>"></div>
                            </div>
                            <div class="col-12 col-sm-6"><label class="form-label" for="add_staff_role">Role <span class="text-danger">*</span></label>
                                <div class="input-group"><span class="input-group-text"><i class="fas fa-id-badge"></i></span><select id="add_staff_role" name="role" class="form-select" required>
                                        <?php foreach (getStaffRoleOptions() as $roleOption): ?><option value="<?php echo htmlspecialchars($roleOption); ?>" <?php echo (($addStaffForm['role'] ?? '') === $roleOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars($roleOption); ?></option><?php endforeach; ?>
                                    </select></div>
                            </div>
                        </div>

                        <p class="section-label mb-3">Security</p>
                        <label class="form-label" for="add_staff_password">Temporary Password <span class="text-danger">*</span></label>
                        <div class="input-group"><span class="input-group-text"><i class="fas fa-lock"></i></span><input type="text" id="add_staff_password" name="temp_password" class="form-control" required minlength="6" value="<?php echo htmlspecialchars($addStaffForm['temp_password'] ?? $generatedStaffPassword); ?>"><button type="button" class="btn btn-outline-secondary" id="regenerateStaffPassword" title="Regenerate password"><i class="fas fa-sync-alt"></i></button></div>
                        <div class="warn-banner mt-2"><i class="fas fa-exclamation-triangle"></i><span>You may keep the auto-generated password or set a custom one (minimum 6 characters). The staff member will receive it by email and should change it after logging in.</span></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-cancel" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning fw-bold btn-create-staff"><i class="fas fa-user-plus me-2"></i>Create Staff Account</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade add-staff-modal" id="editStaffModal" tabindex="-1" aria-labelledby="editStaffModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="viewUsers.php">
                    <input type="hidden" name="edit_staff_id" id="edit_staff_id">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editStaffModalLabel"><i class="fas fa-user-edit me-2 modal-title-icon"></i>Edit Staff Profile</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if ($editStaffError): ?><div class="alert alert-danger py-2"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($editStaffError); ?></div><?php endif; ?>
                        <div class="row g-3">
                            <div class="col-12 col-sm-6"><label class="form-label" for="edit_staff_first_name">First Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="edit_staff_first_name" name="first_name" required value="<?php echo htmlspecialchars($editStaffForm['first_name'] ?? ''); ?>"></div>
                            <div class="col-12 col-sm-6"><label class="form-label" for="edit_staff_last_name">Last Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="edit_staff_last_name" name="last_name" required value="<?php echo htmlspecialchars($editStaffForm['last_name'] ?? ''); ?>"></div>
                            <div class="col-12 col-sm-6"><label class="form-label" for="edit_staff_phone">Phone Number</label><input type="tel" class="form-control" id="edit_staff_phone" name="phone" value="<?php echo htmlspecialchars($editStaffForm['phone'] ?? ''); ?>"></div>
                            <div class="col-12 col-sm-6"><label class="form-label" for="edit_staff_role">Role <span class="text-danger">*</span></label><select class="form-select" id="edit_staff_role" name="role" required><?php foreach (getStaffRoleOptions() as $roleOption): ?><option value="<?php echo htmlspecialchars($roleOption); ?>" <?php echo (($editStaffForm['role'] ?? '') === $roleOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars($roleOption); ?></option><?php endforeach; ?></select></div>
                            <div class="col-12">
                                <p class="mb-0"><span class="text-gold">Status:</span> <span class="badge" id="edit_staff_status"></span></p>
                            </div>
                            <div class="col-12 border-top pt-3"><label class="form-label text-warning" for="edit_staff_password"><i class="fas fa-shield-alt me-1"></i>Require Admin Password <span class="text-danger">*</span></label>
                                <div class="input-group"><span class="input-group-text"><i class="fas fa-user-shield"></i></span><input type="password" class="form-control" id="edit_staff_password" name="admin_password" required placeholder="Verify your admin password"></div><small class="text-muted">Authorize this action with your own credentials.</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-cancel" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning fw-bold"><i class="fas fa-save me-2"></i>Save Changes</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- Account actions stay in context so administrators do not leave the User Hub. -->
    <div class="modal fade add-staff-modal" id="editAdminModal" tabindex="-1" aria-labelledby="editAdminModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" class="ajax-modal-form" id="editAdminForm">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editAdminModalLabel"><i class="fas fa-user-pen me-2 modal-title-icon"></i>Edit Admin Profile</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger py-2 d-none modal-form-error" role="alert"></div>
                        <div class="row g-3">
                            <div class="col-12 col-sm-6"><label class="form-label" for="edit_admin_first_name">First Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="edit_admin_first_name" name="first_name" required></div>
                            <div class="col-12 col-sm-6"><label class="form-label" for="edit_admin_last_name">Last Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="edit_admin_last_name" name="last_name" required></div>
                            <div class="col-12 col-sm-6"><label class="form-label" for="edit_admin_email">Email Address <span class="text-danger">*</span></label><input type="email" class="form-control" id="edit_admin_email" name="email" required></div>
                            <div class="col-12 col-sm-6"><label class="form-label" for="edit_admin_phone">Phone Number</label><input type="tel" class="form-control" id="edit_admin_phone" name="phone"></div>
                            <div class="col-12"><label class="form-label" for="edit_admin_role">Role <span class="text-danger">*</span></label>
                                <select class="form-select" id="edit_admin_role" name="role" required><option value="Admin">Admin</option><option value="Super Admin">Super Admin</option></select>
                                <input type="text" class="form-control d-none" id="edit_admin_role_readonly" readonly aria-label="Admin role">
                                <input type="hidden" id="edit_admin_role_hidden" disabled>
                            </div>
                            <div class="col-12 border-top pt-3"><label class="form-label text-warning" for="edit_admin_password"><i class="fas fa-shield-alt me-1"></i>Require Admin Password <span class="text-danger">*</span></label>
                                <div class="input-group"><span class="input-group-text"><i class="fas fa-user-shield"></i></span><input type="password" class="form-control" id="edit_admin_password" name="admin_password" required autocomplete="current-password" placeholder="Verify your admin password"><button type="button" class="btn btn-outline-secondary password-toggle" data-target="edit_admin_password" aria-label="Show password"><i class="fas fa-eye"></i></button></div><small class="text-muted">Authorize this action with your own credentials.</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-cancel" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning fw-bold"><i class="fas fa-save me-2"></i>Save Changes</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade add-staff-modal" id="changeEmailModal" tabindex="-1" aria-labelledby="changeEmailModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" class="ajax-modal-form" id="changeEmailForm">
                    <div class="modal-header"><h5 class="modal-title" id="changeEmailModalLabel"><i class="fas fa-envelope me-2 text-info"></i>Change Email</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body">
                        <div class="alert alert-danger py-2 d-none modal-form-error" role="alert"></div>
                        <div class="account-target mb-3"><span class="account-target-label">Modifying account for</span><strong id="change_email_name"></strong><span id="change_email_current"></span></div>
                        <label class="form-label" for="change_email_new">New Email Address <span class="text-danger">*</span></label>
                        <div class="input-group mb-3"><span class="input-group-text text-info"><i class="fas fa-paper-plane"></i></span><input type="email" class="form-control" id="change_email_new" name="new_email" required autocomplete="email" placeholder="name@example.com"></div>
                        <div class="border-top pt-3"><label class="form-label text-warning" for="change_email_password"><i class="fas fa-shield-alt me-1"></i>Require Admin Password <span class="text-danger">*</span></label>
                            <div class="input-group"><span class="input-group-text"><i class="fas fa-user-shield"></i></span><input type="password" class="form-control" id="change_email_password" name="admin_password" required autocomplete="current-password" placeholder="Verify your admin password"><button type="button" class="btn btn-outline-secondary password-toggle" data-target="change_email_password" aria-label="Show password"><i class="fas fa-eye"></i></button></div><small class="text-muted">Authorize this action with your own credentials.</small>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-cancel" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-info fw-bold"><i class="fas fa-save me-2"></i>Update Email</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade add-staff-modal" id="resetPasswordModal" tabindex="-1" aria-labelledby="resetPasswordModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" class="ajax-modal-form" id="resetPasswordForm">
                    <div class="modal-header"><h5 class="modal-title" id="resetPasswordModalLabel"><i class="fas fa-key me-2 text-gold"></i>Reset Password</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body">
                        <div class="alert alert-danger py-2 d-none modal-form-error" role="alert"></div>
                        <div class="account-target mb-3"><span class="account-target-label">Modifying account for</span><strong id="reset_password_name"></strong><span id="reset_password_current"></span></div>
                        <label class="form-label" for="reset_password_new">New Password <span class="text-danger">*</span></label>
                        <div class="input-group"><span class="input-group-text"><i class="fas fa-lock"></i></span><input type="password" class="form-control" id="reset_password_new" name="new_password" required minlength="6" autocomplete="new-password" placeholder="Enter new password"><button type="button" class="btn btn-outline-secondary password-toggle" data-target="reset_password_new" aria-label="Show password"><i class="fas fa-eye"></i></button></div><small class="text-muted">Minimum 6 characters.</small>
                        <div class="border-top pt-3 mt-3"><label class="form-label text-warning" for="reset_password_admin"><i class="fas fa-shield-alt me-1"></i>Require Admin Password <span class="text-danger">*</span></label>
                            <div class="input-group"><span class="input-group-text"><i class="fas fa-user-shield"></i></span><input type="password" class="form-control" id="reset_password_admin" name="admin_password" required autocomplete="current-password" placeholder="Verify your admin password"><button type="button" class="btn btn-outline-secondary password-toggle" data-target="reset_password_admin" aria-label="Show password"><i class="fas fa-eye"></i></button></div><small class="text-muted">Authorize this action with your own credentials.</small>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-cancel" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning fw-bold"><i class="fas fa-save me-2"></i>Reset Password</button></div>
                </form>
            </div>
        </div>
    </div>

    <?php if ($isSuperAdmin): ?>
        <div class="modal fade add-staff-modal" id="createAdminModal" tabindex="-1" aria-labelledby="createAdminModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
                <div class="modal-content">
                    <form method="POST" action="createAdmin.php">
                        <div class="modal-header">
                            <h5 class="modal-title" id="createAdminModalLabel"><i class="fas fa-user-shield me-2 modal-title-icon"></i>Create New Admin</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <?php if ($createAdminError): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($createAdminError); ?></div><?php endif; ?>
                            <div class="info-banner mb-3"><i class="fas fa-info-circle"></i><span>A welcome email with login credentials will be sent to the new admin immediately upon account creation.</span></div>
                            <p class="section-label mb-3">Personal Information</p>
                            <div class="row g-3 mb-3">
                                <div class="col-12 col-sm-6"><label class="form-label" for="admin_first_name">First Name <span class="text-danger">*</span></label>
                                    <div class="input-group"><span class="input-group-text"><i class="fas fa-user"></i></span><input id="admin_first_name" name="first_name" class="form-control" required value="<?php echo htmlspecialchars($createAdminForm['first_name'] ?? ''); ?>"></div>
                                </div>
                                <div class="col-12 col-sm-6"><label class="form-label" for="admin_last_name">Last Name <span class="text-danger">*</span></label>
                                    <div class="input-group"><span class="input-group-text"><i class="fas fa-user"></i></span><input id="admin_last_name" name="last_name" class="form-control" required value="<?php echo htmlspecialchars($createAdminForm['last_name'] ?? ''); ?>"></div>
                                </div>
                            </div>
                            <p class="section-label mb-3">Contact Details</p>
                            <div class="mb-3"><label class="form-label" for="admin_email">Email Address <span class="text-danger">*</span></label>
                                <div class="input-group"><span class="input-group-text"><i class="fas fa-envelope"></i></span><input type="email" id="admin_email" name="email" class="form-control" required value="<?php echo htmlspecialchars($createAdminForm['email'] ?? ''); ?>"></div><small class="text-muted">Must be unique. This will be the login email.</small>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-12 col-sm-6"><label class="form-label" for="admin_phone">Phone Number</label>
                                    <div class="input-group"><span class="input-group-text"><i class="fas fa-phone"></i></span><input type="tel" id="admin_phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($createAdminForm['phone'] ?? ''); ?>"></div>
                                </div>
                                <div class="col-12 col-sm-6"><label class="form-label" for="admin_role">Role <span class="text-danger">*</span></label>
                                    <div class="input-group"><span class="input-group-text"><i class="fas fa-user-cog"></i></span><select id="admin_role" name="role" class="form-select">
                                            <option value="Admin" <?php echo (($createAdminForm['role'] ?? 'Admin') === 'Admin') ? 'selected' : ''; ?>>Admin</option>
                                            <option value="Super Admin" <?php echo (($createAdminForm['role'] ?? '') === 'Super Admin') ? 'selected' : ''; ?>>Super Admin</option>
                                        </select></div>
                                </div>
                            </div>
                            <p class="section-label mb-3">Security</p><label class="form-label" for="admin_password">Password <span class="text-danger">*</span></label>
                            <div class="input-group"><span class="input-group-text"><i class="fas fa-lock"></i></span><input type="password" id="admin_password" name="password" class="form-control" required minlength="6" placeholder="Min. 6 characters"><button type="button" class="btn btn-outline-secondary" id="toggleAdminPassword"><i class="fas fa-eye"></i></button></div>
                            <div class="warn-banner mt-2"><i class="fas fa-exclamation-triangle"></i><span>The new admin will receive this password by email and must change it after first login.</span></div>
                        </div>
                        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-cancel" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning fw-bold btn-create-staff"><i class="fas fa-user-plus me-2"></i>Create Administrator Account</button></div>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="modal fade add-staff-modal" id="createCustomerModal" tabindex="-1" aria-labelledby="createCustomerModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="createCustomer.php">
                    <div class="modal-header">
                        <h5 class="modal-title" id="createCustomerModalLabel"><i class="fas fa-user-plus me-2 modal-title-icon"></i>Create Customer</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?php if ($createCustomerError): ?><div class="alert alert-danger py-2"><?php echo htmlspecialchars($createCustomerError); ?></div><?php endif; ?>
                        <div class="info-banner mb-3"><i class="fas fa-info-circle"></i><span>A secure temporary password will be auto-generated and sent to the customer's email.</span></div>
                        <p class="section-label mb-3">Personal Information</p>
                        <div class="row g-3 mb-3">
                            <div class="col-12 col-sm-6"><label class="form-label" for="customer_first_name">First Name <span class="text-danger">*</span></label>
                                <div class="input-group"><span class="input-group-text"><i class="fas fa-user"></i></span><input id="customer_first_name" name="first_name" class="form-control" required value="<?php echo htmlspecialchars($createCustomerForm['first_name'] ?? ''); ?>"></div>
                            </div>
                            <div class="col-12 col-sm-6"><label class="form-label" for="customer_last_name">Last Name <span class="text-danger">*</span></label>
                                <div class="input-group"><span class="input-group-text"><i class="fas fa-user"></i></span><input id="customer_last_name" name="last_name" class="form-control" required value="<?php echo htmlspecialchars($createCustomerForm['last_name'] ?? ''); ?>"></div>
                            </div>
                        </div>
                        <p class="section-label mb-3">Contact Details</p>
                        <div class="mb-3"><label class="form-label" for="customer_email">Email Address <span class="text-danger">*</span></label>
                            <div class="input-group"><span class="input-group-text"><i class="fas fa-envelope"></i></span><input type="email" id="customer_email" name="email" class="form-control" required value="<?php echo htmlspecialchars($createCustomerForm['email'] ?? ''); ?>"></div><small class="text-muted">This will be their login email. Must be unique.</small>
                        </div>
                        <div><label class="form-label" for="customer_phone">Phone Number <span class="fw-normal text-muted">(Optional)</span></label>
                            <div class="input-group"><span class="input-group-text"><i class="fas fa-phone"></i></span><input type="tel" id="customer_phone" name="phone" class="form-control" value="<?php echo htmlspecialchars($createCustomerForm['phone'] ?? ''); ?>"></div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-cancel" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning fw-bold btn-create-staff"><i class="fas fa-paper-plane me-2"></i>Create Customer &amp; Send Invite</button></div>
                </form>
            </div>
        </div>
    </div>

    <!-- CUSTOMER PROFILE MODAL -->
    <div class="modal fade" id="clientModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div class="d-flex align-items-center gap-3">
                        <div class="client-avatar" id="modal-initials" style="width:50px;height:50px;font-size:1.3rem;"></div>
                        <div>
                            <h5 class="modal-title mb-0" id="modal-name"></h5>
                            <small class="text-gold">Customer Profile & History</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-3 p-md-4">
                    <!-- Unverified notice inside modal -->
                    <div id="modal-unverified-notice" class="alert border-0 mb-3 d-none align-items-center gap-2" style="background:rgba(111,66,193,0.15);border-left:4px solid #6f42c1!important;border-radius:8px;">
                        <span style="color:#ccc;font-size:0.85rem;">This account is <strong style="color:#a78bfa;">unverified</strong>. The customer has not completed email OTP yet.</span>
                    </div>
                    <div class="row g-2 g-md-3">
                        <div class="col-12 col-sm-6">
                            <div class="stat-mini">
                                <div class="info-label">Email</div>
                                <div class="info-value text-info" id="modal-email" style="font-size:0.85rem;word-break:break-all;"></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-mini">
                                <div class="info-label">Phone</div>
                                <div class="info-value" id="modal-phone"></div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="stat-mini">
                                <div class="info-label">Address</div>
                                <div class="info-value" id="modal-address" style="word-break:break-word;"></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-mini">
                                <div class="info-label">Member Since</div>
                                <div class="info-value" id="modal-joined"></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-mini">
                                <div class="info-label">Account Status</div>
                                <div class="info-value" id="modal-status"></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-mini">
                                <div class="info-label">Total Orders</div>
                                <div class="info-value" id="modal-orders"></div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-mini">
                                <div class="info-label">Total Spent</div>
                                <div class="info-value text-success" id="modal-spent"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const addStaffModalElement = document.getElementById('addStaffModal');
        if (addStaffModalElement && <?php echo $openAddStaffModal ? 'true' : 'false'; ?>) {
            bootstrap.Modal.getOrCreateInstance(addStaffModalElement).show();
        }

        const createCustomerModalElement = document.getElementById('createCustomerModal');
        if (createCustomerModalElement && <?php echo $openCreateCustomerModal ? 'true' : 'false'; ?>) {
            bootstrap.Modal.getOrCreateInstance(createCustomerModalElement).show();
        }

        const createAdminModalElement = document.getElementById('createAdminModal');
        if (createAdminModalElement && <?php echo $openCreateAdminModal ? 'true' : 'false'; ?>) {
            bootstrap.Modal.getOrCreateInstance(createAdminModalElement).show();
        }

        const editStaffModalElement = document.getElementById('editStaffModal');
        const editStaffFields = {
            id: document.getElementById('edit_staff_id'),
            firstName: document.getElementById('edit_staff_first_name'),
            lastName: document.getElementById('edit_staff_last_name'),
            phone: document.getElementById('edit_staff_phone'),
            role: document.getElementById('edit_staff_role'),
            status: document.getElementById('edit_staff_status')
        };

        function populateEditStaffModal(trigger, includeFormValues = true) {
            editStaffFields.id.value = trigger.dataset.id;
            editStaffFields.status.textContent = trigger.dataset.status;
            editStaffFields.status.className = 'badge ' + (trigger.dataset.status === 'ACTIVE' ? 'bg-success' : trigger.dataset.status === 'DISABLED' ? 'bg-danger' : 'bg-warning text-dark');
            if (includeFormValues) {
                editStaffFields.firstName.value = trigger.dataset.firstName;
                editStaffFields.lastName.value = trigger.dataset.lastName;
                editStaffFields.phone.value = trigger.dataset.phone;
                editStaffFields.role.value = trigger.dataset.role;
            }
        }

        document.querySelectorAll('.edit-staff-trigger').forEach(trigger => {
            trigger.addEventListener('click', () => populateEditStaffModal(trigger));
        });

        if (editStaffModalElement && <?php echo $openEditStaffModal ? 'true' : 'false'; ?>) {
            const trigger = [...document.querySelectorAll('.edit-staff-trigger')].find(button => button.dataset.id === <?php echo json_encode((string)$editStaffId); ?>);
            if (trigger) {
                populateEditStaffModal(trigger, <?php echo $editStaffError ? 'false' : 'true'; ?>);
                bootstrap.Modal.getOrCreateInstance(editStaffModalElement).show();
            }
        }

        document.getElementById('regenerateStaffPassword')?.addEventListener('click', function() {
            const characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$';
            let password = '';
            for (let index = 0; index < 8; index += 1) {
                password += characters.charAt(Math.floor(Math.random() * characters.length));
            }
            document.getElementById('add_staff_password').value = password;
        });

        document.getElementById('toggleAdminPassword')?.addEventListener('click', function() {
            const passwordInput = document.getElementById('admin_password');
            const icon = this.querySelector('i');
            const showPassword = passwordInput.type === 'password';
            passwordInput.type = showPassword ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !showPassword);
            icon.classList.toggle('fa-eye-slash', showPassword);
        });

        // ══════════════════════════════════════════════════════
        // PAGINATION ENGINE
        // ══════════════════════════════════════════════════════
        // Account edit, email change, and password reset are opened in-place from
        // the user list. The server endpoints remain the source of authorization.
        const canManageAdminRoles = <?php echo $isSuperAdmin ? 'true' : 'false'; ?>;
        const editAdminForm = document.getElementById('editAdminForm');
        const editAdminRole = document.getElementById('edit_admin_role');
        const editAdminRoleReadonly = document.getElementById('edit_admin_role_readonly');
        const editAdminRoleHidden = document.getElementById('edit_admin_role_hidden');

        function clearModalFormError(form) {
            const error = form.querySelector('.modal-form-error');
            error.textContent = '';
            error.classList.add('d-none');
        }

        function showModalFormError(form, message) {
            const error = form.querySelector('.modal-form-error');
            error.textContent = message || 'We could not complete that action. Please try again.';
            error.classList.remove('d-none');
        }

        document.querySelectorAll('.edit-admin-trigger').forEach(trigger => {
            trigger.addEventListener('click', () => {
                editAdminForm.action = `editAdmin.php?id=${encodeURIComponent(trigger.dataset.id)}`;
                editAdminForm.reset();
                clearModalFormError(editAdminForm);
                document.getElementById('edit_admin_first_name').value = trigger.dataset.firstName;
                document.getElementById('edit_admin_last_name').value = trigger.dataset.lastName;
                document.getElementById('edit_admin_email').value = trigger.dataset.email;
                document.getElementById('edit_admin_phone').value = trigger.dataset.phone;
                editAdminRole.value = trigger.dataset.role;
                editAdminRoleReadonly.value = trigger.dataset.role;
                editAdminRole.disabled = !canManageAdminRoles;
                editAdminRole.classList.toggle('d-none', !canManageAdminRoles);
                editAdminRoleReadonly.classList.toggle('d-none', canManageAdminRoles);
                editAdminRoleHidden.disabled = canManageAdminRoles;
                editAdminRoleHidden.name = canManageAdminRoles ? '' : 'role';
                editAdminRoleHidden.value = trigger.dataset.role;
            });
        });

        function configureAccountActionModal(trigger, formId, endpoint, nameId, emailId) {
            const form = document.getElementById(formId);
            form.action = `${endpoint}?type=${encodeURIComponent(trigger.dataset.type)}&id=${encodeURIComponent(trigger.dataset.id)}`;
            form.reset();
            clearModalFormError(form);
            document.getElementById(nameId).textContent = trigger.dataset.name;
            document.getElementById(emailId).textContent = trigger.dataset.email;
        }

        document.querySelectorAll('.change-email-trigger').forEach(trigger => {
            trigger.addEventListener('click', () => configureAccountActionModal(trigger, 'changeEmailForm', 'changeEmail.php', 'change_email_name', 'change_email_current'));
        });

        document.querySelectorAll('.reset-password-trigger').forEach(trigger => {
            trigger.addEventListener('click', () => configureAccountActionModal(trigger, 'resetPasswordForm', 'resetPassword.php', 'reset_password_name', 'reset_password_current'));
        });

        document.querySelectorAll('.password-toggle').forEach(button => {
            button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.target);
                const visible = input.type === 'password';
                input.type = visible ? 'text' : 'password';
                button.querySelector('i').classList.toggle('fa-eye', !visible);
                button.querySelector('i').classList.toggle('fa-eye-slash', visible);
                button.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
            });
        });

        document.querySelectorAll('.ajax-modal-form').forEach(form => {
            form.addEventListener('submit', async event => {
                event.preventDefault();
                if (!form.checkValidity()) {
                    form.reportValidity();
                    return;
                }

                clearModalFormError(form);
                const submitButton = form.querySelector('button[type="submit"]');
                const originalLabel = submitButton.innerHTML;
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin'
                    });

                    if (response.redirected) {
                        window.location.assign(response.url);
                        return;
                    }

                    const responseHtml = await response.text();
                    const responseDocument = new DOMParser().parseFromString(responseHtml, 'text/html');
                    const failure = responseDocument.querySelector('.alert-danger');
                    if (failure) {
                        showModalFormError(form, failure.textContent.trim());
                        return;
                    }

                    if (responseDocument.querySelector('.alert-success')) {
                        window.location.assign('viewUsers.php?success=' + encodeURIComponent('Admin account updated successfully.'));
                        return;
                    }

                    showModalFormError(form, 'The action could not be completed. Please try again.');
                } catch (error) {
                    showModalFormError(form, 'A network error occurred. Please check your connection and try again.');
                } finally {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalLabel;
                }
            });
        });

        const PAGE_SIZE = 10;

        function initPagination(tableId, cardsId, barId) {
            const table = document.getElementById(tableId);
            const cardsWrap = document.getElementById(cardsId);
            const bar = document.getElementById(barId);
            if (!bar) return null;

            let currentPage = 1;

            function getAllRows() {
                return table ? [...table.querySelectorAll('tbody tr')] : [];
            }

            function getAllCards() {
                return cardsWrap ? [...cardsWrap.querySelectorAll('.user-card')] : [];
            }

            function getVisibleRows() {
                return getAllRows().filter(r => r.dataset.filtered !== 'true');
            }

            function getVisibleCards() {
                return getAllCards().filter(c => c.dataset.filtered !== 'true');
            }

            function render() {
                const rows = getVisibleRows();
                const cards = getVisibleCards();
                const total = Math.max(rows.length, cards.length);
                const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

                if (currentPage > totalPages) currentPage = totalPages;
                if (currentPage < 1) currentPage = 1;

                const start = (currentPage - 1) * PAGE_SIZE;
                const end = start + PAGE_SIZE;

                getAllRows().forEach(r => {
                    if (r.dataset.filtered === 'true') {
                        r.style.display = 'none';
                    } else {
                        const idx = rows.indexOf(r);
                        r.style.display = (idx >= start && idx < end) ? '' : 'none';
                    }
                });

                getAllCards().forEach(c => {
                    if (c.dataset.filtered === 'true') {
                        c.style.display = 'none';
                    } else {
                        const idx = cards.indexOf(c);
                        c.style.display = (idx >= start && idx < end) ? '' : 'none';
                    }
                });

                const displayStart = total === 0 ? 0 : start + 1;
                const displayEnd = Math.min(end, total);

                bar.innerHTML = `
                    <span class="pagination-info">
                        Showing <strong>${displayStart}</strong>–<strong>${displayEnd}</strong> of <strong>${total}</strong>
                    </span>
                    <div class="pagination-controls" id="${barId}-controls"></div>
                `;

                if (totalPages <= 1) return;

                const controls = document.getElementById(`${barId}-controls`);

                const prev = document.createElement('button');
                prev.className = 'page-btn';
                prev.innerHTML = '&#8249;';
                prev.title = 'Previous page';
                prev.disabled = currentPage === 1;
                prev.addEventListener('click', () => {
                    currentPage--;
                    render();
                });
                controls.appendChild(prev);

                const delta = 2;
                const pages = [];
                for (let i = 1; i <= totalPages; i++) {
                    if (i === 1 || i === totalPages || (i >= currentPage - delta && i <= currentPage + delta)) {
                        pages.push(i);
                    }
                }

                let lastPage = 0;
                pages.forEach(p => {
                    if (lastPage && p - lastPage > 1) {
                        const dots = document.createElement('button');
                        dots.className = 'page-btn dots';
                        dots.textContent = '…';
                        dots.disabled = true;
                        controls.appendChild(dots);
                    }
                    const btn = document.createElement('button');
                    btn.className = 'page-btn' + (p === currentPage ? ' active' : '');
                    btn.textContent = p;
                    btn.addEventListener('click', () => {
                        currentPage = p;
                        render();
                    });
                    controls.appendChild(btn);
                    lastPage = p;
                });

                const next = document.createElement('button');
                next.className = 'page-btn';
                next.innerHTML = '&#8250;';
                next.title = 'Next page';
                next.disabled = currentPage === totalPages;
                next.addEventListener('click', () => {
                    currentPage++;
                    render();
                });
                controls.appendChild(next);
            }

            function reset() {
                currentPage = 1;
                render();
            }

            render();
            return {
                reset,
                render
            };
        }

        const paginators = {};
        window.addEventListener('DOMContentLoaded', () => {
            paginators.admins = initPagination('table-admins', 'cards-admins', 'pagination-admins');
            paginators.staff = initPagination('table-staff', 'cards-staff', 'pagination-staff');
            paginators.customers = initPagination('table-customers', 'cards-customers', 'pagination-customers');
        });

        // ══════════════════════════════════════════════════════
        // TAB STATS SWAP
        // ══════════════════════════════════════════════════════
        document.querySelectorAll('button[data-bs-toggle="tab"]').forEach(button => {
            button.addEventListener('shown.bs.tab', function(e) {
                const newHash = e.target.getAttribute('data-bs-target');
                history.replaceState(null, null, newHash);
                document.querySelectorAll('.stats-section').forEach(el => el.style.display = 'none');
                const statsEl = document.getElementById('stats-' + newHash.substring(1));
                if (statsEl) statsEl.style.display = 'block';
            });
        });

        if (location.hash) {
            const tabId = location.hash.substring(1);
            const tabBtn = document.querySelector(`button[data-bs-target="${location.hash}"]`);
            if (tabBtn) {
                new bootstrap.Tab(tabBtn).show();
                document.querySelectorAll('.stats-section').forEach(el => el.style.display = 'none');
                const statsEl = document.getElementById('stats-' + tabId);
                if (statsEl) statsEl.style.display = 'block';
            }
        }

        // ══════════════════════════════════════════════════════
        // LIVE SEARCH
        // ══════════════════════════════════════════════════════
        function attachSearch(inputId, tableId, cardsId, paginatorKey) {
            const input = document.getElementById(inputId);
            if (!input) return;

            input.addEventListener('input', function() {
                const q = this.value.toLowerCase().trim();

                const table = document.getElementById(tableId);
                if (table) {
                    table.querySelectorAll('tbody tr').forEach(row => {
                        const matches = row.textContent.toLowerCase().includes(q);
                        row.dataset.filtered = matches ? 'false' : 'true';
                    });
                }

                const cards = document.getElementById(cardsId);
                if (cards) {
                    cards.querySelectorAll('.user-card').forEach(card => {
                        const searchVal = card.dataset.search || card.textContent.toLowerCase();
                        const matches = searchVal.includes(q);
                        card.dataset.filtered = matches ? 'false' : 'true';
                    });
                }

                if (paginators[paginatorKey]) paginators[paginatorKey].reset();
            });
        }

        attachSearch('search-admins', 'table-admins', 'cards-admins', 'admins');
        attachSearch('search-staff', 'table-staff', 'cards-staff', 'staff');
        attachSearch('search-customers', 'table-customers', 'cards-customers', 'customers');

        // ══════════════════════════════════════════════════════
        // CUSTOMER MODAL
        // ══════════════════════════════════════════════════════
        document.getElementById('clientModal').addEventListener('show.bs.modal', function(e) {
            const btn = e.relatedTarget;
            const status = (btn.dataset.status || '').toUpperCase();

            document.getElementById('modal-name').textContent = btn.dataset.name;
            document.getElementById('modal-initials').innerHTML = `<img src="${btn.dataset.avatar}" alt="Avatar" class="rounded-circle" style="width:100%;height:100%;object-fit:cover;">`;
            document.getElementById('modal-initials').style.background = 'none';
            document.getElementById('modal-email').textContent = btn.dataset.email;
            document.getElementById('modal-phone').textContent = btn.dataset.phone;
            document.getElementById('modal-address').textContent = btn.dataset.address;
            document.getElementById('modal-joined').textContent = btn.dataset.joined;
            document.getElementById('modal-orders').textContent = btn.dataset.orders;
            document.getElementById('modal-spent').textContent = btn.dataset.spent;

            // Status badge in modal
            const statusColors = {
                'ACTIVE': '#28a745',
                'DISABLED': '#dc3545',
                'PENDING': '#ffc107',
                'REJECTED': '#6c757d',
                'UNVERIFIED': '#6f42c1'
            };
            const color = statusColors[status] || '#6c757d';
            document.getElementById('modal-status').innerHTML =
                `<span class="badge" style="background:${color};">${btn.dataset.status}</span>`;

            // Show/hide unverified notice
            const notice = document.getElementById('modal-unverified-notice');
            if (status === 'UNVERIFIED' || status === 'PENDING') {
                notice.classList.remove('d-none');
                notice.classList.add('d-flex');
            } else {
                notice.classList.remove('d-flex');
                notice.classList.add('d-none');
            }
        });

        // ══════════════════════════════════════════════════════
        // HELPER: POST FORM
        // ══════════════════════════════════════════════════════
        function submitPostWithPassword(url, params) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = url;
            for (const key in params) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = params[key];
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
        }

        // ══════════════════════════════════════════════════════
        // DELETE CONFIRMATION
        // ══════════════════════════════════════════════════════
        document.querySelectorAll('.btn-delete').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.dataset.id;
                const name = this.dataset.name;
                const type = this.dataset.type;
                Swal.fire({
                    title: 'Delete User?',
                    html: `Are you sure you want to permanently delete "<b>${name}</b>"? This cannot be undone.<br><br>
                           <label style="color:#d4af37;" class="mb-2"><i class="fas fa-shield-alt me-1"></i> Admin Password Required</label>`,
                    input: 'password',
                    inputPlaceholder: 'Enter your admin password',
                    inputAttributes: {
                        autocapitalize: 'off',
                        autocorrect: 'off',
                        autocomplete: 'new-password',
                        spellcheck: 'false'
                    },
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, delete it!',
                    background: '#1e1e1e',
                    color: '#fff',
                    didOpen: () => {
                        const swalInput = Swal.getInput();
                        if (swalInput) {
                            swalInput.setAttribute('name', 'admin_password_prompt');
                        }
                    },
                    preConfirm: (password) => {
                        const trimmedPassword = (password ?? '').trim();
                        if (!trimmedPassword) {
                            Swal.showValidationMessage('Password required to authorize.');
                            return false;
                        }
                        return trimmedPassword;
                    }
                }).then((result) => {
                    if (result.isConfirmed && result.value) {
                        submitPostWithPassword('deleteUser.php', {
                            id,
                            type,
                            admin_password: result.value
                        });
                    }
                });
            });
        });

        // ══════════════════════════════════════════════════════
        // LOGOUT CONFIRMATION
        // ══════════════════════════════════════════════════════
        document.getElementById('logout-btn').addEventListener('click', function() {
            Swal.fire({
                title: 'Logging out?',
                text: <?php echo json_encode('You will be signed out of your ' . $currentAdminRole . ' session.'); ?>,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-sign-out-alt me-1"></i> Yes, log me out',
                cancelButtonText: 'Stay',
                background: '#1e1e1e',
                color: '#fff',
                reverseButtons: true,
                focusCancel: true,
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '../../auth/logout.php';
                }
            });
        });

        // ══════════════════════════════════════════════════════
        // STAFF STATUS TOGGLE
        // ══════════════════════════════════════════════════════
        document.querySelectorAll('.btn-status-toggle').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.dataset.id;
                const name = this.dataset.name;
                const action = this.dataset.action;
                const actionText = action === 'enable' ? 'Enable' : 'Suspend/Disable';
                const confirmColor = action === 'enable' ? '#28a745' : '#dc3545';
                Swal.fire({
                    title: `${actionText} Staff Account?`,
                    html: `Are you sure you want to ${action.toLowerCase()} the account for "<b>${name}</b>"?<br><br>
                           <label style="color:#d4af37;" class="mb-2"><i class="fas fa-shield-alt me-1"></i> Admin Password Required</label>`,
                    input: 'password',
                    inputPlaceholder: 'Enter your admin password',
                    inputAttributes: {
                        autocapitalize: 'off',
                        autocorrect: 'off',
                        autocomplete: 'new-password',
                        spellcheck: 'false'
                    },
                    icon: 'info',
                    showCancelButton: true,
                    confirmButtonColor: confirmColor,
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: `Yes, ${action.toLowerCase()} it!`,
                    background: '#1e1e1e',
                    color: '#fff',
                    didOpen: () => {
                        const swalInput = Swal.getInput();
                        if (swalInput) {
                            swalInput.setAttribute('name', 'admin_password_prompt');
                        }
                    },
                    preConfirm: (password) => {
                        if (!password) Swal.showValidationMessage('Password required to authorize.');
                        return password;
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        submitPostWithPassword('../staffManagementCrud/toggleStaffStatus.php', {
                            id,
                            action,
                            admin_password: result.value
                        });
                    }
                });
            });
        });



        // ══════════════════════════════════════════════════════
        // CUSTOMER QUICK VERIFY (no password required)
        // ══════════════════════════════════════════════════════
        document.querySelectorAll('.btn-verify-customer').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.dataset.id;
                const name = this.dataset.name;
                const btn = this;
                Swal.fire({
                    title: 'Verify This Account?',
                    html: `<div style="text-align:left;padding:8px 0;">
                               <p style="margin-bottom:10px;">This will immediately verify <b style="color:#a78bfa;">${name}</b> and activate their account.</p>
                               <div style="background:rgba(111,66,193,0.12);border-radius:8px;padding:10px 12px;border:1px solid rgba(111,66,193,0.25);">
                                   <i class="fas fa-info-circle" style="color:#a78bfa;margin-right:6px;"></i>
                                   <span style="font-size:0.88rem;color:#ccc;">Email OTP verification will be <b>bypassed</b>.</span>
                               </div>
                           </div>`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#6f42c1',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: '<i class="fas fa-user-check me-1"></i> Yes, verify account',
                    cancelButtonText: 'Cancel',
                    background: '#1e1e1e',
                    color: '#fff',
                    reverseButtons: true,
                    focusCancel: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Show loading state
                        Swal.fire({
                            title: 'Verifying...',
                            html: 'Please wait while the account is being verified.',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            showConfirmButton: false,
                            background: '#1e1e1e',
                            color: '#fff',
                            didOpen: () => {
                                Swal.showLoading();
                            }
                        });

                        const formData = new FormData();
                        formData.append('member_id', id);

                        fetch('quickVerifyCustomer.php', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.json())
                            .then(data => {
                                Swal.close();
                                if (data.success) {
                                    // Success toast notification
                                    const Toast = Swal.mixin({
                                        toast: true,
                                        position: 'top-end',
                                        showConfirmButton: false,
                                        timer: 3000,
                                        timerProgressBar: true,
                                        background: '#1e1e1e',
                                        color: '#fff',
                                        didOpen: (toast) => {
                                            toast.addEventListener('mouseenter', Swal.stopTimer);
                                            toast.addEventListener('mouseleave', Swal.resumeTimer);
                                        }
                                    });
                                    Toast.fire({
                                        icon: 'success',
                                        title: data.message || (name + ' has been verified successfully!')
                                    });

                                    // Update the UI without full page reload
                                    // Find the table row and card for this customer
                                    const row = btn.closest('tr');
                                    if (row) {
                                        row.classList.remove('row-unverified');
                                        const statusBadge = row.querySelector('td:nth-child(2) .badge');
                                        if (statusBadge) {
                                            statusBadge.className = 'badge bg-success';
                                            statusBadge.innerHTML = 'ACTIVE';
                                        }
                                        // Update the eye/view button data-status so modal shows correctly
                                        const eyeBtn = row.querySelector('.btn-eye');
                                        if (eyeBtn) eyeBtn.dataset.status = 'ACTIVE';
                                        // Remove the verify button
                                        btn.remove();
                                    }

                                    // Also update the mobile card
                                    const card = document.querySelector(`.user-card .btn-verify-customer[data-id="${id}"]`);
                                    if (card) {
                                        const userCard = card.closest('.user-card');
                                        if (userCard) {
                                            userCard.classList.remove('card-unverified');
                                            const cardBadge = userCard.querySelector('.user-card-header .badge');
                                            if (cardBadge) {
                                                cardBadge.className = 'badge bg-success ms-auto flex-shrink-0';
                                                cardBadge.innerHTML = 'ACTIVE';
                                            }
                                            // Update the eye/view button data-status on mobile card too
                                            const cardEyeBtn = userCard.querySelector('.btn-eye');
                                            if (cardEyeBtn) cardEyeBtn.dataset.status = 'ACTIVE';
                                        }
                                        card.remove();
                                    }

                                    // Reload after a short delay to refresh stats
                                    setTimeout(() => {
                                        window.location.href = 'viewUsers.php#customers';
                                        window.location.reload();
                                    }, 2000);
                                } else {
                                    Swal.fire({
                                        icon: 'error',
                                        title: 'Verification Failed',
                                        text: data.message || 'An unexpected error occurred.',
                                        background: '#1e1e1e',
                                        color: '#fff',
                                        confirmButtonColor: '#6f42c1'
                                    });
                                }
                            })
                            .catch(error => {
                                Swal.close();
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Network Error',
                                    text: 'Could not reach the server. Please try again.',
                                    background: '#1e1e1e',
                                    color: '#fff',
                                    confirmButtonColor: '#6f42c1'
                                });
                            });
                    }
                });
            });
        });

        // ══════════════════════════════════════════════════════
        // LOGOUT LINK (secondary handler for .logout-link anchors)
        // ══════════════════════════════════════════════════════
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
