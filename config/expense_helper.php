<?php

const EXPENSE_CATEGORIES = ['UTILITIES', 'MAINTENANCE', 'SUPPLIES', 'WAGES', 'MARKETING', 'OTHER'];

function expenseFormValues(array $input): array {
    $values = [];
    foreach (['expense_type', 'amount', 'category', 'description'] as $field) {
        $values[$field] = isset($input[$field]) && is_string($input[$field]) ? trim($input[$field]) : '';
    }
    return $values;
}

function recordExpense(PDO $db, array $input, int $adminId): void {
    $values = expenseFormValues($input);
    if ($values['expense_type'] === '' || $values['amount'] === '' || $values['category'] === '') {
        throw new InvalidArgumentException('Please fill in all required fields.');
    }
    if (mb_strlen($values['expense_type'], 'UTF-8') > 100) {
        throw new InvalidArgumentException('Expense type must be 100 characters or fewer.');
    }
    // Match DECIMAL(10,2) without silently rounding or accepting negative expenses.
    if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $values['amount']) || (float)$values['amount'] <= 0) {
        throw new InvalidArgumentException('Enter an amount from 0.01 to 99,999,999.99 with at most two decimal places.');
    }
    if (!in_array($values['category'], EXPENSE_CATEGORIES, true)) {
        throw new InvalidArgumentException('Please select a valid expense category.');
    }
    if (strlen($values['description']) > 65535) {
        throw new InvalidArgumentException('The description is too long.');
    }

    $stmt = $db->prepare('INSERT INTO expenses
        (expense_type, amount, expense_date, description, recorded_by, category, created_at)
        VALUES (:type, :amount, CURDATE(), :description, :recorded_by, :category, NOW())');
    $stmt->execute([
        ':type' => $values['expense_type'],
        ':amount' => $values['amount'],
        ':description' => $values['description'],
        ':recorded_by' => $adminId,
        ':category' => $values['category'],
    ]);
}
