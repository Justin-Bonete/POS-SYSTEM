<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/_bootstrap.php';

$conn = schoolStoreConnect(true);

$cart = json_decode($_POST['cart'], true);

if (empty($cart)) {
    echo json_encode(['success' => false, 'message' => 'Cart is empty']);
    exit;
}

// Start transaction
$conn->begin_transaction();

try {
    foreach ($cart as $item) {
        // Update inventory quantity
        $stmt = $conn->prepare("UPDATE school_inventory SET quantity = quantity - ? WHERE id = ? AND quantity >= ?");
        $stmt->bind_param("iii", $item['qty'], $item['id'], $item['qty']);
        $stmt->execute();
        
        if ($stmt->affected_rows == 0) {
            throw new Exception("Insufficient stock for " . $item['product']);
        }
        
        // Record retail sale (optional - create sales table)
        // INSERT INTO retail_sales (product_id, quantity, price, total, created_at) VALUES (...)
    }
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Retail sale completed successfully']);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
