<?php
session_start();
require_once '../config/config.php';
require_once '../config/Database.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isCustomer()) {
    echo json_encode(['success' => false]);
    exit;
}

$database = new Database();
$db = $database->connect();

$search = trim($_GET['search'] ?? '');
$category = $_GET['category'] ?? 'all';
$allowedPerPage = [6, 9, 12, 18, 24];
$itemsPerPage = (int) ($_GET['per_page'] ?? 9);
if (!in_array($itemsPerPage, $allowedPerPage, true)) $itemsPerPage = 9;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));

// Get user favorites
$favStmt = $db->prepare("SELECT item_id FROM favorites WHERE member_id = :mid");
$favStmt->execute([':mid' => $_SESSION['user_id']]);
$favorites = $favStmt->fetchAll(PDO::FETCH_COLUMN);

// Get active promotions
$promoQuery = "
    SELECT p.* FROM promotions p
    WHERE p.is_active = 1
      AND p.start_date <= CURDATE()
      AND p.end_date >= CURDATE()
      AND (
          p.is_single_use = 0
          OR NOT EXISTS (
              SELECT 1 FROM online_orders o
              WHERE o.promo_code = p.promotion_name
                AND o.member_id = :member_id
          )
      )
";
$promoStmt = $db->prepare($promoQuery);
$promoStmt->bindParam(':member_id', $_SESSION['user_id']);
$promoStmt->execute();
$activePromotions = $promoStmt->fetchAll(PDO::FETCH_ASSOC);

$countQuery = "SELECT COUNT(*) FROM menu_items WHERE is_available = TRUE";
$countParams = [];

if ($category !== 'all') {
    $countQuery .= " AND category = :category";
    $countParams[':category'] = $category;
}
if (!empty($search)) {
    $countQuery .= " AND (item_name LIKE :s1 OR description LIKE :s2)";
    $term = "%$search%";
    $countParams[':s1'] = $term;
    $countParams[':s2'] = $term;
}

$countStmt = $db->prepare($countQuery);
$countStmt->execute($countParams);
$totalItems = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalItems / $itemsPerPage));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * $itemsPerPage;

$query = "SELECT *, (SELECT COUNT(*) FROM favorites f WHERE f.item_id = menu_items.item_id) as favorite_count
          FROM menu_items WHERE is_available = TRUE";
if ($category !== 'all') {
    $query .= " AND category = :category";
}
if (!empty($search)) {
    $query .= " AND (item_name LIKE :s1 OR description LIKE :s2)";
}
$query .= " ORDER BY category, item_name LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($query);
foreach ($countParams as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $itemsPerPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by category
$grouped = [];
foreach ($items as $item) {
    $grouped[$item['category']][] = $item;
}

function getPromoBadgesHtml($category, $activePromotions)
{
    $badges = [];
    foreach ($activePromotions as $promo) {
        $allowed = empty($promo['applicable_category']) ? [] : array_map('strtolower', array_map('trim', explode(',', $promo['applicable_category'])));
        if (empty($allowed) || in_array(strtolower(trim($category)), $allowed, true)) {
            $badges[] = '<span class="promo-badge"><i class="fas fa-tag"></i> ' . htmlspecialchars($promo['promotion_name']) . '</span>';
        }
    }

    if (empty($badges)) {
        return '';
    }

    return '<div class="promo-badge-stack">' . implode('', $badges) . '</div>';
}

// Build promo badge map per category
$promoBadges = [];
foreach ($grouped as $cat => $catItems) {
    $promoBadges[$cat] = getPromoBadgesHtml($cat, $activePromotions);
}

echo json_encode([
    'success' => true,
    'grouped' => $grouped,
    'favorites' => $favorites,
    'promo_badges' => $promoBadges,
    'total' => $totalItems,
    'pagination' => [
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'total_items' => $totalItems,
        'offset' => $offset,
        'items_per_page' => $itemsPerPage,
        'category' => $category,
        'search' => $search,
    ],
]);
