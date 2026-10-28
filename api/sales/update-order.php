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

function ensureSaleItemsAllowsBalanceRows(PDO $db) {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM sale_items LIKE 'product_id'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($column && strtoupper((string) ($column['Null'] ?? 'NO')) !== 'YES') {
            $db->exec("ALTER TABLE sale_items MODIFY product_id INT NULL");
        }
    } catch (Throwable $e) {
        // Ignore here; later insert/update will report the failure if needed.
    }
}

function ensureSalesTellerColumn(PDO $db): void
{
    try {
        $stmt = $db->query("SHOW COLUMNS FROM sales LIKE 'teller_name'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if (!$column) {
            $db->exec("ALTER TABLE sales ADD COLUMN teller_name VARCHAR(120) NULL AFTER customer_phone");
        }
    } catch (Throwable $e) {
        // Ignore; update will surface a meaningful error if schema remains incompatible.
    }
}

function ensureSalesDiscountColumn(PDO $db): void
{
    saleDiscountEnsureSalesColumn($db);
}

$response = [
    'success' => false,
    'message' => '',
    'data' => null,
];

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated');
    }

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    if (!is_array($data)) {
        throw new Exception('Invalid JSON data');
    }

    $orderId = (int) ($data['order_id'] ?? 0);
    if ($orderId <= 0) {
        throw new Exception('Invalid order ID');
    }

    if (empty($data['items']) || !is_array($data['items'])) {
        throw new Exception('No items in order');
    }

    $db = ConfigDatabase::getInstance()->getConnection();
    $product = new Product();
    ensureSaleItemsAllowsBalanceRows($db);
    ensureSalesTellerColumn($db);
    ensureSalesDiscountColumn($db);

    $db->beginTransaction();

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
        throw new Exception('Order not found');
    }

    if (($sale['payment_status'] ?? '') !== 'pending') {
        throw new Exception('Order is not pending (current status: ' . ($sale['payment_status'] ?? 'unknown') . ')');
    }

    $items = [];

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
            if ($productId <= 0) {
                throw new Exception('Invalid order item');
            }

            $productDetails = $product->getById($productId);
            if (!$productDetails) {
                throw new Exception("Product ID {$productId} not found");
            }

            if ((int) ($productDetails['quantity'] ?? 0) < $quantity) {
                throw new Exception("Insufficient stock for {$productDetails['name']}");
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
            'is_balance_item' => $isBalanceItem,
        ];
    }

    $totals = saleDiscountComputeTotals(
        $items,
        $data['discount_percent'] ?? ($sale['discount_percent'] ?? null),
        $data['discount_amount'] ?? ($sale['discount_amount'] ?? 0)
    );
    $totalAmount = $totals['total_amount'];

    $updateSaleStmt = $db->prepare("
        UPDATE sales
        SET total_amount = ?,
            discount_amount = ?,
            discount_percent = ?,
            payment_method = ?,
            customer_name = ?,
            customer_phone = ?,
            teller_name = ?,
            notes = ?
        WHERE id = ?
    ");
    $updateSaleStmt->execute([
        $totalAmount,
        $totals['discount_amount'],
        $totals['discount_percent'],
        $data['payment_method'] ?? ($sale['payment_method'] ?? 'cash'),
        $data['customer_name'] ?? $sale['customer_name'] ?? null,
        $data['customer_phone'] ?? $sale['customer_phone'] ?? null,
        isset($data['teller_name']) ? trim((string) $data['teller_name']) : ($sale['teller_name'] ?? null),
        $data['notes'] ?? $sale['notes'] ?? null,
        $orderId,
    ]);

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

    $db->commit();

    $response['success'] = true;
    $response['message'] = 'Order updated successfully';
    $response['data'] = [
        'id' => $orderId,
        'invoice_number' => $sale['invoice_number'] ?? null,
        'total_amount' => $totalAmount,
        'discount_amount' => $totals['discount_amount'],
        'discount_percent' => $totals['discount_percent'],
    ];
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
