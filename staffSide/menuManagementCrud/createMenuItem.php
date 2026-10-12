<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

// Only staff or admin may access
if (!isLoggedIn() || (!isStaff() && !isAdmin())) {
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
    $item_name = $_POST['item_name'] ?? '';
    $description = $_POST['description'] ?? '';
    $category = $_POST['category'] ?? '';
    $price = $_POST['price'] ?? '';
    $is_available = isset($_POST['is_available']) ? 1 : 0;

    // Validate input
    if (empty($item_name) || empty($description) || empty($category) || empty($price)) {
        $error = 'All fields are required.';
    } elseif ($price <= 0) {
        $error = 'Price must be greater than 0.';
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
                        $image_url = 'images/menu/' . $file_name;
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
                
                // Log action
                $audit_query = "INSERT INTO audit_logs (staff_id, action, table_affected, record_id, ip_address, timestamp) 
                               VALUES (:staff_id, 'CREATE_MENU_ITEM', 'menu_items', :item_id, :ip, NOW())";
                $audit_stmt = $db->prepare($audit_query);
                $audit_stmt->bindParam(':staff_id', $_SESSION['user_id']);
                $audit_stmt->bindParam(':item_id', $item_id);
                $audit_stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
                $audit_stmt->execute();
                
                header('Location: viewMenuItems.php?success=added');
                exit;
            }
        } catch (Exception $e) {
            $error = 'Error adding item: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Menu Item - Staff Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../staff-dashboard.css">
    <link rel="stylesheet" href="../staff-page-theme.css">
</head>
<body class="bg-dark">
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
                <div class="card bg-secondary border-warning">
                    <div class="card-header bg-dark border-warning">
                        <h3 class="text-primary-gold mb-0">
                            <i class="fas fa-plus-circle"></i> Add New Menu Item
                        </h3>
                    </div>
                    <div class="card-body">
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
                                <div class="col-md-6 mb-3">
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

                                <div class="col-md-6 mb-3">
                                    <label for="price" class="form-label text-light">Price ($) <span class="text-warning">*</span></label>
                                    <input type="number" class="form-control bg-dark text-light border-secondary" 
                                           id="price" name="price" step="0.01" min="0" required>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="image" class="form-label text-light">Item Image</label>
                                <div class="mb-2" style="display: none;" id="imagePreviewContainer">
                                    <img src="" id="imagePreview" alt="Image Preview" style="max-height: 150px; border-radius: 8px; border: 2px solid #D4AF37;">
                                </div>
                                <input type="file" class="form-control bg-dark text-light border-secondary" 
                                       id="image" name="image" accept="image/*" onchange="previewImage(this)">
                            </div>

                            <div class="mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="is_available" 
                                       name="is_available" checked>
                                <label class="form-check-label text-light" for="is_available">
                                    Available Now
                                </label>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="button" id="submitBtn" class="btn btn-success">
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
            const price    = document.getElementById('price').value;

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
