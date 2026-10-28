<?php
class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        // Reuse the central ConfigDatabase PDO connection to ensure a single PDO per request
        require_once __DIR__ . '/../config/database.php';
        $this->conn = ConfigDatabase::getInstance()->getConnection();
    }

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    public function beginTransaction() {  // Added missing method
        return $this->conn->beginTransaction();
    }

    public function commit() {
        if ($this->conn instanceof PDO && $this->conn->inTransaction()) {
            return $this->conn->commit();
        }
        return false;
    }

    public function rollback() {
        if ($this->conn instanceof PDO && $this->conn->inTransaction()) {
            return $this->conn->rollBack();
        }
        return false;
    }

    public function lastInsertId() {  // Added missing method
        return $this->conn->lastInsertId();
    }
}
?>