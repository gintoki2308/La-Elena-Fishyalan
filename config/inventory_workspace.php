<?php
// Included only after the inventory entry point has checked the user's role.
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$menuItems = $recipeRows = $movements = [];
$movementPage = max(1, (int)($_GET['page'] ?? 1));
$movementPages = 1;
try {
    if ($inventoryTab === 'recipes') {
        $menuItems = $db->query('SELECT m.item_id, m.item_name, COUNT(mi.menu_ingredient_id) AS ingredient_count FROM menu_items m LEFT JOIN menu_ingredients mi ON mi.item_id = m.item_id GROUP BY m.item_id, m.item_name ORDER BY m.item_name')->fetchAll(PDO::FETCH_ASSOC);
        if ($selectedMenu === '' && $menuItems) $selectedMenu = (string)$menuItems[0]['item_id'];
        $stmt = $db->prepare('SELECT ingredient_id, quantity_required AS quantity FROM menu_ingredients WHERE item_id = ? ORDER BY menu_ingredient_id');
        $stmt->execute([$selectedMenu]);
        $recipeRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_array($_POST['recipe'] ?? null)) $recipeRows = array_values(array_filter($_POST['recipe'], 'is_array'));
        if (!$recipeRows) $recipeRows = [['ingredient_id' => '', 'quantity' => '']];
    } elseif ($inventoryTab === 'stock_in' || $inventoryTab === 'stock_out') {
        $condition = $inventoryTab === 'stock_in'
            ? "(t.transaction_type = 'IN' OR (t.transaction_type = 'ADJUSTMENT' AND t.quantity > 0))"
            : "(t.transaction_type = 'OUT' OR (t.transaction_type = 'ADJUSTMENT' AND t.quantity < 0))";
        $movementPages = max(1, (int)ceil((int)$db->query("SELECT COUNT(*) FROM inventory_transactions t WHERE $condition")->fetchColumn() / 50));
        $movementPage = min($movementPage, $movementPages);
        $offset = ($movementPage - 1) * 50;
        $movements = $db->query("SELECT t.*, i.ingredient_name, i.unit FROM inventory_transactions t JOIN ingredients i ON i.ingredient_id = t.ingredient_id WHERE $condition ORDER BY t.transaction_date DESC, t.transaction_id DESC LIMIT 50 OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('Inventory workspace: ' . $e->getMessage());
    $error = 'Unable to load recipes or stock history. Please check that the recipe inventory migration has been applied.';
}
$title = ['recipes' => 'Menu Recipes', 'stock_in' => 'Stock In', 'stock_out' => 'Stock Out', 'total_stock' => 'Total Stock'][$inventoryTab];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?> | La Elena Inventory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body{background:#f3f7f8;color:#143d4a;font-family:system-ui,sans-serif}main{max-width:1400px;margin:auto;padding:32px 24px}.inventory-panel{background:#fff;border:1px solid #dce7e9;border-radius:16px;padding:24px;margin-bottom:24px}.workspace-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:24px}.btn-success{background:#23786c;border-color:#23786c}.recipe-row{display:grid;grid-template-columns:minmax(160px,1fr) minmax(130px,220px) auto;gap:12px;align-items:end;margin-bottom:16px}.table{--bs-table-color:#143d4a}.table th{white-space:nowrap}.stock-help{color:#526c73;font-size:.9rem}.form-label{font-weight:600}.recipe-row .form-control,.recipe-row .form-select{min-height:44px}h1{font-weight:700}h2{font-size:1.2rem;font-weight:650}header{margin-bottom:24px}.table td{vertical-align:middle}@media(max-width:850px){.workspace-grid{grid-template-columns:1fr}.movement-form{grid-row:1}}@media(max-width:560px){main{padding:20px 12px}.inventory-panel{padding:16px}.recipe-row{grid-template-columns:1fr auto}.recipe-row>div:first-child{grid-column:1/-1}}
    </style>
    <style>
        .workspace-grid > section{min-width:0}.workspace-grid .table{min-width:680px}.workspace-grid .table td:first-child{white-space:nowrap}
        @media(max-width:850px){.workspace-grid{grid-template-columns:minmax(0,1fr)}}
    </style>
    <?php if ($inventoryStaffPage): ?><link rel="stylesheet" href="../staff-page-theme.css"><?php endif; ?>
    <link rel="stylesheet" href="../../config/inventory-layout.css?v=<?= filemtime(__DIR__ . '/inventory-layout.css') ?>">
    <?php if ($inventoryStaffPage): ?><script src="../../adminSide/admin-logout.js" defer></script><?php endif; ?>
</head>
<body>
<?php require __DIR__ . '/inventory_header.php'; ?>
<main class="inventory-content">
    <?php require __DIR__ . '/inventory_heading.php'; ?>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= $escape($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success" role="status"><?= $escape($success) ?></div><?php endif; ?>
    <?php if ($inventoryTab === 'total_stock'): ?>
        <section class="inventory-panel" aria-labelledby="total-stock-heading">
            <h2 id="total-stock-heading">Total Stock</h2>
            <p class="stock-help">Remaining stock for every ingredient after stock in and stock out. For example, 100 pieces in and 10 pieces out leaves 90 pieces.</p>
            <div class="table-responsive">
                <table class="table" id="total-stock-table">
                    <thead><tr><th scope="col">Ingredient</th><th scope="col">Unit</th><th scope="col" class="text-end">Stock In</th><th scope="col" class="text-end">Stock Out</th><th scope="col" class="text-end">Total Stock</th><th scope="col">Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($ingredients as $ingredient): ?>
                        <?php
                        $isLow = $ingredient['current_stock'] <= $ingredient['reorder_level'];
                        ?>
                        <tr data-ingredient-id="<?= (int)$ingredient['ingredient_id'] ?>">
                            <th scope="row"><?= $escape($ingredient['ingredient_name']) ?></th>
                            <td><?= $escape($ingredient['unit']) ?></td>
                            <td class="text-end"><?= number_format((float)$ingredient['quantity_in'], 2) ?></td>
                            <td class="text-end"><?= number_format((float)$ingredient['quantity_out'], 2) ?></td>
                            <td class="text-end fw-bold <?= $isLow ? 'text-danger' : 'text-success' ?>"><?= number_format((float)$ingredient['current_stock'], 2) ?></td>
                            <td><?= (float)$ingredient['current_stock'] <= 0 ? 'Out of stock' : ($isLow ? 'Low stock' : 'In stock') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$ingredients): ?><tr><td colspan="6" class="text-center py-5">No ingredients to display.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="stock-help mb-0">Stock in includes returns and increases; stock out includes order usage and decreases. Each ingredient keeps its own unit.</p>
        </section>
    <?php elseif ($inventoryTab === 'recipes'): ?>
        <section class="inventory-panel">
            <h2>Ingredients per serving</h2>
            <p class="stock-help">Select a food to load its recipe, then add its ingredients and quantities for one serving. Save your changes before switching foods. Customer orders and completed POS sales deduct these quantities multiplied by the servings ordered.</p>
            <div class="mb-3">
                <label class="form-label" for="menu-food-search">Search food / menu item</label>
                <input type="search" class="form-control" id="menu-food-search" placeholder="Search by food or menu item name..." autocomplete="off" aria-controls="menu-food" aria-describedby="menu-food-search-status" <?= !$menuItems ? 'disabled' : '' ?>>
                <p id="menu-food-search-status" class="stock-help mt-2 mb-0" role="status" hidden></p>
            </div>
            <form method="get" id="recipe-picker" class="d-flex flex-wrap gap-2 align-items-end mb-4">
                <input type="hidden" name="tab" value="recipes">
                <div class="flex-grow-1 recipe-food-select"><label class="form-label" for="menu-food">Food / menu item</label><select class="form-select" id="menu-food" name="item_id" required aria-describedby="menu-food-search-status">
                    <?php foreach ($menuItems as $menuItem): ?><option value="<?= (int)$menuItem['item_id'] ?>" data-name="<?= $escape($menuItem['item_name']) ?>" <?= (string)$menuItem['item_id'] === $selectedMenu ? 'selected' : '' ?>><?= $escape($menuItem['item_name']) ?> (<?= (int)$menuItem['ingredient_count'] ?> ingredients)</option><?php endforeach; ?>
                </select></div><button class="btn btn-outline-secondary" id="load-menu-recipe" <?= !$menuItems ? 'disabled' : '' ?>>Load recipe</button>
            </form>
            <?php if ($menuItems): ?>
            <form method="post" id="recipe-form">
                <input type="hidden" name="csrf_token" value="<?= $escape($inventoryCsrf) ?>"><input type="hidden" name="action" value="save_recipe"><input type="hidden" name="item_id" value="<?= $escape($selectedMenu) ?>">
                <fieldset id="recipe-editor">
                <div id="recipe-rows"></div>
                <div class="d-flex gap-2 flex-wrap mt-3"><button type="button" class="btn btn-outline-secondary" id="add-recipe-ingredient">+ Add ingredient</button><button class="btn btn-success">Save recipe</button></div>
                </fieldset>
                <p id="recipe-loading" class="stock-help mt-3 mb-0" role="status" hidden>Loading the selected food's recipe...</p>
                <p class="stock-help mt-3 mb-0">Use the ingredient's inventory unit: for example, 200 for grams, or 0.20 for kilograms. An empty recipe keeps the food on portion stock only. Changes apply to future orders.</p>
            </form>
            <?php else: ?><p>Add a menu item before creating a recipe.</p><?php endif; ?>
        </section>
        <template id="recipe-row-template"><div class="recipe-row"><div><label class="form-label">Ingredient</label><select class="form-select recipe-ingredient" required><option value="">Select ingredient</option><?php foreach ($ingredients as $ingredient): ?><option value="<?= (int)$ingredient['ingredient_id'] ?>" data-unit="<?= $escape($ingredient['unit']) ?>"><?= $escape($ingredient['ingredient_name'] . ' (' . $ingredient['unit'] . ')') ?></option><?php endforeach; ?></select></div><div><label class="form-label">Quantity / serving <span class="recipe-unit"></span></label><input class="form-control recipe-quantity" type="number" min="0.01" max="99999999.99" step="0.01" required></div><button class="btn btn-outline-danger remove-recipe" type="button">Remove</button></div></template>
        <script>
            const recipeRows = <?= json_encode($recipeRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const rowsElement = document.getElementById('recipe-rows');
            let recipeIndex = 0;
            function addRecipeRow(value = {}) {
                if (!rowsElement) return;
                const row = document.getElementById('recipe-row-template').content.firstElementChild.cloneNode(true);
                const select = row.querySelector('select'), input = row.querySelector('input');
                const index = recipeIndex++;
                select.name = `recipe[${index}][ingredient_id]`; input.name = `recipe[${index}][quantity]`;
                select.id = `recipe-ingredient-${index}`; input.id = `recipe-quantity-${index}`;
                row.querySelectorAll('label')[0].htmlFor = select.id; row.querySelectorAll('label')[1].htmlFor = input.id;
                select.value = value.ingredient_id ?? ''; input.value = value.quantity ?? '';
                const updateUnit = () => { row.querySelector('.recipe-unit').textContent = select.selectedOptions[0]?.dataset.unit || ''; };
                select.addEventListener('change', updateUnit); updateUnit();
                row.querySelector('button').addEventListener('click', () => row.remove()); rowsElement.append(row);
            }
            recipeRows.forEach(addRecipeRow);
            document.getElementById('add-recipe-ingredient')?.addEventListener('click', () => addRecipeRow());
            const recipePicker = document.getElementById('recipe-picker');
            const menuFood = document.getElementById('menu-food');
            const recipeForm = document.getElementById('recipe-form');
            const loadedMenuId = recipeForm?.elements.namedItem('item_id').value;
            const menuSearch = document.getElementById('menu-food-search');
            const menuSearchStatus = document.getElementById('menu-food-search-status');
            const loadRecipeButton = document.getElementById('load-menu-recipe');
            const menuOptions = Array.from(menuFood.options);
            function filterMenuItems() {
                const query = menuSearch.value.trim().toLocaleLowerCase();
                const selectedId = menuFood.value || loadedMenuId;
                const matches = menuOptions.filter(option => option.dataset.name.toLocaleLowerCase().includes(query));
                menuFood.replaceChildren(...matches);
                if (matches.some(option => option.value === selectedId)) {
                    menuFood.value = selectedId;
                } else {
                    const placeholder = new Option(matches.length ? 'Select a matching food / menu item' : 'No menu items found', '', true, true);
                    placeholder.disabled = true;
                    menuFood.prepend(placeholder);
                    menuFood.value = '';
                }
                menuFood.disabled = matches.length === 0;
                loadRecipeButton.disabled = !menuFood.value;
                menuSearchStatus.hidden = !query;
                menuSearchStatus.textContent = matches.length
                    ? `${matches.length} menu item${matches.length === 1 ? '' : 's'} found. Select an item to load its recipe.`
                    : 'No menu items found. Try another name or clear your search.';
            }
            menuSearch.addEventListener('input', filterMenuItems);
            menuSearch.addEventListener('keydown', event => {
                if (event.key === 'Enter') event.preventDefault();
            });
            recipePicker.addEventListener('submit', () => {
                // Keep the previous food's fields out of view while the new recipe loads.
                const editor = document.getElementById('recipe-editor');
                if (editor) {
                    editor.disabled = true;
                    editor.hidden = true;
                    document.getElementById('recipe-loading').hidden = false;
                }
            });
            menuFood.addEventListener('change', () => {
                loadRecipeButton.disabled = !menuFood.value;
                if (menuFood.value) recipePicker.requestSubmit();
            });
            recipeForm?.addEventListener('submit', event => {
                if (menuFood.value && menuFood.value !== loadedMenuId) {
                    event.preventDefault();
                    recipePicker.requestSubmit();
                }
            });
            // Back/forward navigation can restore the picker independently of the form.
            window.addEventListener('pageshow', () => {
                if (!recipeForm) return;
                menuFood.value = loadedMenuId;
                filterMenuItems();
                const editor = document.getElementById('recipe-editor');
                editor.disabled = false;
                editor.hidden = false;
                document.getElementById('recipe-loading').hidden = true;
            });
        </script>
    <?php else: ?>
        <div class="workspace-grid">
            <section class="inventory-panel"><h2><?= $title ?> history</h2><p class="stock-help">Order usage, returns, manual entries, and adjustments. Quantities use each ingredient's inventory unit.</p>
                <div class="table-responsive"><table class="table"><thead><tr><th>Date</th><th>Ingredient</th><th>Quantity</th><th>Unit</th><th>Reference</th><th>Notes</th></tr></thead><tbody>
                <?php foreach ($movements as $movement): ?><tr><td><?= $escape($movement['transaction_date']) ?></td><td><?= $escape($movement['ingredient_name']) ?></td><td><?= number_format(abs((float)$movement['quantity']), 2) ?></td><td><?= $escape($movement['unit']) ?></td><td><?= $escape($movement['reference'] ?: ($movement['transaction_type'] === 'ADJUSTMENT' ? 'Adjustment' : 'Manual / opening stock')) ?></td><td><?= $escape($movement['notes'] ?? '') ?></td></tr><?php endforeach; ?>
                <?php if (!$movements): ?><tr><td colspan="6" class="text-center py-5">No <?= strtolower($title) ?> records yet.</td></tr><?php endif; ?>
                </tbody></table></div>
                <nav class="d-flex align-items-center gap-3" aria-label="Stock history pages"><?php if ($movementPage > 1): ?><a href="?tab=<?= $inventoryTab ?>&amp;page=<?= $movementPage - 1 ?>">Previous</a><?php endif; ?><span>Page <?= $movementPage ?> of <?= $movementPages ?></span><?php if ($movementPage < $movementPages): ?><a href="?tab=<?= $inventoryTab ?>&amp;page=<?= $movementPage + 1 ?>">Next</a><?php endif; ?></nav>
            </section>
            <section class="inventory-panel movement-form"><h2>New <?= strtolower($title) ?></h2><form method="post">
                <input type="hidden" name="csrf_token" value="<?= $escape($inventoryCsrf) ?>"><input type="hidden" name="action" value="<?= $inventoryTab ?>">
                <div class="mb-3"><label class="form-label" for="movement-date">Date</label><input id="movement-date" class="form-control" type="date" name="movement_date" max="<?= date('Y-m-d') ?>" value="<?= $escape(inventoryText($_POST, 'movement_date') ?: date('Y-m-d')) ?>" required></div>
                <div class="mb-3"><label class="form-label" for="movement-ingredient">Ingredient</label><select class="form-select" id="movement-ingredient" name="ingredient_id" required><option value="">Select ingredient</option><?php foreach ($ingredients as $ingredient): ?><option value="<?= (int)$ingredient['ingredient_id'] ?>" <?= inventoryText($_POST, 'ingredient_id') === (string)$ingredient['ingredient_id'] ? 'selected' : '' ?>><?= $escape($ingredient['ingredient_name'] . ' / ' . $ingredient['unit'] . ' (stock: ' . $ingredient['current_stock'] . ')') ?></option><?php endforeach; ?></select></div>
                <div class="mb-3"><label class="form-label" for="movement-quantity">Quantity</label><input class="form-control" id="movement-quantity" type="number" name="quantity" min="0.01" max="99999999.99" step="0.01" value="<?= $escape(inventoryText($_POST, 'quantity')) ?>" required><div class="stock-help mt-1">Enter the amount in the selected ingredient's unit.</div></div>
                <div class="mb-3"><label class="form-label" for="movement-notes">Notes / reason</label><textarea class="form-control" id="movement-notes" name="notes" maxlength="255" rows="3"><?= $escape(inventoryText($_POST, 'notes')) ?></textarea></div>
                <button class="btn btn-success w-100">Record <?= strtolower($title) ?></button>
            </form></section>
        </div>
    <?php endif; ?>
</main></body></html>
