<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once 'config/database.php';

try {
    $db = ConfigDatabase::getInstance()->getConnection();

    // Check if deleted_at column exists
    $check = $db->query("SHOW COLUMNS FROM products LIKE 'deleted_at'");
    $exists = $check->fetch();

    if (!$exists) {
        $db->exec("ALTER TABLE products ADD COLUMN deleted_at TIMESTAMP NULL AFTER updated_at");
        echo "✅ Added deleted_at column to products table<br>";
    } else {
        echo "✅ deleted_at column already exists<br>";
    }

    echo "<h3>✅ Database fixed! <a href='views/products/list.php'>Go back to Products</a></h3>";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
