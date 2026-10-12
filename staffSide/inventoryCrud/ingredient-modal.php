<div class="modal fade ingredient-modal" id="addIngredientModal" tabindex="-1" aria-labelledby="addIngredientTitle" aria-describedby="addIngredientDescription" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" action="viewInventory.php" class="modal-content" id="add-ingredient-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($inventoryCsrf); ?>">
            <input type="hidden" name="action" value="add_ingredient">
            <div class="modal-header">
                <div class="ingredient-heading">
                    <span class="ingredient-icon"><i class="fas fa-seedling" aria-hidden="true"></i></span>
                    <div>
                        <div class="ingredient-eyebrow">INVENTORY</div>
                        <h2 class="modal-title" id="addIngredientTitle">Add new ingredient</h2>
                        <p id="addIngredientDescription">Keep your kitchen stocked and ready.</p>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ($addError !== ''): ?>
                    <div class="alert alert-danger" id="add-ingredient-error" role="alert" tabindex="-1"><?php echo htmlspecialchars($addError); ?></div>
                <?php endif; ?>
                <div class="ingredient-section-heading"><h3>Ingredient details</h3><span>* Required fields</span></div>
                <div class="mb-3">
                    <label class="form-label" for="add-ingredient-name">Ingredient name <span>*</span></label>
                    <input class="form-control" type="text" id="add-ingredient-name" name="ingredient_name" placeholder="e.g. Fresh tomatoes" value="<?php echo htmlspecialchars($addValues['ingredient_name']); ?>" required>
                </div>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="add-ingredient-unit">Unit of measurement <span>*</span></label>
                        <select class="form-select" id="add-ingredient-unit" name="unit" required>
                            <option value="">Select a unit</option>
                            <?php foreach ($ingredientUnits as $value => $label): ?>
                                <option value="<?php echo $value; ?>" <?php echo $addValues['unit'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="add-ingredient-cost">Cost per unit (&#8369;) <span>*</span></label>
                        <input class="form-control" type="number" id="add-ingredient-cost" name="cost_per_unit" placeholder="0.00" min="0" step="0.01" value="<?php echo htmlspecialchars($addValues['cost_per_unit']); ?>" required>
                    </div>
                </div>
                <div class="ingredient-stock-section">
                    <h3>Stock &amp; replenishment</h3>
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="add-ingredient-stock">Current stock <span>*</span></label>
                            <input class="form-control" type="number" id="add-ingredient-stock" name="current_stock" placeholder="0.00" min="0" step="0.01" value="<?php echo htmlspecialchars($addValues['current_stock']); ?>" aria-describedby="add-stock-help" required>
                            <div class="form-text" id="add-stock-help">Quantity available right now.</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="add-ingredient-reorder">Reorder level <span>*</span></label>
                            <input class="form-control" type="number" id="add-ingredient-reorder" name="reorder_level" placeholder="0.00" min="0" step="0.01" value="<?php echo htmlspecialchars($addValues['reorder_level']); ?>" aria-describedby="add-reorder-help" required>
                            <div class="form-text" id="add-reorder-help">Low-stock alert at this quantity.</div>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label" for="add-ingredient-supplier">Supplier <small>(optional)</small></label>
                    <select class="form-select" id="add-ingredient-supplier" name="supplier_id">
                        <option value="">No supplier assigned</option>
                        <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo (int) $supplier['supplier_id']; ?>" <?php echo $addValues['supplier_id'] === (string) $supplier['supplier_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($supplier['supplier_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn ingredient-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-gold" id="save-ingredient"><i class="fas fa-plus me-2" aria-hidden="true"></i>Add ingredient</button>
            </div>
        </form>
    </div>
</div>
