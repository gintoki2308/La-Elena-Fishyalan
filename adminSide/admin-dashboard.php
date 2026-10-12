<?php
require_once '../config/config.php';
require_once '../config/Database.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$user = getCurrentUser();
$database = new Database();
$db = $database->connect();

$adminQuery = "SELECT display_name, profile_picture FROM admins WHERE admin_id = :id";
$adminStmt = $db->prepare($adminQuery);
$adminStmt->execute([':id' => $_SESSION['user_id']]);
$currentAdmin = $adminStmt->fetch(PDO::FETCH_ASSOC);

$navbarName   = !empty($currentAdmin['display_name']) ? $currentAdmin['display_name'] : ($user['first_name'] ?? 'Admin');
$navbarAvatar = getProfileAvatar($currentAdmin['profile_picture'] ?? '', $user['first_name'] ?? '', $user['last_name'] ?? '');

$totalOrders = $totalRevenue = $netProfit = 0;
$totalStaff  = $totalCustomers = $lowStock = $lowStockMenu = $totalMenuItems = 0;
$error = '';

try {
    $supportsCustomerVerification = dbTableHasColumn($db, 'customer', 'is_verified');
    $totalStaff    = $db->query("SELECT COUNT(*) FROM staffs")->fetchColumn();
    $customerCountSql = $supportsCustomerVerification
        ? "SELECT COUNT(*) FROM customer WHERE account_status='ACTIVE' AND is_verified = 1"
        : "SELECT COUNT(*) FROM customer WHERE account_status='ACTIVE'";
    $totalCustomers = $db->query($customerCountSql)->fetchColumn();
    $totalMenuItems = $db->query("SELECT COUNT(*) FROM menu_items")->fetchColumn();
    $lowStock      = $db->query("SELECT COUNT(*) FROM ingredients WHERE current_stock <= reorder_level")->fetchColumn();
    $lowStockMenu  = $db->query("SELECT COUNT(*) FROM menu_items WHERE stock_quantity <= reorder_level")->fetchColumn();
} catch (Exception $e) {
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="admin-dashboard.css?v=<?php echo filemtime(__DIR__ . '/admin-dashboard.css'); ?>">
</head>

<body>

    <!-- ── NAVBAR ─────────────────────────────────────────────── -->
    <nav class="navbar navbar-dark bg-dark sticky-top d-flex align-items-center">
        <button class="navbar-toggler me-2 d-md-none" id="sidebarToggleBtn" type="button" aria-label="Toggle sidebar">
            <span class="navbar-toggler-icon"></span>
        </button>
        <a class="navbar-brand me-auto" href="#">
            Admin Panel
        </a>
        <div class="nav-item dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 pe-2"
                href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <img src="<?php echo htmlspecialchars($navbarAvatar); ?>"
                    alt="Avatar" class="rounded-circle"
                    width="32" height="32"
                    style="object-fit:cover;border:1px solid #d4af37;">
                <span class="d-none d-sm-inline"><?php echo htmlspecialchars($navbarName); ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user-tie me-2"></i>Profile</a></li>
                <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                <li>
                    <hr class="dropdown-divider">
                </li>
                <li><a class="logout-link dropdown-item text-danger" href="../auth/logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
            </ul>
        </div>
    </nav>

    <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

    <div class="layout-wrap">
        <aside class="sidebar" id="mainSidebar">
            <div class="sidebar-header">
                <h6 class="text-gold mb-0"><i class="fas fa-chart-line me-2"></i>Dashboard</h6>
            </div>
            <nav class="nav flex-column mt-2">
                <a class="nav-link active" href="#overview"><i class="fas fa-home"></i>Overview</a>

                <a class="nav-link d-flex align-items-center" data-bs-toggle="collapse" href="#inventoryCollapse" role="button" aria-expanded="false">
                    <i class="fas fa-boxes"></i><span>Inventory &amp; Supply</span><i class="fas fa-chevron-down transition-icon ms-auto"></i>
                </a>
                <div class="collapse" id="inventoryCollapse">
                    <nav class="nav flex-column sub-nav">
                        <a class="nav-link" href="inventoryCrud/viewInventory.php">Ingredient Inventory</a>
                        <a class="nav-link" href="inventoryCrud/viewSuppliers.php">Suppliers</a>
                    </nav>
                </div>

                <a class="nav-link d-flex align-items-center" data-bs-toggle="collapse" href="#employeeCollapse" role="button" aria-expanded="false">
                    <i class="fas fa-users"></i><span>Employee Management</span><i class="fas fa-chevron-down transition-icon ms-auto"></i>
                </a>
                <div class="collapse" id="employeeCollapse">
                    <nav class="nav flex-column sub-nav">
                        <a class="nav-link" href="employeeCrud/viewStaffRecords.php">All Staff Records</a>
                        <a class="nav-link" href="employeeCrud/viewSchedule.php">View Schedule</a>
                        <a class="nav-link" href="employeeCrud/viewAttendance.php">View Attendance</a>
                        <a class="nav-link" href="employeeCrud/viewPayroll.php">Staff Payroll</a>
                    </nav>
                </div>


                <a class="nav-link d-flex align-items-center" data-bs-toggle="collapse" href="#financialCollapse" role="button" aria-expanded="false">
                    <i class="fas fa-chart-bar"></i><span>Financial</span><i class="fas fa-chevron-down transition-icon ms-auto"></i>
                </a>
                <div class="collapse" id="financialCollapse">
                    <nav class="nav flex-column sub-nav">
                        <a class="nav-link" href="financialCrud/viewExpenses.php">View Expenses</a>
                        <a class="nav-link" href="financialCrud/financialReport.php">Financial Report</a>
                    </nav>
                </div>

                <a class="nav-link d-flex align-items-center" data-bs-toggle="collapse" href="#customerCollapse" role="button" aria-expanded="false">
                    <i class="fas fa-heart"></i><span>Customer Experience</span><i class="fas fa-chevron-down transition-icon ms-auto"></i>
                </a>
                <div class="collapse" id="customerCollapse">
                    <nav class="nav flex-column sub-nav">
                        <a class="nav-link" href="customerExperienceCrud/viewFeedback.php">View Feedback</a>
                    </nav>
                </div>

                <a class="nav-link d-flex align-items-center" href="reservationsCrud/viewReservations.php">
                    <i class="fas fa-calendar-alt"></i><span>View Reservations</span>
                </a>

                <a class="nav-link d-flex align-items-center" data-bs-toggle="collapse" href="#ordersCollapse" role="button" aria-expanded="false">
                    <i class="fas fa-shopping-cart"></i><span>Online Orders</span><i class="fas fa-chevron-down transition-icon ms-auto"></i>
                </a>
                <div class="collapse" id="ordersCollapse">
                    <nav class="nav flex-column sub-nav">
                        <a class="nav-link" href="onlineOrdersCrud/viewOnlineOrders.php">View Orders</a>
                    </nav>
                </div>

                <a class="nav-link d-flex align-items-center" data-bs-toggle="collapse" href="#menuCollapse" role="button" aria-expanded="false">
                    <i class="fas fa-utensils"></i><span>Menu Management</span><i class="fas fa-chevron-down transition-icon ms-auto"></i>
                </a>
                <div class="collapse" id="menuCollapse">
                    <nav class="nav flex-column sub-nav">
                        <a class="nav-link" href="menuManagementCrud/viewMenuItems.php">View Menu Items</a>
                    </nav>
                </div>

                <a class="nav-link d-flex align-items-center" data-bs-toggle="collapse" href="#featuresCollapse" role="button" aria-expanded="false">
                    <i class="fas fa-star"></i><span>Promotions</span><i class="fas fa-chevron-down transition-icon ms-auto"></i>
                </a>
                <div class="collapse" id="featuresCollapse">
                    <nav class="nav flex-column sub-nav">
                        <a class="nav-link" href="modernFeaturesCrud/viewPromotion.php">View Promotions</a>
                    </nav>
                </div>

                <a class="nav-link d-flex align-items-center" data-bs-toggle="collapse" href="#complianceCollapse" role="button" aria-expanded="false">
                    <i class="fas fa-shield-alt"></i><span>Compliance</span><i class="fas fa-chevron-down transition-icon ms-auto"></i>
                </a>
                <div class="collapse" id="complianceCollapse">
                    <nav class="nav flex-column sub-nav">
                        <a class="nav-link" href="complianceCrud/viewAuditLogs.php">Audit Logs</a>
                    </nav>
                </div>

                <a class="nav-link" href="about.php"><i class="fas fa-code"></i>About</a>

                <?php if (canManageBackOffice()): ?>
                    <div style="border-top:1px solid var(--border);margin:10px 0;"></div>
                    <a class="nav-link d-flex align-items-center" data-bs-toggle="collapse" href="#userMgmtCollapse" role="button" aria-expanded="false">
                        <i class="fas fa-user-shield" style="color:#f1c40f;"></i>
                        <span class="text-warning">Admin Tools</span>
                        <i class="fas fa-chevron-down transition-icon ms-auto"></i>
                    </a>
                    <div class="collapse" id="userMgmtCollapse">
                        <nav class="nav flex-column sub-nav">
                            <a class="nav-link text-warning" href="userManagement/viewUsers.php">
                                <i class="fas fa-users-cog"></i><span>User Management</span>
                            </a>
                        </nav>
                    </div>
                <?php endif; ?>
            </nav>
        </aside>

        <!-- ── MAIN CONTENT ──────────────────────────────────── -->
        <main class="main-content">

            <div class="dashboard-header">
                <h1>Admin Dashboard</h1>
                <p class="text-gold">Welcome back, <?php echo htmlspecialchars($user['first_name'] ?? 'Admin'); ?>!</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stat Cards -->
            <div class="row row-cols-2 row-cols-sm-2 row-cols-lg-3 row-cols-xl-5 g-3 mb-4">
                <div class="col">
                    <div class="stat-card h-100">
                        <div class="stat-icon orders-icon"><i class="fas fa-shopping-bag"></i></div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo number_format($totalOrders); ?></div>
                            <div class="stat-label">Total Orders</div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-card h-100">
                        <div class="stat-icon revenue-icon"><i class="fas fa-dollar-sign"></i></div>
                        <div class="stat-info">
                            <div class="stat-value">&#8369;<?php echo number_format($totalRevenue, 2); ?></div>
                            <div class="stat-label">Total Revenue</div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-card h-100">
                        <div class="stat-icon" style="color:<?php echo $netProfit >= 0 ? '#2ecc71' : '#ff6b6b'; ?>;background:<?php echo $netProfit >= 0 ? 'rgba(46,204,113,.2)' : 'rgba(255,107,107,.2)'; ?>">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <div class="stat-info">
                            <div class="stat-value <?php echo $netProfit >= 0 ? 'text-success-custom' : 'text-danger-custom'; ?>">
                                &#8369;<?php echo number_format($netProfit, 2); ?>
                            </div>
                            <div class="stat-label">Net Profit</div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-card h-100">
                        <div class="stat-icon staff-icon"><i class="fas fa-users"></i></div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo number_format($totalStaff); ?></div>
                            <div class="stat-label">Total Staff</div>
                        </div>
                    </div>
                </div>
                <div class="col">
                    <div class="stat-card h-100">
                        <div class="stat-icon customers-icon"><i class="fas fa-user-friends"></i></div>
                        <div class="stat-info">
                            <div class="stat-value"><?php echo number_format($totalCustomers); ?></div>
                            <div class="stat-label">Total Customers</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alert + Quick Actions -->
            <div class="row g-4 mb-4">

                <!-- ── LOW STOCK ALERTS (RESPONSIVE) ───────────────────── -->
                <div class="col-12 col-lg-6">
                    <div class="alert-card h-100">
                        <h5 class="mb-4">
                            <i class="fas fa-exclamation-triangle text-warning me-2"></i>Low Stock Alerts
                        </h5>

                        <!-- Responsive grid for ingredients and menu items -->
                        <div class="row g-3 mb-3">
                            <!-- Ingredients stat -->
                            <div class="col-12 col-sm-6">
                                <div class="stock-summary d-flex align-items-center gap-3 p-3 rounded-3">
                                    <div class="stock-summary-icon">
                                        <i class="fas fa-boxes"></i>
                                    </div>
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-size:1.8rem;font-weight:700;line-height:1;
                                                color:<?php echo $lowStock > 0 ? '#f1c40f' : '#2ecc71'; ?>;">
                                            <?php echo $lowStock; ?>
                                        </div>
                                        <div class="stock-summary-label">
                                            Low Stock Ingredients
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Menu Items stat -->
                            <div class="col-12 col-sm-6">
                                <div class="stock-summary d-flex align-items-center gap-3 p-3 rounded-3">
                                    <div class="stock-summary-icon">
                                        <i class="fas fa-utensils"></i>
                                    </div>
                                    <div style="flex:1;min-width:0;">
                                        <div style="font-size:1.8rem;font-weight:700;line-height:1;
                                                color:<?php echo $lowStockMenu > 0 ? '#f1c40f' : '#2ecc71'; ?>;">
                                            <?php echo $lowStockMenu; ?>
                                        </div>
                                        <div class="stock-summary-label">
                                            Menu Items
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Status message -->
                        <?php if ($lowStock == 0 && $lowStockMenu == 0): ?>
                            <div class="d-flex align-items-center gap-2 mb-3 px-1"
                                style="color:#2ecc71;font-size:0.82rem;flex-wrap:wrap;">
                                <i class="fas fa-check-circle" style="flex-shrink:0;"></i>
                                <span>All stock levels are healthy — no action needed.</span>
                            </div>
                        <?php else: ?>
                            <div class="d-flex align-items-center gap-2 mb-3 px-1"
                                style="color:#f1c40f;font-size:0.82rem;flex-wrap:wrap;">
                                <i class="fas fa-exclamation-circle" style="flex-shrink:0;"></i>
                                <span><?php echo ((int)$lowStock + (int)$lowStockMenu); ?> item(s) need restocking.</span>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex gap-2 flex-wrap">
                            <a href="inventoryCrud/viewInventory.php" class="btn btn-sm btn-outline-warning flex-grow-1">
                                <i class="fas fa-boxes me-1"></i><span class="d-none d-sm-inline">Ingredients</span><span class="d-sm-none">Ing.</span>
                            </a>
                            <a href="menuManagementCrud/viewMenuItems.php" class="btn btn-sm btn-outline-warning flex-grow-1">
                                <i class="fas fa-utensils me-1"></i><span class="d-none d-sm-inline">Menu Items</span><span class="d-sm-none">Menu</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- ── QUICK ACTIONS ──────────────────────────────── -->
                <div class="col-12 col-lg-6">
                    <div class="alert-card h-100">
                        <h5 class="mb-3"><i class="fas fa-tasks text-info me-2"></i>Quick Actions</h5>
                        <div class="d-flex flex-column gap-2">
                            <a href="employeeCrud/viewSchedule.php?create=1" class="btn btn-sm btn-outline-info text-start">
                                <i class="fas fa-calendar me-2"></i>Schedule Staff Shift
                            </a>
                        </div>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.1/dist/sweetalert2.all.min.js"></script>
    <script>
        const sidebarToggleBtn = document.getElementById('sidebarToggleBtn');
        const mainSidebar = document.getElementById('mainSidebar');
        const backdrop = document.getElementById('sidebarBackdrop');

        function openSidebar() {
            mainSidebar.classList.add('show');
            backdrop.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeSidebar() {
            mainSidebar.classList.remove('show');
            backdrop.classList.remove('show');
            document.body.style.overflow = '';
        }

        if (sidebarToggleBtn) sidebarToggleBtn.addEventListener('click', e => {
            e.stopPropagation();
            mainSidebar.classList.contains('show') ? closeSidebar() : openSidebar();
        });
        backdrop.addEventListener('click', closeSidebar);
        mainSidebar.querySelectorAll('a.nav-link:not([data-bs-toggle])').forEach(link => {
            link.addEventListener('click', () => {
                if (window.innerWidth < 768) closeSidebar();
            });
        });

        document.addEventListener('click', function(e) {
            const link = e.target.closest('.logout-link');
            if (!link) return;
            e.preventDefault();
            const href = link.href || '../auth/login.php';
            Swal.fire({
                title: 'Ready to leave?',
                text: 'You will be logged out of your session.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d4af37',
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
