<?php
require_once '../config/config.php';
require_once '../config/Database.php';

if (!isLoggedIn() || !isCustomer()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$user = getCurrentUser();
$navAvatarSrc = getProfileAvatar($user['profile_picture'] ?? '', $user['first_name'] ?? '', $user['last_name'] ?? '');
$database = new Database();
$db = $database->connect();

$error = null;
$reservationSuccess = $_SESSION['reservation_success'] ?? null;
unset($_SESSION['reservation_success']);
$totalOrders = $totalSpent = $pendingOrders = $favorite_count = 0;
$recentOrders = $activePromotions = [];

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
$cart_count = array_sum($_SESSION['cart']);

try {
    $s = $db->prepare("SELECT COUNT(*) FROM online_orders WHERE member_id=:id");
    $s->execute([':id' => $_SESSION['user_id']]); $totalOrders = $s->fetchColumn();

    $s = $db->prepare("SELECT COALESCE(SUM(total_amount),0) FROM online_orders WHERE member_id=:id AND status IN ('DELIVERED','COMPLETED')");
    $s->execute([':id' => $_SESSION['user_id']]); $totalSpent = $s->fetchColumn();

    $s = $db->prepare("SELECT COUNT(*) FROM online_orders WHERE member_id=:id AND status IN ('PENDING','CONFIRMED','PREPARING','READY','OUT_FOR_DELIVERY')");
    $s->execute([':id' => $_SESSION['user_id']]); $pendingOrders = $s->fetchColumn();

    $s = $db->prepare("SELECT * FROM online_orders WHERE member_id=:id ORDER BY CASE status WHEN 'PENDING' THEN 1 WHEN 'CONFIRMED' THEN 2 WHEN 'PREPARING' THEN 3 WHEN 'READY' THEN 4 WHEN 'OUT_FOR_DELIVERY' THEN 5 WHEN 'DELIVERED' THEN 6 WHEN 'COMPLETED' THEN 7 WHEN 'CANCELLED' THEN 8 ELSE 9 END, order_date DESC LIMIT 5");
    $s->execute([':id' => $_SESSION['user_id']]); $recentOrders = $s->fetchAll(PDO::FETCH_ASSOC);

    $s = $db->prepare("SELECT p.* FROM promotions p
        WHERE p.is_active=1 AND p.start_date<=CURDATE() AND p.end_date>=CURDATE()
        AND (p.is_single_use=0 OR NOT EXISTS (
            SELECT 1 FROM online_orders o WHERE o.promo_code=p.promotion_name AND o.member_id=:member_id
        )) ORDER BY p.created_at DESC LIMIT 4");
    $s->bindParam(':member_id', $_SESSION['user_id']);
    $s->execute(); $activePromotions = $s->fetchAll(PDO::FETCH_ASSOC);

    $s = $db->prepare("SELECT COUNT(*) FROM favorites WHERE member_id=:id");
    $s->execute([':id' => $_SESSION['user_id']]); $favorite_count = (int) $s->fetchColumn();

} catch (Exception $e) { $error = $e->getMessage(); }

function orderStatusColor($s) {
    return ['PENDING'=>'warning','CONFIRMED'=>'info','PREPARING'=>'primary',
            'READY'=>'success','OUT_FOR_DELIVERY'=>'info','DELIVERED'=>'success','CANCELLED'=>'danger'][$s] ?? 'secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard — La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="customer-dashboard.css">
    <link rel="stylesheet" href="customer-theme.css">
    <link rel="stylesheet" href="dashboard-home.css">
</head>
<body class="customer-unified-layout customer-dashboard-page">

<?php
$customerNavPage = 'customer-dashboard.php';
require 'customer-navbar.php';
?>

<!-- ── NAVBAR ──────────────────────────────────────────────────── -->
<nav class="navbar navbar-dark bg-dark sticky-top">
    <div class="container-fluid px-3">

        <!-- Sidebar toggle (mobile only) -->
        <button class="btn btn-link text-light p-0 me-2 d-md-none" id="sidebarToggleBtn" aria-label="Open menu" aria-controls="mainSidebar" aria-expanded="false">
            <i class="fas fa-bars fa-lg"></i>
        </button>

        <!-- Brand -->
        <a class="navbar-brand me-auto" href="#">
            <i class="fas fa-utensils me-2"></i>La Elena Fishyalan
        </a>

        <!-- Avatar + dropdown — always visible, inline -->
        <div class="dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2 py-0"
               href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <img src="<?php echo htmlspecialchars($navAvatarSrc); ?>"
                     alt="avatar" class="rounded-circle"
                     width="32" height="32" style="object-fit:cover;border:1.5px solid #d4af37;">
                <span class="d-none d-sm-inline text-light" style="font-size:0.9rem;">
                    <?php echo htmlspecialchars($user['first_name'] ?? 'Customer'); ?>
                </span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user me-2"></i>My Profile</a></li>
                <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Account Settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="logout-link dropdown-item text-danger" href="../auth/logout.php">
                    <i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</nav>

<!-- ── SIDEBAR BACKDROP (mobile) ───────────────────────────────── -->
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- ── LAYOUT ──────────────────────────────────────────────────── -->
<div class="layout-wrap">

    <!-- Sidebar -->
    <aside class="sidebar" id="mainSidebar">
        <!-- Close button (mobile) -->
        <button class="sidebar-close d-md-none" id="sidebarCloseBtn" aria-label="Close menu">
            <i class="fas fa-times"></i>
        </button>

        <div class="sidebar-header">
            <span class="text-gold fw-bold" style="font-family:'Playfair Display',serif;">
                <i class="fas fa-home me-2"></i>My Account
            </span>
        </div>

        <nav class="nav flex-column mt-2">
            <a class="nav-link active" href="customer-dashboard.php">
                <i class="fas fa-home"></i>Dashboard
            </a>
            <a class="nav-link" href="menu.php">
                <i class="fas fa-utensils"></i>Browse Menu
            </a>
            <a class="nav-link" href="orders.php">
                <i class="fas fa-shopping-cart"></i>My Orders
                <?php if ($pendingOrders > 0): ?>
                    <span class="badge bg-warning text-dark ms-auto"><?php echo $pendingOrders; ?></span>
                <?php endif; ?>
            </a>
            <a class="nav-link" href="cart.php">
                <i class="fas fa-shopping-basket"></i>Cart
                <span class="badge bg-warning text-dark ms-auto"><?php echo $cart_count; ?></span>
            </a>
            <a class="nav-link" href="favorites.php">
                <i class="fas fa-heart"></i>Favorites
                <span class="badge bg-warning text-dark ms-auto"><?php echo $favorite_count; ?></span>
            </a>
            <a class="nav-link" href="reservations.php">
                <i class="fas fa-calendar"></i>Reservations
            </a>
            <a class="nav-link" href="feedback.php">
                <i class="fas fa-comment-dots"></i>Leave Feedback
            </a>

            <div class="sidebar-divider"></div>

            <a class="nav-link" href="profile.php">
                <i class="fas fa-user"></i>My Profile
            </a>
            <a class="nav-link" href="settings.php">
                <i class="fas fa-cog"></i>Settings
            </a>
        </nav>
    </aside>

    <!-- ── MAIN ──────────────────────────────────────────────── -->
    <main class="main-content">

        <?php if ($reservationSuccess): ?>
            <div class="alert alert-success" role="status">
                <?php echo htmlspecialchars($reservationSuccess); ?>
                <a href="reservations.php">View my reservations</a>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="dashboard-heading">
            <a class="dashboard-profile" href="profile.php">
                <img src="<?php echo htmlspecialchars($navAvatarSrc); ?>" alt="" width="38" height="38">
                <span>My account</span><i class="fas fa-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <section class="welcome-banner" aria-labelledby="welcome-title">
            <div class="welcome-copy">
                <h2 id="welcome-title">Welcome back,<br><?php echo htmlspecialchars($user['first_name'] ?? 'Customer'); ?>!</h2>
                <p>Your next favorite meal is waiting. Make yourself at home at La Elena Fishyalan.</p>
                <a href="menu.php" class="dashboard-button">Explore the menu <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </div>
            <div class="welcome-photo"><span><i class="fas fa-location-dot" aria-hidden="true"></i> A little escape. A lot of flavor.</span></div>
        </section>

        <!-- ── QUICK ACTIONS + SPECIAL OFFERS ─────────────── -->
        <div class="row g-4 mb-4">
            <div class="col-12 col-lg-6">
                <div class="action-card h-100 quick-actions">
                    <div class="dashboard-section-heading"><h2>What sounds good?</h2><p>A little something for every plan.</p></div>
                    <div class="dashboard-action-list">
                        <a href="menu.php" class="dashboard-action">
                            <span class="action-icon"><i class="fas fa-utensils" aria-hidden="true"></i></span>
                            <span><strong>Browse the menu</strong><small>Find your next favorite dish</small></span><i class="fas fa-arrow-right" aria-hidden="true"></i>
                        </a>
                        <a href="reservations.php" class="dashboard-action">
                            <span class="action-icon"><i class="far fa-calendar" aria-hidden="true"></i></span>
                            <span><strong>Reserve a table</strong><small>Make room for a good time</small></span><i class="fas fa-arrow-right" aria-hidden="true"></i>
                        </a>
                        <a href="cart.php" class="dashboard-action">
                            <span class="action-icon"><i class="fas fa-shopping-bag" aria-hidden="true"></i></span>
                            <span><strong>Visit your cart</strong><small><?php echo (int)$cart_count; ?> item<?php echo $cart_count == 1 ? '' : 's'; ?> waiting for you</small></span><i class="fas fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="action-card h-100 dashboard-offers">
                    <div class="dashboard-section-heading"><h2>Just for you <span class="section-kicker">SPECIAL OFFERS</span></h2><p>Good food tastes even better with a little extra.</p></div>
                    <?php if (!empty($activePromotions)): ?>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <p class="text-gold mb-0 fw-bold" style="font-size:0.85rem;">
                                <?php echo count($activePromotions); ?> Offer<?php echo count($activePromotions) > 1 ? 's' : ''; ?> Available
                            </p>
                            <?php if (count($activePromotions) > 1): ?>
                            <div class="d-flex align-items-center gap-2" style="z-index: 10;">
                                <span id="promoCounter" class="text-light opacity-75 fw-bold" style="font-size:0.85rem; letter-spacing:1px; white-space:nowrap;">
                                    1 / <?php echo count($activePromotions); ?>
                                </span>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-sm btn-outline-gold rounded-circle d-flex align-items-center justify-content-center" type="button" aria-label="Previous offer" data-bs-target="#promotionsCarousel" data-bs-slide="prev" style="width:28px; height:28px; padding:0; border-width: 1.5px;">
                                        <i class="fas fa-chevron-left" style="font-size: 0.75rem;" aria-hidden="true"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-gold rounded-circle d-flex align-items-center justify-content-center" type="button" aria-label="Next offer" data-bs-target="#promotionsCarousel" data-bs-slide="next" style="width:28px; height:28px; padding:0; border-width: 1.5px;">
                                        <i class="fas fa-chevron-right" style="font-size: 0.75rem;" aria-hidden="true"></i>
                                    </button>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div id="promotionsCarousel" class="carousel slide" data-bs-ride="carousel" data-bs-interval="4000">
                            <div class="carousel-inner pb-1">
                                <?php foreach ($activePromotions as $index => $promo): ?>
                                    <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                        <div class="offer-item m-1">
                                            <div class="d-flex align-items-start gap-2">
                                                <span class="badge bg-danger flex-shrink-0">
                                                    <?php if ($promo['discount_type'] === 'PERCENTAGE'): ?>
                                                        <?php echo $promo['discount_value']; ?>% OFF
                                                    <?php elseif ($promo['discount_type'] === 'FIXED'): ?>
                                                        ₱<?php echo number_format($promo['discount_value'], 0); ?> OFF
                                                    <?php else: ?>
                                                        FREE
                                                    <?php endif; ?>
                                                </span>
                                                <div>
                                                    <div style="font-size:0.88rem;font-weight:600;">
                                                        <?php echo htmlspecialchars($promo['promotion_name']); ?>
                                                    </div>
                                                    <?php if (!empty($promo['description'])): ?>
                                                    <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:2px;">
                                                        <?php echo nl2br(htmlspecialchars($promo['description'])); ?>
                                                    </div>
                                                    <?php endif; ?>
                                                    <small class="text-gold">
                                                        Until <?php echo date('M d, Y', strtotime($promo['end_date'])); ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="offers-empty">
                            <span class="offer-empty-icon"><i class="fas fa-gift" aria-hidden="true"></i></span>
                            <h3>More good things are on the way</h3>
                            <p>No active offers at the moment.<br>Stop by again soon for something special.</p>
                            <a href="menu.php" class="dashboard-text-link">Discover the menu <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── RECENT ORDERS ───────────────────────────────── -->
        <div class="card mb-4 dashboard-orders">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-gold"><i class="fas fa-clock me-2"></i>Recent Orders</h5>
                <a href="orders.php" class="btn btn-sm btn-outline-gold">View All</a>
            </div>
            <div class="card-body p-0">

                <!-- Desktop table -->
                <div class="d-none d-md-block table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Total</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recentOrders)): ?>
                                <?php foreach ($recentOrders as $order): ?>
                                <tr>
                                    <td class="text-gold">#<?php echo htmlspecialchars($order['order_id']); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($order['order_date'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo orderStatusColor($order['status']); ?>">
                                            <?php echo htmlspecialchars($order['status']); ?>
                                        </span>
                                    </td>
                                    <td>₱<?php echo number_format($order['total_amount'], 2); ?></td>
                                    <td>
                                        <a href="order-details.php?id=<?php echo urlencode($order['order_id']); ?>"
                                           class="text-gold text-decoration-none">
                                            <i class="fas fa-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="5"><div class="orders-empty"><i class="fas fa-bag-shopping" aria-hidden="true"></i><h3>Your first delicious moment starts here</h3><p>Your orders will appear here once you place one.</p><a href="menu.php" class="dashboard-text-link">Find something delicious <i class="fas fa-arrow-right" aria-hidden="true"></i></a></div></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Mobile order cards -->
                <div class="d-md-none p-3">
                    <?php if (!empty($recentOrders)): ?>
                        <?php foreach ($recentOrders as $order): ?>
                        <div class="order-mobile-card mb-2">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <span class="text-gold fw-bold">#<?php echo htmlspecialchars($order['order_id']); ?></span>
                                    <small class="text-gold d-block"><?php echo date('M d, Y', strtotime($order['order_date'])); ?></small>
                                </div>
                                <span class="badge bg-<?php echo orderStatusColor($order['status']); ?>">
                                    <?php echo htmlspecialchars($order['status']); ?>
                                </span>
                            </div>
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <span class="fw-bold">₱<?php echo number_format($order['total_amount'], 2); ?></span>
                                <a href="order-details.php?id=<?php echo urlencode($order['order_id']); ?>"
                                   class="btn btn-sm btn-outline-gold">
                                    <i class="fas fa-eye me-1"></i>View
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="orders-empty"><i class="fas fa-bag-shopping" aria-hidden="true"></i><h3>Your first delicious moment starts here</h3><p>Your orders will appear here once you place one.</p><a href="menu.php" class="dashboard-text-link">Browse the menu <i class="fas fa-arrow-right" aria-hidden="true"></i></a></div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <!-- ── ACCOUNT SETTINGS ────────────────────────────── -->
        <div class="pt-4 border-top border-secondary mb-4 dashboard-account">
            <h5 class="mb-3 text-gold"><i class="fas fa-cog me-2"></i>Account Settings</h5>
            <div class="row g-2 justify-content-center">
                <div class="col-6 col-md-3">
                    <a href="profile.php" class="btn btn-outline-gold w-100">
                        <i class="fas fa-user me-1"></i>Profile
                    </a>
                </div>
                <div class="col-6 col-md-3">
                    <a href="settings.php#security" class="btn btn-outline-gold w-100">
                        <i class="fas fa-lock me-1"></i>Security
                    </a>
                </div>
            </div>
        </div>

    </main>
</div><!-- /layout-wrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.1/dist/sweetalert2.all.min.js"></script>
<script>
    // ── SIDEBAR TOGGLE ────────────────────────────────────────
    const sidebar    = document.getElementById('mainSidebar');
    const backdrop   = document.getElementById('sidebarBackdrop');
    const toggleBtn  = document.getElementById('sidebarToggleBtn');
    const closeBtn   = document.getElementById('sidebarCloseBtn');

    function openSidebar()  {
        sidebar.classList.add('show');
        backdrop.classList.add('show');
        document.body.style.overflow = 'hidden';
        toggleBtn.setAttribute('aria-expanded', 'true');
        closeBtn.focus();
    }
    function closeSidebar() {
        sidebar.classList.remove('show');
        backdrop.classList.remove('show');
        document.body.style.overflow = '';
        toggleBtn.setAttribute('aria-expanded', 'false');
    }

    if (toggleBtn) toggleBtn.addEventListener('click', e => { e.stopPropagation(); openSidebar(); });
    if (closeBtn)  closeBtn.addEventListener('click',  closeSidebar);
    backdrop.addEventListener('click', closeSidebar);

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && sidebar.classList.contains('show')) closeSidebar();
    });
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768 && sidebar.classList.contains('show')) closeSidebar();
    });

    // Close sidebar when a leaf nav link is tapped on mobile
    sidebar.querySelectorAll('a.nav-link').forEach(link => {
        link.addEventListener('click', () => { if (window.innerWidth < 768) closeSidebar(); });
    });

    // ── LOGOUT CONFIRM ────────────────────────────────────────
    document.addEventListener('click', function(e) {
        const link = e.target.closest('.logout-link');
        if (!link) return;
        e.preventDefault();
        const href = link.href || '../auth/logout.php';
        Swal.fire({
            title: 'Ready to leave?',
            text: 'You will be logged out of your session.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#e56b4f',
            cancelButtonColor: '#6c757d',
            confirmButtonText: '<i class="fas fa-sign-out-alt me-1"></i> Yes, logout',
            cancelButtonText: 'Stay',
            background: '#fffdf8',
            color: '#143d4a',
            reverseButtons: true,
            focusCancel: true,
        }).then(result => { if (result.isConfirmed) window.location.href = href; });
    });
    
    // ── PROMOTION CAROUSEL COUNTER ────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        const promoCarousel = document.getElementById('promotionsCarousel');
        const promoCounter = document.getElementById('promoCounter');
        if (promoCarousel && promoCounter) {
            promoCarousel.addEventListener('slid.bs.carousel', function (e) {
                const total = <?php echo count($activePromotions); ?>;
                promoCounter.textContent = (e.to + 1) + ' / ' + total;
            });
        }
    });
</script>
</body>
</html>
