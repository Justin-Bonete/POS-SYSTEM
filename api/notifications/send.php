<?php
// api/notifications/send.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $db = ConfigDatabase::getInstance()->getConnection();

    // Ensure table exists
    $db->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        sender_role VARCHAR(20) NOT NULL,
        target_role ENUM('admin','manager','cashier','all') NOT NULL DEFAULT 'admin',
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_target_created (target_role, created_at),
        INDEX idx_is_read (is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    $payload = json_decode(file_get_contents('php://input'), true);
    $message = trim($payload['message'] ?? '');
    $target = $payload['target_role'] ?? 'admin';

    $validTargets = ['admin', 'manager', 'cashier', 'all'];
    if (!in_array($target, $validTargets, true)) {
        $target = 'admin';
    }

    if ($message === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Message is required']);
        exit;
    }

    $senderId = (int)($_SESSION['user_id'] ?? 0);
    $senderRole = $_SESSION['user_role'] ?? 'cashier';

    $stmt = $db->prepare("INSERT INTO notifications (sender_id, sender_role, target_role, message) VALUES (?, ?, ?, ?)");
    $stmt->execute([$senderId, $senderRole, $target, $message]);

    echo json_encode(['success' => true, 'message' => 'Sent']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
}
exit;
