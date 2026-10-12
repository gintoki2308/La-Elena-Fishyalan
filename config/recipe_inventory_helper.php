<?php
require_once __DIR__ . '/inventory_helper.php';

/** Integer hundredths keep recipe and stock arithmetic exact. */
function recipeHundredths($quantity): int {
    $value = inventoryDecimal(['value' => (string)$quantity], 'value', 'Quantity');
    $parts = explode('.', $value);
    return (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
}

function recipeDecimal(int $quantity): string {
    return intdiv($quantity, 100) . '.' . str_pad((string)($quantity % 100), 2, '0', STR_PAD_LEFT);
}

/** Caller owns the order/sale transaction. Capture and deduct at most once. */
function deductRecipeInventory(PDO $db, string $source, int $sourceId, array $quantities, ?int $staffId = null): void {
    if (!$db->inTransaction() || !in_array($source, ['ONLINE', 'POS'], true) || $sourceId <= 0) {
        throw new LogicException('Ingredient deductions require a valid order transaction.');
    }
    $db->prepare('INSERT INTO inventory_order_deductions (source, source_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE source_id = VALUES(source_id)')
        ->execute([$source, $sourceId]);
    // The insert holds the capture lock. Existing snapshots (including empty ones)
    // are identified by the caller-facing marker below before any recipe is read.
    $marker = $db->prepare('SELECT state FROM inventory_order_deductions WHERE source = ? AND source_id = ? FOR UPDATE');
    $marker->execute([$source, $sourceId]);
    if ($marker->fetchColumn() !== 'CAPTURING') return;

    ksort($quantities, SORT_NUMERIC);
    $required = [];
    $menuLock = $db->prepare('SELECT item_id FROM menu_items WHERE item_id = ? FOR UPDATE');
    $recipe = $db->prepare('SELECT ingredient_id, quantity_required FROM menu_ingredients WHERE item_id = ? ORDER BY ingredient_id FOR UPDATE');
    foreach ($quantities as $itemId => $quantity) {
        if (!filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]])) {
            throw new InvalidArgumentException('Order quantity must be between 1 and 999.');
        }
        $menuLock->execute([$itemId]);
        if ($menuLock->fetchColumn() === false) throw new InvalidArgumentException('A menu item is no longer available.');
        $recipe->execute([$itemId]);
        foreach ($recipe->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $amount = recipeHundredths($line['quantity_required']);
            if ($amount <= 0) throw new InvalidArgumentException('A recipe has an invalid ingredient quantity. Ask staff to update it.');
            $id = (int)$line['ingredient_id'];
            $required[$id] = ($required[$id] ?? 0) + $amount * (int)$quantity;
        }
    }
    ksort($required, SORT_NUMERIC);
    $ingredient = $db->prepare('SELECT ingredient_name, current_stock, unit FROM ingredients WHERE ingredient_id = ? FOR UPDATE');
    foreach ($required as $id => $amount) {
        $ingredient->execute([$id]);
        $row = $ingredient->fetch(PDO::FETCH_ASSOC);
        if (!$row || $amount > recipeHundredths($row['current_stock'] ?? '0')) {
            throw new InvalidArgumentException('Insufficient ingredient stock: ' . ($row['ingredient_name'] ?? 'Unknown ingredient') . '. Needed ' . recipeDecimal($amount) . ' ' . ($row['unit'] ?? '') . ', available ' . ($row['current_stock'] ?? '0') . '.');
        }
        $value = recipeDecimal($amount);
        $db->prepare('UPDATE ingredients SET current_stock = current_stock - ? WHERE ingredient_id = ?')->execute([$value, $id]);
        $db->prepare('INSERT INTO inventory_order_usage (source, source_id, ingredient_id, quantity, unit) VALUES (?, ?, ?, ?, ?)')
            ->execute([$source, $sourceId, $id, $value, $row['unit']]);
        inventoryTransaction($db, $id, 'OUT', $value, $staffId, $source . ' #' . $sourceId, 'Ingredients used for order');
    }
    $db->prepare("UPDATE inventory_order_deductions SET state = 'DEDUCTED' WHERE source = ? AND source_id = ?")->execute([$source, $sourceId]);
}

/** Restore the original snapshot, never the current recipe. Caller locks the order. */
function restoreRecipeInventory(PDO $db, string $source, int $sourceId, ?int $staffId = null): void {
    if (!$db->inTransaction() || !in_array($source, ['ONLINE', 'POS'], true)) throw new LogicException('Ingredient returns require an order transaction.');
    $stmt = $db->prepare('SELECT state FROM inventory_order_deductions WHERE source = ? AND source_id = ? FOR UPDATE');
    $stmt->execute([$source, $sourceId]);
    if ($stmt->fetchColumn() !== 'DEDUCTED') return;
    $stmt = $db->prepare('SELECT * FROM inventory_order_usage WHERE source = ? AND source_id = ? ORDER BY ingredient_id');
    $stmt->execute([$source, $sourceId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $lock = $db->prepare('SELECT current_stock FROM ingredients WHERE ingredient_id = ? FOR UPDATE');
        $lock->execute([$line['ingredient_id']]);
        $stock = $lock->fetchColumn();
        if ($stock === false || recipeHundredths($stock) + recipeHundredths($line['quantity']) > 9999999999) {
            throw new InvalidArgumentException('Ingredient stock cannot be restored because it would exceed the stock limit.');
        }
        $db->prepare('UPDATE ingredients SET current_stock = current_stock + ? WHERE ingredient_id = ?')->execute([$line['quantity'], $line['ingredient_id']]);
        inventoryTransaction($db, (int)$line['ingredient_id'], 'IN', $line['quantity'], $staffId, $source . ' #' . $sourceId, 'Ingredients returned from cancelled/refunded order');
    }
    $db->prepare("UPDATE inventory_order_deductions SET state = 'RESTORED' WHERE source = ? AND source_id = ?")->execute([$source, $sourceId]);
}

function saveInventoryRecipe(PDO $db, array $input): string {
    $itemId = inventoryId($input, 'item_id');
    $rows = $input['recipe'] ?? [];
    if (!is_array($rows) || count($rows) > 100) throw new InvalidArgumentException('A recipe supports up to 100 ingredients.');
    $recipe = [];
    foreach ($rows as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('Invalid recipe ingredient.');
        if (inventoryText($row, 'ingredient_id') === '' && inventoryText($row, 'quantity') === '') continue;
        $id = inventoryId($row, 'ingredient_id');
        $quantity = inventoryDecimal($row, 'quantity', 'Quantity per serving');
        if (recipeHundredths($quantity) <= 0) throw new InvalidArgumentException('Each ingredient quantity must be greater than zero.');
        if (isset($recipe[$id])) throw new InvalidArgumentException('Select each ingredient only once per recipe.');
        $recipe[$id] = $quantity;
    }
    ksort($recipe, SORT_NUMERIC);
    $db->beginTransaction();
    try {
        $stmt = $db->prepare('SELECT item_id FROM menu_items WHERE item_id = ? FOR UPDATE');
        $stmt->execute([$itemId]);
        if ($stmt->fetchColumn() === false) throw new InvalidArgumentException('Choose an available menu item.');
        foreach ($recipe as $id => $quantity) {
            $stmt = $db->prepare('SELECT ingredient_id FROM ingredients WHERE ingredient_id = ? FOR UPDATE');
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() === false) throw new InvalidArgumentException('An ingredient no longer exists. Refresh the recipe.');
        }
        $db->prepare('DELETE FROM menu_ingredients WHERE item_id = ?')->execute([$itemId]);
        $stmt = $db->prepare('INSERT INTO menu_ingredients (item_id, ingredient_id, quantity_required) VALUES (?, ?, ?)');
        foreach ($recipe as $id => $quantity) $stmt->execute([$itemId, $id, $quantity]);
        writeAuditLog($db, 'SAVE_RECIPE', 'menu_items', $itemId, ['ingredients' => $recipe]);
        $db->commit();
        return 'Recipe saved. Quantities apply to each serving in future orders.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
