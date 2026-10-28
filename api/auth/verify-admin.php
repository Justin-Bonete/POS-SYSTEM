<?php
// api/auth/verify-admin.php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

$response = [
    'success' => false,
    'message' => 'Invalid credentials'
];

try {
    // Only accept POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        $response['message'] = 'Method not allowed';
        echo json_encode($response);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || empty($body['username']) || empty($body['password'])) {
        http_response_code(400);
        $response['message'] = 'Username and password required';
        echo json_encode($response);
        exit;
    }

    $username = trim($body['username']);
    $password = $body['password'];

    $db = getDB();
    $stmt = $db->prepare("SELECT password, role, status FROM users WHERE username = :username LIMIT 1");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && $user['status'] === 'active' && password_verify($password, $user['password']) && $user['role'] === 'admin') {
        $response['success'] = true;
        $response['message'] = 'Authorized';
    } else {
        $response['message'] = 'Invalid admin credentials';
    }
} catch (Exception $e) {
    http_response_code(500);
    $response['message'] = 'Server error';
}

echo json_encode($response);
