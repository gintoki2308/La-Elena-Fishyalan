-- One-off repair for the live database.
-- Fixes missing AUTO_INCREMENT behavior on the staff table and audit logs table.
-- Safe to run more than once.

DELIMITER //
CREATE PROCEDURE repair_table_auto_increment_if_missing(
    IN p_table_name VARCHAR(64)
)
BEGIN
    DECLARE pk_column VARCHAR(64) DEFAULT NULL;
    DECLARE auto_increment_count INT DEFAULT 0;

    SELECT kcu.column_name INTO pk_column
    FROM information_schema.table_constraints tc
    JOIN information_schema.key_column_usage kcu
      ON tc.constraint_name = kcu.constraint_name
     AND tc.table_schema = kcu.table_schema
     AND tc.table_name = kcu.table_name
    WHERE tc.table_schema = DATABASE()
      AND tc.table_name = p_table_name
      AND tc.constraint_type = 'PRIMARY KEY'
    ORDER BY kcu.ordinal_position
    LIMIT 1;

    IF pk_column IS NOT NULL THEN
        SELECT COUNT(*) INTO auto_increment_count
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = p_table_name
          AND column_name = pk_column
          AND extra LIKE '%auto_increment%';

        IF auto_increment_count = 0 THEN
            SET @stmt_sql = CONCAT(
                'UPDATE `', p_table_name,
                '` SET `', pk_column,
                '` = (SELECT next_id FROM (SELECT COALESCE(MAX(`', pk_column,
                '`), 0) + 1 AS next_id FROM `', p_table_name,
                '`) AS next_pk) WHERE `', pk_column, '` = 0'
            );
            PREPARE stmt FROM @stmt_sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;

            SET @stmt_sql = CONCAT(
                'ALTER TABLE `', p_table_name,
                '` MODIFY `', pk_column, '` INT NOT NULL AUTO_INCREMENT'
            );
            PREPARE stmt FROM @stmt_sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;
    END IF;
END//
DELIMITER ;

-- Staff applications and admin-created staff accounts both rely on this key.
CALL repair_table_auto_increment_if_missing('staffs');

-- Audit log inserts happen inside staff approval and many other admin/staff actions.
CALL repair_table_auto_increment_if_missing('audit_logs');

DROP PROCEDURE repair_table_auto_increment_if_missing;
