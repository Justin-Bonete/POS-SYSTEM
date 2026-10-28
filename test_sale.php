<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once 'config/database.php';
require_once 'classes/Sale.php';

try {
    $db = ConfigDatabase::getInstance()->getConnection();
    
    // Test database connection
    echo "✅ Database connected<br>";
    
    // Check sales table structure
    $stmt = $db->query("DESCRIBE sales");
    echo "<h3>Sales Table Structure:</h3>";
    echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Test creating a simple sale
    $sale = new Sale();
    $testData = [
        'invoice_number' => 'TEST-' . date('YmdHis'),
        'user_id' => 8, // admin user ID
        'customer_name' => 'Test Customer',
        'customer_email' => null,
        'customer_phone' => null,
        'subtotal' => 100.00,
        'tax_amount' => 10.00,
        'total_amount' => 110.00,
        'payment_method' => 'cash',
        'payment_status' => 'paid',
        'cash_amount' => 200.00,
        'change_amount' => 90.00,
        'notes' => 'Test sale',
        'items' => [
            [
                'product_id' => 1,
                'product_name' => 'Test Product',
                'quantity' => 1,
                'price_per_unit' => 100.00,
                'subtotal' => 100.00
            ]
        ]
    ];
    
    echo "<h3>Testing Sale Creation:</h3>";
    $result = $sale->create($testData);
    
    if ($result) {
        echo "✅ Test sale created successfully! ID: " . $result;
    } else {
        echo "❌ Failed to create test sale";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>