<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

// 1. AUTHENTICATION
if (!isLoggedIn() || (!isStaff() && !isAdmin())) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

// 2. DATABASE CONNECTION
$database = new Database();
$db = $database->connect();

// 3. FILTERS & SEARCH
$category_filter = isset($_GET['category']) ? $_GET['category'] : 'all';
$stock_filter = isset($_GET['stock']) ? $_GET['stock'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

if (!in_array($stock_filter, ['all', 'in_stock', 'out_of_stock'], true)) {
    $stock_filter = 'all';
}

// 4. BUILD QUERY
$query = "SELECT * FROM menu_items WHERE LOWER(TRIM(category)) NOT IN ('cocktails', 'cocktail', 'wine', 'non-alcoholic', 'after dinner', 'coffee', 'tea', 'juice', 'smoothie')";
if ($category_filter !== 'all') {
    $query .= " AND category = :category";
}
if ($stock_filter === 'in_stock') {
    $query .= " AND COALESCE(stock_quantity, 0) > 0";
} elseif ($stock_filter === 'out_of_stock') {
    $query .= " AND COALESCE(stock_quantity, 0) <= 0";
}
if ($search !== '') {
    $query .= " AND (item_name LIKE :search1 OR description LIKE :search2)";
}
$query .= " ORDER BY category, item_name ASC";

$stmt = $db->prepare($query);

if ($category_filter !== 'all') {
    $stmt->bindParam(':category', $category_filter);
}
if ($search !== '') {
    $search_term = '%' . $search . '%';
    $stmt->bindParam(':search1', $search_term);
    $stmt->bindParam(':search2', $search_term);
}

$stmt->execute();
$menu_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. GET CATEGORIES FOR DROPDOWN
$cat_query = "SELECT DISTINCT category FROM menu_items WHERE LOWER(TRIM(category)) NOT IN ('cocktails', 'cocktail', 'wine', 'non-alcoholic', 'after dinner', 'coffee', 'tea', 'juice', 'smoothie') ORDER BY category ASC";
$cat_stmt = $db->prepare($cat_query);
$cat_stmt->execute();
$categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

// 6. GET ACTIVE PROMOTIONS
$promoStmt = $db->query("SELECT * FROM promotions WHERE is_active = 1 AND start_date <= CURDATE() AND end_date >= CURDATE()");
$activePromotions = $promoStmt->fetchAll(PDO::FETCH_ASSOC);

function getPromoBadgesHtml($category, $activePromotions)
{
    $badges = [];
    foreach ($activePromotions as $promo) {
        $allowed_categories = empty($promo['applicable_category']) ? [] : array_map('strtolower', array_map('trim', explode(',', $promo['applicable_category'])));
        if (empty($allowed_categories) || in_array(strtolower(trim($category)), $allowed_categories, true)) {
            $badges[] = '<span class="promo-badge"><i class="fas fa-tag"></i> ' . htmlspecialchars($promo['promotion_name']) . '</span>';
        }
    }

    if (empty($badges)) {
        return '';
    }

    return '<div class="promo-badge-stack">' . implode('', $badges) . '</div>';
}

// 7. LOG ACTION
$audit_query = "INSERT INTO audit_logs (staff_id, action, table_affected, record_id, ip_address, timestamp) 
                VALUES (:staff_id, 'VIEW_MENU', 'menu_items', 0, :ip, NOW())";
$audit_stmt = $db->prepare($audit_query);
$audit_stmt->bindParam(':staff_id', $_SESSION['user_id']);
$audit_stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
$audit_stmt->execute();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu Management | La Elena Fishyalan</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-dark@4/dark.css">

    <style>
        :root {
            --gold: #d4af37;
            --dark-bg: #121212;
            --card-bg: #1e1e1e;
            --border-color: #333;
        }

        body {
            background-color: var(--dark-bg);
            color: #e0e0e0;
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }

        .text-primary-gold {
            color: var(--gold) !important;
        }

        /* Keep the add-item modal inside the viewport; only its form scrolls. */
        #addMenuItemModal .modal-dialog {
            max-height: calc(100vh - 2rem);
            margin: 1rem auto;
        }

        #addMenuItemModal .modal-content {
            max-height: calc(100vh - 2rem);
        }

        #addMenuItemModal form {
            display: flex;
            flex: 1 1 auto;
            min-height: 0;
            flex-direction: column;
            overflow: hidden;
        }

        #addMenuItemModal .modal-body {
            overflow-y: auto;
        }

        .navbar {
            background-color: #000 !important;
            border-bottom: 1px solid var(--border-color);
        }

        .menu-card {
            background: var(--card-bg);
            border: 1px solid #3b3b3b;
            border-radius: 14px;
            padding: 24px 26px 22px;
            margin-bottom: 24px;
            position: relative;
            transition: all 0.3s ease;
        }

        .menu-card:hover {
            border-color: var(--gold);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.5);
        }

        .category-badge {
            background: #292929;
            color: #c7c7c7;
            padding: 6px 11px;
            border-radius: 5px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 14px;
            display: inline-block;
        }

        .badge-container {
            position: absolute;
            top: 22px;
            right: 26px;
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
        }

        .price-badge {
            color: var(--gold);
            font-weight: 700;
            font-size: 1.1rem;
            border: 1px solid var(--gold);
            padding: 7px 13px;
            border-radius: 8px;
            background: rgba(212, 175, 55, 0.1);
        }

        .stock-badge {
            color: #c8c8c8;
            font-size: 0.75rem;
            padding: 2px 8px;
            border-radius: 4px;
            background: #2a2a2a;
            border: 1px solid #444;
            letter-spacing: 0.5px;
        }

        .menu-image-wrap {
            position: relative;
            width: 100px;
            height: 100px;
            flex-shrink: 0;
        }

        .menu-image-wrap img,
        .menu-image-placeholder {
            width: 100%;
            height: 100%;
            border-radius: 8px;
        }

        .menu-image-wrap img {
            object-fit: cover;
            border: 1px solid var(--gold);
        }

        .menu-image-placeholder {
            border: 1px dashed #555;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #222;
            color: #555;
        }

        /* ── PROMO BADGES (inline next to item name) ── */
        .promo-badge-stack {
            display: inline-flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px;
            vertical-align: middle;
        }

        .promo-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            border-radius: 999px;
            background: linear-gradient(135deg, #ff5a6e, #e62e4d);
            color: #fff;
            font-size: 0.56rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            box-shadow: 0 6px 14px rgba(0, 0, 0, 0.3);
            white-space: nowrap;
        }

        /* Menu name typography; colors come from the shared staff theme. */
        .item-name {
            font-size: 1.35rem;
            font-weight: 700;
            margin-bottom: 8px;
            padding-right: 120px;
        }

        .item-description {
            color: #bdbdbd;
            font-size: 0.95rem;
            margin-bottom: 0;
            line-height: 1.5;
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
        }

        .status-available {
            background: rgba(25, 135, 84, 0.24);
            color: #71e0a5;
        }

        .status-unavailable {
            background: #431b1b;
            color: #e07575;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .action-btn {
            border-radius: 6px;
            padding: 8px 14px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: 0.2s;
            border: none;
            cursor: pointer;
        }

        @media (max-width: 575.98px) {
            .menu-card {
                padding: 18px;
            }

            .badge-container {
                top: 18px;
                right: 18px;
            }

            .menu-image-wrap {
                width: 76px;
                height: 76px;
            }

            .item-name {
                font-size: 1.15rem;
                padding-right: 92px;
            }

            .action-buttons {
                gap: 6px;
            }

            .action-btn {
                padding: 8px 10px;
            }

            .action-btn.btn-edit {
                font-size: 0;
            }

            .action-btn.btn-edit i {
                margin-right: 0 !important;
            }
        }

        .btn-edit {
            background: #0d6efd;
            color: white;
        }

        .btn-toggle {
            background: #ffc107;
            color: #000;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
        }

        .action-btn:hover {
            opacity: 0.9;
            color: white;
        }

        .btn-toggle:hover {
            color: #000;
        }

        .filter-card {
            background: #1a1a1a;
            border: 1px solid var(--gold);
            border-radius: 8px;
        }

        /* ── Live Search Styles ── */
        .search-wrapper {
            position: relative;
        }

        .search-wrapper .search-icon-inner {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            pointer-events: none;
        }

        .search-wrapper #liveSearchInput {
            padding-left: 38px;
            padding-right: 36px;
        }

        .search-clear-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #888;
            cursor: pointer;
            font-size: 0.9rem;
            display: none;
            line-height: 1;
            padding: 2px 4px;
        }

        .search-clear-btn:hover {
            color: var(--gold);
        }

        .live-search-meta {
            font-size: 0.82rem;
            color: #888;
            margin-top: 8px;
            min-height: 20px;
            transition: opacity 0.2s;
        }

        .live-search-meta span {
            color: var(--gold);
            font-weight: 600;
        }

        .no-results-state {
            display: none;
            text-align: center;
            padding: 4rem 2rem;
            color: #888;
        }

        .no-results-state i {
            font-size: 3rem;
            color: var(--gold);
            opacity: 0.4;
            margin-bottom: 1rem;
        }

        .menu-card-wrapper {
            transition: opacity 0.2s ease;
        }

        .menu-card-wrapper.hidden {
            display: none !important;
        }

        /* ── Pagination Styles ── */
        .pagination-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 12px;
            margin-top: 8px;
            padding-top: 16px;
            border-top: 1px solid var(--border-color);
        }

        .pagination-info {
            font-size: 0.82rem;
            color: #888;
        }

        .pagination-info span {
            color: var(--gold);
            font-weight: 600;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            justify-content: center;
        }

        .page-btn {
            min-width: 38px;
            height: 38px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: #1e1e1e;
            color: #ccc;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 10px;
            transition: all 0.2s;
        }

        .page-btn:hover:not(:disabled) {
            border-color: var(--gold);
            color: var(--gold);
            background: rgba(212, 175, 55, 0.08);
        }

        .page-btn.active {
            background: var(--gold);
            border-color: var(--gold);
            color: #000;
        }

        .page-btn:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }

        .page-btn.ellipsis {
            border: none;
            background: transparent;
            cursor: default;
            color: #666;
        }

        .per-page-select {
            background: #1e1e1e;
            border: 1px solid var(--border-color);
            color: #ccc;
            border-radius: 8px;
            padding: 4px 10px;
            font-size: 0.82rem;
            cursor: pointer;
        }

        .per-page-select:focus {
            outline: none;
            border-color: var(--gold);
        }

        .per-page-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.82rem;
            color: #888;
        }

        @media (max-width: 576px) {
            .badge-container {
                position: static;
                flex-direction: row;
                align-items: center;
                margin-bottom: 15px;
            }

            .item-name {
                padding-right: 0;
            }

            .action-buttons {
                flex-wrap: wrap;
            }

            .action-btn {
                flex: 1;
                justify-content: center;
            }

            .pagination-controls {
                gap: 4px;
            }

            .page-btn {
                min-width: 34px;
                height: 34px;
                font-size: 0.78rem;
            }
        }
    </style>
    <link rel="stylesheet" href="../staff-page-theme.css">
</head>

<body>

    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container-fluid px-4">
            <a class="navbar-brand text-primary-gold fw-bold" href="../staff-dashboard.php">
                <i class="fas fa-utensils me-2"></i>LA ELENA FISHYALAN STAFF
            </a>
            <div class="d-flex align-items-center">
                <span class="text-secondary me-3 d-none d-md-block small">Welcome, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Staff'); ?>!</span>
                <a href="../../auth/logout.php" class="logout-link btn btn-sm btn-outline-danger">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4 px-lg-5">

        <div class="row align-items-center mb-4">
            <div class="col-md-6">
                <a href="../staff-dashboard.php" class="btn btn-sm btn-outline-warning mb-2">
                    <i class="fas fa-arrow-left"></i> Back
                </a>
                <h1 class="h2 mb-0">Menu Management</h1>
            </div>
            <div class="col-md-6 text-md-end">
                <button type="button" class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#addMenuItemModal">
                    <i class="fas fa-plus-circle me-2"></i>Add New Item
                </button>
            </div>
        </div>

        <div class="card filter-card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3" id="filterForm">
                    <div class="col-md-4">
                        <div class="search-wrapper">
                            <i class="fas fa-search search-icon-inner"></i>
                            <input type="text" name="search" id="liveSearchInput"
                                class="form-control bg-dark text-light border-secondary"
                                placeholder="Search items by name or description..."
                                value="<?php echo htmlspecialchars($search); ?>"
                                autocomplete="off">
                            <button type="button" class="search-clear-btn" id="clearSearch" title="Clear search">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <select name="category" id="categoryFilter" class="form-select bg-dark text-light border-secondary">
                            <option value="all">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category_filter === $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="stock" id="stockFilter" class="form-select bg-dark text-light border-secondary">
                            <option value="all" <?php echo $stock_filter === 'all' ? 'selected' : ''; ?>>All Stock Levels</option>
                            <option value="in_stock" <?php echo $stock_filter === 'in_stock' ? 'selected' : ''; ?>>In Stock</option>
                            <option value="out_of_stock" <?php echo $stock_filter === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-warning w-100 fw-bold text-dark">
                            <i class="fas fa-filter me-1"></i> Filter
                        </button>
                    </div>
                </form>
                <div class="live-search-meta" id="searchMeta"></div>
            </div>
        </div>

        <div class="row" id="menuGrid">
            <?php foreach ($menu_items as $item): ?>
                <div class="col-xl-6 menu-card-wrapper"
                    data-name="<?php echo strtolower(htmlspecialchars($item['item_name'])); ?>"
                    data-desc="<?php echo strtolower(htmlspecialchars($item['description'])); ?>"
                    data-category="<?php echo strtolower(htmlspecialchars($item['category'])); ?>"
                    data-stock="<?php echo (int) ($item['stock_quantity'] ?? 0); ?>">
                    <div class="menu-card">
                        <div class="category-badge"><?php echo htmlspecialchars($item['category']); ?></div>

                        <div class="badge-container">
                            <div class="price-badge">₱<?php echo number_format($item['price'], 2); ?></div>
                        </div>

                        <div class="d-flex gap-3 mb-3 mt-2 align-items-start">
                            <div class="menu-image-wrap">
                                <?php if (!empty($item['image_url'])): ?>
                                    <img src="<?php echo htmlspecialchars('../../' . $item['image_url']); ?>" alt="Menu Image">
                                <?php else: ?>
                                    <div class="menu-image-placeholder">
                                        <i class="fas fa-image fa-2x"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex-grow-1">
                                <h5 class="item-name mb-1">
                                    <?php echo htmlspecialchars($item['item_name']); ?>
                                    <?php echo getPromoBadgesHtml($item['category'], $activePromotions); ?>
                                </h5>
                                <p class="item-description">
                                    <?php echo htmlspecialchars($item['description']); ?>
                                </p>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center border-top border-secondary pt-3 mt-auto">
                            <span class="status-badge <?php echo $item['is_available'] ? 'status-available' : 'status-unavailable'; ?>">
                                <i class="fas <?php echo $item['is_available'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                                <?php echo $item['is_available'] ? 'Available' : 'Unavailable'; ?>
                            </span>

                            <div class="action-buttons">
                                <a href="editMenuItem.php?id=<?php echo urlencode($item['item_id']); ?>" class="action-btn btn-edit">
                                    <i class="fas fa-edit me-1"></i> Edit
                                </a>

                                <a href="toggleMenuItem.php?id=<?php echo urlencode($item['item_id']); ?>" class="action-btn btn-toggle">
                                    <i class="fas <?php echo $item['is_available'] ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                </a>

                                <button type="button"
                                    class="action-btn btn-delete delete-confirm-btn"
                                    data-id="<?php echo $item['item_id']; ?>"
                                    data-name="<?php echo htmlspecialchars($item['item_name']); ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="no-results-state" id="noResultsState">
            <i class="fas fa-search-minus d-block"></i>
            <h5 style="color:#ccc;">No items match your search</h5>
            <p>Try a different keyword or <button class="btn btn-sm btn-outline-warning mt-1" onclick="clearLiveSearch()">clear the search</button></p>
        </div>

        <!-- Pagination UI -->
        <div class="pagination-wrapper" id="paginationWrapper">
            <div class="pagination-info" id="paginationInfo"></div>
            <div class="pagination-controls" id="paginationControls"></div>
            <div class="per-page-wrapper">
                <label for="perPageSelect">Items per page:</label>
                <select id="perPageSelect" class="per-page-select">
                    <option value="6">6</option>
                    <option value="10" selected>10</option>
                    <option value="20">20</option>
                    <option value="50">All</option>
                </select>
            </div>
        </div>

    </div><!-- end container -->

    <!-- Add Menu Item Modal -->
    <div class="modal fade" id="addMenuItemModal" tabindex="-1" aria-labelledby="addMenuItemModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content bg-dark border-secondary">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-primary-gold" id="addMenuItemModalLabel">
                        <i class="fas fa-plus-circle me-2"></i>Add New Menu Item
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="createMenuItem.php" method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="modal_item_name" class="form-label text-light">Item Name <span class="text-warning">*</span></label>
                            <input type="text" class="form-control bg-dark text-light border-secondary" id="modal_item_name" name="item_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="modal_description" class="form-label text-light">Description <span class="text-warning">*</span></label>
                            <textarea class="form-control bg-dark text-light border-secondary" id="modal_description" name="description" rows="3" required></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modal_category" class="form-label text-light">Category <span class="text-warning">*</span></label>
                                <select class="form-select bg-dark text-light border-secondary" id="modal_category" name="category" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="modal_price" class="form-label text-light">Price (₱) <span class="text-warning">*</span></label>
                                <input type="number" class="form-control bg-dark text-light border-secondary" id="modal_price" name="price" step="0.01" min="0.01" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="modal_image" class="form-label text-light">Item Image</label>
                            <input type="file" class="form-control bg-dark text-light border-secondary" id="modal_image" name="image" accept="image/*">
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="modal_is_available" name="is_available" checked>
                            <label class="form-check-label text-light" for="modal_is_available">Available Now</label>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i>Add Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // ── Delete Confirmation ──────────────────────────────────────────
        document.querySelectorAll('.delete-confirm-btn').forEach(button => {
            button.addEventListener('click', function() {
                const itemId = this.getAttribute('data-id');
                const itemName = this.getAttribute('data-name');

                Swal.fire({
                    title: 'Are you sure?',
                    text: `You are about to delete "${itemName}". This cannot be undone!`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, delete it!',
                    cancelButtonText: 'Cancel',
                    background: '#1e1e1e',
                    color: '#fff'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `deleteMenuItem.php?id=${itemId}`;
                    }
                });
            });
        });

        // ── Logout Confirmation ──────────────────────────────────────────
        document.querySelector('.logout-link').addEventListener('click', function(e) {
            e.preventDefault();
            const logoutUrl = this.getAttribute('href');

            Swal.fire({
                title: 'Ready to Leave?',
                text: 'Are you sure you want to log out of the staff panel?',
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
        });
    </script>

    <script>
        // ── Live Search + Pagination ─────────────────────────────────────
        const liveInput = document.getElementById('liveSearchInput');
        const clearBtn = document.getElementById('clearSearch');
        const searchMeta = document.getElementById('searchMeta');
        const noResults = document.getElementById('noResultsState');
        const paginationInfo = document.getElementById('paginationInfo');
        const paginationCtrl = document.getElementById('paginationControls');
        const paginationWrap = document.getElementById('paginationWrapper');
        const perPageSelect = document.getElementById('perPageSelect');
        const allCards = Array.from(document.querySelectorAll('.menu-card-wrapper'));
        const totalItems = allCards.length;

        let currentPage = 1;
        let itemsPerPage = parseInt(perPageSelect.value);
        let visibleCards = [...allCards];

        // ── Filter by search query ────────────────────────────────────────
        function applySearch() {
            const query = liveInput.value.trim().toLowerCase();
            visibleCards = allCards.filter(card => {
                const name = card.dataset.name || '';
                const desc = card.dataset.desc || '';
                return name.includes(query) || desc.includes(query);
            });
            clearBtn.style.display = query ? 'block' : 'none';
            currentPage = 1;
            render();
        }

        // ── Render visible cards for the current page ─────────────────────
        function render() {
            const matched = visibleCards.length;
            const query = liveInput.value.trim();

            allCards.forEach(c => c.classList.add('hidden'));

            if (matched === 0) {
                noResults.style.display = 'block';
                paginationWrap.style.display = 'none';
                searchMeta.innerHTML = query ?
                    `No results for "<span>${query}</span>"` :
                    '';
                return;
            }

            noResults.style.display = 'none';

            const perPage = itemsPerPage >= 50 ? Infinity : itemsPerPage;
            const totalPages = perPage === Infinity ? 1 : Math.ceil(matched / perPage);

            if (currentPage > totalPages) currentPage = totalPages;

            const start = perPage === Infinity ? 0 : (currentPage - 1) * perPage;
            const end = perPage === Infinity ? matched : Math.min(start + perPage, matched);

            visibleCards.forEach((card, i) => {
                if (i >= start && i < end) {
                    card.classList.remove('hidden');
                }
            });

            // ── Search meta text ──────────────────────────────────────────
            if (query) {
                searchMeta.innerHTML = `Showing <span>${end - start}</span> of <span>${matched}</span> results for "<span>${query}</span>"`;
            } else {
                searchMeta.innerHTML = perPage === Infinity ?
                    `Showing all <span>${matched}</span> items` :
                    `Showing <span>${start + 1}–${end}</span> of <span>${matched}</span> items`;
            }

            // ── Pagination bar ────────────────────────────────────────────
            if (totalPages <= 1) {
                paginationWrap.style.display = 'flex';
                paginationInfo.innerHTML = '';
                paginationCtrl.innerHTML = '';
                return;
            }

            paginationWrap.style.display = 'flex';
            paginationInfo.innerHTML = `Page <span>${currentPage}</span> of <span>${totalPages}</span>`;
            renderPageButtons(totalPages);
        }

        // ── Page number buttons with ellipsis ────────────────────────────
        function renderPageButtons(totalPages) {
            paginationCtrl.innerHTML = '';

            const btn = (label, page, disabled = false, active = false, isEllipsis = false) => {
                const b = document.createElement('button');
                b.className = 'page-btn' + (active ? ' active' : '') + (isEllipsis ? ' ellipsis' : '');
                b.innerHTML = label;
                b.disabled = disabled;
                if (!isEllipsis && !disabled) {
                    b.addEventListener('click', () => {
                        currentPage = page;
                        render();
                        scrollToGrid();
                    });
                }
                paginationCtrl.appendChild(b);
            };

            btn('<i class="fas fa-chevron-left"></i>', currentPage - 1, currentPage === 1);

            const delta = 2;
            const range = [];
            const rangeWithDots = [];
            let l;

            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= currentPage - delta && i <= currentPage + delta)) {
                    range.push(i);
                }
            }

            for (let i of range) {
                if (l) {
                    if (i - l === 2) {
                        rangeWithDots.push(l + 1);
                    } else if (i - l !== 1) {
                        rangeWithDots.push('...');
                    }
                }
                rangeWithDots.push(i);
                l = i;
            }

            rangeWithDots.forEach(p => {
                if (p === '...') {
                    btn('&hellip;', null, true, false, true);
                } else {
                    btn(p, p, false, p === currentPage);
                }
            });

            btn('<i class="fas fa-chevron-right"></i>', currentPage + 1, currentPage === totalPages);
        }

        function scrollToGrid() {
            const grid = document.getElementById('menuGrid');
            if (grid) grid.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }

        function clearLiveSearch() {
            liveInput.value = '';
            applySearch();
            liveInput.focus();
        }

        liveInput.addEventListener('input', applySearch);
        clearBtn.addEventListener('click', clearLiveSearch);

        perPageSelect.addEventListener('change', () => {
            itemsPerPage = parseInt(perPageSelect.value);
            currentPage = 1;
            render();
        });

        applySearch();
    </script>
</body>

</html>
