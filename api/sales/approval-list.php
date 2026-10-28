<?php
// api/sales/approval-list.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

// Only admins can view approval queue
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

try {
    $db = ConfigDatabase::getInstance()->getConnection();

    // Ensure table exists (matches request-approval.php)
    $db->exec("CREATE TABLE IF NOT EXISTS sale_approvals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sale_id INT NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        requested_by INT NOT NULL,
        approved_by INT DEFAULT NULL,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        cash_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        short_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_status_created(status, created_at),
        INDEX idx_sale_id(sale_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    $status = $_GET['status'] ?? 'pending';
    $limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 50;

    // Old pending notifications can be left behind if a pending order is deleted.
    // Mark them rejected so admins do not see un-actionable approval items.
    if ($status === 'pending') {
        $db->exec("
            UPDATE sale_approvals sa
            LEFT JOIN sales s ON s.id = sa.sale_id
            SET sa.status = 'rejected',
                sa.note = CONCAT(IFNULL(sa.note, ''), '\nAuto-cleared: linked sale was deleted.'),
                sa.updated_at = NOW()
            WHERE sa.status = 'pending'
              AND s.id IS NULL
        ");
    }

    $stmt = $db->prepare("
        SELECT sa.*, s.invoice_number, s.payment_method, s.payment_status,
               s.customer_name, s.customer_phone, s.created_at AS sale_created_at,
               u.username AS requested_by_name, au.username AS approved_by_name
        FROM sale_approvals sa
        JOIN sales s ON s.id = sa.sale_id
        LEFT JOIN users u ON u.id = sa.requested_by
        LEFT JOIN users au ON au.id = sa.approved_by
        WHERE (:status = 'all' OR sa.status = :status)
        ORDER BY sa.created_at DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':status', $status);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $rows
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit();
