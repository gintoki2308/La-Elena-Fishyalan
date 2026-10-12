<?php
require_once '../config/config.php';
require_once '../config/Database.php';
require_once '../config/pagination_helper.php';
require_once '../config/reservation_helper.php';

if (!isLoggedIn() || !isStaff()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$user = getCurrentUser();
$navAvatarSrc = getProfileAvatar($user['profile_picture'] ?? '', $user['first_name'] ?? '', $user['last_name'] ?? '');

$database = new Database();
$db = $database->connect();

$success_msg = $_SESSION['reservation_success'] ?? null;
$error = $_SESSION['reservation_error'] ?? null;
unset($_SESSION['reservation_success'], $_SESSION['reservation_error']);
$csrfToken = reservationCsrfToken();

// Fetch reservations for display
$reservations = [];
try {
    $query = "SELECT r.*, m.first_name, m.last_name, m.phone
              FROM reservations r
              LEFT JOIN customer m ON r.member_id = m.member_id
              ORDER BY r.reservation_date DESC";
    $stmt = $db->query($query);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e) {
    if (!$error) $error = reservationError($e);
}

$reservationsPagination = paginate_array($reservations, 10);
$reservations = $reservationsPagination['items'];

function getResStatusColor($status) {
    return match($status) {
        'CONFIRMED' => 'info',
        'COMPLETED' => 'success',
        'CANCELLED' => 'danger',
        default     => 'warning'
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Reservations - Staff Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="staff-dashboard.css">
    <link rel="stylesheet" href="staff-page-theme.css">
    <link rel="stylesheet" href="reservations.css">
</head>
<body class="reservations-page">

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
        <div class="container-fluid">
            <button class="navbar-toggler me-2 d-lg-none" type="button" id="sidebarToggle" aria-label="Toggle sidebar" aria-controls="mainSidebar" aria-expanded="false">
                <span class="navbar-toggler-icon"></span>
            </button>
            <a class="navbar-brand" href="staff-dashboard.php"><i class="fas fa-user-tie me-2"></i>Staff Panel</a>
            <div class="ms-auto">
                <div class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <img src="<?php echo $navAvatarSrc; ?>" alt="" class="rounded-circle me-2" width="35" height="35" style="object-fit:cover;border:1.5px solid var(--gold);">
                        <span class="d-none d-sm-inline"><?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Staff'); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="logout-link dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="layout-shell">
        <div class="sidebar-overlay" id="sidebarOverlay"></div>
        <aside class="sidebar" id="mainSidebar" aria-label="Staff navigation">
            <div class="sidebar-header">
                <h5 class="text-gold mb-3"><i class="fas fa-tasks me-2"></i>Dashboard</h5>
                <button class="sidebar-close d-lg-none" type="button" aria-label="Close sidebar">&times;</button>
            </div>
            <nav class="nav flex-column">
                <a class="nav-link" href="staff-dashboard.php"><i class="fas fa-home me-2"></i>Overview</a>
                <a class="nav-link" href="onlineOrdersCrud/viewOnlineOrders.php"><i class="fas fa-shopping-cart me-2"></i>Orders</a>
                <a class="nav-link" href="viewSchedule.php"><i class="fas fa-calendar me-2"></i>My Shifts</a>
                <a class="nav-link" href="viewAttendance.php"><i class="fas fa-clock me-2"></i>Attendance</a>
                <a class="nav-link active" href="viewReservations.php"><i class="fas fa-calendar-alt me-2"></i>Reservations</a>
                <a class="nav-link" href="menuManagementCrud/viewMenuItems.php"><i class="fas fa-utensils me-2"></i>Menu Management</a>
                <a class="nav-link" href="inventoryCrud/viewInventory.php"><i class="fas fa-boxes me-2"></i>Inventory</a>
                <a class="nav-link" href="profile.php"><i class="fas fa-user me-2"></i>My Profile</a>
                <a class="nav-link" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a>
            </nav>
        </aside>

        <main class="main-content p-3 p-md-4" id="mainContent">
            <header class="reservations-header">
                <div>
                    <h1><i class="fas fa-calendar-alt me-2 text-gold" aria-hidden="true"></i>Customer Reservations</h1>
                    <p class="text-secondary mb-0">View and manage table reservations made by customers.</p>
                </div>
                <?php if (empty($error)): ?>
                    <span class="reservation-count"><?php echo (int) $reservationsPagination['total_items']; ?> reservations</span>
                <?php endif; ?>
            </header>

            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (empty($reservations) && empty($error)): ?>
                <div class="card reservation-empty">
                    <i class="far fa-calendar-check" aria-hidden="true"></i>
                    <h2>No reservations found</h2>
                    <p class="text-secondary mb-0">Customer table bookings will appear here once a reservation is made.</p>
                </div>
            <?php elseif (!empty($reservations)): ?>
            <div class="desktop-table">
                <div class="card reservation-table-card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th class="ps-3">#</th>
                                        <th>Customer</th>
                                        <th>Date &amp; Time</th>
                                        <th>Party</th>
                                        <th>Notes</th>
                                        <th>Status</th>
                                        <th class="pe-3">Update</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($reservations as $r): ?>
                                    <tr>
                                        <td class="ps-3"><strong class="text-gold">#<?php echo $r['reservation_id']; ?></strong></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars(($r['first_name'] ?? 'Unknown') . ' ' . ($r['last_name'] ?? '')); ?></strong><br>
                                            <small class="text-secondary"><i class="fas fa-phone me-1"></i><?php echo htmlspecialchars($r['phone'] ?? 'N/A'); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo date('M d, Y', strtotime($r['reservation_date'])); ?></strong><br>
                                            <small class="text-secondary"><?php echo date('h:i A', strtotime($r['reservation_date'])); ?></small>
                                        </td>
                                        <td><span class="badge bg-secondary"><?php echo $r['party_size']; ?> guests</span></td>
                                        <td class="reservation-notes text-secondary"><?php echo empty($r['special_requests']) ? '<em>None</em>' : htmlspecialchars($r['special_requests']); ?></td>
                                        <td><span class="badge bg-<?php echo getResStatusColor($r['status'] ?? 'PENDING'); ?> rounded-pill px-3 py-2"><?php echo htmlspecialchars($r['status'] ?? 'PENDING'); ?></span></td>
                                        <td class="pe-3">
                                            <form action="../adminSide/reservationsCrud/manageReservation.php" method="POST" class="m-0">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="reservation_id" value="<?php echo $r['reservation_id']; ?>">
                                                <select name="new_status" class="form-select form-select-sm" aria-label="Update status for reservation #<?php echo (int) $r['reservation_id']; ?>" onchange="this.form.submit()">
                                                    <option value="">Update…</option>
                                                    <option value="PENDING">Pending</option>
                                                    <option value="CONFIRMED">Confirm</option>
                                                    <option value="COMPLETED">Complete</option>
                                                    <option value="CANCELLED">Cancel</option>
                                                </select>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mobile-cards">
                <?php foreach ($reservations as $r): ?>
                <div class="res-card">
                    <div class="res-card-header">
                        <div>
                            <div class="res-id">#<?php echo $r['reservation_id']; ?></div>
                            <div class="res-customer-name"><?php echo htmlspecialchars(($r['first_name'] ?? 'Unknown') . ' ' . ($r['last_name'] ?? '')); ?></div>
                        </div>
                        <span class="badge bg-<?php echo getResStatusColor($r['status'] ?? 'PENDING'); ?> rounded-pill px-3 py-2 flex-shrink-0"><?php echo htmlspecialchars($r['status'] ?? 'PENDING'); ?></span>
                    </div>
                    <div class="res-meta">
                        <div class="res-meta-item"><span class="res-meta-label">Date</span><span class="res-meta-value"><?php echo date('M d, Y', strtotime($r['reservation_date'])); ?></span></div>
                        <div class="res-meta-item"><span class="res-meta-label">Time</span><span class="res-meta-value"><?php echo date('h:i A', strtotime($r['reservation_date'])); ?></span></div>
                        <div class="res-meta-item"><span class="res-meta-label">Party</span><span class="res-meta-value"><?php echo $r['party_size']; ?> guests</span></div>
                        <div class="res-meta-item"><span class="res-meta-label">Phone</span><span class="res-meta-value"><?php echo htmlspecialchars($r['phone'] ?? 'N/A'); ?></span></div>
                    </div>
                    <?php if (!empty($r['special_requests'])): ?>
                        <div class="res-notes"><?php echo htmlspecialchars($r['special_requests']); ?></div>
                    <?php endif; ?>
                    <form action="../adminSide/reservationsCrud/manageReservation.php" method="POST" class="res-update-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="reservation_id" value="<?php echo $r['reservation_id']; ?>">
                        <select name="new_status" class="form-select form-select-sm" aria-label="Reservation status"><option value="">Update status…</option><option value="PENDING">Pending</option><option value="CONFIRMED">Confirm</option><option value="COMPLETED">Complete</option><option value="CANCELLED">Cancel</option></select>
                        <button type="submit" class="btn btn-gold btn-sm"><i class="fas fa-sync-alt me-1"></i>Update</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php echo render_pagination($reservationsPagination); ?>
            <?php endif; ?>
        </main>
    </div>

    <nav class="bottom-nav">
        <div class="bottom-nav-inner">
            <a class="bottom-nav-link" href="staff-dashboard.php"><i class="fas fa-home"></i>Home</a>
            <a class="bottom-nav-link" href="onlineOrdersCrud/viewOnlineOrders.php"><i class="fas fa-shopping-cart"></i>Orders</a>
            <a class="bottom-nav-link" href="viewSchedule.php"><i class="fas fa-calendar"></i>Shifts</a>
            <a class="bottom-nav-link" href="viewAttendance.php"><i class="fas fa-clock"></i>Attendance</a>
            <a class="bottom-nav-link active" href="viewReservations.php"><i class="fas fa-calendar-alt"></i>Reservations</a>
            <a class="bottom-nav-link" href="menuManagementCrud/viewMenuItems.php"><i class="fas fa-utensils"></i>Menu</a>
            <a class="bottom-nav-link" href="inventoryCrud/viewInventory.php"><i class="fas fa-boxes"></i>Inventory</a>
            <a class="bottom-nav-link" href="profile.php"><i class="fas fa-user"></i>Profile</a>
        </div>
    </nav>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="staff-sidebar.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.1/dist/sweetalert2.all.min.js"></script>
</body>
</html>
