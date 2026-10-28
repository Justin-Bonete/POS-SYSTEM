<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

$sb_log_file = __DIR__ . '/../../logs/settle_balance.log';
function sbLog($msg) {
    global $sb_log_file;
    $ts = date('Y-m-d H:i:s');
    if (!file_exists(dirname($sb_log_file))) @mkdir(dirname($sb_log_file), 0777, true);
    @file_put_contents($sb_log_file, "[$ts] $msg\n", FILE_APPEND);
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $payload = json_decode(file_get_contents('php://input'), true);
    sbLog('settle-balance start user=' . ($_SESSION['user_id'] ?? 'n/a') . ' payload=' . json_encode($payload));
    if (!is_array($payload)) {
        throw new Exception('Invalid JSON payload');
    }

    $orderId = (int) ($payload['order_id'] ?? 0);
    if ($orderId <= 0) {
        throw new Exception('Invalid order ID');
    }

    $db = ConfigDatabase::getInstance()->getConnection();
    $db->beginTransaction();

    $saleStmt = $db->prepare("
        SELECT *
        FROM sales
        WHERE id = ?
        FOR UPDATE
    ");
    $saleStmt->execute([$orderId]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        throw new Exception('Sale not found');
    }

    $approvalStmt = $db->prepare("
        SELECT *
        FROM sale_approvals
        WHERE sale_id = ?
          AND status = 'approved'
          AND short_amount > 0
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");
    $approvalStmt->execute([$orderId]);
    $approval = $approvalStmt->fetch(PDO::FETCH_ASSOC);

    if (!$approval) {
        throw new Exception('Approved balance record not found');
    }

    $settlementAmount = (float) ($approval['short_amount'] ?? 0);
    if ($settlementAmount <= 0) {
        throw new Exception('Balance is already settled');
    }

    $updatedCashAmount = (float) ($sale['cash_amount'] ?? 0) + $settlementAmount;
    $noteSuffix = "\nBalance settled on " . date('Y-m-d H:i:s');

    $updateSale = $db->prepare("
        UPDATE sales
        SET cash_amount = :cash_amount,
            payment_status = 'paid',
            change_amount = 0,
            customer_name = :customer_name,
            customer_phone = :customer_phone,
            notes = CONCAT(IFNULL(notes, ''), :note_suffix)
        WHERE id = :id
    ");
    $updateSale->execute([
        ':cash_amount' => $updatedCashAmount,
        ':customer_name' => $payload['customer_name'] ?? $sale['customer_name'] ?? null,
        ':customer_phone' => $payload['customer_phone'] ?? $sale['customer_phone'] ?? null,
        ':note_suffix' => $noteSuffix,
        ':id' => $orderId
    ]);
    sbLog('EXEC updateSale params=' . json_encode([
        'cash_amount' => $updatedCashAmount,
        'customer_name' => $payload['customer_name'] ?? $sale['customer_name'] ?? null,
        'customer_phone' => $payload['customer_phone'] ?? $sale['customer_phone'] ?? null,
        'note_suffix' => $noteSuffix,
        'id' => $orderId
    ]));

    $updateApproval = $db->prepare("
        UPDATE sale_approvals
        SET cash_amount = total_amount,
            short_amount = 0,
            note = CONCAT(IFNULL(note, ''), :note_suffix),
            updated_at = NOW()
        WHERE id = :id
    ");
    $updateApproval->execute([
        ':note_suffix' => $noteSuffix,
        ':id' => (int) $approval['id']
    ]);
    sbLog('EXEC updateApproval params=' . json_encode([
        'note_suffix' => $noteSuffix,
        'id' => (int) $approval['id']
    ]));

    $itemsStmt = $db->prepare("
        SELECT *
        FROM sale_items
        WHERE sale_id = ?
        ORDER BY id ASC
    ");
    $itemsStmt->execute([$orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    sbLog('About to commit settle-balance; txnActive=' . ($db->inTransaction() ? 'yes' : 'no'));
    $db->commit();
    sbLog('settle-balance commit success order_id=' . $orderId . ' amount=' . $settlementAmount);

    $sale['cash_amount'] = $updatedCashAmount;
    $sale['payment_status'] = 'paid';
    $sale['change_amount'] = 0;
    $sale['original_cash_amount'] = $settlementAmount;
    $sale['cash_paid_amount'] = $settlementAmount;
    $sale['approval_short_amount'] = $settlementAmount;
    $sale['balance_paid_amount'] = $settlementAmount;
    $sale['balance_due'] = 0;
    $sale['approval_status'] = 'approved';

    echo json_encode([
        'success' => true,
        'message' => 'Balance marked as paid',
        'data' => [
            'sale' => $sale,
            'items' => $items
        ]
    ]);
} catch (Throwable $e) {
    $txnActive = (isset($db) && $db instanceof PDO && $db->inTransaction()) ? 'yes' : 'no';
    sbLog('ERROR: ' . $e->getMessage() . ' order_id=' . ($orderId ?? 'n/a') . ' txnActive=' . $txnActive . ' trace=' . $e->getTraceAsString());
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

exit();
