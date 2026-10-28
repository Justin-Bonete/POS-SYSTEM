<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once 'config/database.php';

try {
    $db = ConfigDatabase::getInstance()->getConnection();
    
    // Check if cash_amount column exists
    $checkCash = $db->query("SHOW COLUMNS FROM sales LIKE 'cash_amount'");
    $cashExists = $checkCash->fetch();
    
    if (!$cashExists) {
        $db->exec("ALTER TABLE sales ADD COLUMN cash_amount decimal(10,2) DEFAULT NULL AFTER payment_status");
        echo "✅ Added cash_amount column<br>";
    } else {
        echo "✅ cash_amount column already exists<br>";
    }
    
    // Check if change_amount column exists
    $checkChange = $db->query("SHOW COLUMNS FROM sales LIKE 'change_amount'");
    $changeExists = $checkChange->fetch();
    
    if (!$changeExists) {
        $db->exec("ALTER TABLE sales ADD COLUMN change_amount decimal(10,2) DEFAULT NULL AFTER cash_amount");
        echo "✅ Added change_amount column<br>";
    } else {
        echo "✅ change_amount column already exists<br>";
    }
    
    echo "<h3>✅ Database fixed! <a href='views/sales/new.php'>Go back to POS</a></h3>";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>