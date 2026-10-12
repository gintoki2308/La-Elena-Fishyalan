-- Store the address fields collected during customer registration individually.
-- The combined `address` column remains in place for checkout and legacy data.
-- This migration is safe to run more than once.

DELIMITER //
CREATE PROCEDURE add_customer_address_components()
BEGIN
    DECLARE purok_exists INT DEFAULT 0;
    DECLARE barangay_exists INT DEFAULT 0;
    DECLARE municipality_exists INT DEFAULT 0;
    DECLARE province_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO purok_exists
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer' AND COLUMN_NAME = 'purok';
    IF purok_exists = 0 THEN
        ALTER TABLE customer ADD COLUMN purok VARCHAR(100) NULL AFTER address;
    END IF;

    SELECT COUNT(*) INTO barangay_exists
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer' AND COLUMN_NAME = 'barangay';
    IF barangay_exists = 0 THEN
        ALTER TABLE customer ADD COLUMN barangay VARCHAR(100) NULL AFTER purok;
    END IF;

    SELECT COUNT(*) INTO municipality_exists
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer' AND COLUMN_NAME = 'municipality';
    IF municipality_exists = 0 THEN
        ALTER TABLE customer ADD COLUMN municipality VARCHAR(100) NULL AFTER barangay;
    END IF;

    SELECT COUNT(*) INTO province_exists
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer' AND COLUMN_NAME = 'province';
    IF province_exists = 0 THEN
        ALTER TABLE customer ADD COLUMN province VARCHAR(100) NULL AFTER municipality;
    END IF;
END//
DELIMITER ;

CALL add_customer_address_components();
DROP PROCEDURE add_customer_address_components;
