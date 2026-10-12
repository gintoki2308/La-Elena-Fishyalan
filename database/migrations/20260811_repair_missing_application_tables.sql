-- Repairs schema drift between the La Elena PHP application and fishyalan_db.
-- Safe to run more than once: it only creates tables that do not already exist.

-- The original database dump omitted primary/auto-increment keys from these
-- existing entity tables. The application inserts rows without supplying IDs.
DELIMITER //
CREATE PROCEDURE repair_existing_entity_keys()
BEGIN
    DECLARE key_count INT DEFAULT 0;
    DECLARE auto_increment_count INT DEFAULT 0;
    DECLARE pk_column VARCHAR(64) DEFAULT NULL;

    SELECT COUNT(*) INTO key_count FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'staffs' AND constraint_type = 'PRIMARY KEY';
    IF key_count = 0 THEN ALTER TABLE staffs ADD PRIMARY KEY (staff_id); END IF;
    SELECT COUNT(*) INTO auto_increment_count FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'staffs' AND column_name = 'staff_id' AND extra LIKE '%auto_increment%';
    IF auto_increment_count = 0 THEN ALTER TABLE staffs MODIFY staff_id INT NOT NULL AUTO_INCREMENT; END IF;

    SELECT COUNT(*) INTO key_count FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'online_orders' AND constraint_type = 'PRIMARY KEY';
    IF key_count = 0 THEN ALTER TABLE online_orders ADD PRIMARY KEY (order_id); END IF;
    SELECT COUNT(*) INTO auto_increment_count FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'online_orders' AND column_name = 'order_id' AND extra LIKE '%auto_increment%';
    IF auto_increment_count = 0 THEN ALTER TABLE online_orders MODIFY order_id INT NOT NULL AUTO_INCREMENT; END IF;

    SELECT COUNT(*) INTO key_count FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'online_order_items' AND constraint_type = 'PRIMARY KEY';
    IF key_count = 0 THEN ALTER TABLE online_order_items ADD PRIMARY KEY (order_item_id); END IF;
    SELECT COUNT(*) INTO auto_increment_count FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'online_order_items' AND column_name = 'order_item_id' AND extra LIKE '%auto_increment%';
    IF auto_increment_count = 0 THEN ALTER TABLE online_order_items MODIFY order_item_id INT NOT NULL AUTO_INCREMENT; END IF;

    SELECT COUNT(*) INTO key_count FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'promotions' AND constraint_type = 'PRIMARY KEY';
    IF key_count = 0 THEN ALTER TABLE promotions ADD PRIMARY KEY (promotion_id); END IF;
    SELECT COUNT(*) INTO auto_increment_count FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'promotions' AND column_name = 'promotion_id' AND extra LIKE '%auto_increment%';
    IF auto_increment_count = 0 THEN ALTER TABLE promotions MODIFY promotion_id INT NOT NULL AUTO_INCREMENT; END IF;

    SELECT COUNT(*) INTO key_count FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'suppliers' AND constraint_type = 'PRIMARY KEY';
    IF key_count = 0 THEN ALTER TABLE suppliers ADD PRIMARY KEY (supplier_id); END IF;
    SELECT COUNT(*) INTO auto_increment_count FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'suppliers' AND column_name = 'supplier_id' AND extra LIKE '%auto_increment%';
    IF auto_increment_count = 0 THEN ALTER TABLE suppliers MODIFY supplier_id INT NOT NULL AUTO_INCREMENT; END IF;

    SELECT kcu.column_name INTO pk_column
    FROM information_schema.table_constraints tc
    JOIN information_schema.key_column_usage kcu
      ON tc.constraint_name = kcu.constraint_name
     AND tc.table_schema = kcu.table_schema
     AND tc.table_name = kcu.table_name
    WHERE tc.table_schema = DATABASE()
      AND tc.table_name = 'audit_logs'
      AND tc.constraint_type = 'PRIMARY KEY'
    ORDER BY kcu.ordinal_position
    LIMIT 1;

    IF pk_column IS NOT NULL THEN
        SELECT COUNT(*) INTO auto_increment_count FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'audit_logs' AND column_name = pk_column AND extra LIKE '%auto_increment%';
        IF auto_increment_count = 0 THEN
            SET @sql = CONCAT(
                'UPDATE `audit_logs` SET `', pk_column,
                '` = (SELECT next_id FROM (SELECT COALESCE(MAX(`', pk_column,
                '`), 0) + 1 AS next_id FROM `audit_logs`) AS next_pk) WHERE `',
                pk_column, '` = 0'
            );
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;

            SET @sql = CONCAT('ALTER TABLE `audit_logs` MODIFY `', pk_column, '` INT NOT NULL AUTO_INCREMENT');
            PREPARE stmt FROM @sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;
    END IF;
END//
DELIMITER ;
CALL repair_existing_entity_keys();
DROP PROCEDURE repair_existing_entity_keys;

CREATE TABLE IF NOT EXISTS reservations (
    reservation_id INT NOT NULL AUTO_INCREMENT,
    member_id INT NOT NULL,
    reservation_date DATETIME NOT NULL,
    party_size INT NOT NULL,
    special_requests TEXT NULL,
    status ENUM('PENDING', 'CONFIRMED', 'COMPLETED', 'CANCELLED') NOT NULL DEFAULT 'PENDING',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (reservation_id),
    KEY idx_reservations_member_date (member_id, reservation_date),
    KEY idx_reservations_date_status (reservation_date, status),
    CONSTRAINT fk_reservations_member
        FOREIGN KEY (member_id) REFERENCES customer(member_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS favorites (
    favorite_id INT NOT NULL AUTO_INCREMENT,
    member_id INT NOT NULL,
    item_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (favorite_id),
    UNIQUE KEY uq_favorites_member_item (member_id, item_id),
    KEY idx_favorites_item (item_id),
    CONSTRAINT fk_favorites_member
        FOREIGN KEY (member_id) REFERENCES customer(member_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_favorites_item
        FOREIGN KEY (item_id) REFERENCES menu_items(item_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_feedback (
    feedback_id INT NOT NULL AUTO_INCREMENT,
    member_id INT NOT NULL,
    rating TINYINT NOT NULL,
    category VARCHAR(100) NOT NULL,
    comment TEXT NOT NULL,
    feedback_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (feedback_id),
    KEY idx_feedback_member_date (member_id, feedback_date),
    CONSTRAINT chk_customer_feedback_rating CHECK (rating BETWEEN 1 AND 5),
    CONSTRAINT fk_customer_feedback_member
        FOREIGN KEY (member_id) REFERENCES customer(member_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_shifts (
    shift_id INT NOT NULL AUTO_INCREMENT,
    staff_id INT NOT NULL,
    shift_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (shift_id),
    KEY idx_staff_shifts_staff_date (staff_id, shift_date),
    KEY idx_staff_shifts_date (shift_date),
    CONSTRAINT fk_staff_shifts_staff
        FOREIGN KEY (staff_id) REFERENCES staffs(staff_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_attendance (
    attendance_id INT NOT NULL AUTO_INCREMENT,
    staff_id INT NOT NULL,
    shift_id INT NULL,
    attendance_date DATE NOT NULL,
    check_in_time DATETIME NULL,
    check_out_time DATETIME NULL,
    status ENUM('PRESENT', 'LATE', 'ABSENT') NOT NULL DEFAULT 'ABSENT',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (attendance_id),
    UNIQUE KEY uq_staff_attendance_shift (staff_id, shift_id),
    KEY idx_staff_attendance_date (attendance_date),
    CONSTRAINT fk_staff_attendance_staff
        FOREIGN KEY (staff_id) REFERENCES staffs(staff_id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_staff_attendance_shift
        FOREIGN KEY (shift_id) REFERENCES staff_shifts(shift_id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS staff_sessions (
    session_id INT NOT NULL AUTO_INCREMENT,
    staff_id INT NOT NULL,
    php_session_id VARCHAR(128) NOT NULL,
    user_agent VARCHAR(512) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (session_id),
    UNIQUE KEY uq_staff_sessions_php_session (php_session_id),
    KEY idx_staff_sessions_staff (staff_id),
    CONSTRAINT fk_staff_sessions_staff
        FOREIGN KEY (staff_id) REFERENCES staffs(staff_id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
