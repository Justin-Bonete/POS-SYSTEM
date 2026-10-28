<?php
// api/categories/add.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/constants.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../classes/Category.php';
require_once __DIR__ . '/../../classes/Validator.php';

// Require authentication
requireAuth();
if (!canAccess('categories_add')) {
    jsonResponse(false, null, 'You do not have permission to add categories', 403);
}

// Get posted data
$data = json_decode(file_get_contents("php://input"), true);

if (!$data) {
    jsonResponse(false, null, 'Invalid request data', 400);
}

// Validate input
$validator = new Validator();
if (!$validator->validateCategory($data)) {
    jsonResponse(false, null, $validator->getErrors(), 422);
}

// Sanitize input
$data = $validator->sanitize($data);

// Add category
$category = new Category();
$result = $category->create($data);

if ($result['success']) {
    jsonResponse(true, ['id' => $result['id']], $result['message']);
} else {
    jsonResponse(false, null, $result['message'], 400);
}
?>