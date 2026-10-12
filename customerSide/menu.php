<?php
require_once '../config/config.php';
require_once '../config/Database.php';
require_once 'stock_availability.php';

if (!isLoggedIn() || !isCustomer()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
$cart_count = array_sum($_SESSION['cart']);
$buy_now_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buy_now') {
    $item_id = max(0, (int)($_POST['item_id'] ?? 0));
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));

    if ($item_id > 0) {
        $buyNowStmt = $db->prepare("SELECT item_id, item_name, stock_quantity FROM menu_items WHERE item_id = :item_id AND is_available = TRUE LIMIT 1");
        $buyNowStmt->execute([':item_id' => $item_id]);
        $buyNowItem = $buyNowStmt->fetch(PDO::FETCH_ASSOC);

        if ($buyNowItem) {
            $availability = getOrderableStock($db, $item_id, (int)$_SESSION['user_id'], (int)($buyNowItem['stock_quantity'] ?? 0));
            $availableStock = (int)$availability['available'];
            if ($availableStock <= 0) {
                $buy_now_error = stockReservationMessage($buyNowItem['item_name'], $availability);
            } elseif ($quantity > $availableStock) {
                $buy_now_error = stockReservationMessage($buyNowItem['item_name'], $availability);
            } else {
                $_SESSION['checkout_items'] = [$item_id => $quantity];
                $_SESSION['is_buy_now'] = true;
                header('Location: checkout.php');
                exit;
            }
        } else {
            $buy_now_error = 'This menu item is no longer available.';
        }
    }
}

/* ── FILTERS ──────────────────────────────────────────────── */
$category_filter = $_GET['category'] ?? 'all';
$search_query    = trim($_GET['search'] ?? '');

/* ── PAGINATION ───────────────────────────────────────────── */
$allowedPerPage = [6, 9, 12, 18, 24];
$items_per_page = (int) ($_GET['per_page'] ?? 9);
if (!in_array($items_per_page, $allowedPerPage, true)) $items_per_page = 9;
$current_page   = max(1, (int)($_GET['page'] ?? 1));

/* ── COUNT TOTAL (for pagination maths) ──────────────────── */
$count_sql = "SELECT COUNT(*) FROM menu_items WHERE is_available = TRUE";
$count_params = [];
if ($category_filter !== 'all') {
    $count_sql .= " AND category = :category";
    $count_params[':category'] = $category_filter;
}
if (!empty($search_query)) {
    $count_sql .= " AND (item_name LIKE :s1 OR description LIKE :s2)";
    $term = "%$search_query%";
    $count_params[':s1'] = $term;
    $count_params[':s2'] = $term;
}
$count_stmt = $db->prepare($count_sql);
$count_stmt->execute($count_params);
$total_items  = (int)$count_stmt->fetchColumn();
$total_pages  = max(1, (int)ceil($total_items / $items_per_page));
$current_page = min($current_page, $total_pages);  // clamp
$offset       = ($current_page - 1) * $items_per_page;

/* ── FETCH PAGE OF ITEMS ──────────────────────────────────── */
$query = "SELECT *, (SELECT COUNT(*) FROM favorites f WHERE f.item_id = menu_items.item_id) as favorite_count
          FROM menu_items WHERE is_available = TRUE";
if ($category_filter !== 'all') $query .= " AND category = :category";
if (!empty($search_query))       $query .= " AND (item_name LIKE :s1 OR description LIKE :s2)";
$query .= " ORDER BY category, item_name LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($query);
if ($category_filter !== 'all') $stmt->bindParam(':category', $category_filter);
if (!empty($search_query)) {
    $stmt->bindParam(':s1', $term);
    $stmt->bindParam(':s2', $term);
}
$stmt->bindValue(':limit',  $items_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,         PDO::PARAM_INT);
$stmt->execute();
$menu_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ── CATEGORIES ───────────────────────────────────────────── */
$categories = $db->query("SELECT DISTINCT category FROM menu_items WHERE is_available = TRUE ORDER BY category")
    ->fetchAll(PDO::FETCH_COLUMN);

/* ── USER FAVORITES ───────────────────────────────────────── */
$favStmt = $db->prepare("SELECT item_id FROM favorites WHERE member_id = :mid");
$favStmt->execute([':mid' => $_SESSION['user_id']]);
$favorites = $favStmt->fetchAll(PDO::FETCH_COLUMN);

/* ── ACTIVE PROMOTIONS ────────────────────────────────────── */
$promoStmt = $db->prepare("
    SELECT p.* FROM promotions p
    WHERE p.is_active=1 AND p.start_date<=CURDATE() AND p.end_date>=CURDATE()
    AND (p.is_single_use=0 OR NOT EXISTS (
        SELECT 1 FROM online_orders o WHERE o.promo_code=p.promotion_name AND o.member_id=:mid
    ))");
$promoStmt->execute([':mid' => $_SESSION['user_id']]);
$activePromotions = $promoStmt->fetchAll(PDO::FETCH_ASSOC);

function getPromoBadgesHtml($category, $activePromotions)
{
    $badges = [];
    foreach ($activePromotions as $promo) {
        $cats = empty($promo['applicable_category']) ? [] : array_map('strtolower', array_map('trim', explode(',', $promo['applicable_category'])));
        if (empty($cats) || in_array(strtolower(trim($category)), $cats, true)) {
            $badges[] = '<span class="promo-badge"><i class="fas fa-tag"></i> ' . htmlspecialchars($promo['promotion_name']) . '</span>';
        }
    }

    if (empty($badges)) {
        return '';
    }

    return '<div class="promo-badge-stack">' . implode('', $badges) . '</div>';
}

/* ── GROUP BY CATEGORY (current page only) ────────────────── */
$grouped = [];
foreach ($menu_items as $item) $grouped[$item['category']][] = $item;

/* ── PAGINATION URL HELPER ────────────────────────────────── */
function pageUrl($page, $category_filter, $search_query, $items_per_page)
{
    $p = ['page' => $page];
    if ($category_filter !== 'all') $p['category'] = $category_filter;
    if (!empty($search_query))       $p['search']   = $search_query;
    if ($items_per_page !== 9)       $p['per_page'] = $items_per_page;
    return 'menu.php?' . http_build_query($p);
}

/* ── DASHBOARD URL ────────────────────────────────────────── */
$role = $_SESSION['user_role'] ?? 'customer';
$dashboardUrl = '../customerSide/customer-dashboard.php';
if ($role === 'staff') $dashboardUrl = '../staffSide/staff-dashboard.php';
elseif ($role === 'admin') $dashboardUrl = '../adminSide/admin-dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Menu — La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../style.css">
    <style>
        /* ── BUTTON TRANSITIONS ─────────────────────────────── */
        .btn-favorite-ajax,
        .add-to-cart-form button,
        .buy-now-form button {
            transition: all .3s ease;
        }

        .btn-favorite-ajax:active,
        .add-to-cart-form button:active,
        .buy-now-form button:active {
            transform: scale(.95);
        }

        /* ── LIVE SEARCH SPINNER ────────────────────────────── */
        #live-search-spinner {
            display: none;
            text-align: center;
            padding: 60px 20px;
            color: var(--primary-color, #9C7D4A);
            font-size: 1.2rem;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(16px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .menu-category {
            animation: fadeInUp .3s ease both;
        }

        /* ── PAGINATION ─────────────────────────────────────── */
        .pagination-wrap {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 48px;
            padding-bottom: 24px;
        }

        .pagination-info {
            font-size: .82rem;
            color: #aaa;
            text-align: center;
            width: 100%;
            margin-bottom: 2px;
        }

        .pg-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            height: 38px;
            padding: 0 10px;
            border-radius: 8px;
            border: 1px solid #404040;
            background: #1e1e1e;
            color: #e0e0e0;
            font-size: .85rem;
            font-weight: 600;
            text-decoration: none;
            transition: all .2s;
            cursor: pointer;
            user-select: none;
        }

        .pg-btn:hover:not(.disabled):not(.active) {
            background: rgba(212, 175, 55, .12);
            border-color: #d4af37;
            color: #d4af37;
        }

        .pg-btn.active {
            background: #d4af37;
            border-color: #d4af37;
            color: #111;
            pointer-events: none;
        }

        .pg-btn.disabled {
            opacity: .35;
            pointer-events: none;
            cursor: default;
        }

        .pg-ellipsis {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 30px;
            height: 38px;
            color: #666;
            font-size: .9rem;
        }

        /* ── ITEMS-PER-PAGE SELECTOR ────────────────────────── */
        .per-page-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
            margin-top: 14px;
            font-size: .8rem;
            color: #aaa;
        }

        .per-page-select {
            background: #1e1e1e;
            border: 1px solid #404040;
            color: #e0e0e0;
            border-radius: 6px;
            padding: 3px 8px;
            font-size: .8rem;
            cursor: pointer;
        }

        .per-page-select:focus {
            outline: none;
            border-color: #d4af37;
        }

        /* ── RESPONSIVE TWEAKS ──────────────────────────────── */
        @media (max-width: 480px) {
            .pg-btn {
                min-width: 34px;
                height: 34px;
                font-size: .78rem;
            }
        }

        .dish-image-container,
        .modal-image-container {
            position: relative;
            overflow: hidden;
        }

        .promo-badge-stack {
            position: absolute;
            top: 12px;
            left: 12px;
            z-index: 3;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 6px;
            max-width: calc(100% - 24px);
        }

        .promo-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: linear-gradient(135deg, #ff5a6e, #e62e4d);
            color: #fff;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .03em;
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.28);
            white-space: nowrap;
        }

        .promo-badge i {
            font-size: .68rem;
        }

        #modalDishPromo {
            top: 16px;
            left: 16px;
        }

        #modalDishPromo:empty,
        .promo-badge-stack:empty {
            display: none;
        }
    </style>
    <link rel="stylesheet" href="customer-theme.css">
</head>

<body class="customer-menu-page">

    <!-- ── NAVBAR ───────────────────────────────────────────────── -->
    <?php
    $customerNavPage = 'menu.php';
    $customerNavDashboardUrl = $dashboardUrl;
    require 'customer-navbar.php';
    ?>

    <!-- ── MENU SECTION ─────────────────────────────────────────── -->
    <section class="menu-section">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="display-5 mb-3">Our Culinary Masterpieces</h2>
                <p class="lead text-primary-gold mb-4">A carefully curated selection of timeless classics and modern innovations</p>

                <!-- Search -->
                <div class="search-container mx-auto mb-4">
                    <form method="GET" class="search-box" id="search-form">
                        <?php if ($category_filter !== 'all'): ?>
                            <input type="hidden" name="category" value="<?php echo htmlspecialchars($category_filter); ?>">
                        <?php endif; ?>
                        <i class="fas fa-search search-icon"></i>
                        <input type="text" name="search" id="live-search-input" class="search-input"
                            placeholder="Search menu..." value="<?php echo htmlspecialchars($search_query); ?>" autocomplete="off">
                        <button type="button" class="search-clear <?php echo !empty($search_query) ? 'visible' : ''; ?>" id="search-clear-btn">
                            <i class="fas fa-times"></i>
                        </button>
                    </form>
                </div>

                <!-- Category filters -->
                <div class="menu-filters">
                    <a href="menu.php<?php echo !empty($search_query) ? '?search=' . urlencode($search_query) : ''; ?>"
                        class="filter-btn <?php echo $category_filter === 'all' ? 'active' : ''; ?>">All Dishes</a>
                    <?php foreach ($categories as $cat): ?>
                        <?php
                        $fp = ['category' => $cat];
                        if (!empty($search_query)) $fp['search'] = $search_query;
                        ?>
                        <a href="menu.php?<?php echo htmlspecialchars(http_build_query($fp)); ?>"
                            class="filter-btn <?php echo $category_filter === $cat ? 'active' : ''; ?>">
                            <?php echo htmlspecialchars($cat); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Live search spinner -->
            <div id="live-search-spinner">
                <i class="fas fa-spinner fa-spin me-2"></i> Searching...
            </div>

            <?php if (!empty($buy_now_error)): ?>
                <div class="alert alert-warning border-0 mx-auto mb-4" style="max-width: 760px; background: rgba(212,175,55,.12); color: #f5f5f5;">
                    <i class="fas fa-exclamation-triangle me-2 text-warning"></i>
                    <?php echo htmlspecialchars($buy_now_error); ?>
                </div>
            <?php endif; ?>

            <!-- Results container -->
            <div id="menu-results-container">

                <?php if (empty($menu_items)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-search fa-3x text-primary-gold mb-3" style="opacity:.3;"></i>
                        <h4 class="text-primary-gold">No items found</h4>
                        <p class="lead">Try adjusting your search or filter criteria</p>
                        <a href="menu.php" class="luxury-btn mt-3">View All Menu</a>
                    </div>
                <?php else: ?>

                    <?php foreach ($grouped as $category => $items): ?>
                        <div class="menu-category">
                            <h3 class="menu-category-title text-center"><?php echo htmlspecialchars($category); ?></h3>
                            <div class="row g-3">
                                <?php foreach ($items as $item):
                                    $imageUrl  = !empty($item['image_url']) ? '../' . htmlspecialchars($item['image_url']) : '../images/placeholder.png';
                                    $isFavorite = in_array($item['item_id'], $favorites);
                                ?>
                                    <div class="col-12 col-md-6 col-lg-4">
                                        <div class="dish-card"
                                            data-dish-id="<?php echo $item['item_id']; ?>"
                                            data-stock="<?php echo max(0, (int) ($item['stock_quantity'] ?? 0)); ?>">
                                            <div class="dish-image-container"
                                                data-dish-id="<?php echo $item['item_id']; ?>"
                                                role="button" tabindex="0"
                                                aria-label="View details for <?php echo htmlspecialchars($item['item_name']); ?>">
                                                <?php echo getPromoBadgesHtml($item['category'], $activePromotions); ?>
                                                <img src="<?php echo $imageUrl; ?>"
                                                    alt="<?php echo htmlspecialchars($item['item_name']); ?>"
                                                    class="dish-image"
                                                    onerror="this.src='../images/placeholder.png'">
                                                <div class="image-overlay"><i class="fas fa-expand-alt"></i> View Details</div>
                                            </div>
                                            <div class="dish-content">
                                                <div class="dish-header">
                                                    <h4 class="dish-name"><?php echo htmlspecialchars($item['item_name']); ?></h4>
                                                    <div class="d-flex flex-column align-items-end">
                                                        <span class="dish-price">₱<?php echo number_format($item['price'], 2); ?></span>
                                                    </div>
                                                </div>
                                                <p class="dish-description"><?php echo htmlspecialchars($item['description']); ?></p>
                                                <div class="d-flex gap-2 mt-3">
                                                    <form method="POST" class="flex-grow-1 add-to-cart-form">
                                                        <input type="hidden" name="action" value="add_to_cart">
                                                        <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                                        <input type="hidden" name="quantity" value="1">
                                                        <button type="submit" class="btn btn-sm"
                                                            style="background:var(--primary-color,#9C7D4A);color:#fff;width:100%;border:none;">
                                                            <i class="fas fa-cart-plus me-1"></i> Add to Cart
                                                        </button>
                                                    </form>
                                                    <form method="POST" class="flex-grow-1 buy-now-form">
                                                        <input type="hidden" name="action" value="buy_now">
                                                        <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                                                        <input type="hidden" name="quantity" value="1">
                                                        <button type="submit" class="btn btn-sm"
                                                            style="background:#198754;color:#fff;width:100%;border:none;">
                                                            <i class="fas fa-bolt me-1"></i> Buy Now
                                                        </button>
                                                    </form>
                                                    <button type="button" class="btn btn-sm btn-favorite-ajax"
                                                        data-item-id="<?php echo $item['item_id']; ?>"
                                                        style="background:<?php echo $isFavorite ? '#c82333' : 'rgba(156,125,74,.3)'; ?>;
                                                   color:#fff;border:none;display:flex;align-items:center;
                                                   justify-content:center;gap:6px;padding:0 12px;">
                                                        <i class="fa-heart <?php echo $isFavorite ? 'fas' : 'far'; ?>"></i>
                                                        <span id="fav-count-<?php echo $item['item_id']; ?>" style="font-weight:600;">
                                                            <?php echo $item['favorite_count']; ?>
                                                        </span>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- ── PAGINATION ────────────────────────────── -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-wrap" id="pagination-wrap">

                            <div class="pagination-info">
                                Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $items_per_page, $total_items); ?>
                                of <?php echo $total_items; ?> items
                                <?php if ($category_filter !== 'all'): ?>
                                    in <strong><?php echo htmlspecialchars($category_filter); ?></strong>
                                <?php endif; ?>
                            </div>

                            <!-- Prev -->
                            <?php if ($current_page > 1): ?>
                                <a class="pg-btn" href="<?php echo pageUrl($current_page - 1, $category_filter, $search_query, $items_per_page); ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="pg-btn disabled"><i class="fas fa-chevron-left"></i></span>
                            <?php endif; ?>

                            <?php
                            // Build smart page number list with ellipsis
                            $window  = 2; // pages around current
                            $pages   = [];
                            for ($i = 1; $i <= $total_pages; $i++) {
                                if (
                                    $i === 1 || $i === $total_pages
                                    || ($i >= $current_page - $window && $i <= $current_page + $window)
                                ) {
                                    $pages[] = $i;
                                }
                            }
                            $prev = null;
                            foreach ($pages as $p):
                                if ($prev !== null && $p - $prev > 1):
                            ?>
                                    <span class="pg-ellipsis">…</span>
                                <?php
                                endif;
                                if ($p === $current_page):
                                ?>
                                    <span class="pg-btn active"><?php echo $p; ?></span>
                                <?php else: ?>
                                    <a class="pg-btn" href="<?php echo pageUrl($p, $category_filter, $search_query, $items_per_page); ?>"><?php echo $p; ?></a>
                            <?php
                                endif;
                                $prev = $p;
                            endforeach;
                            ?>

                            <!-- Next -->
                            <?php if ($current_page < $total_pages): ?>
                                <a class="pg-btn" href="<?php echo pageUrl($current_page + 1, $category_filter, $search_query, $items_per_page); ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="pg-btn disabled"><i class="fas fa-chevron-right"></i></span>
                            <?php endif; ?>

                            <!-- Items per page selector -->
                            <div class="per-page-wrap w-100">
                                <label for="per-page-select">Items per page:</label>
                                <select class="per-page-select" id="per-page-select">
                                    <?php foreach ([6, 9, 12, 18, 24] as $n): ?>
                                        <option value="<?php echo $n; ?>" <?php echo $n == $items_per_page ? 'selected' : ''; ?>>
                                            <?php echo $n; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php endif; ?>

            </div><!-- /#menu-results-container -->
        </div>
    </section>

    <!-- ── DISH MODAL ────────────────────────────────────────────── -->
    <div class="modal fade" id="dishModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="dishModalLabel">Dish Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="modal-image-container">
                                <div id="modalDishPromo" class="promo-badge-stack"></div>
                                <img id="modalDishImage" src="" alt="" class="img-fluid">
                                <div class="modal-placeholder" id="modalPlaceholder">
                                    <i class="fas fa-image fa-4x mb-3"></i>
                                    <p>No image available</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="modal-dish-details">
                                <h3 id="modalDishName" class="text-primary-gold mb-3"></h3>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h4 id="modalDishPrice" class="text-accent m-0"></h4>
                                </div>
                                <p id="modalDishDescription" class="mb-4"></p>
                                <div class="dish-actions mt-4">
                                    <form method="POST" id="addToCartForm" class="mb-2">
                                        <input type="hidden" name="action" value="add_to_cart">
                                        <input type="hidden" id="modalItemId" name="item_id" value="">
                                        <div class="input-group mb-3">
                                            <input type="number" id="modalQuantity" name="quantity" value="1" min="1"
                                                class="form-control" placeholder="Qty">
                                            <button class="luxury-btn" id="modalAddToCartBtn" type="submit" style="padding:10px 25px;">
                                                <i class="fas fa-plus-circle me-2"></i> Add to Order
                                            </button>
                                        </div>
                                        <div id="modalStockNote" class="small text-light-emphasis"></div>
                                    </form>
                                    <button type="button" id="favoritesBtn" class="btn btn-sm w-100 mt-3"
                                        style="background: rgba(156, 125, 74, 0.3); color: #fff; border: none;">
                                        <i id="favBtnIcon" class="far fa-heart me-2"></i>
                                        <span id="favBtnText">Add to Favorites</span>
                                        <span id="modalFavCount" class="ms-2"></span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.menuCartQuantities = <?php echo json_encode(array_map('intval', $_SESSION['cart']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    </script>
    <script src="customer-menu.js?v=<?php echo filemtime(__DIR__ . '/customer-menu.js'); ?>"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        /* ── CART AJAX ────────────────────────────────────────────── */
        function showCartFeedback(title, text, icon = 'warning') {
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title,
                    text,
                    icon,
                    confirmButtonColor: '#9C7D4A',
                    background: '#1e1e1e',
                    color: '#fff'
                });
                return;
            }
            alert(text);
        }

        function bindCartForms() {
            document.querySelectorAll('.add-to-cart-form, #addToCartForm').forEach(form => {
                if (form._cartBound) return;
                form._cartBound = true;
                form.addEventListener('submit', function(e) {
                    e.preventDefault();

                    const qtyInput = this.querySelector('input[name="quantity"]');
                    const itemId = this.querySelector('input[name="item_id"]')?.value || '';
                    const parsedQty = parseInt(qtyInput?.value || '1', 10);
                    const requestedQty = Number.isNaN(parsedQty) ? 1 : parsedQty;
                    const submittedQty = requestedQty;
                    const maxQty = parseInt(qtyInput?.getAttribute('max') || '', 10);

                    if (qtyInput) {
                        if (!Number.isNaN(maxQty) && requestedQty > maxQty) {
                            qtyInput.value = Math.max(0, maxQty);
                            showCartFeedback(
                                'Stock Limit Reached',
                                maxQty > 0 ?
                                `You can only add ${maxQty} more item(s) of this menu item right now.` :
                                'This item has already reached the available stock limit in your cart.'
                            );
                            if (typeof window.refreshCurrentModalStockState === 'function') {
                                window.refreshCurrentModalStockState();
                            }
                            return;
                        }

                        if (requestedQty <= 0) {
                            showCartFeedback('Invalid Quantity', 'Please enter at least 1 item.', 'error');
                            qtyInput.value = Math.max(1, maxQty || 1);
                            return;
                        }
                    }

                    const btn = this.querySelector('button[type="submit"]');
                    const origText = btn.innerHTML;
                    const origBg = btn.style.backgroundColor;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Adding...';

                    fetch('add_to_cart_ajax.php', {
                            method: 'POST',
                            body: new FormData(this)
                        })
                        .then(r => r.json())
                        .then(data => {
                            const badge = document.getElementById('nav-cart-count');
                            if (badge && typeof data.cart_count !== 'undefined') {
                                badge.textContent = data.cart_count;
                            }
                            if (itemId && typeof data.available_stock !== 'undefined' && typeof window.setDishStockQuantity === 'function') {
                                window.setDishStockQuantity(itemId, data.available_stock);
                            }
                            if (itemId) {
                                window.menuCartQuantities[itemId] = parseInt(data.item_cart_qty ?? window.menuCartQuantities[itemId] ?? 0, 10) || 0;
                            }
                            if (typeof window.refreshCurrentModalStockState === 'function') {
                                window.refreshCurrentModalStockState();
                            }

                            if (data.success) {
                                if (data.adjusted) {
                                    btn.disabled = false;
                                    btn.innerHTML = origText;
                                    btn.style.backgroundColor = origBg;
                                    showCartFeedback('Cart Adjusted', data.message, 'warning');
                                    return;
                                }

                                btn.innerHTML = '<i class="fas fa-check me-1"></i> Added!';
                                btn.style.backgroundColor = '#28a745';
                                setTimeout(() => {
                                    btn.disabled = false;
                                    btn.innerHTML = origText;
                                    btn.style.backgroundColor = origBg;
                                }, 2000);

                                if (this.id === 'addToCartForm' && qtyInput) {
                                    if (typeof showSuccessMessage === 'function') {
                                        const addedQty = parseInt(data.added_quantity ?? submittedQty, 10) || submittedQty;
                                        showSuccessMessage(`Added to cart! (Quantity: ${addedQty})`);
                                    }
                                    setTimeout(() => {
                                        const m = bootstrap.Modal.getInstance(document.getElementById('dishModal'));
                                        if (m) m.hide();
                                    }, 1000);
                                }
                            } else if (data.redirect) {
                                window.location.href = data.redirect;
                            } else {
                                btn.disabled = false;
                                btn.innerHTML = origText;
                                btn.style.backgroundColor = origBg;
                                showCartFeedback('Unable to Add Item', data.error || 'Unable to update the cart right now.');
                            }
                        })
                        .catch(() => {
                            btn.disabled = false;
                            btn.innerHTML = origText;
                            btn.style.backgroundColor = origBg;
                            showCartFeedback('Connection Error', 'Please try adding the item again.', 'error');
                        });
                });
            });
        }

        /* ── FAVORITE AJAX ────────────────────────────────────────── */
        function bindFavoriteButtons() {
            document.querySelectorAll('.btn-favorite-ajax').forEach(btn => {
                if (btn._favBound) return;
                btn._favBound = true;
                btn.addEventListener('click', function() {
                    const itemId = this.getAttribute('data-item-id');
                    const icon = this.querySelector('i');
                    const countSpan = document.getElementById(`fav-count-${itemId}`);
                    this.disabled = true;
                    fetch('toggle_favorite.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: `item_id=${itemId}`
                        })
                        .then(r => r.json())
                        .then(data => {
                            this.disabled = false;
                            if (data.success) {
                                if (data.action === 'added') {
                                    this.style.backgroundColor = '#c82333';
                                    icon.classList.replace('far', 'fas');
                                } else {
                                    this.style.backgroundColor = 'rgba(156,125,74,.3)';
                                    icon.classList.replace('fas', 'far');
                                }
                                countSpan.textContent = data.new_count;
                                const nb = document.getElementById('nav-fav-count');
                                if (nb) nb.textContent = data.user_total;
                                if (typeof window.reinitializeMenuCards === 'function') window.reinitializeMenuCards();
                            } else if (data.redirect) window.location.href = data.redirect;
                        })
                        .catch(() => {
                            this.disabled = false;
                        });
                });
            });
        }

        bindCartForms();
        bindFavoriteButtons();

        /* ── LIVE SEARCH ──────────────────────────────────────────── */
        (function() {
            const input = document.getElementById('live-search-input');
            const clearBtn = document.getElementById('search-clear-btn');
            const form = document.getElementById('search-form');
            const container = document.getElementById('menu-results-container');
            const spinner = document.getElementById('live-search-spinner');
            const categoryFilter = new URLSearchParams(window.location.search).get('category') || 'all';
            let debounceTimer = null,
                latestRequestId = 0;

            function escHtml(str) {
                return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function formatPrice(p) {
                return parseFloat(p).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            }

            function updateUrl(value) {
                const params = new URLSearchParams(window.location.search);
                if (categoryFilter && categoryFilter !== 'all') params.set('category', categoryFilter);
                else params.delete('category');
                params.delete('page'); // reset to page 1 on search
                if (value) params.set('search', value);
                else params.delete('search');
                window.history.replaceState({}, '', `${window.location.pathname}${params.toString() ? '?' + params.toString() : ''}`);
            }

            function buildCard(item, favorites, promoBadgesHtml) {
                const isFav = favorites.includes(item.item_id) || favorites.includes(String(item.item_id));
                const imageUrl = item.image_url ? `../${item.image_url}` : '../images/placeholder.png';
                return `<div class="col-12 col-md-6 col-lg-4">
            <div class="dish-card" data-dish-id="${item.item_id}" data-stock="${Number(item.stock_quantity ?? 0)}">
                <div class="dish-image-container" data-dish-id="${item.item_id}" role="button" tabindex="0"
                     aria-label="View details for ${escHtml(item.item_name)}">
                    ${promoBadgesHtml || ''}
                    <img src="${imageUrl}" alt="${escHtml(item.item_name)}" class="dish-image" onerror="this.src='../images/placeholder.png'">
                    <div class="image-overlay"><i class="fas fa-expand-alt"></i> View Details</div>
                </div>
                <div class="dish-content">
                    <div class="dish-header">
                        <h4 class="dish-name">${escHtml(item.item_name)}</h4>
                        <div class="d-flex flex-column align-items-end">
                            <span class="dish-price">₱${formatPrice(item.price)}</span>
                        </div>
                    </div>
                    <p class="dish-description">${escHtml(item.description)}</p>
                    <div class="d-flex gap-2 mt-3">
                        <form method="POST" class="flex-grow-1 add-to-cart-form">
                            <input type="hidden" name="action" value="add_to_cart">
                            <input type="hidden" name="item_id" value="${item.item_id}">
                            <input type="hidden" name="quantity" value="1">
                            <button type="submit" class="btn btn-sm"
                                style="background:var(--primary-color,#9C7D4A);color:#fff;width:100%;border:none;">
                                <i class="fas fa-cart-plus me-1"></i> Add to Cart
                            </button>
                        </form>
                        <form method="POST" class="flex-grow-1 buy-now-form">
                            <input type="hidden" name="action" value="buy_now">
                            <input type="hidden" name="item_id" value="${item.item_id}">
                            <input type="hidden" name="quantity" value="1">
                            <button type="submit" class="btn btn-sm"
                                style="background:#198754;color:#fff;width:100%;border:none;">
                                <i class="fas fa-bolt me-1"></i> Buy Now
                            </button>
                        </form>
                        <button type="button" class="btn btn-sm btn-favorite-ajax" data-item-id="${item.item_id}"
                            style="background:${isFav?'#c82333':'rgba(156,125,74,.3)'};color:#fff;border:none;
                                   display:flex;align-items:center;justify-content:center;gap:6px;padding:0 12px;">
                            <i class="${isFav?'fas':'far'} fa-heart"></i>
                            <span id="fav-count-${item.item_id}" style="font-weight:600;">${item.favorite_count}</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>`;
            }

            function buildPagination(meta) {
                if (!meta || meta.total_pages <= 1) return '';
                const {
                    current_page,
                    total_pages,
                    total_items,
                    offset,
                    items_per_page,
                    category,
                    search
                } = meta;
                const shown_to = Math.min(offset + items_per_page, total_items);

                function pUrl(p) {
                    const q = new URLSearchParams();
                    if (p > 1) q.set('page', p);
                    if (category && category !== 'all') q.set('category', category);
                    if (search) q.set('search', search);
                    if (items_per_page && Number(items_per_page) !== 9) q.set('per_page', items_per_page);
                    return 'menu.php' + (q.toString() ? '?' + q.toString() : '');
                }

                let html = `<div class="pagination-wrap" id="pagination-wrap">
            <div class="pagination-info">
                Showing ${offset + 1}–${shown_to} of ${total_items} items
                ${category && category !== 'all' ? `in <strong>${escHtml(category)}</strong>` : ''}
            </div>`;

                // Prev
                html += current_page > 1 ?
                    `<a class="pg-btn" href="${pUrl(current_page - 1)}"><i class="fas fa-chevron-left"></i></a>` :
                    `<span class="pg-btn disabled"><i class="fas fa-chevron-left"></i></span>`;

                // Pages with ellipsis
                const window_size = 2;
                let prev = null;
                for (let i = 1; i <= total_pages; i++) {
                    if (i === 1 || i === total_pages || (i >= current_page - window_size && i <= current_page + window_size)) {
                        if (prev !== null && i - prev > 1) html += `<span class="pg-ellipsis">…</span>`;
                        if (i === current_page) {
                            html += `<span class="pg-btn active">${i}</span>`;
                        } else {
                            html += `<a class="pg-btn" href="${pUrl(i)}">${i}</a>`;
                        }
                        prev = i;
                    }
                }

                // Next
                html += current_page < total_pages ?
                    `<a class="pg-btn" href="${pUrl(current_page + 1)}"><i class="fas fa-chevron-right"></i></a>` :
                    `<span class="pg-btn disabled"><i class="fas fa-chevron-right"></i></span>`;

                html += `<div class="per-page-wrap w-100"><label>Items per page:</label>
            <select class="per-page-select" id="per-page-select">
                ${[6,9,12,18,24].map(n => `<option value="${n}"${n==items_per_page?' selected':''}>${n}</option>`).join('')}
            </select></div>`;
                html += '</div>';
                return html;
            }

            function renderResults(data) {
                spinner.style.display = 'none';
                container.style.display = '';
                if (data.total === 0) {
                    container.innerHTML = `<div class="text-center py-5">
                <i class="fas fa-search fa-3x text-primary-gold mb-3" style="opacity:.3;"></i>
                <h4 class="text-primary-gold">No items found</h4>
                <p class="lead">Try adjusting your search or filter criteria</p>
                <a href="menu.php" class="luxury-btn mt-3">View All Menu</a>
            </div>`;
                    return;
                }
                let html = '';
                for (const [category, items] of Object.entries(data.grouped)) {
                    const promoBadgesHtml = data.promo_badges?.[category] || '';
                    html += `<div class="menu-category">
                <h3 class="menu-category-title text-center">${escHtml(category)}</h3>
                <div class="row g-3">`;
                    items.forEach(item => {
                        html += buildCard(item, data.favorites, promoBadgesHtml);
                    });
                    html += `</div></div>`;
                }
                html += buildPagination(data.pagination);
                container.innerHTML = html;
                bindCartForms();
                bindFavoriteButtons();
                bindPerPageSelect();
                if (typeof window.reinitializeMenuCards === 'function') window.reinitializeMenuCards();
            }

            function doSearch(value) {
                const requestId = ++latestRequestId;
                const perPageSelect = document.getElementById('per-page-select');
                const perPage = perPageSelect ? perPageSelect.value : '9';
                container.style.display = 'none';
                spinner.style.display = 'block';
                updateUrl(value);
                fetch(`menu_search_ajax.php?search=${encodeURIComponent(value)}&category=${encodeURIComponent(categoryFilter)}&page=1&per_page=${encodeURIComponent(perPage)}`)
                    .then(r => r.json())
                    .then(data => {
                        if (requestId !== latestRequestId) return;
                        data.success ? renderResults(data) : (spinner.style.display = 'none', container.style.display = '');
                    })
                    .catch(() => {
                        if (requestId === latestRequestId) {
                            spinner.style.display = 'none';
                            container.style.display = '';
                        }
                    });
            }

            form.addEventListener('submit', e => {
                e.preventDefault();
                clearTimeout(debounceTimer);
                doSearch(input.value.trim());
            });
            input.addEventListener('input', function() {
                clearBtn.classList.toggle('visible', this.value.trim().length > 0);
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => doSearch(this.value.trim()), 300);
            });
            clearBtn.addEventListener('click', function() {
                input.value = '';
                this.classList.remove('visible');
                clearTimeout(debounceTimer);
                doSearch('');
            });
        })();

        /* ── PER-PAGE SELECTOR ────────────────────────────────────── */
        function bindPerPageSelect() {
            const sel = document.getElementById('per-page-select');
            if (!sel || sel._bound) return;
            sel._bound = true;
            sel.addEventListener('change', function() {
                const params = new URLSearchParams(window.location.search);
                params.set('per_page', this.value);
                params.delete('page'); // back to page 1
                window.location.href = `menu.php?${params.toString()}`;
            });
        }
        bindPerPageSelect();

        /* ── LOGOUT CONFIRM ───────────────────────────────────────── */
        document.addEventListener('click', function(e) {
            const link = e.target.closest('.logout-link');
            if (!link) return;
            e.preventDefault();
            Swal.fire({
                title: 'Ready to Leave?',
                text: 'Are you sure you want to log out?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#d4af37',
                cancelButtonColor: '#333',
                confirmButtonText: 'Yes, log me out',
                cancelButtonText: 'Cancel',
                background: '#1e1e1e',
                color: '#fff'
            }).then(r => {
                if (r.isConfirmed) window.location.href = link.href;
            });
        });
    </script>
</body>

</html>