<?php
// api/products/update-quantity.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
if (!canAccess('inventory_manage')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['existing_product_id']) || !isset($data['quantity'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Product ID and quantity are required'
    ]);
    exit();
}

$productId = $data['existing_product_id'];
$quantityToAdd = intval($data['quantity']);

if ($quantityToAdd <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Quantity must be greater than 0'
    ]);
    exit();
}

try {
    $product = new Product();
    
    // Get current product
    $existingProduct = $product->getById($productId);
    
    if (!$existingProduct) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Product not found'
        ]);
        exit();
    }
    
    // Stock in using the Product helper so it gets logged in product_history
    $ok = $product->addStock(
        $productId,
        $quantityToAdd,
        'manual',
        null,
        'Stock in'
    );

    if (!$ok) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to add stock'
        ]);
        exit();
    }

    // Get updated product
    $updatedProduct = $product->getById($productId);
    $newQuantity = (int) ($updatedProduct['quantity'] ?? 0);
    
    echo json_encode([
        'success' => true,
        'message' => "Added {$quantityToAdd} units to {$existingProduct['name']}. New total: {$newQuantity}",
        'product' => $updatedProduct
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
