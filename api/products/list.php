<?php
// api/products/list.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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
    'data'    => []
];

try {
    $product = new Product();

$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$rawLimit = isset($_GET['limit']) ? (int)$_GET['limit'] : 0;
// limit=0 means "no limit" (return all). Otherwise cap to 1000 to avoid runaway.
$limit = ($rawLimit === 0) ? 0 : max(1, min(1000, $rawLimit));

    $filters = [];
    if (!empty($_GET['status']))   $filters['status']   = $_GET['status'];
    if (!empty($_GET['category'])) $filters['category'] = $_GET['category'];
    if (!empty($_GET['code']))     $filters['product_code'] = $_GET['code'];
    if (!empty($_GET['search']))   $filters['search']   = $_GET['search'];
    if (!empty($_GET['fields']))   $filters['fields']   = $_GET['fields'];

    $products = $product->getAll($filters, $page, $limit);

    $response['success'] = true;
    $response['data']    = $products;
    $response['pagination'] = [
        'page'  => $page,
        'limit' => $limit,
        'count' => count($products)
    ];
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
