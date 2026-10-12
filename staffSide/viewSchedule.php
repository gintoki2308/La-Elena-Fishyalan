<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../config/pagination_helper.php';

if (!isLoggedIn() || !isStaff()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();
$shifts = [];
$error = '';
$staffId = (int) ($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 0);
$today = date('Y-m-d');

try {
    $query = "SELECT * FROM staff_shifts WHERE staff_id = :staff_id AND shift_date >= :today ORDER BY shift_date ASC, start_time ASC";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':staff_id', $staffId, PDO::PARAM_INT);
    $stmt->bindValue(':today', $today);
    $stmt->execute();
    $shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Error retrieving schedule: ' . $e->getMessage();
}

$shiftsPagination = paginate_array($shifts, 10);
$shifts = $shiftsPagination['items'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Schedule - Staff Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="staff-dashboard.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="staff-page-theme.css">
    <style>
        :root {
            --schedule-bg-start: #f8f1e7;
            --schedule-bg-end: #e6f1ee;
            --schedule-bg-accent: #f5dfd5;
            --schedule-panel: rgba(216, 230, 227, 0.9);
            --schedule-panel-border: rgba(20, 61, 74, 0.14);
            --schedule-text: #143d4a;
            --schedule-muted: #667d82;
            --schedule-accent: #e56b4f;
            --schedule-accent-deep: #c9533b;
            --schedule-row-hover: rgba(226, 243, 240, 0.9);
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(135deg, var(--schedule-bg-start) 0%, var(--schedule-bg-end) 52%, var(--schedule-bg-accent) 100%);
            color: var(--schedule-text);
        }

        .schedule-topbar {
            background: rgba(255, 253, 248, 0.94);
            border-bottom: 1px solid var(--schedule-panel-border);
            box-shadow: 0 4px 20px rgba(20, 61, 74, 0.08);
        }

        .schedule-topbar .container {
            max-width: 1180px;
            margin: 0 auto;
            padding: 0 1.5rem;
        }

        .schedule-back {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--schedule-text);
            font-weight: 700;
            font-size: 1.05rem;
            text-decoration: none;
            padding: 0.9rem 0;
        }

        .schedule-back:hover {
            color: var(--schedule-accent-deep);
            text-decoration: none;
        }

        .schedule-wrapper {
            max-width: 1180px;
            margin: 0 auto;
            padding: 2.2rem 1.5rem 3rem;
        }

        .schedule-header {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            margin: 0 0 1.8rem;
            color: var(--schedule-text);
        }

        .schedule-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.2rem;
            height: 2.2rem;
            background: var(--schedule-accent);
            color: #fff;
            border-radius: 0.55rem;
            box-shadow: 0 8px 18px rgba(229, 107, 79, 0.2);
            font-size: 1.1rem;
        }

        .schedule-header h1 {
            margin: 0;
            font-size: clamp(2rem, 3vw, 3rem);
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: -0.05em;
            color: var(--schedule-text);
        }

        .schedule-card {
            background: var(--schedule-panel);
            border: 1px solid var(--schedule-panel-border);
            border-radius: 0.9rem;
            box-shadow: 0 8px 24px rgba(20, 61, 74, 0.07);
            overflow: hidden;
            width: min(100%, 1100px);
        }

        .schedule-card .table {
            margin: 0;
            background: transparent;
            border-collapse: collapse;
        }

        .schedule-card .table thead th {
            background: rgba(255, 255, 255, 0.12);
            color: var(--schedule-text);
            border-bottom: 1px solid var(--schedule-panel-border);
            font-weight: 700;
            font-size: 1rem;
            padding: 1rem 1.25rem;
            white-space: nowrap;
        }

        .schedule-card .table tbody td {
            color: var(--schedule-text);
            border-top: 1px solid var(--schedule-panel-border);
            padding: 1.05rem 1.25rem;
            vertical-align: middle;
            background: transparent;
        }

        .schedule-card .table tbody tr:hover td {
            background: var(--schedule-row-hover);
        }

        .schedule-card .table tbody .empty-row td {
            color: var(--schedule-muted);
            text-align: center;
            padding: 2.5rem 1rem;
            font-size: 1rem;
        }

        .schedule-card .table tbody td.notes-cell {
            min-width: 220px;
            word-break: break-word;
        }

        .schedule-notes {
            display: inline-block;
            max-width: 100%;
            white-space: normal;
        }

        .schedule-pagination {
            padding: 0.9rem 1rem 0;
        }

        @media (max-width: 767px) {
            .schedule-wrapper {
                padding: 1.6rem 0.85rem 2.4rem;
            }

            .schedule-header {
                gap: 0.6rem;
                margin-bottom: 1.25rem;
            }

            .schedule-icon {
                width: 2rem;
                height: 2rem;
                font-size: 0.95rem;
            }

            .schedule-header h1 {
                font-size: clamp(1.8rem, 8vw, 2.4rem);
            }

            .schedule-card {
                overflow-x: auto;
            }

            .schedule-card .table {
                min-width: 520px;
            }
        }
    </style>
</head>
<body>
    <nav class="schedule-topbar" aria-label="Back navigation">
        <div class="container-fluid">
            <a class="schedule-back" href="staff-dashboard.php"><i class="fas fa-arrow-left"></i>Back</a>
        </div>
    </nav>

    <main class="schedule-wrapper">
        <header class="schedule-header" aria-label="Upcoming shifts heading">
            <span class="schedule-icon" aria-hidden="true"><i class="fas fa-calendar-alt"></i></span>
            <h1>My Upcoming Shifts</h1>
        </header>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <section class="schedule-card" aria-label="Upcoming shifts table">
            <div class="table-responsive">
                <table class="table table-hover" aria-label="My upcoming shifts list">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Time</th>
                            <th scope="col">Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($shifts)): ?>
                            <tr class="empty-row">
                                <td colspan="3">No upcoming shifts scheduled.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($shifts as $shift): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('M d, Y', strtotime($shift['shift_date']))); ?></td>
                                    <td><?php echo date('H:i', strtotime($shift['start_time'])) . ' - ' . date('H:i', strtotime($shift['end_time'])); ?></td>
                                    <td class="notes-cell"><span class="schedule-notes"><?php echo !empty($shift['notes']) ? htmlspecialchars($shift['notes']) : '-'; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($shiftsPagination)) : ?>
                <div class="schedule-pagination"><?php echo render_pagination($shiftsPagination); ?></div>
            <?php endif; ?>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
