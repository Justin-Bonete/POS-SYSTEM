<?php
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null,
];

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated');
    }

    $orderId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($orderId <= 0) {
        throw new Exception('Invalid order ID');
    }

    $db = ConfigDatabase::getInstance()->getConnection();

    $saleStmt = $db->prepare("
        SELECT s.*
        FROM sales s
        WHERE s.id = ?
        LIMIT 1
    ");
    $saleStmt->execute([$orderId]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        throw new Exception('Pending order not found');
    }

    // If not pending, still return data so UI can display/print
    $status = $sale['payment_status'] ?? '';

    $itemsStmt = $db->prepare("
        SELECT
            si.*,
            p.price AS retail_price,
            p.wholesale_price,
            p.cost_price,
            p.quantity AS stock_quantity
        FROM sale_items si
        LEFT JOIN products p ON p.id = si.product_id
        WHERE si.sale_id = ?
        ORDER BY si.id ASC
    ");
    $itemsStmt->execute([$orderId]);

    $response['success'] = true;
    $response['data'] = [
        'sale' => $sale,
        'items' => $itemsStmt->fetchAll(PDO::FETCH_ASSOC),
    ];
} catch (Throwable $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
