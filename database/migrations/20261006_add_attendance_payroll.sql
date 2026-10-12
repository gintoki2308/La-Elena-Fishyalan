-- Existing attendance remains a whole-shift record (SHIFT).
ALTER TABLE staff_attendance ADD COLUMN IF NOT EXISTS session_period VARCHAR(5) NOT NULL DEFAULT 'SHIFT';
-- Add the replacement index before removing the foreign key's supporting index.
ALTER TABLE staff_attendance ADD UNIQUE INDEX IF NOT EXISTS uq_attendance_session (staff_id, shift_id, session_period);
ALTER TABLE staff_attendance DROP INDEX IF EXISTS uq_staff_attendance_shift;

CREATE TABLE IF NOT EXISTS staff_pay_rates (
    staff_id INT NOT NULL PRIMARY KEY,
    pay_basis ENUM('HOURLY','DAILY','MONTHLY') NOT NULL,
    rate DECIMAL(12,2) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES staffs(staff_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_payroll (
    payroll_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    staff_id INT NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    pay_basis ENUM('HOURLY','DAILY','MONTHLY') NOT NULL,
    rate DECIMAL(12,2) NOT NULL,
    worked_minutes INT NOT NULL,
    worked_days INT NOT NULL,
    basic_pay DECIMAL(12,2) NOT NULL,
    allowances DECIMAL(12,2) NOT NULL DEFAULT 0,
    deductions DECIMAL(12,2) NOT NULL DEFAULT 0,
    net_pay DECIMAL(12,2) NOT NULL,
    notes VARCHAR(1000) NOT NULL DEFAULT '',
    attendance_snapshot LONGTEXT NOT NULL,
    status ENUM('PENDING','PAID') NOT NULL DEFAULT 'PENDING',
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    UNIQUE KEY uq_payroll_period (staff_id, period_start, period_end),
    FOREIGN KEY (staff_id) REFERENCES staffs(staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
