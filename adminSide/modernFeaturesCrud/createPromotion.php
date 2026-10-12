<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();
$error = '';
$success = '';

// Fetch categories for the dropdown
$cat_stmt = $db->query("SELECT DISTINCT category FROM menu_items ORDER BY category ASC");
$categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // trim() helps prevent accidental spaces in the promotion names
    $promotion_name = trim($_POST['promotion_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $discount_type = $_POST['discount_type'] ?? '';
    $discount_value = $_POST['discount_value'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    
    // New fields
    $applicable_category = NULL;
    if (!empty($_POST['applicable_category'])) {
        $selected_cats = is_array($_POST['applicable_category']) ? $_POST['applicable_category'] : [$_POST['applicable_category']];
        // If 'all' is selected among others, or selected alone, it overrides category limits
        if (!in_array('all', $selected_cats)) {
            $applicable_category = implode(',', $selected_cats);
        }
    }
    $is_single_use = isset($_POST['is_single_use']) ? 1 : 0;

    if (empty($promotion_name) || empty($discount_type) || empty($discount_value)) {
        $error = "Please fill in all required fields.";
    } elseif ($end_date < $start_date) {
        $error = "End date cannot be earlier than the start date.";
    } else {
        try {
            $insertQuery = "INSERT INTO promotions (promotion_name, description, discount_type, discount_value, start_date, end_date, applicable_category, is_single_use, is_active, created_at)
                           VALUES (:name, :desc, :type, :value, :start, :end, :cat, :single, 1, NOW())";
            
            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->bindParam(':name', $promotion_name);
            $insertStmt->bindParam(':desc', $description);
            $insertStmt->bindParam(':type', $discount_type);
            $insertStmt->bindParam(':value', $discount_value);
            $insertStmt->bindParam(':start', $start_date);
            $insertStmt->bindParam(':end', $end_date);
            $insertStmt->bindParam(':cat', $applicable_category);
            $insertStmt->bindParam(':single', $is_single_use);
            
            $insertStmt->execute();

            $success = "Promotion created successfully!";
        } catch (Exception $e) {
            // Log the real error for you, show a clean message to the user
            error_log("Promotion Error: " . $e->getMessage());
            $error = "Database error: Could not create promotion.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Promotion - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #0d0d0d; color: #f0f0f0; }
        .card { background-color: #1a1a1a; border-color: #404040; }
        .form-control { background-color: #2d2d2d; border-color: #404040; color: #f0f0f0; }
        .form-label { color: #d4af37; font-weight: 600; }
        .btn-gold { background-color: #d4af37; color: #1a1a1a; border-color: #d4af37; font-weight: 600; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="../../adminSide/admin-dashboard.php"><i class="fas fa-arrow-left me-2"></i>Back</a>
        </div>
    </nav>

    <div class="container" style="max-width: 600px;">
        <h1 class="mb-4"><i class="fas fa-tag text-gold me-2"></i>Create Promotion</h1>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body p-4">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Promotion Name *</label>
                        <input type="text" class="form-control" name="promotion_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Discount Type *</label>
                        <select class="form-control" name="discount_type" required>
                            <option>PERCENTAGE</option>
                            <option>FIXED_AMOUNT</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Discount Value *</label>
                        <input type="number" class="form-control" name="discount_value" step="0.01" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Start Date *</label>
                            <input type="date" class="form-control" name="start_date" id="start_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">End Date *</label>
                            <input type="date" class="form-control" name="end_date" id="end_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label mb-2">Applicable Category(s)</label>
                        <div class="card bg-dark border-secondary p-3">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="applicable_category[]" value="all" id="cat_all" checked>
                                <label class="form-check-label text-white fw-bold" for="cat_all">
                                    -- All Categories (Storewide) --
                                </label>
                            </div>
                            <hr class="border-secondary my-2">
                            <div class="row">
                                <?php foreach ($categories as $index => $cat): ?>
                                    <div class="col-md-6 col-lg-4 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input category-checkbox" type="checkbox" name="applicable_category[]" value="<?php echo htmlspecialchars($cat); ?>" id="cat_<?php echo $index; ?>">
                                            <label class="form-check-label text-light" for="cat_<?php echo $index; ?>">
                                                <?php echo htmlspecialchars($cat); ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <small class="text-white mt-2 d-block" style="font-size: 0.8rem;">Select one or multiple categories. Leave 'All Categories' checked to apply the discount to the entire cart.</small>
                    </div>

                    <div class="mb-4 form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" id="is_single_use" name="is_single_use" value="1">
                        <label class="form-check-label text-light" for="is_single_use">Single Use Only (Per Customer)</label>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-gold btn-lg">Create Promotion</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Logic to uncheck "All Categories" if a specific category is clicked, and vice versa
        const allCatCheckbox = document.getElementById('cat_all');
        const catCheckboxes = document.querySelectorAll('.category-checkbox');

        allCatCheckbox.addEventListener('change', function() {
            if (this.checked) {
                catCheckboxes.forEach(cb => cb.checked = false);
            }
        });

        catCheckboxes.forEach(cb => {
            cb.addEventListener('change', function() {
                if (this.checked) {
                    allCatCheckbox.checked = false;
                }
            });
        });

        // Ensure end date cannot be earlier than start date on the client side dynamically
        const startDateInput = document.getElementById('start_date');
        const endDateInput = document.getElementById('end_date');

        startDateInput.addEventListener('change', function() {
            endDateInput.min = this.value;
            if (endDateInput.value && endDateInput.value < this.value) {
                endDateInput.value = this.value;
            }
        });
    </script>
</body>
</html>

