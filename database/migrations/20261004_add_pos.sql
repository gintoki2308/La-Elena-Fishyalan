-- Counter sales are separate from delivery orders, but share menu stock.
CREATE TABLE IF NOT EXISTS pos_sales (
    sale_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    receipt_number VARCHAR(40) NOT NULL UNIQUE,
    request_key CHAR(32) NOT NULL UNIQUE,
    request_hash CHAR(64) NOT NULL,
    staff_id INT NULL,
    cashier_name VARCHAR(205) NOT NULL,
    customer_name VARCHAR(100) NOT NULL DEFAULT 'Walk-in',
    service_type ENUM('DINE_IN','TAKEAWAY') NOT NULL,
    table_number VARCHAR(30) NOT NULL DEFAULT '',
    notes VARCHAR(500) NOT NULL DEFAULT '',
    subtotal DECIMAL(10,2) NOT NULL,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount_reason VARCHAR(200) NOT NULL DEFAULT '',
    tax_rate DECIMAL(5,2) NOT NULL,
    tax_amount DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('CASH','GCASH','CARD') NOT NULL,
    payment_reference VARCHAR(100) NULL,
    amount_tendered DECIMAL(10,2) NOT NULL,
    change_amount DECIMAL(10,2) NOT NULL,
    status ENUM('COMPLETED','REFUNDED') NOT NULL DEFAULT 'COMPLETED',
    sold_at DATETIME NOT NULL,
    refunded_at DATETIME NULL,
    refund_reason VARCHAR(200) NULL,
    stock_restored TINYINT(1) NOT NULL DEFAULT 0,
    INDEX idx_pos_staff_date (staff_id, sold_at),
    INDEX idx_pos_date (sold_at),
    CONSTRAINT fk_pos_cashier FOREIGN KEY (staff_id) REFERENCES staffs(staff_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_sale_items (
    sale_item_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    item_id INT NULL,
    item_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_pos_line_sale FOREIGN KEY (sale_id) REFERENCES pos_sales(sale_id) ON DELETE CASCADE,
    CONSTRAINT fk_pos_line_menu FOREIGN KEY (item_id) REFERENCES menu_items(item_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
