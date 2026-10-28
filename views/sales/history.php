    <?php
    // views/sales/history.php
    $pageTitle = 'Sales History';
    $pageScripts = ['sales-history.js'];

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    require_once '../../includes/auth.php';
    require_once '../../includes/functions.php';
    require_once '../../config/database.php';
    require_once '../../classes/Sale.php';
    require_once '../../classes/Product.php';
    require_once '../../classes/ProductHistory.php';

    $db = ConfigDatabase::getInstance()->getConnection();
    $sale = new Sale();
    $product = new Product();

    $ensureTellerColumn = function (PDO $db, string $table): void {
        try {
            $stmt = $db->prepare("SHOW COLUMNS FROM {$table} LIKE 'teller_name'");
            $stmt->execute();
            $exists = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$exists) {
                $db->exec("ALTER TABLE {$table} ADD COLUMN teller_name VARCHAR(120) NULL AFTER customer_phone");
            }
        } catch (Exception $e) {
            error_log("Unable to ensure {$table}.teller_name column: " . $e->getMessage());
        }
    };
    $ensureTellerColumn($db, 'sales');
    $ensureTellerColumn($db, 'invoices');

    function isShortCashSale(array $saleData): bool
    {
        $totalAmount = (float) ($saleData['total_amount'] ?? 0);
        $cashAmount = $saleData['cash_amount'];
        if ($cashAmount === null || $cashAmount === '') {
            return false;
        }

        $cashAmount = (float) $cashAmount;
        return ($totalAmount > 0) && ($cashAmount > 0) && ($cashAmount < $totalAmount);
    }

    function getOutstandingBalance(array $saleData): float
    {
        if (!isShortCashSale($saleData)) {
            return 0.0;
        }

        return max((float) ($saleData['total_amount'] ?? 0) - (float) ($saleData['cash_amount'] ?? 0), 0);
    }

    function getNetPaidAmountForRefund(array $saleData): float
    {
        $totalAmount = max((float) ($saleData['total_amount'] ?? 0), 0);
        $cashAmount = $saleData['cash_amount'] ?? null;

        if ($cashAmount === null || $cashAmount === '') {
            return strtolower((string) ($saleData['payment_status'] ?? '')) === 'paid' ? $totalAmount : 0.0;
        }

        $changeAmount = max((float) ($saleData['change_amount'] ?? 0), 0);
        return min(max((float) $cashAmount - $changeAmount, 0), $totalAmount);
    }

    function getPaymentBadgeMeta(array $saleData): array
    {
        if (isShortCashSale($saleData)) {
            if (($saleData['payment_status'] ?? '') === 'pending') {
                return ['label' => 'Balance', 'class' => 'badge-warning'];
            }

            return ['label' => 'Borrowed', 'class' => 'badge-danger'];
        }

        return [
            'label' => ucfirst((string) ($saleData['payment_method'] ?? 'cash')),
            'class' => 'badge-info'
        ];
    }

    function getStatusBadgeMeta(array $saleData): array
    {
        if (isShortCashSale($saleData)) {
            if (($saleData['payment_status'] ?? '') === 'pending') {
                return ['label' => 'Pending Balance', 'class' => 'badge-warning'];
            }

            return ['label' => 'Borrowed', 'class' => 'badge-danger'];
        }

        $status = (string) ($saleData['payment_status'] ?? 'paid');
        $class = 'badge-info';

        if ($status === 'paid') {
            $class = 'badge-success';
        } elseif ($status === 'pending') {
            $class = 'badge-warning';
        } elseif ($status === 'cancelled') {
            $class = 'badge-danger';
        } elseif ($status === 'refunded') {
            $class = 'badge-secondary';
        }

        return ['label' => ucfirst($status), 'class' => $class];
    }

    function isReceiptPrintable(PDO $db, array $saleData): bool
    {
        if (($saleData['source'] ?? '') !== 'sales') {
            return true;
        }

        $paymentStatus = strtolower((string) ($saleData['payment_status'] ?? ''));
        if ($paymentStatus !== 'pending') {
            return true;
        }

        if (!isShortCashSale($saleData)) {
            return true;
        }

        $saleId = (int) ($saleData['id'] ?? 0);
        if ($saleId <= 0) {
            return false;
        }

        $approvalStmt = $db->prepare(
            "SELECT status FROM sale_approvals WHERE sale_id = ? AND status IN ('pending', 'approved') ORDER BY id DESC LIMIT 1"
        );
        $approvalStmt->execute([$saleId]);
        $approvalData = $approvalStmt->fetch(PDO::FETCH_ASSOC);
        $approvalStatus = strtolower((string) ($approvalData['status'] ?? ''));

        return $approvalStatus === 'approved';
    }

    function getSalesHistoryRowKey(array $row): string
    {
        $invoiceNumber = trim((string) ($row['invoice_number'] ?? ''));
        if ($invoiceNumber !== '') {
            return $invoiceNumber;
        }

        return trim((string) ($row['source'] ?? 'unknown')) . '-' . ((int) ($row['id'] ?? 0));
    }

    function getSalesHistoryRowPriority(array $row): int
    {
        $status = strtolower(trim((string) ($row['payment_status'] ?? '')));
        $statusPriority = 0;

        switch ($status) {
            case 'refunded':
                $statusPriority = 50;
                break;
            case 'paid':
                $statusPriority = 40;
                break;
            case 'pending':
                $statusPriority = 30;
                break;
            case 'partial':
                $statusPriority = 20;
                break;
            case 'cancelled':
                $statusPriority = 10;
                break;
            default:
                $statusPriority = 0;
                break;
        }

        $sourcePriority = ($row['source'] === 'invoices') ? 2 : 1;
        return ($statusPriority * 10) + $sourcePriority;
    }

    function dedupeSalesHistoryRows(array $rows): array
    {
        $unique = [];

        foreach ($rows as $row) {
            $key = getSalesHistoryRowKey($row);

            if (!isset($unique[$key])) {
                $unique[$key] = $row;
                continue;
            }

            $existing = $unique[$key];
            $existingScore = getSalesHistoryRowPriority($existing);
            $currentScore = getSalesHistoryRowPriority($row);

            if ($currentScore > $existingScore
                || ($currentScore === $existingScore && strtotime($row['created_at']) >= strtotime($existing['created_at']))) {
                $unique[$key] = $row;
            }
        }

        return array_values($unique);
    }

    // Handle AJAX requests for refund processing
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        
        $action = $_POST['action'] ?? '';
        
        if ($action === 'get_sale_details') {
            $saleId = isset($_POST['sale_id']) ? (int)$_POST['sale_id'] : 0;
            $sourceHint = $_POST['source'] ?? '';
            $sourceHint = in_array($sourceHint, ['sales', 'invoices'], true) ? $sourceHint : '';
            
            try {
                $saleData = null;
                $source = '';

                if ($sourceHint === 'sales') {
                    $stmt = $db->prepare("
                        SELECT s.*, COALESCE(u.username, s.created_by, 'System') as cashier_name 
                        FROM sales s
                        LEFT JOIN users u ON s.user_id = u.id
                        WHERE s.id = ?
                    ");
                    $stmt->execute([$saleId]);
                    $saleData = $stmt->fetch(PDO::FETCH_ASSOC);
                    $source = 'sales';
                } elseif ($sourceHint === 'invoices') {
                    $stmt = $db->prepare("
                        SELECT i.*, COALESCE(u.username, i.created_by, 'System') as cashier_name 
                        FROM invoices i
                        LEFT JOIN users u ON i.user_id = u.id
                        WHERE i.id = ?
                    ");
                    $stmt->execute([$saleId]);
                    $saleData = $stmt->fetch(PDO::FETCH_ASSOC);
                    $source = 'invoices';
                }

                if (!$saleData) {
                    $stmt = $db->prepare("
                        SELECT s.*, COALESCE(u.username, s.created_by, 'System') as cashier_name 
                        FROM sales s
                        LEFT JOIN users u ON s.user_id = u.id
                        WHERE s.id = ?
                    ");
                    $stmt->execute([$saleId]);
                    $saleData = $stmt->fetch(PDO::FETCH_ASSOC);
                    $source = 'sales';
                }
                if (!$saleData) {
                    $stmt = $db->prepare("
                        SELECT i.*, COALESCE(u.username, i.created_by, 'System') as cashier_name 
                        FROM invoices i
                        LEFT JOIN users u ON i.user_id = u.id
                        WHERE i.id = ?
                    ");
                    $stmt->execute([$saleId]);
                    $saleData = $stmt->fetch(PDO::FETCH_ASSOC);
                    $source = 'invoices';
                }
                
                if (!$saleData) {
                    echo json_encode(['success' => false, 'message' => 'Sale not found']);
                    exit;
                }
                
                // Get items
                if ($source === 'sales') {
                    $stmt = $db->prepare("
                        SELECT si.*, p.name as product_name 
                        FROM sale_items si
                        LEFT JOIN products p ON si.product_id = p.id
                        WHERE si.sale_id = ?
                    ");
                    $stmt->execute([$saleId]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $stmt = $db->prepare("
                        SELECT ii.*, p.name as product_name 
                        FROM invoice_items ii
                        LEFT JOIN products p ON ii.product_id = p.id
                        WHERE ii.invoice_id = ?
                    ");
                    $stmt->execute([$saleId]);
                    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'sale' => $saleData,
                        'items' => $items,
                        'source' => $source
                    ]
                ]);
                
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }
        
        if ($action === 'process_refund') {
            $refundData = json_decode($_POST['data'], true);
            
            if (!$refundData || empty($refundData['sale_id']) || empty($refundData['items'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid refund data']);
                exit;
            }
            
            try {
                $db->beginTransaction();
                
                $refundNumber = 'REF-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $currentTime = date('Y-m-d H:i:s');
                $userId = $_SESSION['user_id'] ?? 0;
                $refundType = $refundData['refund_type'] ?? 'cash';
                if (!in_array($refundType, ['cash', 'exchange'], true)) {
                    throw new Exception('Only cash refund and exchange are allowed');
                }
                
                // Get sale details (respect source hint to avoid id collisions)
                $source = $refundData['source'] ?? '';
                $source = in_array($source, ['sales', 'invoices'], true) ? $source : '';
                $saleData = null;
                if ($source === 'sales') {
                    $stmt = $db->prepare("SELECT * FROM sales WHERE id = ?");
                    $stmt->execute([$refundData['sale_id']]);
                    $saleData = $stmt->fetch(PDO::FETCH_ASSOC);
                } elseif ($source === 'invoices') {
                    $stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
                    $stmt->execute([$refundData['sale_id']]);
                    $saleData = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                if (!$saleData) {
                    $stmt = $db->prepare("SELECT * FROM sales WHERE id = ?");
                    $stmt->execute([$refundData['sale_id']]);
                    $saleData = $stmt->fetch(PDO::FETCH_ASSOC);
                    $source = 'sales';
                }
                if (!$saleData) {
                    $stmt = $db->prepare("SELECT * FROM invoices WHERE id = ?");
                    $stmt->execute([$refundData['sale_id']]);
                    $saleData = $stmt->fetch(PDO::FETCH_ASSOC);
                    $source = 'invoices';
                }
                
                if (!$saleData) {
                    throw new Exception('Sale not found');
                }

                $requestedRefundValue = 0.0;
                foreach ($refundData['items'] as $refundItem) {
                    $requestedRefundValue += max(0, (int) ($refundItem['quantity'] ?? 0)) * max(0, (float) ($refundItem['unit_price'] ?? 0));
                }
                $requestedRefundValue = round($requestedRefundValue, 2);

                if ($requestedRefundValue <= 0) {
                    throw new Exception('Refund amount must be greater than zero');
                }

                $remainingValueSql = $source === 'invoices'
                    ? "SELECT COALESCE(SUM(quantity * unit_price), 0) FROM invoice_items WHERE invoice_id = ?"
                    : "SELECT COALESCE(SUM(quantity * price_per_unit), 0) FROM sale_items WHERE sale_id = ?";
                $remainingValueStmt = $db->prepare($remainingValueSql);
                $remainingValueStmt->execute([(int) $refundData['sale_id']]);
                $remainingValueBeforeRefund = round((float) $remainingValueStmt->fetchColumn(), 2);
                if ($remainingValueBeforeRefund <= 0) {
                    $remainingValueBeforeRefund = max((float) ($saleData['total_amount'] ?? 0), 0);
                }

                $paidBeforeRefund = getNetPaidAmountForRefund($saleData);
                $remainingValueAfterRefund = round(max($remainingValueBeforeRefund - $requestedRefundValue, 0), 2);
                $cashRefundAmount = round(min($requestedRefundValue, max($paidBeforeRefund - $remainingValueAfterRefund, 0)), 2);
                $balanceCancelledAmount = round(max($requestedRefundValue - $cashRefundAmount, 0), 2);
                $paidAfterRefund = round(max($paidBeforeRefund - $cashRefundAmount, 0), 2);
                $outstandingAfterRefund = round(max($remainingValueAfterRefund - $paidAfterRefund, 0), 2);
                $refundRecordAmount = $refundType === 'cash' ? $cashRefundAmount : min($requestedRefundValue, $cashRefundAmount);
                
                // Detect the parent returns table referenced by return_items.return_id
                $returnsTable = 'returns';
                $fkStmt = $db->prepare("
                    SELECT REFERENCED_TABLE_NAME
                    FROM information_schema.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'return_items'
                    AND COLUMN_NAME = 'return_id'
                    AND REFERENCED_TABLE_NAME IS NOT NULL
                    LIMIT 1
                ");
                $fkStmt->execute();
                $referencedTable = $fkStmt->fetchColumn();
                if (in_array($referencedTable, ['returns', 'invoice_returns'], true)) {
                    $returnsTable = $referencedTable;
                }

                // Create return record (schema-aware)
                if ($returnsTable === 'invoice_returns') {
                    $invoiceReturnId = 0;
                    if ($source === 'invoices') {
                        $mapStmt = $db->prepare("SELECT id FROM invoices WHERE id = ? LIMIT 1");
                        $mapStmt->execute([(int)$refundData['sale_id']]);
                        $invoiceReturnId = (int)($mapStmt->fetchColumn() ?: 0);
                    } else {
                        $invoiceNumber = trim((string)($saleData['invoice_number'] ?? ''));
                        $normalizedInvoiceNumber = ltrim($invoiceNumber, '#');
                        $mapStmt = $db->prepare("
                            SELECT id
                            FROM invoices
                            WHERE invoice_number IN (?, ?, ?)
                            OR REPLACE(invoice_number, '#', '') IN (?, ?)
                            ORDER BY id DESC
                            LIMIT 1
                        ");
                        $mapStmt->execute([
                            $invoiceNumber,
                            $normalizedInvoiceNumber,
                            ('#' . $normalizedInvoiceNumber),
                            $normalizedInvoiceNumber,
                            $invoiceNumber
                        ]);
                        $invoiceReturnId = (int)($mapStmt->fetchColumn() ?: 0);

                        if ($invoiceReturnId <= 0) {
                            $paymentMethod = (string)($saleData['payment_method'] ?? 'cash');
                            if (!in_array($paymentMethod, ['cash', 'card', 'online', 'bank_transfer', 'gcash', 'others'], true)) {
                                $paymentMethod = 'cash';
                            }

                            $paymentStatus = (string)($saleData['payment_status'] ?? 'paid');
                            if (!in_array($paymentStatus, ['paid', 'pending', 'partial', 'cancelled', 'refunded'], true)) {
                                $paymentStatus = 'paid';
                            }

                            if ($invoiceNumber === '') {
                                $invoiceNumber = 'INV-SALES-' . (int)$refundData['sale_id'] . '-' . date('YmdHis');
                            }

                            $invoiceTotal = (float)($saleData['total_amount'] ?? 0);
                            $createdBy = isset($saleData['user_id']) ? (int)$saleData['user_id'] : ($userId ?: null);
                            $createdAt = $saleData['created_at'] ?? $currentTime;

                            $createInvoiceStmt = $db->prepare("
                                INSERT INTO invoices (
                                    invoice_number, total_amount, subtotal, tax_amount, discount_amount,
                                    payment_method, payment_status, cash_amount, change_amount,
                                    customer_name, customer_email, customer_phone, teller_name, notes,
                                    created_at, created_by, user_id
                                ) VALUES (
                                    ?, ?, ?, 0, 0,
                                    ?, ?, ?, ?,
                                    ?, ?, ?, ?, ?,
                                    ?, ?, ?
                                )
                            ");
                            $createInvoiceStmt->execute([
                                $invoiceNumber,
                                $invoiceTotal,
                                $invoiceTotal,
                                $paymentMethod,
                                $paymentStatus,
                                $saleData['cash_amount'] ?? null,
                                $saleData['change_amount'] ?? null,
                                $saleData['customer_name'] ?? null,
                                $saleData['customer_email'] ?? null,
                                $saleData['customer_phone'] ?? null,
                                $saleData['teller_name'] ?? null,
                                $saleData['notes'] ?? null,
                                $createdAt,
                                $createdBy,
                                $createdBy
                            ]);

                            $invoiceReturnId = (int)$db->lastInsertId();
                        }
                    }

                    if ($invoiceReturnId <= 0) {
                        throw new Exception('Cannot process refund: matching invoice record not found for this sale');
                    }

                    $stmt = $db->prepare("INSERT INTO invoice_returns (
                        invoice_id, return_number, total_return_amount, reason,
                        return_type, created_at, created_by, status
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?
                    )");

                    $stmt->execute([
                        $invoiceReturnId,
                        $refundNumber,
                        $refundRecordAmount,
                        $refundData['reason'] ?? null,
                        'partial',
                        $currentTime,
                        $userId,
                        'completed'
                    ]);
                } else {
                    $stmt = $db->prepare("INSERT INTO returns (
                        return_number, original_invoice_id, customer_name, 
                        customer_phone, customer_email, total_refund_amount, refund_type, 
                        has_receipt, return_date, processed_by, status, notes
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )");
                    
                    $stmt->execute([
                        $refundNumber,
                        $refundData['sale_id'],
                        $saleData['customer_name'],
                        $saleData['customer_phone'],
                        $saleData['customer_email'],
                        $refundRecordAmount,
                        $refundType,
                        1,
                        $currentTime,
                        $userId,
                        'completed',
                        $refundData['reason'] ?? null
                    ]);
                }
                
                $returnId = $db->lastInsertId();
                
                // Check if return_items has invoice_item_id (required in some schemas)
                $hasInvoiceItemId = false;
                $colStmt = $db->prepare("
                    SELECT COUNT(*)
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'return_items'
                    AND COLUMN_NAME = 'invoice_item_id'
                ");
                $colStmt->execute();
                $hasInvoiceItemId = ((int)$colStmt->fetchColumn() > 0);

                // Process each refund item
                foreach ($refundData['items'] as $item) {
                    $requestedQty = (int)($item['quantity'] ?? 0);
                    if ($requestedQty <= 0) {
                        throw new Exception('Refund quantity must be at least 1');
                    }

                    $unitPrice = (float)($item['unit_price'] ?? 0);
                    if ($unitPrice < 0) {
                        throw new Exception('Invalid unit price for refund item');
                    }
                    $refundAmount = $requestedQty * $unitPrice;

                    $requestedItemId = (int)($item['item_id'] ?? 0);
                    $itemRefId = 0;
                    $availableQty = 0;
                    if ($source === 'invoices') {
                        if ($requestedItemId > 0) {
                            $itemRefStmt = $db->prepare("SELECT id, quantity FROM invoice_items WHERE invoice_id = ? AND id = ? AND product_id = ? LIMIT 1");
                            $itemRefStmt->execute([$refundData['sale_id'], $requestedItemId, $item['product_id']]);
                        } else {
                            $itemRefStmt = $db->prepare("SELECT id, quantity FROM invoice_items WHERE invoice_id = ? AND product_id = ? AND quantity >= ? ORDER BY id DESC LIMIT 1");
                            $itemRefStmt->execute([$refundData['sale_id'], $item['product_id'], $requestedQty]);
                        }
                        $itemRefRow = $itemRefStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                        $itemRefId = (int)($itemRefRow['id'] ?? 0);
                        $availableQty = (int)($itemRefRow['quantity'] ?? 0);
                    } else {
                        if ($requestedItemId > 0) {
                            $itemRefStmt = $db->prepare("SELECT id, quantity FROM sale_items WHERE sale_id = ? AND id = ? AND product_id = ? LIMIT 1");
                            $itemRefStmt->execute([$refundData['sale_id'], $requestedItemId, $item['product_id']]);
                        } else {
                            $itemRefStmt = $db->prepare("SELECT id, quantity FROM sale_items WHERE sale_id = ? AND product_id = ? AND quantity >= ? ORDER BY id DESC LIMIT 1");
                            $itemRefStmt->execute([$refundData['sale_id'], $item['product_id'], $requestedQty]);
                        }
                        $itemRefRow = $itemRefStmt->fetch(PDO::FETCH_ASSOC) ?: [];
                        $itemRefId = (int)($itemRefRow['id'] ?? 0);
                        $availableQty = (int)($itemRefRow['quantity'] ?? 0);
                    }

                    if ($itemRefId <= 0) {
                        throw new Exception('Unable to match refunded item to invoice/sale details');
                    }
                    if ($requestedQty > $availableQty) {
                        throw new Exception('Refund quantity exceeds quantity bought for one or more items');
                    }

                    if ($hasInvoiceItemId) {
                        // Add to return_items (schema with invoice_item_id)
                        $stmt = $db->prepare("INSERT INTO return_items (
                            return_id, invoice_item_id, product_id, quantity, unit_price, 
                            refund_amount, reason, `condition`, created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?
                        )");
                        
                        $stmt->execute([
                            $returnId,
                            $itemRefId,
                            $item['product_id'],
                            $requestedQty,
                            $unitPrice,
                            $refundAmount,
                            $item['reason'],
                            $item['condition'] ?? 'good',
                            $currentTime
                        ]);
                    } else {
                        // Add to return_items (legacy schema)
                        $stmt = $db->prepare("INSERT INTO return_items (
                            return_id, product_id, quantity, unit_price, 
                            refund_amount, reason, `condition`, created_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?
                        )");
                        
                        $stmt->execute([
                            $returnId,
                            $item['product_id'],
                            $requestedQty,
                            $unitPrice,
                            $refundAmount,
                            $item['reason'],
                            $item['condition'] ?? 'good',
                            $currentTime
                        ]);
                    }
                    
                    // Update product stock and log history via Product helper for consistency
                    $product = new Product();
                    if (!$product->adjustStock(
                        (int) $item['product_id'],
                        $requestedQty,
                        'stock_in',
                        'return',
                        $returnId,
                        'Refund returned to stock'
                    )) {
                        throw new Exception('Failed to update product stock for refund');
                    }
                    
                    // Update sale items
                    if ($source === 'sales') {
                        $stmt = $db->prepare("UPDATE sale_items SET quantity = quantity - ? WHERE sale_id = ? AND id = ? AND quantity >= ?");
                        $stmt->execute([$requestedQty, $refundData['sale_id'], $itemRefId, $requestedQty]);
                    } else {
                        $stmt = $db->prepare("UPDATE invoice_items SET quantity = quantity - ? WHERE invoice_id = ? AND id = ? AND quantity >= ?");
                        $stmt->execute([$requestedQty, $refundData['sale_id'], $itemRefId, $requestedQty]);
                    }

                    if ($stmt->rowCount() === 0) {
                        throw new Exception('Refund quantity exceeds available quantity');
                    }
                }
                
                // Mark as fully refunded only when no item quantity remains
                if ($source === 'sales') {
                    $remainingStmt = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM sale_items WHERE sale_id = ?");
                    $remainingStmt->execute([$refundData['sale_id']]);
                    $remainingQty = (int)$remainingStmt->fetchColumn();

                    if ($balanceCancelledAmount > 0 || $paidBeforeRefund < $remainingValueBeforeRefund) {
                        $noteSuffix = "\nRefund {$refundNumber}: cash refunded " . number_format($cashRefundAmount, 2, '.', '') .
                            ", balance cancelled " . number_format($balanceCancelledAmount, 2, '.', '') .
                            " on " . $currentTime;

                        $updateShortSale = $db->prepare("
                            UPDATE sales
                            SET total_amount = ?,
                                cash_amount = ?,
                                change_amount = 0,
                                notes = CONCAT(IFNULL(notes, ''), ?)
                            WHERE id = ?
                        ");
                        $updateShortSale->execute([
                            $remainingValueAfterRefund,
                            $paidAfterRefund,
                            $noteSuffix,
                            (int) $refundData['sale_id']
                        ]);

                        try {
                            $updateApprovalBalance = $db->prepare("
                                UPDATE sale_approvals
                                SET total_amount = ?,
                                    cash_amount = ?,
                                    short_amount = ?,
                                    note = CONCAT(IFNULL(note, ''), ?),
                                    updated_at = NOW()
                                WHERE sale_id = ?
                                  AND status IN ('pending', 'approved')
                                ORDER BY id DESC
                                LIMIT 1
                            ");
                            $updateApprovalBalance->execute([
                                $remainingValueAfterRefund,
                                $paidAfterRefund,
                                $outstandingAfterRefund,
                                $noteSuffix,
                                (int) $refundData['sale_id']
                            ]);
                        } catch (Throwable $approvalUpdateError) {
                            error_log('Unable to update sale approval balance during refund: ' . $approvalUpdateError->getMessage());
                        }
                    }

                    if ($remainingQty <= 0) {
                        $stmt = $db->prepare("UPDATE sales SET payment_status = 'refunded', total_amount = 0, cash_amount = 0, change_amount = 0 WHERE id = ?");
                        $stmt->execute([(int) $refundData['sale_id']]);
                    }
                } else {
                    $remainingStmt = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM invoice_items WHERE invoice_id = ?");
                    $remainingStmt->execute([$refundData['sale_id']]);
                    $remainingQty = (int)$remainingStmt->fetchColumn();
                    if ($remainingQty <= 0) {
                        $stmt = $db->prepare("UPDATE invoices SET payment_status = 'refunded', total_amount = 0, cash_amount = 0, change_amount = 0 WHERE id = ?");
                        $stmt->execute([(int) $refundData['sale_id']]);
                    }
                }
                
                $db->commit();
                
                $responsePayload = [
                    'success' => true,
                    'message' => 'Refund processed successfully',
                    'refund_number' => $refundNumber,
                    'refund_amount' => $refundRecordAmount,
                    'returned_item_value' => $requestedRefundValue,
                    'cash_refund_amount' => $cashRefundAmount,
                    'balance_cancelled_amount' => $balanceCancelledAmount,
                    'remaining_balance' => $outstandingAfterRefund
                ];

                if ($refundType === 'exchange') {
                    $_SESSION['exchange_sale_context'] = [
                        'refund_number' => $refundNumber,
                        'sale_id' => (int) $refundData['sale_id'],
                        'source' => $source,
                        'source_invoice_number' => $saleData['invoice_number'] ?? null,
                        'exchange_credit_amount' => $refundRecordAmount,
                        'customer_name' => $saleData['customer_name'] ?? null,
                        'customer_phone' => $saleData['customer_phone'] ?? null,
                        'customer_email' => $saleData['customer_email'] ?? null,
                        'payment_method' => $saleData['payment_method'] ?? 'cash',
                        'original_total_amount' => (float) ($saleData['total_amount'] ?? 0),
                        'original_cash_amount' => isset($saleData['cash_amount']) && $saleData['cash_amount'] !== null
                            ? (float) $saleData['cash_amount']
                            : null,
                        'original_change_amount' => isset($saleData['change_amount']) && $saleData['change_amount'] !== null
                            ? (float) $saleData['change_amount']
                            : null,
                        'created_at' => $currentTime,
                        'items' => array_map(static function (array $item): array {
                            $quantity = (int) ($item['quantity'] ?? 0);
                            $unitPrice = (float) ($item['unit_price'] ?? 0);

                            return [
                                'product_id' => (int) ($item['product_id'] ?? 0),
                                'product_name' => $item['product_name'] ?? 'Unknown Product',
                                'quantity' => $quantity,
                                'unit_price' => $unitPrice,
                                'refund_amount' => (float) ($item['refund_amount'] ?? ($quantity * $unitPrice)),
                            ];
                        }, $refundData['items'])
                    ];

                    $responsePayload['message'] = 'Exchange prepared successfully';
                    $responsePayload['redirect_url'] = '/pos-system/views/sales/new.php?exchange=1';
                }

                echo json_encode($responsePayload);
                
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            }
            exit;
        }
    }

    // Get summary statistics from both tables
    $salesSummary = $db->query("SELECT COUNT(*) as total_transactions, COALESCE(SUM(total_amount), 0) as total_sales FROM sales WHERE payment_status = 'paid'")->fetch(PDO::FETCH_ASSOC);
    $invoicesSummary = $db->query("SELECT COUNT(*) as total_transactions, COALESCE(SUM(total_amount), 0) as total_sales FROM invoices WHERE payment_status = 'paid'")->fetch(PDO::FETCH_ASSOC);

    $summary = [
        'total_sales' => ($salesSummary['total_sales'] ?? 0) + ($invoicesSummary['total_sales'] ?? 0),
        'total_transactions' => ($salesSummary['total_transactions'] ?? 0) + ($invoicesSummary['total_transactions'] ?? 0),
        'average_sale' => 0
    ];

    if ($summary['total_transactions'] > 0) {
        $summary['average_sale'] = $summary['total_sales'] / $summary['total_transactions'];
    }

    // Get today's sales
    $today = date('Y-m-d');
    $todaySales = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) as total_sales FROM sales WHERE DATE(created_at) = ? AND payment_status = 'paid'");
    $todaySales->execute([$today]);
    $todaySalesData = $todaySales->fetch(PDO::FETCH_ASSOC);

    $todayInvoices = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) as total_sales FROM invoices WHERE DATE(created_at) = ? AND payment_status = 'paid'");
    $todayInvoices->execute([$today]);
    $todayInvoicesData = $todayInvoices->fetch(PDO::FETCH_ASSOC);

    $todaySummary = [
        'total_sales' => ($todaySalesData['total_sales'] ?? 0) + ($todayInvoicesData['total_sales'] ?? 0)
    ];

    // Get filters from URL
    $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $end_date = $_GET['end_date'] ?? date('Y-m-d');
    $payment_method = $_GET['payment_method'] ?? 'all';
    $status = $_GET['status'] ?? 'all';
    $search = trim($_GET['search'] ?? '');
    $searchNumeric = (ctype_digit($search) && $search !== '') ? (int) $search : null;
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = 20;

    // Build WHERE clauses for UNION query
    $whereClausesSales = [];
    $whereClausesInvoices = [];
    $paramsSales = [];
    $paramsInvoices = [];

    if ($start_date) {
        $whereClausesSales[] = "DATE(s.created_at) >= ?";
        $whereClausesInvoices[] = "DATE(i.created_at) >= ?";
        $paramsSales[] = $start_date;
        $paramsInvoices[] = $start_date;
    }
    if ($end_date) {
        $whereClausesSales[] = "DATE(s.created_at) <= ?";
        $whereClausesInvoices[] = "DATE(i.created_at) <= ?";
        $paramsSales[] = $end_date;
        $paramsInvoices[] = $end_date;
    }
    if ($payment_method !== 'all') {
        $whereClausesSales[] = "s.payment_method = ?";
        $whereClausesInvoices[] = "i.payment_method = ?";
        $paramsSales[] = $payment_method;
        $paramsInvoices[] = $payment_method;
    }
    if ($status !== 'all') {
        $whereClausesSales[] = "s.payment_status = ?";
        $whereClausesInvoices[] = "i.payment_status = ?";
        $paramsSales[] = $status;
        $paramsInvoices[] = $status;
    }
    if (!empty($search)) {
        $searchLike = "%{$search}%";
        $extraItemMatchSales = '';
        $extraItemMatchInvoices = '';
        if ($searchNumeric !== null && $searchNumeric > 0) {
            $extraItemMatchSales = " OR EXISTS (
                SELECT 1
                FROM sale_items si_search_id
                WHERE si_search_id.sale_id = s.id
                AND si_search_id.product_id = ?
            )";
            $extraItemMatchInvoices = " OR EXISTS (
                SELECT 1
                FROM invoice_items ii_search_id
                WHERE ii_search_id.invoice_id = i.id
                AND ii_search_id.product_id = ?
            )";
        }

        $whereClausesSales[] = "(
            s.invoice_number LIKE ?
            OR COALESCE(s.customer_name, '') LIKE ?
            OR COALESCE(s.customer_email, '') LIKE ?
            OR COALESCE(s.customer_phone, '') LIKE ?
            OR COALESCE(s.teller_name, '') LIKE ?
            OR COALESCE(s.notes, '') LIKE ?
            OR COALESCE(u.full_name, u.username, '') LIKE ?
            OR EXISTS (
                SELECT 1
                FROM sale_items si_search
                WHERE si_search.sale_id = s.id
                AND COALESCE(si_search.product_name, '') LIKE ?
            )
            {$extraItemMatchSales}
        )";
        $whereClausesInvoices[] = "(
            i.invoice_number LIKE ?
            OR COALESCE(i.customer_name, '') LIKE ?
            OR COALESCE(i.customer_email, '') LIKE ?
            OR COALESCE(i.customer_phone, '') LIKE ?
            OR COALESCE(i.teller_name, '') LIKE ?
            OR COALESCE(i.notes, '') LIKE ?
            OR COALESCE(u.full_name, u.username, '') LIKE ?
            OR EXISTS (
                SELECT 1
                FROM invoice_items ii_search
                WHERE ii_search.invoice_id = i.id
                AND COALESCE(ii_search.product_name, '') LIKE ?
            )
            {$extraItemMatchInvoices}
        )";
        for ($i = 0; $i < 8; $i++) {
            $paramsSales[] = $searchLike;
            $paramsInvoices[] = $searchLike;
        }
        if ($searchNumeric !== null && $searchNumeric > 0) {
            $paramsSales[] = $searchNumeric;
            $paramsInvoices[] = $searchNumeric;
        }
    }

    $whereSales = !empty($whereClausesSales) ? "WHERE " . implode(" AND ", $whereClausesSales) : "";
    $whereInvoices = !empty($whereClausesInvoices) ? "WHERE " . implode(" AND ", $whereClausesInvoices) : "";

    // Get total count
    $countQuery = "
        SELECT COUNT(DISTINCT COALESCE(invoice_number, unique_key)) as total
        FROM (
            SELECT s.invoice_number, CONCAT('sales-', s.id) as unique_key
            FROM sales s
            LEFT JOIN users u ON s.user_id = u.id
            $whereSales
            UNION ALL
            SELECT i.invoice_number, CONCAT('invoices-', i.id) as unique_key
            FROM invoices i
            LEFT JOIN users u ON i.user_id = u.id
            $whereInvoices
        ) x
    ";

    $countStmt = $db->prepare($countQuery);
    $combinedParams = array_merge($paramsSales, $paramsInvoices);
    foreach ($combinedParams as $index => $value) {
        $countStmt->bindValue($index + 1, $value);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    // Get paginated results from both tables separately
    $offset = ($page - 1) * $limit;

    // Get sales
    $salesQuery = "SELECT 
        s.id, 
        s.invoice_number, 
        s.total_amount,
        s.payment_method,
        s.payment_status,
        s.cash_amount,
        s.change_amount,
        s.customer_name,
        s.customer_email,
        s.customer_phone,
        s.teller_name,
        s.notes,
        s.created_at,
        s.user_id,
        COALESCE(u.full_name, u.username, 'System') AS cashier_name,
        (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as item_count,
        (SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(si_names.product_name), '') ORDER BY si_names.product_name SEPARATOR ', ')
            FROM sale_items si_names
            WHERE si_names.sale_id = s.id) as item_names,
        (CASE WHEN EXISTS (SELECT 1 FROM sale_items si2 WHERE si2.sale_id = s.id AND si2.quantity > 0) THEN 1 ELSE 0 END) as has_refundable_items,
        'sales' as source
    FROM sales s
    LEFT JOIN users u ON s.user_id = u.id
    $whereSales
    ORDER BY s.created_at DESC
    LIMIT ?";

    $fetchLimit = max($limit * 5, 100);

    $salesStmt = $db->prepare($salesQuery);
    foreach ($paramsSales as $index => $value) {
        $salesStmt->bindValue($index + 1, $value);
    }
    $salesStmt->bindValue(count($paramsSales) + 1, (int)$fetchLimit, PDO::PARAM_INT);
    $salesStmt->execute();
    $salesData = $salesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get invoices
    $invoicesQuery = "SELECT 
        i.id, 
        i.invoice_number, 
        i.total_amount,
        i.payment_method,
        i.payment_status,
        i.cash_amount,
        i.change_amount,
        i.customer_name,
        i.customer_email,
        i.customer_phone,
        i.teller_name,
        i.notes,
        i.created_at,
        i.user_id,
        COALESCE(u.full_name, u.username, 'System') AS cashier_name,
        (SELECT COUNT(*) FROM invoice_items WHERE invoice_id = i.id) as item_count,
        (SELECT GROUP_CONCAT(DISTINCT NULLIF(TRIM(ii_names.product_name), '') ORDER BY ii_names.product_name SEPARATOR ', ')
            FROM invoice_items ii_names
            WHERE ii_names.invoice_id = i.id) as item_names,
        (CASE WHEN EXISTS (SELECT 1 FROM invoice_items ii2 WHERE ii2.invoice_id = i.id AND ii2.quantity > 0) THEN 1 ELSE 0 END) as has_refundable_items,
        'invoices' as source
    FROM invoices i
    LEFT JOIN users u ON i.user_id = u.id
    $whereInvoices
    ORDER BY i.created_at DESC
    LIMIT ?";

    $invoicesStmt = $db->prepare($invoicesQuery);
    foreach ($paramsInvoices as $index => $value) {
        $invoicesStmt->bindValue($index + 1, $value);
    }
    $invoicesStmt->bindValue(count($paramsInvoices) + 1, (int)$fetchLimit, PDO::PARAM_INT);
    $invoicesStmt->execute();
    $invoicesData = $invoicesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Combine, deduplicate and sort
    $allSales = array_merge($salesData, $invoicesData);
    $allSales = dedupeSalesHistoryRows($allSales);
    usort($allSales, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });

    // Paginate combined, deduplicated results
    $sales = array_slice($allSales, $offset, $limit);
    ?>
    <?php include '../layouts/header.php'; ?>

    <div style="padding:12px 18px;">
        <a href="/pos-system/views/dashboard/index.php" class="btn btn-secondary" style="background:#4b5563;color:#fff;border-radius:10px;padding:10px 16px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>

    <style>
    /* Sales History Styles */
    .sales-history-container {
        padding: 1.5rem;
    }

    /* Filters Section */
    .filters-card {
        background: white;
        border-radius: 20px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 5px 20px rgba(0,0,0,0.05);
    }

    .filters-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        align-items: end;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .filter-group label {
        font-size: 0.9rem;
        font-weight: 600;
        color: #495057;
    }

    .filter-actions {
        display: flex;
        gap: 0.5rem;
        justify-content: flex-end;
    }

    /* Stats Cards */
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .stat-card {
        background: white;
        border-radius: 15px;
        padding: 1.5rem;
        box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        display: flex;
        align-items: center;
        gap: 1rem;
    }

    .stat-icon {
        width: 60px;
        height: 60px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        color: white;
    }

    .stat-icon.primary { background: linear-gradient(135deg, #4361ee, #4895ef); }
    .stat-icon.success { background: linear-gradient(135deg, #06d6a0, #06b890); }
    .stat-icon.info { background: linear-gradient(135deg, #3a86ff, #4361ee); }
    .stat-icon.warning { background: linear-gradient(135deg, #ffb703, #faa307); }

    .stat-content {
        flex: 1;
    }

    .stat-label {
        font-size: 0.9rem;
        color: #6c757d;
        margin-bottom: 0.3rem;
    }

    .stat-value {
        font-size: 1.8rem;
        font-weight: 700;
        color: #2b2d42;
        line-height: 1.2;
    }

    /* Table Styles */
    .table-card {
        background: white;
        border-radius: 20px;
        padding: 1.5rem;
        box-shadow: 0 5px 20px rgba(0,0,0,0.05);
    }

    .table-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.5rem;
        flex-wrap: wrap;
        gap: 1rem;
    }

    .table-header h3 {
        font-size: 1.2rem;
        color: #2b2d42;
        margin: 0;
    }

    .table-actions {
        display: flex;
        gap: 0.5rem;
    }

    .item-summary {
        display: flex;
        flex-direction: column;
        gap: 0.25rem;
        min-width: 220px;
    }

    .item-count {
        font-weight: 600;
        color: #2b2d42;
    }

    .item-names {
        font-size: 0.8rem;
        color: #6c757d;
        line-height: 1.35;
        display: -webkit-box;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
        overflow: hidden;
    }

    .table-responsive {
        overflow-x: auto;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    table th {
        text-align: left;
        padding: 1rem;
        background: #f8f9fa;
        color: #495057;
        font-weight: 600;
        font-size: 0.9rem;
        border-bottom: 2px solid #e9ecef;
    }

    table td {
        padding: 1rem;
        border-bottom: 1px solid #e9ecef;
        color: #2b2d42;
    }

    table tr:hover td {
        background: #f8f9fa;
    }

    /* Badge Styles */
    .badge {
        padding: 0.3rem 0.8rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 500;
        display: inline-block;
    }

    .badge-success {
        background: rgba(6, 214, 160, 0.1);
        color: #06d6a0;
    }

    .badge-warning {
        background: rgba(255, 183, 3, 0.1);
        color: #ffb703;
    }

    .badge-danger {
        background: rgba(247, 37, 133, 0.1);
        color: #f72585;
    }

    .badge-info {
        background: rgba(67, 97, 238, 0.1);
        color: #4361ee;
    }

    .badge-secondary {
        background: rgba(108, 117, 125, 0.1);
        color: #6c757d;
    }

    /* Action Buttons */
    .action-btn {
        width: 35px;
        height: 35px;
        border-radius: 8px;
        border: none;
        background: transparent;
        color: #6c757d;
        cursor: pointer;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin: 0 0.2rem;
    }

    .action-btn:hover {
        background: #4361ee;
        color: white;
    }

    .action-btn.refund:hover {
        background: #f72585;
    }

    .action-btn.print:hover {
        background: #06d6a0;
    }

    /* Loading State */
    .loading-spinner {
        display: inline-block;
        width: 40px;
        height: 40px;
        border: 4px solid #f3f3f3;
        border-top: 4px solid #4361ee;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin-bottom: 1rem;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 3rem;
        color: #adb5bd;
    }

    .empty-state i {
        font-size: 3rem;
        margin-bottom: 1rem;
        color: #dee2e6;
    }

    /* Pagination */
    .pagination {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        margin-top: 1.5rem;
        flex-wrap: wrap;
    }

    .pagination-info {
        color: #6c757d;
        font-size: 0.9rem;
    }

    .pagination-controls {
        display: flex;
        gap: 0.3rem;
        flex-wrap: wrap;
    }

    .pagination-btn {
        min-width: 35px;
        height: 35px;
        border-radius: 8px;
        border: 1px solid #e9ecef;
        background: white;
        color: #495057;
        cursor: pointer;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0 0.5rem;
    }

    .pagination-btn:hover:not(:disabled) {
        background: #4361ee;
        color: white;
        border-color: #4361ee;
    }

    .pagination-btn.active {
        background: #4361ee;
        color: white;
        border-color: #4361ee;
    }

    .pagination-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    /* Modal Styles */
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
        z-index: 1000;
        overflow-y: auto;
    }

    .modal.show {
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .modal-dialog {
        position: relative;
        width: 90%;
        max-width: 800px;
        margin: 1.75rem auto;
        pointer-events: none;
    }

    .modal-content {
        position: relative;
        display: flex;
        flex-direction: column;
        width: 100%;
        pointer-events: auto;
        background-color: #fff;
        border-radius: 20px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.15);
    }

    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #e9ecef;
        border-radius: 20px 20px 0 0;
    }

    .modal-header h5 {
        margin: 0;
        font-size: 1.2rem;
        font-weight: 600;
        color: #2b2d42;
    }

    .modal-close {
        background: none;
        border: none;
        font-size: 1.5rem;
        cursor: pointer;
        color: #adb5bd;
        transition: color 0.3s;
    }

    .modal-close:hover {
        color: #f72585;
    }

    .modal-body {
        padding: 1.5rem;
        max-height: 70vh;
        overflow-y: auto;
    }

    .modal-footer {
        padding: 1rem 1.5rem;
        border-top: 1px solid #e9ecef;
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
        border-radius: 0 0 20px 20px;
    }

    /* Receipt Styles */
    .receipt {
        max-width: 100%;
        font-family: 'Courier New', monospace;
    }

    .receipt-header {
        text-align: center;
        margin-bottom: 1.5rem;
        padding-bottom: 1rem;
        border-bottom: 2px dashed #dee2e6;
    }

    .receipt-header h3 {
        margin-bottom: 0.5rem;
        color: #2b2d42;
    }

    .receipt-table {
        width: 100%;
        border-collapse: collapse;
        margin: 1rem 0;
    }

    .receipt-table th,
    .receipt-table td {
        padding: 0.5rem;
        text-align: left;
        border-bottom: 1px dashed #dee2e6;
    }

    .receipt-totals {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 2px solid #dee2e6;
    }

    .total-row {
        display: flex;
        justify-content: space-between;
        margin: 0.3rem 0;
    }

    .grand-total {
        font-weight: bold;
        font-size: 1.1rem;
        border-top: 2px solid #2b2d42;
        padding-top: 0.5rem;
        margin-top: 0.5rem;
    }

    /* Refund Modal Styles */
    .refund-item {
        border: 1px solid #e9ecef;
        border-radius: 12px;
        padding: 1rem;
        margin-bottom: 1rem;
    }

    .refund-item-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 0.5rem;
    }

    .refund-item-select {
        margin-bottom: 0.75rem;
    }

    .refund-item-select label {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 600;
        color: #2b2d42;
        cursor: pointer;
    }

    .refund-item-name {
        font-weight: 600;
        color: #2b2d42;
    }

    .refund-item-price {
        color: #06d6a0;
        font-weight: 600;
    }

    .refund-item-controls {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 1rem;
        margin-top: 0.5rem;
    }

    .refund-qty-input {
        width: 100%;
        padding: 0.5rem;
        border: 1px solid #e9ecef;
        border-radius: 8px;
    }

    .refund-select {
        width: 100%;
        padding: 0.5rem;
        border: 1px solid #e9ecef;
        border-radius: 8px;
    }

    .refund-type-options {
        display: flex;
        gap: 1rem;
        margin: 1rem 0;
    }

    .refund-type-option {
        flex: 1;
        padding: 1rem;
        border: 2px solid #e9ecef;
        border-radius: 12px;
        cursor: pointer;
        text-align: center;
        transition: all 0.3s;
    }

    .refund-type-option:hover {
        border-color: #4361ee;
        background: #f8f9fa;
    }

    .refund-type-option.selected {
        border-color: #06d6a0;
        background: rgba(6, 214, 160, 0.05);
    }

    .refund-type-option i {
        font-size: 1.5rem;
        margin-bottom: 0.5rem;
        display: block;
    }

    /* Notification */
    .notification {
        position: fixed;
        top: 20px;
        right: 20px;
        background: white;
        border-radius: 12px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.15);
        padding: 1rem;
        display: flex;
        align-items: center;
        gap: 1rem;
        z-index: 9999;
        animation: slideIn 0.3s ease;
        max-width: 350px;
    }

    .notification-success {
        border-left: 4px solid #06d6a0;
    }

    .notification-error {
        border-left: 4px solid #f72585;
    }

    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }

    @media (max-width: 768px) {
        .filters-grid {
            grid-template-columns: 1fr;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .table-header {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .pagination {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .refund-type-options {
            flex-direction: column;
        }
    }
    </style>

    <div class="sales-history-container">
        <!-- Filters Section -->
        <div class="filters-card">
            <form method="GET" class="filters-grid" id="filter-form">
                <div class="filter-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                
                <div class="filter-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                
                <div class="filter-group">
                    <label>Payment Method</label>
                    <select name="payment_method" class="form-control">
                        <option value="all" <?php echo $payment_method === 'all' ? 'selected' : ''; ?>>All Methods</option>
                        <option value="cash" <?php echo $payment_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                        <option value="card" <?php echo $payment_method === 'card' ? 'selected' : ''; ?>>Card</option>
                        <option value="online" <?php echo $payment_method === 'online' ? 'selected' : ''; ?>>Online</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="paid" <?php echo $status === 'paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="refunded" <?php echo $status === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Invoice #, customer, teller, item name / product ID, cashier, notes..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search mr-2"></i> Apply Filters
                    </button>
                    <a href="?" class="btn btn-secondary">
                        <i class="fas fa-undo mr-2"></i> Reset
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon primary">
                    <i class="fas fa-dollar-sign"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Total Sales</div>
                    <div class="stat-value">₱<?php echo number_format($summary['total_sales'] ?? 0, 2); ?></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon success">
                    <i class="fas fa-receipt"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Transactions</div>
                    <div class="stat-value"><?php echo number_format($summary['total_transactions'] ?? 0); ?></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon info">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Average Sale</div>
                    <div class="stat-value">₱<?php echo number_format($summary['average_sale'] ?? 0, 2); ?></div>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon warning">
                    <i class="fas fa-calendar-day"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-label">Today's Sales</div>
                    <div class="stat-value">₱<?php echo number_format($todaySummary['total_sales'] ?? 0, 2); ?></div>
                </div>
            </div>
        </div>
        
        <!-- Sales Table -->
        <div class="table-card">
            <div class="table-header">
                <h3><i class="fas fa-history mr-2"></i> Sales History</h3>
                <div class="table-actions">
                    <button class="btn btn-success" onclick="exportSales()">
                        <i class="fas fa-download mr-2"></i> Export
                    </button>
                    <button class="btn btn-primary" onclick="window.location.href='/pos-system/views/sales/new.php'">
                        <i class="fas fa-plus mr-2"></i> New Sale
                    </button>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Invoice #</th>
                            <th>Date & Time</th>
                            <th>Customer</th>
                            <th>Teller</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Payment</th>
                            <th>Cashier</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales)): ?>
                            <tr>
                                <td colspan="9" class="empty-state">
                                    <i class="fas fa-receipt fa-3x"></i>
                                    <p>No sales found</p>
                                    <small>Try adjusting your filters or create a new sale</small>
                                    <a href="/pos-system/views/sales/new.php" class="btn btn-primary mt-3">
                                        <i class="fas fa-plus mr-2"></i> New Sale
                                    </a>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sales as $saleItem): ?>
                                <?php
                                $paymentBadge = getPaymentBadgeMeta($saleItem);
                                $outstandingBalance = getOutstandingBalance($saleItem);
                                $canPrintReceipt = isReceiptPrintable($db, $saleItem);
                                ?>
                                <tr>
                                    <td><strong>#<?php echo htmlspecialchars($saleItem['invoice_number'] ?? $saleItem['id']); ?></strong></td>
                                    <td><?php echo date('M d, Y h:i A', strtotime($saleItem['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($saleItem['customer_name'] ?? 'Walk-in Customer'); ?></td>
                                    <td>
                                        <?php
                                            $tellerDisplay = trim((string) ($saleItem['teller_name'] ?? ''));
                                            if ($tellerDisplay === '') {
                                                $tellerDisplay = trim((string) ($saleItem['cashier_name'] ?? ''));
                                            }
                                            echo htmlspecialchars($tellerDisplay);
                                        ?>
                                    </td>
                                    <td>
                                        <div class="item-summary">
                                            <span class="item-count"><?php echo (int) ($saleItem['item_count'] ?? 0); ?> item<?php echo ((int) ($saleItem['item_count'] ?? 0) === 1) ? '' : 's'; ?></span>
                                            <?php if (!empty($saleItem['item_names'])): ?>
                                                <span class="item-names" title="<?php echo htmlspecialchars($saleItem['item_names']); ?>">
                                                    <?php echo htmlspecialchars($saleItem['item_names']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="item-names">No item names available</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><strong>₱<?php echo number_format($saleItem['total_amount'] ?? 0, 2); ?></strong></td>
                                    <td>
                                        <span class="badge <?php echo $paymentBadge['class']; ?>"><?php echo htmlspecialchars($paymentBadge['label']); ?></span>
                                        <?php if ($outstandingBalance > 0): ?>
                                            <div style="font-size:0.75rem; color:#b45309; margin-top:4px;">Due: ₱<?php echo number_format($outstandingBalance, 2); ?></div>
                                        <?php else: ?>
                                            <div style="font-size:0.75rem; color:#64748b; margin-top:4px;"><?php echo htmlspecialchars(ucfirst((string) ($saleItem['payment_status'] ?? 'paid'))); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($saleItem['cashier_name'] ?? 'System'); ?></td>
                                    <td>
                                        <button class="action-btn" onclick="viewSale(<?php echo $saleItem['id']; ?>)" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="action-btn print" onclick="printSale(<?php echo $saleItem['id']; ?>, '<?php echo htmlspecialchars($saleItem['source'] ?? ''); ?>')" title="<?php echo $canPrintReceipt ? 'Print Receipt' : 'Print blocked until admin approval'; ?>" <?php echo $canPrintReceipt ? '' : 'disabled'; ?>>
                                            <i class="fas fa-print"></i>
                                        </button>
                                        <?php if ((int)($saleItem['has_refundable_items'] ?? 0) > 0 && ($saleItem['payment_status'] ?? '') !== 'cancelled'): ?>
                                            <button class="action-btn refund" onclick="openRefundModal(<?php echo $saleItem['id']; ?>, '<?php echo htmlspecialchars($saleItem['source'] ?? ''); ?>')" title="Refund Items">
                                                <i class="fas fa-undo-alt"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (($saleItem['payment_status'] ?? '') === 'pending'): ?>
                                            <button class="action-btn delete" onclick="cancelSale(<?php echo $saleItem['id']; ?>)" title="Cancel Sale">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total > $limit): ?>
                <?php
                $totalPages = ceil($total / $limit);
                $start = (($page - 1) * $limit) + 1;
                $end = min($page * $limit, $total);
                ?>
                <div class="pagination">
                    <div class="pagination-info">
                        Showing <?php echo $start; ?> to <?php echo $end; ?> of <?php echo $total; ?> entries
                    </div>
                    <div class="pagination-controls">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                        class="pagination-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>" 
                        <?php echo $page <= 1 ? 'onclick="return false;"' : ''; ?>>
                            <i class="fas fa-chevron-left"></i>
                        </a>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        if ($startPage > 1) {
                            echo '<a href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '" class="pagination-btn">1</a>';
                            if ($startPage > 2) {
                                echo '<span class="pagination-btn" style="border: none;">...</span>';
                            }
                        }
                        
                        for ($i = $startPage; $i <= $endPage; $i++): 
                        ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                            class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <span class="pagination-btn" style="border: none;">...</span>
                            <?php endif; ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $totalPages])); ?>" class="pagination-btn">
                                <?php echo $totalPages; ?>
                            </a>
                        <?php endif; ?>
                        
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                        class="pagination-btn <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"
                        <?php echo $page >= $totalPages ? 'onclick="return false;"' : ''; ?>>
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- View Sale Modal -->
    <div class="modal" id="viewSaleModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-receipt mr-2"></i> Sale Details
                    </h5>
                    <button type="button" class="modal-close" onclick="closeModal('viewSaleModal')">&times;</button>
                </div>
                <div class="modal-body" id="sale-details-content">
                    <div class="text-center p-4">
                        <div class="loading-spinner"></div>
                        <p>Loading sale details...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" onclick="printCurrentReceipt()" id="history-print-current-btn">
                        <i class="fas fa-print mr-2"></i> Print Receipt
                    </button>
                    <button class="btn btn-secondary" onclick="closeModal('viewSaleModal')">
                        <i class="fas fa-times mr-2"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Refund Modal -->
    <div class="modal" id="refundModal">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-undo-alt mr-2"></i> Process Refund
                    </h5>
                    <button type="button" class="modal-close" onclick="closeModal('refundModal')">&times;</button>
                </div>
                <div class="modal-body" id="refund-items-content">
                    <div class="text-center p-4">
                        <div class="loading-spinner"></div>
                        <p>Loading sale items...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" onclick="submitRefund()">
                        <i class="fas fa-check mr-2"></i> Process Refund
                    </button>
                    <button class="btn btn-secondary" onclick="closeModal('refundModal')">
                        <i class="fas fa-times mr-2"></i> Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    // API Base URL
    const API_BASE_URL = '/pos-system';

    // Format currency
    function formatCurrency(amount) {
        if (amount === undefined || amount === null) return '₱0.00';
        return '₱' + parseFloat(amount).toFixed(2);
    }

    // Format date
    function formatDate(dateString) {
        if (!dateString) return 'N/A';
        const date = new Date(dateString);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    // Escape HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function getShortBalance(sale) {
        const totalAmount = Number(sale?.total_amount || 0);
        const cashAmount = sale?.cash_amount === null || sale?.cash_amount === undefined || sale?.cash_amount === ''
            ? null
            : Number(sale.cash_amount);

        if (cashAmount === null || cashAmount <= 0 || cashAmount >= totalAmount) {
            return 0;
        }

        return totalAmount - cashAmount;
    }

    function getPaymentMeta(sale) {
        const shortBalance = getShortBalance(sale);
        if (shortBalance > 0) {
            return sale.payment_status === 'pending'
                ? { label: 'Balance', className: 'badge-warning' }
                : { label: 'Borrowed', className: 'badge-danger' };
        }

        return {
            label: String(sale.payment_method || 'cash'),
            className: 'badge-info'
        };
    }

    function getStatusMeta(sale) {
        const shortBalance = getShortBalance(sale);
        if (shortBalance > 0) {
            return sale.payment_status === 'pending'
                ? { label: 'Pending Balance', className: 'badge-warning' }
                : { label: 'Borrowed', className: 'badge-danger' };
        }

        if (sale.payment_status === 'paid') return { label: 'Paid', className: 'badge-success' };
        if (sale.payment_status === 'pending') return { label: 'Pending', className: 'badge-warning' };
        if (sale.payment_status === 'cancelled') return { label: 'Cancelled', className: 'badge-danger' };
        if (sale.payment_status === 'refunded') return { label: 'Refunded', className: 'badge-secondary' };
        return { label: String(sale.payment_status || 'paid'), className: 'badge-info' };
    }

    // View sale details
    async function viewSale(id) {
        const modal = document.getElementById('viewSaleModal');
        const content = document.getElementById('sale-details-content');
        
        content.innerHTML = '<div class="text-center p-4"><div class="loading-spinner"></div><p>Loading sale details...</p></div>';
        modal.classList.add('show');
        
        try {
            const formData = new FormData();
            formData.append('action', 'get_sale_details');
            formData.append('sale_id', id);
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success && result.data) {
                displaySaleDetails(result.data);
            } else {
                content.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Failed to load sale details</p></div>';
            }
        } catch (error) {
            console.error('Error loading sale details:', error);
            content.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading sale details</p></div>';
        }
    }

    // Display sale details
    function displaySaleDetails(saleData) {
        const content = document.getElementById('sale-details-content');
        const sale = saleData.sale;
        const items = saleData.items || [];
        
        let itemsHtml = '';
        if (items.length === 0) {
            itemsHtml = '<tr><td colspan="5" class="text-center text-warning">âš ï¸ No items found for this sale</td></tr>';
        } else {
            items.forEach(item => {
                const itemQty = parseInt(item.quantity, 10) || 0;
                const itemStatus = itemQty <= 0
                    ? '<span class="badge badge-secondary">Refunded</span>'
                    : '<span class="badge badge-success">Active</span>';
                itemsHtml += `
                    <tr>
                        <td>${escapeHtml(item.product_name || 'Unknown')}</td>
                        <td>${itemQty}</td>
                        <td>${formatCurrency(item.price_per_unit || item.unit_price)}</td>
                        <td>${formatCurrency(item.subtotal)}</td>
                        <td>${itemStatus}</td>
                    </tr>
                `;
            });
        }
        
        const paymentMeta = getPaymentMeta(sale);
        const statusMeta = getStatusMeta(sale);
        const shortBalance = getShortBalance(sale);
        const isPendingApprovalBalance = String(sale.payment_status || '').toLowerCase() === 'pending'
            && shortBalance > 0
            && String(sale.approval_status || '').toLowerCase() !== 'approved';
        const canPrintSaleReceipt = !isPendingApprovalBalance;
        const printButton = document.getElementById('history-print-current-btn');
        if (printButton) {
            printButton.disabled = !canPrintSaleReceipt;
            printButton.title = canPrintSaleReceipt ? 'Print Receipt' : 'Print blocked until admin approval';
            printButton.innerHTML = `${canPrintSaleReceipt ? '<i class="fas fa-print mr-2"></i>' : '<i class="fas fa-lock mr-2"></i>'} ${canPrintSaleReceipt ? 'Print Receipt' : 'Pending Approval'}`;
        }
        
        const html = `
            <div class="receipt">
                <div class="receipt-header">
                    <h3>Sale Receipt</h3>
                    <p><strong>Invoice #:</strong> ${escapeHtml(sale.invoice_number || sale.id)}</p>
                    <p><strong>Date:</strong> ${formatDate(sale.created_at)}</p>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <p><strong>Customer:</strong> ${escapeHtml(sale.customer_name || 'Walk-in Customer')}</p>
                        ${sale.customer_email ? `<p><strong>Email:</strong> ${escapeHtml(sale.customer_email)}</p>` : ''}
                        ${sale.customer_phone ? `<p><strong>Phone:</strong> ${escapeHtml(sale.customer_phone)}</p>` : ''}
                    </div>
                    <div>
                        <p><strong>Cashier:</strong> ${escapeHtml(sale.cashier_name || 'System')}</p>
                        ${(sale.teller_name || sale.cashier_name) ? `<p><strong>Teller:</strong> ${escapeHtml(sale.teller_name || sale.cashier_name)}</p>` : ''}
                        <p><strong>Payment Method:</strong> <span class="badge ${paymentMeta.className}">${escapeHtml(paymentMeta.label)}</span></p>
                        <p><strong>Status:</strong> <span class="badge ${statusMeta.className}">${escapeHtml(statusMeta.label)}</span></p>
                        ${shortBalance > 0 ? `<p><strong>Balance Due:</strong> ${formatCurrency(shortBalance)}</p>` : ''}
                    </div>
                </div>
                <table class="receipt-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Price</th>
                            <th>Total</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itemsHtml}
                    </tbody>
                </table>
                <div class="receipt-totals">
                    <div class="total-row">
                        <span>Subtotal:</span>
                        <span>${formatCurrency(sale.subtotal || sale.total_amount)}</span>
                    </div>
                    <div class="total-row">
                        <span>Tax (10%):</span>
                        <span>${formatCurrency(sale.tax_amount || 0)}</span>
                    </div>
                    <div class="total-row grand-total">
                        <span>Total:</span>
                        <span>${formatCurrency(sale.total_amount)}</span>
                    </div>
                </div>
                ${sale.notes ? `
                    <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #dee2e6;">
                        <strong>Notes:</strong>
                        <p>${escapeHtml(sale.notes)}</p>
                    </div>
                ` : ''}
                ${isPendingApprovalBalance ? `
                    <div style="margin-top: 1rem; padding: 1rem; background: #fef3c7; border-radius: 8px; border: 1px solid #f59e0b; color: #92400e;">
                        <strong>Receipt is locked until admin approval.</strong>
                        <div style="margin-top: 4px; font-size: 0.9rem;">This balance transaction cannot be printed until the approval is completed.</div>
                    </div>
                ` : ''}
                ${sale.payment_method === 'cash' && sale.cash_amount ? `
                    <div style="margin-top: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 8px;">
                        <div class="total-row">
                            <span>Cash Received:</span>
                            <span>${formatCurrency(sale.cash_amount)}</span>
                        </div>
                        <div class="total-row">
                            <span>Change:</span>
                            <span>${formatCurrency(sale.change_amount || 0)}</span>
                        </div>
                    </div>
                ` : ''}
            </div>
        `;
        
        content.innerHTML = html;
        window.currentSaleData = Object.assign({}, sale, { source: saleData.source || '', can_print: canPrintSaleReceipt });
    }

    // Print sale receipt sales/history.php when we print the receipt the 
    function printSale(id, source = '') {
        const src = source ? `&source=${encodeURIComponent(source)}` : '';
        const sale = window.currentSaleData && Number(window.currentSaleData.id) === Number(id)
            ? window.currentSaleData
            : null;
        if (sale && sale.can_print === false) {
            showNotification('Receipt can only be printed after admin approval', 'warning');
            return;
        }
        window.open(`/pos-system/views/sales/print.php?id=${id}${src}`, '_blank');
    }

    // Print current receipt from modal
    function printCurrentReceipt() {
        if (window.currentSaleData) {
            if (window.currentSaleData.can_print === false) {
                showNotification('Receipt can only be printed after admin approval', 'warning');
                return;
            }
            printSale(window.currentSaleData.id, window.currentSaleData.source || '');
        }
    }

    // Open refund modal
    let currentSaleForRefund = null;
    let refundItems = [];

    async function openRefundModal(saleId, source = '') {
        const modal = document.getElementById('refundModal');
        const content = document.getElementById('refund-items-content');
        
        content.innerHTML = '<div class="text-center p-4"><div class="loading-spinner"></div><p>Loading sale items...</p></div>';
        modal.classList.add('show');
        
        try {
            const formData = new FormData();
            formData.append('action', 'get_sale_details');
            formData.append('sale_id', saleId);
            if (source) formData.append('source', source);
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success && result.data) {
                currentSaleForRefund = result.data;
                displayRefundForm(result.data);
            } else {
                content.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Failed to load sale items</p></div>';
            }
        } catch (error) {
            console.error('Error loading refund items:', error);
            content.innerHTML = '<div class="empty-state"><i class="fas fa-exclamation-circle"></i><p>Error loading sale items</p></div>';
        }
    }

    // Display refund form
    function displayRefundForm(saleData) {
        const content = document.getElementById('refund-items-content');
        const sale = saleData.sale;
        const items = saleData.items || [];
        
        refundItems = items.map(item => ({
            item_id: item.id,
            product_id: item.product_id,
            product_name: item.product_name,
            original_quantity: item.quantity,
            unit_price: item.price_per_unit || item.unit_price,
            is_refunded: (parseInt(item.quantity, 10) || 0) <= 0,
            selected: false,
            refund_quantity: 0,
            reason: 'defective',
            condition: 'good'
        }));
        
        let itemsHtml = '';
        items.forEach((item, index) => {
            const itemQty = parseInt(item.quantity, 10) || 0;
            const isRefunded = itemQty <= 0;
            itemsHtml += `
                <div class="refund-item">
                    <div class="refund-item-header">
                        <span class="refund-item-name">${escapeHtml(item.product_name)}</span>
                        <span class="refund-item-price">${formatCurrency(item.price_per_unit || item.unit_price)}</span>
                    </div>
                    <div class="refund-item-select">
                        <label>
                            <input type="checkbox" data-index="${index}" onchange="toggleRefundItem(this)" ${isRefunded ? 'disabled' : ''}>
                            ${isRefunded ? 'Item refunded (remaining: 0)' : `Select item (Remaining: ${itemQty})`}
                        </label>
                    </div>
                    <div class="refund-item-controls" id="refund-controls-${index}" style="display: none;">
                        <div>
                            <label>Quantity to Refund</label>
                            <input type="number" class="refund-qty-input" data-index="${index}" min="1" max="${itemQty}" value="1" onchange="updateRefundQuantity(this)">
                        </div>
                        <div>
                            <label>Reason</label>
                            <select class="refund-select" data-index="${index}" onchange="updateRefundReason(this)">
                                <option value="defective">Defective Product</option>
                                <option value="wrong_item">Wrong Item Delivered</option>
                                <option value="not_needed">No Longer Needed</option>
                                <option value="damaged">Damaged on Arrival</option>
                                <option value="other">Other Reason</option>
                            </select>
                        </div>
                        <div>
                            <label>Condition</label>
                            <select class="refund-select" data-index="${index}" onchange="updateRefundCondition(this)">
                                <option value="good">Good (Resellable)</option>
                                <option value="damaged">Damaged</option>
                                <option value="defective">Defective</option>
                            </select>
                        </div>
                    </div>
                </div>
            `;
        });
        
        const totalAmount = sale.total_amount;
        const refundSummary = `
            <div class="refund-summary mt-3 p-3" style="background: #f8f9fa; border-radius: 12px;">
                <div class="total-row">
                    <span>Original Total:</span>
                    <span>${formatCurrency(totalAmount)}</span>
                </div>
                <div class="total-row">
                    <span>Returned Item Value:</span>
                    <span id="refund-total">₱0.00</span>
                </div>
                <div class="total-row">
                    <span>Cash to Return:</span>
                    <span id="refund-cash-total">₱0.00</span>
                </div>
                <div class="total-row">
                    <span>Balance Canceled:</span>
                    <span id="refund-balance-cancelled">₱0.00</span>
                </div>
                <div class="total-row mt-2">
                    <strong>Refund Type:</strong>
                </div>
                <div class="refund-type-options">
                    <div class="refund-type-option" data-type="cash" onclick="selectRefundType('cash')">
                        <i class="fas fa-money-bill-wave"></i>
                        <strong>Cash Refund</strong>
                        <small>Refund as cash</small>
                    </div>
                    <div class="refund-type-option" data-type="exchange" onclick="selectRefundType('exchange')">
                        <i class="fas fa-exchange-alt"></i>
                        <strong>Exchange</strong>
                        <small>Exchange of same item/product</small>
                    </div>
                </div>
            </div>
        `;
        
        content.innerHTML = itemsHtml + refundSummary;
        window.selectedRefundType = 'cash';
        document.querySelector('.refund-type-option[data-type="cash"]').classList.add('selected');
    }

    // Toggle item selection for refund
    function toggleRefundItem(checkbox) {
        const index = parseInt(checkbox.dataset.index);
        const controls = document.getElementById(`refund-controls-${index}`);
        const maxQuantity = parseInt(refundItems[index].original_quantity) || 1;
        if (refundItems[index].is_refunded) {
            checkbox.checked = false;
            return;
        }

        refundItems[index].selected = checkbox.checked;

        if (checkbox.checked) {
            controls.style.display = 'grid';
            if (!refundItems[index].refund_quantity || refundItems[index].refund_quantity < 1) {
                refundItems[index].refund_quantity = 1;
            }
        } else {
            controls.style.display = 'none';
            refundItems[index].refund_quantity = 0;
        }

        if (refundItems[index].refund_quantity > maxQuantity) {
            refundItems[index].refund_quantity = maxQuantity;
        }

        const qtyInput = document.querySelector(`.refund-qty-input[data-index="${index}"]`);
        if (qtyInput) {
            qtyInput.value = refundItems[index].refund_quantity || 1;
        }

        updateRefundTotal();
    }

    // Update refund quantity
    function updateRefundQuantity(input) {
        const index = parseInt(input.dataset.index);
        const quantity = parseInt(input.value) || 0;
        const maxQuantity = parseInt(refundItems[index].original_quantity) || 1;
        const safeQuantity = Math.min(maxQuantity, Math.max(1, quantity));
        refundItems[index].selected = true;
        refundItems[index].refund_quantity = safeQuantity;
        input.value = safeQuantity;
        updateRefundTotal();
    }

    // Update refund reason
    function updateRefundReason(select) {
        const index = parseInt(select.dataset.index);
        refundItems[index].reason = select.value;
    }

    // Update refund condition
    function updateRefundCondition(select) {
        const index = parseInt(select.dataset.index);
        refundItems[index].condition = select.value;
    }

    function getRefundPaymentBreakdown(returnedItemValue) {
        const sale = currentSaleForRefund?.sale || {};
        const originalTotal = Number(sale.total_amount || 0);
        const cashRaw = sale.cash_amount;
        const cashAmount = cashRaw === null || cashRaw === undefined || cashRaw === ''
            ? (String(sale.payment_status || '').toLowerCase() === 'paid' ? originalTotal : 0)
            : Number(cashRaw || 0);
        const changeAmount = Number(sale.change_amount || 0);
        const paidAmount = Math.min(Math.max(cashAmount - changeAmount, 0), Math.max(originalTotal, 0));
        const remainingAfterRefund = Math.max(originalTotal - returnedItemValue, 0);
        const cashRefund = Math.min(returnedItemValue, Math.max(paidAmount - remainingAfterRefund, 0));

        return {
            returnedItemValue,
            cashRefund,
            balanceCancelled: Math.max(returnedItemValue - cashRefund, 0)
        };
    }

    // Update refund total
    function updateRefundTotal() {
        let total = 0;
        refundItems.forEach(item => {
            total += item.refund_quantity * item.unit_price;
        });
        const breakdown = getRefundPaymentBreakdown(total);
        document.getElementById('refund-total').textContent = formatCurrency(breakdown.returnedItemValue);
        document.getElementById('refund-cash-total').textContent = formatCurrency(breakdown.cashRefund);
        document.getElementById('refund-balance-cancelled').textContent = formatCurrency(breakdown.balanceCancelled);
    }

    // Select refund type
    function selectRefundType(type) {
        if (type !== 'cash' && type !== 'exchange') {
            return;
        }
        window.selectedRefundType = type;
        document.querySelectorAll('.refund-type-option').forEach(opt => {
            opt.classList.remove('selected');
        });
        document.querySelector(`.refund-type-option[data-type="${type}"]`).classList.add('selected');
    }

    // Submit refund
    async function submitRefund() {
        const refundItemsList = refundItems.filter(item => item.selected && item.refund_quantity > 0);
        
        if (refundItemsList.length === 0) {
            alert('Please select at least one item to refund');
            return;
        }
        
        const totalAmount = refundItemsList.reduce((sum, item) => sum + (item.refund_quantity * item.unit_price), 0);
        const breakdown = getRefundPaymentBreakdown(totalAmount);
        
        if (!confirm(`Return items worth ${formatCurrency(totalAmount)}?\nCash to return: ${formatCurrency(breakdown.cashRefund)}\nBalance canceled: ${formatCurrency(breakdown.balanceCancelled)}`)) {
            return;
        }
        
        const submitBtn = document.querySelector('#refundModal .btn-primary');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        
        try {
            const refundData = {
                sale_id: currentSaleForRefund.sale.id,
                source: currentSaleForRefund.source || '',
                items: refundItemsList.map(item => ({
                    item_id: item.item_id,
                    product_id: item.product_id,
                    product_name: item.product_name,
                    quantity: item.refund_quantity,
                    unit_price: item.unit_price,
                    refund_amount: item.refund_quantity * item.unit_price,
                    reason: item.reason,
                    condition: item.condition
                })),
                total_amount: totalAmount,
                refund_type: window.selectedRefundType,
                reason: 'Customer request'
            };
            
            const formData = new FormData();
            formData.append('action', 'process_refund');
            formData.append('data', JSON.stringify(refundData));
            
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            });
            
            const result = await response.json();
            
            if (result.success) {
                if (window.selectedRefundType === 'exchange' && result.redirect_url) {
                    showNotification('Exchange prepared. Redirecting to new sale...', 'success');
                    setTimeout(() => {
                        closeModal('refundModal');
                        window.location.href = result.redirect_url;
                    }, 900);
                } else {
                    showNotification('Refund processed successfully!', 'success');
                    setTimeout(() => {
                        closeModal('refundModal');
                        location.reload();
                    }, 1500);
                }
            } else {
                throw new Error(result.message || 'Refund failed');
            }
        } catch (error) {
            console.error('Error:', error);
            showNotification(error.message || 'Error processing refund', 'error');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }

    // Cancel sale
    async function cancelSale(id) {
        if (!confirm('Are you sure you want to cancel this sale? This will restock the items.')) return;
        
        try {
            const response = await fetch(`${API_BASE_URL}/api/sales/cancel.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            });
            
            const result = await response.json();
            
            if (result.success) {
                showNotification('Sale cancelled successfully', 'success');
                location.reload();
            } else {
                alert(result.message || 'Failed to cancel sale');
            }
        } catch (error) {
            console.error('Error cancelling sale:', error);
            alert('Error cancelling sale');
        }
    }

    // Export sales
    function exportSales() {
        const form = document.getElementById('filter-form');
        const formData = new FormData(form);
        const params = new URLSearchParams(formData).toString();
        window.location.href = `/pos-system/api/sales/export.php?${params}`;
    }

    // Close modal
    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('show');
    }

    // Show notification
    function showNotification(message, type) {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i>
                <span>${message}</span>
            </div>
            <button class="notification-close" onclick="this.parentElement.remove()">&times;</button>
        `;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 3000);
    }

    // Auto-filter search
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            let searchTimeout;
            const form = document.getElementById('filter-form');
            const initialSearch = (searchInput.value || '').trim();
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                const value = (this.value || '').trim();
                if (value.length > 0 && value.length < 3) {
                    return;
                }
                if (value === initialSearch) {
                    return;
                }
                searchTimeout = setTimeout(() => {
                    form.submit();
                }, 700);
            });
        }
    });
    </script>

    <?php include '../layouts/footer.php'; ?>

