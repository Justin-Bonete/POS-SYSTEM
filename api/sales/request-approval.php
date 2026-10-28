<?php
// api/sales/request-approval.php
// Creates a pending sale and an approval request when tendered cash is short

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sale_discount.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Sale.php';
require_once __DIR__ . '/../../classes/Product.php';

$ra_log_file = __DIR__ . '/../../logs/request_approval.log';
function raLog($msg) {
    global $ra_log_file;
    $ts = date('Y-m-d H:i:s');
    if (!file_exists(dirname($ra_log_file))) @mkdir(dirname($ra_log_file), 0777, true);
    @file_put_contents($ra_log_file, "[$ts] $msg\n", FILE_APPEND);
}

// Only logged-in users can create approval requests
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

/**
 * Ensure the sale_approvals table exists (auto-migration for this feature)
 */
function ensureApprovalTable(PDO $db)
{
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
}

function generateApprovalInvoice(): string
{
    return 'PEND-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
}

try {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        throw new Exception('Invalid JSON payload');
    }

    $orderId = isset($payload['order_id']) ? (int) $payload['order_id'] : 0;

    $itemsInput = $payload['items'] ?? [];
    raLog('request-approval start user=' . ($_SESSION['user_id'] ?? 'n/a') . ' payload=' . json_encode($payload));
    if (empty($itemsInput) || !is_array($itemsInput)) {
        throw new Exception('Cart is empty');
    }

    $sale = null;
    $product = new Product();
    $items = [];

    foreach ($itemsInput as $index => $item) {
        $productId = (int) ($item['product_id'] ?? 0);
        $quantity = (int) ($item['quantity'] ?? 0);
        $unitPrice = isset($item['price']) ? (float) $item['price'] : null;
        $productName = trim((string) ($item['product_name'] ?? ''));
        $isBalanceItem = !empty($item['is_balance_item']) || $productId <= 0;
        $priceMode = saleDiscountNormalizeMode($item['price_mode'] ?? 'retail');

        if ($quantity <= 0) {
            throw new Exception("Invalid cart item at position " . ($index + 1));
        }

        if ($isBalanceItem) {
            if ($productName === '' || $unitPrice === null || $unitPrice <= 0) {
                throw new Exception("Invalid balance item at position " . ($index + 1));
            }
        } else {
            $productDetails = $product->getById($productId);
            if (!$productDetails) {
                throw new Exception("Product ID {$productId} not found");
            }

            // Do not allow requesting more than available stock
            $availableQty = (int) ($productDetails['quantity'] ?? 0);
            if ($availableQty < $quantity) {
                throw new Exception("Insufficient stock for {$productDetails['name']} (available: {$availableQty})");
            }

            if ($unitPrice === null || $unitPrice <= 0) {
                $unitPrice = (float) ($productDetails['price'] ?? 0);
            }
            $productName = $productDetails['name'];
        }

        $subtotal = $unitPrice * $quantity;

        $items[] = [
            'product_id' => $productId,
            'product_name' => $productName,
            'quantity' => $quantity,
            'price_per_unit' => $unitPrice,
            'subtotal' => $subtotal,
            'price_mode' => $priceMode,
            'is_balance_item' => $isBalanceItem,
        ];
    }

    $totals = saleDiscountComputeTotals(
        $items,
        $payload['discount_percent'] ?? null,
        $payload['discount_amount'] ?? 0
    );
    $total = $totals['total_amount'];

    $cashAmount = isset($payload['cash_amount']) ? (float) $payload['cash_amount'] : 0;
    $paymentMethod = $payload['payment_method'] ?? 'cash';
    $customerName = $payload['customer_name'] ?? null;
    $customerPhone = $payload['customer_phone'] ?? null;
    $notes = $payload['notes'] ?? '';
    $tellerName = isset($payload['teller_name']) ? trim((string) $payload['teller_name']) : '';
    if ($tellerName === '') {
        throw new Exception('Salesman name is required');
    }

    $shortAmount = max($total - $cashAmount, 0);
    if ($shortAmount <= 0) {
        throw new Exception('This endpoint is only for short-cash approvals');
    }

    $db = ConfigDatabase::getInstance()->getConnection();
    if ($orderId > 0) {
        ensureApprovalTable($db);
        $db->beginTransaction();

        $orderStmt = $db->prepare('SELECT * FROM sales WHERE id = ? FOR UPDATE');
        $orderStmt->execute([$orderId]);
        $sale = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if (!$sale || ($sale['payment_status'] ?? '') !== 'pending') {
            throw new Exception('Pending order not found or already settled');
        }

        if (!empty($items)) {
            $deleteItems = $db->prepare('DELETE FROM sale_items WHERE sale_id = ?');
            $deleteItems->execute([$orderId]);

            $insertItem = $db->prepare('INSERT INTO sale_items (sale_id, product_id, product_name, quantity, price_per_unit, subtotal) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($items as $item) {
                $insertItem->execute([
                    $orderId,
                    ((int) $item['product_id'] > 0 ? (int) $item['product_id'] : null),
                    $item['product_name'],
                    $item['quantity'],
                    $item['price_per_unit'],
                    $item['subtotal']
                ]);
            }
        }

        $updatedNotes = trim(($notes ?? $sale['notes'] ?? '') . '\nShort payment: ' . number_format($shortAmount, 2));
        $updateSale = $db->prepare("UPDATE sales SET total_amount = ?, discount_amount = ?, discount_percent = ?, payment_method = ?, cash_amount = ?, change_amount = 0, customer_name = ?, customer_phone = ?, teller_name = ?, notes = ?, updated_at = NOW() WHERE id = ?");
        $updateSale->execute([
            $total,
            $totals['discount_amount'],
            $totals['discount_percent'],
            $paymentMethod,
            $cashAmount,
            $customerName,
            $customerPhone,
            $tellerName,
            $updatedNotes,
            $orderId
        ]);

        ensureApprovalTable($db);
        $stmt = $db->prepare("INSERT INTO sale_approvals (sale_id, status, requested_by, total_amount, cash_amount, short_amount, note) VALUES (?, 'pending', ?, ?, ?, ?, ?)");
        $approvalParams = [
            $orderId,
            (int) $_SESSION['user_id'],
            $total,
            $cashAmount,
            $shortAmount,
            $updatedNotes
        ];
        raLog('EXEC prepare: INSERT INTO sale_approvals params=' . json_encode($approvalParams));
        $stmt->execute($approvalParams);

        $approvalId = (int) $db->lastInsertId();
        raLog('approval created id=' . $approvalId . ' sale_id=' . $orderId);
        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Sent to admin for approval',
            'data' => [
                'sale_id' => $orderId,
                'approval_id' => $approvalId,
                'invoice_number' => $sale['invoice_number'] ?? null,
                'total_amount' => $total,
                'discount_amount' => $totals['discount_amount'],
                'discount_percent' => $totals['discount_percent'],
                'cash_amount' => $cashAmount,
                'short_amount' => $shortAmount,
                'items' => $items,
                'sale' => [
                    'id' => $orderId,
                    'invoice_number' => $sale['invoice_number'] ?? null,
                    'total_amount' => $total,
                    'cash_amount' => $cashAmount,
                    'payment_status' => 'pending'
                ]
            ]
        ]);
        exit;
    }

    $db = ConfigDatabase::getInstance()->getConnection();
    $isInTransaction = $db->inTransaction();
    if (!$isInTransaction) {
        $db->beginTransaction();
    }
    $invoice = generateApprovalInvoice();
    $saleObj = new Sale();
    $saleId = $saleObj->create([
        'invoice_number' => $invoice,
        'user_id' => (int) $_SESSION['user_id'],
        'customer_name' => $customerName,
        'customer_email' => null,
        'customer_phone' => $customerPhone,
        'teller_name' => $tellerName,
        'total_amount' => $total,
        'discount_amount' => $totals['discount_amount'],
        'discount_percent' => $totals['discount_percent'],
        'payment_method' => $paymentMethod,
        'payment_status' => 'pending',
        'cash_amount' => $cashAmount,
        'change_amount' => 0,
        'notes' => trim($notes . '\nShort payment: ' . number_format($shortAmount, 2)),
        'items' => $items,
    ]);

    if (!$saleId) {
        throw new Exception('Failed to create pending sale');
    }

    ensureApprovalTable($db);
    $stmt = $db->prepare("INSERT INTO sale_approvals (sale_id, status, requested_by, total_amount, cash_amount, short_amount, note) VALUES (?, 'pending', ?, ?, ?, ?, ?)");
    $approvalParams = [
        $saleId,
        (int) $_SESSION['user_id'],
        $total,
        $cashAmount,
        $shortAmount,
        trim($notes . '\nShort payment: ' . number_format($shortAmount, 2))
    ];
    raLog('EXEC prepare: INSERT INTO sale_approvals params=' . json_encode($approvalParams));
    $stmt->execute($approvalParams);

    $approvalId = (int) $db->lastInsertId();
    raLog('approval created id=' . $approvalId . ' sale_id=' . $saleId);
    if ($db->inTransaction()) {
        $db->commit();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Sent to admin for approval',
        'data' => [
            'sale_id' => $saleId,
            'approval_id' => $approvalId,
            'invoice_number' => $invoice,
            'total_amount' => $total,
            'discount_amount' => $totals['discount_amount'],
            'discount_percent' => $totals['discount_percent'],
            'cash_amount' => $cashAmount,
            'short_amount' => $shortAmount,
            'items' => $items,
            'sale' => [
                'id' => $saleId,
                'invoice_number' => $invoice,
                'total_amount' => $total,
                'cash_amount' => $cashAmount,
                'payment_status' => 'pending'
            ]
        ]
    ]);
} catch (Throwable $e) {
    // capture diagnostic info and roll back if needed
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    $txnActive = (isset($db) && $db instanceof PDO && $db->inTransaction()) ? 'yes' : 'no';
    raLog('ERROR: ' . $e->getMessage() . ' txnActive=' . $txnActive . ' trace=' . $e->getTraceAsString());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

exit();
