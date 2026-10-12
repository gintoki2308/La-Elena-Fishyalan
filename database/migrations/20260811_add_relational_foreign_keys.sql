-- Connect existing application tables with enforced foreign-key relationships.
-- This migration is safe to re-run.

DELIMITER //
CREATE PROCEDURE add_foreign_key_if_missing(
    IN p_source_table VARCHAR(64),
    IN p_constraint_name VARCHAR(64),
    IN p_foreign_key_definition TEXT
)
BEGIN
    DECLARE constraint_count INT DEFAULT 0;

    SELECT COUNT(*) INTO constraint_count
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = p_source_table
      AND constraint_name = p_constraint_name;

    IF constraint_count = 0 THEN
        SET @statement = CONCAT(
            'ALTER TABLE `', p_source_table, '` ADD CONSTRAINT `', p_constraint_name,
            '` ', p_foreign_key_definition
        );
        PREPARE foreign_key_statement FROM @statement;
        EXECUTE foreign_key_statement;
        DEALLOCATE PREPARE foreign_key_statement;
    END IF;
END//
DELIMITER ;

CALL add_foreign_key_if_missing('audit_logs', 'fk_audit_logs_staff', 'FOREIGN KEY (staff_id) REFERENCES staffs(staff_id) ON DELETE SET NULL ON UPDATE CASCADE');
CALL add_foreign_key_if_missing('audit_logs', 'fk_audit_logs_admin', 'FOREIGN KEY (admin_id) REFERENCES admins(admin_id) ON DELETE SET NULL ON UPDATE CASCADE');
CALL add_foreign_key_if_missing('expenses', 'fk_expenses_admin', 'FOREIGN KEY (recorded_by) REFERENCES admins(admin_id) ON DELETE SET NULL ON UPDATE CASCADE');
CALL add_foreign_key_if_missing('ingredients', 'fk_ingredients_supplier', 'FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE SET NULL ON UPDATE CASCADE');
CALL add_foreign_key_if_missing('inventory_transactions', 'fk_inventory_transactions_ingredient', 'FOREIGN KEY (ingredient_id) REFERENCES ingredients(ingredient_id) ON DELETE RESTRICT ON UPDATE CASCADE');
CALL add_foreign_key_if_missing('inventory_transactions', 'fk_inventory_transactions_staff', 'FOREIGN KEY (staff_id) REFERENCES staffs(staff_id) ON DELETE SET NULL ON UPDATE CASCADE');
CALL add_foreign_key_if_missing('menu_ingredients', 'fk_menu_ingredients_item', 'FOREIGN KEY (item_id) REFERENCES menu_items(item_id) ON DELETE CASCADE ON UPDATE CASCADE');
CALL add_foreign_key_if_missing('menu_ingredients', 'fk_menu_ingredients_ingredient', 'FOREIGN KEY (ingredient_id) REFERENCES ingredients(ingredient_id) ON DELETE CASCADE ON UPDATE CASCADE');
CALL add_foreign_key_if_missing('online_orders', 'fk_online_orders_member', 'FOREIGN KEY (member_id) REFERENCES customer(member_id) ON DELETE SET NULL ON UPDATE CASCADE');
CALL add_foreign_key_if_missing('online_order_items', 'fk_online_order_items_order', 'FOREIGN KEY (order_id) REFERENCES online_orders(order_id) ON DELETE CASCADE ON UPDATE CASCADE');
CALL add_foreign_key_if_missing('online_order_items', 'fk_online_order_items_item', 'FOREIGN KEY (item_id) REFERENCES menu_items(item_id) ON DELETE SET NULL ON UPDATE CASCADE');
CALL add_foreign_key_if_missing('promotions', 'fk_promotions_admin', 'FOREIGN KEY (created_by) REFERENCES admins(admin_id) ON DELETE SET NULL ON UPDATE CASCADE');
DROP PROCEDURE add_foreign_key_if_missing;
