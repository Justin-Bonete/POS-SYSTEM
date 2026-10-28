<?php
// api/products/search.php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized', 'data' => []]);
    exit();
}
if (!canAccess('products')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden', 'data' => []]);
    exit();
}

$response = [
    'success' => false,
    'message' => '',
    'data' => []
];

try {
    $product = new Product();
    
    if (!isset($_GET['q'])) {
        throw new Exception('No search query provided');
    }
    
    $searchTerm = trim((string)$_GET['q']);
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

    $results = ($searchTerm === '')
        ? []
        : $product->search($searchTerm, $limit);

    $response['success'] = true;
    $response['data'] = $results;
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
?>
