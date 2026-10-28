CREATE DATABASE IF NOT EXISTS schoolstore_db;
USE schoolstore_db;

CREATE TABLE IF NOT EXISTS school_inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barcode VARCHAR(100) NOT NULL UNIQUE,
    quantity INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    capital_price DECIMAL(10,2) NOT NULL,
    wholesale_price DECIMAL(10,2) NOT NULL,
    retail_price DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
