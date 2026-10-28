<?php
// api/products/add.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
if (!canAccess('products_add')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

// Get database connection
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

// Get posted data
$data = json_decode(file_get_contents("php://input"), true);

// Validate required fields
if (!isset($data['name']) || !isset($data['price']) || !isset($data['quantity'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields: name, price, and quantity are required'
    ]);
    exit();
}

// Sanitize and validate inputs
$name = trim($data['name']);
$price = floatval($data['price']);
$quantity = intval($data['quantity']);
$category = isset($data['category']) ? trim($data['category']) : 'Uncategorized';
$description = isset($data['description']) ? trim($data['description']) : '';
$cost_price = isset($data['cost_price']) ? floatval($data['cost_price']) : 0;
$wholesale_price = isset($data['wholesale_price']) ? floatval($data['wholesale_price']) : 0;

if ($cost_price < 0) {
    echo json_encode(['success' => false, 'message' => 'Cost price cannot be negative']);
    exit();
}
if ($wholesale_price < 0) {
    echo json_encode(['success' => false, 'message' => 'Wholesale price cannot be negative']);
    exit();
}

// Validate data
if (empty($name)) {
    echo json_encode(['success' => false, 'message' => 'Product name cannot be empty']);
    exit();
}

if ($price <= 0) {
    echo json_encode(['success' => false, 'message' => 'Price must be greater than 0']);
    exit();
}

if ($quantity < 0) {
    echo json_encode(['success' => false, 'message' => 'Quantity cannot be negative']);
    exit();
}

try {
    // Generate unique product code
    $product_code = 'PRD' . strtoupper(uniqid()) . rand(100, 999);
    
    // Insert product into database
    $cols = ['product_code', 'name', 'description', 'price', 'quantity', 'category'];
    $vals = [':product_code', ':name', ':description', ':price', ':quantity', ':category'];

    $supportsCostPrice = table_has_column($db, 'products', 'cost_price');
    $supportsWholesalePrice = table_has_column($db, 'products', 'wholesale_price');
    if ($supportsCostPrice) {
        $cols[] = 'cost_price';
        $vals[] = ':cost_price';
    }
    if ($supportsWholesalePrice) {
        $cols[] = 'wholesale_price';
        $vals[] = ':wholesale_price';
    }

    $query = "INSERT INTO products (" . implode(', ', $cols) . ")
              VALUES (" . implode(', ', $vals) . ")";
    
    $stmt = $db->prepare($query);
    
    $stmt->bindParam(':product_code', $product_code);
    $stmt->bindParam(':name', $name);
    $stmt->bindParam(':description', $description);
    $stmt->bindParam(':price', $price);
    $stmt->bindParam(':quantity', $quantity);
    $stmt->bindParam(':category', $category);
    if ($supportsCostPrice) $stmt->bindParam(':cost_price', $cost_price);
    if ($supportsWholesalePrice) $stmt->bindParam(':wholesale_price', $wholesale_price);
    
    if ($stmt->execute()) {
        $product_id = $db->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'message' => 'Product added successfully',
            'product' => [
                'id' => $product_id,
                'product_code' => $product_code,
                'name' => $name,
                'price' => $price,
                'quantity' => $quantity,
                'category' => $category,
                'description' => $description
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add product']);
    }
    
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
