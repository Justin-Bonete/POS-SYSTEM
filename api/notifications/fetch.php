<?php
// api/notifications/fetch.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

    $role = $_SESSION['user_role'] ?? 'cashier';
    $userId = (int)($_SESSION['user_id'] ?? 0);

    $stmt = $db->prepare("
        SELECT n.id, n.message, n.target_role, n.sender_role, n.is_read, n.created_at,
               COALESCE(u.full_name, u.username, 'User') AS sender_name
        FROM notifications n
        LEFT JOIN users u ON u.id = n.sender_id
        WHERE n.target_role IN ('all', :role)
           OR n.sender_id = :uid
        ORDER BY n.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([':role' => $role, ':uid' => $userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()]);
}
exit;
