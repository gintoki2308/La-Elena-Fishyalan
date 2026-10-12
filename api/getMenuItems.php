<?php
header('Content-Type: application/json');
require_once '../config/config.php';
require_once '../config/Database.php';

$database = new Database();
$db = $database->connect();

try {
    $query = "SELECT * FROM menu_items
              WHERE is_available = TRUE
                AND LOWER(TRIM(category)) NOT IN ('cocktails', 'cocktail', 'wine', 'non-alcoholic', 'after dinner', 'coffee', 'tea', 'juice', 'smoothie')
              ORDER BY category, item_name ASC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $menu_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $menu_items]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
