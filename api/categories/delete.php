<?php
// api/categories/delete.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit();
}

// Fix the path - go up two levels to reach the includes folder
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/constants.php';
require_once __DIR__ . '/../../classes/Category.php';

// Require authentication
if (!isLoggedIn()) {
    jsonResponse(false, null, 'User not authenticated', 401);
}
if (!canAccess('categories_delete')) {
    jsonResponse(false, null, 'You do not have permission to delete categories', 403);
}

// Get ID from request (check both GET and DELETE body)
$id = isset($_GET['id']) ? $_GET['id'] : null;

// If not in GET, try to get from JSON input
if (!$id) {
    $data = json_decode(file_get_contents("php://input"), true);
    $id = isset($data['id']) ? $data['id'] : null;
}

if (!$id) {
    jsonResponse(false, null, 'Category ID is required', 400);
}

// Create Category instance
$category = new Category();

// Check if category exists
$existing = $category->getById($id);
if (!$existing) {
    jsonResponse(false, null, 'Category not found', 404);
}

// Delete category
$result = $category->delete($id);

if (is_array($result)) {
    if (!empty($result['success'])) {
        jsonResponse(true, null, $result['message'] ?? 'Category deleted successfully');
    }

    jsonResponse(false, null, $result['message'] ?? 'Failed to delete category', 400);
}

if ($result) {
    jsonResponse(true, null, 'Category deleted successfully');
}

jsonResponse(false, null, 'Failed to delete category', 500);
?>
