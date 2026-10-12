<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

$search        = isset($_GET['search'])        ? trim($_GET['search'])        : '';
$action_filter = isset($_GET['action_filter']) ? $_GET['action_filter']       : 'all';
$date_filter   = isset($_GET['date_filter'])   ? trim($_GET['date_filter'])   : '';
$quick_date    = isset($_GET['quick_date'])    ? $_GET['quick_date']          : '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 25;

$validatedDate = '';
if ($quick_date !== '' && $date_filter === '') {
    switch ($quick_date) {
        case 'today':
            $validatedDate = date('Y-m-d');
            break;
        case 'yesterday':
            $validatedDate = date('Y-m-d', strtotime('-1 day'));
            break;
        case 'last7':
            $validatedDate = '';
            break;
    }
} elseif ($date_filter !== '') {
    $dateObject = DateTime::createFromFormat('Y-m-d', $date_filter);
    if ($dateObject && $dateObject->format('Y-m-d') === $date_filter) {
        $validatedDate = $date_filter;
    }
}

$baseSelect = "SELECT al.*, 
                    COALESCE(s.first_name, '') as staff_fname, 
                    COALESCE(s.last_name, '') as staff_lname,
                    COALESCE(a.first_name, '') as admin_fname,
                    COALESCE(a.last_name, '') as admin_lname,
                    COALESCE(auth_a.first_name, '') as auth_admin_fname,
                    COALESCE(auth_a.last_name, '') as auth_admin_lname,
                    COALESCE(auth_s.first_name, '') as auth_staff_fname,
                    COALESCE(auth_s.last_name, '') as auth_staff_lname,
                    COALESCE(auth_m.first_name, '') as auth_member_fname,
                    COALESCE(auth_m.last_name, '') as auth_member_lname";

$baseFrom = " FROM audit_logs al
            LEFT JOIN staffs s ON al.staff_id = s.staff_id
            LEFT JOIN admins a ON al.admin_id = a.admin_id
            LEFT JOIN admins auth_a ON (al.admin_id IS NULL AND al.staff_id IS NULL AND al.table_affected = 'admins' AND al.record_id = auth_a.admin_id AND al.action IN ('LOGIN', 'LOGOUT'))
            LEFT JOIN staffs auth_s ON (al.admin_id IS NULL AND al.staff_id IS NULL AND al.table_affected = 'staffs' AND al.record_id = auth_s.staff_id AND al.action IN ('LOGIN', 'LOGOUT'))
            LEFT JOIN customer auth_m ON (al.admin_id IS NULL AND al.staff_id IS NULL AND al.table_affected = 'customer' AND al.record_id = auth_m.member_id AND al.action IN ('LOGIN', 'LOGOUT', 'REGISTER'))";

$conditions = [];
$params = [];

if ($action_filter !== 'all') {
    $conditions[] = "al.action = :action_filter";
    $params[':action_filter'] = $action_filter;
}

if ($quick_date === 'last7' && $date_filter === '') {
    $conditions[] = "al.timestamp >= :date_start";
    $params[':date_start'] = date('Y-m-d H:i:s', strtotime('-7 days'));
} elseif ($validatedDate !== '') {
    $conditions[] = "al.timestamp >= :date_start AND al.timestamp < :date_end";
    $params[':date_start'] = $validatedDate . ' 00:00:00';
    $params[':date_end']   = date('Y-m-d H:i:s', strtotime($validatedDate . ' +1 day'));
}

if ($search !== '') {
    $conditions[] = "(
            al.action LIKE :search
            OR al.table_affected LIKE :search
            OR COALESCE(al.ip_address, '') LIKE :search
            OR COALESCE(al.action_details, '') LIKE :search
            OR COALESCE(CAST(al.record_id AS CHAR), '') LIKE :search
            OR CONCAT_WS(' ',
                COALESCE(s.first_name, ''), COALESCE(s.last_name, ''),
                COALESCE(a.first_name, ''), COALESCE(a.last_name, ''),
                COALESCE(auth_a.first_name, ''), COALESCE(auth_a.last_name, ''),
                COALESCE(auth_s.first_name, ''), COALESCE(auth_s.last_name, ''),
                COALESCE(auth_m.first_name, ''), COALESCE(auth_m.last_name, '')
            ) LIKE :search
        )";
    $params[':search'] = '%' . $search . '%';
}

$whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

$countStmt = $db->prepare("SELECT COUNT(*)" . $baseFrom . $whereSql);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalLogs = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalLogs / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$query = $baseSelect . $baseFrom . $whereSql . " ORDER BY al.timestamp DESC LIMIT :limit OFFSET :offset";
$stmt  = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$actionsStmt = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action ASC");
$allActions  = $actionsStmt->fetchAll(PDO::FETCH_COLUMN);

function getActionColor($action)
{
    $action = strtoupper((string)$action);
    if (strpos($action, 'DELETE') !== false) return 'danger';
    if (strpos($action, 'ADD') !== false || strpos($action, 'APPROVE') !== false || strpos($action, 'CREATE') !== false || strpos($action, 'REGISTER') !== false) return 'success';
    if (strpos($action, 'EDIT') !== false || strpos($action, 'UPDATE') !== false || strpos($action, 'CHANGE') !== false || strpos($action, 'RESET') !== false) return 'primary';
    if (strpos($action, 'LOGIN') !== false || strpos($action, 'LOGOUT') !== false) return 'info';
    return 'secondary';
}

/**
 * Returns a single clean human-readable sentence for an audit log entry.
 * Examples:
 *   DELETE_PROMOTION  → "Deleted promotion: Holiday Season"
 *   LOGIN             → "Admin logged in: johncine@email.com"
 *   CREATE_MENU_ITEM  → "Created menu item: Grilled Salmon"
 */
function getSimpleDetail($action, $rawDetails)
{
    $d = [];
    if ($rawDetails !== null && trim((string)$rawDetails) !== '') {
        $decoded = json_decode($rawDetails, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $d = $decoded;
        }
    }

    $action = strtoupper((string)$action);

    // Helper: grab first non-empty value from a list of possible keys
    $get = function (array $keys) use ($d) {
        foreach ($keys as $k) {
            if (!empty($d[$k])) return (string)$d[$k];
        }
        return null;
    };

    // ── Auth ────────────────────────────────────────────────
    if ($action === 'LOGIN') {
        $role  = ucfirst(strtolower($get(['role']) ?? 'User'));
        $email = $get(['email', 'username']);
        $name  = $get(['name', 'first_name']);
        $who   = $name ?? $email ?? 'Unknown';
        return "{$role} logged in: {$who}";
    }
    if ($action === 'LOGOUT') {
        $role = ucfirst(strtolower($get(['role']) ?? 'User'));
        $name = $get(['name', 'email', 'username']);
        return $name ? "{$role} logged out: {$name}" : "{$role} logged out";
    }
    if ($action === 'REGISTER') {
        $name  = $get(['name', 'first_name', 'email']);
        return $name ? "New account registered: {$name}" : "New account registered";
    }

    // ── Promotions ──────────────────────────────────────────
    if ($action === 'DELETE_PROMOTION') {
        $name = $get(['promotion_name', 'name', 'summary']);
        return $name ? "Deleted promotion: {$name}" : "Deleted a promotion";
    }
    if (in_array($action, ['CREATE_PROMOTION', 'ADD_PROMOTION'])) {
        $name = $get(['promotion_name', 'name']);
        return $name ? "Created promotion: {$name}" : "Created a new promotion";
    }
    if (in_array($action, ['EDIT_PROMOTION', 'UPDATE_PROMOTION'])) {
        $name = $get(['promotion_name', 'name']);
        return $name ? "Updated promotion: {$name}" : "Updated a promotion";
    }

    // ── Menu Items ──────────────────────────────────────────
    if (in_array($action, ['CREATE_MENU_ITEM', 'ADD_MENU_ITEM'])) {
        $name = $get(['item_name', 'name']);
        return $name ? "Added menu item: {$name}" : "Added a new menu item";
    }
    if (in_array($action, ['EDIT_MENU_ITEM', 'UPDATE_MENU_ITEM'])) {
        $name = $get(['item_name', 'name']);
        return $name ? "Updated menu item: {$name}" : "Updated a menu item";
    }
    if ($action === 'DELETE_MENU_ITEM') {
        $name = $get(['item_name', 'name']);
        return $name ? "Deleted menu item: {$name}" : "Deleted a menu item";
    }
    if (in_array($action, ['TOGGLE_MENU_ITEM', 'TOGGLE_AVAILABILITY'])) {
        $name   = $get(['item_name', 'name']);
        $status = $get(['status', 'is_available']);
        $state  = ($status === '1' || $status === 'available') ? 'Available' : 'Unavailable';
        return $name ? "Set \"{$name}\" to {$state}" : "Toggled menu item availability";
    }
    if ($action === 'VIEW_MENU_ADMIN') {
        return $get(['summary']) ?? "Viewed menu management";
    }

    // ── Orders ──────────────────────────────────────────────
    if (in_array($action, ['CREATE_ORDER', 'PLACE_ORDER'])) {
        $id = $get(['order_id', 'id']);
        return $id ? "New order placed: #{$id}" : "New order placed";
    }
    if (in_array($action, ['UPDATE_ORDER', 'EDIT_ORDER'])) {
        $id     = $get(['order_id', 'id']);
        $status = $get(['status', 'order_status']);
        if ($id && $status) return "Order #{$id} updated to: {$status}";
        if ($id)            return "Updated order #{$id}";
        return "Updated an order";
    }
    if ($action === 'DELETE_ORDER') {
        $id = $get(['order_id', 'id']);
        return $id ? "Deleted order: #{$id}" : "Deleted an order";
    }
    if ($action === 'APPROVE_ORDER') {
        $id = $get(['order_id', 'id']);
        return $id ? "Approved order: #{$id}" : "Approved an order";
    }

    // ── Reservations ────────────────────────────────────────
    if (in_array($action, ['CREATE_RESERVATION', 'ADD_RESERVATION'])) {
        $name = $get(['guest_name', 'name', 'customer_name']);
        return $name ? "Reservation created for: {$name}" : "New reservation created";
    }
    if (in_array($action, ['UPDATE_RESERVATION', 'EDIT_RESERVATION'])) {
        $id = $get(['reservation_id', 'id']);
        return $id ? "Updated reservation: #{$id}" : "Updated a reservation";
    }
    if ($action === 'DELETE_RESERVATION') {
        $id = $get(['reservation_id', 'id']);
        return $id ? "Deleted reservation: #{$id}" : "Deleted a reservation";
    }

    // ── Staff / Users ────────────────────────────────────────
    if (in_array($action, ['CREATE_STAFF', 'ADD_STAFF'])) {
        $name = $get(['name', 'first_name', 'email']);
        return $name ? "Added staff: {$name}" : "Added a new staff member";
    }
    if (in_array($action, ['EDIT_STAFF', 'UPDATE_STAFF'])) {
        $name = $get(['name', 'first_name', 'email']);
        return $name ? "Updated staff: {$name}" : "Updated a staff member";
    }
    if ($action === 'DELETE_STAFF') {
        $name = $get(['name', 'first_name', 'email']);
        return $name ? "Removed staff: {$name}" : "Removed a staff member";
    }
    if ($action === 'RESET_PASSWORD') {
        $who = $get(['email', 'name', 'username']);
        return $who ? "Password reset for: {$who}" : "Password was reset";
    }

    // ── Inventory / Stock ────────────────────────────────────
    if (in_array($action, ['UPDATE_STOCK', 'EDIT_STOCK', 'ADJUST_STOCK'])) {
        $item = $get(['item_name', 'name']);
        $qty  = $get(['quantity', 'stock_quantity', 'new_quantity']);
        if ($item && $qty !== null) return "Updated stock for \"{$item}\": {$qty} units";
        if ($item)                 return "Updated stock for \"{$item}\"";
        return "Stock quantity updated";
    }

    // ── Generic fallback ─────────────────────────────────────
    // Use the "summary" key if it exists in the JSON
    $summary = $get(['summary', 'message', 'description', 'note']);
    if ($summary) return $summary;

    // Last resort: humanize the action name itself
    $human = ucwords(strtolower(str_replace('_', ' ', $action)));
    return $human;
}

function renderActorLabel($log)
{
    $details = json_decode($log['action_details'] ?? '', true);
    if (!empty($log['admin_id'])) {
        $name = trim(($log['admin_fname'] ?? '') . ' ' . ($log['admin_lname'] ?? ''));
        if ($name === '') $name = 'Admin #' . $log['admin_id'];
        return '<span class="actor-badge actor-admin"><i class="fas fa-user-shield"></i>' . htmlspecialchars($name) . '</span>';
    }
    if (!empty($log['staff_id'])) {
        $roleFromDetails = strtolower((string)($details['role'] ?? ''));
        $isAdminFallback = $roleFromDetails === 'admin';
        $name = trim(($log['staff_fname'] ?? '') . ' ' . ($log['staff_lname'] ?? ''));
        if ($name === '') $name = ($isAdminFallback ? 'Admin' : 'Staff') . ' #' . $log['staff_id'];
        if ($isAdminFallback) return '<span class="actor-badge actor-admin"><i class="fas fa-user-shield"></i>' . htmlspecialchars($name) . '</span>';
        return '<span class="actor-badge actor-staff"><i class="fas fa-user-tie"></i>' . htmlspecialchars($name) . '</span>';
    }
    if (!empty($log['auth_admin_fname'])) return '<span class="actor-badge actor-admin"><i class="fas fa-user-shield"></i>' . htmlspecialchars(trim($log['auth_admin_fname'] . ' ' . $log['auth_admin_lname'])) . '</span>';
    if (!empty($log['auth_staff_fname'])) return '<span class="actor-badge actor-staff"><i class="fas fa-user-tie"></i>' . htmlspecialchars(trim($log['auth_staff_fname'] . ' ' . $log['auth_staff_lname'])) . '</span>';
    if (!empty($log['auth_member_fname'])) return '<span class="actor-badge actor-customer"><i class="fas fa-user"></i>' . htmlspecialchars(trim($log['auth_member_fname'] . ' ' . $log['auth_member_lname'])) . '</span>';
    if (is_array($details) && (!empty($details['role']) || !empty($details['email']))) {
        $role  = ucfirst(strtolower((string)($details['role'] ?? 'User')));
        $email = (string)($details['email'] ?? ('Record #' . ($log['record_id'] ?? 'N/A')));
        $cls   = $role === 'Admin' ? 'actor-admin' : ($role === 'Staff' ? 'actor-staff' : 'actor-customer');
        $icon  = $role === 'Admin' ? 'fa-user-shield' : ($role === 'Staff' ? 'fa-user-tie' : 'fa-user');
        return '<span class="actor-badge ' . $cls . '"><i class="fas ' . $icon . '"></i>' . htmlspecialchars($role . ': ' . $email) . '</span>';
    }
    return '<span class="actor-badge actor-system"><i class="fas fa-cog"></i>System</span>';
}

function maskIpAddress($ipAddress)
{
    $ipAddress = trim((string) $ipAddress);
    if ($ipAddress === '') {
        return '--';
    }

    if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ipAddress);
        $parts[3] = 'xxx';
        return implode('.', $parts);
    }

    if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $segments = explode(':', $ipAddress);
        $visible = array_slice($segments, 0, min(4, count($segments)));
        return implode(':', $visible) . ':xxxx';
    }

    return 'Hidden';
}

$resultsStart = $totalLogs > 0 ? $offset + 1 : 0;
$resultsEnd   = $totalLogs > 0 ? min($offset + $perPage, $totalLogs) : 0;
$canViewFullIp = isSuperAdmin();
$paginationBaseParams = [
    'search'        => $search,
    'action_filter' => $action_filter,
    'date_filter'   => $validatedDate,
    'quick_date'    => ($date_filter === '' ? $quick_date : ''),
];

$hasActiveFilters = $search !== '' || $action_filter !== 'all' || $date_filter !== '' || $quick_date !== '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs | La Elena Fishyalan Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --gold: #d4af37;
            --gold-dim: rgba(212, 175, 55, .15);
            --gold-border: rgba(212, 175, 55, .3);
            --dark: #0d0d0d;
            --card-bg: #181818;
            --card-bg2: #1f1f1f;
            --border: #2a2a2a;
            --text: #e8e8e8;
            --text-muted: #888;
            --radius: 12px;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: var(--dark);
            color: var(--text);
            font-family: 'Segoe UI', system-ui, sans-serif;
            font-size: 14px;
            line-height: 1.6;
            overflow-x: hidden;
        }

        /* ── NAVBAR ─────────────────────────────────────────── */
        .navbar {
            background: #111 !important;
            border-bottom: 1px solid var(--border);
            padding: 0 14px;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 200;
            gap: 8px;
        }

        .navbar-left {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            flex: 1;
        }

        .navbar-brand {
            font-size: clamp(.72rem, 2.5vw, 1rem);
            font-weight: 700;
            color: var(--gold) !important;
            text-decoration: none;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        .breadcrumb-sep {
            color: #444;
            flex-shrink: 0;
        }

        .breadcrumb-current {
            color: var(--text-muted);
            font-size: clamp(.65rem, 2vw, .82rem);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .navbar-right {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
        }

        .btn-nav {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: transparent;
            border-radius: 7px;
            padding: 5px 10px;
            font-size: .78rem;
            text-decoration: none;
            transition: all .2s;
            white-space: nowrap;
            cursor: pointer;
        }

        .btn-nav-back {
            border: 1px solid #e56b4f;
            color: #e56b4f;
            background: rgba(229, 107, 79, .06);
        }

        .btn-nav-back:hover {
            background: #e56b4f;
            border-color: #e56b4f;
            color: #ffffff;
        }

        .btn-logout {
            border: 1px solid rgba(220, 53, 69, .4);
            color: #dc3545;
        }

        .btn-logout:hover {
            background: rgba(220, 53, 69, .1);
            border-color: #dc3545;
            color: #dc3545;
        }

        @media (max-width: 380px) {

            .btn-label,
            .breadcrumb-sep,
            .breadcrumb-current {
                display: none;
            }
        }

        /* ── PAGE WRAP ──────────────────────────────────────── */
        .page-wrap {
            max-width: 1400px;
            margin: 0 auto;
            padding: clamp(14px, 3vw, 28px) clamp(12px, 3vw, 20px);
        }

        /* ── PAGE HEADER ────────────────────────────────────── */
        .page-header {
            margin-bottom: 20px;
        }

        .page-header h1 {
            font-size: clamp(1.1rem, 3.5vw, 1.5rem);
            font-weight: 700;
            color: #fff;
            margin-bottom: 4px;
        }

        .page-header p {
            color: var(--text-muted);
            font-size: .82rem;
        }

        /* ── FILTER CARD ────────────────────────────────────── */
        .filter-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: clamp(14px, 3vw, 20px);
            margin-bottom: 18px;
        }

        .section-label {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--gold);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .filter-toggle-btn {
            display: none;
            width: 100%;
            background: #e56b4f;
            border: 1px solid #e56b4f;
            color: #ffffff;
            border-radius: 8px;
            padding: 9px 14px;
            font-size: .83rem;
            cursor: pointer;
            text-align: left;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            transition: background .2s ease, border-color .2s ease, transform .2s ease;
        }

        .filter-toggle-btn:hover {
            background: #c9533b;
            border-color: #c9533b;
            color: #ffffff;
            transform: translateY(-1px);
        }

        .filter-toggle-btn .toggle-arrow {
            transition: transform .25s;
        }

        .filter-toggle-btn.open .toggle-arrow {
            transform: rotate(180deg);
        }

        @media (max-width: 767px) {
            .filter-toggle-btn {
                display: flex;
            }

            .filter-body {
                display: none;
            }

            .filter-body.open {
                display: block;
            }
        }

        .quick-presets {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }

        .quick-presets-label {
            font-size: .72rem;
            color: var(--text-muted);
            align-self: center;
            white-space: nowrap;
        }

        .preset-btn {
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text-muted);
            border-radius: 20px;
            padding: 3px 12px;
            font-size: .76rem;
            cursor: pointer;
            transition: all .2s;
            text-decoration: none;
            white-space: nowrap;
        }

        .preset-btn:hover,
        .preset-btn.active {
            border-color: var(--gold);
            color: var(--gold);
            background: var(--gold-dim);
        }

        .form-control,
        .form-select {
            background: #111 !important;
            border: 1px solid var(--border) !important;
            color: var(--text) !important;
            border-radius: 8px !important;
            font-size: .84rem;
            transition: border-color .2s;
            height: 38px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--gold) !important;
            box-shadow: 0 0 0 3px var(--gold-dim) !important;
            outline: none;
        }

        .form-label {
            font-size: .72rem;
            color: var(--text-muted);
            font-weight: 600;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: .04em;
            display: block;
        }

        .btn-apply {
            background: linear-gradient(135deg, var(--gold), #f0cb67);
            color: #111;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: .84rem;
            padding: 8px 18px;
            cursor: pointer;
            transition: all .2s;
            height: 38px;
            white-space: nowrap;
        }

        .btn-apply:hover {
            filter: brightness(.95);
        }

        .btn-clear {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-muted);
            border-radius: 8px;
            font-size: .84rem;
            padding: 8px 18px;
            text-decoration: none;
            transition: all .2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 38px;
            white-space: nowrap;
        }

        .btn-clear:hover {
            border-color: var(--text-muted);
            color: var(--text);
        }

        .filter-active-dot {
            display: inline-block;
            width: 7px;
            height: 7px;
            background: var(--gold);
            border-radius: 50%;
            margin-left: 4px;
        }

        /* ── RESULTS BAR ────────────────────────────────────── */
        .results-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 6px;
        }

        .results-meta {
            color: var(--text-muted);
            font-size: .8rem;
        }

        .results-meta strong {
            color: var(--text);
        }

        .privacy-note {
            margin-bottom: 14px;
            padding: 11px 14px;
            border: 1px solid var(--gold-border);
            border-radius: 10px;
            background: rgba(212, 175, 55, .08);
            color: #d8d8d8;
            font-size: .78rem;
            line-height: 1.55;
        }

        .privacy-note strong {
            color: var(--gold);
        }

        /* ── DESKTOP TABLE ──────────────────────────────────── */
        .table-wrap {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .audit-table {
            width: 100%;
            border-collapse: collapse;
        }

        .audit-table thead th {
            background: #111;
            color: var(--gold);
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            padding: 13px 14px;
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }

        .audit-table tbody tr {
            border-bottom: 1px solid var(--border);
            transition: background .15s;
        }

        .audit-table tbody tr:last-child {
            border-bottom: none;
        }

        .audit-table tbody tr:hover {
            background: rgba(212, 175, 55, .04);
        }

        .audit-table tbody td {
            padding: 12px 14px;
            vertical-align: middle;
            font-size: .83rem;
        }

        @media (max-width: 1100px) {
            .col-ip {
                display: none;
            }
        }

        @media (max-width: 900px) {

            .col-table,
            .col-record {
                display: none;
            }
        }

        .desktop-table {
            display: block;
        }

        .mobile-log-list {
            display: none;
        }

        @media (max-width: 767px) {
            .desktop-table {
                display: none;
            }

            .mobile-log-list {
                display: block;
            }
        }

        /* ── MOBILE CARD LIST ───────────────────────────────── */
        .log-card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 14px 15px;
            margin-bottom: 10px;
            transition: border-color .2s, box-shadow .2s;
            position: relative;
            overflow: hidden;
        }

        .log-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: transparent;
            border-radius: 3px 0 0 3px;
            transition: background .2s;
        }

        .log-card:hover {
            border-color: var(--gold-border);
            box-shadow: 0 4px 20px rgba(212, 175, 55, .07);
        }

        .log-card:hover::before {
            background: var(--gold);
        }

        .log-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 10px;
        }

        .log-card-time {
            font-size: .73rem;
            color: var(--text-muted);
            text-align: right;
            flex-shrink: 0;
            line-height: 1.5;
            white-space: nowrap;
        }

        .log-card-time .time-date {
            color: var(--text);
            font-weight: 600;
            font-size: .78rem;
            display: block;
        }

        .log-card-time .time-clock {
            color: var(--text-muted);
            font-size: .71rem;
            display: block;
        }

        .log-card-actor {
            margin-bottom: 10px;
        }

        .log-card-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 0;
            margin-bottom: 10px;
            font-size: .76rem;
            color: var(--text-muted);
            line-height: 1.5;
        }

        .log-card-meta-sep {
            margin: 0 8px;
            color: #3a3a3a;
        }

        .log-card-meta strong {
            color: var(--text);
            font-weight: 600;
        }

        /* Simple detail text for mobile */
        .log-card-detail-simple {
            font-size: .8rem;
            color: #bbb;
            line-height: 1.5;
        }

        @media (min-width: 520px) and (max-width: 767px) {
            .mobile-log-list {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .empty-state-card {
                grid-column: 1 / -1;
            }
        }

        /* ── ACTOR BADGES ───────────────────────────────────── */
        .actor-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: .73rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            white-space: nowrap;
            max-width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .actor-admin {
            background: var(--gold-dim);
            color: var(--gold);
            border: 1px solid var(--gold-border);
        }

        .actor-staff {
            background: rgba(13, 202, 240, .1);
            color: #0dcaf0;
            border: 1px solid rgba(13, 202, 240, .25);
        }

        .actor-customer {
            background: rgba(108, 117, 125, .15);
            color: #adb5bd;
            border: 1px solid rgba(108, 117, 125, .3);
        }

        .actor-system {
            background: rgba(108, 117, 125, .1);
            color: #888;
            border: 1px solid var(--border);
        }

        /* ── ACTION BADGES ──────────────────────────────────── */
        .action-badge {
            display: inline-block;
            font-size: .7rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        .action-danger {
            background: rgba(220, 53, 69, .15);
            color: #ff6b6b;
            border: 1px solid rgba(220, 53, 69, .3);
        }

        .action-success {
            background: rgba(46, 204, 113, .12);
            color: #2ecc71;
            border: 1px solid rgba(46, 204, 113, .25);
        }

        .action-primary {
            background: rgba(13, 110, 253, .15);
            color: #6ea8fe;
            border: 1px solid rgba(13, 110, 253, .3);
        }

        .action-info {
            background: rgba(13, 202, 240, .12);
            color: #0dcaf0;
            border: 1px solid rgba(13, 202, 240, .25);
        }

        .action-secondary {
            background: rgba(108, 117, 125, .15);
            color: #adb5bd;
            border: 1px solid rgba(108, 117, 125, .3);
        }

        /* ── DETAIL TEXT (desktop) ──────────────────────────── */
        .detail-simple {
            color: #bbb;
            font-size: .83rem;
            line-height: 1.5;
        }

        /* ── TIMESTAMP (desktop) ────────────────────────────── */
        .ts-date {
            font-weight: 600;
            color: var(--text);
        }

        .ts-time {
            font-size: .73rem;
            color: var(--text-muted);
        }

        /* ── IP MONO ────────────────────────────────────────── */
        .ip-mono {
            font-family: 'Courier New', monospace;
            color: var(--gold);
            font-size: .8rem;
        }

        /* ── EMPTY STATE ────────────────────────────────────── */
        .empty-state {
            text-align: center;
            padding: clamp(30px, 8vw, 60px) 20px;
        }

        .empty-icon {
            width: 60px;
            height: 60px;
            background: var(--gold-dim);
            border: 1px solid var(--gold-border);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
            font-size: 1.4rem;
            color: var(--gold);
        }

        .empty-state h3 {
            font-size: .95rem;
            color: var(--text);
            margin-bottom: 5px;
        }

        .empty-state p {
            color: var(--text-muted);
            font-size: .82rem;
        }

        /* ── PAGINATION ─────────────────────────────────────── */
        .pagination-wrap {
            margin-top: 20px;
            display: flex;
            justify-content: center;
        }

        .pagination {
            flex-wrap: wrap;
            gap: 4px;
        }

        .pagination .page-link {
            background: var(--card-bg);
            border-color: var(--border);
            color: var(--gold);
            font-size: .8rem;
            padding: 6px 11px;
            border-radius: 7px !important;
            transition: all .2s;
            min-width: 36px;
            text-align: center;
        }

        .pagination .page-link:hover,
        .pagination .page-item.active .page-link {
            background: var(--gold);
            border-color: var(--gold);
            color: #111;
        }

        .pagination .page-item.disabled .page-link {
            background: #111;
            border-color: var(--border);
            color: #555;
            pointer-events: none;
        }
    </style>
</head>

<body>

    <!-- ── NAVBAR ─────────────────────────────────────────────────── -->
    <nav class="navbar">
        <div class="navbar-left">
            <a class="navbar-brand" href="../admin-dashboard.php">
                LA ELENA FISHYALAN
            </a>
            <span class="breadcrumb-sep">/</span>
            <span class="breadcrumb-current">Audit Logs</span>
        </div>
        <div class="navbar-right">
            <a href="../admin-dashboard.php" class="btn-nav btn-nav-back">
                <i class="fas fa-arrow-left"></i>
                <span class="btn-label">Admin Home</span>
            </a>
            <a href="../../auth/logout.php" class="btn-nav btn-logout logout-link">
                <i class="fas fa-sign-out-alt"></i>
                <span class="btn-label">Logout</span>
            </a>
        </div>
    </nav>

    <div class="page-wrap">

        <!-- Page Header -->
        <div class="page-header">
            <h1><i class="fas fa-shield-alt me-2" style="color:var(--gold);"></i>System Activity &amp; Audit Logs</h1>
            <p>Tracked actions performed across the system — searchable, filterable, paginated.</p>
        </div>

        <!-- ── FILTER CARD ───────────────────────────────────────── -->
        <div class="filter-card">
            <button class="filter-toggle-btn" id="filterToggleBtn" type="button">
                <span>
                    <i class="fas fa-filter me-2" style="color:#ffffff;"></i>
                    Filters &amp; Search
                    <?php if ($hasActiveFilters): ?><span class="filter-active-dot"></span><?php endif; ?>
                </span>
                <i class="fas fa-chevron-down toggle-arrow"></i>
            </button>

            <div class="filter-body" id="filterBody">
                <span class="section-label d-none d-md-flex">
                    <i class="fas fa-filter"></i>Filters &amp; Search
                    <?php if ($hasActiveFilters): ?><span class="filter-active-dot" title="Filters active"></span><?php endif; ?>
                </span>

                <div class="quick-presets">
                    <span class="quick-presets-label">Quick:</span>
                    <?php
                    $baseQs = 'action_filter=' . urlencode($action_filter) . '&search=' . urlencode($search);
                    $presets = [
                        ['today',     'Today'],
                        ['yesterday', 'Yesterday'],
                        ['last7',     'Last 7 Days'],
                        ['',          'All Time'],
                    ];
                    foreach ($presets as [$val, $label]):
                        $isActive = ($val === '' && $quick_date === '' && $date_filter === '' && $search === '' && $action_filter === 'all')
                            || ($val !== '' && $quick_date === $val && $date_filter === '');
                        $href = $val === '' ? 'viewAuditLogs.php' : "?{$baseQs}&quick_date=" . urlencode($val);
                    ?>
                        <a href="<?php echo $href; ?>" class="preset-btn <?php echo $isActive ? 'active' : ''; ?>">
                            <?php echo $label; ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <form method="GET" id="filterForm">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-sm-6 col-lg-4">
                            <label class="form-label">Search</label>
                            <input type="text" name="search" class="form-control"
                                value="<?php echo htmlspecialchars($search); ?>"
                                placeholder="Actor, action, IP, details…">
                        </div>
                        <div class="col-6 col-sm-3 col-lg-3">
                            <label class="form-label">Action Type</label>
                            <select name="action_filter" class="form-select">
                                <option value="all">All Actions</option>
                                <?php foreach ($allActions as $act): ?>
                                    <option value="<?php echo htmlspecialchars($act); ?>"
                                        <?php echo $action_filter === $act ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($act); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6 col-sm-3 col-lg-3">
                            <label class="form-label">Specific Date</label>
                            <input type="date" name="date_filter" class="form-control"
                                value="<?php echo htmlspecialchars($validatedDate); ?>">
                        </div>
                        <div class="col-12 col-lg-2 d-flex gap-2">
                            <button type="submit" class="btn-apply flex-fill">
                                <i class="fas fa-search me-1"></i>Apply
                            </button>
                            <a href="viewAuditLogs.php" class="btn-clear flex-fill">Clear</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Results bar -->
        <div class="results-bar">
            <div class="results-meta">
                <?php if ($totalLogs > 0): ?>
                    Showing <strong><?php echo $resultsStart; ?>–<?php echo $resultsEnd; ?></strong>
                    of <strong><?php echo number_format($totalLogs); ?></strong> logs
                <?php else: ?>
                    <strong>0</strong> logs found
                <?php endif; ?>
            </div>
            <div class="results-meta">
                Page <strong><?php echo $page; ?></strong> of <strong><?php echo $totalPages; ?></strong>
            </div>
        </div>

        <div class="privacy-note">
            <strong>Privacy notice:</strong>
            IP addresses are treated as security data and are masked for standard admins. Full IP visibility is limited to Super Admin accounts and should follow your documented retention and disposal schedule.
        </div>

        <!-- ── DESKTOP TABLE (≥768px) ───────────────────────────── -->
        <div class="table-wrap desktop-table">
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th class="col-table">Table</th>
                        <th class="col-record">Record #</th>
                        <th>Details</th>
                        <th class="col-ip">IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($logs) > 0): ?>
                        <?php foreach ($logs as $log):
                            $simpleDetail = getSimpleDetail($log['action'], $log['action_details'] ?? null);
                            $actorHtml    = renderActorLabel($log);
                            $actionColor  = getActionColor($log['action']);
                            $displayIp    = $canViewFullIp
                                ? ($log['ip_address'] ?: '--')
                                : maskIpAddress($log['ip_address'] ?? '');
                        ?>
                            <tr>
                                <td>
                                    <div class="ts-date"><?php echo date('M d, Y', strtotime($log['timestamp'])); ?></div>
                                    <div class="ts-time"><?php echo date('H:i:s', strtotime($log['timestamp'])); ?></div>
                                </td>
                                <td><?php echo $actorHtml; ?></td>
                                <td>
                                    <span class="action-badge action-<?php echo $actionColor; ?>">
                                        <?php echo htmlspecialchars($log['action']); ?>
                                    </span>
                                </td>
                                <td class="col-table" style="color:var(--text-muted);font-size:.79rem;">
                                    <?php echo htmlspecialchars($log['table_affected'] ?? '—'); ?>
                                </td>
                                <td class="col-record" style="color:var(--text-muted);font-size:.79rem;">
                                    <?php echo htmlspecialchars($log['record_id'] ?? '—'); ?>
                                </td>
                                <td style="max-width:280px;min-width:160px;">
                                    <span class="detail-simple"><?php echo htmlspecialchars($simpleDetail); ?></span>
                                </td>
                                <td class="col-ip">
                                    <span class="ip-mono"><?php echo htmlspecialchars($displayIp); ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">
                                    <div class="empty-icon"><i class="fas fa-search"></i></div>
                                    <h3>No logs found</h3>
                                    <p>No audit logs match your current filters.<br>Try adjusting your search or clearing the filters.</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- ── MOBILE CARD LIST (<768px) ────────────────────────── -->
        <div class="mobile-log-list">
            <?php if (count($logs) > 0): ?>
                <?php foreach ($logs as $log):
                    $simpleDetail = getSimpleDetail($log['action'], $log['action_details'] ?? null);
                    $actorHtml    = renderActorLabel($log);
                    $actionColor  = getActionColor($log['action']);
                    $displayIp    = $canViewFullIp
                        ? ($log['ip_address'] ?: '--')
                        : maskIpAddress($log['ip_address'] ?? '');
                ?>
                    <div class="log-card">
                        <div class="log-card-top">
                            <span class="action-badge action-<?php echo $actionColor; ?>">
                                <?php echo htmlspecialchars($log['action']); ?>
                            </span>
                            <div class="log-card-time">
                                <span class="time-date"><?php echo date('M d, Y', strtotime($log['timestamp'])); ?></span>
                                <span class="time-clock"><?php echo date('H:i:s', strtotime($log['timestamp'])); ?></span>
                            </div>
                        </div>

                        <div class="log-card-actor"><?php echo $actorHtml; ?></div>

                        <div class="log-card-meta">
                            <span>Table: <strong><?php echo htmlspecialchars($log['table_affected'] ?? '—'); ?></strong></span>
                            <span class="log-card-meta-sep">|</span>
                            <span>Record: <strong>#<?php echo htmlspecialchars($log['record_id'] ?? '—'); ?></strong></span>
                            <?php if ($displayIp !== '--'): ?>
                                <span class="log-card-meta-sep">|</span>
                                <span>IP: <strong class="ip-mono"><?php echo htmlspecialchars($displayIp); ?></strong></span>
                            <?php endif; ?>
                        </div>

                        <div class="log-card-detail-simple"><?php echo htmlspecialchars($simpleDetail); ?></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state-card">
                    <div class="empty-state">
                        <div class="empty-icon"><i class="fas fa-search"></i></div>
                        <h3>No logs found</h3>
                        <p>Try adjusting your filters or clearing the search.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- ── PAGINATION ───────────────────────────────────────── -->
        <?php if ($totalPages > 1): ?>
            <nav class="pagination-wrap" aria-label="Audit log pages">
                <ul class="pagination">
                    <?php
                    $prevParams = $paginationBaseParams;
                    $prevParams['page'] = max(1, $page - 1);
                    $nextParams = $paginationBaseParams;
                    $nextParams['page'] = min($totalPages, $page + 1);
                    ?>
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($prevParams)); ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    <?php
                    $pagesToShow  = [];
                    for ($i = 1; $i <= $totalPages; $i++) {
                        if ($i === 1 || $i === $totalPages || abs($i - $page) <= 2) $pagesToShow[] = $i;
                    }
                    $lastRendered = 0;
                    foreach ($pagesToShow as $pn):
                        if ($lastRendered > 0 && $pn - $lastRendered > 1):
                    ?>
                            <li class="page-item disabled"><span class="page-link">…</span></li>
                        <?php endif;
                        $pp = $paginationBaseParams;
                        $pp['page'] = $pn; ?>
                        <li class="page-item <?php echo $pn === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($pp)); ?>"><?php echo $pn; ?></a>
                        </li>
                    <?php $lastRendered = $pn;
                    endforeach; ?>
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query($nextParams)); ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>

    </div><!-- /page-wrap -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.1/dist/sweetalert2.all.min.js"></script>
    <script>
        // ── MOBILE FILTER TOGGLE ────────────────────────────────────
        const filterToggleBtn = document.getElementById('filterToggleBtn');
        const filterBody = document.getElementById('filterBody');

        if (filterToggleBtn && filterBody) {
            const hasActive = <?php echo $hasActiveFilters ? 'true' : 'false'; ?>;
            if (hasActive) {
                filterBody.classList.add('open');
                filterToggleBtn.classList.add('open');
            }
            filterToggleBtn.addEventListener('click', function() {
                const isOpen = filterBody.classList.toggle('open');
                filterToggleBtn.classList.toggle('open', isOpen);
            });
        }

        // ── LOGOUT CONFIRM ──────────────────────────────────────────
        document.addEventListener('click', function(e) {
            const link = e.target.closest('.logout-link');
            if (!link) return;
            e.preventDefault();
            const href = link.href || '../../auth/logout.php';
            Swal.fire({
                title: 'Ready to leave?',
                text: 'You will be logged out of your session.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#d4af37',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, logout',
                cancelButtonText: 'Stay',
                background: '#1e1e1e',
                color: '#fff',
                reverseButtons: true,
                focusCancel: true,
            }).then(r => {
                if (r.isConfirmed) window.location.href = href;
            });
        });
    </script>
</body>

</html>
