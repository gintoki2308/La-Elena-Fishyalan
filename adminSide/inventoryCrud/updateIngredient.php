<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();
$ingredients = [];
$error = '';

try {
    // UPDATED QUERY: 
    // Uses GROUP_CONCAT to list all menu items an ingredient is used in without duplicating rows.
    $query = "SELECT i.*, GROUP_CONCAT(m.item_name SEPARATOR ', ') as used_in_items 
              FROM ingredients i
              LEFT JOIN menu_ingredients mi ON i.ingredient_id = mi.ingredient_id
              LEFT JOIN menu_items m ON mi.item_id = m.item_id
              GROUP BY i.ingredient_id
              ORDER BY i.ingredient_name";
              
    $stmt = $db->query($query);
    $ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Inventory Fetch Error: " . $e->getMessage());
    $error = "Error fetching ingredients list.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Inventory - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body { background-color: #0d0d0d; color: #f0f0f0; }
        .table { background-color: #1a1a1a; vertical-align: middle; }
        .table th { color: #d4af37; background-color: #2d2d2d; border-color: #444; }
        .table td { border-color: #333; }
        .card { background-color: #1a1a1a; border-color: #404040; }
        .text-gold { color: #d4af37; }
        .btn-outline-gold { color: #d4af37; border-color: #d4af37; }
        .btn-outline-gold:hover { background-color: #d4af37; color: #1a1a1a; }
        .badge-low-stock { color: #ff4d4d; font-weight: bold; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="viewInventory.php">
                <i class="fas fa-arrow-left me-2"></i>Back to Inventory
            </a>
        </div>
    </nav>

    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1><i class="fas fa-boxes-stacked text-gold me-2"></i>Update Ingredients</h1>
            <a href="viewInventory.php?create=1" class="btn btn-outline-gold"><i class="fas fa-plus me-2"></i>New Ingredient</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger bg-danger text-white border-0"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card shadow">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Ingredient</th>
                                <th>Used In Menu Items</th>
                                <th>Unit</th>
                                <th>Cost</th>
                                <th>Stock Level</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($ingredients)): ?>
                                <?php foreach ($ingredients as $ing): ?>
                                <?php 
                                    $isLowStock = $ing['current_stock'] <= $ing['reorder_level'];
                                ?>
                                <tr>
                                    <td class="fw-bold"><?php echo htmlspecialchars($ing['ingredient_name']); ?></td>
                                    <td>
                                        <small class="text-muted">
                                            <?php echo htmlspecialchars($ing['used_in_items'] ?? 'Not assigned'); ?>
                                        </small>
                                    </td>
                                    <td><?php echo htmlspecialchars($ing['unit']); ?></td>
                                    <td>₱<?php echo number_format($ing['unit_price'] ?? 0, 2); ?></td>
                                    <td>
                                        <span class="<?php echo $isLowStock ? 'badge-low-stock' : ''; ?>">
                                            <?php echo htmlspecialchars($ing['current_stock']); ?>
                                        </span>
                                        <?php if ($isLowStock): ?>
                                            <i class="fas fa-triangle-exclamation ms-1 text-danger" title="Low Stock!"></i>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <a href="viewInventory.php?edit=<?php echo urlencode($ing['ingredient_id']); ?>" 
                                           class="btn btn-sm btn-outline-gold">
                                            <i class="fas fa-pen-to-square me-1"></i>Edit
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center p-5">
                                        <i class="fas fa-info-circle me-2"></i>No ingredients found in the inventory.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
