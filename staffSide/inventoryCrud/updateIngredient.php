<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (!isLoggedIn() || (!isStaff() && !isAdmin())) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();
$ingredients = [];
$error = '';

try {
    $query = "SELECT i.*, m.item_name, m.price FROM ingredients i
              LEFT JOIN menu_ingredients mi ON i.ingredient_id = mi.ingredient_id
              LEFT JOIN menu_items m ON mi.item_id = m.item_id
              ORDER BY i.ingredient_name";
    $stmt = $db->query($query);
    $ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "Error fetching ingredients: " . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Ingredient - Staff Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        body { background-color: #0d0d0d; color: #f0f0f0; }
        .table { background-color: #1a1a1a; }
        .table th { color: #d4af37; background-color: #2d2d2d; }
        .card { background-color: #1a1a1a; border-color: #404040; }
        .text-gold { color: #d4af37; }
        .btn-outline-gold { 
            color: #d4af37; 
            border-color: #d4af37; 
        }
        .btn-outline-gold:hover { 
            background-color: #d4af37; 
            color: #1a1a1a; 
        }
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
        <h1 class="mb-4"><i class="fas fa-edit text-gold me-2"></i>Update Ingredient</h1>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Ingredient</th>
                                <th>Used In Menu Item</th>
                                <th>Unit</th>
                                <th>Cost</th>
                                <th>Stock</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($ingredients)): ?>
                                <?php foreach ($ingredients as $ing): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($ing['ingredient_name']); ?></td>
                                    <td><?php echo htmlspecialchars($ing['item_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($ing['unit']); ?></td>
                                    <td>₱<?php echo number_format($ing['unit_price'] ?? 0, 2); ?></td>
                                    <td><?php echo htmlspecialchars($ing['current_stock']); ?></td>
                                    <td>
                                        <a href="viewInventory.php?edit=<?php echo urlencode($ing['ingredient_id']); ?>" class="btn btn-sm btn-outline-gold">Edit</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center p-4">No ingredients found.</td>
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
