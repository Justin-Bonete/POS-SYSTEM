<?php
// api/products/get.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
if (!canAccess('products')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$db = getDB();

// Check if ID or product_code is provided
$hasId = isset($_GET['id']) && $_GET['id'] !== '';
$hasCode = isset($_GET['code']) && $_GET['code'] !== '';

if (!$hasId && !$hasCode) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Please provide product ID or product code'
    ]);
    exit();
}

try {
    // Prefer single-key lookup to keep index usage efficient on large tables
    if ($hasId) {
        $query = "SELECT * FROM products WHERE id = :id LIMIT 1";
    } else {
        $query = "SELECT * FROM products WHERE product_code = :code LIMIT 1";
    }

    $stmt = $db->prepare($query);
    if ($hasId) {
        $id = (int)$_GET['id'];
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    } else {
        $code = (string)$_GET['code'];
        $stmt->bindValue(':code', $code);
    }
    $stmt->execute();
    
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($product) {
        echo json_encode([
            'success' => true,
            'product' => $product
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Product not found'
        ]);
    }
    
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
