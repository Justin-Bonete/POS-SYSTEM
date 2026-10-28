<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

function ds_table_exists(PDO $db, string $table): bool {
    try {
        $result = $db->query("SHOW TABLES LIKE '" . str_replace("'", "\\'", $table) . "'");
        return $result && $result->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function ds_ensure_drugstore_batch_support(PDO $db): void {
    if (!ds_table_exists($db, 'drugstore_batches')) {
        $db->exec("CREATE TABLE drugstore_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inventory_id INT NOT NULL,
            batch_number VARCHAR(50) NOT NULL,
            expiration_date DATE NOT NULL,
            quantity INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (inventory_id) REFERENCES drugstore_inventory(id) ON DELETE CASCADE
        )");
    }

    if (!ds_table_exists($db, 'drugstore_sale_batches')) {
        $db->exec("CREATE TABLE drugstore_sale_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_item_id INT NOT NULL,
            batch_id INT NOT NULL,
            quantity INT NOT NULL,
            FOREIGN KEY (sale_item_id) REFERENCES drugstore_sale_items(id) ON DELETE CASCADE,
            FOREIGN KEY (batch_id) REFERENCES drugstore_batches(id) ON DELETE CASCADE
        )");
    }
}

function ds_sync_inventory_quantity_from_batches(PDO $db, int $inventoryId): void {
    $stmt = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM drugstore_batches WHERE inventory_id = ?");
    $stmt->execute([$inventoryId]);
    $totalQuantity = (int)$stmt->fetchColumn();

    $updateStmt = $db->prepare("UPDATE drugstore_inventory SET quantity = ? WHERE id = ?");
    $updateStmt->execute([$totalQuantity, $inventoryId]);
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['selected_system']) || $_SESSION['selected_system'] !== 'drugstore') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid sale payload']);
    exit();
}

$invoiceNumber = trim((string)($payload['invoice_number'] ?? ''));
$saleType = ($payload['sale_type'] ?? 'retail') === 'wholesale' ? 'wholesale' : 'retail';
$customerName = trim((string)($payload['customer_name'] ?? 'Walk-in Customer'));
$amountTendered = isset($payload['amount_tendered']) ? (float)$payload['amount_tendered'] : 0.0;
$changeAmount = isset($payload['change']) ? (float)$payload['change'] : 0.0;
$grandTotal = isset($payload['total']) ? (float)$payload['total'] : 0.0;
$paymentMethod = trim((string)($payload['payment_method'] ?? 'cash')) ?: 'cash';
$saleDate = !empty($payload['date']) ? date('Y-m-d H:i:s', strtotime((string)$payload['date'])) : date('Y-m-d H:i:s');
$items = $payload['items'] ?? [];

if ($invoiceNumber === '' || $grandTotal <= 0 || !is_array($items) || empty($items)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Sale data is incomplete']);
    exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "drugstore1_db";

try {
    $db = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $existingSaleStmt = $db->prepare("SELECT id, invoice_number FROM drugstore_sales WHERE invoice_number = ?");
    $existingSaleStmt->execute([$invoiceNumber]);
    $existingSale = $existingSaleStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingSale) {
        echo json_encode([
            'success' => true,
            'invoice_number' => $existingSale['invoice_number'],
            'sale_id' => (int)$existingSale['id'],
            'already_saved' => true
        ]);
        exit();
    }

    ds_ensure_drugstore_batch_support($db);

    $db->beginTransaction();

    $saleStmt = $db->prepare(
        "INSERT INTO drugstore_sales
        (invoice_number, sale_type, customer_name, grand_total, amount_tendered, change_amount, payment_method, user_id, sale_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $saleStmt->execute([
        $invoiceNumber,
        $saleType,
        $customerName,
        $grandTotal,
        $amountTendered,
        $changeAmount,
        $paymentMethod,
        (int)$_SESSION['user_id'],
        $saleDate
    ]);

    $saleId = (int)$db->lastInsertId();

    $itemInsertStmt = $db->prepare(
        "INSERT INTO drugstore_sale_items (sale_id, product_id, quantity, price, subtotal)
        VALUES (?, ?, ?, ?, ?)"
    );
    $inventorySelectStmt = $db->prepare(
        "SELECT id, generic_name, brand_name FROM drugstore_inventory WHERE id = ? FOR UPDATE"
    );
    $batchSelectStmt = $db->prepare(
        "SELECT id, batch_number, expiration_date, quantity
         FROM drugstore_batches
         WHERE inventory_id = ? AND quantity > 0 AND expiration_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY)
         ORDER BY expiration_date ASC, id ASC"
    );
    $batchUpdateStmt = $db->prepare("UPDATE drugstore_batches SET quantity = quantity - ? WHERE id = ?");
    $saleBatchInsertStmt = $db->prepare("INSERT INTO drugstore_sale_batches (sale_item_id, batch_id, quantity) VALUES (?, ?, ?)");

    $saleAmountStmt = $db->prepare("INSERT INTO " . ($saleType === 'wholesale' ? 'agent' : 'store') . " (amount, sale_date) VALUES (?, ?)");

    $calculatedGrandTotal = 0.0;

    foreach ($items as $item) {
        $productId = (int)($item['id'] ?? 0);
        $quantity = (int)($item['qty'] ?? 0);
        $price = (float)($item['price'] ?? 0);
        $subtotal = round($quantity * $price, 2);

        if ($productId <= 0 || $quantity <= 0 || $price < 0) {
            throw new RuntimeException('Invalid sale item detected.');
        }

        $inventorySelectStmt->execute([$productId]);
        $product = $inventorySelectStmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new RuntimeException('A product in the sale could not be found.');
        }

        $batchSelectStmt->execute([$productId]);
        $batches = $batchSelectStmt->fetchAll(PDO::FETCH_ASSOC);
        $availableSafeQuantity = 0;
        foreach ($batches as $batch) {
            $availableSafeQuantity += (int)($batch['quantity'] ?? 0);
        }

        if ($availableSafeQuantity <= 0) {
            $productName = trim((string)(($product['brand_name'] ?? '') . ' ' . ($product['generic_name'] ?? '')));
            throw new RuntimeException('This product cannot be sold because all remaining batches are within 30 days of expiration.');
        }

        if ($availableSafeQuantity < $quantity) {
            $productName = trim((string)(($product['brand_name'] ?? '') . ' ' . ($product['generic_name'] ?? '')));
            throw new RuntimeException('Not enough safe stock for ' . ($productName !== '' ? $productName : 'the selected item') . '.');
        }

        $itemInsertStmt->execute([
            $saleId,
            $productId,
            $quantity,
            $price,
            $subtotal
        ]);

        $saleItemId = (int)$db->lastInsertId();
        $remainingQty = $quantity;
        foreach ($batches as $batch) {
            if ($remainingQty <= 0) {
                break;
            }

            $batchQuantity = (int)($batch['quantity'] ?? 0);
            if ($batchQuantity <= 0) {
                continue;
            }

            $usedFromBatch = min($remainingQty, $batchQuantity);
            if ($usedFromBatch <= 0) {
                continue;
            }

            $batchUpdateStmt->execute([$usedFromBatch, (int)$batch['id']]);
            $saleBatchInsertStmt->execute([$saleItemId, (int)$batch['id'], $usedFromBatch]);
            $remainingQty -= $usedFromBatch;
        }

        if ($remainingQty > 0) {
            throw new RuntimeException('Unable to allocate stock from safe batches for the selected item.');
        }

        ds_sync_inventory_quantity_from_batches($db, $productId);
        $calculatedGrandTotal += $subtotal;
    }

    if (abs($calculatedGrandTotal - $grandTotal) > 0.05) {
        $updateTotalStmt = $db->prepare("UPDATE drugstore_sales SET grand_total = ?, amount_tendered = ?, change_amount = ? WHERE id = ?");
        $recalculatedChange = max(0, $amountTendered - $calculatedGrandTotal);
        $updateTotalStmt->execute([$calculatedGrandTotal, $amountTendered, $recalculatedChange, $saleId]);
        $grandTotal = $calculatedGrandTotal;
        $changeAmount = $recalculatedChange;
    }

    $saleAmountStmt->execute([$grandTotal, $saleDate]);

    $db->commit();

    echo json_encode([
        'success' => true,
        'invoice_number' => $invoiceNumber,
        'sale_id' => $saleId,
        'grand_total' => $grandTotal,
        'change_amount' => $changeAmount
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
