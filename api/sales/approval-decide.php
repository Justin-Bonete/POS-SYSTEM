<?php
// api/sales/approval-decide.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
// Avoid echoing PHP warnings into the JSON response
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../classes/ProductHistory.php';

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

try {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new Exception('Invalid JSON payload');
    }

    $approvalId = (int) ($payload['approval_id'] ?? 0);
    $action = strtolower($payload['action'] ?? '');
    $note = trim($payload['note'] ?? '');

    if ($approvalId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
        throw new Exception('Invalid approval request');
    }

    $db = ConfigDatabase::getInstance()->getConnection();

    // Ensure table exists
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
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");

    $db->beginTransaction();

    // Lock approval row. Use a left join so reject/remove can still clear stale
    // notifications whose pending sale was already deleted.
    $stmt = $db->prepare("
        SELECT sa.*, s.invoice_number, s.payment_status, s.payment_method, s.total_amount, s.cash_amount
        FROM sale_approvals sa
        LEFT JOIN sales s ON s.id = sa.sale_id
        WHERE sa.id = ?
        FOR UPDATE
    ");
    $stmt->execute([$approvalId]);
    $approval = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$approval) {
        throw new Exception('Approval request not found');
    }
    if ($approval['status'] !== 'pending') {
        throw new Exception('Approval already processed');
    }

    if ($action === 'reject') {
        $upd = $db->prepare("UPDATE sale_approvals 
                             SET status = 'rejected', 
                                 approved_by = :approved_by, 
                                 note = CONCAT(IFNULL(note,''), :note), 
                                 updated_at = NOW() 
                             WHERE id = :id");
        $upd->bindValue(':approved_by', $_SESSION['user_id'] ?? null, PDO::PARAM_INT);
        $upd->bindValue(':id', $approvalId, PDO::PARAM_INT);
        $upd->bindValue(':note', $note ? "\nAdmin note: " . $note : '');
        $upd->execute();

        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Request rejected']);
        exit();
    }

    if (empty($approval['sale_id']) || empty($approval['invoice_number'])) {
        throw new Exception('Linked pending sale was not found. Remove this notification instead.');
    }

    // Approve flow

    // Fetch sale items
    $itemsStmt = $db->prepare("
        SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id ASC
    ");
    $itemsStmt->execute([$approval['sale_id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($items)) {
        throw new Exception('No items found for this sale');
    }

    $product = new Product();

    // Validate stock
    foreach ($items as $item) {
        if ((int) ($item['product_id'] ?? 0) <= 0) {
            continue;
        }
        $p = $product->getById((int) $item['product_id']);
        if (!$p) {
            throw new Exception("Product ID {$item['product_id']} not found");
        }
        if ((int) $p['quantity'] < (int) $item['quantity']) {
            throw new Exception("Insufficient stock for {$p['name']} (available: {$p['quantity']})");
        }
    }

    // Deduct stock & log history
    foreach ($items as $item) {
        if ((int) ($item['product_id'] ?? 0) <= 0) {
            continue;
        }
        if (!$product->adjustStock(
            (int) $item['product_id'],
            -(int) $item['quantity'],
            'stock_out',
            'sale',
            (int) $approval['sale_id'],
            "Approved short-cash sale #{$approval['invoice_number']}"
        )) {
            throw new Exception("Insufficient stock or failed stock update for {$item['product_name']}");
        }
    }

    // Update sale as paid (even if short, outstanding tracked in sale_approvals.short_amount)
    $saleUpdate = $db->prepare("UPDATE sales 
        SET payment_status = 'paid', 
            cash_amount = :cash_amount, 
            change_amount = 0, 
            notes = CONCAT(IFNULL(notes,''), :note) 
        WHERE id = :id");
    $saleUpdate->bindValue(':id', $approval['sale_id'], PDO::PARAM_INT);
    $saleUpdate->bindValue(':cash_amount', $approval['cash_amount']);
    $saleUpdate->bindValue(':note', "\nApproved short payment by admin on " . date('Y-m-d H:i:s'));
    $saleUpdate->execute();

    // Mark approval
    $approveStmt = $db->prepare("UPDATE sale_approvals 
        SET status = 'approved', 
            approved_by = :approved_by, 
            note = CONCAT(IFNULL(note,''), :note), 
            updated_at = NOW() 
        WHERE id = :id");
    $approveStmt->bindValue(':id', $approvalId, PDO::PARAM_INT);
    $approveStmt->bindValue(':approved_by', $_SESSION['user_id'] ?? null, PDO::PARAM_INT);
    $approveStmt->bindValue(':note', $note ? "\nAdmin note: " . $note : '');
    $approveStmt->execute();

    $db->commit();

    $saleReceiptStmt = $db->prepare("
        SELECT *
        FROM sales
        WHERE id = ?
        LIMIT 1
    ");
    $saleReceiptStmt->execute([(int) $approval['sale_id']]);
    $saleRecord = $saleReceiptStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Sale approved and completed',
        'data' => [
            'sale' => $saleRecord,
            'items' => $items,
            'approval' => [
                'id' => (int) $approvalId,
                'status' => 'approved',
                'total_amount' => (float) ($approval['total_amount'] ?? 0),
                'cash_amount' => (float) ($approval['cash_amount'] ?? 0),
                'short_amount' => (float) ($approval['short_amount'] ?? 0)
            ]
        ]
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit();
