<?php

if (!function_exists('schoolStoreAbort')) {
    function schoolStoreAbort(string $message, bool $json = false): void
    {
        if ($json) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['success' => false, 'message' => $message]);
            exit;
        }

        die($message);
    }
}

if (!function_exists('schoolStoreEnsureSchema')) {
    function schoolStoreEnsureSchema(mysqli $conn, bool $json = false): void
    {
        static $initialized = false;
        if ($initialized) {
            return;
        }

        $queries = [
            "CREATE TABLE IF NOT EXISTS school_inventory (
                id INT AUTO_INCREMENT PRIMARY KEY,
                barcode VARCHAR(100) NOT NULL,
                quantity INT NOT NULL DEFAULT 0,
                product_name VARCHAR(255) NOT NULL,
                capital_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                wholesale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                retail_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_school_inventory_barcode (barcode),
                INDEX idx_school_inventory_product_name (product_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS wholesale_sales (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sale_date DATETIME NOT NULL,
                total_amount DECIMAL(12,2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS wholesale_sale_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sale_id INT NOT NULL,
                product_id INT NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                barcode VARCHAR(100) NOT NULL,
                quantity INT NOT NULL,
                unit_price DECIMAL(12,2) NOT NULL,
                total_price DECIMAL(12,2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_wholesale_sale_id (sale_id),
                CONSTRAINT fk_wholesale_sale_items_sale
                    FOREIGN KEY (sale_id) REFERENCES wholesale_sales(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS retail_sales (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sale_date DATETIME NOT NULL,
                total_amount DECIMAL(12,2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS retail_sale_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                sale_id INT NOT NULL,
                product_id INT NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                barcode VARCHAR(100) NOT NULL,
                quantity INT NOT NULL,
                unit_price DECIMAL(12,2) NOT NULL,
                total_price DECIMAL(12,2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_retail_sale_id (sale_id),
                CONSTRAINT fk_retail_sale_items_sale
                    FOREIGN KEY (sale_id) REFERENCES retail_sales(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS sales (
                id INT AUTO_INCREMENT PRIMARY KEY,
                amount DECIMAL(12,2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];

        foreach ($queries as $sql) {
            if (!$conn->query($sql)) {
                schoolStoreAbort('School module setup failed: ' . $conn->error, $json);
            }
        }

        $initialized = true;
    }
}

if (!function_exists('schoolStoreConnect')) {
    function schoolStoreConnect(bool $json = false): mysqli
    {
        static $conn = null;
        if ($conn instanceof mysqli) {
            return $conn;
        }

        mysqli_report(MYSQLI_REPORT_OFF);

        $host = 'localhost';
        $username = 'root';
        $password = '';
        $dbName = 'pos_system';

        $conn = new mysqli($host, $username, $password);
        if ($conn->connect_error) {
            schoolStoreAbort('School module database connection failed: ' . $conn->connect_error, $json);
        }

        if (!$conn->set_charset('utf8mb4')) {
            schoolStoreAbort('Unable to set database charset: ' . $conn->error, $json);
        }

        if (!$conn->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            schoolStoreAbort('Unable to prepare school module database: ' . $conn->error, $json);
        }

        if (!$conn->select_db($dbName)) {
            schoolStoreAbort('Unable to use school module database: ' . $conn->error, $json);
        }

        schoolStoreEnsureSchema($conn, $json);

        return $conn;
    }
}

