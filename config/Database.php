<?php

class Database {
    private $conn;

    public function connect() {
        $this->conn = null;

        try {

            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];


            $this->conn = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
            
        } catch(PDOException $e) {
            // Logs the error safely to the server without exposing database structure to users
            error_log('Database Connection Error: ' . $e->getMessage());
            $this->conn = null;
        }

        return $this->conn;
    }

    public function getConnection() {
        return $this->conn;
    }
}
?>