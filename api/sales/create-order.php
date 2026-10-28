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
require_once __DIR__ . '/../../classes/Sale.php';
require_once __DIR__ . '/../../classes/Product.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null,
];

function generateOrderNumber() {
    return 'ORD-' . date('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
}

function ensureSaleItemsAllowsBalanceRows(PDO $db) {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM sale_items LIKE 'product_id'");
        $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        if ($column && strtoupper((string) ($column['Null'] ?? 'NO')) !== 'YES') {
            $db->exec("ALTER TABLE sale_items MODIFY product_id INT NULL");
        }
    } catch (Throwable $e) {
        // Ignore here; insert will still surface a meaningful error if schema remains incompatible.
    }
}

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('User not authenticated');
    }

    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!is_array($data)) {
        throw new Exception('Invalid JSON data');
    }

    if (empty($data['items']) || !is_array($data['items'])) {
        throw new Exception('No items in cart');
    }

    $product = new Product();
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
        $data['discount_percent'] ?? null,
        $data['discount_amount'] ?? 0
    );
    $totalAmount = $totals['total_amount'];

    require_once __DIR__ . '/../../config/database.php';
    $db = ConfigDatabase::getInstance()->getConnection();
    ensureSaleItemsAllowsBalanceRows($db);
    saleDiscountEnsureSalesColumn($db);

    $sale = new Sale();
    $saleId = $sale->create([
        'invoice_number' => generateOrderNumber(),
        'user_id' => (int) $_SESSION['user_id'],
        'customer_name' => $data['customer_name'] ?? null,
        'customer_email' => $data['customer_email'] ?? null,
        'customer_phone' => $data['customer_phone'] ?? null,
        'teller_name' => isset($data['teller_name']) ? trim((string) $data['teller_name']) : null,
        'total_amount' => $totalAmount,
        'discount_amount' => $totals['discount_amount'],
        'discount_percent' => $totals['discount_percent'],
        'payment_method' => $data['payment_method'] ?? 'cash',
        'payment_status' => 'pending',
        'cash_amount' => null,
        'change_amount' => null,
        'notes' => $data['notes'] ?? null,
        'items' => $items,
    ]);

    if (!$saleId) {
        throw new Exception('Failed to save order');
    }

    $response['success'] = true;
    $response['message'] = 'Order saved successfully';
    $response['data'] = [
        'id' => (int) $saleId,
    ];
} catch (Throwable $e) {
    $response['message'] = $e->getMessage();
}

echo json_encode($response);
exit;
