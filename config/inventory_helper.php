<?php

function inventoryText(array $input, string $key): string {
    return isset($input[$key]) && is_string($input[$key]) ? trim($input[$key]) : '';
}

function inventoryId(array $input, string $key): int {
    $value = inventoryText($input, $key);
    // Keep legacy ID 0 addressable; a missing ID must never target that record.
    if (!preg_match('/^(0|[1-9][0-9]{0,9})$/D', $value) || (float)$value > 2147483647) {
        throw new InvalidArgumentException('Please select a valid ingredient or supplier.');
    }
    return (int)$value;
}

function inventoryDecimal(array $input, string $key, string $label): string {
    $value = inventoryText($input, $key);
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) {
        throw new InvalidArgumentException($label . ' must be between 0 and 99,999,999.99, with at most two decimal places.');
    }
    return $value;
}

function inventoryDetails(PDO $db, array $input): array {
    $name = inventoryText($input, 'ingredient_name');
    $unit = inventoryText($input, 'unit');
    if ($name === '' || mb_strlen($name) > 255 || $unit === '' || mb_strlen($unit) > 50) {
        throw new InvalidArgumentException('Enter an ingredient name (up to 255 characters) and unit (up to 50 characters).');
    }
    $supplier = null;
    if (($input['supplier_id'] ?? '') !== '') {
        $supplier = inventoryId($input, 'supplier_id');
        $stmt = $db->prepare('SELECT supplier_id FROM suppliers WHERE supplier_id = ?');
        $stmt->execute([$supplier]);
        if ($stmt->fetchColumn() === false) {
            throw new InvalidArgumentException('Select an available supplier or leave it unassigned.');
        }
    }
    return [
        'ingredient_name' => $name, 'unit' => $unit,
        'unit_price' => inventoryDecimal($input, 'unit_price', 'Cost per unit'),
        'reorder_level' => inventoryDecimal($input, 'reorder_level', 'Reorder level'),
        'supplier_id' => $supplier,
    ];
}

function inventoryTransaction(PDO $db, int $id, string $type, string $quantity, ?int $staffId, ?string $reference = null, ?string $notes = null, ?string $date = null): void {
    $db->prepare('INSERT INTO inventory_transactions (ingredient_id, transaction_type, quantity, staff_id, reference, notes, transaction_date) VALUES (?, ?, ?, ?, ?, ?, COALESCE(?, NOW()))')
        ->execute([$id, $type, $quantity, $staffId, $reference, $notes, $date]);
}

/** Apply each change and its history atomically. Call only after authorization/CSRF validation. */
function inventoryAction(PDO $db, string $action, array $input, ?int $staffId = null): string {
    if (!in_array($action, ['create_ingredient', 'add_ingredient', 'edit_ingredient', 'update_stock', 'delete_ingredient', 'stock_in', 'stock_out'], true)) {
        throw new InvalidArgumentException('Unknown inventory action.');
    }
    $db->beginTransaction();
    try {
        if ($action === 'create_ingredient' || $action === 'add_ingredient') {
            if ($action === 'add_ingredient') $input['unit_price'] = $input['cost_per_unit'] ?? '';
            $details = inventoryDetails($db, $input);
            $details['current_stock'] = inventoryDecimal($input, 'current_stock', 'Current stock');
            $db->prepare('INSERT INTO ingredients (ingredient_name, unit, unit_price, reorder_level, supplier_id, current_stock)
                VALUES (:ingredient_name, :unit, :unit_price, :reorder_level, :supplier_id, :current_stock)')->execute($details);
            $id = (int)$db->lastInsertId();
            if ((float)$details['current_stock'] > 0) inventoryTransaction($db, $id, 'IN', $details['current_stock'], $staffId);
            $auditAction = 'CREATE_INGREDIENT';
            $message = 'Ingredient added successfully!';
        } else {
            $id = inventoryId($input, 'ingredient_id');
            $stmt = $db->prepare('SELECT * FROM ingredients WHERE ingredient_id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $before = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$before) throw new InvalidArgumentException('Ingredient not found. Refresh the inventory and try again.');
            $details = ['ingredient_name' => $before['ingredient_name']];
            if ($action === 'stock_in' || $action === 'stock_out') {
                $quantity = inventoryDecimal($input, 'quantity', 'Quantity');
                if ((float)$quantity <= 0) throw new InvalidArgumentException('Quantity must be greater than zero.');
                $date = inventoryText($input, 'movement_date');
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$parsed || $parsed->format('Y-m-d') !== $date || $date > date('Y-m-d')) throw new InvalidArgumentException('Choose a valid date today or earlier.');
                $notes = inventoryText($input, 'notes');
                if (mb_strlen($notes) > 255) throw new InvalidArgumentException('Notes must be 255 characters or fewer.');
                $delta = (int)round((float)$quantity * 100) * ($action === 'stock_in' ? 1 : -1);
                $stock = (int)round((float)$before['current_stock'] * 100) + $delta;
                if ($stock < 0 || $stock > 9999999999) throw new InvalidArgumentException('This movement would exceed the available stock or stock limit.');
                $db->prepare('UPDATE ingredients SET current_stock = ? WHERE ingredient_id = ?')->execute([number_format($stock / 100, 2, '.', ''), $id]);
                inventoryTransaction($db, $id, $action === 'stock_in' ? 'IN' : 'OUT', $quantity, $staffId, 'MANUAL', $notes, $date . ' ' . date('H:i:s'));
                $auditAction = strtoupper($action);
                $message = ($action === 'stock_in' ? 'Stock in' : 'Stock out') . ' recorded successfully!';
                $details += ['quantity' => $quantity, 'notes' => $notes];
            } elseif ($action === 'update_stock') {
                $stock = inventoryDecimal($input, 'new_stock', 'Stock');
                $db->prepare('UPDATE ingredients SET current_stock = ? WHERE ingredient_id = ?')->execute([$stock, $id]);
                $delta = round((float)$stock * 100) - round((float)$before['current_stock'] * 100);
                if ($delta != 0) inventoryTransaction($db, $id, 'ADJUSTMENT', number_format($delta / 100, 2, '.', ''), $staffId);
                $details += ['previous_stock' => $before['current_stock'], 'new_stock' => $stock, 'unit' => $before['unit']];
                $auditAction = 'UPDATE_INVENTORY';
                $message = 'Stock updated successfully!';
            } elseif ($action === 'edit_ingredient') {
                $values = inventoryDetails($db, $input);
                if ($values['unit'] !== $before['unit']) {
                    $used = $db->prepare('SELECT (SELECT COUNT(*) FROM menu_ingredients WHERE ingredient_id = ?) + (SELECT COUNT(*) FROM inventory_transactions WHERE ingredient_id = ?)');
                    $used->execute([$id, $id]);
                    if ((int)$used->fetchColumn() > 0) throw new InvalidArgumentException('This ingredient has a recipe or stock history. Keep its unit, or create a new ingredient for a different unit.');
                }
                $db->prepare('UPDATE ingredients SET ingredient_name = :ingredient_name, unit = :unit, unit_price = :unit_price,
                    reorder_level = :reorder_level, supplier_id = :supplier_id WHERE ingredient_id = :id')->execute($values + ['id' => $id]);
                $details = ['previous' => $before, 'updated' => $values];
                $auditAction = 'EDIT_INGREDIENT';
                $message = 'Ingredient updated successfully!';
            } else {
                $used = $db->prepare('SELECT COUNT(*) FROM inventory_order_usage WHERE ingredient_id = ?');
                $used->execute([$id]);
                if ((int)$used->fetchColumn() > 0) throw new InvalidArgumentException('This ingredient is recorded in an order and cannot be deleted.');
                // The confirmation explicitly includes linked recipes and stock history.
                $db->prepare('DELETE FROM inventory_transactions WHERE ingredient_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM menu_ingredients WHERE ingredient_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM ingredients WHERE ingredient_id = ?')->execute([$id]);
                $auditAction = 'DELETE_INGREDIENT';
                $message = 'Ingredient "' . $before['ingredient_name'] . '" deleted successfully!';
            }
        }
        writeAuditLog($db, $auditAction, 'ingredients', $id, ['summary' => $message] + $details);
        $db->commit();
        return $message;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
