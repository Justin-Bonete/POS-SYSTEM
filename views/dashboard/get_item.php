<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['selected_system']) || $_SESSION['selected_system'] !== 'drugstore') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$itemId = (int)($_GET['id'] ?? 0);
if ($itemId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid item ID']);
    exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "drugstore1_db";

try {
    $db = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare("SELECT * FROM drugstore_inventory WHERE id = ?");
    $stmt->execute([$itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$item) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Item not found']);
        exit();
    }

    $item['expiry_date'] = !empty($item['expiry_date']) && $item['expiry_date'] !== '0000-00-00'
        ? $item['expiry_date']
        : '';

    echo json_encode(['success' => true, 'item' => $item]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load item data']);
}
