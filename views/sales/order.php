<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

requireAuth();
requireAccess('orders');

$pageTitle = 'Pending Orders';
$db = ConfigDatabase::getInstance()->getConnection();
$search = trim((string) ($_GET['search'] ?? ''));
$highlightId = isset($_GET['highlight']) ? (int) $_GET['highlight'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_order') {
    $orderId = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;

    try {
        if ($orderId <= 0) {
            throw new Exception('Invalid order ID');
        }

        $db->beginTransaction();

        $checkStmt = $db->prepare("SELECT id FROM sales WHERE id = ? AND payment_status = 'pending' LIMIT 1");
        $checkStmt->execute([$orderId]);
        if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception('Pending order not found');
        }

        $deleteItemsStmt = $db->prepare("DELETE FROM sale_items WHERE sale_id = ?");
        $deleteItemsStmt->execute([$orderId]);

        $deleteSaleStmt = $db->prepare("DELETE FROM sales WHERE id = ? AND payment_status = 'pending'");
        $deleteSaleStmt->execute([$orderId]);

        if ($deleteSaleStmt->rowCount() < 1) {
            throw new Exception('Failed to delete pending order');
        }

        $db->commit();
        $_SESSION['success'] = 'Pending order deleted successfully.';
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error'] = $e->getMessage();
    }
    header('Location: /pos-system/views/sales/order.php');
    exit;
}

// Handle receipt printing - direct print view (same style as print.php)
if (isset($_GET['print_id']) && is_numeric($_GET['print_id'])) {
    $orderId = (int) $_GET['print_id'];
    
    $orderStmt = $db->prepare("
        SELECT s.*, u.username as cashier_name 
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.id = ? AND s.payment_status = 'pending'
    ");
    $orderStmt->execute([$orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($order) {
        $itemsStmt = $db->prepare("
            SELECT si.*, p.name as product_name_db, p.price as retail_price, p.wholesale_price, p.cost_price
            FROM sale_items si
            LEFT JOIN products p ON si.product_id = p.id
            WHERE si.sale_id = ?
        ");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        $itemsSubtotal = 0;
        foreach ($items as $item) {
            $qty = (float)($item['quantity'] ?? 0);
            $unitPrice = (float)($item['price_per_unit'] ?? ($item['unit_price'] ?? 0));
            $lineTotal = isset($item['subtotal']) ? (float)$item['subtotal'] : ($qty * $unitPrice);
            $itemsSubtotal += $lineTotal;
        }
        
        $subtotal = (isset($order['subtotal']) && (float)$order['subtotal'] > 0)
            ? (float)$order['subtotal']
            : $itemsSubtotal;
        
        $taxAmount = (float)($order['tax_amount'] ?? 0);
        $discountAmount = (float)($order['discount_amount'] ?? 0);
        $totalAmount = (float)($order['total_amount'] ?? ($subtotal + $taxAmount - $discountAmount));
        
        $invoiceNo = $order['invoice_number'] ?? $order['id'];
        $invoiceLabel = $invoiceNo ? '#' . ltrim((string)$invoiceNo, '#') : ('#' . $order['id']);
        $createdAt = $order['created_at'] ?? date('Y-m-d H:i:s');
        $paymentMethod = $order['payment_method'] ?? 'cash';
        $paymentStatus = $order['payment_status'] ?? 'pending';
        $customerName = $order['customer_name'] ?? 'Walk-in Customer';
        $customerPhone = $order['customer_phone'] ?? '';
        $customerEmail = $order['customer_email'] ?? '';
        $notes = $order['notes'] ?? '';
        $cashAmount = $order['cash_amount'] ?? null;
        $changeAmount = $order['change_amount'] ?? null;
        $tellerName = $order['teller_name'] ?? '';
        
        // Helper functions (same as print.php)
        function formatMoney($amount): string {
            return '₱' . number_format((float)$amount, 2);
        }
        
        function esc($value): string {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        }
        
        function receiptPriceIndicator(array $item, float $unitPrice): string {
            $priceMode = strtolower((string)($item['price_mode'] ?? ''));
            if ($priceMode === 'wholesale') {
                return 'W';
            }
            if ($priceMode === 'cost') {
                return 'C';
            }

            $wholesale = isset($item['wholesale_price']) && $item['wholesale_price'] !== ''
                ? (float)$item['wholesale_price']
                : null;
            $cost = isset($item['cost_price']) && $item['cost_price'] !== ''
                ? (float)$item['cost_price']
                : null;

            if ($cost !== null && abs($cost - $unitPrice) < 0.0001) {
                return 'C';
            }
            if ($wholesale !== null && abs($wholesale - $unitPrice) < 0.0001) {
                return 'W';
            }
            return 'R';
        }
        
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Pending Order Receipt <?= esc($invoiceLabel); ?></title>
            <style>
                @page { 
                    size: 80mm auto; 
                    margin: 6mm 5mm; 
                }
                * { 
                    box-sizing: border-box; 
                }
                body { 
                    font-family: 'Courier New', monospace; 
                    margin: 0; 
                    padding: 0; 
                    background: #fff; 
                }
                .receipt { 
                    width: 100%; 
                    max-width: 78mm; 
                    margin: 0 auto; 
                    padding: 4px; 
                }
                .receipt-header { 
                    text-align: center; 
                    margin-bottom: 10px; 
                }
                .receipt-header h2 { 
                    margin: 0 0 6px; 
                    font-size: 16px; 
                    letter-spacing: 0.5px; 
                }
                .meta { 
                    font-size: 11px; 
                    line-height: 1.35; 
                }
                .divider { 
                    border-top: 1px dashed #000; 
                    margin: 8px 0; 
                }
                table { 
                    width: 100%; 
                    border-collapse: collapse; 
                    font-size: 11px; 
                }
                th, td { 
                    padding: 3px 0; 
                    vertical-align: top;
                }
                th { 
                    text-align: left; 
                    border-bottom: 1px dashed #000; 
                }
                .item-name {
                    width: 40%;
                    word-wrap: break-word;
                }
                .qty-col {
                    width: 15%;
                    text-align: right;
                }
                .price-col {
                    width: 25%;
                    text-align: right;
                    white-space: nowrap;
                }
                .total-col {
                    width: 20%;
                    text-align: right;
                }
                .price-indicator {
                    display: inline-block;
                    min-width: 20px;
                    font-weight: bold;
                    text-align: left;
                }
                .price-amount {
                    display: inline-block;
                    text-align: right;
                }
                .totals { 
                    margin-top: 6px; 
                    font-size: 11px; 
                }
                .totals-row { 
                    display: flex; 
                    justify-content: space-between; 
                    padding: 2px 0; 
                }
                .grand { 
                    font-weight: 700; 
                    border-top: 1px dashed #000; 
                    margin-top: 4px; 
                    padding-top: 4px; 
                }
                .tag { 
                    display: inline-block; 
                    padding: 2px 6px; 
                    border-radius: 4px; 
                    font-size: 10px; 
                    background: #f4f5f7; 
                    border: 1px solid #dcdfe4; 
                }
                .footer-note { 
                    margin-top: 10px; 
                    font-size: 10px; 
                    text-align: center; 
                    color: #444; 
                }
                .no-print {
                    text-align: center;
                    margin-bottom: 15px;
                    padding: 10px;
                }
                .no-print button {
                    padding: 8px 16px;
                    margin: 0 5px;
                    cursor: pointer;
                    font-size: 12px;
                }
                @media print {
                    body {
                        margin: 0;
                        padding: 0;
                    }
                    .no-print {
                        display: none;
                    }
                }
            </style>
        </head>
        <body>
            <div class="no-print">
                <button onclick="window.print();">🖨️ Print Receipt</button>
                <button onclick="window.close();">❌ Close</button>
            </div>
            <div class="receipt">
                <div class="receipt-header">
                    <h2>PENDING ORDER</h2>
                    <div class="meta"><?= esc($invoiceLabel); ?> | <?= esc(strtoupper('SALES')); ?></div>
                    <div class="meta">Date: <?= esc(date('M d, Y h:i A', strtotime($createdAt))); ?></div>
                    <div class="meta">Cashier: <?= esc($order['cashier_name'] ?? 'System'); ?></div>
                    <?php if (!empty($tellerName)): ?>
                        <div class="meta">Salesman: <?= esc($tellerName); ?></div>
                    <?php endif; ?>
                </div>

                <div class="meta">
                    <div><strong>Customer:</strong> <?= esc($customerName); ?></div>
                    <?php if ($customerPhone): ?>
                        <div><strong>Phone:</strong> <?= esc($customerPhone); ?></div>
                    <?php endif; ?>
                    <?php if ($customerEmail): ?>
                        <div><strong>Email:</strong> <?= esc($customerEmail); ?></div>
                    <?php endif; ?>
                    <div><strong>Payment:</strong> <?= esc(ucfirst($paymentMethod)); ?> 
                    <span class="tag"><?= esc($paymentStatus); ?></span></div>
                </div>

                <div class="divider"></div>

                <table>
                    <thead>
                        <tr>
                            <th class="item-name">Item</th>
                            <th class="qty-col">Qty</th>
                            <th class="price-col">Price</th>
                            <th class="total-col">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="4">No items found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): 
                                $qty = (float)($item['quantity'] ?? 0);
                                $unitPrice = (float)($item['price_per_unit'] ?? ($item['unit_price'] ?? 0));
                                $lineTotal = isset($item['subtotal']) ? (float)$item['subtotal'] : ($qty * $unitPrice);
                                $name = $item['product_name'] ?? $item['product_name_db'] ?? 'Item';
                                $indicator = receiptPriceIndicator($item, $unitPrice);
                            ?>
                                <tr>
                                    <td class="item-name"><?= esc($name); ?></td>
                                    <td class="qty-col"><?= rtrim(rtrim(number_format($qty, 2), '0'), '.'); ?></td>
                                    <td class="price-col">
                                        <span class="price-indicator">[<?= esc($indicator); ?>]</span>
                                        <span class="price-amount"><?= formatMoney($unitPrice); ?></span>
                                    </td>
                                    <td class="total-col"><?= formatMoney($lineTotal); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="divider"></div>

                <div class="totals">
                    <div class="totals-row">
                        <span>Subtotal</span>
                        <span><?= formatMoney($subtotal); ?></span>
                    </div>
                    <?php if ($taxAmount != 0): ?>
                        <div class="totals-row">
                            <span>Tax</span>
                            <span><?= formatMoney($taxAmount); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if ($discountAmount != 0): ?>
                        <div class="totals-row">
                            <span>Discount</span>
                            <span>-<?= formatMoney(abs($discountAmount)); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="totals-row grand">
                        <span>TOTAL</span>
                        <span><?= formatMoney($totalAmount); ?></span>
                    </div>
                    <?php if ($paymentMethod === 'cash' && $cashAmount !== null): ?>
                        <div class="totals-row">
                            <span>Cash</span>
                            <span><?= formatMoney($cashAmount); ?></span>
                        </div>
                        <div class="totals-row">
                            <span>Change</span>
                            <span><?= formatMoney($changeAmount ?? 0); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($notes): ?>
                    <div class="divider"></div>
                    <div class="meta">
                        <strong>Notes:</strong> <?= esc($notes); ?>
                    </div>
                <?php endif; ?>

                <div class="divider"></div>
                
                <div class="footer-note">
                    ⚠️ This is a PENDING ORDER receipt ⚠️<br>
                    Payment has not been completed yet.<br>
                    Thank you for your business!
                </div>
            </div>
            
            <script>
                window.onload = function() {
                    setTimeout(function() {
                        window.print();
                    }, 150);
                };
            </script>
        </body>
        </html>
        <?php
        exit;
    }
}

$sql = "
    SELECT
        s.id,
        s.invoice_number,
        s.customer_name,
        s.customer_phone,
        s.total_amount,
        s.payment_method,
        s.created_at,
        COUNT(si.id) AS item_count
    FROM sales s
    LEFT JOIN sale_items si ON si.sale_id = s.id
    WHERE s.payment_status = 'pending'
      AND NOT EXISTS (
          SELECT 1
          FROM sale_approvals sa
          WHERE sa.sale_id = s.id
            AND sa.status IN ('pending', 'approved')
      )
";

$params = [];
if ($search !== '') {
    $sql .= " AND (s.invoice_number LIKE ? OR s.customer_name LIKE ? OR s.customer_phone LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= "
    GROUP BY s.id, s.invoice_number, s.customer_name, s.customer_phone, s.total_amount, s.payment_method, s.created_at
    ORDER BY s.created_at DESC
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summaryStmt = $db->query("
    SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(total_amount), 0) AS total_amount
    FROM sales s
    WHERE s.payment_status = 'pending'
      AND NOT EXISTS (
          SELECT 1
          FROM sale_approvals sa
          WHERE sa.sale_id = s.id
            AND sa.status IN ('pending', 'approved')
      )
");
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: ['total_orders' => 0, 'total_amount' => 0];
?>
<?php include __DIR__ . '/../layouts/header.php'; ?>

<style>
.orders-page {
    padding: 1.5rem;
}

.orders-toolbar,
.orders-summary,
.orders-card {
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 6px 24px rgba(15, 23, 42, 0.08);
}

.orders-toolbar {
    padding: 1rem 1.25rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}

.orders-summary {
    padding: 1rem 1.25rem;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}

.summary-box {
    padding: 1rem;
    border-radius: 14px;
    background: linear-gradient(135deg, #eff6ff, #ffffff);
    border: 1px solid #dbeafe;
}

.summary-box strong {
    display: block;
    font-size: 1.4rem;
    color: #1e3a8a;
}

.orders-card {
    overflow: hidden;
}

.orders-table {
    width: 100%;
    border-collapse: collapse;
}

.orders-table th,
.orders-table td {
    padding: 0.95rem 1rem;
    border-bottom: 1px solid #e5e7eb;
    text-align: left;
}

.orders-table th {
    background: #f8fafc;
    color: #475569;
    font-size: 0.88rem;
}

.orders-table tr.highlight-row {
    background: #ecfeff;
}

.status-badge,
.method-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 0.3rem 0.7rem;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-badge {
    background: #fef3c7;
    color: #92400e;
}

.method-badge {
    background: #e0f2fe;
    color: #075985;
}

.empty-state {
    padding: 3rem 1rem;
    text-align: center;
    color: #64748b;
}

.action-group {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.btn-warning {
    background: #f59e0b;
    border-color: #f59e0b;
    color: #fff;
}

.btn-info {
    background: #0ea5e9;
    border-color: #0ea5e9;
    color: #fff;
}

.btn-info:hover {
    background: #0284c7;
    border-color: #0284c7;
}

@media (max-width: 768px) {
    .orders-table thead {
        display: none;
    }

    .orders-table,
    .orders-table tbody,
    .orders-table tr,
    .orders-table td {
        display: block;
        width: 100%;
    }

    .orders-table tr {
        border-bottom: 1px solid #e5e7eb;
    }

    .orders-table td {
        padding: 0.75rem 1rem;
    }
}
</style>

<div class="orders-page">

<div style="padding:12px 18px;">
    <a href="/pos-system/views/dashboard/index.php" class="btn btn-secondary" style="background:#4b5563;color:#fff;border-radius:10px;padding:10px 16px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
        <i class="fas fa-arrow-left"></i> Back to Dashboard
    </a>
</div>

    <div class="orders-toolbar">
        <div>
            <h2 style="margin:0;">Pending Orders</h2>
            <small style="color:#64748b;">Save customer orders here, then finish payment later in POS.</small>
        </div>
        <div style="display:flex; gap:0.75rem; flex-wrap:wrap;">
            <form method="get" style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                <input
                    type="text"
                    name="search"
                    class="form-control"
                    placeholder="Search order, customer, phone"
                    value="<?php echo htmlspecialchars($search); ?>"
                    style="min-width:260px;"
                >
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search mr-2"></i> Search
                </button>
            </form>
            <a href="/pos-system/views/sales/new.php" class="btn btn-success">
                <i class="fas fa-plus mr-2"></i> New POS Sale
            </a>
        </div>
    </div>

    <div class="orders-summary">
        <div class="summary-box">
            <span>Total Pending Orders</span>
            <strong><?php echo (int) ($summary['total_orders'] ?? 0); ?></strong>
        </div>
        <div class="summary-box">
            <span>Total Pending Amount</span>
            <strong><?php echo formatCurrency((float) ($summary['total_amount'] ?? 0)); ?></strong>
        </div>
    </div>

    <div class="orders-card">
        <table class="orders-table">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Items</th>
                    <th>Total</th>
                    <th>Method</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="8" class="empty-state">
                            <i class="fas fa-receipt fa-3x" style="margin-bottom:0.75rem;"></i>
                            <p style="margin:0;">No pending orders found.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                        <tr class="<?php echo $highlightId === (int) $order['id'] ? 'highlight-row' : ''; ?>">
                            <td><strong><?php echo htmlspecialchars($order['invoice_number'] ?: ('#' . $order['id'])); ?></strong></td>
                            <td><?php echo date('M d, Y h:i A', strtotime((string) $order['created_at'])); ?></td>
                            <td>
                                <?php echo htmlspecialchars($order['customer_name'] ?: 'Walk-in Customer'); ?>
                                <?php if (!empty($order['customer_phone'])): ?>
                                    <br><small><?php echo htmlspecialchars((string) $order['customer_phone']); ?></small>
                                <?php endif; ?>
                             </td>
                            <td><?php echo (int) ($order['item_count'] ?? 0); ?></td>
                            <td><strong><?php echo formatCurrency((float) ($order['total_amount'] ?? 0)); ?></strong></td>
                            <td><span class="method-badge"><?php echo htmlspecialchars((string) ($order['payment_method'] ?? 'cash')); ?></span></td>
                            <td><span class="status-badge">Pending</span></td>
                            <td>
                                <div class="action-group">
                                    <a href="/pos-system/views/sales/order.php?print_id=<?php echo (int) $order['id']; ?>" class="btn btn-info btn-sm" target="_blank">
                                        <i class="fas fa-print mr-2"></i> Print
                                    </a>
                                    <a href="/pos-system/views/sales/new.php?order_id=<?php echo (int) $order['id']; ?>" class="btn btn-warning btn-sm">
                                        <i class="fas fa-pen mr-2"></i> Edit
                                    </a>
                                    <a href="/pos-system/views/sales/new.php?order_id=<?php echo (int) $order['id']; ?>" class="btn btn-primary btn-sm">
                                        <i class="fas fa-cash-register mr-2"></i> Payment
                                    </a>
                                    <form method="post" onsubmit="return confirmDeleteOrder('<?php echo htmlspecialchars((string) ($order['invoice_number'] ?: ('#' . $order['id']))); ?>');" style="margin:0;">
                                        <input type="hidden" name="action" value="delete_order">
                                        <input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fas fa-trash mr-2"></i> Delete
                                        </button>
                                    </form>
                                </div>
                             </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function confirmDeleteOrder(orderNumber) {
    return confirm(`Delete pending order ${orderNumber}? This cannot be undone.`);
}
</script>

<?php include __DIR__ . '/../layouts/footer.php'; ?>