<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (!isLoggedIn() || (!isStaff() && !isAdmin())) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$inventoryStaffPage = true;
require __DIR__ . '/../../config/inventory_page.php';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management | Staff Panel</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@sweetalert2/theme-dark@4/dark.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --gold: #d4af37;
            --gold-hover: #b8941f;
            --dark-bg: #121212;
            --card-bg: #1e1e1e;
            --card-bg-2: #2a2a2a;
            --border-color: #333;
            --inventory-font: 'Montserrat', 'Segoe UI', sans-serif;
        }

        body {
            background-color: var(--dark-bg);
            color: #e0e0e0;
            font-family: var(--inventory-font);
        }

        .text-primary-gold {
            color: var(--gold) !important;
        }

        /* ── NAVBAR ───────────────────────────────────────────── */
        .navbar {
            background-color: #000 !important;
            border-bottom: 1px solid var(--border-color);
        }

        /* ── STAT CARDS ───────────────────────────────────────── */
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px 20px;
            height: 100%;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            border-color: var(--gold);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3);
        }

        .stat-card .stat-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #888;
            margin-bottom: 4px;
        }

        .stat-card .stat-value {
            font-size: clamp(1.5rem, 4vw, 2rem);
            font-weight: 700;
        }

        /* ── BUTTONS ──────────────────────────────────────────── */
        .btn-gold {
            background-color: var(--gold);
            color: #1a1a1a;
            border-color: var(--gold);
            font-weight: 600;
        }

        .btn-gold:hover {
            background-color: var(--gold-hover);
            color: #1a1a1a;
        }

        .btn-outline-gold {
            border-color: var(--gold);
            color: var(--gold);
            font-weight: 600;
        }

        .btn-outline-gold:hover {
            background-color: var(--gold);
            color: #1a1a1a;
        }

        .btn-delete-ingredient {
            border-color: #dc3545;
            color: #dc3545;
        }

        .btn-delete-ingredient:hover {
            background-color: #dc3545;
            color: #fff;
        }

        /* ── SEARCH & FILTERS ─────────────────────────────────── */
        .search-box {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: #f0f0f0;
            border-radius: 8px;
            padding: 8px 14px;
            font-size: 0.9rem;
            font-family: var(--inventory-font);
            width: 100%;
            max-width: 300px;
        }

        .search-box:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 0 2px rgba(212, 175, 55, 0.15);
        }

        .search-box::placeholder {
            color: #666;
        }

        .filter-pills {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-pill {
            font-size: 0.8rem;
            padding: 6px 16px;
            border-radius: 20px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: #aaa;
            cursor: pointer;
            transition: 0.2s;
            font-family: var(--inventory-font);
            font-weight: 500;
        }

        .filter-pill:hover,
        .filter-pill.active {
            background: var(--gold);
            color: #000;
            border-color: var(--gold);
            font-weight: 700;
        }

        /* ── TABLE (desktop) ──────────────────────────────────── */
        .table-dark-inv {
            --bs-table-bg: var(--card-bg);
            --bs-table-color: #e0e0e0;
            --bs-table-border-color: var(--border-color);
            --bs-table-hover-bg: #2a2a2a;
        }

        .table-dark-inv thead th {
            background-color: #1a1a1a;
            color: var(--gold);
            border-color: var(--border-color);
            white-space: nowrap;
            padding: 14px 16px;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table-dark-inv tbody td {
            border-color: var(--border-color);
            vertical-align: middle;
            padding: 12px 16px;
        }

        /* ── MOBILE CARDS ─────────────────────────────────────── */
        .mobile-cards {
            display: none;
        }

        @media (max-width: 767px) {
            .desktop-table {
                display: none !important;
            }

            .mobile-cards {
                display: block;
            }
        }

        .inv-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
        }

        .inv-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 12px;
            gap: 8px;
        }

        .inv-card-title {
            font-weight: 600;
            font-size: 1.1rem;
            color: #143d4a;
        }

        .inv-card-id {
            font-size: 0.75rem;
            color: #888;
        }

        .inv-card-meta {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-bottom: 14px;
        }

        .inv-meta-item {
            background: #121212;
            border-radius: 8px;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
        }

        .inv-meta-item .meta-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #888;
            display: block;
        }

        .inv-meta-item .meta-value {
            font-size: 0.95rem;
            font-weight: 600;
        }

        .inv-meta-item.full-width {
            grid-column: 1 / -1;
        }

        .inv-card-actions {
            display: flex;
            gap: 8px;
        }

        .inv-card-actions .btn {
            flex: 1;
            font-size: 0.85rem;
            padding: 8px;
        }

        .delete-ingredient-form {
            display: inline-block;
        }

        /* ── STATUS ───────────────────────────────────────────── */
        .stock-low {
            color: #dc3545;
            font-weight: 700;
        }

        .stock-ok {
            color: #198754;
            font-weight: 600;
        }

        .status-badge {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: bold;
            text-transform: uppercase;
        }

        .bg-low {
            background: #431b1b;
            color: #e07575;
        }

        .bg-ok {
            background: #1b4332;
            color: #75e0a3;
        }

        /* ── MODALS ───────────────────────────────────────────── */
        .modal-content {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: #e0e0e0;
            border-radius: 12px;
        }

        .modal-header {
            background: #121212;
            border-bottom: 1px solid var(--border-color);
        }

        .modal-footer {
            border-top: 1px solid var(--border-color);
        }

        .form-control,
        .form-select {
            background-color: #1a1a1a;
            border: 1px solid var(--border-color);
            color: #e0e0e0;
        }

        .form-control:focus,
        .form-select:focus {
            background-color: #1a1a1a;
            color: #e0e0e0;
            border-color: var(--gold);
            box-shadow: 0 0 0 2px rgba(212, 175, 55, 0.25);
        }

        /* ── TOOLBAR ──────────────────────────────────────────── */
        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 20px;
        }

        @media (max-width: 575px) {
            .search-box {
                max-width: 100%;
            }

            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
    <link rel="stylesheet" href="../staff-page-theme.css">
    <link rel="stylesheet" href="ingredient-modal.css">
    <link rel="stylesheet" href="../../config/inventory-layout.css?v=<?= filemtime(__DIR__ . '/../../config/inventory-layout.css') ?>">
</head>

<body>

    <!-- ── NAVBAR ─────────────────────────────────────────────── -->
    <?php require __DIR__ . '/../../config/inventory_header.php'; ?>

    <div class="inventory-content">
        <?php require __DIR__ . '/../../config/inventory_heading.php'; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show bg-danger text-white border-0" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show bg-success text-white border-0" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- ── STAT CARDS ────────────────────────────────────────── -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-4">
                <div class="stat-card" style="border-left: 4px solid var(--gold);">
                    <div class="stat-label">Total Ingredients</div>
                    <div class="stat-value text-primary-gold"><?php echo $totalItems; ?></div>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <div class="stat-card" style="border-left: 4px solid #dc3545;">
                    <div class="stat-label">Low Stock Items</div>
                    <div class="stat-value text-danger"><?php echo $lowStock; ?></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="stat-card" style="border-left: 4px solid #198754;">
                    <div class="stat-label">Total Stock Value</div>
                    <div class="stat-value text-success">₱<?php echo number_format($totalValue, 2); ?></div>
                </div>
            </div>
        </div>

        <!-- ── MAIN CARD ─────────────────────────────────────────── -->
        <div class="card" style="background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px;">
            <div class="card-body p-4">

                <!-- Toolbar -->
                <div class="toolbar">
                    <div class="d-flex align-items-center gap-3 flex-wrap w-100">
                        <input type="text" class="search-box" id="inv-search" placeholder="Search ingredients...">
                        <div class="filter-pills flex-grow-1">
                            <button class="filter-pill active" data-filter="all">All Items</button>
                            <button class="filter-pill" data-filter="low">
                                <i class="fas fa-exclamation-triangle me-1"></i>Low Stock
                            </button>
                            <button class="filter-pill" data-filter="ok">
                                <i class="fas fa-check-circle me-1"></i>In Stock
                            </button>
                        </div>
                        <span class="text-secondary fw-bold" id="row-count"><?php echo $totalItems; ?> items</span>
                    </div>
                </div>

                <!-- ── DESKTOP TABLE ──────────────────────────────── -->
                <div class="desktop-table table-responsive border rounded" style="border-color: var(--border-color) !important;">
                    <table class="table table-dark-inv table-hover mb-0" id="inv-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Unit</th>
                                <th>Cost/Unit</th>
                                <th>Total Stock</th>
                                <th>Quantity In</th>
                                <th>Quantity Out</th>
                                <th>Reorder Level</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ingredients as $item): ?>
                                <tr data-status="<?php echo $item['current_stock'] <= $item['reorder_level'] ? 'low' : 'ok'; ?>"
                                    data-search="<?php echo strtolower(htmlspecialchars($item['ingredient_name'] . ' ' . ($item['supplier_name'] ?? ''))); ?>">
                                    <td class="text-primary-gold fw-bold">#<?php echo htmlspecialchars($item['ingredient_id']); ?></td>
                                    <td><strong style="color:#143d4a;"><?php echo htmlspecialchars($item['ingredient_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($item['unit']); ?></td>
                                    <td>₱<?php echo number_format($item['unit_price'] ?? 0, 2); ?></td>
                                    <td>
                                        <span class="<?php echo $item['current_stock'] <= $item['reorder_level'] ? 'stock-low' : ''; ?>">
                                            <?php echo htmlspecialchars($item['current_stock']); ?>
                                        </span>
                                    </td>
                                    <td><?= number_format((float)$item['quantity_in'], 2) ?></td>
                                    <td><?= number_format((float)$item['quantity_out'], 2) ?></td>
                                    <td class="text-secondary"><?php echo htmlspecialchars($item['reorder_level']); ?></td>
                                    <td>
                                        <?php if ($item['current_stock'] <= $item['reorder_level']): ?>
                                            <span class="status-badge bg-low"><i class="fas fa-exclamation-triangle me-1"></i>Low</span>
                                        <?php else: ?>
                                            <span class="status-badge bg-ok"><i class="fas fa-check-circle me-1"></i>OK</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <button class="btn btn-sm btn-outline-primary me-1"
                                            data-bs-toggle="modal"
                                            data-bs-target="#editModal<?php echo $item['ingredient_id']; ?>" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-sm btn-gold"
                                            data-bs-toggle="modal"
                                            data-bs-target="#updateModal<?php echo $item['ingredient_id']; ?>" title="Update Stock">
                                            <i class="fas fa-boxes"></i>
                                        </button>
                                        <form method="POST" class="delete-ingredient-form ms-1" data-ingredient-name="<?php echo htmlspecialchars($item['ingredient_name']); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($inventoryCsrf); ?>">
                                            <input type="hidden" name="action" value="delete_ingredient">
                                            <input type="hidden" name="ingredient_id" value="<?php echo $item['ingredient_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-delete-ingredient" title="Delete Ingredient">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ── MOBILE CARDS ───────────────────────────────── -->
                <div class="mobile-cards" id="inv-cards">
                    <?php foreach ($ingredients as $item):
                        $isLow = $item['current_stock'] <= $item['reorder_level'];
                    ?>
                        <div class="inv-card"
                            data-status="<?php echo $isLow ? 'low' : 'ok'; ?>"
                            data-search="<?php echo strtolower(htmlspecialchars($item['ingredient_name'] . ' ' . ($item['supplier_name'] ?? ''))); ?>">

                            <div class="inv-card-header">
                                <div>
                                    <div class="inv-card-title"><?php echo htmlspecialchars($item['ingredient_name']); ?></div>
                                    <div class="inv-card-id">ID #<?php echo htmlspecialchars($item['ingredient_id']); ?></div>
                                </div>
                                <?php if ($isLow): ?>
                                    <span class="status-badge bg-low"><i class="fas fa-exclamation-triangle"></i></span>
                                <?php else: ?>
                                    <span class="status-badge bg-ok"><i class="fas fa-check-circle"></i></span>
                                <?php endif; ?>
                            </div>

                            <div class="inv-card-meta">
                                <div class="inv-meta-item">
                                    <span class="meta-label">Unit</span>
                                    <span class="meta-value"><?php echo htmlspecialchars($item['unit']); ?></span>
                                </div>
                                <div class="inv-meta-item">
                                    <span class="meta-label">Cost / Unit</span>
                                    <span class="meta-value text-primary-gold">₱<?php echo number_format($item['unit_price'] ?? 0, 2); ?></span>
                                </div>
                                <div class="inv-meta-item">
                                    <span class="meta-label">Total Stock</span>
                                    <span class="meta-value <?php echo $isLow ? 'stock-low' : ''; ?>">
                                        <?php echo htmlspecialchars($item['current_stock']); ?>
                                    </span>
                                </div>
                                <div class="inv-meta-item">
                                    <span class="meta-label">Reorder Level</span>
                                    <span class="meta-value text-secondary"><?php echo htmlspecialchars($item['reorder_level']); ?></span>
                                </div>
                                <div class="inv-meta-item"><span class="meta-label">Quantity In</span><span class="meta-value"><?= number_format((float)$item['quantity_in'], 2) ?></span></div>
                                <div class="inv-meta-item"><span class="meta-label">Quantity Out</span><span class="meta-value"><?= number_format((float)$item['quantity_out'], 2) ?></span></div>
                            </div>

                            <div class="inv-card-actions">
                                <button class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editModal<?php echo $item['ingredient_id']; ?>">
                                    <i class="fas fa-edit me-1"></i>Edit
                                </button>
                                <button class="btn btn-sm btn-gold"
                                    data-bs-toggle="modal"
                                    data-bs-target="#updateModal<?php echo $item['ingredient_id']; ?>">
                                    <i class="fas fa-boxes me-1"></i>Stock
                                </button>
                                <form method="POST" class="delete-ingredient-form flex-fill" data-ingredient-name="<?php echo htmlspecialchars($item['ingredient_name']); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($inventoryCsrf); ?>">
                                    <input type="hidden" name="action" value="delete_ingredient">
                                    <input type="hidden" name="ingredient_id" value="<?php echo $item['ingredient_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-delete-ingredient w-100">
                                        <i class="fas fa-trash-alt me-1"></i>Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Empty state -->
                <div id="empty-state" class="text-center py-5 text-secondary" style="display:none!important;">
                    <i class="fas fa-search fa-3x mb-3 opacity-50"></i>
                    <h5 class="text-light">No ingredients found</h5>
                    <p class="mb-0">Try adjusting your search or filters.</p>
                </div>

                <div class="d-flex flex-column flex-md-row align-items-center justify-content-between gap-3 mt-4" id="pagination-wrapper" style="display:none;">
                    <div class="small text-secondary" id="pagination-info"></div>
                    <div class="d-flex flex-wrap justify-content-center gap-2" id="pagination-controls"></div>
                </div>

            </div>
        </div>
    </div>

    <!-- ══ MODALS (outside container for proper stacking) ══════ -->
    <?php foreach ($ingredients as $item): ?>

        <!-- Update Stock Modal -->
        <div class="modal fade" id="updateModal<?php echo $item['ingredient_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="mb-0 text-primary-gold"><i class="fas fa-boxes me-2"></i>Update Stock</h5>
                            <small class="text-secondary"><?php echo htmlspecialchars($item['ingredient_name']); ?></small>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($inventoryCsrf); ?>">
                        <div class="modal-body">
                            <input type="hidden" name="action" value="update_stock">
                            <input type="hidden" name="ingredient_id" value="<?php echo $item['ingredient_id']; ?>">
                            <div class="mb-3">
                                <label class="form-label text-light fw-bold">
                                    Current Stock (<?php echo htmlspecialchars($item['unit']); ?>)
                                </label>
                                <input type="number" class="form-control form-control-lg" name="new_stock"
                                    value="<?php echo $item['current_stock']; ?>" required step="0.01" min="0">
                                <div class="form-text text-secondary mt-2">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Reorder level is set to: <?php echo $item['reorder_level']; ?> <?php echo $item['unit']; ?>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-gold px-4">Save Changes</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Edit Ingredient Modal -->
        <div class="modal fade" id="editModal<?php echo $item['ingredient_id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <div>
                            <h5 class="mb-0 text-primary-gold"><i class="fas fa-edit me-2"></i>Edit Ingredient</h5>
                            <small class="text-secondary">ID #<?php echo htmlspecialchars($item['ingredient_id']); ?></small>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($inventoryCsrf); ?>">
                        <div class="modal-body">
                            <input type="hidden" name="action" value="edit_ingredient">
                            <input type="hidden" name="ingredient_id" value="<?php echo $item['ingredient_id']; ?>">

                            <div class="mb-3">
                                <label class="form-label text-light fw-bold">Ingredient Name</label>
                                <input type="text" class="form-control" name="ingredient_name"
                                    value="<?php echo htmlspecialchars($item['ingredient_name']); ?>" required>
                            </div>

                            <div class="row mb-3 g-3">
                                <div class="col-6">
                                    <label class="form-label text-light fw-bold">Unit</label>
                                    <input type="text" class="form-control" name="unit"
                                        value="<?php echo htmlspecialchars($item['unit']); ?>" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label text-light fw-bold">Cost per Unit (₱)</label>
                                    <input type="number" class="form-control" name="unit_price"
                                        value="<?php echo $item['unit_price']; ?>" step="0.01" min="0" required>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label text-light fw-bold">Reorder Level</label>
                                <input type="number" class="form-control" name="reorder_level"
                                    value="<?php echo $item['reorder_level']; ?>" step="0.01" min="0" required>
                                <div class="form-text text-secondary">
                                    <i class="fas fa-info-circle me-1"></i>Stock limit to trigger 'Low Stock' warning.
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label text-light fw-bold">Supplier</label>
                                <select class="form-select" name="supplier_id">
                                    <option value="">-- No Supplier Selected --</option>
                                    <?php foreach ($suppliers as $sup): ?>
                                        <option value="<?php echo $sup['supplier_id']; ?>"
                                            <?php echo ($item['supplier_id'] == $sup['supplier_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sup['supplier_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary px-4">Update Ingredient</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

    <?php endforeach; ?>

    <?php require __DIR__ . '/ingredient-modal.php'; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        const addIngredientModal = document.getElementById('addIngredientModal');
        addIngredientModal.addEventListener('shown.bs.modal', () => {
            (document.getElementById('add-ingredient-error') || document.getElementById('add-ingredient-name')).focus();
        });
        addIngredientModal.addEventListener('hidden.bs.modal', () => {
            document.getElementById('add-ingredient-trigger').focus();
        });
        if (<?php echo json_encode($addError !== '' || isset($_GET['add'])); ?>) {
            bootstrap.Modal.getOrCreateInstance(addIngredientModal).show();
        }
        document.getElementById('add-ingredient-form').addEventListener('submit', () => {
            const submitButton = document.getElementById('save-ingredient');
            submitButton.disabled = true;
            submitButton.textContent = 'Saving...';
        });

        // ── SEARCH + FILTER ────────────────────────────────────────
        const searchInput = document.getElementById('inv-search');
        const filterPills = document.querySelectorAll('.filter-pill');
        const rowCount = document.getElementById('row-count');
        const emptyState = document.getElementById('empty-state');
        const paginationWrap = document.getElementById('pagination-wrapper');
        const paginationInfo = document.getElementById('pagination-info');
        const paginationControls = document.getElementById('pagination-controls');
        const rows = Array.from(document.querySelectorAll('#inv-table tbody tr'));
        const cards = Array.from(document.querySelectorAll('#inv-cards .inv-card'));
        const perPage = 10;
        let activeFilter = 'all';
        let currentPage = 1;

        function renderPagination(totalVisible) {
            const totalPages = Math.max(1, Math.ceil(totalVisible / perPage));
            currentPage = Math.min(currentPage, totalPages);
            const start = totalVisible === 0 ? 0 : ((currentPage - 1) * perPage) + 1;
            const end = totalVisible === 0 ? 0 : Math.min(currentPage * perPage, totalVisible);

            paginationControls.innerHTML = '';

            if (totalVisible === 0) {
                paginationWrap.style.display = 'none';
                return {
                    startIndex: 0,
                    endIndex: 0
                };
            }

            paginationWrap.style.display = 'flex';
            paginationInfo.textContent = `Showing ${start}-${end} of ${totalVisible}`;

            if (totalPages <= 1) {
                return {
                    startIndex: 0,
                    endIndex: totalVisible
                };
            }

            function makeButton(label, page, disabled, active = false) {
                const button = document.createElement(disabled || active ? 'span' : 'button');
                button.className = active ?
                    'btn btn-sm btn-warning text-dark' :
                    'btn btn-sm btn-outline-secondary';
                button.textContent = label;
                if (disabled) {
                    button.classList.add('disabled');
                } else if (!active) {
                    button.type = 'button';
                    button.addEventListener('click', () => {
                        currentPage = page;
                        applyFilters();
                    });
                }
                paginationControls.appendChild(button);
            }

            makeButton('Prev', currentPage - 1, currentPage === 1);

            for (let page = 1; page <= totalPages; page++) {
                if (page === 1 || page === totalPages || (page >= currentPage - 2 && page <= currentPage + 2)) {
                    makeButton(String(page), page, false, page === currentPage);
                } else if (
                    page === currentPage - 3 ||
                    page === currentPage + 3
                ) {
                    const ellipsis = document.createElement('span');
                    ellipsis.className = 'btn btn-sm btn-outline-secondary disabled';
                    ellipsis.textContent = '...';
                    paginationControls.appendChild(ellipsis);
                }
            }

            makeButton('Next', currentPage + 1, currentPage === totalPages);

            return {
                startIndex: (currentPage - 1) * perPage,
                endIndex: currentPage * perPage,
            };
        }

        function applyFilters(resetPage = false) {
            const q = searchInput.value.toLowerCase().trim();
            if (resetPage) currentPage = 1;
            let visible = 0;
            const visibleRows = [];
            const visibleCards = [];

            function matches(el) {
                const search = (el.dataset.search || el.textContent).toLowerCase();
                const status = el.dataset.status || '';
                const searchOk = !q || search.includes(q);
                const filterOk = activeFilter === 'all' || status === activeFilter;
                return searchOk && filterOk;
            }

            rows.forEach(r => {
                r.style.display = 'none';
                if (matches(r)) {
                    visibleRows.push(r);
                    visible++;
                }
            });

            cards.forEach(c => {
                c.style.display = 'none';
                if (matches(c)) {
                    visibleCards.push(c);
                }
            });

            const pageState = renderPagination(visible);
            visibleRows.slice(pageState.startIndex, pageState.endIndex).forEach(r => {
                r.style.display = '';
            });
            visibleCards.slice(pageState.startIndex, pageState.endIndex).forEach(c => {
                c.style.display = '';
            });

            rowCount.textContent = visible + ' item' + (visible !== 1 ? 's' : '');
            emptyState.style.setProperty('display', visible === 0 ? 'block' : 'none', 'important');
        }

        searchInput.addEventListener('input', () => applyFilters(true));

        filterPills.forEach(pill => {
            pill.addEventListener('click', function() {
                filterPills.forEach(p => p.classList.remove('active'));
                this.classList.add('active');
                activeFilter = this.dataset.filter;
                applyFilters(true);
            });
        });

        applyFilters();

        // ── LOGOUT CONFIRMATION ────────────────────────────────────
        const editIngredientId = <?php echo json_encode(isset($_GET['edit']) && is_string($_GET['edit']) && ctype_digit($_GET['edit']) ? (int) $_GET['edit'] : null); ?>;
        if (editIngredientId !== null) {
            const editModalEl = document.getElementById(`editModal${editIngredientId}`);
            if (editModalEl && window.bootstrap?.Modal) {
                new bootstrap.Modal(editModalEl).show();
            }
        }

        document.querySelectorAll('.delete-ingredient-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const ingredientName = this.dataset.ingredientName || 'this ingredient';

                if (!window.Swal) {
                    if (window.confirm(`Permanently delete ${ingredientName}, linked menu recipes, and stock history?`)) this.submit();
                    return;
                }
                Swal.fire({
                    title: 'Delete Ingredient?',
                    text: `This will permanently remove "${ingredientName}" from inventory, linked menu recipes, and stock history. This action cannot be undone.`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, delete it',
                    cancelButtonText: 'Cancel',
                    background: '#1e1e1e',
                    color: '#fff'
                }).then((result) => {
                    if (result.isConfirmed) {
                        this.submit();
                    }
                });
            });
        });

        document.querySelector('.logout-link')?.addEventListener('click', function(e) {
            e.preventDefault();
            const logoutUrl = this.getAttribute('href');
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
            }).then((result) => {
                if (result.isConfirmed) window.location.href = logoutUrl;
            });
        });
    </script>
</body>

</html>
