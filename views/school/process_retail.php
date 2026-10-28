<?php
// process_retail.php - Process retail checkout and save to database
header('Content-Type: application/json');
require_once __DIR__ . '/_bootstrap.php';

$conn = schoolStoreConnect(true);

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);
$cart = $data['cart'];

if (empty($cart)) {
    echo json_encode(['success' => false, 'message' => 'Cart is empty']);
    exit;
}

// Start transaction
$conn->begin_transaction();

try {
    // Calculate total amount
    $total_amount = 0;
    foreach ($cart as $item) {
        $total_amount += $item['qty'] * $item['price'];
    }
    
    // Insert into retail_sales
    $sale_date = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("INSERT INTO retail_sales (sale_date, total_amount) VALUES (?, ?)");
    $stmt->bind_param("sd", $sale_date, $total_amount);
    $stmt->execute();
    $sale_id = $stmt->insert_id;
    
    // Insert items and update inventory
    foreach ($cart as $item) {
        // Insert sale item
        $stmt = $conn->prepare("INSERT INTO retail_sale_items 
            (sale_id, product_id, product_name, barcode, quantity, unit_price, total_price) 
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $total_price = $item['qty'] * $item['price'];
        $stmt->bind_param("iissddd", 
            $sale_id, 
            $item['id'], 
            $item['product'], 
            $item['barcode'], 
            $item['qty'], 
            $item['price'], 
            $total_price
        );
        $stmt->execute();
        
        // Update inventory quantity
        $stmt = $conn->prepare("UPDATE school_inventory SET quantity = quantity - ? WHERE id = ? AND quantity >= ?");
        $stmt->bind_param("iii", $item['qty'], $item['id'], $item['qty']);
        $stmt->execute();
        
        if ($stmt->affected_rows == 0) {
            throw new Exception("Insufficient stock for " . $item['product']);
        }
    }
    
    $conn->commit();
    echo json_encode(['success' => true, 'message' => 'Checkout successful', 'sale_id' => $sale_id]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
