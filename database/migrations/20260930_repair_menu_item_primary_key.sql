-- Menu-item creation omits item_id, so the key must be auto-incrementing.
-- Move any legacy zero ID to the next positive ID before enabling it.
-- Foreign keys referencing menu_items.item_id use ON UPDATE CASCADE.

DELIMITER //
CREATE PROCEDURE repair_menu_item_primary_key()
BEGIN
    DECLARE item_id_is_auto_increment INT DEFAULT 0;

    UPDATE menu_items
    SET item_id = (
        SELECT next_id
        FROM (SELECT COALESCE(MAX(item_id), 0) + 1 AS next_id FROM menu_items) AS next_pk
    )
    WHERE item_id = 0;

    SELECT COUNT(*) INTO item_id_is_auto_increment
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'menu_items'
      AND COLUMN_NAME = 'item_id'
      AND EXTRA LIKE '%auto_increment%';

    IF item_id_is_auto_increment = 0 THEN
        ALTER TABLE menu_items
            MODIFY item_id INT NOT NULL AUTO_INCREMENT;
    END IF;
END//
DELIMITER ;

CALL repair_menu_item_primary_key();
DROP PROCEDURE repair_menu_item_primary_key;