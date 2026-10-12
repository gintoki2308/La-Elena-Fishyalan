<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

// Get all categories for dropdown
$cat_query = "SELECT DISTINCT category FROM menu_items WHERE LOWER(TRIM(category)) NOT IN ('cocktails', 'cocktail', 'wine', 'non-alcoholic', 'after dinner', 'coffee', 'tea', 'juice', 'smoothie') ORDER BY category ASC";
$cat_stmt = $db->prepare($cat_query);
$cat_stmt->execute();
$categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

$error = '';
$success = '';

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
    $is_available = isset($_POST['is_available']) ? 1 : 0;

    // Check if this is an AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
        || (isset($_POST['item_name']) && isset($_POST['description'])); // Also treat multipart form data as AJAX if coming from modal

    // Validate input
    if (empty($item_name) || empty($description) || empty($category) || empty($price)) {
        $error = 'All fields are required.';
        if ($isAjax || strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart') !== false) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    } elseif ($price <= 0) {
        $error = 'Price must be greater than 0.';
        if ($isAjax || strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart') !== false) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    } else {
        try {
            // Handle image upload
            $image_url = null;
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
                        $image_url = 'images/menu/' . $file_name; // Using relative path
                    }
                }
            }

            $insert_query = "INSERT INTO menu_items (item_name, description, category, price, is_available, image_url, created_at)
                           VALUES (:item_name, :description, :category, :price, :is_available, :image_url, NOW())";
            $insert_stmt = $db->prepare($insert_query);

            $insert_stmt->bindParam(':item_name', $item_name);
            $insert_stmt->bindParam(':description', $description);
            $insert_stmt->bindParam(':category', $category);
            $insert_stmt->bindParam(':price', $price);
            $insert_stmt->bindParam(':is_available', $is_available);
            $insert_stmt->bindParam(':image_url', $image_url);

            if ($insert_stmt->execute()) {
                $item_id = $db->lastInsertId();
                writeAuditLog($db, 'CREATE_MENU_ITEM', 'menu_items', (int) $item_id, [
                    'summary' => 'Created menu item',
                    'item_name' => $item_name,
                    'category' => $category,
                    'price' => $price,
                    'is_available' => (int) $is_available,
                    'image_url' => $image_url,
                ]);

                // Return JSON for AJAX requests
                if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart') !== false) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'item_id' => $item_id]);
                    exit;
                } else {
                    header('Location: viewMenuItems.php?success=added');
                    exit;
                }
            }
        } catch (Exception $e) {
            $error = 'Error adding item: ' . $e->getMessage();
            if (strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart') !== false) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Menu Item - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../admin-dashboard.css">
</head>

<body class="bg-dark">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary">
        <div class="container-fluid">
            <a class="navbar-brand text-primary-gold text-truncate" href="../admin-dashboard.php" style="max-width: 60%;">
                <i class="fas fa-utensils"></i> <span class="d-none d-sm-inline">La Elena Fishyalan Admin</span><span class="d-inline d-sm-none">Admin</span>
            </a>
            <div class="d-flex align-items-center">
                <span class="text-light me-3 d-none d-md-inline">Welcome, <?php echo htmlspecialchars($_SESSION['first_name'] ?? 'Admin'); ?>!</span>
                <a href="../../auth/logout.php" class="logout-link btn btn-sm btn-outline-danger">Logout</a>
            </div>
        </div>
    </nav>

    <div class="container py-3 py-md-5">
        <div class="row justify-content-center">
            <div class="col-12 col-md-10 col-lg-8">
                <div class="card bg-secondary border-warning">
                    <div class="card-header bg-dark border-warning">
                        <h3 class="text-primary-gold mb-0 fs-4 fs-md-3">
                            <i class="fas fa-plus-circle"></i> Add New Menu Item
                        </h3>
                    </div>
                    <div class="card-body p-3 p-md-4">
                        <?php /* Errors are shown via SweetAlert below */ ?>

                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-3">
                                <label for="item_name" class="form-label text-light">Item Name <span class="text-warning">*</span></label>
                                <input type="text" class="form-control bg-dark text-light border-secondary"
                                    id="item_name" name="item_name" required>
                            </div>

                            <div class="mb-3">
                                <label for="description" class="form-label text-light">Description <span class="text-warning">*</span></label>
                                <textarea class="form-control bg-dark text-light border-secondary"
                                    id="description" name="description" rows="4" required></textarea>
                            </div>

                            <div class="row">
                                <div class="col-12 col-md-6 mb-3">
                                    <label for="category" class="form-label text-light">Category <span class="text-warning">*</span></label>
                                    <select class="form-select bg-dark text-light border-secondary"
                                        id="category" name="category" required>
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo htmlspecialchars($cat); ?>">
                                                <?php echo htmlspecialchars($cat); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="">-- Add New Category --</option>
                                    </select>
                                    <input type="text" class="form-control bg-dark text-light border-secondary mt-2"
                                        id="new_category" name="new_category" placeholder="Or enter new category name" style="display: none;">
                                </div>

                                <div class="col-12 col-md-6 mb-3">
                                    <label for="price" class="form-label text-light">Price (₱) <span class="text-warning">*</span></label>
                                    <input type="number" class="form-control bg-dark text-light border-secondary"
                                        id="price" name="price" step="0.01" min="0" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="image" class="form-label text-light">Item Image</label>
                                <div class="mb-2 text-center text-md-start" style="display: none;" id="imagePreviewContainer">
                                    <img src="" id="imagePreview" alt="Image Preview" class="img-fluid" style="max-height: 150px; border-radius: 8px; border: 2px solid #D4AF37;">
                                </div>
                                <input type="file" class="form-control bg-dark text-light border-secondary"
                                    id="image" name="image" accept="image/*" onchange="previewImage(this)">
                            </div>

                            <div class="mb-4 form-check">
                                <input type="checkbox" class="form-check-input" id="is_available"
                                    name="is_available" checked>
                                <label class="form-check-label text-light" for="is_available">
                                    Available Now
                                </label>
                            </div>

                            <div class="d-grid gap-2 d-md-flex">
                                <button type="button" id="submitBtn" class="btn btn-success mb-2 mb-md-0">
                                    <i class="fas fa-save me-2"></i> Add Item
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

        // Confirm before submitting the form
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
                title: 'Add Menu Item?',
                html: `You are about to add <strong style="color:#d4af37">${itemName}</strong> to the menu.<br>Do you want to proceed?`,
                showCancelButton: true,
                confirmButtonText: '<i class="fas fa-check me-1"></i> Yes, Add It',
                cancelButtonText: 'Review Again'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.querySelector('form').submit();
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
                }
                reader.readAsDataURL(input.files[0]);
            } else {
                document.getElementById('imagePreviewContainer').style.display = 'none';
            }
        }
    </script>
    <script>
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