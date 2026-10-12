-- Expense forms omit expense_id. Preserve the existing primary key and records
-- while letting MySQL generate a unique ID for each new expense.
ALTER TABLE expenses MODIFY expense_id INT NOT NULL AUTO_INCREMENT;
