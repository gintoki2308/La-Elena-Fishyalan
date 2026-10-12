<header class="inventory-heading">
    <div>
        <a href="<?= $inventoryDashboard ?>" class="inventory-back"><span aria-hidden="true">&larr;</span> Back to Dashboard</a>
        <h1>Inventory Management</h1>
        <p>Manage ingredients, recipes, and every stock movement.</p>
    </div>
    <?php if ($inventoryTab === 'ingredients'): ?>
        <button type="button" class="btn btn-success inventory-add" id="add-ingredient-trigger" data-bs-toggle="modal" data-bs-target="<?= $inventoryStaffPage ? '#addIngredientModal' : '#createIngredientModal' ?>">
            <i class="fas fa-plus-circle me-2" aria-hidden="true"></i>Add New Ingredient
        </button>
    <?php endif; ?>
</header>
<?php require __DIR__ . '/inventory_navigation.php'; ?>
