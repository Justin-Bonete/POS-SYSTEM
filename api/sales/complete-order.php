<?php
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
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../classes/ProductHistory.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null,
];

$logFile = __DIR__ . '/../../logs/complete_order.log';
function coLog($msg) {
    global $logFile;
    $ts = date('Y-m-d H:i:s');
    if (!file_exists(dirname($logFile))) {
        @mkdir(dirname($logFile), 0777, true);
    }
    @file_put_contents($logFile, "[$ts] $msg\n", FILE_APPEND);
}

function coEnsureSaleItemsAllowsBalanceRows(PDO $db) {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM sale_items LIKE 'product_id'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($column && strtoupper((string) ($column['Null'] ?? 'NO')) !== 'YES') {
            $db->exec("ALTER TABLE sale_items MODIFY product_id INT NULL");
        }
    } catch (Throwable $e) {
        coLog("Schema adjust warning: " . $e->getMessage());
    }
}

function coEnsureSalesDiscountColumn(PDO $db): void
{
    saleDiscountEnsureSalesColumn($db);
}

function coSettleLinkedBalances(PDO $db, array $sourceBalanceIds, string $reference): array {
    $settled = [];
    $seen = [];

    $selectApproval = $db->prepare("
        SELECT sa.*
        FROM sale_approvals sa
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

try {
    if (!isset($_SESSION['user_id'])) {
        coLog("User not authenticated");
        throw new Exception('User not authenticated');
    }

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    coLog("Complete order start user=" . ($_SESSION['user_id'] ?? '') . " order_id=" . (isset($data['order_id']) ? $data['order_id'] : ''));

    if (!is_array($data)) {
        throw new Exception('Invalid JSON data');
    }

    $orderId = (int) ($data['order_id'] ?? 0);
    if ($orderId <= 0) {
        throw new Exception('Invalid order ID');
    }

    $paymentMethod = (string) ($data['payment_method'] ?? 'cash');
    $cashAmount = isset($data['cash_amount']) ? (float) $data['cash_amount'] : null;
    $changeAmount = isset($data['change_amount']) ? (float) $data['change_amount'] : null;
    $tellerName = isset($data['teller_name']) ? trim((string) $data['teller_name']) : '';
    if ($tellerName === '') {
        throw new Exception('Salesman name is required');
    }

    $db = ConfigDatabase::getInstance()->getConnection();
    coEnsureSaleItemsAllowsBalanceRows($db);
    coEnsureSalesDiscountColumn($db);

    // Ensure approval table exists (used when cash is short)
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
    $db->beginTransaction();
    coLog("Begin transaction order_id=$orderId");

    $saleStmt = $db->prepare("
        SELECT *
        FROM sales
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $saleStmt->execute([$orderId]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

    if (!$sale) {
        throw new Exception('Pending order not found');
    }

    $currentStatus = $sale['payment_status'] ?? '';

    // If already paid, just return sale/items so frontend can show/print
    if ($currentStatus === 'paid') {
        $itemsStmt = $db->prepare("
            SELECT si.*
            FROM sale_items si
            WHERE si.sale_id = ?
            ORDER BY si.id ASC
        ");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $db->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Sale already paid',
            'data' => [
                'sale' => $sale,
                'items' => $items,
                'status' => 'already_paid'
            ]
        ]);
        exit;
    }

    // Only allow pending-like statuses to proceed
    if ($currentStatus !== 'pending') {
        throw new Exception('Order is not pending (current status: ' . $currentStatus . ')');
    }

    $items = [];
    $subtotalAmount = 0;
    $discountPercent = 0;
    $discountAmount = 0;
    $totalAmount = 0;
    $linkedBalanceIds = [];

    if (!empty($data['items']) && is_array($data['items'])) {
        foreach ($data['items'] as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            $unitPrice = isset($item['price']) ? (float) $item['price'] : 0;
            $productName = trim((string) ($item['product_name'] ?? ''));
            $isBalanceItem = !empty($item['is_balance_item']) || $productId <= 0;
            $priceMode = saleDiscountNormalizeMode($item['price_mode'] ?? 'retail');

            if ($quantity <= 0) {
                throw new Exception('Invalid order item');
            }

            if ($isBalanceItem) {
                if ($productName === '' || $unitPrice <= 0) {
                    throw new Exception('Invalid balance item');
                }
            } else {
                // Lock product row using the same transaction to avoid cross-connection locks
                $prodStmt = $db->prepare("SELECT * FROM products WHERE id = ? FOR UPDATE");
                $prodStmt->execute([$productId]);
                $productDetails = $prodStmt->fetch(PDO::FETCH_ASSOC);
                if (!$productDetails) {
                    throw new Exception("Product ID {$productId} not found");
                }

                if ($unitPrice <= 0) {
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
                'retail_price' => isset($item['retail_price']) ? (float) $item['retail_price'] : null,
                'wholesale_price' => isset($item['wholesale_price']) ? (float) $item['wholesale_price'] : null,
                'cost_price' => isset($item['cost_price']) ? (float) $item['cost_price'] : null,
                'is_balance_item' => $isBalanceItem,
                'source_balance_id' => isset($item['source_balance_id']) ? (int) $item['source_balance_id'] : null,
            ];

            if ($isBalanceItem && !empty($item['source_balance_id'])) {
                $linkedBalanceIds[] = (int) $item['source_balance_id'];
            }
        }

        $totals = saleDiscountComputeTotals(
            $items,
            $data['discount_percent'] ?? ($sale['discount_percent'] ?? null),
            $data['discount_amount'] ?? ($sale['discount_amount'] ?? 0)
        );
        $subtotalAmount = $totals['subtotal'];
        $discountPercent = $totals['discount_percent'];
        $discountAmount = $totals['discount_amount'];
        $totalAmount = $totals['total_amount'];
        $totalAmount = $totals['total_amount'];

        // Compute reserved map (sum of pending sale_items) for products involved in this order
        $productIds = array_values(array_filter(array_unique(array_map(function($it){ return isset($it['product_id']) ? (int)$it['product_id'] : 0; }, $items)), function($v){ return $v > 0; }));
        $reservedMap = [];
        if (!empty($productIds)) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $reservedSql = "SELECT si.product_id, COALESCE(SUM(si.quantity),0) AS reserved FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.payment_status = 'pending' AND si.product_id IN ($placeholders) GROUP BY si.product_id";
            $rs = $db->prepare($reservedSql);
            $rs->execute($productIds);
            while ($r = $rs->fetch(PDO::FETCH_ASSOC)) {
                $reservedMap[(int)$r['product_id']] = (int)$r['reserved'];
            }
        }

        $deleteItemsStmt = $db->prepare("DELETE FROM sale_items WHERE sale_id = ?");
        $deleteItemsStmt->execute([$orderId]);

        $insertItemStmt = $db->prepare("
            INSERT INTO sale_items (sale_id, product_id, product_name, quantity, price_per_unit, subtotal)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        foreach ($items as $item) {
            $insertItemStmt->execute([
                $orderId,
                ((int) $item['product_id'] > 0 ? (int) $item['product_id'] : null),
                $item['product_name'],
                $item['quantity'],
                $item['price_per_unit'],
                $item['subtotal'],
            ]);
        }
    } else {
        $itemsStmt = $db->prepare("
            SELECT si.*
            FROM sale_items si
            WHERE si.sale_id = ?
            ORDER BY si.id ASC
        ");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($items)) {
            throw new Exception('Pending order has no items');
        }

        // Lock all product rows referenced to prevent concurrent edits
        $lockStmt = $db->prepare("SELECT id FROM products WHERE id = ? FOR UPDATE");
        foreach ($items as $item) {
            if ((int) ($item['product_id'] ?? 0) <= 0) {
                continue;
            }
            $lockStmt->execute([(int)$item['product_id']]);
        }

        foreach ($items as $item) {
            $subtotalAmount += (float) ($item['subtotal'] ?? 0);
            if ((int) ($item['product_id'] ?? 0) <= 0 && !empty($item['source_balance_id'])) {
                $linkedBalanceIds[] = (int) $item['source_balance_id'];
            }
        }

        $discountPercent = saleDiscountNormalizePercent($sale['discount_percent'] ?? 0);
        $discountAmount = max(0.0, saleDiscountToFloat($sale['discount_amount'] ?? 0));
        $discountAmount = min($discountAmount, round($subtotalAmount, 2));
        $subtotalAmount = round($subtotalAmount, 2);
        $totalAmount = round(max($subtotalAmount - $discountAmount, 0), 2);
    }

    if ($paymentMethod === 'cash') {
        if ($cashAmount === null) {
            throw new Exception('Cash amount is required for cash payments');
        }
        if ($cashAmount < $totalAmount) {
            $short = $totalAmount - $cashAmount;
            $tellerName = trim($tellerName);

            $stmt = $db->prepare("INSERT INTO sale_approvals (sale_id, status, requested_by, total_amount, cash_amount, short_amount, note) VALUES (?, 'pending', ?, ?, ?, ?, ?)");
            $approvalParams = [
                $orderId,
                (int) $_SESSION['user_id'],
                $totalAmount,
                $cashAmount,
                $short,
                $data['notes'] ?? $sale['notes'] ?? null
            ];
            coLog('EXEC prepare: INSERT INTO sale_approvals params=' . json_encode($approvalParams));
            $stmt->execute($approvalParams);

            // Keep sale pending but record cash tendered
            $updatePending = $db->prepare("
                UPDATE sales
                   SET total_amount = ?,
                       discount_amount = ?,
                       discount_percent = ?,
                       customer_name = ?,
                       customer_phone = ?,
                       teller_name = ?,
                       cash_amount = ?,
                       change_amount = 0,
                       notes = ?,
                       payment_method = ?,
                       payment_status = 'pending'
                 WHERE id = ?
            ");
            $updatePending->execute([
                $totalAmount,
                $discountAmount,
                $discountPercent,
                $data['customer_name'] ?? $sale['customer_name'] ?? null,
                $data['customer_phone'] ?? $sale['customer_phone'] ?? null,
                ($tellerName !== '' ? $tellerName : null),
                $cashAmount,
                $data['notes'] ?? $sale['notes'] ?? null,
                $paymentMethod,
                $orderId
            ]);
            coLog('EXEC updatePending params=' . json_encode([
                'totalAmount' => $totalAmount,
                'discountAmount' => $discountAmount,
                'discountPercent' => $discountPercent,
                'customer_name' => $data['customer_name'] ?? $sale['customer_name'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? $sale['customer_phone'] ?? null,
                'teller_name' => ($tellerName !== '' ? $tellerName : null),
                'cashAmount' => $cashAmount,
                'notes' => $data['notes'] ?? $sale['notes'] ?? null,
                'paymentMethod' => $paymentMethod,
                'orderId' => $orderId
            ]));

                    if ($db->inTransaction()) {
                $db->commit();
                coLog("Short cash approval order_id=$orderId short=$short commit=yes");
            } else {
                coLog("Short cash approval order_id=$orderId short=$short commit=no active transaction");
            }
            echo json_encode([
                'success' => true,
                'message' => 'Sent to admin for approval (short cash).',
                'data' => [
                    'sale' => array_merge($sale, [
                        'customer_name' => $data['customer_name'] ?? $sale['customer_name'] ?? null,
                        'customer_phone' => $data['customer_phone'] ?? $sale['customer_phone'] ?? null,
                        'teller_name' => ($tellerName !== '' ? $tellerName : null),
                        'total_amount' => $totalAmount,
                        'discount_amount' => $discountAmount,
                        'discount_percent' => $discountPercent,
                        'payment_method' => $paymentMethod,
                        'cash_amount' => $cashAmount,
                        'change_amount' => 0,
                        'notes' => $data['notes'] ?? $sale['notes'] ?? null,
                        'payment_status' => 'pending',
                    ]),
                    'items' => $items,
                    'status' => 'needs_approval'
                ]
            ]);
            exit;
        }
    } else {
        $cashAmount = null;
        $changeAmount = null;
    }

    $tellerName = trim($tellerName);

    $updateStmt = $db->prepare("
        UPDATE sales
        SET total_amount = ?,
            discount_amount = ?,
            discount_percent = ?,
            customer_name = ?,
            customer_phone = ?,
            teller_name = ?,
            payment_method = ?,
            payment_status = 'paid',
            cash_amount = ?,
            change_amount = ?,
            notes = ?
        WHERE id = ?
    ");
    $updateStmt->execute([
        $totalAmount,
        $discountAmount,
        $discountPercent,
        $data['customer_name'] ?? $sale['customer_name'] ?? null,
        $data['customer_phone'] ?? $sale['customer_phone'] ?? null,
        ($tellerName !== '' ? $tellerName : null),
        $paymentMethod,
        $cashAmount,
        $changeAmount,
        $data['notes'] ?? $sale['notes'] ?? null,
        $orderId,
    ]);

    // Ensure we have a reserved map (in case items were loaded from DB path)
    if (!isset($reservedMap) || !is_array($reservedMap)) {
        $productIds = array_values(array_filter(array_unique(array_map(function($it){ return isset($it['product_id']) ? (int)$it['product_id'] : 0; }, $items)), function($v){ return $v > 0; }));
        $reservedMap = [];
        if (!empty($productIds)) {
            $placeholders = implode(',', array_fill(0, count($productIds), '?'));
            $reservedSql = "SELECT si.product_id, COALESCE(SUM(si.quantity),0) AS reserved FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.payment_status = 'pending' AND si.product_id IN ($placeholders) GROUP BY si.product_id";
            $rs = $db->prepare($reservedSql);
            $rs->execute($productIds);
            while ($r = $rs->fetch(PDO::FETCH_ASSOC)) {
                $reservedMap[(int)$r['product_id']] = (int)$r['reserved'];
            }
        }
    }

    $product = new Product();
    $productHistory = new ProductHistory();

    foreach ($items as $item) {
        if (!empty($item['is_balance_item']) || (int) $item['product_id'] <= 0) {
            continue;
        }
        $pid = (int)$item['product_id'];
        $orderedQty = (int)$item['quantity'];
        $reservedQty = isset($reservedMap[$pid]) ? (int)$reservedMap[$pid] : 0;
        $decrement = min($orderedQty, $reservedQty > 0 ? $reservedQty : $orderedQty);
        if ($decrement <= 0) {
            $decrement = $orderedQty;
        }
        if ($decrement <= 0) {
            continue;
        }

        if (!$product->adjustStock(
            $pid,
            -$decrement,
            'stock_out',
            'sale',
            $orderId,
            "Order completed #{$orderId}"
        )) {
            throw new Exception("Insufficient stock or failed stock update for product ID {$pid} while completing order");
        }
    }

    $settledBalances = coSettleLinkedBalances(
        $db,
        $linkedBalanceIds,
        'sale #' . ($sale['invoice_number'] ?? $orderId)
    );

    if ($db->inTransaction()) {
        try {
            coLog('About to commit; txnActive=' . ($db->inTransaction() ? 'yes' : 'no'));
            $db->commit();
            coLog("Commit success order_id=$orderId");
        } catch (Throwable $e) {
            // If commit fails, attempt rollback and surface a clearer message
            if ($db->inTransaction()) {
                coLog('Commit failed, rolling back');
                $db->rollBack();
            }
            throw new Exception('Commit failed: ' . $e->getMessage());
        }
    } else {
        coLog('Final commit skipped because no active transaction order_id=' . $orderId);
    }

    // Recompute current reserved counts for products affected by this order
    $currentReservedMap = [];
    $productIdsForQuery = array_values(array_filter(array_unique(array_map(function($it){ return isset($it['product_id']) ? (int)$it['product_id'] : 0; }, $items)), function($v){ return $v > 0; }));
    if (!empty($productIdsForQuery)) {
        $placeholders = implode(',', array_fill(0, count($productIdsForQuery), '?'));
        $reservedSql = "SELECT si.product_id, COALESCE(SUM(si.quantity),0) AS reserved FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.payment_status = 'pending' AND si.product_id IN ($placeholders) GROUP BY si.product_id";
        $rs2 = $db->prepare($reservedSql);
        $rs2->execute($productIdsForQuery);
        while ($r2 = $rs2->fetch(PDO::FETCH_ASSOC)) {
            $currentReservedMap[(int)$r2['product_id']] = (int)$r2['reserved'];
        }
    }

    $sale['customer_name'] = $data['customer_name'] ?? $sale['customer_name'] ?? null;
    $sale['customer_phone'] = $data['customer_phone'] ?? $sale['customer_phone'] ?? null;
    $sale['teller_name'] = ($tellerName !== '' ? $tellerName : null);
    $sale['total_amount'] = $totalAmount;
    $sale['discount_amount'] = $discountAmount;
    $sale['discount_percent'] = $discountPercent;
    $sale['payment_method'] = $paymentMethod;
    $sale['payment_status'] = 'paid';
    $sale['cash_amount'] = $cashAmount;
    $sale['change_amount'] = $changeAmount;
    $sale['notes'] = $data['notes'] ?? $sale['notes'] ?? null;

    $response['success'] = true;
    $response['message'] = 'Order completed successfully';
    $response['data'] = [
        'sale' => $sale,
        'items' => $items,
        'settled_balances' => $settledBalances,
        'reserved_map' => $currentReservedMap,
    ];
    coLog("Complete order success order_id=$orderId status=" . ($sale['payment_status'] ?? ''));
} catch (Throwable $e) {
    $trace = $e->getTraceAsString();
    $line = $e->getLine();
    coLog("Error order_id=" . ($data['order_id'] ?? 'n/a') . " msg=" . $e->getMessage() . " line=" . $line . " trace=" . str_replace("\n", ' | ', $trace));
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
        coLog('Rolled back transaction order_id=' . ($data['order_id'] ?? 'n/a'));
    }
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
