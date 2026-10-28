<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $db = ConfigDatabase::getInstance()->getConnection();

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

    $search = trim($_GET['search'] ?? '');
    $limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 100;

    $query = "
        SELECT
            s.*,
            sa.id AS approval_id,
            sa.status AS approval_status,
            sa.total_amount AS approval_total_amount,
            sa.cash_amount AS approval_cash_amount,
            sa.short_amount AS approval_short_amount,
            CASE
                WHEN sa.status = 'approved' THEN GREATEST(COALESCE(sa.short_amount, 0), 0)
                ELSE GREATEST(COALESCE(s.total_amount, 0) - COALESCE(s.cash_amount, 0), 0)
            END AS outstanding_amount
        FROM sales s
        LEFT JOIN (
            SELECT latest.*
            FROM sale_approvals latest
            INNER JOIN (
                SELECT sale_id, MAX(id) AS max_id
                FROM sale_approvals
                GROUP BY sale_id
            ) grouped ON grouped.max_id = latest.id
        ) sa ON sa.sale_id = s.id
        WHERE (
            (sa.status IN ('pending', 'approved') AND GREATEST(COALESCE(sa.short_amount, 0), 0) > 0)
        )
    ";

    if ($search !== '') {
        $query .= " AND (
            s.customer_name LIKE :search
            OR s.customer_phone LIKE :search
            OR s.invoice_number LIKE :search
        )";
    }

    $query .= " ORDER BY s.created_at DESC LIMIT :limit";

    $stmt = $db->prepare($query);
    if ($search !== '') {
        $searchTerm = '%' . $search . '%';
        $stmt->bindValue(':search', $searchTerm, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    echo json_encode([
        'success' => true,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

exit();
