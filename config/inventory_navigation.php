<nav class="inventory-sections" aria-label="Inventory sections">
    <?php foreach (['ingredients' => 'Ingredients', 'recipes' => 'Menu Recipes', 'stock_in' => 'Stock In', 'stock_out' => 'Stock Out', 'total_stock' => 'Total Stock'] as $tab => $label): ?>
        <a class="inventory-section" href="viewInventory.php?tab=<?= $tab ?>" <?= $inventoryTab === $tab ? 'aria-current="page"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
</nav>
