-- MariaDB / XAMPP. Additive migration; existing stock and orders are unchanged.
ALTER TABLE menu_ingredients MODIFY menu_ingredient_id INT NOT NULL AUTO_INCREMENT;
ALTER TABLE inventory_transactions
    ADD COLUMN IF NOT EXISTS reference VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS notes VARCHAR(255) NULL;

-- A capture marker also records orders without recipes, so a later recipe edit
-- cannot cause an old order to consume newly configured ingredients.
CREATE TABLE IF NOT EXISTS inventory_order_deductions (
    source ENUM('ONLINE','POS') NOT NULL,
    source_id INT NOT NULL,
    state ENUM('CAPTURING','DEDUCTED','RESTORED') NOT NULL DEFAULT 'CAPTURING',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (source, source_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_order_usage (
    source ENUM('ONLINE','POS') NOT NULL,
    source_id INT NOT NULL,
    ingredient_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    PRIMARY KEY (source, source_id, ingredient_id),
    CONSTRAINT fk_usage_capture FOREIGN KEY (source, source_id)
        REFERENCES inventory_order_deductions(source, source_id) ON DELETE RESTRICT,
    CONSTRAINT fk_usage_ingredient FOREIGN KEY (ingredient_id)
        REFERENCES ingredients(ingredient_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
