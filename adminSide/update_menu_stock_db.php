<?php
require_once '../config/Database.php';

try {
    $database = new Database();
    $db = $database->connect();
    
    $queries = [
        "ALTER TABLE menu_items ADD COLUMN stock_quantity INT DEFAULT 100",
        "ALTER TABLE menu_items ADD COLUMN reorder_level INT DEFAULT 10",
        "UPDATE menu_items SET stock_quantity = 100, reorder_level = 10 WHERE stock_quantity IS NULL OR stock_quantity = 0"
    ];

    foreach ($queries as $query) {
        try {
            $db->exec($query);
            echo "Executed: " . $query . "\n";
        } catch (PDOException $e) {
            // Ignore duplicate column errors
            if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate column') !== false) {
                echo "Column already exists: " . $query . "\n";
            } else {
                echo "Error on query '" . $query . "': " . $e->getMessage() . "\n";
            }
        }
    }
    echo "Menu inventory database setup completed.\n";
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
}
?>
