-- Repairs the customer-account table used by customer registration.
-- The previous schema defined member_id as a primary key but did not make it
-- auto-incrementing, causing INSERT statements that omit member_id to fail.
-- This migration is safe to re-run.

DELIMITER //
CREATE PROCEDURE repair_customer_registration_schema()
BEGIN
    DECLARE member_id_is_auto_increment INT DEFAULT 0;
    DECLARE supports_disabled_status INT DEFAULT 0;

    SELECT COUNT(*) INTO member_id_is_auto_increment
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'customer'
      AND COLUMN_NAME = 'member_id'
      AND EXTRA LIKE '%auto_increment%';

    IF member_id_is_auto_increment = 0 THEN
        ALTER TABLE customer
            MODIFY member_id INT NOT NULL AUTO_INCREMENT;
    END IF;

    SELECT COUNT(*) INTO supports_disabled_status
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'customer'
      AND COLUMN_NAME = 'account_status'
      AND COLUMN_TYPE LIKE '%''DISABLED''%';

    IF supports_disabled_status = 0 THEN
        ALTER TABLE customer
            MODIFY account_status ENUM('PENDING', 'ACTIVE', 'REJECTED', 'ARCHIVED', 'DISABLED')
            NOT NULL DEFAULT 'PENDING';
    END IF;
END//
DELIMITER ;

CALL repair_customer_registration_schema();
DROP PROCEDURE repair_customer_registration_schema;
