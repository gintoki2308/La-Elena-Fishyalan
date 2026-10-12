<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

// 1. AUTHENTICATION (Admin Only)
if (!isLoggedIn() || !isAdmin()) {
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

// 5. GET CATEGORIES
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
writeAuditLog($db, 'VIEW_MENU_ADMIN', 'menu_items', 0, [
    'summary'          => 'Viewed menu management list',
    'category_filter'  => $category_filter,
    'stock_filter'     => $stock_filter,
    'search'           => $search,
    'visible_count'    => count($menu_items),
]);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Menu Management | La Elena Fishyalan</title>

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
            font-family: 'Segoe UI', sans-serif;
        }

        .text-primary-gold {
            color: var(--gold) !important;
        }

        .navbar {
            background-color: #000 !important;
            border-bottom: 1px solid var(--border-color);
        }

        /* Menu table */
        .menu-table-container {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.08);
        }

        .menu-table {
            min-width: 900px;
            margin-bottom: 0;
        }

        .menu-table > :not(caption) > * > * {
            padding: 16px 20px;
            vertical-align: middle;
        }

        .menu-table thead th {
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            white-space: nowrap;
        }

        .menu-table tbody tr:last-child > * {
            border-bottom: 0;
        }

        .menu-item-details {
            min-width: 260px;
            max-width: 480px;
        }

        .menu-table .price-cell {
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .menu-table .price-cell {
            color: #9a7700 !important;
            font-weight: 700;
        }

        .category-badge {
            background: #f3f4f6;
            color: #4b5563;
            padding: 6px 11px;
            border-radius: 5px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 0;
            display: inline-block;
        }

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

        .item-name {
            color: #1f2937;
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 4px;
            overflow-wrap: anywhere;
        }

        .item-description {
            color: #6b7280;
            font-size: 0.9rem;
            margin-bottom: 0;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: bold;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .status-available {
            background: #dcfce7;
            color: #15803d;
        }

        .status-unavailable {
            background: #fee2e2;
            color: #b91c1c;
        }

        .action-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
        }

        .action-btn {
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            transition: 0.2s;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
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
            opacity: 0.85;
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

        /* ── LIVE SEARCH ────────────────────────────────────── */
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
            color: #888;
        }

        .menu-table .no-results-state td {
            padding: 4rem 2rem;
        }

        .no-results-state i {
            font-size: 3rem;
            color: var(--gold);
            opacity: 0.4;
            margin-bottom: 1rem;
        }

        /* ── PAGINATION ─────────────────────────────────────── */
        .pagination-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
            margin-bottom: 24px;
        }

        .pagination-info {
            font-size: 0.82rem;
            color: #888;
        }

        .pagination-info strong {
            color: var(--gold);
        }

        .pagination-controls {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }

        .page-btn {
            background: #1e1e1e;
            border: 1px solid #333;
            color: #e0e0e0;
            border-radius: 6px;
            padding: 5px 11px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
            min-width: 34px;
            text-align: center;
            line-height: 1.4;
        }

        .page-btn:hover {
            border-color: var(--gold);
            color: var(--gold);
        }

        .page-btn.active {
            background: var(--gold);
            border-color: var(--gold);
            color: #000;
            font-weight: 700;
        }

        .page-btn:disabled {
            opacity: 0.35;
            cursor: not-allowed;
        }

        .page-btn.dots {
            cursor: default;
            border-color: transparent;
            background: transparent;
        }

        .page-btn.dots:hover {
            color: #e0e0e0;
            border-color: transparent;
        }

        /* Keep the add-item form focused and compact on larger screens. */
        .menu-item-modal {
            max-width: 520px;
        }

        .menu-item-modal .modal-content {
            border-radius: 10px;
        }

        .menu-item-modal .modal-header,
        .menu-item-modal .modal-footer {
            padding: 14px 20px;
        }

        .menu-item-modal .modal-body {
            padding: 20px !important;
        }

        .menu-item-modal textarea {
            min-height: 96px;
        }

        .menu-item-modal {
            max-height: calc(100vh - 2rem);
            margin-top: 1rem;
            margin-bottom: 1rem;
        }

        .menu-item-modal .modal-content {
            max-height: calc(100vh - 2rem);
        }

        /* ── RESPONSIVE ─────────────────────────────────────── */
        @media (max-width: 576px) {
            .menu-table > :not(caption) > * > * {
                padding: 12px;
            }
        }
    </style>
</head>

<body>

    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container-fluid px-4">
            <a class="navbar-brand text-primary-gold fw-bold" href="../admin-dashboard.php">
                LA ELENA FISHYALAN ADMIN
            </a>
            <div class="d-flex align-items-center">
                <span class="text-secondary me-3 d-none d-md-block small">Logged in as Administrator</span>
                <a href="../../auth/logout.php" class="logout-link btn btn-sm btn-outline-danger">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4 px-lg-5">

        <div class="row align-items-center mb-4">
            <div class="col-md-6">
                <a href="../admin-dashboard.php" class="btn btn-sm btn-outline-warning mb-2">
                    <i class="fas fa-arrow-left"></i> Admin Home
                </a>
                <h1 class="h2 mb-0">Menu Management</h1>
            </div>
            <div class="col-md-6 text-md-end">
                <button type="button" class="btn btn-success btn-lg" data-bs-toggle="modal" data-bs-target="#addMenuItemModal">
                    <i class="fas fa-plus-circle me-2"></i>Add New Item
                </button>
            </div>
        </div>

        <!-- FILTERS -->
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

        <!-- PAGINATION BAR (top) -->
        <div class="pagination-bar" id="pagination-top"></div>

        <!-- MENU TABLE -->
        <div class="table-responsive menu-table-container" role="region" aria-label="Menu items" tabindex="0">
            <table class="table table-hover align-middle menu-table">
                <caption class="visually-hidden">Menu items with prices, availability, and management actions</caption>
                <thead>
                    <tr>
                        <th scope="col">Item</th>
                        <th scope="col">Category</th>
                        <th scope="col" class="text-end">Price</th>
                        <th scope="col">Availability</th>
                        <th scope="col" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody id="menuTableBody">
                    <?php foreach ($menu_items as $item): ?>
                        <tr class="menu-item-row"
                            data-name="<?php echo strtolower(htmlspecialchars($item['item_name'])); ?>"
                            data-desc="<?php echo strtolower(htmlspecialchars($item['description'])); ?>"
                            data-category="<?php echo strtolower(htmlspecialchars($item['category'])); ?>"
                            data-stock="<?php echo (int) ($item['stock_quantity'] ?? 0); ?>">
                            <td class="menu-item-details">
                                <div class="item-name">
                                    <?php echo htmlspecialchars($item['item_name']); ?>
                                    <?php echo getPromoBadgesHtml($item['category'], $activePromotions); ?>
                                </div>
                                <p class="item-description">
                                    <?php echo htmlspecialchars($item['description']); ?>
                                </p>
                            </td>
                            <td><span class="category-badge"><?php echo htmlspecialchars($item['category']); ?></span></td>
                            <td class="price-cell text-end">₱<?php echo number_format($item['price'], 2); ?></td>
                            <td>
                                <span class="status-badge <?php echo $item['is_available'] ? 'status-available' : 'status-unavailable'; ?>">
                                    <i class="fas <?php echo $item['is_available'] ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                                    <?php echo $item['is_available'] ? 'Available' : 'Unavailable'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button type="button" class="action-btn btn-edit edit-menu-item-btn"
                                        data-item="<?php echo htmlspecialchars(json_encode([
                                            'id' => (int) $item['item_id'],
                                            'name' => $item['item_name'],
                                            'description' => $item['description'],
                                            'category' => $item['category'],
                                            'price' => $item['price'],
                                            'stock_quantity' => $item['stock_quantity'] ?? 0,
                                            'reorder_level' => $item['reorder_level'] ?? 10,
                                            'is_available' => (bool) $item['is_available'],
                                            'image_url' => $item['image_url'] ?? '',
                                        ]), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-bs-toggle="modal" data-bs-target="#editMenuItemModal">
                                        <i class="fas fa-edit me-1"></i> Edit
                                    </button>
                                    <a href="toggleMenuItem.php?id=<?php echo urlencode($item['item_id']); ?>" class="action-btn btn-toggle"
                                        title="<?php echo $item['is_available'] ? 'Mark unavailable' : 'Mark available'; ?>"
                                        aria-label="<?php echo $item['is_available'] ? 'Mark unavailable' : 'Mark available'; ?>">
                                        <i class="fas <?php echo $item['is_available'] ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                    </a>
                                    <button type="button"
                                        class="action-btn btn-delete delete-confirm-btn"
                                        title="Delete item" aria-label="Delete item"
                                        data-id="<?php echo $item['item_id']; ?>"
                                        data-name="<?php echo htmlspecialchars($item['item_name']); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <!-- No Results State -->
                <tr class="no-results-state" id="noResultsState">
                    <td colspan="5">
                        <i class="fas fa-search-minus d-block"></i>
                        <h5>No items match your search</h5>
                        <p>Try a different keyword or
                            <button class="btn btn-sm btn-outline-warning mt-1" onclick="clearLiveSearch()">clear the search</button>
                        </p>
                    </td>
                        </tr>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION BAR (bottom) -->
        <div class="pagination-bar" id="pagination-bottom"></div>

    </div><!-- /container -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // ══════════════════════════════════════════════════════
        // PAGINATION ENGINE
        // ══════════════════════════════════════════════════════
        const PAGE_SIZE = 10; // rows per page

        let currentPage = 1;

        function getAllRows() {
            return [...document.querySelectorAll('.menu-item-row')];
        }

        function getVisibleRows() {
            return getAllRows().filter(row => row.dataset.filtered !== 'true');
        }

        function renderPaginationBar(barEl, total, totalPages) {
            if (!barEl) return;

            const start = (currentPage - 1) * PAGE_SIZE + 1;
            const end = Math.min(currentPage * PAGE_SIZE, total);

            barEl.innerHTML = `
                <span class="pagination-info">
                    Showing <strong>${total === 0 ? 0 : start}</strong>–<strong>${total === 0 ? 0 : end}</strong>
                    of <strong>${total}</strong> items
                </span>
                <div class="pagination-controls" id="${barEl.id}-controls"></div>
            `;

            if (totalPages <= 1) return;

            const controls = document.getElementById(`${barEl.id}-controls`);

            // Prev
            const prev = document.createElement('button');
            prev.className = 'page-btn';
            prev.innerHTML = '&#8249;';
            prev.disabled = currentPage === 1;
            prev.title = 'Previous page';
            prev.addEventListener('click', () => {
                currentPage--;
                applyPagination();
            });
            controls.appendChild(prev);

            // Page numbers with ellipsis
            const delta = 2;
            const pages = [];
            for (let i = 1; i <= totalPages; i++) {
                if (i === 1 || i === totalPages || (i >= currentPage - delta && i <= currentPage + delta)) {
                    pages.push(i);
                }
            }

            let last = 0;
            pages.forEach(p => {
                if (last && p - last > 1) {
                    const dots = document.createElement('button');
                    dots.className = 'page-btn dots';
                    dots.textContent = '…';
                    dots.disabled = true;
                    controls.appendChild(dots);
                }
                const btn = document.createElement('button');
                btn.className = 'page-btn' + (p === currentPage ? ' active' : '');
                btn.textContent = p;
                btn.addEventListener('click', () => {
                    currentPage = p;
                    applyPagination();
                });
                controls.appendChild(btn);
                last = p;
            });

            // Next
            const next = document.createElement('button');
            next.className = 'page-btn';
            next.innerHTML = '&#8250;';
            next.disabled = currentPage === totalPages;
            next.title = 'Next page';
            next.addEventListener('click', () => {
                currentPage++;
                applyPagination();
            });
            controls.appendChild(next);
        }

        function applyPagination() {
            const visible = getVisibleRows();
            const total = visible.length;
            const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            const start = (currentPage - 1) * PAGE_SIZE;
            const end = start + PAGE_SIZE;

            // Hide all first, then show only current page slice
            getAllRows().forEach(row => {
                if (row.dataset.filtered === 'true') {
                    row.style.display = 'none';
                    return;
                }
                const idx = visible.indexOf(row);
                row.style.display = (idx >= start && idx < end) ? '' : 'none';
            });

            // Render both top and bottom bars
            renderPaginationBar(document.getElementById('pagination-top'), total, totalPages);
            renderPaginationBar(document.getElementById('pagination-bottom'), total, totalPages);

            // No results state
            document.getElementById('noResultsState').style.display = total === 0 ? 'table-row' : 'none';
        }

        // ══════════════════════════════════════════════════════
        // LIVE SEARCH
        // ══════════════════════════════════════════════════════
        const liveInput = document.getElementById('liveSearchInput');
        const clearBtn = document.getElementById('clearSearch');
        const searchMeta = document.getElementById('searchMeta');
        const allRows = getAllRows();
        const totalItems = allRows.length;

        function runFilter() {
            const query = liveInput.value.trim().toLowerCase();

            allRows.forEach(row => {
                const name = row.dataset.name || '';
                const desc = row.dataset.desc || '';
                const matches = name.includes(query) || desc.includes(query);
                row.dataset.filtered = matches ? 'false' : 'true';
            });

            // Clear button
            clearBtn.style.display = query ? 'block' : 'none';

            // Meta label
            const visibleCount = getVisibleRows().length;
            if (query) {
                searchMeta.innerHTML = `Showing <span>${visibleCount}</span> of <span>${totalItems}</span> items for "<span>${liveInput.value.trim()}</span>"`;
            } else {
                searchMeta.innerHTML = `Showing all <span>${totalItems}</span> items`;
            }

            // Reset to page 1 on new search and re-apply pagination
            currentPage = 1;
            applyPagination();
        }

        function clearLiveSearch() {
            liveInput.value = '';
            runFilter();
            liveInput.focus();
        }

        liveInput.addEventListener('input', runFilter);
        clearBtn.addEventListener('click', clearLiveSearch);

        // Run on page load
        runFilter();

        // ══════════════════════════════════════════════════════
        // DELETE CONFIRMATION
        // ══════════════════════════════════════════════════════
        document.querySelectorAll('.delete-confirm-btn').forEach(button => {
            button.addEventListener('click', function() {
                const itemId = this.getAttribute('data-id');
                const itemName = this.getAttribute('data-name');

                Swal.fire({
                    title: 'Delete Item?',
                    text: `Confirming deletion of "${itemName}". This action is irreversible.`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Confirm Delete',
                    background: '#1e1e1e',
                    color: '#fff'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `deleteMenuItem.php?id=${itemId}`;
                    }
                });
            });
        });

        // ══════════════════════════════════════════════════════
        // LOGOUT CONFIRMATION
        // ══════════════════════════════════════════════════════
        document.querySelector('.logout-link').addEventListener('click', function(e) {
            e.preventDefault();
            const logoutUrl = this.getAttribute('href');

            Swal.fire({
                title: 'Ready to Leave?',
                text: 'Are you sure you want to log out of the admin panel?',
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

    <!-- Add Menu Item Modal -->
    <div class="modal fade" id="addMenuItemModal" tabindex="-1" aria-labelledby="addMenuItemLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable menu-item-modal">
            <div class="modal-content">
                <div class="modal-header bg-dark border-warning">
                    <h5 class="modal-title text-primary-gold" id="addMenuItemLabel">
                        <i class="fas fa-plus-circle me-2"></i>Add New Menu Item
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="addMenuItemForm" method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="modal_item_name" class="form-label text-dark">Item Name <span class="text-warning">*</span></label>
                            <input type="text" class="form-control bg-white text-dark border-secondary"
                                id="modal_item_name" name="item_name" required>
                        </div>

                        <div class="mb-3">
                            <label for="modal_description" class="form-label text-dark">Description <span class="text-warning">*</span></label>
                            <textarea class="form-control bg-white text-dark border-secondary"
                                id="modal_description" name="description" rows="3" required></textarea>
                        </div>

                        <div class="row">
                            <div class="col-12 col-md-6 mb-3">
                                <label for="modal_category" class="form-label text-dark">Category <span class="text-warning">*</span></label>
                                <select class="form-select bg-white text-dark border-secondary"
                                    id="modal_category" name="category" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat); ?>">
                                            <?php echo htmlspecialchars($cat); ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="">-- Add New Category --</option>
                                </select>
                                <input type="text" class="form-control bg-white text-dark border-secondary mt-2"
                                    id="modal_new_category" name="new_category" placeholder="Or enter new category name" style="display: none;">
                            </div>

                            <div class="col-12 col-md-6 mb-3">
                                <label for="modal_price" class="form-label text-dark">Price (₱) <span class="text-warning">*</span></label>
                                <input type="number" class="form-control bg-white text-dark border-secondary"
                                    id="modal_price" name="price" step="0.01" min="0" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="modal_image" class="form-label text-dark">Item Image</label>
                            <div class="mb-2 text-center" style="display: none;" id="modal_imagePreviewContainer">
                                <img src="" id="modal_imagePreview" alt="Image Preview" class="img-fluid" style="max-height: 150px; border-radius: 8px; border: 2px solid #D4AF37;">
                            </div>
                            <input type="file" class="form-control bg-white text-dark border-secondary"
                                id="modal_image" name="image" accept="image/*" onchange="previewModalImage(this)">
                        </div>

                        <div class="mb-4 form-check">
                            <input type="checkbox" class="form-check-input" id="modal_is_available"
                                name="is_available" checked>
                            <label class="form-check-label text-dark" for="modal_is_available">
                                Available Now
                            </label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer bg-light border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="submitModalBtn">
                        <i class="fas fa-save me-2"></i> Add Item
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Menu Item Modal -->
    <div class="modal fade" id="editMenuItemModal" tabindex="-1" aria-labelledby="editMenuItemLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable menu-item-modal">
            <div class="modal-content">
                <div class="modal-header bg-dark border-warning">
                    <h5 class="modal-title text-primary-gold" id="editMenuItemLabel">
                        <i class="fas fa-edit me-2"></i>Edit Menu Item
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="editMenuItemForm" method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="edit_item_name" class="form-label text-dark">Item Name <span class="text-warning">*</span></label>
                            <input type="text" class="form-control bg-white text-dark border-secondary"
                                id="edit_item_name" name="item_name" required>
                        </div>

                        <div class="mb-3">
                            <label for="edit_description" class="form-label text-dark">Description <span class="text-warning">*</span></label>
                            <textarea class="form-control bg-white text-dark border-secondary"
                                id="edit_description" name="description" rows="3" required></textarea>
                        </div>

                        <div class="row">
                            <div class="col-12 col-md-6 mb-3">
                                <label for="edit_category" class="form-label text-dark">Category <span class="text-warning">*</span></label>
                                <select class="form-select bg-white text-dark border-secondary" id="edit_category" name="category" required>
                                    <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                                    <?php endforeach; ?>
                                    <option value="">-- Add New Category --</option>
                                </select>
                                <input type="text" class="form-control bg-white text-dark border-secondary mt-2"
                                    id="edit_new_category" name="new_category" placeholder="Or enter new category name" style="display: none;">
                            </div>
                            <div class="col-12 col-md-6 mb-3">
                                <label for="edit_price" class="form-label text-dark">Price (₱) <span class="text-warning">*</span></label>
                                <input type="number" class="form-control bg-white text-dark border-secondary"
                                    id="edit_price" name="price" step="0.01" min="0.01" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-12 col-md-6 mb-3">
                                <label for="edit_stock_quantity" class="form-label text-dark">Current Stock <span class="text-warning">*</span></label>
                                <input type="number" class="form-control bg-white text-dark border-secondary"
                                    id="edit_stock_quantity" name="stock_quantity" min="0" required>
                            </div>
                            <div class="col-12 col-md-6 mb-3">
                                <label for="edit_reorder_level" class="form-label text-dark">Low Stock Threshold <span class="text-warning">*</span></label>
                                <input type="number" class="form-control bg-white text-dark border-secondary"
                                    id="edit_reorder_level" name="reorder_level" min="0" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="edit_image" class="form-label text-dark">Item Image</label>
                            <div class="mb-2 text-center" id="edit_currentImageContainer" style="display: none;">
                                <img src="" id="edit_currentImage" alt="Current item image" class="img-fluid" style="max-height: 150px; border-radius: 8px; border: 2px solid #D4AF37;">
                            </div>
                            <div class="mb-2 text-center" id="edit_imagePreviewContainer" style="display: none;">
                                <img src="" id="edit_imagePreview" alt="New image preview" class="img-fluid" style="max-height: 150px; border-radius: 8px; border: 2px solid #D4AF37;">
                            </div>
                            <input type="file" class="form-control bg-white text-dark border-secondary"
                                id="edit_image" name="image" accept="image/*">
                        </div>

                        <div class="mb-2 form-check">
                            <input type="checkbox" class="form-check-input" id="edit_is_available" name="is_available">
                            <label class="form-check-label text-dark" for="edit_is_available">Available Now</label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer bg-light border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="submitEditModalBtn">
                        <i class="fas fa-save me-2"></i>Update Item
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Handle category selection in modal
        document.getElementById('modal_category').addEventListener('change', function() {
            const newCategoryInput = document.getElementById('modal_new_category');
            if (this.value === '') {
                newCategoryInput.style.display = 'block';
                newCategoryInput.required = true;
            } else {
                newCategoryInput.style.display = 'none';
                newCategoryInput.required = false;
                newCategoryInput.value = '';
            }
        });

        // Preview image in modal
        function previewModalImage(input) {
            const previewContainer = document.getElementById('modal_imagePreviewContainer');
            const preview = document.getElementById('modal_imagePreview');

            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    previewContainer.style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Submit modal form
        document.getElementById('submitModalBtn').addEventListener('click', function() {
            const form = document.getElementById('addMenuItemForm');
            if (form.checkValidity()) {
                const formData = new FormData(form);

                fetch('createMenuItem.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                title: 'Success!',
                                text: 'Menu item added successfully',
                                icon: 'success',
                                confirmButtonColor: '#28a745',
                                background: '#1e1e1e',
                                color: '#fff'
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                title: 'Error',
                                text: data.message || 'Failed to add item',
                                icon: 'error',
                                confirmButtonColor: '#dc3545',
                                background: '#1e1e1e',
                                color: '#fff'
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        Swal.fire({
                            title: 'Error',
                            text: 'An error occurred while adding the item',
                            icon: 'error',
                            confirmButtonColor: '#dc3545',
                            background: '#1e1e1e',
                            color: '#fff'
                        });
                    });
            } else {
                form.reportValidity();
            }
        });

        // Reset form when modal is closed
        document.getElementById('addMenuItemModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('addMenuItemForm').reset();
            document.getElementById('modal_new_category').style.display = 'none';
            document.getElementById('modal_imagePreviewContainer').style.display = 'none';
        });
    </script>
    <script>
        const editMenuItemForm = document.getElementById('editMenuItemForm');
        const editCategory = document.getElementById('edit_category');
        const editNewCategory = document.getElementById('edit_new_category');
        const editCurrentImage = document.getElementById('edit_currentImage');
        const editCurrentImageContainer = document.getElementById('edit_currentImageContainer');
        const editImagePreview = document.getElementById('edit_imagePreview');
        const editImagePreviewContainer = document.getElementById('edit_imagePreviewContainer');
        const editImageInput = document.getElementById('edit_image');
        const submitEditModalBtn = document.getElementById('submitEditModalBtn');

        document.querySelectorAll('.edit-menu-item-btn').forEach(button => {
            button.addEventListener('click', function() {
                const item = JSON.parse(this.dataset.item);
                editMenuItemForm.reset();
                editMenuItemForm.action = `editMenuItem.php?id=${encodeURIComponent(item.id)}`;
                document.getElementById('edit_item_name').value = item.name;
                document.getElementById('edit_description').value = item.description;
                document.getElementById('edit_price').value = item.price;
                document.getElementById('edit_stock_quantity').value = item.stock_quantity;
                document.getElementById('edit_reorder_level').value = item.reorder_level;
                document.getElementById('edit_is_available').checked = item.is_available;

                let categoryOption = Array.from(editCategory.options).find(option => option.value === item.category);
                if (!categoryOption) {
                    categoryOption = new Option(item.category, item.category);
                    editCategory.add(categoryOption, 0);
                }
                editCategory.value = item.category;
                editNewCategory.style.display = 'none';
                editNewCategory.required = false;
                editNewCategory.value = '';

                const imageUrl = (item.image_url || '').replace(/^\/+/, '');
                if (imageUrl) {
                    editCurrentImage.src = `../../${imageUrl}`;
                    editCurrentImageContainer.style.display = 'block';
                } else {
                    editCurrentImage.removeAttribute('src');
                    editCurrentImageContainer.style.display = 'none';
                }
                editImagePreview.removeAttribute('src');
                editImagePreviewContainer.style.display = 'none';
            });
        });

        editCategory.addEventListener('change', function() {
            const isNewCategory = this.value === '';
            editNewCategory.style.display = isNewCategory ? 'block' : 'none';
            editNewCategory.required = isNewCategory;
            if (!isNewCategory) editNewCategory.value = '';
        });

        editImageInput.addEventListener('change', function() {
            if (!this.files || !this.files[0]) {
                editImagePreview.removeAttribute('src');
                editImagePreviewContainer.style.display = 'none';
                return;
            }

            const reader = new FileReader();
            reader.onload = event => {
                editImagePreview.src = event.target.result;
                editImagePreviewContainer.style.display = 'block';
                editCurrentImageContainer.style.display = 'none';
            };
            reader.readAsDataURL(this.files[0]);
        });

        submitEditModalBtn.addEventListener('click', function() {
            if (!editMenuItemForm.checkValidity()) {
                editMenuItemForm.reportValidity();
                return;
            }

            const itemName = document.getElementById('edit_item_name').value.trim();
            Swal.fire({
                title: 'Save Changes?',
                text: `Update ${itemName}?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0d6efd',
                cancelButtonColor: '#6c757d',
                confirmButtonText: '<i class="fas fa-save me-1"></i> Update Item',
                background: '#1e1e1e',
                color: '#fff'
            }).then(result => {
                if (!result.isConfirmed) return;

                submitEditModalBtn.disabled = true;
                fetch(editMenuItemForm.action, {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        body: new FormData(editMenuItemForm)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) throw new Error(data.message || 'Failed to update item.');
                        bootstrap.Modal.getInstance(document.getElementById('editMenuItemModal')).hide();
                        return Swal.fire({
                            title: 'Updated!',
                            text: 'The menu item has been updated successfully.',
                            icon: 'success',
                            timer: 1400,
                            showConfirmButton: false,
                            background: '#1e1e1e',
                            color: '#fff'
                        });
                    })
                    .then(() => location.reload())
                    .catch(error => {
                        Swal.fire({
                            title: 'Unable to Update Item',
                            text: error.message,
                            icon: 'error',
                            confirmButtonColor: '#dc3545',
                            background: '#1e1e1e',
                            color: '#fff'
                        });
                    })
                    .finally(() => {
                        submitEditModalBtn.disabled = false;
                    });
            });
        });

        document.getElementById('editMenuItemModal').addEventListener('hidden.bs.modal', function() {
            editMenuItemForm.reset();
            editNewCategory.style.display = 'none';
            editNewCategory.required = false;
            editCurrentImageContainer.style.display = 'none';
            editImagePreviewContainer.style.display = 'none';
        });
    </script>
</body>

</html>
