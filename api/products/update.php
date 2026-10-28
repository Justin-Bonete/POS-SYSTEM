<?php
// api/products/update.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
if (!canAccess('products_edit')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$db = getDB();

function table_has_column(PDO $db, $table, $column) {
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $stmt = $db->prepare("SHOW COLUMNS FROM `$table` LIKE :col");
        $stmt->execute([':col' => $column]);
        $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

// Get PUT data
$data = json_decode(file_get_contents("php://input"), true);

// Check if ID is provided
if (!isset($data['id']) && !isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Product ID is required'
    ]);
    exit();
}

$id = isset($data['id']) ? $data['id'] : $_GET['id'];

try {
    // First check if product exists
    $checkQuery = "SELECT * FROM products WHERE id = :id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':id', $id);
    $checkStmt->execute();
    
    if ($checkStmt->rowCount() == 0) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Product not found'
        ]);
        exit();
    }
    
    // Build update query dynamically based on provided fields
    $updateFields = [];
    $params = [':id' => $id];
    
    if (array_key_exists('quantity', $data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Use the stock adjustment workflow to change quantity']);
        exit();
    }

    $allowedFields = ['name', 'description', 'price', 'category', 'status'];
    if (table_has_column($db, 'products', 'cost_price')) $allowedFields[] = 'cost_price';
    if (table_has_column($db, 'products', 'wholesale_price')) $allowedFields[] = 'wholesale_price';
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updateFields[] = "$field = :$field";
            
            // Validate specific fields
            switch($field) {
                case 'price':
                    if ($data[$field] <= 0) {
                        echo json_encode(['success' => false, 'message' => 'Price must be greater than 0']);
                        exit();
                    }
                    break;
                case 'quantity':
                    if ($data[$field] < 0) {
                        echo json_encode(['success' => false, 'message' => 'Quantity cannot be negative']);
                        exit();
                    }
                    break;
                case 'name':
                    if (empty(trim($data[$field]))) {
                        echo json_encode(['success' => false, 'message' => 'Product name cannot be empty']);
                        exit();
                    }
                    break;
                case 'cost_price':
                    if ($data[$field] < 0) {
                        echo json_encode(['success' => false, 'message' => 'Cost price cannot be negative']);
                        exit();
                    }
                    break;
                case 'wholesale_price':
                    if ($data[$field] < 0) {
                        echo json_encode(['success' => false, 'message' => 'Wholesale price cannot be negative']);
                        exit();
                    }
                    break;
            }
            
            $params[":$field"] = $data[$field];
        }
    }
    
    if (empty($updateFields)) {
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
        exit();
    }
    
    // Add updated_at timestamp
    $updateFields[] = "updated_at = NOW()";
    
    $query = "UPDATE products SET " . implode(', ', $updateFields) . " WHERE id = :id";
    
    $stmt = $db->prepare($query);
    
    if ($stmt->execute($params)) {
        // Fetch updated product
        $selectStmt = $db->prepare("SELECT * FROM products WHERE id = :id");
        $selectStmt->bindParam(':id', $id);
        $selectStmt->execute();
        $updatedProduct = $selectStmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'message' => 'Product updated successfully',
            'product' => $updatedProduct
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update product']);
    }
    
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
