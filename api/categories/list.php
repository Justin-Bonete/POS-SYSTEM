<?php
// api/categories/list.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Fix the paths - go up two levels to reach the includes folder
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/constants.php';
require_once __DIR__ . '/../../classes/Category.php';

// Require authentication (function comes from helpers.php)
requireAuth();

// Get status filter
$status = isset($_GET['status']) ? $_GET['status'] : 'active';

// Get categories
$category = new Category();
$categories = $category->getAll($status);

// Prepare response
$response = [
    'success' => true,
    'data' => $categories,
    'count' => count($categories)
];

// Get statistics if requested
if (isset($_GET['include_stats']) && $_GET['include_stats'] == 'true') {
    $statistics = $category->getStatistics();
    $response['statistics'] = $statistics;
}

// Return JSON response
echo json_encode($response);
exit();
?>