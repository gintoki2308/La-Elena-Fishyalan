-- Preserve existing IDs (including legacy ID 0) and all foreign-key links.
-- New ingredient/history inserts must receive unique IDs automatically.
SET @inventory_previous_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'NO_AUTO_VALUE_ON_ZERO');
ALTER TABLE ingredients MODIFY ingredient_id INT NOT NULL AUTO_INCREMENT;
ALTER TABLE inventory_transactions MODIFY transaction_id INT NOT NULL AUTO_INCREMENT;
SET SESSION sql_mode = @inventory_previous_sql_mode;
