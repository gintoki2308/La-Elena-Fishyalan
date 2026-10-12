<?php
/* Shared customer navigation. Include after the page has established its session. */
$customerNavPage = $customerNavPage ?? basename($_SERVER['PHP_SELF'] ?? '');
$customerNavDashboardUrl = $customerNavDashboardUrl ?? ($dashboardUrl ?? 'customer-dashboard.php');
$customerNavCartCount = array_sum($_SESSION['cart'] ?? []);

if (isset($favorite_count)) {
    $customerNavFavoriteCount = (int)$favorite_count;
} elseif (isset($fav_count)) {
    $customerNavFavoriteCount = (int)$fav_count;
} elseif (isset($favorites) && is_array($favorites)) {
    $customerNavFavoriteCount = count($favorites);
} elseif (isset($db) && $db instanceof PDO) {
    $favoriteCountStmt = $db->prepare('SELECT COUNT(*) FROM favorites WHERE member_id = :member_id');
    $favoriteCountStmt->execute([':member_id' => (int)($_SESSION['user_id'] ?? 0)]);
    $customerNavFavoriteCount = (int)$favoriteCountStmt->fetchColumn();
} else {
    $customerNavFavoriteCount = 0;
}

function customerNavActive(string $page, string $current): string {
    return $page === $current ? ' active' : '';
}
?>
<nav class="navbar customer-navbar navbar-expand-lg sticky-top">
    <div class="container-fluid customer-navbar-inner">
        <a class="navbar-brand" href="<?php echo htmlspecialchars($customerNavDashboardUrl); ?>">
            <i class="fas fa-utensils me-2"></i>La Elena Fishyalan
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#customerNav"
                aria-controls="customerNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="customerNav">
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <li class="nav-item"><a class="nav-link<?php echo customerNavActive('customer-dashboard.php', $customerNavPage); ?>" href="<?php echo htmlspecialchars($customerNavDashboardUrl); ?>"><i class="fas fa-house"></i><span>Home</span></a></li>
                <li class="nav-item"><a class="nav-link<?php echo customerNavActive('menu.php', $customerNavPage); ?>" href="menu.php"><i class="fas fa-book-open"></i><span>Menu</span></a></li>
                <li class="nav-item"><a class="nav-link<?php echo customerNavActive('orders.php', $customerNavPage); ?>" href="orders.php"><i class="fas fa-clipboard-list"></i><span>My Orders</span></a></li>
                <li class="nav-item"><a class="nav-link<?php echo customerNavActive('reservations.php', $customerNavPage); ?>" href="reservations.php"><i class="fas fa-calendar-days"></i><span>Reservations</span></a></li>
                <li class="nav-item"><a class="nav-link<?php echo customerNavActive('feedback.php', $customerNavPage); ?>" href="feedback.php"><i class="fas fa-comment-dots"></i><span>Feedback</span></a></li>
                <li class="nav-item customer-nav-divider" aria-hidden="true"></li>
                <li class="nav-item"><a class="nav-link<?php echo customerNavActive('cart.php', $customerNavPage); ?>" href="cart.php"><i class="fas fa-cart-shopping"></i><span>Cart</span><span class="customer-nav-badge" id="nav-cart-count"><?php echo $customerNavCartCount; ?></span></a></li>
                <li class="nav-item"><a class="nav-link<?php echo customerNavActive('favorites.php', $customerNavPage); ?>" href="favorites.php"><i class="fas fa-heart"></i><span>Favorites</span><span class="customer-nav-badge" id="nav-fav-count"><?php echo $customerNavFavoriteCount; ?></span></a></li>
                <li class="nav-item"><a class="nav-link customer-logout logout-link" href="../auth/logout.php"><i class="fas fa-right-from-bracket"></i><span>Logout</span></a></li>
            </ul>
        </div>
    </div>
</nav>
