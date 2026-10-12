<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/workforce_admin_helper.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db       = $database->connect();
$shifts   = [];
$error    = '';
$success  = '';
$staffList = [];
$showScheduleModal = false;
$today = date('Y-m-d');

// ── Flash message ──────────────────────────────────────────────────────────
if (isset($_SESSION['schedule_flash_success'])) {
    $success = (string) $_SESSION['schedule_flash_success'];
    unset($_SESSION['schedule_flash_success']);
}

// ── Delete action ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_shift') {
    $shiftId      = (int) ($_POST['shift_id'] ?? 0);
    $redirectPage = max(1, (int) ($_POST['redirect_page'] ?? 1));
    $redirectSearch = trim($_POST['redirect_search'] ?? '');

    try {
        workforceVerifyCsrf($_POST['csrf_token'] ?? null);
        if ($shiftId <= 0) {
            throw new Exception('Invalid shift selected.');
        }

        $shiftStmt = $db->prepare(
            "SELECT ss.shift_id, ss.shift_date, ss.start_time, ss.end_time, s.first_name, s.last_name
             FROM staff_shifts ss
             JOIN staffs s ON ss.staff_id = s.staff_id
             WHERE ss.shift_id = :shift_id
             LIMIT 1"
        );
        $shiftStmt->execute([':shift_id' => $shiftId]);
        $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift) {
            throw new Exception('Shift not found.');
        }

        workforceDeleteShift($db,$shiftId);

        $staffName = trim(($shift['first_name'] ?? '') . ' ' . ($shift['last_name'] ?? ''));
        $shiftDate = date('M d, Y', strtotime($shift['shift_date']));
        $shiftTime = date('H:i', strtotime($shift['start_time'])) . ' - ' . date('H:i', strtotime($shift['end_time']));

        $_SESSION['schedule_flash_success'] = 'Deleted shift for ' . $staffName . ' on ' . $shiftDate . ' (' . $shiftTime . ').';

        $qs = http_build_query(array_filter([
            'page'   => $redirectPage > 1 ? $redirectPage : null,
            'search' => $redirectSearch !== '' ? $redirectSearch : null,
        ]));
        header('Location: viewSchedule.php' . ($qs ? '?' . $qs : ''));
        exit;
    } catch (Exception $e) {
        error_log('Delete Shift Error: ' . $e->getMessage());
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Could not delete the selected shift.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'schedule_shift') {
    $showScheduleModal = true;
    $staffId = (int) ($_POST['staff_id'] ?? 0);
    $shiftDate = $_POST['shift_date'] ?? '';
    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $shiftDateObj = DateTime::createFromFormat('Y-m-d', $shiftDate);
    $isValidShiftDate = $shiftDateObj && $shiftDateObj->format('Y-m-d') === $shiftDate;

    try {
        workforceVerifyCsrf($_POST['csrf_token'] ?? null);
        $staff = workforceScheduleShift($db,$staffId,$shiftDate,$startTime,$endTime,$notes);

        $_SESSION['schedule_flash_success'] = 'Shift scheduled for ' . trim($staff['first_name'] . ' ' . $staff['last_name']) . '.';
        header('Location: viewSchedule.php');
        exit;
    } catch (Exception $e) {
        $error = 'Could not schedule shift: ' . $e->getMessage();
    }
}

try {
    $staffList = $db->query('SELECT staff_id, first_name, last_name FROM staffs ORDER BY first_name, last_name')->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Unable to retrieve staff members.';
}

// ── Filter & Pagination params ─────────────────────────────────────────────
$perPage     = 12;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$search      = trim($_GET['search'] ?? '');

// ── Count total matching rows ──────────────────────────────────────────────
$totalRecords = 0;
$totalPages   = 1;

try {
    $searchParam = $search !== '' ? '%' . $search . '%' : null;

    $whereSearch = $searchParam
        ? "AND (s.first_name LIKE :search OR s.last_name LIKE :search_last OR CONCAT(s.first_name,' ',s.last_name) LIKE :search_full)"
        : '';

    $countSql  = "SELECT COUNT(*) FROM staff_shifts ss
                  JOIN staffs s ON ss.staff_id = s.staff_id
                  WHERE DATE(ss.shift_date) >= CURDATE()
                  $whereSearch";
    $countStmt = $db->prepare($countSql);
    if ($searchParam) {
        $countStmt->bindValue(':search', $searchParam); $countStmt->bindValue(':search_last', $searchParam); $countStmt->bindValue(':search_full', $searchParam);
    }
    $countStmt->execute();
    $totalRecords = (int) $countStmt->fetchColumn();

    $totalPages  = max(1, (int) ceil($totalRecords / $perPage));
    if ($currentPage > $totalPages) {
        $currentPage = $totalPages;
    }
    $offset = ($currentPage - 1) * $perPage;

    // ── Fetch page ──────────────────────────────────────────────────────────
    $sql = "SELECT ss.*, s.first_name, s.last_name
            FROM staff_shifts ss
            JOIN staffs s ON ss.staff_id = s.staff_id
            WHERE DATE(ss.shift_date) >= CURDATE()
            $whereSearch
            ORDER BY ss.shift_date ASC, ss.start_time ASC
            LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($sql);
    if ($searchParam) {
        $stmt->bindValue(':search', $searchParam); $stmt->bindValue(':search_last', $searchParam); $stmt->bindValue(':search_full', $searchParam);
    }
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Error retrieving schedule: ' . $e->getMessage();
}

// ── URL builder (preserves search, updates page) ───────────────────────────
function buildUrl(array $overrides = []): string
{
    global $search, $currentPage;
    $params = array_filter([
        'search' => $search !== '' ? $search : null,
        'page'   => $currentPage,
    ]);
    $params = array_merge($params, $overrides);
    // drop null / empty values
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');
    return 'viewSchedule.php' . ($params ? '?' . http_build_query($params) : '');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>View Schedule - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-dark@4/dark.css">
    <style>
        body {
            background-color: #0d0d0d;
            color: #f0f0f0;
        }

        .table {
            background-color: #1a1a1a;
            color: #f0f0f0;
            margin-bottom: 0;
        }

        .table th {
            color: #d4af37;
            background-color: #262626;
            border-bottom: 2px solid #404040;
        }

        .table td {
            border-bottom: 1px solid #333;
            vertical-align: middle;
        }

        .card {
            background-color: #1a1a1a;
            border-color: #404040;
            overflow: hidden;
        }

        .text-gold {
            color: #d4af37;
        }

        .btn-delete {
            border-color: #dc3545;
            color: #dc3545;
        }

        .btn-delete:hover {
            background-color: #dc3545;
            color: #fff;
        }

        /* Search input */
        .search-wrapper .form-control {
            background: #2d2d2d;
            border-color: #444;
            color: #f0f0f0;
            border-radius: 20px 0 0 20px;
        }

        .search-wrapper .form-control::placeholder {
            color: #777;
        }

        .search-wrapper .form-control:focus {
            background: #333;
            border-color: #d4af37;
            color: #fff;
            box-shadow: 0 0 0 .2rem rgba(212, 175, 55, .2);
        }

        .search-wrapper .btn-search {
            background: #f06a3a;
            border-color: #f06a3a;
            color: #fff;
            border-radius: 0 20px 20px 0;
            font-weight: 600;
        }

        .search-wrapper .btn-search:hover {
            background: #e0592e;
            border-color: #e0592e;
            color: #fff;
        }

        .search-wrapper .btn-clear {
            background: #2d2d2d;
            border-color: #555;
            color: #aaa;
            border-radius: 20px;
            font-size: .8rem;
        }

        .search-wrapper .btn-clear:hover {
            background: #3a3a3a;
            color: #fff;
        }

        /* Pagination */
        .pagination .page-link {
            background-color: #2d2d2d;
            border-color: #444;
            color: #ccc;
        }

        .pagination .page-link:hover {
            background-color: #3a3a3a;
            color: #fff;
        }

        .pagination .page-item.active .page-link {
            background-color: #d4af37;
            border-color: #d4af37;
            color: #000;
            font-weight: 600;
        }

        .pagination .page-item.disabled .page-link {
            background-color: #1a1a1a;
            border-color: #333;
            color: #555;
        }

        /* Schedule modal — mockup inspired styling */
        .schedule-modal-dialog {
            max-width: 610px;
        }

        .schedule-modal {
            background: #f3f2f1;
            border: 1px solid rgba(20, 61, 74, .10);
            border-radius: 8px;
            box-shadow: 0 12px 28px rgba(17, 24, 27, 0.12);
            color: #18333b;
            font-family: 'Montserrat', sans-serif;
            overflow: hidden;
            position: relative;
        }

        .schedule-modal .modal-header {
            border: 0;
            justify-content: center;
            padding: 28px 36px 18px;
            position: relative;
        }

        .schedule-modal .modal-header>div {
            text-align: center;
        }

        .schedule-modal .modal-title {
            align-items: center;
            color: #143d4a;
            display: flex;
            gap: 12px;
            justify-content: center;
            font-family: 'Playfair Display', serif;
            font-size: clamp(1.5rem, 3vw, 2rem);
            font-weight: 700;
            letter-spacing: 0;
            margin: 0;
        }

        .schedule-modal .modal-subtitle {
            color: #647779;
            font-size: 0.9rem;
            margin: 12px 0 0;
        }

        .schedule-modal .title-icon {
            align-items: center;
            background: #d9b24d;
            border-radius: 50%;
            color: #fff;
            display: inline-flex;
            font-size: 1.1rem;
            height: 34px;
            justify-content: center;
            min-width: 34px;
        }

        .schedule-modal .btn-close {
            background: transparent;
            border: 0;
            box-shadow: none;
            color: #18333b;
            filter: none;
            opacity: 0.8;
            position: absolute;
            right: 16px;
            top: 16px;
        }

        .schedule-modal .btn-close:hover {
            opacity: 1;
        }

        .schedule-modal .modal-body {
            padding: 16px 36px 10px;
        }

        .schedule-modal .form-label {
            color: #617477;
            display: block;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: 1.4px;
            margin-bottom: 10px;
            text-transform: uppercase;
        }

        .schedule-modal .form-label .optional {
            color: #8b9ca1;
            font-size: .92em;
            font-weight: 500;
            text-transform: none;
        }

        .schedule-modal .form-control,
        .schedule-modal .form-select {
            background: #f8f8f8;
            border: 1px solid rgba(20, 61, 74, .18);
            border-radius: 6px;
            color: #18333b;
            font-size: 1.05rem;
            min-height: 56px;
            padding: .78rem 1rem;
            transition: border-color .2s ease, box-shadow .2s ease;
        }

        .schedule-modal .form-select {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: linear-gradient(45deg, transparent 50%, #4a5d63 50%), linear-gradient(135deg, #4a5d63 50%, transparent 50%);
            background-position: calc(100% - 18px) calc(50% - 2px), calc(100% - 12px) calc(50% - 2px);
            background-size: 6px 6px, 6px 6px;
            background-repeat: no-repeat;
            padding-right: 2.7rem;
        }

        .schedule-modal .form-control[type="text"],
        .schedule-modal .form-control[type="date"],
        .schedule-modal .form-control[type="time"] {
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
        }

        .schedule-modal .field-with-icon {
            position: relative;
        }

        .schedule-modal .field-with-icon .form-control {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%234a5d63' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4' width='18' height='18' rx='2' ry='2'/%3E%3Cline x1='16' y1='2' x2='16' y2='6'/%3E%3Cline x1='8' y1='2' x2='8' y2='6'/%3E%3Cline x1='3' y1='10' x2='21' y2='10'/%3E%3C/svg%3E");
            background-position: right 1rem center;
            background-repeat: no-repeat;
            background-size: 18px 18px;
            padding-right: 2.8rem;
        }

        .schedule-modal .time-input {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='22' height='22' viewBox='0 0 24 24' fill='none' stroke='%234a5d63' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='12' cy='12' r='9'/%3E%3Cpath d='M12 7v5l3 2'/%3E%3C/svg%3E");
            background-position: right 1rem center;
            background-repeat: no-repeat;
            background-size: 18px 18px;
            padding-right: 2.8rem;
        }

        .schedule-modal textarea.form-control {
            min-height: 112px;
            resize: vertical;
        }

        .schedule-modal .form-control::placeholder {
            color: #9aa0a2;
        }

        .schedule-modal .form-control:focus,
        .schedule-modal .form-select:focus {
            background-color: #fff;
            border-color: #5daeb1;
            box-shadow: 0 0 0 3px rgba(93, 174, 177, .15);
            color: #18333b;
        }

        .schedule-modal .modal-footer {
            border: 0;
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding: 18px 36px 28px;
        }

        .schedule-modal .btn {
            border-radius: 6px;
            font-weight: 700;
            min-height: 52px;
            padding: .7rem 1.2rem;
        }

        .schedule-modal .btn-secondary {
            background: #7b7d7f;
            border-color: #7b7d7f;
            color: #fff;
            min-width: 120px;
        }

        .schedule-modal .btn-secondary:hover {
            background: #686b6d;
            border-color: #686b6d;
            color: #fff;
        }

        .schedule-modal .btn-schedule {
            background: linear-gradient(180deg, #f06a3a, #e65d2f);
            border: 0;
            box-shadow: none;
            color: #fff;
            min-width: 200px;
        }

        .schedule-modal .btn-schedule:hover {
            background: linear-gradient(180deg, #e55e2d, #d95027);
            color: #fff;
        }

        @media (max-width: 575px) {
            .schedule-modal .modal-header {
                padding: 24px 20px 12px;
            }

            .schedule-modal .modal-body {
                padding: 14px 20px 8px;
            }

            .schedule-modal .modal-footer {
                padding: 14px 20px 20px;
            }

            .schedule-modal .modal-title {
                font-size: 1.9rem;
            }

            .schedule-modal .modal-footer .btn {
                width: auto;
            }
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="../../adminSide/admin-dashboard.php">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>
    </nav>

    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
            <h1 class="mb-0">
                <i class="fas fa-calendar-alt text-gold me-2"></i>Upcoming Staff Schedule
            </h1>
            <button type="button" class="btn btn-outline-warning" data-bs-toggle="modal" data-bs-target="#scheduleShiftModal">
                <i class="fas fa-plus me-2"></i>Schedule Shift
            </button>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- ── Search Bar ── -->
        <form method="GET" action="viewSchedule.php" class="mb-3">
            <div class="d-flex align-items-center gap-2 flex-wrap search-wrapper">
                <div class="input-group" style="max-width:380px;">
                    <input type="text"
                        name="search"
                        class="form-control"
                        placeholder="Search by staff name…"
                        value="<?php echo htmlspecialchars($search); ?>"
                        autocomplete="off">
                    <button type="submit" class="btn btn-search">
                        <i class="fas fa-search me-1"></i>Search
                    </button>
                </div>
                <?php if ($search !== ''): ?>
                    <a href="viewSchedule.php" class="btn btn-clear">
                        <i class="fas fa-times me-1"></i>Clear
                    </a>
                <?php endif; ?>
                <span class="ms-auto text-muted" style="font-size:.82rem;">
                    <?php echo number_format($totalRecords); ?> shift<?php echo $totalRecords !== 1 ? 's' : ''; ?>
                    <?php if ($search !== ''): ?>
                        for <em>"<?php echo htmlspecialchars($search); ?>"</em>
                    <?php endif; ?>
                </span>
            </div>
        </form>

        <!-- ── Table ── -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover">
                        <thead>
                            <tr>
                                <th>Staff Member</th>
                                <th>Shift Date</th>
                                <th>Time</th>
                                <th>Notes</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($shifts)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-gold">
                                        <?php echo $search !== ''
                                            ? 'No shifts found for "' . htmlspecialchars($search) . '".'
                                            : 'No upcoming shifts found.'; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($shifts as $shift): ?>
                                    <?php
                                    $staffName  = trim(($shift['first_name'] ?? '') . ' ' . ($shift['last_name'] ?? ''));
                                    $shiftDate  = date('M d, Y', strtotime($shift['shift_date']));
                                    $shiftTime  = date('H:i', strtotime($shift['start_time'])) . ' - ' . date('H:i', strtotime($shift['end_time']));
                                    $shiftLabel = $staffName . ' on ' . $shiftDate . ' (' . $shiftTime . ')';
                                    ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($staffName); ?></td>
                                        <td><?php echo htmlspecialchars($shiftDate); ?></td>
                                        <td>
                                            <i class="far fa-clock text-gold me-1"></i>
                                            <?php echo htmlspecialchars($shiftTime); ?>
                                        </td>
                                        <td class="small text-muted fst-italic">
                                            <?php echo !empty($shift['notes']) ? htmlspecialchars($shift['notes']) : '-'; ?>
                                        </td>
                                        <td class="text-center">
                                            <form method="POST" class="d-inline delete-shift-form"
                                                data-shift-label="<?php echo htmlspecialchars($shiftLabel); ?>">
                                                <input type="hidden" name="action" value="delete_shift"><input type="hidden" name="csrf_token" value="<?= workforceCsrf() ?>">
                                                <input type="hidden" name="shift_id" value="<?php echo (int) $shift['shift_id']; ?>">
                                                <input type="hidden" name="redirect_page" value="<?php echo $currentPage; ?>">
                                                <input type="hidden" name="redirect_search" value="<?php echo htmlspecialchars($search); ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger btn-delete" title="Delete Shift">
                                                    <i class="fas fa-trash-alt me-1"></i>Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ── Pagination ── -->
                <?php if ($totalPages > 1): ?>
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top border-secondary">
                        <small class="text-muted">
                            Page <?php echo $currentPage; ?> of <?php echo $totalPages; ?>
                        </small>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <!-- Previous -->
                                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildUrl(['page' => $currentPage - 1]); ?>" aria-label="Previous">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>

                                <?php
                                $window = 2;
                                $pages  = [];
                                for ($p = 1; $p <= $totalPages; $p++) {
                                    if ($p === 1 || $p === $totalPages || ($p >= $currentPage - $window && $p <= $currentPage + $window)) {
                                        $pages[] = $p;
                                    }
                                }
                                $prev = null;
                                foreach ($pages as $p):
                                    if ($prev !== null && $p - $prev > 1):
                                ?>
                                        <li class="page-item disabled"><span class="page-link">…</span></li>
                                    <?php
                                    endif;
                                    $prev = $p;
                                    ?>
                                    <li class="page-item <?php echo $p === $currentPage ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo buildUrl(['page' => $p]); ?>"><?php echo $p; ?></a>
                                    </li>
                                <?php endforeach; ?>

                                <!-- Next -->
                                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildUrl(['page' => $currentPage + 1]); ?>" aria-label="Next">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <div class="modal fade" id="scheduleShiftModal" tabindex="-1" aria-labelledby="scheduleShiftModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable schedule-modal-dialog">
            <div class="modal-content schedule-modal">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="scheduleShiftModalLabel"><span class="title-icon"><i class="fas fa-calendar-plus"></i></span>Schedule Staff Shift</h5>
                        <p class="modal-subtitle">Assign a staff member to an upcoming shift.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="scheduleShiftForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="schedule_shift"><input type="hidden" name="csrf_token" value="<?= workforceCsrf() ?>">
                        <div class="mb-3">
                            <label class="form-label" for="schedule-staff-id">Staff Member</label>
                            <select class="form-select" id="schedule-staff-id" name="staff_id" required>
                                <option value="">Select staff member</option>
                                <?php foreach ($staffList as $staff): ?>
                                    <option value="<?php echo (int) $staff['staff_id']; ?>" <?php echo ((string) ($staff['staff_id'] ?? '') === (string) ($_POST['staff_id'] ?? '')) ? 'selected' : ''; ?>><?php echo htmlspecialchars(trim($staff['first_name'] . ' ' . $staff['last_name'])); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="schedule-shift-date">Shift Date</label>
                            <div class="field-with-icon">
                                <input type="text" class="form-control" id="schedule-shift-date" name="shift_date" placeholder="mm/dd/yyyy" value="<?php echo htmlspecialchars($_POST['shift_date'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="schedule-start-time">Start Time</label>
                                <div class="field-with-icon">
                                    <input type="text" class="form-control time-input" id="schedule-start-time" name="start_time" placeholder="--:-- --" value="<?php echo htmlspecialchars($_POST['start_time'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="schedule-end-time">End Time</label>
                                <div class="field-with-icon">
                                    <input type="text" class="form-control time-input" id="schedule-end-time" name="end_time" placeholder="--:-- --" value="<?php echo htmlspecialchars($_POST['end_time'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="mb-1"><label class="form-label" for="schedule-notes">Notes <span class="optional">(optional)</span></label><textarea class="form-control" id="schedule-notes" name="notes" rows="3" placeholder="Add any special notes for this shift"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-schedule"><i class="fas fa-save me-2"></i>Schedule Shift</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const swalDark = {
            background: '#1e1e1e',
            color: '#ffffff',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d'
        };

        document.querySelectorAll('.delete-shift-form').forEach(form => {
            form.addEventListener('submit', function(event) {
                event.preventDefault();
                const shiftLabel = this.dataset.shiftLabel || 'this shift';
                Swal.fire({
                    ...swalDark,
                    title: 'Delete Shift?',
                    html: `This will permanently remove <strong>${shiftLabel}</strong>. This action cannot be undone.`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, delete it',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        this.submit();
                    }
                });
            });
        });

        <?php if (!empty($success)): ?>
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: <?php echo json_encode($success); ?>,
                showConfirmButton: false,
                timer: 2600,
                timerProgressBar: true,
                background: '#1e1e1e',
                color: '#ffffff',
                iconColor: '#d4af37'
            });
        <?php endif; ?>

        const shouldOpenScheduleModal = <?php echo json_encode($showScheduleModal || (isset($_GET['create']) && $_GET['create'] === '1')); ?>;
        if (shouldOpenScheduleModal) {
            new bootstrap.Modal(document.getElementById('scheduleShiftModal')).show();
        }
    </script>
</body>

</html>
