<?php
// views/sales/print.php - dedicated printable receipt

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../../includes/auth.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';
requireAuth();
requireAccess('sales_history');

$db = ConfigDatabase::getInstance()->getConnection();

$saleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sourceHint = $_GET['source'] ?? '';
$sourceHint = in_array($sourceHint, ['sales', 'invoices'], true) ? $sourceHint : '';

if ($saleId <= 0) {
    http_response_code(400);
    echo 'Missing or invalid sale ID.';
    exit;
}

/**
 * Fetch a sale or invoice with cashier name.
 */
function fetchRecord(PDO $db, int $id, string $table, string $alias): ?array
{
    $sql = "
        SELECT {$alias}.*, COALESCE(u.full_name, u.username, 'System') AS cashier_name
        FROM {$table} {$alias}
        LEFT JOIN users u ON {$alias}.user_id = u.id
        WHERE {$alias}.id = ?
        LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$saleData = null;
$source = '';

// Try hinted source first
if ($sourceHint === 'sales') {
    $saleData = fetchRecord($db, $saleId, 'sales', 's');
    $source = $saleData ? 'sales' : '';
} elseif ($sourceHint === 'invoices') {
    $saleData = fetchRecord($db, $saleId, 'invoices', 'i');
    $source = $saleData ? 'invoices' : '';
}

// Fallback lookups to avoid id collisions
if (!$saleData) {
    $saleData = fetchRecord($db, $saleId, 'sales', 's');
    $source = $saleData ? 'sales' : '';
}
if (!$saleData) {
    $saleData = fetchRecord($db, $saleId, 'invoices', 'i');
    $source = $saleData ? 'invoices' : '';
}

if (!$saleData) {
    http_response_code(404);
    echo 'Sale not found.';
    exit;
}

// Load items
if ($source === 'sales') {
    $itemStmt = $db->prepare("
        SELECT si.*, p.name AS product_name_db, p.price AS retail_price, p.wholesale_price, p.cost_price
        FROM sale_items si
        LEFT JOIN products p ON si.product_id = p.id
        WHERE si.sale_id = ?
    ");
} else {
    $itemStmt = $db->prepare("
        SELECT ii.*, p.name AS product_name_db, p.price AS retail_price, p.wholesale_price, p.cost_price
        FROM invoice_items ii
        LEFT JOIN products p ON ii.product_id = p.id
        WHERE ii.invoice_id = ?
    ");
}
$itemStmt->execute([$saleId]);
$items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

// Helpers
function formatMoney($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

function esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function receiptPriceIndicator(array $item, float $unitPrice): string
{
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

// Totals
$itemsSubtotal = 0;
foreach ($items as $item) {
    $qty = (float)($item['quantity'] ?? 0);
    $unitPrice = (float)($item['price_per_unit'] ?? ($item['unit_price'] ?? 0));
    $lineTotal = isset($item['subtotal']) ? (float)$item['subtotal'] : ($qty * $unitPrice);
    $itemsSubtotal += $lineTotal;
}

$subtotal = (isset($saleData['subtotal']) && (float)$saleData['subtotal'] > 0)
    ? (float)$saleData['subtotal']
    : $itemsSubtotal;

$taxAmount = (float)($saleData['tax_amount'] ?? 0);
$discountAmount = (float)($saleData['discount_amount'] ?? 0);
$totalAmount = (float)($saleData['total_amount'] ?? ($subtotal + $taxAmount - $discountAmount));

$invoiceNo = $saleData['invoice_number'] ?? $saleData['id'];
$invoiceLabel = $invoiceNo ? '#' . ltrim((string)$invoiceNo, '#') : ('#' . $saleId);
$createdAt = $saleData['created_at'] ?? date('Y-m-d H:i:s');
$paymentMethod = $saleData['payment_method'] ?? 'cash';
$paymentStatus = $saleData['payment_status'] ?? 'paid';
$customerName = $saleData['customer_name'] ?? 'Walk-in Customer';
$customerPhone = $saleData['customer_phone'] ?? '';
$customerEmail = $saleData['customer_email'] ?? '';
$notes = $saleData['notes'] ?? '';
$cashAmount = $saleData['cash_amount'] ?? null;
$changeAmount = $saleData['change_amount'] ?? null;
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
$approvalStmt = $db->prepare("
    SELECT *
    FROM sale_approvals
    WHERE sale_id = ?
      AND status IN ('pending', 'approved')
    ORDER BY id DESC
    LIMIT 1
");
$approvalStmt->execute([$saleId]);
$approvalData = $approvalStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$borrowedAmount = 0.0;
if ($approvalData) {
    $borrowedAmount = (float) ($approvalData['short_amount'] ?? 0);
} elseif ($paymentStatus === 'pending' && $cashAmount !== null && (float) $cashAmount < $totalAmount) {
    $borrowedAmount = max($totalAmount - (float) $cashAmount, 0);
}

$isPendingApprovalBalance = false;
if ($source === 'sales') {
    $isPendingApprovalBalance = $paymentStatus === 'pending' && $borrowedAmount > 0;
    if ($approvalData) {
        $isPendingApprovalBalance = strtolower((string) ($approvalData['status'] ?? '')) === 'pending' && $borrowedAmount > 0;
    }
}

if ($isPendingApprovalBalance) {
    http_response_code(403);
    echo 'Receipt is locked until admin approval is completed.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Print Receipt <?= esc($invoiceLabel); ?></title>
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
        /* Fix for price indicator spacing */
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
    <div class="receipt">
        <div class="receipt-header">
            <h2>SALE RECEIPT</h2>
            <div class="meta"><?= esc($invoiceLabel); ?> | <?= esc(strtoupper($source)); ?></div>
            <div class="meta">Date: <?= esc(date('M d, Y h:i A', strtotime($createdAt))); ?></div>
            <div class="meta">Cashier: <?= esc($saleData['cashier_name'] ?? 'System'); ?></div>
            <?php if (!empty($saleData['teller_name'])): ?>
                <div class="meta">Salesman: <?= esc($saleData['teller_name']); ?></div>
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
            <?php if ($borrowedAmount > 0): ?>
                <div class="totals-row">
                    <span>Balance Due</span>
                    <span><?= formatMoney($borrowedAmount); ?></span>
                </div>
                <div class="totals-row">
                    <span>Amount Paid</span>
                    <span><?= formatMoney($cashAmount ?? 0); ?></span>
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
            Thank you for your purchase!<br>
        </div>
    </div>
    
    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 80);
        };
    </script>
</body>
</html>
