<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';
require_once '../../config/pagination_helper.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();
$promotions = [];
$error = '';
$success = '';

$currentPage = max(1, (int) ($_GET['page'] ?? 1));

if (isset($_SESSION['promotion_flash_success'])) {
    $success = (string) $_SESSION['promotion_flash_success'];
    unset($_SESSION['promotion_flash_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_promotion') {
    $promotion_name = trim($_POST['promotion_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $discount_type = $_POST['discount_type'] ?? '';
    $discount_value = $_POST['discount_value'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $applicable_category = null;
    $selected_cats = $_POST['applicable_category'] ?? [];
    $selected_cats = is_array($selected_cats) ? $selected_cats : [$selected_cats];
    if (!in_array('all', $selected_cats, true) && !empty($selected_cats)) {
        $applicable_category = implode(',', $selected_cats);
    }
    $is_single_use = isset($_POST['is_single_use']) ? 1 : 0;

    if (empty($promotion_name) || empty($discount_type) || empty($discount_value) || empty($start_date) || empty($end_date)) {
        $error = 'Please fill in all required fields.';
    } elseif ($end_date < $start_date) {
        $error = 'End date cannot be earlier than the start date.';
    } else {
        try {
            $insertStmt = $db->prepare("INSERT INTO promotions (promotion_name, description, discount_type, discount_value, start_date, end_date, applicable_category, is_single_use, is_active, created_at)
                VALUES (:name, :description, :type, :value, :start, :end, :category, :single_use, 1, NOW())");
            $insertStmt->execute([
                ':name' => $promotion_name,
                ':description' => $description,
                ':type' => $discount_type,
                ':value' => $discount_value,
                ':start' => $start_date,
                ':end' => $end_date,
                ':category' => $applicable_category,
                ':single_use' => $is_single_use,
            ]);
            $_SESSION['promotion_flash_success'] = 'Promotion created successfully!';
            header('Location: viewPromotion.php');
            exit;
        } catch (Exception $e) {
            error_log('Create Promotion Error: ' . $e->getMessage());
            $error = 'Database error: Could not create promotion.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_promotion') {
    $promotionId = (int) ($_POST['promotion_id'] ?? 0);
    $redirectPage = max(1, (int) ($_POST['redirect_page'] ?? $currentPage));

    try {
        if ($promotionId <= 0) {
            throw new Exception('Invalid promotion selected.');
        }

        $promotionStmt = $db->prepare("SELECT promotion_name FROM promotions WHERE promotion_id = :id");
        $promotionStmt->execute([':id' => $promotionId]);
        $promotionName = $promotionStmt->fetchColumn();

        if (!$promotionName) {
            throw new Exception('Promotion not found.');
        }

        $deleteStmt = $db->prepare("DELETE FROM promotions WHERE promotion_id = :id");
        $deleteStmt->execute([':id' => $promotionId]);

        writeAuditLog($db, 'DELETE_PROMOTION', 'promotions', $promotionId, [
            'summary' => 'Deleted promotion',
            'promotion_name' => $promotionName,
        ]);

        $_SESSION['promotion_flash_success'] = 'Promotion "' . $promotionName . '" deleted successfully!';

        $redirectUrl = 'viewPromotion.php';
        if ($redirectPage > 1) {
            $redirectUrl .= '?page=' . $redirectPage;
        }

        header('Location: ' . $redirectUrl);
        exit;
    } catch (Exception $e) {
        error_log("Delete Promotion Error: " . $e->getMessage());
        $error = 'Could not delete the selected promotion.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_promotion') {
    $promotion_id = (int)($_POST['promotion_id'] ?? 0);
    $promotion_name = trim($_POST['promotion_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $discount_type = $_POST['discount_type'] ?? '';
    $discount_value = $_POST['discount_value'] ?? '';
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $applicable_category = NULL;
    if (!empty($_POST['applicable_category'])) {
        $selected_cats = is_array($_POST['applicable_category']) ? $_POST['applicable_category'] : [$_POST['applicable_category']];
        if (!in_array('all', $selected_cats)) {
            $applicable_category = implode(',', $selected_cats);
        }
    }
    $is_single_use = isset($_POST['is_single_use']) ? 1 : 0;

    if ($promotion_id <= 0 || empty($promotion_name) || empty($discount_type) || empty($discount_value)) {
        $error = "Please fill in all required fields.";
    } elseif ($end_date < $start_date) {
        $error = "End date cannot be earlier than the start date.";
    } else {
        try {
            $updateQuery = "UPDATE promotions SET promotion_name = :name, description = :desc, discount_type = :type, discount_value = :value, start_date = :start, end_date = :end, applicable_category = :cat, is_single_use = :single, is_active = :active WHERE promotion_id = :id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([
                ':name' => $promotion_name,
                ':desc' => $description,
                ':type' => $discount_type,
                ':value' => $discount_value,
                ':start' => $start_date,
                ':end' => $end_date,
                ':cat' => $applicable_category,
                ':single' => $is_single_use,
                ':active' => $is_active,
                ':id' => $promotion_id
            ]);

            writeAuditLog($db, 'UPDATE_PROMOTION', 'promotions', $promotion_id, ['summary' => 'Updated promotion', 'promotion_name' => $promotion_name]);
            $_SESSION['promotion_flash_success'] = 'Promotion "' . $promotion_name . '" updated successfully!';

            $redirectPage = max(1, (int) ($_POST['redirect_page'] ?? $currentPage));
            $redirectUrl = 'viewPromotion.php' . ($redirectPage > 1 ? '?page=' . $redirectPage : '');
            header('Location: ' . $redirectUrl);
            exit;
        } catch (Exception $e) {
            error_log("Update Promotion Error: " . $e->getMessage());
            $error = "Could not update the promotion.";
        }
    }
}

$cat_stmt = $db->query("SELECT DISTINCT category FROM menu_items ORDER BY category ASC");
$categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);

try {

    $filter_status = $_GET['status'] ?? 'ALL';

    $baseQuery = "SELECT * FROM promotions";
    $whereConds = [];
    $params = [];

    if ($filter_status === 'ACTIVE') {
        $whereConds[] = "is_active = 1 AND NOW() BETWEEN start_date AND CONCAT(end_date, ' 23:59:59')";
    } elseif ($filter_status === 'EXPIRED') {
        $whereConds[] = "NOW() > CONCAT(end_date, ' 23:59:59')";
    } elseif ($filter_status === 'INACTIVE') {
        $whereConds[] = "NOT (is_active = 1 AND NOW() BETWEEN start_date AND CONCAT(end_date, ' 23:59:59')) AND NOW() <= CONCAT(end_date, ' 23:59:59')";
    }

    if (count($whereConds) > 0) {
        $baseQuery .= " WHERE " . implode(" AND ", $whereConds);
    }

    $statusOrderSql = "CASE 
        WHEN is_active = 1 AND NOW() BETWEEN start_date AND CONCAT(end_date, ' 23:59:59') THEN 1
        WHEN NOW() > CONCAT(end_date, ' 23:59:59') THEN 3
        ELSE 2
    END";

    $query = $baseQuery . " ORDER BY {$statusOrderSql}, created_at DESC";
    $stmt = $db->query($query);
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Log error privately, show generic message if preferred
    error_log("View Promotions Error: " . $e->getMessage());
    $error = "Could not retrieve promotions list.";
}

$promotionsPagination = paginate_array($promotions, 10);
$promotions = $promotionsPagination['items'];

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>View Promotions - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-dark@4/dark.css">
    <style>
        body {
            background-color: #0d0d0d;
            color: #f0f0f0;
        }

        .table {
            background-color: #1a1a1a;
            border-color: #404040;
        }

        .table th {
            color: #d4af37;
            background-color: #2d2d2d;
            border-color: #404040;
        }

        .table td {
            border-color: #333;
            vertical-align: middle;
        }

        .card {
            background-color: #1a1a1a;
            border-color: #404040;
        }

        .btn-gold {
            background-color: #d4af37;
            color: #1a1a1a;
            font-weight: 600;
        }

        .btn-gold:hover {
            background-color: #b8962d;
            color: #1a1a1a;
        }

        .text-gold {
            color: #d4af37;
        }

        .btn-delete {
            border-color: #dc3545;
            color: #dc3545;
        }

        .btn-delete:hover {
            background-color: #dc3545;
            color: #fff;
        }

        .btn-outline-info {
            border-color: #0dcaf0;
            color: #0dcaf0;
        }

        .btn-outline-info:hover {
            background-color: #0dcaf0;
            color: #000;
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="../../adminSide/admin-dashboard.php"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>
        </div>
    </nav>

    <div class="container">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
            <div class="d-flex align-items-center gap-3">
                <h1 class="mb-0 fs-3"><i class="fas fa-tags text-gold me-2"></i>Promotions</h1>
                <form method="GET" class="d-flex align-items-center m-0">
                    <label for="statusFilter" class="me-2 text-gold fw-bold d-none d-md-inline" style="white-space: nowrap;">Filter:</label>
                    <select name="status" id="statusFilter" class="form-select bg-dark text-light border-secondary" style="width:140px; cursor:pointer;" onchange="this.form.submit()">
                        <option value="ALL" <?php echo ($filter_status ?? '') === 'ALL' ? 'selected' : ''; ?>>All</option>
                        <option value="ACTIVE" <?php echo ($filter_status ?? '') === 'ACTIVE' ? 'selected' : ''; ?>>Active</option>
                        <option value="INACTIVE" <?php echo ($filter_status ?? '') === 'INACTIVE' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="EXPIRED" <?php echo ($filter_status ?? '') === 'EXPIRED' ? 'selected' : ''; ?>>Expired</option>
                    </select>
                </form>
            </div>
            <button type="button" class="btn btn-gold mt-2 mt-md-0" data-bs-toggle="modal" data-bs-target="#createPromotionModal">
                <i class="fas fa-plus me-2"></i>New Promotion
            </button>
        </div>




        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Value</th>
                                <th>Start Date</th>
                                <th>End Date</th>
                                <th>Status</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($promotions)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">No promotions found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($promotions as $promo): ?>
                                    <?php
                                    $now = time();
                                    // Use strtotime to compare dates correctly
                                    $startDate = strtotime($promo['start_date'] . ' 00:00:00');
                                    $endDate = strtotime($promo['end_date'] . ' 23:59:59');

                                    // Logic: Active if flag is 1 AND we are within the date range
                                    $isCurrentlyActive = $promo['is_active'] && ($now >= $startDate && $now <= $endDate);

                                    // Identify if it's expired vs just disabled
                                    $isExpired = ($now > $endDate);
                                    ?>
                                    <tr>
                                        <td class="fw-bold"><?php echo htmlspecialchars($promo['promotion_name']); ?></td>
                                        <td><small class="text-uppercase"><?php echo htmlspecialchars(str_replace('_', ' ', $promo['discount_type'])); ?></small></td>
                                        <td>
                                            <?php
                                            echo $promo['discount_type'] === 'PERCENTAGE'
                                                ? htmlspecialchars($promo['discount_value']) . '%'
                                                : '₱' . number_format($promo['discount_value'], 2); // Changed $ to ₱ as a guess for PH context
                                            ?>
                                        </td>
                                        <td><?php echo date('M d, Y', $startDate); ?></td>
                                        <td><?php echo date('M d, Y', $endDate); ?></td>
                                        <td>
                                            <?php if ($isCurrentlyActive): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php elseif ($isExpired): ?>
                                                <span class="badge bg-secondary">Expired</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-sm btn-outline-info me-1 btn-edit" title="Edit Promotion"
                                                data-bs-toggle="modal" data-bs-target="#editPromotionModal"
                                                data-promo="<?php echo htmlspecialchars(json_encode($promo), ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <form method="POST" class="d-inline delete-promotion-form" data-promotion-name="<?php echo htmlspecialchars($promo['promotion_name']); ?>">
                                                <input type="hidden" name="action" value="delete_promotion">
                                                <input type="hidden" name="promotion_id" value="<?php echo (int) $promo['promotion_id']; ?>">
                                                <input type="hidden" name="redirect_page" value="<?php echo (int) $promotionsPagination['current_page']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger btn-delete" title="Delete Promotion">
                                                    <i class="fas fa-trash-alt me-1"></i>Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php echo render_pagination($promotionsPagination); ?>
            </div>
        </div>
    </div>

    <!-- Create Promotion Modal -->
    <div class="modal fade" id="createPromotionModal" tabindex="-1" aria-hidden="true" data-bs-theme="dark">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content" style="background-color: #1a1a1a; color: #f0f0f0;">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-gold"><i class="fas fa-plus-circle me-2"></i>New Promotion</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_promotion">
                        <div class="mb-3">
                            <label class="form-label text-gold fw-bold">Promotion Name *</label>
                            <input type="text" class="form-control" name="promotion_name" required maxlength="255">
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-gold fw-bold">Description</label>
                            <textarea class="form-control" name="description" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-gold fw-bold">Discount Type *</label>
                                <select class="form-control" name="discount_type" required>
                                    <option value="PERCENTAGE">PERCENTAGE</option>
                                    <option value="FIXED_AMOUNT">FIXED_AMOUNT</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-gold fw-bold">Discount Value *</label>
                                <input type="number" class="form-control" name="discount_value" step="0.01" min="0" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-gold fw-bold">Start Date *</label>
                                <input type="date" class="form-control" name="start_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-gold fw-bold">End Date *</label>
                                <input type="date" class="form-control" name="end_date" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-gold fw-bold">Applicable Category(s)</label>
                            <div class="card bg-dark border-secondary p-3">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="applicable_category[]" value="all" id="create_cat_all" checked>
                                    <label class="form-check-label text-white fw-bold" for="create_cat_all">All Categories (Storewide)</label>
                                </div>
                                <hr class="border-secondary my-2">
                                <div class="row">
                                    <?php foreach ($categories as $index => $cat): ?>
                                        <div class="col-md-6 col-lg-4 mb-2">
                                            <div class="form-check">
                                                <input class="form-check-input create-category-checkbox" type="checkbox" name="applicable_category[]" value="<?php echo htmlspecialchars($cat); ?>" id="create_cat_<?php echo $index; ?>">
                                                <label class="form-check-label text-light" for="create_cat_<?php echo $index; ?>"><?php echo htmlspecialchars($cat); ?></label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="create_is_single_use" name="is_single_use" value="1">
                            <label class="form-check-label" for="create_is_single_use">Single Use Only (Per Customer)</label>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gold">Create Promotion</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Promotion Modal -->
    <div class="modal fade" id="editPromotionModal" tabindex="-1" aria-hidden="true" data-bs-theme="dark">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background-color: #1a1a1a; color: #f0f0f0;">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title text-gold"><i class="fas fa-edit me-2"></i>Edit Promotion</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_promotion">
                        <input type="hidden" id="edit_promotion_id" name="promotion_id">
                        <input type="hidden" name="redirect_page" value="<?php echo (int) $promotionsPagination['current_page']; ?>">

                        <div class="mb-3">
                            <label class="form-label text-gold fw-bold">Promotion Name *</label>
                            <input type="text" class="form-control" id="edit_promotion_name" name="promotion_name" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-gold fw-bold">Description</label>
                            <textarea class="form-control" id="edit_description" name="description" rows="2"></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-gold fw-bold">Discount Type *</label>
                            <select class="form-control" id="edit_discount_type" name="discount_type" required>
                                <option value="PERCENTAGE">PERCENTAGE</option>
                                <option value="FIXED_AMOUNT">FIXED_AMOUNT</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label text-gold fw-bold">Discount Value *</label>
                            <input type="number" class="form-control" id="edit_discount_value" name="discount_value" step="0.01" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-gold fw-bold">Start Date *</label>
                                <input type="date" class="form-control" id="edit_start_date" name="start_date" min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label text-gold fw-bold">End Date *</label>
                                <input type="date" class="form-control" id="edit_end_date" name="end_date" min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label mb-2 text-gold fw-bold">Applicable Category(s)</label>
                            <div class="card bg-dark border-secondary p-3">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="applicable_category[]" value="all" id="edit_cat_all">
                                    <label class="form-check-label text-white fw-bold" for="edit_cat_all">
                                        -- All Categories (Storewide) --
                                    </label>
                                </div>
                                <hr class="border-secondary my-2">
                                <div class="row">
                                    <?php foreach ($categories as $index => $cat): ?>
                                        <div class="col-md-6 col-lg-4 mb-2">
                                            <div class="form-check">
                                                <input class="form-check-input edit-category-checkbox" type="checkbox" name="applicable_category[]" value="<?php echo htmlspecialchars($cat); ?>" id="edit_cat_<?php echo $index; ?>">
                                                <label class="form-check-label text-light" for="edit_cat_<?php echo $index; ?>">
                                                    <?php echo htmlspecialchars($cat); ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="edit_is_single_use" name="is_single_use" value="1">
                            <label class="form-check-label" for="edit_is_single_use">Single Use Only (Per Customer)</label>
                        </div>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active" value="1">
                            <label class="form-check-label" for="edit_is_active">Active Status</label>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gold">Save Changes</button>
                    </div>
                </form>
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

        document.querySelectorAll('.delete-promotion-form').forEach(form => {
            form.addEventListener('submit', function(event) {
                event.preventDefault();
                const promotionName = this.dataset.promotionName || 'this promotion';

                Swal.fire({
                    ...swalDark,
                    title: 'Delete Promotion?',
                    html: `This will permanently remove <strong>${promotionName}</strong>. This action cannot be undone.`,
                    icon: 'warning',
                    confirmButtonColor: '#dc3545',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, delete it',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        this.submit();
                    }
                });
            });
        });

        const createCatAll = document.getElementById('create_cat_all');
        const createCatCheckboxes = document.querySelectorAll('.create-category-checkbox');
        if (createCatAll) {
            createCatAll.addEventListener('change', function() {
                if (this.checked) {
                    createCatCheckboxes.forEach(cb => cb.checked = false);
                }
            });
            createCatCheckboxes.forEach(cb => {
                cb.addEventListener('change', function() {
                    if (this.checked) {
                        createCatAll.checked = false;
                    }
                });
            });
        }

        // Edit Modal JS
        const editCatAll = document.getElementById('edit_cat_all');
        const editCatCheckboxes = document.querySelectorAll('.edit-category-checkbox');
        if (editCatAll) {
            editCatAll.addEventListener('change', function() {
                if (this.checked) {
                    editCatCheckboxes.forEach(cb => cb.checked = false);
                }
            });
            editCatCheckboxes.forEach(cb => {
                cb.addEventListener('change', function() {
                    if (this.checked) {
                        editCatAll.checked = false;
                    }
                });
            });
        }

        const editStartDate = document.getElementById('edit_start_date');
        const editEndDate = document.getElementById('edit_end_date');
        if (editStartDate && editEndDate) {
            editStartDate.addEventListener('change', function() {
                editEndDate.min = this.value;
                if (editEndDate.value && editEndDate.value < this.value) {
                    editEndDate.value = this.value;
                }
            });
        }

        document.querySelectorAll('.btn-edit').forEach(btn => {
            btn.addEventListener('click', function() {
                const promo = JSON.parse(this.dataset.promo);

                document.getElementById('edit_promotion_id').value = promo.promotion_id;
                document.getElementById('edit_promotion_name').value = promo.promotion_name;
                document.getElementById('edit_description').value = promo.description;
                document.getElementById('edit_discount_type').value = promo.discount_type;
                document.getElementById('edit_discount_value').value = promo.discount_value;
                document.getElementById('edit_start_date').value = promo.start_date;
                document.getElementById('edit_end_date').value = promo.end_date;

                // Allow the original start date to be valid even if it's in the past
                const phpToday = "<?php echo date('Y-m-d'); ?>";
                document.getElementById('edit_start_date').min = promo.start_date < phpToday ? promo.start_date : phpToday;
                document.getElementById('edit_end_date').min = promo.start_date;

                document.getElementById('edit_is_single_use').checked = promo.is_single_use == 1;
                document.getElementById('edit_is_active').checked = promo.is_active == 1;

                // Handle categories
                editCatCheckboxes.forEach(cb => cb.checked = false);
                editCatAll.checked = false;

                if (!promo.applicable_category) {
                    editCatAll.checked = true;
                } else {
                    const cats = promo.applicable_category.split(',');
                    cats.forEach(c => {
                        const cb = Array.from(editCatCheckboxes).find(box => box.value === c);
                        if (cb) cb.checked = true;
                    });
                }
            });
        });

        <?php if ($success): ?>
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: <?php echo json_encode($success); ?>,
                showConfirmButton: false,
                timer: 2600,
                timerProgressBar: true,
                background: '#1e1e1e',
                color: '#ffffff',
                iconColor: '#d4af37'
            });
        <?php endif; ?>
    </script>
</body>

</html>