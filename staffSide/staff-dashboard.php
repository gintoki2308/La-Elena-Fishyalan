<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../config/pos_helper.php';
require_once __DIR__ . '/../config/workforce_helper.php';

if (!isLoggedIn() || !isStaff()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$user = getCurrentUser();
$navAvatarSrc = getProfileAvatar($user['profile_picture'] ?? '', $user['first_name'] ?? '', $user['last_name'] ?? '');
$database = new Database();
$db = $database->connect();
$staffId = (int) ($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 0);
$canUsePos = $db && posCashier($db, $staffId) !== null;
$today = date('Y-m-d');

if ($staffId <= 0) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$message = '';
$messageType = 'success';
$error = '';
$checkInEarlyAllowanceMinutes = 15;

function fetchShiftAttendanceRecords(PDO $db, array $shiftIds, bool $lockRows = false): array
{
    if (empty($shiftIds)) {
        return [];
    }

    $placeholders = [];
    $params = [];

    foreach (array_values($shiftIds) as $index => $shiftId) {
        $placeholder = ':shift_id_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = (int) $shiftId;
    }

    $query = "SELECT a.*, s.start_time, s.end_time FROM staff_attendance a JOIN staff_shifts s ON s.shift_id = a.shift_id WHERE a.shift_id IN (" . implode(', ', $placeholders) . ") ORDER BY a.check_in_time";
    if ($lockRows) {
        $query .= " FOR UPDATE";
    }

    $stmt = $db->prepare($query);
    foreach ($params as $placeholder => $value) {
        $stmt->bindValue($placeholder, $value, PDO::PARAM_INT);
    }
    $stmt->execute();

    $records = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
        $id = (int)$record['shift_id'];
        $minutes = workforceMinutes($record);
        if (!isset($records[$id])) {
            $records[$id] = $record;
            $records[$id]['worked_minutes'] = $minutes;
        } else {
            $records[$id]['worked_minutes'] += $minutes;
            $records[$id]['check_out_time'] = $record['check_out_time'];
            if ($record['status'] === 'LATE') $records[$id]['status'] = 'LATE';
        }
    }

    return $records;
}

function fetchShiftAttendanceRecord(PDO $db, int $shiftId, bool $lockRow = false): ?array
{
    $query = "SELECT * FROM staff_attendance WHERE shift_id = :shift_id LIMIT 1";
    if ($lockRow) {
        $query .= " FOR UPDATE";
    }

    $stmt = $db->prepare($query);
    $stmt->bindValue(':shift_id', $shiftId, PDO::PARAM_INT);
    $stmt->execute();

    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    return $record ?: null;
}

function fetchOwnedShift(PDO $db, int $staffId, int $shiftId): ?array
{
    $stmt = $db->prepare("SELECT * FROM staff_shifts WHERE shift_id = :shift_id AND staff_id = :staff_id LIMIT 1");
    $stmt->bindValue(':shift_id', $shiftId, PDO::PARAM_INT);
    $stmt->bindValue(':staff_id', $staffId, PDO::PARAM_INT);
    $stmt->execute();

    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    return $shift ?: null;
}

function formatWorkedDuration(?string $checkInTime, ?string $checkOutTime): string
{
    if (empty($checkInTime) || empty($checkOutTime)) {
        return '--';
    }

    $start = strtotime($checkInTime);
    $end = strtotime($checkOutTime);
    if ($start === false || $end === false || $end < $start) {
        return '--';
    }

    $minutes = (int) floor(($end - $start) / 60);
    $hours = intdiv($minutes, 60);
    $remainingMinutes = $minutes % 60;

    return sprintf('%dh %02dm', $hours, $remainingMinutes);
}

function getWorkedMinutesFromRecord(?array $record): int
{
    if (isset($record['worked_minutes'])) return (int)$record['worked_minutes'];
    if (!$record || empty($record['check_in_time']) || empty($record['check_out_time'])) {
        return 0;
    }

    $start = strtotime($record['check_in_time']);
    $end = strtotime($record['check_out_time']);
    if ($start === false || $end === false || $end < $start) {
        return 0;
    }

    return (int) floor(($end - $start) / 60);
}

function formatMinutesAsDuration(int $minutes): string
{
    if ($minutes <= 0) {
        return '0h 00m';
    }

    return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
}

function getAttendanceDisplayState(array $record): array
{
    $hasCheckIn = !empty($record['check_in_time']);
    $hasCheckOut = !empty($record['check_out_time']);
    $status = strtoupper((string) ($record['status'] ?? 'ABSENT'));

    if ($hasCheckIn && !$hasCheckOut) {
        return ['label' => 'INCOMPLETE', 'class' => 'bg-warning text-dark'];
    }

    if (!$hasCheckIn && $hasCheckOut) {
        return ['label' => 'INVALID', 'class' => 'bg-danger'];
    }

    if ($status === 'LATE') {
        return ['label' => 'LATE', 'class' => 'bg-warning text-dark'];
    }

    if ($status === 'PRESENT') {
        return ['label' => 'PRESENT', 'class' => 'bg-success'];
    }

    return ['label' => $status, 'class' => 'bg-secondary'];
}

function buildShiftState(array $shift, ?array $attendanceRecord, int $referenceTimestamp): array
{
    $shiftDate = (string) ($shift['shift_date'] ?? date('Y-m-d'));
    $startTimestamp = strtotime($shiftDate . ' ' . $shift['start_time']);
    $endTimestamp = strtotime($shiftDate . ' ' . $shift['end_time']);
    $checkInOpensTimestamp = $startTimestamp - ($GLOBALS['checkInEarlyAllowanceMinutes'] * 60);

    $hasCheckedIn = !empty($attendanceRecord['check_in_time']);
    $hasCheckedOut = !empty($attendanceRecord['check_out_time']);
    $hasInvalidAttendanceSequence = !$hasCheckedIn && $hasCheckedOut;
    $windowLabel = date('H:i', $startTimestamp) . ' - ' . date('H:i', $endTimestamp);

    if ($hasInvalidAttendanceSequence) {
        $displayState = ['label' => 'INVALID', 'class' => 'bg-danger'];
    } elseif ($hasCheckedIn && !$hasCheckedOut) {
        $displayState = ['label' => 'CHECKED IN', 'class' => 'bg-info text-dark'];
    } elseif ($hasCheckedOut) {
        $displayState = getAttendanceDisplayState($attendanceRecord ?? []);
    } elseif ($referenceTimestamp > $endTimestamp) {
        $displayState = ['label' => 'MISSED', 'class' => 'bg-secondary'];
    } elseif ($referenceTimestamp < $checkInOpensTimestamp) {
        $displayState = ['label' => 'UPCOMING', 'class' => 'bg-secondary'];
    } else {
        $displayState = ['label' => 'READY', 'class' => 'bg-primary'];
    }

    $canCheckIn = !$hasCheckedIn
        && !$hasInvalidAttendanceSequence
        && $referenceTimestamp >= $checkInOpensTimestamp
        && $referenceTimestamp <= $endTimestamp;
    $canCheckOut = $hasCheckedIn
        && !$hasCheckedOut
        && $referenceTimestamp >= $endTimestamp;

    $checkInStateText = 'Available for this shift.';
    if ($hasInvalidAttendanceSequence) {
        $checkInStateText = 'This shift has an invalid attendance record that needs admin correction.';
    } elseif ($hasCheckedIn) {
        $checkInStateText = 'Already checked in at ' . date('H:i', strtotime($attendanceRecord['check_in_time']));
    } elseif ($referenceTimestamp < $checkInOpensTimestamp) {
        $checkInStateText = 'Check-in opens at ' . date('H:i', $checkInOpensTimestamp) . ' (15 minutes before shift start).';
    } elseif ($referenceTimestamp > $endTimestamp) {
        $checkInStateText = 'This shift has already ended.';
    }

    $checkOutStateText = 'Check in to this shift first.';
    if ($hasInvalidAttendanceSequence) {
        $checkOutStateText = 'This shift has an invalid attendance record that needs admin correction.';
    } elseif ($hasCheckedOut) {
        $checkOutStateText = 'Already checked out at ' . date('H:i', strtotime($attendanceRecord['check_out_time']));
    } elseif ($hasCheckedIn) {
        if ($referenceTimestamp < $endTimestamp) {
            $checkOutStateText = 'Check-out will be available at ' . date('H:i', $endTimestamp) . ' when this shift ends.';
        } else {
            $checkOutStateText = 'Ready to check out from this shift.';
        }
    }

    return [
        'shift' => $shift,
        'attendance_record' => $attendanceRecord,
        'start_timestamp' => $startTimestamp,
        'end_timestamp' => $endTimestamp,
        'check_in_opens_timestamp' => $checkInOpensTimestamp,
        'window_label' => $windowLabel,
        'has_checked_in' => $hasCheckedIn,
        'has_checked_out' => $hasCheckedOut,
        'has_invalid_attendance_sequence' => $hasInvalidAttendanceSequence,
        'worked_minutes' => getWorkedMinutesFromRecord($attendanceRecord),
        'worked_duration' => formatMinutesAsDuration(getWorkedMinutesFromRecord($attendanceRecord)),
        'display_state' => $displayState,
        'can_check_in' => $canCheckIn,
        'can_check_out' => $canCheckOut,
        'check_in_state_text' => $checkInStateText,
        'check_out_state_text' => $checkOutStateText,
        'is_open' => $hasCheckedIn && !$hasCheckedOut,
        'is_current_window' => $referenceTimestamp >= $startTimestamp && $referenceTimestamp <= $endTimestamp,
        'is_completed' => $hasCheckedOut,
    ];
}

function buildTodayShiftStates(array $todayShiftData, array $attendanceByShift, int $referenceTimestamp): array
{
    $states = [];
    foreach ($todayShiftData as $shift) {
        $shiftId = (int) ($shift['shift_id'] ?? 0);
        $states[] = buildShiftState($shift, $attendanceByShift[$shiftId] ?? null, $referenceTimestamp);
    }

    return $states;
}

function pickFocusShiftState(array $shiftStates): ?array
{
    foreach ($shiftStates as $state) {
        if ($state['is_open']) {
            return $state;
        }
    }

    foreach ($shiftStates as $state) {
        if ($state['is_current_window'] && !$state['is_completed']) {
            return $state;
        }
    }

    foreach ($shiftStates as $state) {
        if ($state['can_check_in']) {
            return $state;
        }
    }

    foreach ($shiftStates as $state) {
        if (!$state['is_completed']) {
            return $state;
        }
    }

    if (empty($shiftStates)) {
        return null;
    }

    return $shiftStates[count($shiftStates) - 1];
}

$todayShiftData = [];
$todayAttendanceByShift = [];
$todayShiftStates = [];
$focusShiftState = null;
$nextShiftData = null;
$pendingOrders = 0;
$ordersToday = 0;
$lowStock = 0;
$lowStockMenu = 0;
$recentOrders = [];
$shiftDisplay = 'No Shift Scheduled';
$shiftCardLabel = 'Next Shift';
$scheduleCardTitle = 'Schedule Snapshot';
$scheduleCardData = [];
$scheduleEmptyMessage = 'No shifts scheduled yet.';
$hasShift = false;
$shiftStartTimeMs = 0;
$shiftEndTimeMs = 0;
$todayShiftWindowLabel = 'No shift scheduled today.';
$todayAttendanceStatus = 'ABSENT';
$todayWorkedDuration = '0h 00m';
$canCheckIn = false;
$canCheckOut = false;
$checkInStateText = 'No shift scheduled today.';
$checkOutStateText = 'Check in to a scheduled shift first.';
$activeShiftId = 0;
$staffRoleLabel = 'Staff';
$supportsShiftAttendance = dbTableHasColumn($db, 'staff_attendance', 'shift_id');

try {
    $staffRoleStmt = $db->prepare('SELECT role FROM staffs WHERE staff_id = :staff_id LIMIT 1');
    $staffRoleStmt->execute([':staff_id' => $staffId]);
    $staffRoleLabel = trim((string) $staffRoleStmt->fetchColumn()) ?: 'Staff';

    if (!$supportsShiftAttendance) {
        throw new Exception('Attendance per-shift support is not ready yet. Please run the latest attendance migration first.');
    }

    $todayShiftsQuery = "SELECT * FROM staff_shifts WHERE staff_id = :staff_id AND shift_date = :today ORDER BY start_time ASC";
    $todayShiftsStmt = $db->prepare($todayShiftsQuery);
    $todayShiftsStmt->bindValue(':staff_id', $staffId, PDO::PARAM_INT);
    $todayShiftsStmt->bindValue(':today', $today);
    $todayShiftsStmt->execute();
    $todayShiftData = $todayShiftsStmt->fetchAll(PDO::FETCH_ASSOC);

    $nextShiftQuery = "SELECT * FROM staff_shifts WHERE staff_id = :staff_id AND shift_date >= :today ORDER BY shift_date ASC, start_time ASC LIMIT 1";
    $nextShiftStmt = $db->prepare($nextShiftQuery);
    $nextShiftStmt->bindValue(':staff_id', $staffId, PDO::PARAM_INT);
    $nextShiftStmt->bindValue(':today', $today);
    $nextShiftStmt->execute();
    $nextShiftData = $nextShiftStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!empty($todayShiftData)) {
        $scheduleCardData = $todayShiftData;
        $scheduleCardTitle = "Today's Schedule";
    } elseif ($nextShiftData) {
        $scheduleCardData = [$nextShiftData];
    } else {
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Location: viewAttendance.php'); exit; }

    if (!empty($todayShiftData)) {
        $todayAttendanceByShift = fetchShiftAttendanceRecords($db, array_column($todayShiftData, 'shift_id'));
        $todayShiftStates = buildTodayShiftStates($todayShiftData, $todayAttendanceByShift, time());
        $focusShiftState = pickFocusShiftState($todayShiftStates);

        if ($focusShiftState) {
            $hasShift = true;
            $shiftDisplay = $focusShiftState['window_label'];
            $shiftCardLabel = ($focusShiftState['is_open'] || $focusShiftState['is_current_window']) ? 'Current Shift' : "Today's Shift";
            $shiftStartTimeMs = $focusShiftState['start_timestamp'] * 1000;
            $shiftEndTimeMs = $focusShiftState['end_timestamp'] * 1000;
            $todayShiftWindowLabel = $focusShiftState['window_label'];
            $todayAttendanceStatus = $focusShiftState['display_state']['label'];
            $todayWorkedDuration = formatMinutesAsDuration(array_sum(array_column($todayShiftStates, 'worked_minutes')));
            $canCheckIn = $focusShiftState['can_check_in'];
            $canCheckOut = $focusShiftState['can_check_out'];
            $checkInStateText = $focusShiftState['check_in_state_text'];
            $checkOutStateText = $focusShiftState['check_out_state_text'];
            $activeShiftId = (int) ($focusShiftState['shift']['shift_id'] ?? 0);
        }
    } elseif ($nextShiftData) {
        $hasShift = true;
        $nextShiftStartTimestamp = strtotime($nextShiftData['shift_date'] . ' ' . $nextShiftData['start_time']);
        $nextShiftEndTimestamp = strtotime($nextShiftData['shift_date'] . ' ' . $nextShiftData['end_time']);
        $shiftDisplay = date('M d', strtotime($nextShiftData['shift_date'])) . ' '
            . date('H:i', $nextShiftStartTimestamp) . ' - ' . date('H:i', $nextShiftEndTimestamp);
        $shiftCardLabel = 'Next Shift';
        $shiftStartTimeMs = $nextShiftStartTimestamp * 1000;
        $shiftEndTimeMs = $nextShiftEndTimestamp * 1000;
    }

    $attendanceSummary=workforceDaySummary($db,$staffId,$today);
    $todayWorkedDuration=workforceDuration($attendanceSummary['minutes']);
    $todayAttendanceStatus=$attendanceSummary['status'];
    if (empty($todayShiftData)) $todayShiftWindowLabel='Flexible attendance available without an assigned shift.';

    $pendingOrdersQuery = "SELECT COUNT(*) as pending_orders FROM online_orders WHERE status = 'PENDING'";
    $pendingOrdersStmt  = $db->query($pendingOrdersQuery);
    $pendingOrders      = $pendingOrdersStmt->fetch(PDO::FETCH_ASSOC)['pending_orders'] ?? 0;

    $ordersQuery  = "SELECT COUNT(*) as orders_today FROM online_orders WHERE DATE(order_date) = CURDATE()";
    $ordersStmt   = $db->query($ordersQuery);
    $ordersToday  = $ordersStmt->fetch(PDO::FETCH_ASSOC)['orders_today'] ?? 0;

    $lowStockQuery = "SELECT COUNT(*) as low_stock FROM ingredients WHERE current_stock <= reorder_level";
    $lowStockStmt  = $db->query($lowStockQuery);
    $lowStock      = $lowStockStmt->fetch(PDO::FETCH_ASSOC)['low_stock'] ?? 0;

    $lowStockMenuQuery = "SELECT COUNT(*) as low_stock_menu FROM menu_items WHERE stock_quantity <= reorder_level";
    $lowStockMenuStmt  = $db->query($lowStockMenuQuery);
    $lowStockMenu      = $lowStockMenuStmt->fetch(PDO::FETCH_ASSOC)['low_stock_menu'] ?? 0;

    $recentOrdersQuery = "
        SELECT oo.*,
               COALESCE((SELECT SUM(quantity) FROM online_order_items ooi WHERE ooi.order_id = oo.order_id), 0) as item_count,
               COALESCE(m.first_name, CONCAT('[Deleted] ', COALESCE(oo.delivery_name, 'Customer'))) AS customer_first_name,
               COALESCE(m.last_name, '') AS customer_last_name
        FROM online_orders oo
        LEFT JOIN customer m ON m.member_id = oo.member_id
        ORDER BY CASE oo.status
                    WHEN 'PENDING' THEN 1
                    WHEN 'CONFIRMED' THEN 2
                    WHEN 'PREPARING' THEN 3
                    WHEN 'READY' THEN 4
                    WHEN 'OUT_FOR_DELIVERY' THEN 5
                    WHEN 'DELIVERED' THEN 6
                    WHEN 'COMPLETED' THEN 7
                    WHEN 'CANCELLED' THEN 8
                    ELSE 9
                 END, oo.order_date DESC
        LIMIT 5
    ";
    $recentOrdersStmt = $db->query($recentOrdersQuery);
    $recentOrders     = $recentOrdersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    if ($e->getCode() === '23000') {
        $error = 'Attendance record conflict detected. Please refresh and try again.';
    } else {
        $error = 'Attendance action failed: ' . $e->getMessage();
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Dashboard - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="staff-dashboard.css">
</head>
<body>

    <!-- ═══ NAVBAR ════════════════════════════════════════════════════ -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
        <div class="container-fluid">

            <!-- Hamburger (tablet only – hidden on mobile via CSS) -->
            <button class="navbar-toggler me-2 d-lg-none" type="button" id="sidebarToggle" aria-label="Toggle sidebar" aria-controls="mainSidebar" aria-expanded="false">
                <span class="navbar-toggler-icon"></span>
            </button>

            <a class="navbar-brand" href="#">
                <i class="fas fa-user-tie me-2"></i>Staff Panel
            </a>

            <!-- User dropdown – always visible on the right -->
            <div class="ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#"
                       id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="<?php echo $navAvatarSrc; ?>" alt=""
                             class="rounded-circle me-2" width="35" height="35"
                             style="object-fit:cover;border:1.5px solid var(--gold);">
                        <span class="d-none d-sm-inline text-start">
                            <span class="d-block"><?php echo htmlspecialchars($user['first_name'] ?? 'Staff'); ?></span>
                            <small class="d-block text-gold"><?php echo htmlspecialchars($staffRoleLabel); ?></small>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li><span class="dropdown-header">Role: <?php echo htmlspecialchars($staffRoleLabel); ?></span></li>
                        <li>
                            <a class="dropdown-item" href="profile.php">
                                <i class="fas fa-user-tie me-2"></i>My Profile
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="settings.php">
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
            </div>

        </div>
    </nav>

    <!-- ═══ LAYOUT SHELL ══════════════════════════════════════════════ -->
    <div class="layout-shell">

        <!-- Sidebar overlay for phones and tablets -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- ── Sidebar ─────────────────────────────────────────────── -->
        <aside class="sidebar" id="mainSidebar" aria-label="Staff navigation">
            <div class="sidebar-header">
                <h5 class="text-gold mb-3">
                    <i class="fas fa-tasks me-2"></i>Dashboard
                </h5>
                <button class="sidebar-close d-lg-none" type="button" aria-label="Close sidebar">&times;</button>
            </div>
            <nav class="nav flex-column">
                <a class="nav-link active" href="#mainContent" aria-current="page">
                    <i class="fas fa-home me-2"></i>Overview
                </a>
                <?php if ($canUsePos): ?>
                <a class="nav-link" href="pos/index.php"><i class="fas fa-cash-register me-2"></i>Point of Sale</a>
                <?php endif; ?>
                <a class="nav-link" href="onlineOrdersCrud/viewOnlineOrders.php">
                    <i class="fas fa-shopping-cart me-2"></i>Orders
                </a>
                <a class="nav-link" href="viewSchedule.php">
                    <i class="fas fa-calendar me-2"></i>My Shifts
                </a>
                <a class="nav-link" href="viewAttendance.php">
                    <i class="fas fa-clock me-2"></i>Attendance
                </a>
                <a class="nav-link" href="viewReservations.php">
                    <i class="fas fa-calendar-alt me-2"></i>Reservations
                </a>
                <a class="nav-link" href="menuManagementCrud/viewMenuItems.php">
                    <i class="fas fa-utensils me-2"></i>Menu Management
                </a>
                <a class="nav-link" href="inventoryCrud/viewInventory.php">
                    <i class="fas fa-boxes me-2"></i>Inventory
                </a>
                <a class="nav-link" href="profile.php">
                    <i class="fas fa-user me-2"></i>My Profile
                </a>
                <a class="nav-link" href="settings.php">
                    <i class="fas fa-cog me-2"></i>Settings
                </a>
            </nav>
        </aside>

        <!-- ── Main content ────────────────────────────────────────── -->
        <main class="main-content" id="mainContent">

            <!-- Header -->
            <div class="dashboard-header">
                <h1><i class="fas fa-tasks me-2 text-gold"></i>Staff Dashboard</h1>
                <p class=" text-gold-1">
                    Welcome back, <?php echo htmlspecialchars($user['first_name'] ?? 'Staff'); ?>!
                </p>
                <span class="badge rounded-pill bg-gold">
                    <i class="fas fa-id-badge me-1" aria-hidden="true"></i>Role: <?php echo htmlspecialchars($staffRoleLabel); ?>
                </span>
            </div>

            <!-- Alerts -->
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?php echo $messageType === 'warning' ? 'warning' : 'success'; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- ── Stat cards ──────────────────────────────────────── -->
            <div class="stats-grid mb-4">

                <div class="stat-card">
                    <div class="stat-icon shifts-icon">
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" style="font-size:clamp(1rem,2.5vw,1.35rem);">
                            <?php echo $shiftDisplay; ?>
                        </div>
                        <div class="stat-label"><?php echo htmlspecialchars($shiftCardLabel); ?></div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon pending-icon">
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $pendingOrders; ?></div>
                        <div class="stat-label">Pending Orders</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon orders-icon">
                        <i class="fas fa-shopping-bag"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $ordersToday; ?></div>
                        <div class="stat-label">Orders Today</div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon time-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value" id="shift-countdown">--:--:--</div>
                        <div class="stat-label" id="shift-countdown-label">Time to Shift</div>
                    </div>
                </div>

            </div><!-- /stats-grid -->

            <!-- ── Low-stock alert ─────────────────────────────────── -->
            <div class="action-card low-stock-card mb-4"
                 style="background:rgba(212,175,55,0.08);border:1px solid var(--gold);">
                <h5 class="mb-3 low-stock-heading">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <span class="low-stock-title text-uppercase fw-bold">Low Stock Alerts</span>
                </h5>
                <div class="d-flex gap-4 mb-3 low-stock-flex flex-wrap">
                    <div>
                        <span class="low-stock-value"><?php echo $lowStock; ?></span>
                        <span class="low-stock-label">Low Stock Ingredients</span>
                    </div>
                    <div>
                        <span class="low-stock-value"><?php echo $lowStockMenu; ?></span>
                        <span class="low-stock-label">Menu Items</span>
                    </div>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a href="inventoryCrud/viewInventory.php" class="btn btn-sm btn-outline-warning">
                        <i class="fas fa-boxes me-1"></i>Ingredients
                    </a>
                    <a href="menuManagementCrud/viewMenuItems.php" class="btn btn-sm btn-outline-warning">
                        <i class="fas fa-utensils me-1"></i>Menu Items
                    </a>
                </div>
            </div>

            <!-- ── Quick actions + Schedule ───────────────────────── -->
            <div class="row g-3 mb-4">

                <div class="col-lg-6">
                    <div class="action-card">
                        <h5><i class="fas fa-tasks text-info me-2"></i>Quick Actions</h5>
                        <div class="small text-white-50 mb-3"></div>
                        <div class="d-flex flex-column gap-2">
                            <?php if ($canUsePos): ?>
                            <a href="pos/index.php" class="btn btn-success text-start"><i class="fas fa-cash-register me-2"></i>Open Point of Sale</a>
                            <?php endif; ?>
                            <a href="viewAttendance.php" class="btn btn-sm btn-outline-info text-start">
                                <i class="fas fa-clock me-2"></i>Time In / Out · My Attendance
                            </a>
                            <a href="viewSchedule.php" class="btn btn-sm btn-outline-info text-start">
                                <i class="fas fa-calendar-alt me-2"></i>My Schedule
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="action-card">
                        <h5>
                            <i class="fas fa-info-circle text-warning me-2"></i>
                            <?php echo htmlspecialchars($scheduleCardTitle); ?>
                        </h5>
                        <?php if (!empty($scheduleCardData)): ?>
                            <?php foreach ($scheduleCardData as $s): ?>
                                <?php
                                    $shiftState = null;
                                    if (!empty($todayShiftStates)) {
                                        foreach ($todayShiftStates as $candidateState) {
                                            if ((int) ($candidateState['shift']['shift_id'] ?? 0) === (int) ($s['shift_id'] ?? 0)) {
                                                $shiftState = $candidateState;
                                                break;
                                            }
                                        }
                                    }
                                ?>
                                <?php if (($s['shift_date'] ?? '') !== $today): ?>
                                    <p class="text-white-50 small mb-1">
                                        <i class="fas fa-calendar-day me-2"></i>
                                        <?php echo date('M d, Y', strtotime($s['shift_date'])); ?>
                                    </p>
                                <?php endif; ?>
                                <p class="text-white mb-1 fw-bold">
                                    <i class="far fa-clock me-2"></i>Shift:
                                    <?php echo date('H:i', strtotime($s['start_time'])) . ' - ' . date('H:i', strtotime($s['end_time'])); ?>
                                </p>
                                <?php if ($shiftState): ?>
                                    <p class="small mb-1">
                                        <span class="badge <?php echo htmlspecialchars($shiftState['display_state']['class']); ?>">
                                            <?php echo htmlspecialchars($shiftState['display_state']['label']); ?>
                                        </span>
                                    </p>
                                    <p class="text-white-50 small mb-1">
                                        <i class="fas fa-fingerprint me-2"></i>
                                        Check In:
                                        <?php echo !empty($shiftState['attendance_record']['check_in_time']) ? date('H:i', strtotime($shiftState['attendance_record']['check_in_time'])) : '-'; ?>
                                        |
                                        Check Out:
                                        <?php echo !empty($shiftState['attendance_record']['check_out_time']) ? date('H:i', strtotime($shiftState['attendance_record']['check_out_time'])) : '-'; ?>
                                    </p>
                                <?php endif; ?>
                                <p class="text-white-50 small mb-3">
                                    <i class="fas fa-sticky-note me-2"></i>
                                    <?php echo !empty($s['notes']) ? htmlspecialchars($s['notes']) : 'No notes.'; ?>
                                </p>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="text-white"><?php echo htmlspecialchars($scheduleEmptyMessage); ?></p>
                        <?php endif; ?>
                        <a href="viewSchedule.php" class="btn btn-sm btn-outline-warning mt-2">
                            <i class="fas fa-arrow-right me-1"></i>View Full Schedule
                        </a>
                    </div>
                </div>

            </div>

            <!-- ── Recent orders table ────────────────────────────── -->
            <div class="card bg-dark border-secondary">
                <div class="card-header bg-dark border-secondary d-flex align-items-center justify-content-between">
                    <h5 class="mb-0">Recent Orders</h5>
                    <a href="onlineOrdersCrud/viewOnlineOrders.php"
                       class="btn btn-sm btn-outline-warning">
                        <i class="fas fa-external-link-alt me-1"></i>
                        <span class="d-none d-sm-inline">View All</span>
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-dark table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Order ID</th>
                                    <th>Customer</th>
                                    <th>Items</th>
                                    <th>Status</th>
                                    <th>Time</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recentOrders)): ?>
                                    <?php foreach (array_slice($recentOrders, 0, 5) as $order): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($order['order_id']); ?></td>
                                            <td><?php echo htmlspecialchars(trim($order['customer_first_name'] . ' ' . $order['customer_last_name'])); ?></td>
                                            <td>
                                                <?php
                                                    $cnt = intval($order['item_count']);
                                                    echo $cnt . ' item' . ($cnt !== 1 ? 's' : '');
                                                ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo getStatusColor($order['status']); ?>">
                                                    <?php echo htmlspecialchars($order['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('H:i', strtotime($order['order_date'])); ?></td>
                                            <td>
                                                <a href="onlineOrdersCrud/viewOrderDetails.php?id=<?php echo urlencode($order['order_id']); ?>"
                                                   class="text-gold text-decoration-none">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-gold py-4">
                                            No recent orders
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </main><!-- /main-content -->
    </div><!-- /layout-shell -->

    <!-- ═══ BOTTOM NAV (mobile only) ════════════════════════════════ -->
    <nav class="bottom-nav" aria-label="Mobile navigation">
        <div class="bottom-nav-inner">
            <a class="bottom-nav-link active" href="#overview">
                <i class="fas fa-home"></i>Home
            </a>
            <?php if ($canUsePos): ?>
            <a class="bottom-nav-link" href="pos/index.php"><i class="fas fa-cash-register"></i><span>POS</span></a>
            <?php endif; ?>
            <a class="bottom-nav-link" href="onlineOrdersCrud/viewOnlineOrders.php">
                <i class="fas fa-shopping-cart"></i>Orders
            </a>
            <a class="bottom-nav-link" href="viewSchedule.php">
                <i class="fas fa-calendar"></i>Shifts
            </a>
            <a class="bottom-nav-link" href="viewAttendance.php">
                <i class="fas fa-clock"></i>Attendance
            </a>
            <a class="bottom-nav-link" href="viewReservations.php">
                <i class="fas fa-calendar-alt"></i>Reservations
            </a>
            <a class="bottom-nav-link" href="menuManagementCrud/viewMenuItems.php">
                <i class="fas fa-utensils"></i>Menu
            </a>
            <a class="bottom-nav-link" href="inventoryCrud/viewInventory.php">
                <i class="fas fa-boxes"></i>Inventory
            </a>
            <a class="bottom-nav-link" href="profile.php">
                <i class="fas fa-user"></i>Profile
            </a>
        </div>
    </nav>

    <!-- ═══ SCRIPTS ══════════════════════════════════════════════════ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Shift countdown -->
    <script>
        const hasShift    = <?php echo $hasShift ? 'true' : 'false'; ?>;
        const shiftStart  = <?php echo $shiftStartTimeMs; ?>;
        const shiftEnd    = <?php echo $shiftEndTimeMs; ?>;

        const countdownEl    = document.getElementById('shift-countdown');
        const countdownLabel = document.getElementById('shift-countdown-label');

        if (hasShift) {
            setInterval(() => {
                const now = Date.now();
                if (now < shiftStart) {
                    updateTimer(shiftStart - now, 'Time to Shift', '');
                } else if (now <= shiftEnd) {
                    updateTimer(shiftEnd - now, 'Shift Ends In', 'text-success');
                } else {
                    countdownEl.textContent      = '00:00:00';
                    countdownLabel.textContent   = 'Shift Ended';
                    countdownEl.className        = 'stat-value text-gold';
                }
            }, 1000);
        } else {
            countdownEl.textContent    = '--:--';
            countdownLabel.textContent = 'No Upcoming Shift';
        }

        function updateTimer(diffMs, label, colorClass) {
            const h = Math.floor(diffMs / 3600000);
            const m = Math.floor((diffMs % 3600000) / 60000);
            const s = Math.floor((diffMs % 60000) / 1000);
            countdownEl.textContent  = [h, m, s].map(n => String(n).padStart(2, '0')).join(':');
            countdownLabel.textContent = label;
            countdownEl.className    = colorClass ? `stat-value ${colorClass}` : 'stat-value';
        }
    </script>

    <!-- Shared staff sidebar behavior -->
    <script src="staff-sidebar.js"></script>

    <!-- Logout confirmation (SweetAlert2) -->
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
                if (result.isConfirmed) window.location.href = href;
            });
        });
    </script>

</body>
</html>

<?php
function getStatusColor($status) {
    $colors = [
        'PENDING'   => 'warning',
        'CONFIRMED' => 'info',
        'COMPLETED' => 'success',
        'CANCELLED' => 'danger',
        'DELIVERED' => 'success',
        'PREPARING' => 'primary',
    ];
    return $colors[$status] ?? 'secondary';
}
?>
