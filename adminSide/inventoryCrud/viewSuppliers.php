<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();
$suppliers = [];
$error = '';
$success = '';
$showCreateModal = false;
$supplierQuery = 'SELECT s.*, COUNT(i.ingredient_id) AS ingredient_count
                  FROM suppliers s
                  LEFT JOIN ingredients i ON i.supplier_id = s.supplier_id
                  GROUP BY s.supplier_id
                  ORDER BY s.supplier_name ASC';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_supplier') {
    $showCreateModal = true;
    $supplierName = trim($_POST['supplier_name'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $postalCode = trim($_POST['postal_code'] ?? '');
    $country = trim($_POST['country'] ?? '');

    try {
        if ($supplierName === '' || $contactPerson === '' || $email === '' || $phone === '' || $address === '' || $city === '' || $state === '' || $postalCode === '' || $country === '') {
            throw new Exception('Please fill in all supplier fields.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please provide a valid email address.');
        }

        $db->prepare(
            'INSERT INTO suppliers (supplier_name, contact_person, email, phone, address, city, state, postal_code, country)
             VALUES (:name, :contact_person, :email, :phone, :address, :city, :state, :postal_code, :country)'
        )->execute([
            ':name' => $supplierName,
            ':contact_person' => $contactPerson,
            ':email' => $email,
            ':phone' => $phone,
            ':address' => $address,
            ':city' => $city,
            ':state' => $state,
            ':postal_code' => $postalCode,
            ':country' => $country,
        ]);

        $supplierId = (int) $db->lastInsertId();
        writeAuditLog($db, 'CREATE_SUPPLIER', 'suppliers', $supplierId, [
            'summary' => 'Created supplier',
            'supplier_name' => $supplierName,
            'contact_person' => $contactPerson,
        ]);
        $success = 'Supplier added successfully!';
        $showCreateModal = false;
    } catch (Exception $e) {
        $error = 'Error adding supplier: ' . $e->getMessage();
    }
}

try {
    $suppliers = $db->query($supplierQuery)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Unable to load suppliers.';
    error_log('Supplier list error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suppliers | La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
        .suppliers-page { max-width: 1440px; margin: 0 auto; }
        .btn-back { color: var(--admin-muted); padding-left: 0; font-weight: 600; }
        .btn-back:hover { color: var(--admin-accent-hover); }
        .page-heading { display: flex; align-items: center; gap: 14px; }
        .heading-icon, .empty-icon { display: inline-flex; align-items: center; justify-content: center; background: #eef8f6; color: #39776d; border-radius: 14px; flex-shrink: 0; }
        .heading-icon { width: 52px; height: 52px; font-size: 1.35rem; }
        .page-heading h1 { font-weight: 700; letter-spacing: -.6px; }
        .page-description { color: var(--admin-muted); margin: 5px 0 0; font-size: .95rem; }
        .supplier-add { padding: 11px 18px; border-radius: 9px; font-weight: 600; }
        .supplier-card { background: #fff; border: 1px solid var(--admin-border); border-radius: 16px; box-shadow: 0 4px 20px rgba(20, 61, 74, .05); }
        .supplier-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 24px; }
        .supplier-search-wrap { position: relative; width: 100%; max-width: 400px; }
        .supplier-search-wrap > i { position: absolute; top: 50%; left: 15px; transform: translateY(-50%); color: var(--admin-muted); pointer-events: none; }
        .search-box { border: 1px solid var(--admin-border); border-radius: 9px; padding: 11px 14px 11px 42px; width: 100%; }
        .search-box::placeholder { color: var(--admin-muted); }
        .search-box:focus { outline: 0; }
        .supplier-count { background: var(--admin-soft); color: var(--admin-muted); padding: 7px 12px; border-radius: 20px; font-size: .85rem; font-weight: 600; white-space: nowrap; }
        .supplier-table { min-width: 780px; }
        .supplier-table th { font-size: .72rem; letter-spacing: .65px; text-transform: uppercase; white-space: nowrap; }
        .supplier-table th, .supplier-table td { padding: 17px 24px; vertical-align: middle; }
        .supplier-table td { font-size: .9rem; overflow-wrap: anywhere; max-width: 320px; }
        .supplier-table tbody tr:last-child td { border-bottom: 0; }
        .supplier-table th:last-child, .supplier-table td:last-child { text-align: center; }
        .contact-link { color: var(--admin-ink); text-decoration: none; }
        .contact-link i { color: var(--admin-muted); width: 16px; }
        .contact-link:hover { color: var(--admin-accent-hover); text-decoration: underline; }
        .ingredient-count { background: #eef8f6; color: #32685f; border: 1px solid #d7eae5; min-width: 36px; }
        .supplier-empty { text-align: center; padding: 54px 24px 60px; color: var(--admin-muted); }
        .empty-icon { width: 72px; height: 72px; font-size: 1.8rem; margin-bottom: 20px; border-radius: 50%; }
        .supplier-empty h2 { font-size: 1.2rem; font-weight: 700; }
        .supplier-empty p { max-width: 360px; margin: 10px auto 22px; line-height: 1.6; }
        .supplier-empty .btn { font-weight: 600; border-radius: 8px; }
        #createSupplierModal .modal-header, #createSupplierModal .modal-body, #createSupplierModal .modal-footer { padding: 20px 24px; }
        #createSupplierModal .form-label { color: var(--admin-ink); font-size: .875rem; font-weight: 600; }
        #createSupplierModal .form-control { padding: 10px 12px; border-radius: 8px; }
        @media (max-width: 575.98px) {
            .navbar .navbar-brand { font-size: .78rem; white-space: normal; max-width: calc(100% - 80px); }
            .navbar > .container-fluid { padding-left: 16px !important; padding-right: 16px !important; }
            .suppliers-page { padding-left: 16px; padding-right: 16px; }
            .supplier-toolbar { padding: 16px; flex-wrap: wrap; }
            .supplier-search-wrap { max-width: none; }
            .supplier-empty { padding: 40px 20px; }
            .page-description { font-size: .875rem; }
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg sticky-top">
    <div class="container-fluid px-4">
        <a class="navbar-brand text-gold fw-bold" href="../admin-dashboard.php"><i class="fas fa-utensils me-2"></i>LA ELENA FISHYALAN ADMIN</a>
        <a href="../../auth/logout.php" class="btn btn-sm btn-outline-danger">Logout</a>
    </div>
</nav>

<main class="container-fluid suppliers-page py-4 px-lg-5">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <a href="../admin-dashboard.php" class="btn btn-sm btn-back mb-2"><i class="fas fa-arrow-left me-1"></i>Back</a>
            <div class="page-heading">
                <span class="heading-icon" aria-hidden="true"><i class="fas fa-truck"></i></span>
                <div><h1 class="h2 mb-0">Suppliers</h1><p class="page-description">Manage supplier contacts and linked ingredients.</p></div>
            </div>
        </div>
        <button type="button" class="btn btn-success supplier-add" data-bs-toggle="modal" data-bs-target="#createSupplierModal"><i class="fas fa-plus me-2" aria-hidden="true"></i>Add New Supplier</button>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success" role="alert"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <section class="supplier-card">
        <div class="card-body">
            <div class="supplier-toolbar">
                <div class="supplier-search-wrap"><i class="fas fa-search" aria-hidden="true"></i><input type="search" class="search-box" id="supplier-search" placeholder="Search suppliers..." aria-label="Search suppliers" aria-controls="supplier-table"></div>
                <span class="supplier-count" id="supplier-count" role="status"><?php echo count($suppliers); ?> supplier<?php echo count($suppliers) === 1 ? '' : 's'; ?></span>
            </div>
            <div class="table-responsive">
                <table class="table supplier-table table-hover mb-0" id="supplier-table">
                    <thead><tr><th scope="col">Supplier</th><th scope="col">Contact Person</th><th scope="col">Contact Details</th><th scope="col">Address</th><th scope="col">Ingredients</th></tr></thead>
                    <tbody>
                    <?php foreach ($suppliers as $supplier):
                        $location = array_filter([$supplier['city'], $supplier['state'], $supplier['postal_code'], $supplier['country']]);
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($supplier['supplier_name']); ?></strong></td>
                            <td><?php echo htmlspecialchars($supplier['contact_person'] ?: '—'); ?></td>
                            <td>
                                <?php if ($supplier['email']): ?><a class="contact-link d-block" href="mailto:<?php echo htmlspecialchars($supplier['email']); ?>"><i class="fas fa-envelope text-gold me-1"></i><?php echo htmlspecialchars($supplier['email']); ?></a><?php endif; ?>
                                <?php if ($supplier['phone']): ?><a class="contact-link d-block mt-1" href="tel:<?php echo htmlspecialchars($supplier['phone']); ?>"><i class="fas fa-phone text-gold me-1"></i><?php echo htmlspecialchars($supplier['phone']); ?></a><?php endif; ?>
                                <?php if (!$supplier['email'] && !$supplier['phone']): ?>—<?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars(implode(', ', array_merge($supplier['address'] ? [$supplier['address']] : [], $location)) ?: '—'); ?></td>
                            <td><span class="badge ingredient-count rounded-pill px-3 py-2"><?php echo (int) $supplier['ingredient_count']; ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="supplier-empty <?php echo $suppliers ? 'd-none' : ''; ?>" id="empty-suppliers">
                <span class="empty-icon" aria-hidden="true"><i class="fas fa-truck-loading"></i></span>
                <h2 id="empty-suppliers-title">No suppliers yet</h2>
                <p id="empty-suppliers-description">Add your first supplier to keep contact details in one place and link inventory ingredients.</p>
                <button type="button" class="btn btn-outline-gold" id="empty-suppliers-add" data-bs-toggle="modal" data-bs-target="#createSupplierModal"><i class="fas fa-plus me-2" aria-hidden="true"></i>Add your first supplier</button>
                <button type="button" class="btn btn-outline-gold d-none" id="clear-supplier-search">Clear search</button>
            </div>
        </div>
    </section>
</main>

<div class="modal fade" id="createSupplierModal" tabindex="-1" aria-labelledby="createSupplierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content supplier-card">
            <div class="modal-header border-secondary">
                <div>
                    <h5 class="modal-title text-gold" id="createSupplierModalLabel"><i class="fas fa-plus-circle me-2"></i>Add New Supplier</h5>
                    <small class="text-secondary">Enter the supplier's contact and location details.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_supplier">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="supplier-supplier_name">Supplier Name</label><input class="form-control" id="supplier-supplier_name" name="supplier_name" required value="<?php echo htmlspecialchars($_POST['supplier_name'] ?? ''); ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="supplier-contact_person">Contact Person</label><input class="form-control" id="supplier-contact_person" name="contact_person" required value="<?php echo htmlspecialchars($_POST['contact_person'] ?? ''); ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="supplier-email">Email</label><input type="email" class="form-control" id="supplier-email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"></div>
                        <div class="col-md-6"><label class="form-label" for="supplier-phone">Phone</label><input type="tel" class="form-control" id="supplier-phone" name="phone" required value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"></div>
                        <div class="col-12"><label class="form-label" for="supplier-address">Address</label><input class="form-control" id="supplier-address" name="address" required value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>"></div>
                        <div class="col-md-4"><label class="form-label" for="supplier-city">City</label><input class="form-control" id="supplier-city" name="city" required value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>"></div>
                        <div class="col-md-4"><label class="form-label" for="supplier-state">State / Province</label><input class="form-control" id="supplier-state" name="state" required value="<?php echo htmlspecialchars($_POST['state'] ?? ''); ?>"></div>
                        <div class="col-md-4"><label class="form-label" for="supplier-postal_code">Postal Code</label><input class="form-control" id="supplier-postal_code" name="postal_code" required value="<?php echo htmlspecialchars($_POST['postal_code'] ?? ''); ?>"></div>
                        <div class="col-12"><label class="form-label" for="supplier-country">Country</label><input class="form-control" id="supplier-country" name="country" required value="<?php echo htmlspecialchars($_POST['country'] ?? ''); ?>"></div>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-save me-2"></i>Add Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const supplierSearch = document.getElementById('supplier-search');
    const supplierRows = Array.from(document.querySelectorAll('#supplier-table tbody tr'));
    const supplierCount = document.getElementById('supplier-count');
    const emptySuppliers = document.getElementById('empty-suppliers');
    const emptyTitle = document.getElementById('empty-suppliers-title');
    const emptyDescription = document.getElementById('empty-suppliers-description');
    const emptyAdd = document.getElementById('empty-suppliers-add');
    const clearSearch = document.getElementById('clear-supplier-search');
    supplierSearch.addEventListener('input', () => {
        const query = supplierSearch.value.toLowerCase().trim();
        let visible = 0;
        supplierRows.forEach(row => {
            const matches = row.textContent.toLowerCase().includes(query);
            row.classList.toggle('d-none', !matches);
            if (matches) visible++;
        });
        supplierCount.textContent = `${visible} supplier${visible === 1 ? '' : 's'}`;
        emptySuppliers.classList.toggle('d-none', visible !== 0);
        emptyTitle.textContent = query ? 'No matching suppliers' : 'No suppliers yet';
        emptyDescription.textContent = query
            ? 'Try a different name, contact detail, or address.'
            : 'Add your first supplier to keep contact details in one place and link inventory ingredients.';
        emptyAdd.classList.toggle('d-none', query !== '');
        clearSearch.classList.toggle('d-none', query === '');
    });
    clearSearch.addEventListener('click', () => {
        supplierSearch.value = '';
        supplierSearch.dispatchEvent(new Event('input'));
        supplierSearch.focus();
    });

    <?php if ($showCreateModal): ?>
        new bootstrap.Modal(document.getElementById('createSupplierModal')).show();
    <?php endif; ?>
</script>
</body>
</html>
