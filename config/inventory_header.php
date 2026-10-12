<?php $inventoryDashboard = isAdmin() ? '../../adminSide/admin-dashboard.php' : '../../staffSide/staff-dashboard.php'; ?>
<nav class="inventory-topbar" aria-label="Main navigation">
    <div class="inventory-topbar-inner">
        <a class="inventory-brand" href="<?= $inventoryDashboard ?>">
            <i class="fas fa-utensils" aria-hidden="true"></i>
            <span>LA ELENA FISHYALAN <span class="inventory-role"><?= isAdmin() ? 'ADMIN' : 'STAFF' ?></span></span>
        </a>
        <div class="inventory-account">
            <span class="inventory-welcome">Welcome, <?= htmlspecialchars($_SESSION['first_name'] ?? (isAdmin() ? 'Admin' : 'Staff'), ENT_QUOTES, 'UTF-8') ?>!</span>
            <a href="../../auth/logout.php" class="logout-link btn btn-sm btn-outline-danger">Logout</a>
        </div>
    </div>
</nav>
