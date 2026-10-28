<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once 'config/database.php';
require_once 'classes/Product.php';

$productId = 4; // The product ID that's failing

echo "<h2>Debugging Product ID: $productId</h2>";

try {
    // Check database connection
    $db = ConfigDatabase::getInstance()->getConnection();
    echo "✅ Database connected<br>";
    
    // Direct SQL query to check product
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($product) {
        echo "✅ Product found in database:<br>";
        echo "<pre>";
        print_r($product);
        echo "</pre>";
    } else {
        echo "❌ Product ID $productId not found in database<br>";
    }
    
    // Test the Product class
    $productClass = new Product();
    echo "<br>Testing Product class update method...<br>";
    
    // Try to update with simple data
    $updateData = ['quantity' => $product['quantity']]; // Same quantity, just test
    $result = $productClass->update($productId, $updateData);
    
    if ($result) {
        echo "✅ Product class update() method succeeded<br>";
    } else {
        echo "❌ Product class update() method failed<br>";
        
        // Let's look at the Product class code
        echo "<br>Checking Product class file...<br>";
        $productClassFile = file_get_contents('classes/Product.php');
        
        // Extract the update method
        preg_match('/public function update.*?\{.*?\}/s', $productClassFile, $matches);
        if (isset($matches[0])) {
            echo "<pre>" . htmlspecialchars($matches[0]) . "</pre>";
        } else {
            echo "Could not find update method<br>";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
    echo "Stack trace: <pre>" . $e->getTraceAsString() . "</pre>";
}
?>