<?php
// api/sales/process.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Log file for debugging
$logFile = __DIR__ . '/../../logs/sales_debug.log';
if (!file_exists(dirname($logFile))) {
    mkdir(dirname($logFile), 0777, true);
}

function debugLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function settleLinkedBalances(PDO $db, array $sourceBalanceIds, ?int $userId, string $reference): array {
    $settled = [];
    $seen = [];

    $selectApproval = $db->prepare("
        SELECT sa.*, s.customer_name, s.customer_phone
        FROM sale_approvals sa
        INNER JOIN sales s ON s.id = sa.sale_id
        WHERE sa.sale_id = ?
          AND sa.status = 'approved'
          AND sa.short_amount > 0
        ORDER BY sa.id DESC
        LIMIT 1
        FOR UPDATE
    ");
    $selectSale = $db->prepare("SELECT * FROM sales WHERE id = ? FOR UPDATE");
    $updateSale = $db->prepare("
        UPDATE sales
        SET cash_amount = :cash_amount,
            payment_status = 'paid',
            change_amount = 0,
            notes = CONCAT(IFNULL(notes, ''), :note_suffix)
        WHERE id = :id
    ");
    $updateApproval = $db->prepare("
        UPDATE sale_approvals
        SET cash_amount = total_amount,
            short_amount = 0,
            note = CONCAT(IFNULL(note, ''), :note_suffix),
            updated_at = NOW()
        WHERE id = :id
    ");
    $selectItems = $db->prepare("
        SELECT *
        FROM sale_items
        WHERE sale_id = ?
        ORDER BY id ASC
    ");

    foreach ($sourceBalanceIds as $rawId) {
        $saleId = (int) $rawId;
        if ($saleId <= 0 || isset($seen[$saleId])) {
            continue;
        }
        $seen[$saleId] = true;

        $selectSale->execute([$saleId]);
        $linkedSale = $selectSale->fetch(PDO::FETCH_ASSOC);
        if (!$linkedSale) {
            throw new Exception("Linked balance sale #{$saleId} not found");
        }

        $selectApproval->execute([$saleId]);
        $approval = $selectApproval->fetch(PDO::FETCH_ASSOC);
        if (!$approval) {
            throw new Exception("Approved balance for sale #{$saleId} was not found or is already settled");
        }

        $settlementAmount = (float) ($approval['short_amount'] ?? 0);
        if ($settlementAmount <= 0) {
            continue;
        }

        $noteSuffix = "\nAuto-settled from {$reference} on " . date('Y-m-d H:i:s');
        $updateSale->execute([
            ':cash_amount' => (float) ($linkedSale['cash_amount'] ?? 0) + $settlementAmount,
            ':note_suffix' => $noteSuffix,
            ':id' => $saleId
        ]);
        $updateApproval->execute([
            ':note_suffix' => $noteSuffix,
            ':id' => (int) $approval['id']
        ]);

        $selectItems->execute([$saleId]);
        $balanceItems = $selectItems->fetchAll(PDO::FETCH_ASSOC);

        $settled[] = [
            'sale_id' => $saleId,
            'invoice_number' => $linkedSale['invoice_number'] ?? null,
            'amount' => $settlementAmount,
            'customer_name' => $linkedSale['customer_name'] ?? null,
            'items' => $balanceItems,
        ];
    }

    return $settled;
}

debugLog("=== New sale request started ===");

// Include required files
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/sale_discount.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Sale.php';
require_once __DIR__ . '/../../classes/Product.php';

// Helper function for invoice number
function generateInvoiceNumber() {
    return 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'data' => null
];

try {
    // Check if user is authenticated
    if (!isset($_SESSION['user_id'])) {
        debugLog("ERROR: User not authenticated");
        throw new Exception('User not authenticated');
    }
    debugLog("User ID: " . $_SESSION['user_id']);

    // Get POST data
    $input = file_get_contents('php://input');
    debugLog("Raw input: " . $input);
    
    $data = json_decode($input, true);
    debugLog("Decoded data: " . print_r($data, true));

    if (!$data) {
        throw new Exception('Invalid JSON data: ' . json_last_error_msg());
    }

    // Validate required fields
    if (!isset($data['items']) || empty($data['items'])) {
        debugLog("ERROR: No items in cart");
        throw new Exception('No items in cart');
    }
    debugLog("Number of items: " . count($data['items']));

    $tellerName = isset($data['teller_name']) ? trim((string) $data['teller_name']) : '';
    if ($tellerName === '') {
        debugLog("ERROR: Salesman name is required");
        throw new Exception('Salesman name is required');
    }

    // Initialize classes
    $sale = new Sale();
    $product = new Product();

    // Get database connection
    $db = ConfigDatabase::getInstance()->getConnection();
    
    // Check if columns exist
    $columnsCheck = $db->query("SHOW COLUMNS FROM sales");
    $columns = $columnsCheck->fetchAll(PDO::FETCH_COLUMN);
    debugLog("Available columns in sales table: " . implode(", ", $columns));
    
    $db->beginTransaction();

    // Calculate totals
    $items = [];
    $linkedBalanceIds = [];

    // Validate stock and prepare items
    foreach ($data['items'] as $index => $item) {
        debugLog("Processing item $index: " . print_r($item, true));
        $productId = (int) ($item['product_id'] ?? 0);
        $isBalanceItem = !empty($item['is_balance_item']) || $productId <= 0;
        $productName = trim((string) ($item['product_name'] ?? ''));
        $productDetails = null;
        $priceMode = saleDiscountNormalizeMode($item['price_mode'] ?? 'retail');

        if (!$isBalanceItem) {
            $productDetails = $product->getById($productId);
            
            if (!$productDetails) {
                throw new Exception("Product ID {$item['product_id']} not found");
            }
            debugLog("Product details: " . print_r($productDetails, true));

            if ($productDetails['quantity'] < $item['quantity']) {
                throw new Exception("Insufficient stock for {$productDetails['name']}. Available: {$productDetails['quantity']}");
            }
        } elseif ($productName === '') {
            throw new Exception('Invalid balance item');
        }

        $itemPrice = isset($item['price']) ? floatval($item['price']) : floatval($productDetails['price'] ?? 0);
        $itemSubtotal = $itemPrice * intval($item['quantity']);

        $items[] = [
            'product_id' => $productId,
            'product_name' => $isBalanceItem ? $productName : $productDetails['name'],
            'quantity' => intval($item['quantity']),
            'price_per_unit' => $itemPrice,
            'subtotal' => $itemSubtotal,
            'price_mode' => $priceMode,
            'is_balance_item' => $isBalanceItem,
            'source_balance_id' => isset($item['source_balance_id']) ? (int) $item['source_balance_id'] : null
        ];

        if ($isBalanceItem && !empty($item['source_balance_id'])) {
            $linkedBalanceIds[] = (int) $item['source_balance_id'];
        }
    }

    $totals = saleDiscountComputeTotals(
        $items,
        $data['discount_percent'] ?? null,
        $data['discount_amount'] ?? 0
    );
    $subtotal = $totals['subtotal'];
    $discountPercent = $totals['discount_percent'];
    $discountAmount = $totals['discount_amount'];

    debugLog("Subtotal calculated: $subtotal");

    // No tax
    $tax_rate = 0.00;
    $tax_amount = 0.00;
    $total_amount = $totals['total_amount'];

    debugLog("Tax: $tax_amount, Discount: $discountAmount, Total: $total_amount");

    // Validate cash payment if payment method is cash
    $payment_method = $data['payment_method'] ?? 'cash';
    $cash_amount = isset($data['cash_amount']) ? floatval($data['cash_amount']) : null;
    $change_amount = isset($data['change_amount']) ? floatval($data['change_amount']) : null;
    
    debugLog("Payment method: $payment_method, Cash: $cash_amount, Change: $change_amount");
    
    if ($payment_method === 'cash') {
        if ($cash_amount === null) {
            throw new Exception('Cash amount is required for cash payments');
        }
        
        if ($cash_amount < $total_amount) {
            throw new Exception("Insufficient cash amount. Need: $total_amount, Given: $cash_amount");
        }
    }

    // Prepare sale data for Sale class
    $saleData = [
        'invoice_number' => generateInvoiceNumber(),
        'user_id' => $_SESSION['user_id'],
        'customer_name' => $data['customer_name'] ?? null,
        'customer_email' => $data['customer_email'] ?? null,
        'customer_phone' => $data['customer_phone'] ?? null,
        'teller_name' => $tellerName,
        'subtotal' => $subtotal,
        'tax_amount' => $tax_amount,
        'total_amount' => $total_amount,
        'discount_amount' => $discountAmount,
        'discount_percent' => $discountPercent,
        'payment_method' => $payment_method,
        'payment_status' => 'paid',
        'cash_amount' => $cash_amount,
        'change_amount' => $change_amount,
        'notes' => $data['notes'] ?? null,
        'items' => $items
    ];

    debugLog("Sale data prepared: " . print_r($saleData, true));

    // Create sale
    debugLog("Attempting to create sale...");
    $saleId = $sale->create($saleData);
    debugLog("Sale creation result: " . ($saleId ? "Success (ID: $saleId)" : "Failed"));

    if (!$saleId) {
        throw new Exception('Failed to create sale record');
    }

    // Update product quantities and log history
    foreach ($items as $item) {
        if (!empty($item['is_balance_item']) || (int) $item['product_id'] <= 0) {
            continue;
        }
        $quantity = (int) $item['quantity'];
        debugLog("Deducting {$quantity} units from product {$item['product_id']}");

        $updateResult = $product->adjustStock(
            $item['product_id'],
            -$quantity,
            'stock_out', 
            'sale', 
            $saleId, 
            "Sale #{$saleData['invoice_number']}"
        );

        if (!$updateResult) {
            throw new Exception("Insufficient stock or failed stock update for product ID {$item['product_id']}");
        }
    }

    $settledBalances = settleLinkedBalances(
        $db,
        $linkedBalanceIds,
        isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        'sale #' . $saleData['invoice_number']
    );

    // Ensure stock deduction and product history for settled linked balances
    // Some workflows settle balances without having adjusted stock (e.g., when approval rows exist
    // but stock movement wasn't applied). Apply stock update and history here if missing.
    if (!empty($settledBalances)) {
        foreach ($settledBalances as $sb) {
            $linkedSaleId = (int) ($sb['sale_id'] ?? 0);
            $items = is_array($sb['items']) ? $sb['items'] : [];
            foreach ($items as $it) {
                $pid = isset($it['product_id']) ? (int)$it['product_id'] : 0;
                $qty = isset($it['quantity']) ? (int)$it['quantity'] : 0;
                if ($pid <= 0 || $qty === 0) continue;

                // Check if there's already a product_history entry for this sale & product
                $chk = $db->prepare("SELECT COUNT(*) FROM product_history WHERE reference_type = 'sale' AND reference_id = :sale_id AND product_id = :product_id AND action_type = 'stock_out'");
                $chk->execute([':sale_id' => $linkedSaleId, ':product_id' => $pid]);
                $exists = (int) $chk->fetchColumn();
                if ($exists > 0) continue; // already recorded

                if (!$product->adjustStock(
                    $pid,
                    -$qty,
                    'stock_out',
                    'sale',
                    $linkedSaleId,
                    'Settled balance - ' . ($sb['invoice_number'] ?? null)
                )) {
                    throw new Exception("Insufficient stock while settling balance sale #{$linkedSaleId}");
                }
            }
        }
    }

    // Commit transaction
    $db->commit();
    debugLog("Transaction committed successfully");

    // Prepare response data with cash payment details
    $response['success'] = true;
    $response['message'] = 'Sale completed successfully';
    $response['data'] = [
        'sale' => [
            'id' => $saleId,
            'invoice_number' => $saleData['invoice_number'],
            'total_amount' => $total_amount,
            'discount_amount' => $discountAmount,
            'discount_percent' => $discountPercent,
            'payment_method' => $payment_method,
            'cash_amount' => $cash_amount,
            'change_amount' => $change_amount,
            'customer_name' => $saleData['customer_name'],
            'created_at' => date('Y-m-d H:i:s')
        ],
        'items' => $items,
        'settled_balances' => $settledBalances,
        'summary' => [
            'subtotal' => $subtotal,
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountAmount,
            'tax' => $tax_amount,
            'total' => $total_amount
        ]
    ];

    debugLog("Sale completed successfully: " . json_encode($response));

} catch (Exception $e) {
    // Rollback transaction if started
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
        debugLog("Transaction rolled back");
    }
    
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    debugLog("ERROR: " . $e->getMessage());
    debugLog("Stack trace: " . $e->getTraceAsString());
}

// Return JSON response
echo json_encode($response);
debugLog("Response sent: " . json_encode($response));
debugLog("=== Sale request ended ===\n");
exit();
