<?php
require_once 'config/database.php';
require_once 'classes/ProductHistory.php';

// Test database connection
$db = ConfigDatabase::getInstance()->getConnection();
if ($db) {
    echo "Database connection successful!<br>";
}

// Test ProductHistory class
$history = new ProductHistory();
echo "ProductHistory class loaded successfully!<br>";