<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';
require_once '../../config/menu_image_helper.php';

// Only staff or admin may access
if (!isLoggedIn() || (!isStaff() && !isAdmin())) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

// Get item ID
$item_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get the menu item
$query = "SELECT * FROM menu_items WHERE item_id = :item_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':item_id', $item_id);
$stmt->execute();
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    header('Location: viewMenuItems.php?error=not_found');
    exit;
}

// Get all categories for dropdown
$cat_query = "SELECT DISTINCT category FROM menu_items WHERE LOWER(TRIM(category)) NOT IN ('cocktails', 'cocktail', 'wine', 'non-alcoholic', 'after dinner', 'coffee', 'tea', 'juice', 'smoothie') ORDER BY category ASC";
$cat_stmt = $db->prepare($cat_query);
$cat_stmt->execute();
$categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_name = trim($_POST['item_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $new_category = trim($_POST['new_category'] ?? '');

    if (empty($category) && !empty($new_category)) {
        $category = $new_category;
    }

    $price = $_POST['price'] ?? '';
    $stock_quantity = $_POST['stock_quantity'] ?? 0;
    $reorder_level = $_POST['reorder_level'] ?? 10;
    $is_available = isset($_POST['is_available']) ? 1 : 0;

    // Validate input
    if (empty($item_name) || empty($description) || empty($category) || empty($price)) {
        $error = 'All fields are required.';
    } elseif ($price <= 0) {
        $error = 'Price must be greater than 0.';
    } else {
        try {
            // Handle image upload
            $image_url = $item['image_url'] ?? null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = dirname(__DIR__, 2) . '/images/menu/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $file_extension = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                if (in_array($file_extension, $allowed_extensions)) {
                    $file_name = uniqid('menu_') . '.' . $file_extension;
                    $target_path = $upload_dir . $file_name;

                    if (move_uploaded_file($_FILES['image']['tmp_name'], $target_path)) {
                        $image_url = 'images/menu/' . $file_name;
                    }
                }
            }

            $update_query = "UPDATE menu_items SET item_name = :item_name, description = :description, 
                           category = :category, price = :price, stock_quantity = :stock_quantity, reorder_level = :reorder_level, is_available = :is_available, 
                           image_url = :image_url, updated_at = NOW() WHERE item_id = :item_id";
            $update_stmt = $db->prepare($update_query);

            $update_stmt->bindParam(':item_name', $item_name);
            $update_stmt->bindParam(':description', $description);
            $update_stmt->bindParam(':category', $category);
            $update_stmt->bindParam(':price', $price);
            $update_stmt->bindParam(':stock_quantity', $stock_quantity);
            $update_stmt->bindParam(':reorder_level', $reorder_level);
            $update_stmt->bindParam(':is_available', $is_available);
            $update_stmt->bindParam(':image_url', $image_url);
            $update_stmt->bindParam(':item_id', $item_id);

            if ($update_stmt->execute()) {
                if ($image_url !== ($item['image_url'] ?? null)) {
                    removeUnusedMenuImage($db, $item['image_url'] ?? null);
                }
                writeAuditLog($db, 'UPDATE_MENU_ITEM', 'menu_items', (int) $item_id, [
                    'summary' => 'Updated menu item',
                    'previous_item_name' => $item['item_name'] ?? null,
                    'new_item_name' => $item_name,
                    'previous_category' => $item['category'] ?? null,
                    'new_category' => $category,
                    'previous_price' => $item['price'] ?? null,
                    'new_price' => $price,
                    'previous_stock_quantity' => $item['stock_quantity'] ?? null,
                    'new_stock_quantity' => $stock_quantity,
                    'previous_reorder_level' => $item['reorder_level'] ?? null,
                    'new_reorder_level' => $reorder_level,
                    'previous_is_available' => $item['is_available'] ?? null,
                    'new_is_available' => (int) $is_available,
                    'previous_image_url' => $item['image_url'] ?? null,
                    'new_image_url' => $image_url,
                ]);

                header('Location: viewMenuItems.php?success=updated');
                exit;
            }
        } catch (Exception $e) {
            $error = 'Error updating item: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Menu Item - Staff Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../staff-dashboard.css">
    <link rel="stylesheet" href="../staff-page-theme.css">
    <style>
        .edit-menu-page {
            background: radial-gradient(circle at top right, #17333d 0, #11191d 42%, #091014 100%) !important;
            color: #f4f4ee;
        }

        .edit-menu-page .card {
            background: #102128 !important;
            border: 1px solid rgba(216, 164, 47, .55) !important;
            border-radius: 14px;
            box-shadow: 0 18px 45px rgba(0, 0, 0, .28);
            overflow: hidden;
        }

        .edit-menu-page .card-header {
            background: #0b171c !important;
            border-bottom: 1px solid rgba(216, 164, 47, .3) !important;
            padding: 20px 24px;
        }

        .edit-menu-page .card-header h3 {
            color: #edbd4d !important;
        }

        .edit-menu-page .card-body {
            padding: 26px 28px 30px;
        }

        .edit-menu-page .form-label,
        .edit-menu-page .form-check-label {
            color: #f4f4ee !important;
            font-weight: 600;
            margin-bottom: 7px;
        }

        .edit-menu-page .form-control,
        .edit-menu-page .form-select {
            background-color: #091419 !important;
            border-color: #3a555d !important;
            color: #f4f4ee !important;
            min-height: 42px;
        }

        .edit-menu-page textarea.form-control {
            min-height: 112px;
        }

        .edit-menu-page .form-control:focus,
        .edit-menu-page .form-select:focus {
            border-color: #d8a42f !important;
            box-shadow: 0 0 0 .2rem rgba(216, 164, 47, .16);
        }

        .edit-menu-page .current-image {
            display: inline-flex;
            padding: 8px;
            margin-bottom: 4px;
            background: #091419;
            border: 1px solid #3a555d;
            border-radius: 9px;
        }

        .edit-menu-page .current-image img {
            display: block;
            max-width: 220px;
            max-height: 140px;
            object-fit: cover;
            border-radius: 5px;
        }

        .edit-menu-page .form-control::file-selector-button {
            background: #24383e;
            color: #f4f4ee;
            border: 0;
        }

        @media (max-width: 575.98px) {
            .edit-menu-page .card-body {
                padding: 20px 16px 24px;
            }
        }
    </style>
</head>

<body class="bg-dark edit-menu-page">
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary">
        <div class="container-fluid">
            <a class="navbar-brand text-primary-gold" href="../staff-dashboard.php">
                <i class="fas fa-utensils"></i> La Elena Fishyalan Staff
            </a>
            <div class="d-flex align-items-center">
                <span class="text-light me-3">Welcome, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Staff'); ?>!</span>
                <a href="../../auth/logout.php" class="logout-link btn btn-sm btn-outline-danger">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container py-5">
        <!-- Back Link -->
        <div class="mb-4">
            <a href="viewMenuItems.php" class="btn btn-outline-warning">
                <i class="fas fa-arrow-left"></i> Back to Menu Items
            </a>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card bg-dark border-warning">
                    <div class="card-header bg-dark border-warning">
                        <h3 class="text-primary-gold mb-0">
                            <i class="fas fa-edit"></i> Edit Menu Item
                        </h3>
                    </div>
                    <div class="card-body">
                        <?php /* Errors are shown via SweetAlert below */ ?>

                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="item_name" class="form-label text-light">Item Name <span class="text-warning">*</span></label>
                                <input type="text" class="form-control bg-dark text-light border-secondary"
                                    id="item_name" name="item_name" value="<?php echo htmlspecialchars($item['item_name']); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="description" class="form-label text-light">Description <span class="text-warning">*</span></label>
                                <textarea class="form-control bg-dark text-light border-secondary"
                                    id="description" name="description" rows="4" required><?php echo htmlspecialchars($item['description']); ?></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="category" class="form-label text-light">Category <span class="text-warning">*</span></label>
                                    <select class="form-select bg-dark text-light border-secondary"
                                        id="category" name="category" required>
                                        <option value="<?php echo htmlspecialchars($item['category']); ?>" selected>
                                            <?php echo htmlspecialchars($item['category']); ?>
                                        </option>
                                        <?php foreach ($categories as $cat): ?>
                                            <?php if ($cat !== $item['category']): ?>
                                                <option value="<?php echo htmlspecialchars($cat); ?>">
                                                    <?php echo htmlspecialchars($cat); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <option value="">-- Add New Category --</option>
                                    </select>
                                    <input type="text" class="form-control bg-dark text-light border-secondary mt-2"
                                        id="new_category" name="new_category" placeholder="Or enter new category name" style="display: none;">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label for="price" class="form-label text-light">Price (₱) <span class="text-warning">*</span></label>
                                    <input type="number" class="form-control bg-dark text-light border-secondary"
                                        id="price" name="price" step="0.01" min="0" value="<?php echo htmlspecialchars($item['price']); ?>" required>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="stock_quantity" class="form-label text-light">Current Stock <span class="text-warning">*</span></label>
                                    <input type="number" class="form-control bg-dark text-light border-secondary"
                                        id="stock_quantity" name="stock_quantity" min="0" value="<?php echo htmlspecialchars($item['stock_quantity'] ?? 0); ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="reorder_level" class="form-label text-light">Low Stock Threshold <span class="text-warning">*</span></label>
                                    <input type="number" class="form-control bg-dark text-light border-secondary"
                                        id="reorder_level" name="reorder_level" min="0" value="<?php echo htmlspecialchars($item['reorder_level'] ?? 10); ?>" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="image" class="form-label text-light">Item Image</label>
                                <?php if (!empty($item['image_url'])): ?>
                                    <div class="current-image" id="currentImageContainer">
                                        <img src="<?php echo htmlspecialchars('../../' . ltrim($item['image_url'], '/')); ?>" alt="Current Image">
                                    </div>
                                <?php endif; ?>
                                <div class="mb-2" style="display: none;" id="imagePreviewContainer">
                                    <img src="" id="imagePreview" alt="Image Preview" style="max-height: 150px; border-radius: 8px; border: 2px solid #D4AF37;">
                                </div>
                                <input type="file" class="form-control bg-dark text-light border-secondary"
                                    id="image" name="image" accept="image/*" onchange="previewImage(this)">
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="is_available"
                                    name="is_available" <?php echo $item['is_available'] ? 'checked' : ''; ?>>
                                <label class="form-check-label text-light" for="is_available">
                                    Available Now
                                </label>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="button" id="submitBtn" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i> Update Item
                                </button>
                                <a href="viewMenuItems.php" class="btn btn-secondary">
                                    <i class="fas fa-times me-2"></i> Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const swalDark = {
            background: '#1e1e1e',
            color: '#ffffff',
            confirmButtonColor: '#d4af37',
            cancelButtonColor: '#6c757d'
        };

        // Show error via SweetAlert if PHP detected a validation error
        <?php if ($error): ?>
            Swal.fire({
                ...swalDark,
                icon: 'error',
                title: 'Oops!',
                text: <?php echo json_encode($error); ?>,
                iconColor: '#ff4757'
            });
        <?php endif; ?>

        const editForm = document.querySelector('form');

        // Confirm before submitting the update
        document.getElementById('submitBtn').addEventListener('click', function() {
            const itemName = document.getElementById('item_name').value.trim();
            const price = document.getElementById('price').value;

            if (!itemName || !price) {
                Swal.fire({
                    ...swalDark,
                    icon: 'warning',
                    title: 'Missing Fields',
                    text: 'Please fill in all required fields before submitting.',
                    iconColor: '#d4af37'
                });
                return;
            }

            Swal.fire({
                ...swalDark,
                icon: 'question',
                title: 'Save Changes?',
                html: `Update <strong style="color:#d4af37">${itemName}</strong>?<br>Your changes will be saved immediately.`,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-save me-1"></i> Yes, Update It',
                cancelButtonText: 'Review Again'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        ...swalDark,
                        icon: 'success',
                        title: 'Updated!',
                        text: 'Menu item has been updated successfully.',
                        timer: 1500,
                        showConfirmButton: false,
                        iconColor: '#d4af37'
                    }).then(() => {
                        editForm.submit();
                    });
                }
            });
        });

        // Handle category dropdown for adding new categories
        document.getElementById('category').addEventListener('change', function() {
            const newCatInput = document.getElementById('new_category');
            if (this.value === '') {
                newCatInput.style.display = 'block';
                newCatInput.required = true;
            } else {
                newCatInput.style.display = 'none';
                newCatInput.required = false;
            }
        });

        function previewImage(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('imagePreview').src = e.target.result;
                    document.getElementById('imagePreviewContainer').style.display = 'block';
                    var currentImg = document.getElementById('currentImageContainer');
                    if (currentImg) {
                        currentImg.style.display = 'none';
                    }
                }
                reader.readAsDataURL(input.files[0]);
            } else {
                document.getElementById('imagePreviewContainer').style.display = 'none';
                var currentImg = document.getElementById('currentImageContainer');
                if (currentImg) {
                    currentImg.style.display = 'block';
                }
            }
        }

        // Logout confirmation
        document.addEventListener('click', function(e) {
            const link = e.target.closest('.logout-link');
            if (link) {
                e.preventDefault();
                const logoutUrl = link.getAttribute('href');
                Swal.fire({
                    ...swalDark,
                    title: 'Ready to Leave?',
                    text: 'Are you sure you want to log out?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, log me out',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = logoutUrl;
                    }
                });
            }
        });
    </script>
</body>

</html>
