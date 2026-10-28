<?php
date_default_timezone_set('Asia/Manila');
// At the top of views/sales/new.php
require_once __DIR__ . '/../../includes/auth.php';
requireAuth();
requireAccess('sales'); // Only users with sales access can view

// Prevent managers from accessing dashboard - redirect to appropriate page
if (isset($_GET['redirect_to']) && $_GET['redirect_to'] === 'dashboard' && isManager()) {
    header('Location: /pos-system/views/products/stock-in.php');
    exit();
}

// views/sales/new.php
$pageTitle = 'Point of Sale';
$pageScripts = ['pos.js', 'qr-scanner.js'];

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Handle AJAX reservation/create/update requests directly from this view
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    // Always return JSON if this view was called via fetch/ajax
    $isAjaxLike = isset($_SERVER['CONTENT_TYPE']) && stripos((string)$_SERVER['CONTENT_TYPE'], 'application/json') !== false;
    if ($isAjaxLike) {
        header('Content-Type: application/json');
    }

    if (is_array($payload) && !empty($payload['action']) && in_array($payload['action'], ['reserve_order', 'reserve_update'], true)) {
        require_once __DIR__ . '/../../config/database.php';
        $db = ConfigDatabase::getInstance()->getConnection();

        $response = ['success' => false, 'message' => '', 'data' => null];

        try {
            if (!isset($_SESSION['user_id'])) {
                throw new Exception('User not authenticated');
            }

            if (empty($payload['items']) || !is_array($payload['items'])) {
                throw new Exception('No items provided');
            }


            // Build items and check availability using reserved = SUM(pending sale_items)
            $productStmt = $db->prepare('SELECT * FROM products WHERE id = ? FOR UPDATE');
            $reservedStmt = $db->prepare("SELECT COALESCE(SUM(si.quantity),0) AS reserved FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.payment_status = 'pending' AND si.product_id = ?" );

            $items = [];
            foreach ($payload['items'] as $it) {
                $productId = (int) ($it['product_id'] ?? 0);
                $quantity = (int) ($it['quantity'] ?? 0);
                $unitPrice = isset($it['price']) ? (float) $it['price'] : 0;
                $productName = trim((string) ($it['product_name'] ?? ''));
                $isBalanceItem = !empty($it['is_balance_item']) || $productId <= 0;

                if ($quantity <= 0) {
                    throw new Exception('Invalid item quantity');
                }

                if ($isBalanceItem) {
                    if ($productName === '' || $unitPrice <= 0) {
                        throw new Exception('Invalid balance item');
                    }
                } else {
                    if ($productId <= 0) {
                        throw new Exception('Invalid product');
                    }

                    // lock product row
                    $productStmt->execute([$productId]);
                    $prod = $productStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$prod) {
                        throw new Exception("Product ID {$productId} not found");
                    }

                    // compute reserved excluding current order when updating
                    $reservedStmt->execute([$productId]);
                    $row = $reservedStmt->fetch(PDO::FETCH_ASSOC);
                    $reserved = (int) ($row['reserved'] ?? 0);
                    if (!empty($payload['order_id']) && $payload['action'] === 'reserve_update') {
                        // subtract quantities from this order's existing items (if any) so update comparisons are correct
                        $existingStmt = $db->prepare('SELECT COALESCE(SUM(quantity),0) AS qty FROM sale_items WHERE sale_id = ? AND product_id = ?');
                        $existingStmt->execute([(int)$payload['order_id'], $productId]);
                        $ex = $existingStmt->fetch(PDO::FETCH_ASSOC);
                        $reserved -= (int) ($ex['qty'] ?? 0);
                    }

                    $available = (int) $prod['quantity'] - max(0, $reserved);
                    if ($quantity > $available) {
                        throw new Exception("Insufficient available stock for {$prod['name']}. Available: {$available}");
                    }

                    if ($unitPrice <= 0) {
                        $unitPrice = (float) ($prod['price'] ?? 0);
                    }
                    $productName = $prod['name'];
                }

                $items[] = [
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'quantity' => $quantity,
                    'price_per_unit' => $unitPrice,
                    'subtotal' => $unitPrice * $quantity,
                    'is_balance_item' => $isBalanceItem,
                ];
            }

            // Totals and insertion (mimic existing api behavior but with proper reserved checks)
            // Simple totals
            $totalAmount = 0;
            foreach ($items as $it) $totalAmount += $it['subtotal'];

            // Ensure `updated_at` exists on `sales` table (some installs omit it)
            try {
                $colCheck = $db->query("SHOW COLUMNS FROM sales LIKE 'updated_at'");
                $hasUpdatedAt = $colCheck && $colCheck->fetch(PDO::FETCH_ASSOC);
                if (!$hasUpdatedAt) {
                    // Add updated_at column without breaking existing rows
                    $db->exec("ALTER TABLE sales ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
                }
            } catch (Throwable $ee) {
                // ignore - if alter fails, the later queries may fail and will be handled
            }

            // Insert or update sale within transaction
            $db->beginTransaction();

            if ($payload['action'] === 'reserve_order') {
                $invoice = 'ORD-' . date('Ymd') . '-' . str_pad((string) random_int(1, 9999), 4, '0', STR_PAD_LEFT);
                $insertSale = $db->prepare("INSERT INTO sales (invoice_number, user_id, customer_name, customer_email, customer_phone, teller_name, total_amount, payment_method, payment_status, notes, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())");
                $insertSale->execute([
                    $invoice,
                    (int) $_SESSION['user_id'],
                    $payload['customer_name'] ?? null,
                    $payload['customer_email'] ?? null,
                    $payload['customer_phone'] ?? null,
                    isset($payload['teller_name']) ? trim((string)$payload['teller_name']) : null,
                    $totalAmount,
                    $payload['payment_method'] ?? 'cash',
                    $payload['notes'] ?? null,
                ]);
                $saleId = (int)$db->lastInsertId();
            } else {
                $saleId = (int) ($payload['order_id'] ?? 0);
                if ($saleId <= 0) throw new Exception('Invalid order id for update');

                // ensure sale exists and is pending
                $sStmt = $db->prepare('SELECT * FROM sales WHERE id = ? FOR UPDATE');
                $sStmt->execute([$saleId]);
                $s = $sStmt->fetch(PDO::FETCH_ASSOC);
                if (!$s) throw new Exception('Order not found');
                if (($s['payment_status'] ?? '') !== 'pending') throw new Exception('Only pending orders can be updated');

                $updateSale = $db->prepare('UPDATE sales SET total_amount = ?, customer_name = ?, customer_phone = ?, teller_name = ?, payment_method = ?, notes = ?, updated_at = NOW() WHERE id = ?');
                $updateSale->execute([
                    $totalAmount,
                    $payload['customer_name'] ?? $s['customer_name'] ?? null,
                    $payload['customer_phone'] ?? $s['customer_phone'] ?? null,
                    isset($payload['teller_name']) ? trim((string)$payload['teller_name']) : ($s['teller_name'] ?? null),
                    $payload['payment_method'] ?? $s['payment_method'] ?? 'cash',
                    $payload['notes'] ?? $s['notes'] ?? null,
                    $saleId,
                ]);

                $deleteItems = $db->prepare('DELETE FROM sale_items WHERE sale_id = ?');
                $deleteItems->execute([$saleId]);
            }

            $insItem = $db->prepare('INSERT INTO sale_items (sale_id, product_id, product_name, quantity, price_per_unit, subtotal) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($items as $it) {
                $insItem->execute([
                    $saleId,
                    ((int)$it['product_id'] > 0 ? (int)$it['product_id'] : null),
                    $it['product_name'],
                    $it['quantity'],
                    $it['price_per_unit'],
                    $it['subtotal'],
                ]);
            }

            $db->commit();

            $response['success'] = true;
            $response['message'] = ($payload['action'] === 'reserve_order') ? 'Order reserved successfully' : 'Order updated and reservation refreshed';
            $response['data'] = ['id' => $saleId, 'invoice_number' => $invoice ?? ($s['invoice_number'] ?? null)];
        } catch (Throwable $e) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            $response['message'] = $e->getMessage();
        }

        echo json_encode($response);
        exit;
    }
}
$exchangeSaleContext = null;
if (isset($_GET['exchange']) && !empty($_SESSION['exchange_sale_context']) && is_array($_SESSION['exchange_sale_context'])) {
    $exchangeSaleContext = $_SESSION['exchange_sale_context'];
}

// Include auth first - this will handle the auth check
require_once __DIR__ . '/../../includes/auth.php';

// functions.php might already be included in auth.php, but if not:
if (!function_exists('formatCurrency')) {
    require_once __DIR__ . '/../../includes/functions.php';
}

// Database and classes
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../classes/Category.php';
require_once __DIR__ . '/../../classes/ProductHistory.php';

// Now check authentication
requireAuth();

$product = new Product();
$category = new Category();
$productHistory = new ProductHistory();
$lowStockThreshold = 10;
$criticalStockThreshold = 5;

// Get all active products for the grid (unlimited rows)
$allProducts = $product->getAll(['status' => 'active'], 1, 0);

// Keep all active products in the grid (including out-of-stock items)
$products = array_values($allProducts);

// Compute reserved quantities for displayed products (sum of pending sale_items)
if (!empty($products)) {
    try {
        $db = ConfigDatabase::getInstance()->getConnection();
        $ids = array_map(function($p){ return (int)$p['id']; }, $products);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $reservedSql = "SELECT si.product_id, COALESCE(SUM(si.quantity),0) AS reserved FROM sale_items si JOIN sales s ON si.sale_id = s.id WHERE s.payment_status = 'pending' AND si.product_id IN ($placeholders) GROUP BY si.product_id";
        $stmt = $db->prepare($reservedSql);
        $stmt->execute($ids);
        $reservedMap = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $reservedMap[(int)$r['product_id']] = (int)$r['reserved'];
        }

        // Attach reserved and available to product entries
        foreach ($products as &$pp) {
            $pid = (int)$pp['id'];
            $res = $reservedMap[$pid] ?? 0;
            $pp['reserved'] = $res;
            $pp['available'] = max(0, (int)$pp['quantity'] - $res);
        }
        unset($pp);
    } catch (Throwable $e) {
        // If DB read fails, fall back to defaults
        foreach ($products as &$pp) {
            $pp['reserved'] = 0;
            $pp['available'] = max(0, (int)$pp['quantity']);
        }
        unset($pp);
    }
} 

// Get categories and filter to only those with products in stock
$allCategories = $category->getAll();
$categories = [];

// Build a list of categories that actually have products in stock
$categoriesWithStock = [];
foreach ($products as $p) {
    if (!empty($p['category'])) {
        $categoriesWithStock[$p['category']] = true;
    }
}

// Filter categories to only show those with products in stock
foreach ($allCategories as $cat) {
    $catName = is_array($cat) ? $cat['name'] : $cat;
    if (isset($categoriesWithStock[$catName])) {
        $categories[] = $cat;
    }
}

// If no categories found, show a message
if (empty($categories)) {
    $categories = [];
}

$exchangeSaleContextJson = json_encode(
    $exchangeSaleContext,
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<?php include __DIR__ . '/../layouts/header.php'; ?>

<div style="padding:12px 18px;">
    <a href="/pos-system/views/dashboard/index.php" class="btn btn-secondary" style="background:#4b5563;color:#fff;border-radius:10px;padding:10px 16px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
</div>

<!-- Include jsQR library -->
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<!-- ZXing for QR + 1D barcode scanning -->
<script src="https://unpkg.com/@zxing/library@0.20.0/umd/index.min.js"></script>

<!-- Add a debug section to help identify issues -->
<?php if (isset($_GET['debug'])): ?>
<div class="debug-info m-3 p-3 bg-light" style="font-size: 12px; border: 1px solid #ccc; max-height: 200px; overflow: auto;">
    <h6>Debug Info <button onclick="this.parentElement.style.display='none'" style="float:right">X</button></h6>
    <p><strong>Total products in DB (active):</strong> <?php echo count($allProducts); ?></p>
    <p><strong>Products with stock > 0:</strong> <?php echo count($products); ?></p>
    <p><strong>Categories with stock:</strong> <?php echo count($categories); ?></p>
    <p><strong>Products with zero stock hidden:</strong> <?php echo count($allProducts) - count($products); ?></p>
    <button class="btn btn-sm btn-primary" onclick="console.log('All Products:', <?php echo htmlspecialchars(json_encode($allProducts)); ?>)">Log All Products</button>
    <button class="btn btn-sm btn-success" onclick="console.log('Filtered Products:', <?php echo htmlspecialchars(json_encode($products)); ?>)">Log Filtered Products</button>
</div>
<?php endif; ?>

<style>
/* ==================== POS Layout ==================== */
.pos-container {
    display: grid;
    grid-template-columns: 1fr var(--cart-width, 450px);
    gap: 1.5rem;
    min-height: calc(100vh - 100px);
}

/* ==================== Products Grid ==================== */
.products-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
    max-height: calc(100vh - 250px);
    overflow-y: auto;
    padding: 0.5rem;
}

.product-card {
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 12px;
    overflow: hidden;
    cursor: pointer;
    transition: all 0.3s;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
    content-visibility: auto;
    contain-intrinsic-size: 260px;
}

.product-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(67, 97, 238, 0.15);
    border-color: #4361ee;
}

.product-card.highlight {
    animation: highlight 0.5s ease;
}

@keyframes highlight {
    0% { background: white; }
    50% { background: #e3f2fd; transform: scale(1.02); }
    100% { background: white; }
}

.product-card.out-of-stock {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

.product-image {
    height: 150px;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
}

.product-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.product-image-zoom {
    position: absolute;
    right: 10px;
    bottom: 10px;
    width: 34px;
    height: 34px;
    border: none;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.72);
    color: #fff;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.2s ease, transform 0.2s ease;
    z-index: 2;
}

.product-card:hover .product-image-zoom,
.product-image:hover .product-image-zoom,
.product-image-zoom:focus {
    opacity: 1;
}

.product-image-zoom:hover {
    transform: scale(1.06);
}

.product-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #adb5bd;
    font-size: 3rem;
}

.product-info {
    padding: 1rem;
}

.product-name {
    font-weight: 600;
    margin-bottom: 0.5rem;
    color: #2b2d42;
    font-size: 1rem;
    word-wrap: break-word;
    line-height: 1.3;
}

.product-price {
    color: #4361ee;
    font-weight: 700;
    font-size: 1.25rem;
    margin-bottom: 0.5rem;
}

.product-stock {
    font-size: 0.95rem;
    color: #6c757d;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 3px;
    line-height: 1.1;
}

.product-stock-total {
    font-size: 1.05rem;
    font-weight: 700;
    color: #2b2d42;
    margin-bottom: 2px;
}
.product-stock-reserved {
    font-size: 0.95rem;
    margin-bottom: 2px;
}
.product-stock-available {
    font-size: 1.05rem;
    font-weight: 700;
}

.product-stock.stock-low {
    color: #f72585;
}

.product-stock.stock-warning {
    color: #b45309;
    font-weight: 700;
}

.product-stock.stock-out {
    color: #dc3545;
}

.product-stock-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    margin-top: 0.45rem;
    padding: 0.3rem 0.55rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    line-height: 1.2;
}

.product-stock-badge.warning {
    background: #fef3c7;
    color: #92400e;
}

.product-stock-badge.danger {
    background: #ffe4e6;
    color: #be123c;
}

.no-products-message {
    text-align: center;
    padding: 3rem;
    color: #6c757d;
    grid-column: 1 / -1;
}

.no-products-message i {
    font-size: 3rem;
    margin-bottom: 1rem;
    color: #dee2e6;
}

/* ==================== Cart Section ==================== */
.cart-section {
    position: sticky;
    top: 80px;
    height: calc(100vh - 100px);
    position: relative;
}

.cart-resize-handle {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 8px;
    background: transparent;
    cursor: ew-resize;
    user-select: none;
    transition: background 0.3s;
    z-index: 10;
}

.cart-resize-handle:hover {
    background: linear-gradient(to right, #4361ee, transparent);
}

.cart-container {
    background: white;
    border-radius: 20px;
    padding: 1.5rem;
    padding-left: 2rem;
    height: 100%;
    display: flex;
    flex-direction: column;
    box-shadow: 0 5px 20px rgba(0,0,0,0.05);
}

.cart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 2px solid #f8f9fa;
}

.cart-badge {
    background: #4361ee;
    color: white;
    padding: 0.3rem 0.8rem;
    border-radius: 20px;
    font-weight: 600;
}

.scanner-input-group {
    position: relative;
    margin-bottom: 1rem;
}

.scanner-input-wrap {
    position: relative;
}

.scanner-input-wrap i {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: #adb5bd;
    width: 16px;
    text-align: center;
    pointer-events: none;
}

.scanner-input-wrap input {
    padding-left: 35px;
}

.scanner-input-group small {
    display: block;
    margin-top: 0.3rem;
    color: #6c757d;
}

/* Cart Items */
.cart-items {
    flex: 1 1 240px;
    overflow-y: auto;
    margin-bottom: 0.75rem;
    min-height: 180px;
}

.empty-cart {
    text-align: center;
    padding: 2rem;
    color: #adb5bd;
}

.empty-cart i {
    margin-bottom: 1rem;
}

.cart-item {
    padding: 0.5rem;
    border-bottom: 1px solid #e9ecef;
    margin-bottom: 0.35rem;
    background: #fafafa;
    border-radius: 8px;
}

.cart-item-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.3rem;
}

.cart-item-name {
    font-weight: 600;
    color: #2b2d42;
    font-size: 0.9rem;
    line-height: 1.2;
    flex: 1;
}

.cart-item-subtotal {
    font-weight: 700;
    color: #4361ee;
    font-size: 0.9rem;
}

.cart-item-details {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.35rem;
    margin-top: 0.3rem;
}

.cart-price-mode {
    flex: 1;
}

.cart-price-mode select {
    width: 100%;
    padding: 2px 6px;
    font-size: 0.75rem;
    border-radius: 6px;
    border: 1px solid #dee2e6;
    background: white;
}

.cart-item-actions {
    display: flex;
    align-items: center;
    gap: 0.35rem;
}

.cart-qty-input {
    width: 54px;
    height: 30px;
    border-radius: 8px;
    border: 1px solid #e9ecef;
    text-align: center;
    font-weight: 600;
    outline: none;
    padding: 4px;
}

.cart-qty-input:focus {
    border-color: #4361ee;
    box-shadow: 0 0 0 0.2rem rgba(67, 97, 238, 0.15);
}

.cart-btn {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    border: 1px solid #e9ecef;
    background: white;
    color: #6c757d;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s;
}

.cart-btn:hover {
    background: #4361ee;
    color: white;
    border-color: #4361ee;
}

.cart-btn.delete:hover {
    background: #f72585;
    border-color: #f72585;
}

/* Cart Summary */
.cart-summary {
    background: #f8f9fa;
    padding: 1rem;
    border-radius: 12px;
    margin-bottom: 1rem;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 0.5rem;
    color: #6c757d;
}

.summary-row.discount {
    color: #b91c1c;
    font-weight: 600;
}

.summary-row.total {
    font-weight: 700;
    color: #2b2d42;
    font-size: 1.2rem;
    border-top: 2px solid #dee2e6;
    padding-top: 0.5rem;
    margin-top: 0.5rem;
}

/* Cash Payment Section */
.cash-payment-section {
    margin-bottom: 1rem;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 12px;
}

.cash-payment-section h4 {
    margin-bottom: 1rem;
    font-size: 1rem;
    color: #2b2d42;
}

.amount-input {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.8rem;
}

.amount-input .currency-symbol {
    font-size: 1.2rem;
    font-weight: 600;
    color: #4361ee;
}

.amount-input input {
    flex: 1;
    padding: 0.8rem;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    font-size: 1.1rem;
    appearance: textfield;
    -moz-appearance: textfield;
}

.amount-input input::-webkit-outer-spin-button,
.amount-input input::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}

.quick-amounts {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.quick-amount-btn {
    padding: 0.5rem 1rem;
    background: white;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
    font-weight: 500;
}

.quick-amount-btn:hover {
    background: #4361ee;
    color: white;
    border-color: #4361ee;
}

.change-row {
    display: flex;
    justify-content: space-between;
    padding: 0.8rem;
    background: #e8f5e9;
    border-radius: 8px;
    font-weight: bold;
    color: #000000;
}

.change-row span:last-child {
    font-size: 1.2rem;
}

.discount-input-group {
    margin-top: 0.9rem;
    padding-top: 0.9rem;
    border-top: 1px dashed #cbd5e1;
}

.discount-input-group label {
    display: block;
    margin-bottom: 0.45rem;
    font-weight: 600;
    color: #334155;
}

.discount-input-group .amount-input {
    margin-bottom: 0.45rem;
}

.discount-help {
    color: #64748b;
    font-size: 0.82rem;
    line-height: 1.35;
}

.discount-help strong {
    color: #0f172a;
}

.exchange-credit-banner {
    display: none;
    margin-bottom: 1rem;
    padding: 1rem;
    border: 1px solid #bfdbfe;
    border-radius: 12px;
    background: linear-gradient(135deg, #eff6ff 0%, #f8fbff 100%);
}

.exchange-credit-banner.show {
    display: block;
}

.exchange-credit-banner h4 {
    margin-bottom: 0.8rem;
    font-size: 1rem;
    color: #1d4ed8;
}

.exchange-credit-grid {
    display: grid;
    gap: 0.65rem;
}

.exchange-credit-row {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    font-size: 0.95rem;
}

.exchange-credit-row strong {
    color: #0f172a;
}

.exchange-credit-note {
    margin-top: 0.75rem;
    font-size: 0.85rem;
    color: #475569;
}

/* Customer & Payment Sections */
.customer-section,
.payment-section {
    margin-bottom: 1rem;
    padding: 1rem;
    background: #f8f9fa;
    border-radius: 12px;
}

.customer-section h4,
.payment-section h4 {
    margin-bottom: 1rem;
    font-size: 1rem;
    color: #2b2d42;
}

/* Cart Actions */
.cart-actions {
    display: grid;
    gap: 0.5rem;
}

.btn-block {
    width: 100%;
    padding: 1rem;
    font-size: 1rem;
}

/* Scanner Modal */
.scanner-container {
    position: relative;
    width: 100%;
    overflow: hidden;
    border-radius: 12px;
}

#qr-video {
    width: 100%;
    height: auto;
    border-radius: 12px;
}

.scanner-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    pointer-events: none;
}

.scanner-frame {
    width: 250px;
    height: 250px;
    border: 3px solid #4361ee;
    border-radius: 20px;
    box-shadow: 0 0 0 9999px rgba(0,0,0,0.5);
    margin-bottom: 1rem;
}

.scanner-instruction {
    color: white;
    background: rgba(0,0,0,0.7);
    padding: 0.5rem 1rem;
    border-radius: 20px;
    font-size: 0.9rem;
}

/* Receipt Styles */
.receipt {
    font-family: 'Courier New', monospace;
    max-width: 400px;
    margin: 0 auto;
    padding: 0;
}

.receipt-header {
    text-align: center;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
}

.receipt-table {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
}

.receipt-table thead {
    background-color: #f5f5f5;
}

.receipt-table th {
    padding: 10px 8px;
    text-align: left;
    font-weight: 600;
    font-size: 0.95rem;
    border-bottom: 1px solid #333;
}

.receipt-table td {
    padding: 10px 8px;
    text-align: left;
    border-bottom: 1px dashed #ddd;
    font-size: 0.95rem;
    line-height: 1.4;
    word-break: break-word;
}

.receipt-table .product-cell {
    width: auto;
    flex: 1;
    font-weight: 500;
}

.receipt-table .price-cell {
    width: 80px;
    text-align: right;
}

.receipt-table .qty-cell {
    width: 50px;
    text-align: center;
    font-weight: 600;
}

.receipt-table .total-cell {
    width: 100px;
    text-align: right;
    font-weight: 600;
}

.receipt-table tbody tr:last-child td {
    border-bottom: 1px solid #999;
}

.receipt-totals {
    margin-top: 1.5rem;
    padding: 1rem 0;
}

.total-row {
    display: flex;
    justify-content: space-between;
    margin: 0.75rem 0;
    padding: 0.5rem 0;
    font-size: 1rem;
}

.grand-total {
    font-weight: bold;
    font-size: 1.15rem;
    color: #333;
}

.payment-info {
    margin-top: 1.5rem;
    font-size: 0.95rem;
    padding: 1rem 0;
}

.payment-row {
    display: flex;
    justify-content: space-between;
    margin: 0.5rem 0;
    padding: 0.3rem 0;
}

.receipt-footer {
    text-align: center;
    margin-top: 2rem;
    padding-top: 1.5rem;
    border-top: 1px dashed #999;
    font-weight: bold;
    font-size: 1rem;
}

/* Product Selection Modal */
.product-select-grid {
    display: grid;
    gap: 0.5rem;
}

.product-select-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.3s;
}

.product-select-item:hover {
    background: #f8f9fa;
    border-color: #4361ee;
}

.product-select-info {
    display: flex;
    flex-direction: column;
}

.product-select-info small {
    color: #6c757d;
    font-size: 0.8rem;
}

/* Notifications */
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

.notification-warning {
    border-left: 4px solid #ffb703;
}

.notification-info {
    border-left: 4px solid #4361ee;
}

.notification-content {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 1;
}

.notification-close {
    background: none;
    border: none;
    color: #adb5bd;
    cursor: pointer;
    font-size: 1.2rem;
    padding: 0;
}

/* Notification Panel (team chat) */
.notif-panel {
    position: fixed;
    top: 68px;
    right: 20px;
    width: 340px;
    max-width: 92vw;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.18);
    border: 1px solid #e5e7eb;
    display: none;
    flex-direction: column;
    overflow: hidden;
    z-index: 9998;
}
.notif-panel.show { display: flex; }
.notif-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 14px;
    background: linear-gradient(135deg, #4361ee, #4895ef);
    color: #fff;
    font-weight: 600;
}
.notif-close {
    background: none;
    border: none;
    color: #fff;
    font-size: 1.1rem;
    cursor: pointer;
}
.notif-list {
    max-height: 340px;
    overflow-y: auto;
    padding: 10px 12px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.notif-item {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 8px 10px;
    background: #f9fafb;
}
.notif-item.unread { border-color: #4361ee; background: #eef2ff; }
.notif-meta {
    font-size: 11px;
    color: #6b7280;
    display: flex;
    justify-content: space-between;
    gap: 6px;
    margin-bottom: 4px;
}
.notif-text { font-size: 13px; color: #111827; white-space: pre-wrap; }
.notif-form {
    border-top: 1px solid #e5e7eb;
    padding: 10px 12px;
    display: grid;
    grid-template-columns: 1fr;
    gap: 8px;
}
.notif-form-row {
    display: grid;
    grid-template-columns: 1fr 90px;
    gap: 8px;
}
.notif-form select,
.notif-form input {
    width: 100%;
}
.notif-form button {
    justify-self: flex-end;
}
.notif-empty {
    text-align: center;
    color: #6b7280;
    font-size: 13px;
    padding: 12px 6px;
}

/* Balance Lookup */
.balance-results {
    max-height: 320px;
    overflow-y: auto;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    padding: 8px;
    background: #f9fafb;
}
.balance-results table {
    width: 100%;
    border-collapse: collapse;
}
.balance-results th,
.balance-results td {
    padding: 6px 4px;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.9rem;
}
.balance-results tr:last-child td {
    border-bottom: none;
}
.balance-total {
    font-size: 0.95rem;
}
.customer-balance-hint {
    display: none;
    margin-top: 10px;
    padding: 10px 12px;
    border: 1px solid #dbeafe;
    border-radius: 10px;
    background: #eff6ff;
}
.customer-balance-hint.show {
    display: block;
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

/* Search Box */
.search-box {
    position: relative;
}

.search-icon {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: #adb5bd;
    width: 16px;
    text-align: center;
    pointer-events: none;
}

.search-box input {
    padding-left: 35px;
}

/* Responsive */
@media (max-width: 992px) {
    .pos-container {
        grid-template-columns: 1fr;
    }
    
    .cart-section {
        position: static;
        height: auto;
    }
    
    .cart-resize-handle {
        display: none;
    }
}

@media (max-width: 768px) {
    .products-grid {
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    }
    
    .card-header .d-flex {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .search-box {
        width: 100%;
    }
    
    #pos-search {
        width: 100% !important;
    }
}

/* Loading Spinner */
.loading-spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid #f3f3f3;
    border-top: 3px solid #4361ee;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Badge Styles */
.badge-cash {
    background: #4361ee;
    color: white;
    padding: 0.3rem 0.8rem;
    border-radius: 12px;
    font-size: 0.75rem;
}

.badge-card {
    background: #06d6a0;
    color: white;
    padding: 0.3rem 0.8rem;
    border-radius: 12px;
    font-size: 0.75rem;
}

.badge-online {
    background: #ffb703;
    color: white;
    padding: 0.3rem 0.8rem;
    border-radius: 12px;
    font-size: 0.75rem;
}

.image-preview-modal .modal-dialog {
    max-width: 900px;
}

.image-preview-modal .modal-body {
    padding: 1rem;
    text-align: center;
    background: #0f172a;
}

.image-preview-modal img {
    max-width: 100%;
    max-height: 75vh;
    object-fit: contain;
    border-radius: 12px;
}

.image-preview-caption {
    margin-top: 0.9rem;
    color: #e2e8f0;
    font-weight: 600;
}
</style>

<div class="pos-container">
    <!-- Products Grid Section -->
    <div class="products-section">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-between align-center flex-wrap">
                    <h3><i class="fas fa-box mr-2"></i> Products</h3>
                    <div class="d-flex gap-2 flex-wrap">
                        <div class="search-box">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" id="pos-search" class="form-control" placeholder="Search products..." style="width: 250px;">
                        </div>
                        <select id="pos-category" class="form-control" style="width: 180px;">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <?php $catName = is_array($cat) ? $cat['name'] : $cat; ?>
                                <option value="<?php echo htmlspecialchars($catName); ?>">
                                    <?php echo htmlspecialchars($catName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary" id="scan-qr-btn">
                            <i class="fas fa-barcode"></i> Scan Code
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="products-grid" id="products-grid">
                    <!-- Products loaded via PHP -->
                    <?php if (empty($products)): ?>
                        <div class="no-products-message">
                            <i class="fas fa-box-open"></i>
                            <h4>No products available</h4>
                            <p>There are no products in stock at the moment.</p>
                            <a href="/pos-system/views/products/add.php" class="btn btn-primary mt-3">
                                <i class="fas fa-plus mr-2"></i>Add Product
                            </a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($products as $p): ?>
                        <div class="product-card <?php echo ((int)($p['available'] ?? $p['quantity']) <= 0) ? 'out-of-stock' : ''; ?>" 
                            data-id="<?php echo $p['id']; ?>"
                            data-name="<?php echo htmlspecialchars($p['name']); ?>"
                            data-price="<?php echo $p['price']; ?>"
                            data-retail-price="<?php echo $p['price']; ?>"
                            data-wholesale-price="<?php echo $p['wholesale_price'] ?? ''; ?>"
                            data-cost-price="<?php echo $p['cost_price'] ?? ''; ?>"
                            data-stock="<?php echo $p['quantity']; ?>"
                            data-reserved="<?php echo (int)($p['reserved'] ?? 0); ?>"
                            data-available="<?php echo (int)($p['available'] ?? $p['quantity']); ?>"
                            data-code="<?php echo $p['product_code']; ?>"
                            data-category="<?php echo htmlspecialchars($p['category'] ?? ''); ?>">
                            <div class="product-image">
                                <?php if (!empty($p['image_path'])): ?>
                                    <img src="<?php echo htmlspecialchars(productImageUrl($p['image_path'])); ?>" alt="<?php echo htmlspecialchars($p['name']); ?>" loading="lazy" decoding="async">
                                    <button
                                        type="button"
                                        class="product-image-zoom"
                                        data-image-src="<?php echo htmlspecialchars(productImageUrl($p['image_path'])); ?>"
                                        data-product-name="<?php echo htmlspecialchars($p['name']); ?>"
                                        onclick="event.stopPropagation(); openProductImageModal(this.dataset.imageSrc, this.dataset.productName);"
                                        aria-label="Zoom product image"
                                        title="Zoom image"
                                    >
                                        <i class="fas fa-search-plus"></i>
                                    </button>
                                <?php else: ?>
                                    <div class="product-placeholder">
                                        <i class="fas fa-box"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="product-info">
                                <div class="product-name"><?php echo htmlspecialchars($p['name']); ?></div>
                                <div class="product-price">₱<?php echo number_format($p['price'], 2); ?></div>
                                <div class="product-stock">
                                    <div class="product-stock-total"><i class="fas fa-cubes mr-1"></i> Stock: <?php echo $p['quantity']; ?></div>
                                    <div class="product-stock-reserved" style="color:#6b7280;">Reserved: <?php echo (int)($p['reserved'] ?? 0); ?></div>
                                    <div class="product-stock-available <?php echo $p['available'] <= $criticalStockThreshold ? 'stock-low' : ($p['available'] <= $lowStockThreshold ? 'stock-warning' : ''); ?>">Available: <?php echo (int)($p['available'] ?? $p['quantity']); ?></div>
                                </div>
                                <?php if ((int)$p['available'] <= $lowStockThreshold): ?>
                                    <div class="product-stock-badge <?php echo (int)$p['available'] <= $criticalStockThreshold ? 'danger' : 'warning'; ?>">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <?php echo (int)$p['available'] <= $criticalStockThreshold
                                            ? 'Critical available: only ' . (int)$p['available'] . ' left'
                                            : 'Only ' . (int)$p['available'] . ' available'; ?>
                                    </div>
                                <?php endif; ?>
                                <small class="text-muted"><?php echo $p['product_code']; ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Shopping Cart Section -->
    <div class="cart-section" id="cart-section">
        <div class="cart-resize-handle" id="cart-resize-handle"></div>
        <div class="cart-container">
            <div class="cart-header">
                <h3><i class="fas fa-shopping-cart mr-2"></i> Shopping Cart</h3>
                <div style="display:flex; align-items:center; gap:0.5rem;">
                    <a href="/pos-system/views/sales/history.php" class="btn btn-outline-primary btn-sm" style="white-space: nowrap;">
                        <i class="fas fa-undo-alt mr-1"></i> RETURN REFUND
                    </a>
                    <select id="price-mode" class="form-control form-control-sm" style="width: 140px;" aria-label="Select price mode">
                        <option value="retail" selected>Retail</option>
                        <option value="wholesale">Wholesale</option>
                        <option value="cost">Cost</option>
                    </select>
                    <span class="cart-badge" id="cart-count">0</span>
                </div>
            </div>
            
            <!-- Scanner Input (for barcode scanners) -->
            <div class="scanner-input-group">
                <div class="scanner-input-wrap">
                    <i class="fas fa-barcode"></i>
                    <input type="text" id="scan-input" class="form-control" 
                           placeholder="Scan barcode or QR code..." autofocus>
                </div>
                <small class="text-muted">Focus here and scan with USB scanner</small>
                <div class="form-check mt-2">
                    <input type="checkbox" class="form-check-input" id="auto-complete-sale">
                    <label class="form-check-label" for="auto-complete-sale">Auto-complete sale after scan</label>
                </div>
            </div>
            
            <!-- Cart Items -->
            <div class="cart-items" id="cart-items">
                <div class="empty-cart">
                    <i class="fas fa-shopping-cart fa-3x"></i>
                    <p>Your cart is empty</p>
                    <small>Click on products or scan barcode to add them</small>
                </div>
            </div>
            
            <!-- Cart Summary -->
            <div class="cart-summary">
                <div class="summary-row">
                    <span>Subtotal:</span>
                    <span id="cart-subtotal">₱0.00</span>
                </div>
                <div class="summary-row discount">
                    <span>Retail Discount:</span>
                    <span id="cart-discount">₱0.00</span>
                </div>
                <div class="summary-row" id="tax-row" style="display:none">
                    <span>Tax:</span>
                    <span id="cart-tax">₱0.00</span>
                </div>
                <div class="summary-row total">
                    <span>Total:</span>
                    <span id="cart-total">₱0.00</span>
                </div>
            </div>
            
            <!-- Cash Payment Section (for cash payments) -->
            <div class="cash-payment-section" id="cash-payment-section">
                <h4><i class="fas fa-money-bill-wave"></i> Cash Payment</h4>
                <div class="amount-input">
                    <span class="currency-symbol">₱</span>
                    <input type="number" id="cash-amount" class="form-control" placeholder="Enter cash amount" min="0" step="0.01" value="0.00">
                </div>
                <div class="quick-amounts">
                    <button class="quick-amount-btn" onclick="setCashAmount(100)">₱100</button>
                    <button class="quick-amount-btn" onclick="setCashAmount(200)">₱200</button>
                    <button class="quick-amount-btn" onclick="setCashAmount(500)">₱500</button>
                    <button class="quick-amount-btn" onclick="setCashAmount(1000)">₱1000</button>
                    <button class="quick-amount-btn" onclick="setExactAmount()">Exact</button>
                    <button class="quick-amount-btn" onclick="clearCashAmount()">Clear Amount</button>
                    <button class="quick-amount-btn" onclick="openBalanceModal()" id="balance-btn">Balance</button>
                </div>
                <div class="change-row" id="change-display">
                    <span style="font-weight: bold; color: #000000;">Change:</span>
                    <span id="change-amount" style="font-weight: bold; color: #000000;">₱0.00</span>
                </div>
                <div class="discount-input-group">
                    <label for="retail-discount">Customer Discount (%)</label>
                    <div class="amount-input">
                        <span class="currency-symbol">%</span>
                        <input type="number" id="retail-discount" class="form-control" placeholder="Enter discount percent" min="0" max="100" step="0.01" value="0.00">
                    </div>
                    <div class="discount-help" id="retail-discount-help">
                        Available only for retail-priced items.
                    </div>
                </div>
            </div>

            <div class="exchange-credit-banner" id="exchange-credit-banner">
                <h4><i class="fas fa-exchange-alt mr-2"></i> Exchange In Progress</h4>
                <div class="exchange-credit-grid">
                    <div class="exchange-credit-row">
                        <span>Original Sale</span>
                        <strong id="exchange-credit-source">N/A</strong>
                    </div>
                    <div class="exchange-credit-row">
                        <span>Returned Items</span>
                        <strong id="exchange-credit-items">N/A</strong>
                    </div>
                    <div class="exchange-credit-row">
                        <span>Exchange Credit</span>
                        <strong id="exchange-credit-amount">0.00</strong>
                    </div>
                    <div class="exchange-credit-row">
                        <span>Current Cart Total</span>
                        <strong id="exchange-cart-total">0.00</strong>
                    </div>
                    <div class="exchange-credit-row">
                        <span>Settlement</span>
                        <strong id="exchange-credit-status">Add replacement items</strong>
                    </div>
                </div>
                <div class="exchange-credit-note" id="exchange-credit-note">
                    The cash field starts with the exchange credit. If the customer adds money, enter the new total tendered in the same field.
                </div>
            </div>
            
            <!-- Customer Information -->
            <div class="customer-section">
                <h4><i class="fas fa-user mr-2"></i> Customer Information</h4>
                <div class="form-group">
                    <input type="text" id="customer-name" class="form-control" placeholder="Customer Name">
                </div>
                <div class="form-group">
                    <input type="address" id="customer-address" class="form-control" placeholder="Customer Address">
                </div>
                <div class="form-group">
                    <input type="tel" id="customer-phone" class="form-control" placeholder="Customer Phone">
                </div>
                <div id="customer-balance-hint" class="customer-balance-hint">
                    <div id="customer-balance-hint-text" style="font-weight:700; color:#1e3a8a;"></div>
                    <div id="customer-balance-hint-subtext" style="font-size:0.9rem; color:#475569; margin-top:4px;"></div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:10px;">
                        <button type="button" class="btn btn-warning btn-sm" onclick="addInlineCustomerBalance()">
                            <i class="fas fa-plus-circle mr-1"></i> Add Remaining Balance
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="dismissInlineCustomerBalance()">
                            Proceed Without Balance
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Payment Section -->
            <div class="payment-section">
                <h4><i class="fas fa-credit-card mr-2"></i> Payment Method</h4>
                <div class="form-group">
                    <select id="payment-method" class="form-control" onchange="toggleCashPayment()">
                        <option value="cash">Cash</option>
                        <option value="card">Credit/Debit Card</option>
                        <option value="online">Online Payment</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="teller-name" style="display:block;margin-bottom:6px;font-weight:600;">
                        Salesman <span style="color:#b91c1c;">*</span>
                    </label>
                    <input type="text" id="teller-name" class="form-control" placeholder="Enter salesman's name" required>
                    <small class="text-muted" style="display:block;margin-top:6px;">
                        Required to complete the transaction.
                    </small>
                </div>
                <div class="form-group">
                    <label for="sale-notes" id="sale-notes-label" style="display:block;margin-bottom:6px;font-weight:600;">
                        Receipt Note
                    </label>
                    <textarea id="sale-notes" class="form-control" placeholder="Notes (optional)" rows="2"></textarea>
                    <small id="sale-notes-help" class="text-muted" style="display:block;margin-top:6px;">
                        Optional receipt note.
                    </small>
                </div>
            </div>
            
            <!-- Actions -->
            <div class="cart-actions">
                <button class="btn btn-primary btn-block" onclick="saveOrder()" id="save-order">
                    <i class="fas fa-receipt mr-2"></i> Order
                </button>
                <button class="btn btn-success btn-block" onclick="processSale()" id="process-sale">
                    <i class="fas fa-check mr-2"></i> Complete Sale
                </button>
                <button class="btn btn-danger btn-block" onclick="clearCart()" id="clear-cart">
                    <i class="fas fa-trash mr-2"></i> Clear Cart
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Product Image Preview Modal -->
<div class="modal image-preview-modal" id="productImageModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-image mr-2"></i> Product Image
                </h5>
                <button type="button" class="modal-close" onclick="closeModal('productImageModal')">&times;</button>
            </div>
            <div class="modal-body">
                <img id="product-image-preview" src="" alt="Product preview">
                <div class="image-preview-caption" id="product-image-caption"></div>
            </div>
        </div>
    </div>
</div>

<!-- QR Scanner Modal -->
<div class="modal" id="scannerModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-qrcode mr-2"></i> Scan QR Code
                </h5>
                <button type="button" class="modal-close" onclick="closeScanner()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="scanner-container">
                    <video id="qr-video" autoplay playsinline></video>
                    <div class="scanner-overlay">
                        <div class="scanner-frame"></div>
                        <p class="scanner-instruction">Position barcode or QR within the frame</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeScanner()">
                    <i class="fas fa-times mr-2"></i> Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal" id="receiptModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-receipt mr-2"></i> Sale Receipt
                </h5>
                <button type="button" class="modal-close" onclick="closeModal('receiptModal')">&times;</button>
            </div>
            <div class="modal-body" id="receipt-content">
                <!-- Receipt content will appear here -->
            </div>
            <div class="modal-footer" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <div style="display:flex;align-items:center;gap:8px;flex:1 1 260px;min-width:240px;">
                    <label for="printer-profile" style="margin:0;font-weight:600;">Printer</label>
                    <select id="printer-profile" class="form-control" style="min-width:220px;">
                        <option value="auto">Browser default / 58mm roll</option>
                        <option value="epson-c6">Epsonf941d2 - C6 envelope (114 x 162 mm)</option>
                    </select>
                </div>
                <div style="display:flex;gap:8px;flex:0 0 auto;">
                    <button class="btn btn-primary" onclick="printReceipt()">
                        <i class="fas fa-print mr-2"></i> Print
                    </button>
                    <button class="btn btn-secondary" onclick="closeModal('receiptModal')">
                        <i class="fas fa-times mr-2"></i> Close
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Balance Lookup Modal -->
<div class="modal" id="balanceModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-wallet mr-2"></i> Customer Balance
                </h5>
                <button type="button" class="modal-close" onclick="closeModal('balanceModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-row" style="display:flex; gap:10px; flex-wrap:wrap;">
                    <div class="form-group" style="flex:1 1 200px;">
                        <label for="balance-search-name" style="font-weight:600;">Customer Name</label>
                        <input type="text" id="balance-search-name" class="form-control" placeholder="Type customer name">
                    </div>
                    <div class="form-group" style="flex:1 1 200px;">
                        <label for="balance-search-phone" style="font-weight:600;">Phone</label>
                        <input type="tel" id="balance-search-phone" class="form-control" placeholder="Type phone number">
                    </div>
                </div>
                <div style="display:flex; gap:8px; margin-bottom:12px; flex-wrap:wrap;">
                    <button class="btn btn-primary btn-sm" onclick="searchCustomerBalance()">
                        <i class="fas fa-search mr-1"></i> Search
                    </button>
                    <button class="btn btn-secondary btn-sm" onclick="resetBalanceSearch()">
                        <i class="fas fa-undo mr-1"></i> Clear
                    </button>
                </div>
                <div id="balance-results" class="balance-results text-muted" style="min-height:80px;">
                    Start typing a name or phone to see outstanding balances.
                </div>
                <div id="balance-selected-summary" class="balance-total" style="margin-top:10px;"></div>
                <div id="balance-total" class="balance-total" style="margin-top:10px; text-align:right; font-weight:700;"></div>
            </div>
            <div class="modal-footer" style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
                <button class="btn btn-success" onclick="markBalanceAsPaid()">
                    <i class="fas fa-check mr-1"></i> Mark as Paid
                </button>
                <button class="btn btn-primary" onclick="printSelectedBalanceReceipt()">
                    <i class="fas fa-print mr-1"></i> Print Receipt
                </button>
                <button class="btn btn-warning" onclick="addSelectedBalanceToCart()">
                    <i class="fas fa-plus-circle mr-1"></i> Add Balance
                </button>
                <button class="btn btn-outline-primary" onclick="startNewTransactionFromBalance()">
                    <i class="fas fa-plus mr-1"></i> Add New Transaction
                </button>
                <button class="btn btn-secondary" onclick="closeModal('balanceModal')">
                    <i class="fas fa-times mr-1"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Product Selection Modal (for multiple scan results) -->
<div class="modal" id="productSelectModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-box mr-2"></i> Select Product
                </h5>
                <button type="button" class="modal-close" onclick="closeModal('productSelectModal')">&times;</button>
            </div>
            <div class="modal-body" id="product-select-list">
                <!-- Products will be listed here -->
            </div>
        </div>
    </div>
</div>

<script>
// ==================== Global Variables ====================
let cart = [];
let scannerActive = false;
let zxingReader = null;
let zxingControls = null;
const API_BASE_URL = '/pos-system/api';
const PRINTER_PROFILE_KEY = 'pos_printer_profile';
// Price mode selector (retail/wholesale/cost)
let priceMode = 'retail';
let productCardCache = [];
let productSearchTerm = '';
let productCategoryFilter = '';
let currentOrderId = <?php echo isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0; ?>;
let currentOrderNumber = '';
let balanceSearchResults = [];
let selectedBalanceIds = new Set();
let inlineCustomerBalanceResults = [];
let inlineCustomerBalanceDismissedKey = '';
let currentExchangeContext = <?php echo $exchangeSaleContextJson ?: 'null'; ?>;
let exchangeItemsLoaded = false;
const LOW_STOCK_THRESHOLD = <?php echo (int)$lowStockThreshold; ?>;
const CRITICAL_STOCK_THRESHOLD = <?php echo (int)$criticalStockThreshold; ?>;

// Helper function to get actual available stock (total - reserved)
function getActualAvailableStock(productId) {
    const productCard = document.querySelector(`.product-card[data-id="${productId}"]`);
    if (!productCard) return 0;
    const totalStock = parseInt(productCard.dataset.stock, 10) || 0;
    const reserved = parseInt(productCard.dataset.reserved, 10) || 0;
    return totalStock - reserved;
}

// ==================== Helper Functions ====================
function formatCurrency(amount) {
    return '₱' + parseFloat(amount).toFixed(2);
}

function toNumber(v) {
    if (typeof v === 'number') return Number.isFinite(v) ? v : 0;
    if (v === null || v === undefined) return 0;
    const normalized = String(v).replace(/[^0-9.-]/g, '');
    const n = parseFloat(normalized);
    return Number.isFinite(n) ? n : 0;
}

function parseOptionalPrice(v) {
    if (v === null || v === undefined || String(v).trim() === '') return NaN;
    const normalized = String(v).replace(/[^0-9.-]/g, '');
    const n = parseFloat(normalized);
    return Number.isFinite(n) ? n : NaN;
}

function getPriceMode() {
    const el = document.getElementById('price-mode');
    const v = el ? String(el.value || '') : '';
    if (v === 'retail' || v === 'wholesale' || v === 'cost') return v;
    return 'retail';
}

function priceModeLabel(mode) {
    if (mode === 'wholesale') return 'Wholesale';
    if (mode === 'cost') return 'Cost';
    return 'Retail';
}

function selectUnitPrice(retail, wholesale, cost) {
    const r = toNumber(retail);
    const w = parseOptionalPrice(wholesale);
    const c = parseOptionalPrice(cost);

    if (priceMode === 'wholesale' && Number.isFinite(w)) return w;
    if (priceMode === 'cost' && Number.isFinite(c)) return c;
    return r;
}

function setCartItemPrice(item) {
    if (item.fixed_price === true) {
        item.price = toNumber(item.fixed_unit_price);
        item.subtotal = toNumber(item.quantity) * toNumber(item.price);
        if (!item.price_mode) {
            item.price_mode = 'retail';
        }
        item.price_mode_label = item.price_mode_label || priceModeLabel(item.price_mode);
        return;
    }

    // If user manually chose a mode on this item, keep it; otherwise follow global priceMode
    const mode = item.price_mode_locked ? (item.price_mode || 'retail') : priceMode;
    item.price_mode = mode;
    item.price_mode_label = priceModeLabel(mode);

    const retail = toNumber(item.retail_price);
    const wholesale = parseOptionalPrice(item.wholesale_price);
    const cost = parseOptionalPrice(item.cost_price);

    if (mode === 'wholesale') {
        item.price = Number.isFinite(wholesale) && wholesale > 0 ? wholesale : retail;
    } else if (mode === 'cost') {
        item.price = Number.isFinite(cost) && cost > 0 ? cost : retail;
    } else {
        item.price = retail;
    }

    item.subtotal = toNumber(item.quantity) * toNumber(item.price);
}

function isRetailDiscountEligibleItem(item) {
    if (!item || item.is_balance_item === true) {
        return false;
    }

    const mode = String(item.price_mode || 'retail').toLowerCase();
    return mode === 'retail';
}

function formatDiscountPercent(percent) {
    const normalized = Math.max(0, toNumber(percent));
    return normalized.toFixed(2).replace(/\.00$/, '').replace(/(\.\d)0$/, '$1') + '%';
}

function getRetailDiscountInputValue() {
    const input = document.getElementById('retail-discount');
    const value = input ? toNumber(input.value) : 0;
    return Math.min(100, Math.max(0, value));
}

function getRetailDiscountEligibleSubtotal() {
    return cart.reduce((sum, item) => {
        if (!isRetailDiscountEligibleItem(item)) {
            return sum;
        }
        return sum + toNumber(item.subtotal);
    }, 0);
}

function syncRetailDiscountControls() {
    const input = document.getElementById('retail-discount');
    const help = document.getElementById('retail-discount-help');

    if (!input || !help) {
        return {
            eligible: 0,
            percent: 0,
            applied: 0
        };
    }

    const eligible = Math.max(0, getRetailDiscountEligibleSubtotal());
    let requestedPercent = getRetailDiscountInputValue();

    if (eligible <= 0.0001) {
        requestedPercent = 0;
        input.value = '0.00';
        input.disabled = true;
        help.textContent = 'Discount is available only for retail-priced items.';
    } else {
        input.value = requestedPercent.toFixed(2);
        input.disabled = false;
        const appliedDiscount = Math.round((eligible * (requestedPercent / 100)) * 100) / 100;
        help.innerHTML = `Applies only to retail-priced items. Eligible retail amount: <strong>${formatCurrency(eligible)}</strong>. Current discount: <strong>${formatDiscountPercent(requestedPercent)}</strong> = <strong>${formatCurrency(appliedDiscount)}</strong>.`;
    }

    const appliedDiscount = Math.round((eligible * (requestedPercent / 100)) * 100) / 100;

    return {
        eligible,
        percent: requestedPercent,
        applied: appliedDiscount
    };
}

function getAppliedRetailDiscountAmount() {
    return syncRetailDiscountControls().applied;
}

function getAppliedRetailDiscountPercent() {
    return syncRetailDiscountControls().percent;
}

function refreshCartPrices() {
    cart.forEach((item) => setCartItemPrice(item));
    updateCartDisplay();
}

function updateProductGridPrices() {
    if (!productCardCache.length) {
        buildProductCardCache();
    }
    productCardCache.forEach((entry) => {
        const card = entry.card;
        const retail = card.dataset.retailPrice ?? card.dataset.price ?? 0;
        const wholesale = card.dataset.wholesalePrice ?? 0;
        const cost = card.dataset.costPrice ?? 0;
        const unit = selectUnitPrice(retail, wholesale, cost);
        if (entry.priceEl) {
            entry.priceEl.textContent = formatCurrency(unit);
        }
    });
}

function escapeHtml(text) {
    if (!text) return text;
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function buildProductCardCache() {
    productCardCache = Array.from(document.querySelectorAll('.product-card')).map((card) => ({
        card,
        priceEl: card.querySelector('.product-price'),
        nameLower: String(card.dataset.name || '').toLowerCase(),
        codeLower: String(card.dataset.code || '').toLowerCase(),
        category: String(card.dataset.category || '')
    }));
}

function applyProductCardFilters() {
    const search = productSearchTerm.trim().toLowerCase();
    const category = productCategoryFilter;

    productCardCache.forEach((entry) => {
        const matchSearch = !search || entry.nameLower.includes(search) || entry.codeLower.includes(search);
        const matchCategory = !category || entry.category === category;
        entry.card.style.display = (matchSearch && matchCategory) ? 'block' : 'none';
    });
}

function setProductCardStock(productId, stock) {
    const card = document.querySelector(`.product-card[data-id="${productId}"]`);
    if (!card) return;

    const currentAvailable = Math.max(parseInt(stock, 10) || 0, 0);
    card.dataset.available = String(currentAvailable);
    // mark out of stock if available is zero
    card.classList.toggle('out-of-stock', currentAvailable <= 0);

    const totalStock = parseInt(card.dataset.stock, 10) || currentAvailable;
    const reserved = parseInt(card.dataset.reserved, 10) || 0;

    const totalEl = card.querySelector('.product-stock-total');
    if (totalEl) totalEl.innerHTML = `<i class="fas fa-cubes mr-1"></i> Stock: ${totalStock}`;

    const reservedEl = card.querySelector('.product-stock-reserved');
    if (reservedEl) reservedEl.innerText = `Reserved: ${reserved}`;

    const availEl = card.querySelector('.product-stock-available');
    if (availEl) {
        availEl.innerText = `Available: ${currentAvailable}`;
        availEl.classList.toggle('stock-low', currentAvailable <= CRITICAL_STOCK_THRESHOLD);
        availEl.classList.toggle('stock-warning', currentAvailable > CRITICAL_STOCK_THRESHOLD && currentAvailable <= LOW_STOCK_THRESHOLD);
        availEl.classList.toggle('stock-out', currentAvailable <= 0);
    }

    let badge = card.querySelector('.product-stock-badge');
    if (currentAvailable <= LOW_STOCK_THRESHOLD && currentAvailable > 0) {
        if (!badge) {
            badge = document.createElement('div');
            badge.className = 'product-stock-badge';
            const productInfo = card.querySelector('.product-info');
            const codeEl = productInfo ? productInfo.querySelector('.text-muted') : null;
            if (productInfo && codeEl) {
                productInfo.insertBefore(badge, codeEl);
            } else if (productInfo) {
                productInfo.appendChild(badge);
            }
        }
        const isCritical = currentAvailable <= CRITICAL_STOCK_THRESHOLD;
        badge.className = `product-stock-badge ${isCritical ? 'danger' : 'warning'}`;
        badge.innerHTML = `<i class="fas fa-exclamation-triangle"></i> ${isCritical ? `Critical available: only ${currentAvailable} left` : `Only ${currentAvailable} available`}`;
    } else if (badge) {
        badge.remove();
    }
}

function decrementProductStocksLocally(items) {
    const soldByProduct = new Map();
    (items || []).forEach((item) => {
        const productId = parseInt(item.product_id, 10) || 0;
        const quantity = parseInt(item.quantity, 10) || 0;
        if (productId <= 0 || quantity <= 0 || item.is_balance_item === true) return;
        soldByProduct.set(productId, (soldByProduct.get(productId) || 0) + quantity);
    });

    soldByProduct.forEach((quantity, productId) => {
        const card = document.querySelector(`.product-card[data-id="${productId}"]`);
        if (!card) return;
        const currentAvailable = parseInt(card.dataset.available, 10) || (parseInt(card.dataset.stock, 10) || 0);
        setProductCardStock(productId, currentAvailable - quantity);
    });
}

function restoreProductStocksForItems(items) {
    const restoredByProduct = new Map();
    (items || []).forEach((item) => {
        const productId = parseInt(item.product_id, 10) || 0;
        const quantity = parseInt(item.quantity, 10) || 0;
        if (productId <= 0 || quantity <= 0 || item.is_balance_item === true) return;
        restoredByProduct.set(productId, (restoredByProduct.get(productId) || 0) + quantity);
    });

    restoredByProduct.forEach((quantity, productId) => {
        const card = document.querySelector(`.product-card[data-id="${productId}"]`);
        if (!card) return;
        const currentAvailable = parseInt(card.dataset.available, 10) || (parseInt(card.dataset.stock, 10) || 0);
        setProductCardStock(productId, currentAvailable + quantity);
    });

    buildProductCardCache();
    applyProductCardFilters();
}

async function refreshProductStocksForItems(items) {
    const productIds = Array.from(new Set((items || [])
        .filter(item => item && item.is_balance_item !== true)
        .map(item => parseInt(item.product_id, 10) || 0)
        .filter(productId => productId > 0)));

    if (!productIds.length) return;

    try {
        await Promise.all(productIds.map(async (productId) => {
            const response = await fetch(`${API_BASE_URL}/products/get.php?id=${encodeURIComponent(productId)}`);
            const result = await response.json();
            if (!result.success || !result.product) {
                throw new Error(result.message || `Unable to refresh product #${productId}`);
            }
            // compute available = total quantity - reserved (reserved may be present in card dataset)
            const card = document.querySelector(`.product-card[data-id="${productId}"]`);
            const reserved = card ? (parseInt(card.dataset.reserved, 10) || 0) : 0;
            const available = Math.max((parseInt(result.product.quantity, 10) || 0) - reserved, 0);
            setProductCardStock(productId, available);
        }));
        buildProductCardCache();
        applyProductCardFilters();
    } catch (error) {
        console.warn('Product stock refresh failed, using local stock update:', error);
        decrementProductStocksLocally(items);
        buildProductCardCache();
        applyProductCardFilters();
    }
}

function hasExchangeContext() {
    return currentOrderId <= 0 && currentExchangeContext && typeof currentExchangeContext === 'object';
}

function getExchangeCreditAmount() {
    return hasExchangeContext() ? toNumber(currentExchangeContext.exchange_credit_amount) : 0;
}

function summarizeExchangeItems(items) {
    if (!Array.isArray(items) || items.length === 0) {
        return 'N/A';
    }

    const parts = items
        .map((item) => {
            const qty = parseInt(item?.quantity, 10) || 0;
            const name = String(item?.product_name || '').trim();
            if (qty <= 0 || name === '') {
                return '';
            }
            return `${qty} x ${name}`;
        })
        .filter(Boolean);

    if (!parts.length) {
        return 'N/A';
    }

    if (parts.length <= 3) {
        return parts.join(', ');
    }

    return `${parts.slice(0, 3).join(', ')} +${parts.length - 3} more`;
}

function clearExchangeQueryParam() {
    try {
        const url = new URL(window.location.href);
        if (!url.searchParams.has('exchange')) {
            return;
        }
        url.searchParams.delete('exchange');
        const nextUrl = url.pathname + (url.searchParams.toString() ? `?${url.searchParams.toString()}` : '') + url.hash;
        window.history.replaceState({}, document.title, nextUrl);
    } catch (error) {
        console.warn('Unable to clear exchange query parameter:', error);
    }
}

function renderExchangeCreditBanner() {
    const banner = document.getElementById('exchange-credit-banner');
    const sourceEl = document.getElementById('exchange-credit-source');
    const itemsEl = document.getElementById('exchange-credit-items');
    const amountEl = document.getElementById('exchange-credit-amount');
    const cartTotalEl = document.getElementById('exchange-cart-total');
    const statusEl = document.getElementById('exchange-credit-status');
    const noteEl = document.getElementById('exchange-credit-note');

    if (!banner || !sourceEl || !itemsEl || !amountEl || !cartTotalEl || !statusEl || !noteEl) {
        return;
    }

    if (!hasExchangeContext()) {
        banner.classList.remove('show');
        return;
    }

    const creditAmount = getExchangeCreditAmount();
    const currentSubtotal = cart.reduce((sum, item) => sum + toNumber(item.subtotal), 0);
    const currentTotal = Math.max(currentSubtotal - getAppliedRetailDiscountAmount(), 0);
    const difference = creditAmount - currentTotal;
    const invoiceRef = String(currentExchangeContext.source_invoice_number || '').trim();
    const sourceLabel = invoiceRef !== ''
        ? invoiceRef
        : `Sale #${parseInt(currentExchangeContext.sale_id, 10) || 'N/A'}`;

    let settlementMessage = 'Add replacement items to continue this exchange.';
    if (currentTotal > 0) {
        if (Math.abs(difference) < 0.005) {
            settlementMessage = 'No extra payment needed.';
        } else if (difference > 0) {
            settlementMessage = `Return ${formatCurrency(difference)} to the customer or add more items.`;
        } else {
            settlementMessage = `Collect ${formatCurrency(Math.abs(difference))} more from the customer.`;
        }
    }

    const originalCashAmount = toNumber(currentExchangeContext.original_cash_amount);
    const noteParts = [];
    if (originalCashAmount > 0) {
        noteParts.push(`Original cash received: ${formatCurrency(originalCashAmount)}.`);
    }
    noteParts.push('The cash field starts with the exchange credit.');
    noteParts.push('If the customer adds money, enter the new total tendered in the same field.');

    banner.classList.add('show');
    sourceEl.textContent = sourceLabel;
    itemsEl.textContent = summarizeExchangeItems(currentExchangeContext.items);
    amountEl.textContent = formatCurrency(creditAmount);
    cartTotalEl.textContent = formatCurrency(currentTotal);
    statusEl.textContent = settlementMessage;
    noteEl.textContent = noteParts.join(' ');
}

function applyExchangeContext() {
    if (!hasExchangeContext()) {
        renderExchangeCreditBanner();
        return;
    }

    const customerNameInput = document.getElementById('customer-name');
    const customerPhoneInput = document.getElementById('customer-phone');
    const paymentMethodSelect = document.getElementById('payment-method');
    const cashInput = document.getElementById('cash-amount');
    const creditAmount = getExchangeCreditAmount();

    if (customerNameInput && currentExchangeContext.customer_name) {
        customerNameInput.value = currentExchangeContext.customer_name;
    }
    if (customerPhoneInput && currentExchangeContext.customer_phone) {
        customerPhoneInput.value = currentExchangeContext.customer_phone;
    }
    if (paymentMethodSelect) {
        paymentMethodSelect.value = 'cash';
    }
    if (cashInput && creditAmount > 0) {
        cashInput.value = creditAmount.toFixed(2);
    }

    toggleCashPayment();
    calculateChange();
    renderExchangeCreditBanner();
}

function getExchangeProductSnapshotFromCard(productId) {
    const productCard = document.querySelector(`.product-card[data-id="${productId}"]`);
    if (!productCard) {
        return null;
    }

    return {
        id: parseInt(productId, 10),
        name: productCard.dataset.name || '',
        retail_price: productCard.dataset.retailPrice ?? productCard.dataset.price ?? 0,
        wholesale_price: productCard.dataset.wholesalePrice ?? null,
        cost_price: productCard.dataset.costPrice ?? null,
        stock: productCard.dataset.stock ?? 0
    };
}

async function fetchExchangeProductSnapshot(productId) {
    const localSnapshot = getExchangeProductSnapshotFromCard(productId);
    if (localSnapshot) {
        return localSnapshot;
    }

    try {
        const response = await fetch(`${API_BASE_URL}/products/get.php?id=${encodeURIComponent(productId)}`);
        const result = await response.json();
        if (result.success && result.product) {
            return {
                id: parseInt(result.product.id, 10),
                name: result.product.name || '',
                retail_price: result.product.price ?? 0,
                wholesale_price: result.product.wholesale_price ?? null,
                cost_price: result.product.cost_price ?? null,
                stock: result.product.quantity ?? 0
            };
        }
    } catch (error) {
        console.error('Unable to fetch exchange item product data:', error);
    }

    return null;
}

async function buildExchangeCartItem(line) {
    const productId = parseInt(line?.product_id, 10) || 0;
    const quantity = parseInt(line?.quantity, 10) || 0;
    const unitPrice = toNumber(line?.unit_price);

    if (productId <= 0 || quantity <= 0) {
        return null;
    }

    const productSnapshot = await fetchExchangeProductSnapshot(productId);
    const retailPrice = productSnapshot ? toNumber(productSnapshot.retail_price) : unitPrice;
    const wholesalePrice = productSnapshot ? productSnapshot.wholesale_price : null;
    const costPrice = productSnapshot ? productSnapshot.cost_price : null;
    const stock = productSnapshot ? Math.max(parseInt(productSnapshot.stock, 10) || 0, quantity) : quantity;
    const inferredMode = inferPriceMode(unitPrice, retailPrice || unitPrice, wholesalePrice, costPrice);

    return {
        product_id: productId,
        product_name: String(productSnapshot?.name || line?.product_name || `Product #${productId}`),
        retail_price: retailPrice || unitPrice,
        wholesale_price: wholesalePrice,
        cost_price: costPrice,
        price_mode: inferredMode,
        price_mode_label: priceModeLabel(inferredMode),
        price_mode_locked: true,
        fixed_price: true,
        fixed_unit_price: unitPrice,
        price: unitPrice,
        quantity,
        stock,
        subtotal: unitPrice * quantity
    };
}

async function preloadExchangeItems() {
    if (!hasExchangeContext() || exchangeItemsLoaded || cart.length > 0) {
        renderExchangeCreditBanner();
        return;
    }

    const exchangeItems = Array.isArray(currentExchangeContext.items) ? currentExchangeContext.items : [];
    if (!exchangeItems.length) {
        exchangeItemsLoaded = true;
        renderExchangeCreditBanner();
        return;
    }

    let loadedCount = 0;
    let failedCount = 0;

    for (const item of exchangeItems) {
        const cartItem = await buildExchangeCartItem(item);
        if (cartItem) {
            cart.push(cartItem);
            loadedCount++;
        } else {
            failedCount++;
        }
    }

    exchangeItemsLoaded = true;
    updateCartDisplay();

    if (loadedCount > 0) {
        showNotification('Exchange items loaded into cart', 'info');
    }
    if (failedCount > 0) {
        showNotification('Some exchange items could not be loaded automatically', 'warning');
    }
}

// ==================== Cash Payment Functions ====================
function toggleCashPayment() {
    const paymentMethod = document.getElementById('payment-method').value;
    const cashSection = document.getElementById('cash-payment-section');
    const notesLabel = document.getElementById('sale-notes-label');
    const notesField = document.getElementById('sale-notes');
    const notesHelp = document.getElementById('sale-notes-help');
    
    if (paymentMethod === 'cash') {
        cashSection.style.display = 'block';
    } else {
        cashSection.style.display = 'none';
    }

    if (notesLabel) {
        notesLabel.textContent = paymentMethod === 'online'
            ? 'Transaction Reference'
            : 'Receipt Note';
    }

    if (notesField) {
        notesField.placeholder = paymentMethod === 'online'
            ? 'Transaction reference or payment details (internal only)'
            : 'Notes (optional)';
    }

    if (notesHelp) {
        notesHelp.textContent = paymentMethod === 'online'
            ? 'Online payment notes are saved for staff reference and will not appear on the receipt.'
            : 'Optional receipt note.';
    }
}

function setCashAmount(amount) {
    const cashInput = document.getElementById('cash-amount');
    const currentAmount = parseFloat(cashInput.value) || 0;
    cashInput.value = (currentAmount + amount).toFixed(2);
    calculateChange();
}

function setExactAmount() {
    const totalText = document.getElementById('cart-total').textContent;
    const total = parseFloat(totalText.replace('₱', '')) || 0;
    document.getElementById('cash-amount').value = total.toFixed(2);
    calculateChange();
}

function clearCashAmount() {
    document.getElementById('cash-amount').value = '0.00';
    calculateChange();
}

function calculateChange() {
    const totalText = document.getElementById('cart-total').textContent;
    const total = parseFloat(totalText.replace('₱', '')) || 0;
    const cash = parseFloat(document.getElementById('cash-amount').value) || 0;
    const change = cash - total;
    
    if (change >= 0) {
        document.getElementById('change-amount').textContent = formatCurrency(change);
        document.getElementById('change-amount').style.color = '#2e7d32';
    } else {
        document.getElementById('change-amount').textContent = formatCurrency(Math.abs(change)) + ' (short)';
        document.getElementById('change-amount').style.color = '#c62828';
    }
}

function getCartTotalValue() {
    const totalText = document.getElementById('cart-total').textContent;
    return toNumber(totalText);
}

function getCashPaymentValues(paymentMethod) {
    if (paymentMethod !== 'cash') {
        return {
            cashAmount: 0,
            changeAmount: 0
        };
    }

    const cashAmount = parseFloat(document.getElementById('cash-amount').value) || 0;
    const changeAmount = cashAmount - getCartTotalValue();

    return {
        cashAmount,
        changeAmount
    };
}

function resetSaleForm() {
    cart = [];
    currentOrderId = 0;
    currentOrderNumber = '';
    currentExchangeContext = null;
    exchangeItemsLoaded = false;
    updateCartDisplay();
    updateActionButtons();
    clearExchangeQueryParam();

    document.getElementById('customer-name').value = '';
    if (document.getElementById('teller-name')) {
        document.getElementById('teller-name').value = '';
    }
    document.getElementById('customer-address').value = '';
    document.getElementById('customer-phone').value = '';
    document.getElementById('sale-notes').value = '';
    document.getElementById('cash-amount').value = '0.00';
    if (document.getElementById('retail-discount')) {
        document.getElementById('retail-discount').value = '0.00';
    }
    inlineCustomerBalanceResults = [];
    inlineCustomerBalanceDismissedKey = '';
    renderInlineCustomerBalanceHint();
    calculateChange();
    // Remove order_id from URL so new.php is shown without the query param
    try {
        const u = new URL(window.location.href);
        if (u.searchParams.has('order_id')) {
            u.searchParams.delete('order_id');
            const newSearch = u.searchParams.toString();
            const newUrl = u.pathname + (newSearch ? ('?' + newSearch) : '');
            window.history.replaceState({}, document.title, newUrl);
        }
    } catch (e) {
        // ignore URL API errors
    }
    try { window.__lastReceiptItems = null; } catch (e) {}
}

function updateActionButtons() {
    const processBtn = document.getElementById('process-sale');
    const saveOrderBtn = document.getElementById('save-order');

    if (processBtn) {
        processBtn.innerHTML = currentOrderId > 0
            ? '<i class="fas fa-cash-register mr-2"></i> Complete Payment'
            : '<i class="fas fa-check mr-2"></i> Complete Sale';
    }

    if (saveOrderBtn) {
        saveOrderBtn.innerHTML = currentOrderId > 0
            ? '<i class="fas fa-save mr-2"></i> Update Order'
            : '<i class="fas fa-receipt mr-2"></i> Order';
    }
}

function inferPriceMode(unitPrice, retailPrice, wholesalePrice, costPrice) {
    const price = toNumber(unitPrice);
    const retail = toNumber(retailPrice);
    const wholesale = parseOptionalPrice(wholesalePrice);
    const cost = parseOptionalPrice(costPrice);

    if (Number.isFinite(cost) && Math.abs(cost - price) < 0.0001) return 'cost';
    if (Number.isFinite(wholesale) && Math.abs(wholesale - price) < 0.0001) return 'wholesale';
    if (Math.abs(retail - price) < 0.0001) return 'retail';
    return 'retail';
}

function buildPendingOrderPayload() {
    const subtotal = cart.reduce((sum, item) => sum + item.subtotal, 0);
    const discountPercent = getAppliedRetailDiscountPercent();
    const discountAmount = getAppliedRetailDiscountAmount();

    return {
        total_amount: Math.max(subtotal - discountAmount, 0),
        discount_percent: discountPercent,
        discount_amount: discountAmount,
        payment_method: document.getElementById('payment-method').value || 'cash',
        customer_name: document.getElementById('customer-name').value.trim() || null,
        customer_phone: document.getElementById('customer-phone').value.trim() || null,
        teller_name: document.getElementById('teller-name') ? (document.getElementById('teller-name').value.trim() || null) : null,
        notes: document.getElementById('sale-notes').value.trim() || null,
        items: cart.map(item => ({
            product_id: item.product_id,
            product_name: item.product_name,
            quantity: item.quantity,
            price: item.price,
            price_mode: item.price_mode,
            is_balance_item: item.is_balance_item === true,
            source_balance_id: item.source_balance_id || null
        }))
    };
}

// ==================== Notification System ====================
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
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

// --- Lightweight team chat via notification bell ---
const CURRENT_USER = {
    id: <?php echo (int)($_SESSION['user_id'] ?? 0); ?>,
    role: '<?php echo addslashes($_SESSION["user_role"] ?? "cashier"); ?>',
    name: '<?php echo addslashes($_SESSION["user_name"] ?? $_SESSION["username"] ?? "User"); ?>'
};
const CURRENT_USERNAME = '<?php echo addslashes($_SESSION["username"] ?? ""); ?>';

let notifPanel, notifListEl, notifMessageInput, notifTargetSelect;
let notifVisible = false;
let notifPollTimer = null;
let lastNotifId = 0;

function initNotificationPanel() {
    if (notifPanel) return;

    notifPanel = document.createElement('div');
    notifPanel.id = 'notif-panel';
    notifPanel.className = 'notif-panel';
    notifPanel.innerHTML = `
        <div class="notif-header">
            <span>Team Chat</span>
            <button class="notif-close" type="button" aria-label="Close">&times;</button>
        </div>
        <div class="notif-list" id="notif-list">
            <div class="notif-empty">Loading messages...</div>
        </div>
        <form class="notif-form" id="notif-form">
            <div class="notif-form-row">
                <input type="text" id="notif-message" class="form-control" placeholder="Type a message" maxlength="200" required />
                <select id="notif-target" class="form-control">
                    <option value="admin">Admin</option>
                    <option value="cashier">Cashier</option>
                    <option value="manager">Inventory/Manager</option>
                    <option value="all">Everyone</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="fas fa-paper-plane mr-1"></i> Send
            </button>
        </form>
    `;
    document.body.appendChild(notifPanel);

    notifListEl = notifPanel.querySelector('#notif-list');
    notifMessageInput = notifPanel.querySelector('#notif-message');
    notifTargetSelect = notifPanel.querySelector('#notif-target');

    notifPanel.querySelector('.notif-close').addEventListener('click', hideNotificationPanel);
    notifPanel.querySelector('#notif-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        await sendChatNotification();
    });

    if (notifTargetSelect) {
        notifTargetSelect.value = (CURRENT_USER.role === 'admin') ? 'cashier' : 'admin';
    }
}

function toggleNotificationPanel() {
    initNotificationPanel();
    notifVisible = !notifVisible;
    notifPanel.classList.toggle('show', notifVisible);

    if (notifVisible) {
        fetchChatNotifications(false);
        markChatNotificationsRead();
        notifMessageInput.focus();
    }
}

function hideNotificationPanel() {
    notifVisible = false;
    if (notifPanel) {
        notifPanel.classList.remove('show');
    }
}

async function fetchChatNotifications(showToast = false) {
    try {
        const res = await fetch('/pos-system/api/notifications/fetch.php', { cache: 'no-store' });
        const data = await res.json();
        if (!data.success) return;

        const list = Array.isArray(data.data) ? data.data : [];
        renderChatNotifications(list);

        if (list.length && list[0].id > lastNotifId) {
            if (showToast && !notifVisible) {
                const from = list[0].sender_name || list[0].sender_role || 'Someone';
                showNotification(`New message from ${from}`, 'info');
            }
            lastNotifId = list[0].id;
        }
    } catch (err) {
        console.warn('Notification fetch failed', err);
    }
}

function renderChatNotifications(list) {
    if (!notifListEl) return;
    if (!list.length) {
        notifListEl.innerHTML = '<div class="notif-empty">No messages yet.</div>';
        return;
    }

    const items = list.map((item) => {
        const isUnread = !item.is_read && (item.target_role === CURRENT_USER.role || item.target_role === 'all');
        const time = item.created_at ? new Date(item.created_at.replace(' ', 'T')).toLocaleString() : '';
        const sender = item.sender_name || item.sender_role || 'User';
        const targetLabel = item.target_role === 'all' ? 'Everyone' : item.target_role;
        return `
            <div class="notif-item ${isUnread ? 'unread' : ''}">
                <div class="notif-meta">
                    <span>${escapeHtml(sender)} → ${escapeHtml(targetLabel)}</span>
                    <span>${escapeHtml(time)}</span>
                </div>
                <div class="notif-text">${escapeHtml(item.message || '')}</div>
            </div>
        `;
    }).join('');

    notifListEl.innerHTML = items;
}

async function sendChatNotification() {
    if (!notifMessageInput || !notifTargetSelect) return;
    const message = notifMessageInput.value.trim();
    const target = notifTargetSelect.value;
    if (!message) {
        notifMessageInput.focus();
        return;
    }
    try {
        const res = await fetch('/pos-system/api/notifications/send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message, target_role: target })
        });
        const data = await res.json();
        if (data.success) {
            notifMessageInput.value = '';
            fetchChatNotifications(false);
        } else {
            showNotification(data.message || 'Unable to send message', 'error');
        }
    } catch (err) {
        console.error('Send notification failed:', err);
        showNotification('Unable to send message', 'error');
    }
}

async function markChatNotificationsRead() {
    try {
        await fetch('/pos-system/api/notifications/mark-read.php', { method: 'POST' });
    } catch (err) {
        console.warn('mark-read failed', err);
    }
}

function startNotificationPolling() {
    if (notifPollTimer) clearInterval(notifPollTimer);
    notifPollTimer = setInterval(() => fetchChatNotifications(true), 15000);
}

// ==================== Modal Functions ====================
function openModal(modalId) {
    document.getElementById(modalId).classList.add('show');
}

function closeModal(modalId) {
    const modalEl = document.getElementById(modalId);
    try {
        modalEl.dispatchEvent(new CustomEvent('modalClosed', { detail: { id: modalId } }));
    } catch (e) {
        // ignore if CustomEvent not supported
    }
    // If closing receipt modal, try to refresh stocks and reset the cart using lastReceipt items
    if (modalId === 'receiptModal') {
        try { refreshProductStocksForItems(window.__lastReceiptItems || []); } catch (e) { console.warn('refreshProductStocksForItems failed', e); }
        try { resetSaleForm(); } catch (e) { console.warn('resetSaleForm failed', e); }
        window.__lastReceiptItems = null;
    }
    modalEl.classList.remove('show');
}

// ==================== Balance Lookup ====================
function openBalanceModal() {
    const nameInput = document.getElementById('balance-search-name');
    const phoneInput = document.getElementById('balance-search-phone');
    if (nameInput) nameInput.value = (document.getElementById('customer-name')?.value || '').trim();
    if (phoneInput) phoneInput.value = (document.getElementById('customer-phone')?.value || '').trim();
    balanceSearchResults = [];
    selectedBalanceIds = new Set();
    renderBalanceResults([]);
    openModal('balanceModal');
    if ((nameInput && nameInput.value) || (phoneInput && phoneInput.value)) {
        searchCustomerBalance();
    }
}

function resetBalanceSearch() {
    const nameInput = document.getElementById('balance-search-name');
    const phoneInput = document.getElementById('balance-search-phone');
    if (nameInput) nameInput.value = '';
    if (phoneInput) phoneInput.value = '';
    balanceSearchResults = [];
    selectedBalanceIds = new Set();
    renderBalanceResults([]);
}

function getOutstandingBalance(order = {}) {
    const explicitOutstanding = Number(order.outstanding_amount || 0);
    if (explicitOutstanding > 0) {
        return explicitOutstanding;
    }

    const approvalOutstanding = Number(order.approval_short_amount || 0);
    if (String(order.approval_status || '').toLowerCase() === 'approved' && approvalOutstanding > 0) {
        return approvalOutstanding;
    }

    const totalAmount = Number(order.total_amount || 0);
    const cashAmount = Number(order.cash_amount || 0);

    if (totalAmount <= 0) {
        return 0;
    }

    if (cashAmount <= 0) {
        return totalAmount;
    }

    return Math.max(totalAmount - cashAmount, 0);
}

function renderBalanceResults(results = []) {
    const container = document.getElementById('balance-results');
    const totalEl = document.getElementById('balance-total');
    const summaryEl = document.getElementById('balance-selected-summary');
    if (!container || !totalEl) return;

    balanceSearchResults = results;
    selectedBalanceIds = new Set();
    if (summaryEl) {
        summaryEl.innerHTML = '';
    }

    if (!results.length) {
        container.innerHTML = '<span class=\"text-muted\">No pending balances found.</span>';
        totalEl.textContent = '';
        return;
    }

    let html = '<div class=\"balance-table\"><table><thead><tr><th></th><th>Customer</th><th>Phone</th><th>Invoice</th><th>Amount</th><th>Date</th></thead><tbody>';
    results.forEach((order, idx) => {
        const amount = getOutstandingBalance(order);
        const created = order.created_at ? new Date(order.created_at).toLocaleString() : '';
        const checked = idx === 0 ? 'checked' : '';
        html += `
            <tr>
                <td><input type="checkbox" name="balance-select" value="${order.id}" ${checked}></td>
                <td>${escapeHtml(order.customer_name || 'N/A')}</td>
                <td>${escapeHtml(order.customer_phone || 'N/A')}</td>
                <td>${escapeHtml(order.invoice_number || 'ORD')}</td>
                <td style="text-align:right; font-weight:600;">${formatCurrency(amount)}</td>
                <td>${created}</td>
            </tr>
        `;
        if (checked) {
            selectedBalanceIds.add(Number(order.id));
        }
    });
    html += '</tbody></table></div>';
    container.innerHTML = html;

    const totalOutstanding = results.reduce((sum, order) => sum + getOutstandingBalance(order), 0);
    totalEl.textContent = `Total outstanding: ${formatCurrency(totalOutstanding)}`;

    container.querySelectorAll('input[name=\"balance-select\"]').forEach(input => {
        input.addEventListener('change', (e) => {
            const value = Number(e.target.value);
            if (e.target.checked) {
                selectedBalanceIds.add(value);
            } else {
                selectedBalanceIds.delete(value);
            }
            updateSelectedBalanceSummary();
        });
    });

    updateSelectedBalanceSummary();
}

async function searchCustomerBalance() {
    const name = (document.getElementById('balance-search-name')?.value || '').trim();
    const phone = (document.getElementById('balance-search-phone')?.value || '').trim();
    const queries = [...new Set([name, phone].filter(Boolean))];
    if (!queries.length) {
        renderBalanceResults([]);
        return;
    }

    try {
        const responses = await Promise.all(queries.map(async (query) => {
            const response = await fetch(`${API_BASE_URL}/sales/balance-list.php?search=${encodeURIComponent(query)}&limit=100`, {
                headers: { 'Accept': 'application/json' }
            });
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.message || 'Failed to load balances');
            }
            return result.data || [];
        }));

        const normalizedName = name.toLowerCase();
        const normalizedPhone = phone.toLowerCase();
        const mergedResults = new Map();

        responses.flat().forEach(order => {
            if (!order || !order.id) return;
            mergedResults.set(Number(order.id), order);
        });

        const filteredResults = Array.from(mergedResults.values()).filter(order => {
            const customerName = String(order.customer_name || '').toLowerCase();
            const customerPhone = String(order.customer_phone || '').toLowerCase();
            const invoiceNumber = String(order.invoice_number || '').toLowerCase();
            const matchesName = !normalizedName || customerName.includes(normalizedName) || invoiceNumber.includes(normalizedName);
            const matchesPhone = !normalizedPhone || customerPhone.includes(normalizedPhone);
            return matchesName && matchesPhone && getOutstandingBalance(order) > 0;
        });

        renderBalanceResults(filteredResults);
    } catch (error) {
        console.error('Balance lookup error:', error);
        showNotification(error.message || 'Unable to load balances', 'error');
    }
}

function getCustomerBalanceLookupKey(name = '', phone = '') {
    return `${String(name || '').trim().toLowerCase()}|${String(phone || '').trim().toLowerCase()}`;
}

function renderInlineCustomerBalanceHint() {
    const hintEl = document.getElementById('customer-balance-hint');
    const textEl = document.getElementById('customer-balance-hint-text');
    const subtextEl = document.getElementById('customer-balance-hint-subtext');
    if (!hintEl || !textEl || !subtextEl) return;

    const records = inlineCustomerBalanceResults || [];
    const total = records.reduce((sum, record) => sum + getOutstandingBalance(record), 0);
    if (!records.length || total <= 0) {
        hintEl.classList.remove('show');
        textEl.textContent = '';
        subtextEl.textContent = '';
        return;
    }

    const customerName = records[0]?.customer_name || 'Customer';
    textEl.textContent = `${customerName} has remaining balance: ${formatCurrency(total)}`;
    subtextEl.textContent = `${records.length} balance record(s) found. Cashier can add the remaining balance or continue without it.`;
    hintEl.classList.add('show');
}

async function lookupInlineCustomerBalance() {
    const name = (document.getElementById('customer-name')?.value || '').trim();
    const phone = (document.getElementById('customer-phone')?.value || '').trim();
    const queries = [...new Set([name, phone].filter(Boolean))];
    const currentKey = getCustomerBalanceLookupKey(name, phone);

    if (!queries.length) {
        inlineCustomerBalanceResults = [];
        inlineCustomerBalanceDismissedKey = '';
        renderInlineCustomerBalanceHint();
        return;
    }

    if (inlineCustomerBalanceDismissedKey && inlineCustomerBalanceDismissedKey === currentKey) {
        return;
    }

    try {
        const responses = await Promise.all(queries.map(async (query) => {
            const response = await fetch(`${API_BASE_URL}/sales/balance-list.php?search=${encodeURIComponent(query)}&limit=100`, {
                headers: { 'Accept': 'application/json' }
            });
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.message || 'Failed to load balances');
            }
            return result.data || [];
        }));

        const normalizedName = name.toLowerCase();
        const normalizedPhone = phone.toLowerCase();
        const mergedResults = new Map();

        responses.flat().forEach(order => {
            if (!order || !order.id) return;
            mergedResults.set(Number(order.id), order);
        });

        inlineCustomerBalanceResults = Array.from(mergedResults.values()).filter(order => {
            const customerName = String(order.customer_name || '').toLowerCase();
            const customerPhone = String(order.customer_phone || '').toLowerCase();
            const matchesName = !normalizedName || customerName.includes(normalizedName);
            const matchesPhone = !normalizedPhone || customerPhone.includes(normalizedPhone);
            return matchesName && matchesPhone && getOutstandingBalance(order) > 0;
        });

        renderInlineCustomerBalanceHint();
    } catch (error) {
        console.error('Inline balance lookup error:', error);
        inlineCustomerBalanceResults = [];
        renderInlineCustomerBalanceHint();
    }
}

function dismissInlineCustomerBalance() {
    const name = (document.getElementById('customer-name')?.value || '').trim();
    const phone = (document.getElementById('customer-phone')?.value || '').trim();
    inlineCustomerBalanceDismissedKey = getCustomerBalanceLookupKey(name, phone);
    inlineCustomerBalanceResults = [];
    renderInlineCustomerBalanceHint();
    showNotification('Continuing without adding previous balance', 'info');
}

async function markBalanceAsPaid() {
    const records = getSelectedBalanceRecords();
    if (!records.length) {
        showNotification('Select balance record(s) first', 'warning');
        return;
    }

    try {
        for (const record of records) {
            const amount = getOutstandingBalance(record);
            const payload = {
                order_id: record.id,
                payment_method: 'cash',
                cash_amount: amount,
                change_amount: 0,
                customer_name: record.customer_name || (document.getElementById('balance-search-name')?.value || '').trim() || null,
                customer_phone: record.customer_phone || (document.getElementById('balance-search-phone')?.value || '').trim() || null
            };

            const approvalStatus = String(record.approval_status || '').toLowerCase();
            const endpoint = approvalStatus === 'approved'
                ? `${API_BASE_URL}/sales/settle-balance.php`
                : `${API_BASE_URL}/sales/complete-order.php`;
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (!result.success) {
                throw new Error(result.message || 'Failed to mark as paid');
            }
            if (result.data && result.data.sale) {
                showReceipt({
                    sale: result.data.sale,
                    items: result.data.items || []
                });
            }
        }
        showNotification(`${records.length} balance record(s) marked as paid`, 'success');
        await searchCustomerBalance();
    } catch (error) {
        console.error('Mark paid error:', error);
        showNotification(error.message || 'Unable to mark as paid', 'error');
    }
}

function getSelectedBalanceRecords() {
    return (balanceSearchResults || []).filter(r => selectedBalanceIds.has(Number(r.id)));
}

async function fetchBalanceSaleItems(saleId) {
    const normalizedId = Number(saleId);
    if (normalizedId <= 0) return [];

    try {
        const response = await fetch(`${API_BASE_URL}/sales/details.php?id=${encodeURIComponent(normalizedId)}`, {
            headers: {
                'Accept': 'application/json'
            }
        });

        const result = await response.json();
        if (!result.success || !result.data) {
            return [];
        }

        return Array.isArray(result.data.items) ? result.data.items : [];
    } catch (error) {
        console.error('Failed to load balance sale items:', error);
        return [];
    }
}

async function addBalanceRecordsToCart(records = [], options = {}) {
    const closeAfter = options.closeAfter !== false;
    if (!records.length) {
        showNotification('Select balance record(s) first', 'warning');
        return false;
    }

    let addedCount = 0;
    let addedTotal = 0;
    for (const record of records) {
        const amount = getOutstandingBalance(record);
        if (amount <= 0) continue;

        const existingBalanceItem = cart.find(item => Number(item.source_balance_id) === Number(record.id));
        if (existingBalanceItem) continue;

        const balanceItems = await fetchBalanceSaleItems(record.id);

        cart.push({
            product_id: 0,
            product_name: `Previous Balance - ${record.invoice_number || ('#' + record.id)}`,
            retail_price: amount,
            wholesale_price: null,
            cost_price: null,
            price_mode: 'retail',
            price_mode_label: 'Retail',
            price_mode_locked: true,
            fixed_price: true,
            fixed_unit_price: amount,
            price: amount,
            quantity: 1,
            stock: 1,
            subtotal: amount,
            source_balance_id: Number(record.id),
            source_balance_invoice: record.invoice_number || null,
            source_balance_amount: amount,
            is_balance_item: true,
            balance_items: balanceItems
        });
        addedCount++;
        addedTotal += amount;
    }

    const primaryRecord = records[0];
    const nameInput = document.getElementById('customer-name');
    const phoneInput = document.getElementById('customer-phone');
    if (nameInput && primaryRecord.customer_name) nameInput.value = primaryRecord.customer_name;
    if (phoneInput && primaryRecord.customer_phone) phoneInput.value = primaryRecord.customer_phone;

    updateCartDisplay();
    if (closeAfter) {
        closeModal('balanceModal');
    }
    if (addedCount === 0) {
        showNotification('Selected balance already added to cart', 'info');
        return false;
    }

    inlineCustomerBalanceResults = [];
    inlineCustomerBalanceDismissedKey = '';
    renderInlineCustomerBalanceHint();
    showNotification(`Added ${addedCount} balance record(s): ${formatCurrency(addedTotal)}`, 'success');
    return true;
}

function updateSelectedBalanceSummary() {
    const summaryEl = document.getElementById('balance-selected-summary');
    if (!summaryEl) return;

    const records = getSelectedBalanceRecords();
    if (!records.length) {
        summaryEl.innerHTML = '';
        return;
    }

    const firstRecord = records[0];
    const totalSelected = records.reduce((sum, record) => sum + getOutstandingBalance(record), 0);
    const allApproved = records.every(record => String(record.approval_status || '').toLowerCase() === 'approved');
    const statusLabel = allApproved ? 'Approved balance' : 'Pending/mixed balance';

    summaryEl.innerHTML = `
        <div style="padding:10px; border:1px solid #dbeafe; background:#eff6ff; border-radius:8px;">
            <div style="font-weight:700; color:#1e3a8a;">${escapeHtml(firstRecord.customer_name || 'Customer')}</div>
            <div style="font-size:0.9rem; color:#475569; margin-top:2px;">
                ${records.length} selected | ${escapeHtml(statusLabel)}
            </div>
            <div style="margin-top:6px; font-size:1rem; font-weight:700; color:#0f172a;">
                Balance: ${formatCurrency(totalSelected)}
            </div>
        </div>
    `;
}

function printSelectedBalanceReceipt() {
    const records = getSelectedBalanceRecords();
    if (!records.length) {
        showNotification('Select balance record(s) first', 'warning');
        return;
    }

    const unapproved = records.find(record => String(record.approval_status || '').toLowerCase() !== 'approved');
    if (unapproved) {
        showNotification('Receipt can only be printed after admin approval', 'warning');
        return;
    }

    records.forEach(record => {
        window.open(`/pos-system/views/sales/print.php?id=${encodeURIComponent(record.id)}&source=sales`, '_blank');
    });
}

async function addSelectedBalanceToCart() {
    const records = getSelectedBalanceRecords();
    await addBalanceRecordsToCart(records, { closeAfter: true });
}

async function addInlineCustomerBalance() {
    if (!inlineCustomerBalanceResults.length) {
        showNotification('No remaining balance found for this customer', 'info');
        return;
    }
    await addBalanceRecordsToCart(inlineCustomerBalanceResults, { closeAfter: false });
}

function startNewTransactionFromBalance() {
    // Prefill customer fields and close the modal so cashier can continue
    const records = getSelectedBalanceRecords();
    const record = records[0] || null;
    const nameInput = document.getElementById('customer-name');
    const phoneInput = document.getElementById('customer-phone');

    if (record) {
        if (nameInput && record.customer_name) nameInput.value = record.customer_name;
        if (phoneInput && record.customer_phone) phoneInput.value = record.customer_phone;
    } else {
        if (nameInput) nameInput.value = document.getElementById('balance-search-name')?.value || nameInput.value;
        if (phoneInput) phoneInput.value = document.getElementById('balance-search-phone')?.value || phoneInput.value;
    }

    closeModal('balanceModal');
    showNotification('Customer info filled for new transaction', 'info');
}

function openProductImageModal(imageSrc, productName = '') {
    const image = document.getElementById('product-image-preview');
    const caption = document.getElementById('product-image-caption');

    if (!image || !imageSrc) return;

    image.src = imageSrc;
    image.alt = productName || 'Product image';
    if (caption) {
        caption.textContent = productName || '';
    }

    openModal('productImageModal');
}

function closeScanner() {
    const modal = document.getElementById('scannerModal');
    const video = document.getElementById('qr-video');
    
    if (zxingControls && typeof zxingControls.stop === 'function') {
        try { zxingControls.stop(); } catch (_) {}
        zxingControls = null;
    }
    
    if (video && video.srcObject) {
        video.srcObject.getTracks().forEach(track => track.stop());
        video.srcObject = null;
    }
    
    scannerActive = false;
    modal.classList.remove('show');
}

// ==================== Scanner Functions ====================
function initScanner() {
    const scanBtn = document.getElementById('scan-qr-btn');
    const video = document.getElementById('qr-video');
    
    if (!scanBtn || !video) return;
    
    scanBtn.addEventListener('click', toggleScanner);
}

function toggleScanner() {
    const modal = document.getElementById('scannerModal');
    
    if (scannerActive) {
        closeScanner();
    } else {
        openScanner();
    }
}

async function openScanner() {
    const modal = document.getElementById('scannerModal');
    const video = document.getElementById('qr-video');
    
    modal.classList.add('show');
    
    try {
        if (!zxingReader && window.ZXing && ZXing.BrowserMultiFormatReader) {
            zxingReader = new ZXing.BrowserMultiFormatReader();
        }
        
        if (!zxingReader) {
            throw new Error('Scanner library not loaded');
        }
        
        scannerActive = true;
        zxingControls = await zxingReader.decodeFromVideoDevice(
            undefined,
            video,
            (result, err, controls) => {
                if (result) {
                    showNotification('✅ Code detected!', 'success');
                    handleScan(result.getText());
                    if (controls && typeof controls.stop === 'function') {
                        controls.stop();
                    }
                    zxingControls = null;
                    scannerActive = false;
                    closeScanner();
                }
            }
        );
        
    } catch (error) {
        alert('Camera access error: ' + error.message);
        closeScanner();
    }
}

function initBarcodeMode() {
    const input = document.getElementById('scan-input');
    if (!input) return;
    input.focus();

    let buffer = '';
    let timeout = null;

    function flushBuffer() {
        const trimmed = buffer.trim();
        buffer = '';
        if (trimmed) {
            handleScan(trimmed);
            input.value = '';
        }
    }

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            flushBuffer();
            return;
        }

        if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
            buffer += e.key;
            if (timeout) clearTimeout(timeout);
            timeout = setTimeout(flushBuffer, 250); // assume scan ended
        }
    });

    input.addEventListener('paste', (e) => {
        setTimeout(() => {
            const value = input.value.trim();
            if (value) {
                handleScan(value);
                input.value = '';
                buffer = '';
            }
        }, 50);
    });

    // Keep support for manual enter from keyboard text
    input.addEventListener('change', () => {
        const value = input.value.trim();
        if (value) {
            handleScan(value);
            input.value = '';
            buffer = '';
        }
    });
}

// ==================== Scan Handler ====================
async function handleScan(code) {
    code = String(code || '').trim();
    if (!code) {
        showNotification('Scan code is empty', 'warning');
        return;
    }

    console.log('Processing scan:', code);
    showNotification('Processing scan...', 'info');

    try {
        let productData = {};

        // Accept JSON payloads (e.g., {"product_code":"ABC","name":"Item"})
        try {
            productData = JSON.parse(code);
            console.log('Parsed QR/JSON scan data:', productData);
        } catch (e) {
            // Fallback: treat as plain string or pipe-delimited "CODE|Name"
            if (code.includes('|')) {
                const [rawCode] = code.split('|');
                productData = { code: rawCode };
            } else {
                productData = { code };
            }
        }

        let added = false;

        // Try to find product by ID
        if (productData.product_id) {
            const response = await fetch(`${API_BASE_URL}/products/get.php?id=${encodeURIComponent(productData.product_id)}`);
            const result = await response.json();
            if (result.success && result.product) {
                added = addToCart(result.product.id);
            }
        }

        // Try by product_code string
        if (!added && (productData.product_code || productData.code)) {
            const lookupCode = productData.product_code || productData.code;
            const response = await fetch(`${API_BASE_URL}/products/get.php?code=${encodeURIComponent(lookupCode)}`);
            const result = await response.json();
            if (result.success && result.product) {
                added = addToCart(result.product.id);
            }
        }

        // Fallback: search by raw code anywhere in product fields
        if (!added) {
            const searchResponse = await fetch(`${API_BASE_URL}/products/search.php?q=${encodeURIComponent(code)}`);
            const searchResult = await searchResponse.json();
            if (searchResult.success && Array.isArray(searchResult.data) && searchResult.data.length > 0) {
                if (searchResult.data.length === 1) {
                    added = addToCart(searchResult.data[0].id);
                } else {
                    showProductSelection(searchResult.data);
                    return;
                }
            }
        }

        if (added) {
            // auto sale option
            const autoSale = document.getElementById('auto-complete-sale');
            if (autoSale && autoSale.checked) {
                setTimeout(() => processSale(), 250);
            }
            const scanInput = document.getElementById('scan-input');
            if (scanInput) {
                scanInput.focus();
            }
            return;
        }

        showNotification('Product not found', 'warning');
    } catch (error) {
        console.error('Scan processing error:', error);
        showNotification('Error processing scan', 'error');
    }
}

function showProductSelection(products) {
    const modal = document.getElementById('productSelectModal');
    const list = document.getElementById('product-select-list');
    
    let html = '<div class="product-select-grid">';
    products.forEach(product => {
        html += `
            <div class="product-select-item" onclick="addToCart(${product.id}); closeModal('productSelectModal')">
                <div class="product-select-info">
                    <strong>${escapeHtml(product.name)}</strong>
                    <small>Code: ${product.product_code}</small>
                    <small>Price (${priceModeLabel(priceMode)}): ${formatCurrency(selectUnitPrice(product.price, product.wholesale_price, product.cost_price))}</small>
                    <small>Stock: ${product.quantity}</small>
                </div>
                <button class="btn btn-sm btn-primary">Select</button>
            </div>
        `;
    });
    html += '</div>';
    
    list.innerHTML = html;
    modal.classList.add('show');
}

// ==================== Cart Functions ====================
// MODIFIED: Added quantity limit based on actual available stock
function upsertCartItem(product, deltaQty = 1) {
    const productId = parseInt(product.id, 10);
    const productName = product.name;
    const retailPrice = toNumber(product.retail_price ?? product.price);
    const wholesalePrice = product.wholesale_price ?? null;
    const costPrice = product.cost_price ?? null;
    const unitPrice = selectUnitPrice(retailPrice, wholesalePrice, costPrice);
    
    // Get actual available stock (total - reserved)
    const actualAvailableStock = getActualAvailableStock(productId);
    const alreadyInCart = cart.filter(c => c.product_id === productId).reduce((sum, c) => sum + toNumber(c.quantity), 0);
    const remainingStock = actualAvailableStock - alreadyInCart;

    if (!Number.isFinite(productId) || !Number.isFinite(actualAvailableStock)) {
        showNotification('Invalid product data', 'error');
        return false;
    }
    if (actualAvailableStock <= 0) {
        showNotification('Product out of stock', 'error');
        return false;
    }

    if (deltaQty > remainingStock) {
        showNotification(`Not enough stock. Only ${remainingStock} available.`, 'warning');
        return false;
    }

    const item = {
        product_id: productId,
        product_name: productName,
        retail_price: retailPrice,
        wholesale_price: wholesalePrice,
        cost_price: costPrice,
        price_mode: priceMode,
        price_mode_label: priceModeLabel(priceMode),
        price_mode_locked: false,
        price: unitPrice,
        quantity: deltaQty,
        stock: actualAvailableStock,
        subtotal: unitPrice * deltaQty
    };

    cart.push(item);
    updateCartDisplay();
    highlightProduct(productId);
    showNotification(`${productName} added to cart`, 'success');
    return true;
}

function addToCart(productId) {
    // Find product in the grid
    const productCard = document.querySelector(`.product-card[data-id="${productId}"]`);
    
    if (!productCard) {
        // If not in grid, fetch from API
        return fetchProductAndAdd(productId);
    }
    
    // Calculate actual available stock (total quantity - reserved)
    const totalStock = parseInt(productCard.dataset.stock, 10) || 0;
    const reserved = parseInt(productCard.dataset.reserved, 10) || 0;
    const actualAvailable = totalStock - reserved;
    
    return upsertCartItem({
        id: productId,
        name: productCard.dataset.name,
        retail_price: productCard.dataset.retailPrice ?? productCard.dataset.price,
        wholesale_price: productCard.dataset.wholesalePrice,
        cost_price: productCard.dataset.costPrice,
        stock: actualAvailable
    }, 1);
}

async function fetchProductAndAdd(productId) {
    try {
        const response = await fetch(`${API_BASE_URL}/products/get.php?id=${productId}`);
        const result = await response.json();
        
            if (result.success && result.product) {
                const product = result.product;
                const card = document.querySelector(`.product-card[data-id="${product.id}"]`);
                const reserved = card ? (parseInt(card.dataset.reserved, 10) || 0) : 0;
                const actualAvailable = Math.max((parseInt(product.quantity, 10) || 0) - reserved, 0);
                return upsertCartItem({
                    id: product.id,
                    name: product.name,
                    retail_price: product.price,
                    wholesale_price: product.wholesale_price,
                    cost_price: product.cost_price,
                    stock: actualAvailable
                }, 1);
            }
    } catch (error) {
        console.error('Error fetching product:', error);
    }
    return false;
}

// MODIFIED: Added quantity limit check
function updateCartItem(index, action) {
    const item = cart[index];
    if (!item) return;
    if (item.is_balance_item === true) {
        showNotification('Balance line amount is fixed', 'info');
        updateCartDisplay();
        return;
    }
    
    // Get actual available stock
    const actualAvailable = getActualAvailableStock(item.product_id);
    const alreadyInCart = cart.filter(c => c.product_id === item.product_id).reduce((sum, c) => sum + toNumber(c.quantity), 0);
    const remainingStock = actualAvailable - (alreadyInCart - item.quantity);
    
    if (action === 'increase') {
        if (item.quantity >= remainingStock) {
            showNotification(`Cannot add more. Only ${remainingStock} available.`, 'warning');
            return;
        }
        item.quantity++;
    } else if (action === 'decrease') {
        if (item.quantity > 1) {
            item.quantity--;
        } else {
            removeFromCart(index);
            return;
        }
    }
    
    item.subtotal = item.quantity * item.price;
    updateCartDisplay();
}

// MODIFIED: Added quantity limit check
function setCartQuantity(index, rawQuantity) {
    const item = cart[index];
    if (!item) return;
    if (item.is_balance_item === true) {
        item.quantity = 1;
        item.subtotal = item.price;
        updateCartDisplay();
        return;
    }

    // Get actual available stock
    const actualAvailable = getActualAvailableStock(item.product_id);
    const alreadyInCart = cart.filter(c => c.product_id === item.product_id).reduce((sum, c) => sum + toNumber(c.quantity), 0);
    const remainingStock = actualAvailable - (alreadyInCart - item.quantity);

    let qty = parseInt(String(rawQuantity), 10);
    if (!Number.isFinite(qty)) qty = 1;
    if (qty < 1) qty = 1;
    if (qty > remainingStock) {
        qty = remainingStock;
        showNotification(`Quantity limited to available stock: ${remainingStock}`, 'warning');
    }

    item.quantity = qty;
    item.subtotal = item.quantity * item.price;
    updateCartDisplay();
}

async function setCartItemPriceMode(index, mode, selectElement) {
    const item = cart[index];
    if (!item) return;

    // Check if wholesale price exists
    if (mode === 'wholesale' && !Number.isFinite(parseOptionalPrice(item.wholesale_price))) {
        showNotification('Wholesale price not available for this item', 'warning');
        if (selectElement) selectElement.value = item.price_mode || 'retail';
        updateCartDisplay();
        return;
    }

    // Check if cost price exists
    if (mode === 'cost' && !Number.isFinite(parseOptionalPrice(item.cost_price))) {
        showNotification('Cost price not available for this item', 'warning');
        if (selectElement) selectElement.value = item.price_mode || 'retail';
        updateCartDisplay();
        return;
    }

    // If selecting cost, require admin credentials
    if (mode === 'cost') {
        const password = await promptAdminPassword();
        if (!password) {
            showNotification('Admin password required to use cost price', 'warning');
            if (selectElement) selectElement.value = item.price_mode || 'retail';
            return;
        }

        const auth = await verifyAdminPassword(password);
        if (!auth) {
            showNotification('Invalid admin credentials', 'error');
            if (selectElement) selectElement.value = item.price_mode || 'retail';
            return;
        }
    }

    // Update item price mode
    item.price_mode = mode;
    item.price_mode_label = priceModeLabel(mode);
    item.price_mode_locked = true;
    item.fixed_price = false;
    item.fixed_unit_price = null;

    const retail = toNumber(item.retail_price);
    const wholesale = parseOptionalPrice(item.wholesale_price);
    const cost = parseOptionalPrice(item.cost_price);

    if (mode === 'wholesale') {
        item.price = Number.isFinite(wholesale) && wholesale > 0 ? wholesale : retail;
    } else if (mode === 'cost') {
        item.price = Number.isFinite(cost) && cost > 0 ? cost : retail;
    } else {
        item.price = retail;
    }

    // Update subtotal
    item.subtotal = item.quantity * item.price;
    updateCartDisplay();
}

function removeFromCart(index) {
    const item = cart[index];
    cart.splice(index, 1);
    updateCartDisplay();
    showNotification(`${item.product_name} removed from cart`, 'info');
}

function promptAdminPassword() {
    return new Promise((resolve) => {
        const overlay = document.createElement('div');
        overlay.style.position = 'fixed';
        overlay.style.inset = '0';
        overlay.style.background = 'rgba(0,0,0,0.35)';
        overlay.style.display = 'flex';
        overlay.style.alignItems = 'center';
        overlay.style.justifyContent = 'center';
        overlay.style.zIndex = '10000';

        const dialog = document.createElement('div');
        dialog.style.background = '#fff';
        dialog.style.borderRadius = '10px';
        dialog.style.width = '320px';
        dialog.style.maxWidth = '92vw';
        dialog.style.padding = '16px';
        dialog.style.boxShadow = '0 8px 24px rgba(0,0,0,0.2)';

        dialog.innerHTML = `
            <div style="font-weight:600; margin-bottom:8px;">Admin Password Required</div>
            <div style="font-size:13px; color:#4b5563; margin-bottom:10px;">Enter admin password to use cost price.</div>
            <input id="admin-password-input" type="password" autocomplete="current-password"
                   style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; margin-bottom:12px;" />
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" id="admin-pass-cancel" class="btn btn-sm btn-secondary">Cancel</button>
                <button type="button" id="admin-pass-ok" class="btn btn-sm btn-primary">Confirm</button>
            </div>
        `;

        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        const input = dialog.querySelector('#admin-password-input');
        const cancelBtn = dialog.querySelector('#admin-pass-cancel');
        const okBtn = dialog.querySelector('#admin-pass-ok');

        const cleanup = () => {
            if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        };

        const cancel = () => {
            cleanup();
            resolve(null);
        };

        const confirm = () => {
            const value = (input.value || '').trim();
            cleanup();
            resolve(value || null);
        };

        cancelBtn.addEventListener('click', cancel);
        okBtn.addEventListener('click', confirm);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) cancel();
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') confirm();
            if (e.key === 'Escape') cancel();
        });

        setTimeout(() => input.focus(), 0);
    });
}

async function verifyAdminPassword(password) {
    try {
        const response = await fetch('/pos-system/api/auth/verify-admin-password.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ password })
        });
        const result = await response.json();
        return result.success === true;
    } catch (error) {
        console.error('Admin password verification failed:', error);
        return false;
    }
}

function clearCart() {
    if (cart.length === 0) return;
    
    const message = currentOrderId > 0
        ? 'Clear the loaded order from the cart? The pending order will remain in the order list.'
        : 'Clear all items from cart?';

    if (confirm(message)) {
        cart = [];
        currentOrderId = 0;
        currentOrderNumber = '';
        updateCartDisplay();
        updateActionButtons();
        showNotification('Cart cleared', 'info');
    }
}

function updateCartDisplay() {
    const cartItems = document.getElementById('cart-items');
    const cartCount = document.getElementById('cart-count');
    const cartSubtotal = document.getElementById('cart-subtotal');
    const cartDiscount = document.getElementById('cart-discount');
    const cartTax = document.getElementById('cart-tax');
    const cartTotal = document.getElementById('cart-total');

    // Recalculate each item subtotal in case price or quantity was changed.
    cart.forEach((item) => {
        const price = toNumber(item.price);
        const qty = parseInt(item.quantity, 10);
        item.quantity = Number.isFinite(qty) && qty > 0 ? qty : 1;
        item.price = Number.isFinite(price) ? price : 0;
        item.subtotal = item.quantity * item.price;
    });

    // Update count badge
    const totalItems = cart.reduce((sum, item) => sum + toNumber(item.quantity), 0);
    cartCount.textContent = totalItems;
    
    if (cart.length === 0) {
        cartItems.innerHTML = `
            <div class="empty-cart">
                <i class="fas fa-shopping-cart fa-3x"></i>
                <p>Your cart is empty</p>
                <small>Click on products or scan barcode to add them</small>
            </div>
        `;
    } else {
        let html = '';
        cart.forEach((item, index) => {
            // Always show price mode dropdown for every cart item.
            const hasWholesale = Number.isFinite(parseOptionalPrice(item.wholesale_price));
            const hasCost = Number.isFinite(parseOptionalPrice(item.cost_price));
            const wholesalePrice = hasWholesale ? formatCurrency(item.wholesale_price) : 'N/A';
            const retailPrice = formatCurrency(item.retail_price);
            const costPrice = hasCost ? formatCurrency(item.cost_price) : 'N/A';
            const priceModeBadge = `
                <div class="cart-price-mode">
                    <select class="form-control form-control-sm" onchange="setCartItemPriceMode(${index}, this.value, this)">
                        <option value="retail" ${item.price_mode === 'retail' ? 'selected' : ''}>Retail (${retailPrice})</option>
                        <option value="wholesale" ${item.price_mode === 'wholesale' ? 'selected' : ''} ${!hasWholesale ? 'disabled' : ''}>Wholesale (${wholesalePrice})</option>
                        <option value="cost" ${item.price_mode === 'cost' ? 'selected' : ''} ${!hasCost ? 'disabled' : ''}>Cost (${costPrice})</option>
                    </select>
                </div>
            `;
            
            // Format the price based on current mode
            const currentPriceDisplay = formatCurrency(item.price);
            const priceIndicator = item.is_balance_item === true
                ? 'BAL'
                : (item.price_mode === 'wholesale' ? 'W' : (item.price_mode === 'cost' ? 'C' : 'R'));
            const qtyInputAttributes = item.is_balance_item === true
                ? 'readonly style="background:#f8fafc; cursor:not-allowed;"'
                : '';

            const inlineBalanceItems = (item.is_balance_item === true && Array.isArray(item.balance_items))
                ? item.balance_items.filter(bi => toNumber(bi.quantity) > 0)
                : [];
            const balanceItemsHtml = inlineBalanceItems.length
                ? `
                    <div style="margin-top:6px; font-size:0.86em; color:#475569;">
                        <div style="font-weight:700; color:#334155;">Bought items</div>
                        ${inlineBalanceItems.map(bi => `
                            <div style="margin-top:2px; padding-left:10px;">- ${escapeHtml(bi.product_name || 'Item')} x${toNumber(bi.quantity)}</div>
                        `).join('')}
                    </div>
                `
                : '';
            
            html += `
                <div class="cart-item">
                    <div class="cart-item-header">
                        <div class="cart-item-name" title="${escapeHtml(item.product_name)}">
                            ${escapeHtml(item.product_name)}
                            <small style="color: #6c757d; margin-left: 5px;">(${priceIndicator})</small>
                            ${balanceItemsHtml}
                        </div>
                        <div class="cart-item-subtotal">${formatCurrency(item.subtotal)}</div>
                    </div>
                    ${priceModeBadge}
                    <div class="cart-item-details">
                        <div class="cart-item-actions">
                            <input
                                class="cart-qty-input"
                                type="number"
                                min="1"
                                value="${item.quantity}"
                                onchange="setCartQuantity(${index}, this.value)"
                                onkeydown="if(event.key==='Enter'){event.preventDefault(); this.blur();}"
                                aria-label="Quantity"
                                ${qtyInputAttributes}
                            >
                            <button class="cart-btn delete" onclick="removeFromCart(${index})" aria-label="Remove item">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                        <div class="cart-item-price">
                            <small class="text-muted">@ ${currentPriceDisplay}</small>
                        </div>
                    </div>
                </div>
            `;
        });
        cartItems.innerHTML = html;
    }
    
    // Calculate totals
    const subtotal = cart.reduce((sum, item) => sum + item.subtotal, 0);
    const discountState = syncRetailDiscountControls();
    const discountPercent = discountState.percent;
    const discount = discountState.applied;
    const tax = 0;
    const total = Math.max(subtotal - discount, 0);
    
    cartSubtotal.textContent = formatCurrency(subtotal);
    if (cartDiscount) {
        cartDiscount.textContent = discount > 0
            ? `-${formatCurrency(discount)} (${formatDiscountPercent(discountPercent)})`
            : `${formatCurrency(0)} (${formatDiscountPercent(0)})`;
    }
    if (cartTax) cartTax.textContent = formatCurrency(tax);
    cartTotal.textContent = formatCurrency(total);
    
    // Recalculate change if cash payment
    calculateChange();
    renderExchangeCreditBanner();
}

function highlightProduct(productId) {
    const productCard = document.querySelector(`.product-card[data-id="${productId}"]`);
    if (productCard) {
        productCard.classList.add('highlight');
        setTimeout(() => {
            productCard.classList.remove('highlight');
        }, 500);
    }
}

async function saveOrder() {
    if (cart.length === 0) {
        showNotification('Cart is empty', 'warning');
        return;
    }

    const saveBtn = document.getElementById('save-order');
    const originalText = saveBtn.innerHTML;
    saveBtn.disabled = true;
    saveBtn.innerHTML = currentOrderId > 0
        ? '<i class="fas fa-spinner fa-spin mr-2"></i> Updating...'
        : '<i class="fas fa-spinner fa-spin mr-2"></i> Saving...';

    const orderData = buildPendingOrderPayload();

    try {
        // Use local reservation handler in this view to account for pending reservations
        const endpoint = window.location.pathname; // posts back to this PHP view
        orderData.action = currentOrderId > 0 ? 'reserve_update' : 'reserve_order';
        if (currentOrderId > 0) orderData.order_id = currentOrderId;

        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(orderData)
        });

        const result = await response.json();
        if (!result.success) {
            throw new Error(result.message || 'Failed to save order');
        }

        if (currentOrderId > 0) {
            showNotification('Order updated', 'success');
            currentOrderNumber = String(result.data.invoice_number || currentOrderNumber || '');
        } else {
            showNotification('Order saved', 'success');
            resetSaleForm();
            window.location.href = `/pos-system/views/sales/order.php?highlight=${encodeURIComponent(result.data.id)}`;
        }
    } catch (error) {
        console.error('Save order error:', error);
        showNotification(error.message || 'Failed to save order', 'error');
    } finally {
        saveBtn.disabled = false;
        saveBtn.innerHTML = originalText;
        updateActionButtons();
    }
}

async function loadPendingOrder(orderId) {
    if (!orderId || orderId <= 0) {
        updateActionButtons();
        return;
    }

    try {
        const response = await fetch(`${API_BASE_URL}/sales/get-order.php?id=${encodeURIComponent(orderId)}`);
        const result = await response.json();

        if (!result.success) {
            throw new Error(result.message || 'Unable to load order');
        }

        const order = result.data.sale || {};
        const items = result.data.items || [];

        currentOrderId = parseInt(order.id, 10) || 0;
        currentOrderNumber = String(order.invoice_number || '');
        document.getElementById('customer-name').value = order.customer_name || '';
        document.getElementById('customer-phone').value = order.customer_phone || '';
        document.getElementById('sale-notes').value = order.notes || '';
        if (document.getElementById('teller-name')) {
            document.getElementById('teller-name').value = order.teller_name || '';
        }

        const paymentMethod = order.payment_method || 'cash';
        document.getElementById('payment-method').value = paymentMethod;

        const loadedModes = [];
        cart = items.map((item) => {
            const unitPrice = toNumber(item.price_per_unit);
            const quantity = parseInt(item.quantity, 10) || 1;
            const productId = parseInt(item.product_id, 10) || 0;
            const retailPrice = toNumber(item.retail_price ?? item.product_price ?? unitPrice);
            const wholesalePrice = item.wholesale_price ?? null;
            const costPrice = item.cost_price ?? null;
            const mode = inferPriceMode(unitPrice, retailPrice, wholesalePrice, costPrice);
            const hasCatalogProduct = productId > 0;
            const wholesaleNumber = parseOptionalPrice(wholesalePrice);
            const costNumber = parseOptionalPrice(costPrice);
            const matchesRetail = Math.abs(retailPrice - unitPrice) < 0.0001;
            const matchesWholesale = Number.isFinite(wholesaleNumber) && Math.abs(wholesaleNumber - unitPrice) < 0.0001;
            const matchesCost = Number.isFinite(costNumber) && Math.abs(costNumber - unitPrice) < 0.0001;
            const knownCatalogPrice = hasCatalogProduct && (matchesRetail || matchesWholesale || matchesCost);

            if (knownCatalogPrice) {
                loadedModes.push(mode);
            }

            return {
                product_id: productId,
                product_name: item.product_name,
                retail_price: retailPrice,
                wholesale_price: wholesalePrice,
                cost_price: costPrice,
                price_mode: mode,
                price_mode_label: priceModeLabel(mode),
                price_mode_locked: !knownCatalogPrice,
                fixed_price: !knownCatalogPrice,
                fixed_unit_price: knownCatalogPrice ? null : unitPrice,
                price: unitPrice,
                quantity,
                stock: Math.max(parseInt(item.stock_quantity, 10) || 0, quantity),
                subtotal: unitPrice * quantity
            };
        });

        const productModes = loadedModes.filter(mode => mode === 'retail' || mode === 'wholesale');
        const commonMode = productModes.length > 0 && productModes.every(mode => mode === productModes[0])
            ? productModes[0]
            : 'retail';
        const priceModeSelect = document.getElementById('price-mode');
        if (priceModeSelect) {
            priceModeSelect.value = commonMode;
        }
        priceMode = commonMode;

        const retailDiscountInput = document.getElementById('retail-discount');
        if (retailDiscountInput) {
            let loadedDiscountPercent = Math.max(0, toNumber(order.discount_percent || 0));
            if (loadedDiscountPercent <= 0) {
                const eligibleRetailSubtotal = cart.reduce((sum, item) => {
                    return isRetailDiscountEligibleItem(item) ? sum + toNumber(item.subtotal) : sum;
                }, 0);
                const savedDiscountAmount = Math.max(0, toNumber(order.discount_amount || 0));
                if (eligibleRetailSubtotal > 0.0001 && savedDiscountAmount > 0) {
                    loadedDiscountPercent = Math.min(100, (savedDiscountAmount / eligibleRetailSubtotal) * 100);
                }
            }
            retailDiscountInput.value = loadedDiscountPercent.toFixed(2);
        }

        updateCartDisplay();
        toggleCashPayment();
        updateActionButtons();
        showNotification(`Loaded pending order ${currentOrderNumber || '#' + currentOrderId}`, 'info');
    } catch (error) {
        console.error('Load order error:', error);
        showNotification(error.message || 'Unable to load order', 'error');
    }
}

async function sendPendingOrderApproval(total, cashAmount, shortAmount) {
    const discountPercent = getAppliedRetailDiscountPercent();
    const discountAmount = getAppliedRetailDiscountAmount();
    
    const saleData = {
        order_id: currentOrderId,  // Important: Include the existing order ID
        payment_method: 'cash',
        discount_percent: discountPercent,
        discount_amount: discountAmount,
        cash_amount: cashAmount,
        outstanding_amount: shortAmount, // Store the balance due
        customer_name: document.getElementById('customer-name').value.trim() || null,
        customer_phone: document.getElementById('customer-phone').value.trim() || null,
        teller_name: document.getElementById('teller-name') ? (document.getElementById('teller-name').value.trim() || null) : null,
        notes: document.getElementById('sale-notes').value.trim() || null,
        items: cart.map(item => ({
            product_id: item.product_id,
            product_name: item.product_name,
            quantity: item.quantity,
            price: item.price,
            price_mode: item.price_mode,
            is_balance_item: item.is_balance_item === true,
            source_balance_id: item.source_balance_id || null
        }))
    };

    const response = await fetch(`${API_BASE_URL}/sales/complete-order.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({
            order_id: currentOrderId,
            payment_method: 'cash',
            discount_percent: discountPercent,
            discount_amount: discountAmount,
            cash_amount: cashAmount,
            change_amount: 0,
            customer_name: document.getElementById('customer-name').value.trim() || null,
            customer_phone: document.getElementById('customer-phone').value.trim() || null,
            teller_name: document.getElementById('teller-name') ? (document.getElementById('teller-name').value.trim() || null) : null,
            notes: document.getElementById('sale-notes').value.trim() || null,
            items: cart.map(item => ({
                product_id: item.product_id,
                product_name: item.product_name,
                quantity: item.quantity,
                price: item.price,
                subtotal: item.subtotal,
                price_mode: item.price_mode,
                retail_price: item.retail_price,
                wholesale_price: item.wholesale_price,
                cost_price: item.cost_price,
                is_balance_item: item.is_balance_item === true,
                source_balance_id: item.source_balance_id || null
            }))
        })
    });

    const result = await response.json();
    if (!result.success) {
        throw new Error(result.message || 'Unable to send for approval');
    }

    showNotification(result.message || `Order processed with balance of ₱${shortAmount.toFixed(2)}. Customer can pay later.`, 'info');
    resetSaleForm();
    
    // Remove the order_id from URL so it doesn't reload the same order
    const url = new URL(window.location.href);
    url.searchParams.delete('order_id');
    window.history.replaceState({}, document.title, url.pathname);
}

async function completePendingOrder() {
    const paymentMethod = document.getElementById('payment-method').value;

    const tellerEl = document.getElementById('teller-name');
    const tellerName = tellerEl ? tellerEl.value.trim() : '';
    if (!tellerName) {
        showNotification('Salesman name is required to complete the transaction.', 'warning');
        tellerEl?.focus();
        return;
    }

    if (paymentMethod === 'cash') {
        const total = getCartTotalValue();
        const cashInputEl = document.getElementById('cash-amount');
        let cash = parseFloat(cashInputEl?.value) || 0;

        if (cash < total) {
            const short = total - cash;
            const proceed = confirm(`Cash is short by ₱${short.toFixed(2)}.\n\nThis will mark the order as having a balance. The order will be removed from the pending list.\n\nProceed?`);
            if (proceed) {
                // Process as balance order and remove from pending list
                const processBtn = document.getElementById('process-sale');
                const originalText = processBtn.innerHTML;
                processBtn.disabled = true;
                processBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';
                
                try {
                    await sendPendingOrderApproval(total, cash, short);
                    return;
                } catch (err) {
                    console.error(err);
                    showNotification(err.message || 'Failed to process balance order', 'error');
                } finally {
                    processBtn.disabled = false;
                    processBtn.innerHTML = originalText;
                }
            }
            return;
        }
    }

    const processBtn = document.getElementById('process-sale');
    const originalText = processBtn.innerHTML;
    processBtn.disabled = true;
    processBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';

    const { cashAmount, changeAmount } = getCashPaymentValues(paymentMethod);
    const discountPercent = getAppliedRetailDiscountPercent();
    const discountAmount = getAppliedRetailDiscountAmount();

    try {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);

        const response = await fetch(`${API_BASE_URL}/sales/complete-order.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                order_id: currentOrderId,
                payment_method: paymentMethod,
                discount_percent: discountPercent,
                discount_amount: discountAmount,
                cash_amount: cashAmount,
                change_amount: changeAmount,
                customer_name: document.getElementById('customer-name').value.trim() || null,
                customer_phone: document.getElementById('customer-phone').value.trim() || null,
                teller_name: document.getElementById('teller-name') ? (document.getElementById('teller-name').value.trim() || null) : null,
                notes: document.getElementById('sale-notes').value.trim() || null,
                items: cart.map(item => ({
                    product_id: item.product_id,
                    product_name: item.product_name,
                    quantity: item.quantity,
                    price: item.price,
                    subtotal: item.subtotal,
                    price_mode: item.price_mode,
                    retail_price: item.retail_price,
                    wholesale_price: item.wholesale_price,
                    cost_price: item.cost_price,
                    is_balance_item: item.is_balance_item === true,
                    source_balance_id: item.source_balance_id || null,
                    balance_items: Array.isArray(item.balance_items) ? item.balance_items : []
                }))
            }),
            signal: controller.signal
        });

        clearTimeout(timeout);

        const raw = await response.text();
        let result;
        try {
            result = JSON.parse(raw);
        } catch (e) {
            console.error('Complete-order response not JSON:', raw);
            throw new Error('Server returned invalid response while completing the order.');
        }
        if (!result.success) {
            throw new Error(result.message || 'Failed to complete order');
        }

        showNotification('Payment completed successfully! Order removed from pending list.', 'success');
        
        // Show receipt
        const receiptItems = cart.map(item => ({
            product_id: item.product_id,
            product_name: item.product_name,
            quantity: item.quantity,
            unit_price: item.price,
            price_per_unit: item.price,
            subtotal: item.subtotal,
            price_mode: item.price_mode,
            retail_price: item.retail_price,
            wholesale_price: item.wholesale_price,
            cost_price: item.cost_price,
            is_balance_item: item.is_balance_item === true,
            source_balance_id: item.source_balance_id || null,
            balance_items: Array.isArray(item.balance_items) ? item.balance_items : []
        }));

        showReceipt({
            sale: result.data.sale,
            items: receiptItems.length ? receiptItems : (result.data.items || []),
            settled_balances: result.data.settled_balances || []
        });
        
        if (result.data && result.data.reserved_map && typeof result.data.reserved_map === 'object') {
            try {
                Object.keys(result.data.reserved_map).forEach(pidStr => {
                    const pid = parseInt(pidStr, 10);
                    const val = parseInt(result.data.reserved_map[pidStr], 10) || 0;
                    const card = document.querySelector(`.product-card[data-id="${pid}"]`);
                    if (card) {
                        card.dataset.reserved = String(val);
                    }
                });
            } catch (e) {
                console.warn('Failed to apply reserved_map from server', e);
            }
        }
        
        try { refreshProductStocksForItems(receiptItems); } catch (e) { console.warn('refreshProductStocksForItems failed', e); }
        
        // Reset the form which also removes order_id from URL
        resetSaleForm();
        updateActionButtons();
        
    } catch (error) {
        console.error('Complete order error:', error);
        const msg = error.name === 'AbortError'
            ? 'Request timed out. Please check your connection and try again.'
            : (error.message || 'Failed to complete order');
        showNotification(msg, 'error');
    } finally {
        processBtn.disabled = false;
        processBtn.innerHTML = originalText;
        updateActionButtons();
    }
}

// ==================== Process Sale ====================
async function processSale() {
    if (cart.length === 0) {
        showNotification('Cart is empty', 'warning');
        return;
    }

    const tellerEl = document.getElementById('teller-name');
    const tellerName = tellerEl ? tellerEl.value.trim() : '';
    if (!tellerName) {
        showNotification('Salesman name is required to complete the transaction.', 'warning');
        tellerEl?.focus();
        return;
    }

    if (currentOrderId > 0) {
        await completePendingOrder();
        return;
    }
    
    const paymentMethod = document.getElementById('payment-method').value;
    const processBtn = document.getElementById('process-sale');
    const originalText = processBtn.innerHTML;
    
    // For cash payments, check if cash amount is sufficient
    if (paymentMethod === 'cash') {
        const total = getCartTotalValue();
        const cash = parseFloat(document.getElementById('cash-amount').value) || 0;
        
        if (cash < total) {
            const short = total - cash;
            const proceed = confirm(`Cash is short by ₱${short.toFixed(2)}.\n\nThis will mark the order as having a balance.\n\nProceed?`);
            if (!proceed) return;

            try {
                processBtn.disabled = true;
                processBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';
                await sendShortCashApproval(total, cash, short, paymentMethod);
            } catch (err) {
                console.error(err);
                showNotification(err.message || 'Failed to process balance order', 'error');
            } finally {
                processBtn.disabled = false;
                processBtn.innerHTML = originalText;
            }
            return;
        }
    }
    processBtn.disabled = true;
    processBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';
    
    // Get cash amount if payment is cash
    const { cashAmount, changeAmount } = getCashPaymentValues(paymentMethod);
    
    // Calculate totals
    const subtotal = cart.reduce((sum, item) => sum + item.subtotal, 0);
    const discountPercent = getAppliedRetailDiscountPercent();
    const discountAmount = getAppliedRetailDiscountAmount();
    const tax = 0;
    const total = Math.max(subtotal - discountAmount, 0);
    
    // Get Philippine time for invoice number
    const now = new Date();
    const philippineTime = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
    const year = philippineTime.getFullYear();
    const month = String(philippineTime.getMonth() + 1).padStart(2, '0');
    const day = String(philippineTime.getDate()).padStart(2, '0');
    const dateStr = `${year}${month}${day}`;
    const random = Math.floor(Math.random() * 9999).toString().padStart(4, '0').toUpperCase();
    const invoiceNumber = 'INV-' + dateStr + '-' + random;
    
    // Prepare sale data - make sure to include the actual prices used
    const saleData = {
        invoice_number: invoiceNumber,
        total_amount: total,
        subtotal: subtotal,
        discount_percent: discountPercent,
        discount_amount: discountAmount,
        tax_amount: tax,
        payment_method: paymentMethod,
        payment_status: 'paid',
        cash_amount: cashAmount,
        change_amount: changeAmount,
        customer_name: document.getElementById('customer-name').value.trim() || null,
        customer_address: document.getElementById('customer-address').value.trim() || null,
        customer_phone: document.getElementById('customer-phone').value.trim() || null,
        teller_name: document.getElementById('teller-name') ? (document.getElementById('teller-name').value.trim() || null) : null,
        notes: document.getElementById('sale-notes').value.trim() || null,
        user_id: <?php echo $_SESSION['user_id'] ?? 0; ?>,
        created_at: philippineTime.toISOString(), // Use Philippine time
        items: cart.map(item => ({
            product_id: item.product_id,
            product_name: item.product_name,
            quantity: item.quantity,
            price: item.price,
            unit_price: item.price,
            subtotal: item.subtotal,
            price_mode: item.price_mode,
            retail_price: item.retail_price,
            wholesale_price: item.wholesale_price,
            cost_price: item.cost_price,
            is_balance_item: item.is_balance_item === true,
            source_balance_id: item.source_balance_id || null
        }))
    };
    
    console.log('Sending sale data:', JSON.stringify(saleData, null, 2));
    
    try {
        const response = await fetch(`${API_BASE_URL}/sales/process.php`, {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(saleData)
        });
        
        // Get response text first for debugging
        const responseText = await response.text();
        console.log('Raw response:', responseText);
        
        // Try to parse JSON
        let result;
        try {
            result = JSON.parse(responseText);
        } catch (e) {
            console.error('Failed to parse JSON:', responseText);
            throw new Error('Server returned invalid JSON. Check console for details.');
        }
        
        if (result.success) {
            showNotification('✅ Sale completed successfully!', 'success');
            
            if (Array.isArray(result.data?.settled_balances) && result.data.settled_balances.length) {
                const settledTotal = result.data.settled_balances.reduce((sum, row) => sum + toNumber(row.amount), 0);
                showNotification(`Previous balance settled automatically: ${formatCurrency(settledTotal)}`, 'info');
            }

            // Build receipt payload with the actual cart data (preserving price modes)
            const receiptData = {
                sale: {
                    invoice_number: invoiceNumber,
                    total_amount: total,
                    discount_percent: discountPercent,
                    discount_amount: discountAmount,
                    payment_method: paymentMethod,
                    cash_amount: cashAmount,
                    change_amount: changeAmount,
                    customer_name: saleData.customer_name,
                    teller_name: saleData.teller_name,
                    notes: saleData.notes,
                    created_at: philippineTime.toISOString()
                },
                items: cart.map(item => ({
                    product_id: item.product_id,
                    product_name: item.product_name,
                    quantity: item.quantity,
                    unit_price: item.price,
                    subtotal: item.subtotal,
                    price_per_unit: item.price,
                    price_mode: item.price_mode,
                    retail_price: item.retail_price,
                    wholesale_price: item.wholesale_price,
                    cost_price: item.cost_price,
                    is_balance_item: item.is_balance_item === true,
                    source_balance_id: item.source_balance_id || null,
                    balance_items: Array.isArray(item.balance_items) ? item.balance_items : []
                })),
                settled_balances: result.data?.settled_balances || []
            };
            
            showReceipt(receiptData);
            try { refreshProductStocksForItems(receiptData.items || []); } catch (e) { console.warn('refreshProductStocksForItems failed', e); }
            
            resetSaleForm();
            
        } else {
            throw new Error(result.message || 'Sale failed');
        }
    } catch (error) {
        console.error('Sale error:', error);
        showNotification('❌ ' + error.message, 'error');
    } finally {
        processBtn.disabled = false;
        processBtn.innerHTML = originalText;
    }
}

// When cash is short, create a pending sale and approval request for admin
async function sendShortCashApproval(total, cashAmount, shortAmount, paymentMethod) {
    const discountPercent = getAppliedRetailDiscountPercent();
    const discountAmount = getAppliedRetailDiscountAmount();
    const saleData = {
        payment_method: paymentMethod,
        discount_percent: discountPercent,
        discount_amount: discountAmount,
        cash_amount: cashAmount,
        outstanding_amount: shortAmount,
        customer_name: document.getElementById('customer-name').value.trim() || null,
        customer_phone: document.getElementById('customer-phone').value.trim() || null,
        teller_name: document.getElementById('teller-name') ? (document.getElementById('teller-name').value.trim() || null) : null,
        notes: document.getElementById('sale-notes').value.trim() || null,
        items: cart.map(item => ({
            product_id: item.product_id,
            product_name: item.product_name,
            quantity: item.quantity,
            price: item.price,
            price_mode: item.price_mode,
            is_balance_item: item.is_balance_item === true,
            source_balance_id: item.source_balance_id || null
        }))
    };

    if (currentOrderId > 0) {
        saleData.order_id = currentOrderId;
    }

    const response = await fetch(`${API_BASE_URL}/sales/request-approval.php`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify(saleData)
    });

    const result = await response.json();
    if (!result.success) {
        throw new Error(result.message || 'Unable to process balance order');
    }

    showNotification(result.message || `Order processed with balance of ₱${shortAmount.toFixed(2)}. Customer can pay later.`, 'info');
    
    // Show receipt with balance due
    showReceipt({
        sale: result.data.sale || {
            id: result.data.sale_id || null,
            invoice_number: result.data.invoice_number || null,
            total_amount: result.data.total_amount || null,
            cash_amount: result.data.cash_amount || null,
            change_amount: 0,
            payment_status: 'pending',
            approval_status: 'pending',
            approval_short_amount: shortAmount
        },
        items: result.data.items || [],
        balance_due: shortAmount,
        payment_status: 'pending',
        approval_status: 'pending',
        approval_short_amount: shortAmount
    });
    
    resetSaleForm();
}

// ==================== Receipt Functions ====================
function getReceiptPriceMode(item) {
    const explicitMode = String(item.price_mode || '').toLowerCase();
    if (explicitMode === 'retail' || explicitMode === 'wholesale' || explicitMode === 'cost') {
        return explicitMode;
    }

    const unitPrice = toNumber(item.unit_price || item.price_per_unit || item.price);
    const wholesale = parseOptionalPrice(item.wholesale_price);
    const cost = parseOptionalPrice(item.cost_price);
    const retail = parseOptionalPrice(item.retail_price);

    if (Number.isFinite(cost) && Math.abs(cost - unitPrice) < 0.0001) return 'cost';
    if (Number.isFinite(wholesale) && Math.abs(wholesale - unitPrice) < 0.0001) return 'wholesale';
    if (Number.isFinite(retail) && Math.abs(retail - unitPrice) < 0.0001) return 'retail';
    return 'retail';
}

function isPendingApprovalBalanceReceipt(saleData = {}) {
    const sale = saleData.sale || {};
    const paymentStatus = String(sale.payment_status || saleData.payment_status || '').toLowerCase();
    const approvalStatus = String(sale.approval_status || saleData.approval_status || '').toLowerCase();
    const approvalShortAmount = toNumber(sale.approval_short_amount || saleData.approval_short_amount || sale.short_amount || saleData.short_amount || 0);

    if (approvalStatus === 'approved') {
        return false;
    }

    if (approvalStatus === 'pending') {
        return approvalShortAmount > 0;
    }

    const totalAmount = toNumber(sale.total_amount || saleData.total_amount || 0);
    const cashAmount = toNumber(sale.cash_amount || saleData.cash_amount || 0);
    return paymentStatus === 'pending' && totalAmount > 0 && cashAmount > 0 && cashAmount < totalAmount;
}

function showReceipt(saleData) {
    const modal = document.getElementById('receiptModal');
    const content = document.getElementById('receipt-content');

    if (modal) {
        modal.dataset.printable = 'false';
    }
    
    const items = saleData.items || [];
    // store items temporarily so closeModal can access them when receipt is closed
    try { window.__lastReceiptItems = items; } catch (e) { /* ignore */ }
    const sale = saleData.sale || {};
    const cashierName = sale.cashier_name || sale.created_by || CURRENT_USERNAME || 'System';
    const settledBalances = Array.isArray(saleData.settled_balances) ? saleData.settled_balances : [];
    const settledBalanceMap = new Map(
        settledBalances.map(balance => [Number(balance.sale_id || 0), balance]).filter(([saleId]) => saleId > 0)
    );
    
    // Get Philippine time
    const now = new Date();
    const philippineTime = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
    
    let receiptDate;
    if (sale.created_at) {
        receiptDate = new Date(sale.created_at);
    } else {
        receiptDate = philippineTime;
    }
    
    // Format date for Philippines (MM/DD/YYYY)
    const month = String(receiptDate.getMonth() + 1).padStart(2, '0');
    const day = String(receiptDate.getDate()).padStart(2, '0');
    const year = receiptDate.getFullYear();
    const formattedDate = `${month}/${day}/${year}`;
    
    // Format time for Philippines (HH:MM AM/PM)
    let hours = receiptDate.getHours();
    const minutes = String(receiptDate.getMinutes()).padStart(2, '0');
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12;
    hours = hours ? hours : 12;
    const formattedTime = `${String(hours).padStart(2, '0')}:${minutes} ${ampm}`;
    
    let itemsHtml = '';
    let itemsSubtotal = 0;
    items.forEach(item => {
        const mode = getReceiptPriceMode(item);
        let indicator = '';
        if (mode === 'cost') indicator = 'C';
        else if (mode === 'wholesale') indicator = 'W';
        else indicator = 'R';
        
        const unitPriceValue = toNumber(item.unit_price || item.price_per_unit || item.price);
        const totalValue = item.subtotal !== undefined ? toNumber(item.subtotal) : unitPriceValue * toNumber(item.quantity);
        itemsSubtotal += totalValue;
        const unitPrice = `${indicator} ${formatCurrency(unitPriceValue)}`;
        const totalPrice = formatCurrency(totalValue);
        const balanceSourceId = Number(item.source_balance_id || 0);
        const linkedBalance = balanceSourceId > 0 ? settledBalanceMap.get(balanceSourceId) : null;
        const inlineBalanceItems = Array.isArray(item.balance_items) ? item.balance_items : [];
        const originalBalanceItems = inlineBalanceItems.length
            ? inlineBalanceItems
            : (linkedBalance && Array.isArray(linkedBalance.items) ? linkedBalance.items : []);
        let productCellHtml = escapeHtml(item.product_name);

        if (originalBalanceItems.length > 0) {
            const originalItemsHtml = originalBalanceItems.map(balanceItem => `
                <div style="font-size: 0.88em; color: #555; margin-top: 2px; padding-left: 10px;">
                    - ${escapeHtml(balanceItem.product_name || 'Item')} x${toNumber(balanceItem.quantity)}
                </div>
            `).join('');

            productCellHtml += `
                <div style="font-size: 0.88em; color: #444; margin-top: 4px; font-weight: 700;">
                    Bought items from ${escapeHtml((linkedBalance && linkedBalance.invoice_number) || item.source_balance_invoice || ('#' + balanceSourceId))}
                </div>
                ${originalItemsHtml}
            `;
        }
        
        itemsHtml += `
            <tr>
                <td class="qty-cell">${item.quantity}</td>
                <td class="product-cell">${productCellHtml}</td>
                <td class="price-cell">${unitPrice}</td>
                <td class="total-cell"><strong>${totalPrice}</strong></td>
            </tr>
        `;
    });
    
    const discountAmount = toNumber(sale.discount_amount || 0);
    const subtotal = itemsSubtotal;
    const tax = 0;
    const total = sale.total_amount !== undefined
        ? toNumber(sale.total_amount)
        : Math.max(subtotal - discountAmount, 0);
    const balanceDue = toNumber(saleData.balance_due || sale.outstanding_amount || sale.approval_short_amount || 0);
    const originalCashAmount = toNumber(sale.original_cash_amount || sale.cash_paid_amount || sale.cash_amount);
    const showBalanceDetails = balanceDue > 0;
    const isPendingApprovalBalance = isPendingApprovalBalanceReceipt(saleData);
    window.__currentReceiptPrintable = !isPendingApprovalBalance;
    const printButton = document.querySelector('#receiptModal .modal-footer .btn-primary');
    if (printButton) {
        printButton.disabled = isPendingApprovalBalance;
        printButton.setAttribute('aria-disabled', isPendingApprovalBalance ? 'true' : 'false');
        printButton.innerHTML = isPendingApprovalBalance
            ? '<i class="fas fa-lock mr-2"></i> Pending Approval'
            : '<i class="fas fa-print mr-2"></i> Print';
        printButton.title = isPendingApprovalBalance
            ? 'Receipt can only be printed after admin approval'
            : 'Print receipt';
    }
    if (modal) {
        modal.dataset.printable = isPendingApprovalBalance ? 'false' : 'true';
    }
    
    let paymentDetails = '';
    if (sale.payment_method === 'cash' && sale.cash_amount) {
        paymentDetails = `
            <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #ddd;">
                <div class="payment-row">
                    <strong>Cash:</strong>
                    <strong style="color: #000000; font-weight: bold;">${formatCurrency(sale.cash_amount)}</strong>
                </div>
                <div class="payment-row" style="margin-top: 6px;">
                    <strong>Change:</strong>
                    <strong style="color: #000000; font-weight: bold;">${formatCurrency(sale.change_amount)}</strong>
                </div>
                ${showBalanceDetails ? `
                <div class="payment-row" style="margin-top: 6px;">
                    <strong>Balance Due:</strong>
                    <strong style="color: #b91c1c; font-weight: bold;">${formatCurrency(balanceDue)}</strong>
                </div>` : ''}
            </div>
        `;
    }
    
    content.innerHTML = `
        <div class="receipt">
            <div class="receipt-header" style="text-align:center; padding-bottom: 8px; border-bottom: 2px solid #333;">
                <p style="margin: 0; font-size: 0.95rem; color: #555;">
                    <strong>${formattedDate} ${formattedTime}</strong>
                </p>
            </div>
            
            <div style="margin: 16px 0; padding: 12px 0; border-top: 1px dashed #999; border-bottom: 1px dashed #999;">
                <table class="receipt-table" style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 1px solid #333;">
                            <th class="qty-cell" style="text-align: left; padding: 8px 0 6px 0;">Qty</th>                                    
                            <th class="product-cell" style="text-align: left; padding: 8px 0 6px 0;">Item</th>
                            <th class="price-cell" style="text-align: right; padding: 8px 0 6px 0;">Price</th>
                            <th class="total-cell" style="text-align: right; padding: 8px 0 6px 0;">Total</th>
                        <tr>
                    </thead>
                    <tbody>
                        ${itemsHtml || '<tr><td colspan="4" class="text-center">No items</td></tr>'}
                    </tbody>
                </table>
            </div>
            
            <div class="receipt-totals" style="margin: 16px 0; padding: 12px 0;">
                <div class="total-row" style="display: flex; justify-content: space-between; margin: 8px 0; font-size: 1rem;">
                    <span>Subtotal:</span>
                    <span><strong>${formatCurrency(subtotal)}</strong></span>
                </div>
                ${discountAmount > 0 ? `
                <div class="total-row" style="display: flex; justify-content: space-between; margin: 8px 0; font-size: 1rem;">
                    <span>Discount:</span>
                    <span><strong>-${formatCurrency(discountAmount)}</strong></span>
                </div>
                ` : ''}
                <div class="total-row grand-total" style="display: flex; justify-content: space-between; margin: 12px 0; font-size: 1.2rem; font-weight: bold; padding-top: 8px; border-top: 2px solid #333;">
                    <span>Total:</span>
                    <span><strong>${formatCurrency(total)}</strong></span>
                </div>
            </div>
            
            <div class="payment-info" style="margin-top: 12px;">
                ${isPendingApprovalBalance ? `
                <div style="margin: 0 0 12px; padding: 10px; background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; color: #92400e; text-align: center;">
                    <strong>Receipt locked until admin approval.</strong>
                    <div style="font-size: 0.85rem; margin-top: 4px;">This balance transaction cannot be printed until the approval is completed.</div>
                </div>
                ` : ''}
                <div style="margin: 8px 0;">
                    <strong>Payment Method:</strong> <span style="float: right;">${sale.payment_method || 'cash'}</span>
                </div>
                <div style="margin: 8px 0;">
                    <strong>Cashier:</strong> <span style="float: right;">${escapeHtml(cashierName)}</span>
                </div>
                <div style="margin: 8px 0;">
                    <strong>Salesman:</strong> <span style="float: right;">${escapeHtml(sale.teller_name || '')}</span>
                </div>
                ${sale.customer_name ? `<div style="margin: 8px 0;">
                    <strong>Customer:</strong> <span style="float: right;">${escapeHtml(sale.customer_name)}</span>
                </div>` : ''}
                ${sale.notes ? `<div style="margin: 12px 0; padding-top: 10px; border-top: 1px dashed #999;">
                    <strong>Notes:</strong>
                    <div style="margin-top: 6px; white-space: pre-wrap; word-break: break-word;">${escapeHtml(sale.notes)}</div>
                </div>` : ''}
                ${paymentDetails}
                ${showBalanceDetails ? `
                <div style="margin-top: 12px; padding: 8px; background: #fef3c7; border-radius: 8px; text-align: center;">
                    <strong style="color: #92400e;">⚠️ Balance Due: ${formatCurrency(balanceDue)}</strong>
                    <div style="font-size: 0.85rem; color: #78350f; margin-top: 4px;">Please settle remaining balance</div>
                </div>
                ` : ''}
            </div>
            
            <div class="receipt-footer" style="text-align: center; margin-top: 20px; padding-top: 12px; border-top: 1px dashed #999;">
                <p style="margin: 8px 0; font-weight: bold;">Thank you for your purchase!</p>
                ${showBalanceDetails ? '<p style="margin: 4px 0; font-size: 0.85rem;">Balance due to be paid within agreed terms</p>' : ''}
            </div>
        </div>
    `;
    
    modal.classList.add('show');
}

function rememberPrinterProfile(profile) {
    try {
        localStorage.setItem(PRINTER_PROFILE_KEY, profile);
    } catch (err) {
        console.warn('Unable to save printer profile', err);
    }
}

function getPrinterProfile() {
    const select = document.getElementById('printer-profile');
    if (select && select.value) {
        return select.value;
    }
    try {
        return localStorage.getItem(PRINTER_PROFILE_KEY) || 'auto';
    } catch (err) {
        return 'auto';
    }
}

function initPrinterProfileSelector() {
    const select = document.getElementById('printer-profile');
    if (!select) return;

    try {
        const saved = localStorage.getItem(PRINTER_PROFILE_KEY);
        if (saved && Array.from(select.options).some(opt => opt.value === saved)) {
            select.value = saved;
        }
    } catch (err) {
        console.warn('Unable to load saved printer profile', err);
    }

    select.addEventListener('change', () => rememberPrinterProfile(select.value));
}

function printReceipt() {
    const receiptModal = document.getElementById('receiptModal');
    if (window.__currentReceiptPrintable === false || (receiptModal && receiptModal.dataset.printable === 'false')) {
        showNotification('Receipt can only be printed after admin approval', 'warning');
        return;
    }

    const receiptContent = document.getElementById('receipt-content').innerHTML;
    const printerProfile = getPrinterProfile();
    const useEpsonEnvelope = printerProfile === 'epson-c6';
    const printWindow = window.open('', '_blank');

    const pageStyles = useEpsonEnvelope ? `
                /* Epsonf941d2 preset: C6 envelope 114 x 162 mm */
                @page { size: 114mm 162mm; margin: 6mm; }
                * { box-sizing: border-box; }
                body { font-family: 'Courier New', monospace; width: 100%; max-width: calc(114mm - 12mm); margin: 0 auto; padding: 0; font-size: 11px; line-height: 1.28; }
                .receipt { text-align: left; width: 100%; }
                .receipt-header { margin-bottom: 10px; text-align: center; }
                .receipt-table { width: 100%; border-collapse: separate; border-spacing: 0; margin: 10px 0; }
                .receipt-table thead th { padding: 4px 2px; text-align: left; border-bottom: 1px dashed #000; font-size: 11px; }
                /* Two-column body so long item lists fit on a single envelope sheet */
                .receipt-table.two-col tbody { display: grid; grid-template-columns: repeat(2, 1fr); column-gap: 8px; row-gap: 6px; }
                .receipt-table.two-col tbody tr { display: grid; grid-template-columns: 18% 46% 16% 20%; width: 100%; border-bottom: 1px dashed #b6b6b6; padding-bottom: 4px; margin: 0; }
                .receipt-table.two-col tbody tr td { padding: 0 2px; text-align: left; font-size: 11px; }
                .receipt-table.two-col tbody tr .qty-cell { text-align: left; }
                .receipt-table.two-col tbody tr .price-cell { text-align: right; }
                .receipt-table.two-col tbody tr .total-cell { text-align: right; font-weight: 700; }
                .receipt-table.two-col tbody tr::after { content: ''; grid-column: 1 / -1; border-bottom: 1px dashed #e0e0e0; margin-top: 4px; }
                .receipt-table.two-col tbody tr:last-child::after { border-bottom: none; }
                .receipt-table.two-col tbody tr:nth-child(2n+1)::after { display: none; } /* avoid double borders per row pair */
                .receipt-table.two-col tbody tr:nth-child(2n) { border-left: 1px dashed #d0d0d0; padding-left: 6px; }
                .receipt-totals { margin-top: 10px; }
                .total-row { display: flex; justify-content: space-between; margin: 4px 0; font-size: 11px; }
                .grand-total { font-weight: bold; border-top: 1px dashed #000; padding-top: 4px; }
                table { page-break-inside: auto; }
                tr { page-break-inside: avoid; page-break-after: auto; }
            ` : `
                /* 2-inch thermal roll width; JS sets height dynamically so it prints as one long page */
                @page { size: 50.8mm auto; margin: 0; }
                * { box-sizing: border-box; }
                .receipt, table, thead, tbody, tfoot, tr, td, th, p, div, span { 
                    page-break-inside: avoid !important; 
                    break-inside: avoid !important; 
                }
                body { font-family: 'Courier New', monospace; width: 50.8mm; margin: 0 auto; padding: 4px; font-size: 11px; line-height: 1.25; }
                .receipt { text-align: center; page-break-inside: avoid; }
                .receipt-header { margin-bottom: 10px; }
                .receipt-table { width: 100%; border-collapse: collapse; margin: 8px 0; }
                .receipt-table th, .receipt-table td { padding: 3px 0; text-align: left; border-bottom: 1px dashed #000; font-size: 11px; }
                .receipt-totals { margin-top: 8px; }
                .total-row { display: flex; justify-content: space-between; margin: 3px 0; font-size: 11px; }
                .grand-total { font-weight: bold; border-top: 1px dashed #000; padding-top: 3px; }
                tr, .total-row { page-break-inside: avoid; }
            `;

    const dynamicPageStyleTag = useEpsonEnvelope ? '' : '<style id="dynamic-page-style"></style>';
    
    printWindow.document.write(`
        <html>
        <head>
            <title>Print Receipt</title>
            <style>
                ${pageStyles}
            </style>
            ${dynamicPageStyleTag}
        </head>
        <body>
            ${receiptContent}
        </body>
        </html>
    `);
    
    printWindow.document.close();
    printWindow.onload = () => {
        try {
            const doc = printWindow.document;
            if (!useEpsonEnvelope) {
                const PX_PER_MM = 3.7795; // 96dpi conversion
                const receipt = doc.querySelector('.receipt');
                const dyn = doc.getElementById('dynamic-page-style');
                if (receipt && dyn) {
                    const mm = Math.ceil(receipt.scrollHeight / PX_PER_MM) + 2; // small buffer
                    dyn.textContent = `@page { size: 50.8mm ${mm}mm; margin: 0; }`;
                }
            } else {
                // For fixed C6 envelope size, let the browser paginate naturally
                const receiptTable = doc.querySelector('.receipt-table');
                if (receiptTable) {
                    receiptTable.classList.add('two-col');
                }
                const receipt = doc.querySelector('.receipt');
                if (receipt) {
                    receipt.style.maxWidth = '100%';
                }
            }
        } catch (e) {
            console.error('Print sizing error:', e);
        }
        
        // allow style to apply
        setTimeout(() => {
            printWindow.focus();
            printWindow.print();
            // Keep the window open so the user can reprint or save as PDF
        }, 30);
    };
}

// ==================== Event Listeners ====================
function setupEventListeners() {
    // Search functionality
    const searchInput = document.getElementById('pos-search');
    if (searchInput) {
        searchInput.addEventListener('input', debounce(function(e) {
            productSearchTerm = String(e.target.value || '');
            applyProductCardFilters();
        }, 300));
    }
    
    // Category filter
    const categoryFilter = document.getElementById('pos-category');
    if (categoryFilter) {
        categoryFilter.addEventListener('change', function(e) {
            productCategoryFilter = String(e.target.value || '');
            applyProductCardFilters();
        });
    }
    
    // Cash amount input
    const cashInput = document.getElementById('cash-amount');
    if (cashInput) {
        cashInput.addEventListener('input', calculateChange);
        // Prevent mouse wheel from changing the number while scrolling
        cashInput.addEventListener('wheel', function(e) {
            if (document.activeElement === cashInput) {
                e.preventDefault();
            }
        }, { passive: false });
    }

    const discountInput = document.getElementById('retail-discount');
    if (discountInput) {
        discountInput.addEventListener('input', updateCartDisplay);
        discountInput.addEventListener('blur', function() {
            discountInput.value = Math.min(100, Math.max(0, toNumber(discountInput.value))).toFixed(2);
            updateCartDisplay();
        });
        discountInput.addEventListener('wheel', function(e) {
            if (document.activeElement === discountInput) {
                e.preventDefault();
            }
        }, { passive: false });
    }

    // Printer selector
    initPrinterProfileSelector();

    // Balance lookup inputs
    const balanceNameInput = document.getElementById('balance-search-name');
    const balancePhoneInput = document.getElementById('balance-search-phone');
    if (balanceNameInput) {
        balanceNameInput.addEventListener('input', debounce(searchCustomerBalance, 300));
    }
    if (balancePhoneInput) {
        balancePhoneInput.addEventListener('input', debounce(searchCustomerBalance, 300));
    }

    const customerNameInput = document.getElementById('customer-name');
    const customerPhoneInput = document.getElementById('customer-phone');
    const debouncedInlineBalanceLookup = debounce(() => {
        const currentKey = getCustomerBalanceLookupKey(
            document.getElementById('customer-name')?.value || '',
            document.getElementById('customer-phone')?.value || ''
        );
        if (inlineCustomerBalanceDismissedKey && inlineCustomerBalanceDismissedKey !== currentKey) {
            inlineCustomerBalanceDismissedKey = '';
        }
        lookupInlineCustomerBalance();
    }, 350);
    if (customerNameInput) {
        customerNameInput.addEventListener('input', debouncedInlineBalanceLookup);
    }
    if (customerPhoneInput) {
        customerPhoneInput.addEventListener('input', debouncedInlineBalanceLookup);
    }

    // Ensure product cards respond to clicks even if inline handlers fail.
    const productsGrid = document.getElementById('products-grid');
    if (productsGrid) {
        // make cards focusable and pointer-friendly
        productsGrid.querySelectorAll('.product-card').forEach(c => {
            if (!c.hasAttribute('tabindex')) c.setAttribute('tabindex', '0');
            if (!c.hasAttribute('role')) c.setAttribute('role', 'button');
            if (!c.style.cursor) c.style.cursor = 'pointer';
        });

        // Delegate clicks to the add-to-cart logic with defensive logging
        productsGrid.addEventListener('click', function (ev) {
            try {
                const card = ev.target.closest('.product-card');
                if (!card) return;
                const pid = parseInt(card.dataset.id, 10) || 0;
                if (!pid) {
                    console.warn('Product card clicked but no data-id found', card);
                    return;
                }
                if (typeof addToCart === 'function') {
                    addToCart(pid);
                } else if (typeof upsertCartItem === 'function') {
                    fetchProductAndAdd(pid);
                } else {
                    console.error('No addToCart/upsertCartItem function available');
                    try { showNotification('Internal error: unable to add item to cart', 'error'); } catch(e){}
                }
            } catch (err) {
                console.error('Error handling product click', err);
                try { showNotification('Error adding item to cart', 'error'); } catch(e){}
            }
        });
    }

    // Price mode selector
    const priceModeSelect = document.getElementById('price-mode');
    if (priceModeSelect) {
        priceModeSelect.addEventListener('change', async function() {
            const selectedMode = String(priceModeSelect.value || 'retail');

            // Require admin password when switching all invoice pricing to cost
            if (selectedMode === 'cost' && priceMode !== 'cost') {
                const password = await promptAdminPassword();
                if (!password) {
                    showNotification('Admin password required to use cost price', 'warning');
                    priceModeSelect.value = priceMode;
                    return;
                }

                const authorized = await verifyAdminPassword(password);
                if (!authorized) {
                    showNotification('Invalid admin credentials', 'error');
                    priceModeSelect.value = priceMode;
                    return;
                }
            }

            priceMode = getPriceMode();
            refreshCartPrices();
            updateProductGridPrices();
        });
    }

    // Cart width resize functionality
    const cartResizeHandle = document.getElementById('cart-resize-handle');
    const cartSection = document.getElementById('cart-section');
    const posContainer = document.querySelector('.pos-container');
    
    if (cartResizeHandle && cartSection && posContainer) {
        let isResizing = false;
        let startX = 0;
        let startWidth = 0;

        cartResizeHandle.addEventListener('mousedown', (e) => {
            if (e.button !== 0) return; // Only left click
            
            isResizing = true;
            startX = e.clientX;
            startWidth = cartSection.offsetWidth;
            
            // Prevent text selection during resize
            document.body.style.userSelect = 'none';
            document.body.style.cursor = 'ew-resize';
            cartResizeHandle.style.background = 'linear-gradient(to right, #4361ee, transparent)';
        });

        document.addEventListener('mousemove', (e) => {
            if (!isResizing) return;
            
            // Calculate new width (negative because dragging left makes it wider)
            const delta = startX - e.clientX;
            const newWidth = startWidth + delta;
            
            // Set minimum width to 300px and maximum to 80% of viewport
            const minWidth = 300;
            const maxWidth = window.innerWidth * 0.8;
            
            if (newWidth >= minWidth && newWidth <= maxWidth) {
                posContainer.style.setProperty('--cart-width', newWidth + 'px');
            }
        });

        document.addEventListener('mouseup', () => {
            if (isResizing) {
                isResizing = false;
                document.body.style.userSelect = '';
                document.body.style.cursor = '';
                cartResizeHandle.style.background = 'transparent';
            }
        });
    }
}

// Runtime diagnostics: show JS errors in-page and add keyboard fallback for product cards
(function() {
    try {
        const overlay = document.createElement('div');
        overlay.id = 'js-error-overlay';
        overlay.style.position = 'fixed';
        overlay.style.right = '12px';
        overlay.style.top = '68px';
        overlay.style.zIndex = '99999';
        overlay.style.maxWidth = '360px';
        overlay.style.padding = '10px 12px';
        overlay.style.borderRadius = '8px';
        overlay.style.background = 'rgba(255,255,255,0.98)';
        overlay.style.boxShadow = '0 6px 18px rgba(0,0,0,0.12)';
        overlay.style.color = '#b91c1c';
        overlay.style.fontSize = '13px';
        overlay.style.display = 'none';
        overlay.style.overflow = 'auto';
        overlay.style.maxHeight = '40vh';
        overlay.innerText = '';
        document.addEventListener('DOMContentLoaded', function() {
            document.body.appendChild(overlay);
        });

        window.addEventListener('error', function (ev) {
            try {
                const msg = String(ev && ev.message) || 'Unknown error';
                overlay.innerText = 'JS Error: ' + msg + '\n' + (ev && ev.filename ? (ev.filename + ':' + (ev.lineno||'?')) : '');
                overlay.style.display = 'block';
                console.error('Captured JS error', ev.error || ev.message || ev);
            } catch (e) {
                console.error('Error reporting JS error', e);
            }
        });

        window.addEventListener('unhandledrejection', function (ev) {
            try {
                const reason = ev && ev.reason ? (ev.reason.message || JSON.stringify(ev.reason)) : 'Unhandled rejection';
                overlay.innerText = 'Promise Rejection: ' + reason;
                overlay.style.display = 'block';
                console.error('Unhandled rejection', ev.reason);
            } catch (e) { console.error(e); }
        });

        // keyboard fallback: Enter / Space on focused product-card triggers add
        document.addEventListener('keydown', function(e) {
            try {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                const active = document.activeElement;
                if (!active) return;
                if (active.classList && active.classList.contains('product-card')) {
                    e.preventDefault();
                    const pid = parseInt(active.dataset.id, 10) || 0;
                    if (pid) {
                        if (typeof addToCart === 'function') {
                            addToCart(pid);
                        } else if (typeof fetchProductAndAdd === 'function') {
                            fetchProductAndAdd(pid);
                        } else {
                            console.warn('No addToCart function available');
                        }
                    }
                }
            } catch (err) {
                console.error('Keyboard add-to-cart failed', err);
            }
        });
    } catch (outer) {
        console.error('Failed to install runtime diagnostics', outer);
    }
})();

// ==================== Initialize ====================
document.addEventListener('DOMContentLoaded', async function() {
    console.log('POS page initialized');

    buildProductCardCache();
    priceMode = getPriceMode();
    updateProductGridPrices();
    
    // Initialize scanner
    initScanner();
    
    // Initialize barcode scanner mode
    initBarcodeMode();
    
    // Setup event listeners
    setupEventListeners();

    // Restore exchange context before the first cart render
    applyExchangeContext();
    
    // Update cart display
    updateCartDisplay();

    if (currentOrderId <= 0) {
        await preloadExchangeItems();
    }
    
    // Initialize cash payment section visibility
    toggleCashPayment();
    updateActionButtons();
    loadPendingOrder(currentOrderId);

    // If redirected from Balance Report, open balance modal and search by invoice/sale id
    try {
        const params = new URLSearchParams(window.location.search);
        const shouldOpen = params.get('balance_open');
        const invoice = (params.get('balance_invoice') || '').trim();
        const saleId = (params.get('balance_sale_id') || '').trim();
        if (shouldOpen && (invoice || saleId)) {
            openBalanceModal();
            const nameInput = document.getElementById('balance-search-name');
            const phoneInput = document.getElementById('balance-search-phone');
            if (phoneInput) phoneInput.value = '';
            if (nameInput) nameInput.value = invoice || saleId;
            await searchCustomerBalance();

            const targetId = Number(saleId);
            if (targetId > 0 && Array.isArray(balanceSearchResults) && balanceSearchResults.length) {
                const match = balanceSearchResults.find(r => Number(r && r.id) === targetId);
                if (match) {
                    await addBalanceRecordsToCart([match], { closeAfter: true });
                }
            }
        }
    } catch (err) {
        console.warn('Balance redirect init failed', err);
    }
    
    // Team chat / notifications
    if (!window.POSGlobalChat) {
        initNotificationPanel();
        const notifBell = document.querySelector('.chat-notifications');
        if (notifBell) {
            notifBell.style.cursor = 'pointer';
            notifBell.addEventListener('click', toggleNotificationPanel);
        }
        fetchChatNotifications(false);
        startNotificationPolling();
    }
    
    // Log product count
    console.log('Products loaded:', <?php echo count($products); ?>);
});
</script>

<?php include '../layouts/footer.php'; ?>