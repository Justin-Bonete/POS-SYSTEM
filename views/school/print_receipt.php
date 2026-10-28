<?php
// views/school/print_receipt.php - printable receipt for SchoolStore Pro history

session_start();
require_once __DIR__ . '/_bootstrap.php';

$conn = schoolStoreConnect();

$saleId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$type = isset($_GET['type']) ? strtolower(trim((string) $_GET['type'])) : '';

if ($saleId <= 0) {
    http_response_code(400);
    echo 'Missing or invalid sale ID.';
    exit;
}

if (!in_array($type, ['retail', 'wholesale'], true)) {
    http_response_code(400);
    echo 'Missing or invalid sale type.';
    exit;
}

$salesTable = $type . '_sales';
$itemsTable = $type . '_sale_items';

$saleStmt = $conn->prepare("SELECT * FROM {$salesTable} WHERE id = ? LIMIT 1");
$saleStmt->bind_param('i', $saleId);
$saleStmt->execute();
$saleRes = $saleStmt->get_result();
$sale = $saleRes ? $saleRes->fetch_assoc() : null;

if (!$sale) {
    http_response_code(404);
    echo 'Sale not found.';
    exit;
}

$itemsStmt = $conn->prepare("SELECT * FROM {$itemsTable} WHERE sale_id = ? ORDER BY id ASC");
$itemsStmt->bind_param('i', $saleId);
$itemsStmt->execute();
$itemsRes = $itemsStmt->get_result();
$items = [];
if ($itemsRes) {
    while ($row = $itemsRes->fetch_assoc()) {
        $items[] = $row;
    }
}

function esc($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money($amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

$saleDate = $sale['sale_date'] ?? ($sale['created_at'] ?? date('Y-m-d H:i:s'));
$status = (string) ($sale['status'] ?? 'completed');
$total = (float) ($sale['total_amount'] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Print Receipt</title>
    <style>
        @page { size: 58mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body { font-family: 'Courier New', monospace; width: 58mm; margin: 0 auto; padding: 8px; font-size: 11px; line-height: 1.25; }
        .receipt { text-align: left; }
        .center { text-align: center; }
        .muted { color: #444; }
        .divider { border-top: 1px dashed #000; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3px 0; vertical-align: top; }
        th { border-bottom: 1px dashed #000; }
        .qty { width: 10%; }
        .price { width: 22%; text-align: right; }
        .total { width: 24%; text-align: right; font-weight: 700; }
        .actions { margin-top: 10px; display: flex; gap: 8px; justify-content: center; }
        .btn { border: 1px solid #111; background: #fff; padding: 6px 10px; cursor: pointer; font-family: inherit; border-radius: 6px; }
        .btn.primary { background: #111; color: #fff; }
        @media print { .no-print { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="center">
            <div style="font-weight:700;">SALE RECEIPT</div>
            <div class="muted">#<?php echo (int) $saleId; ?> | <?php echo esc(strtoupper($type)); ?></div>
            <div class="muted"><?php echo esc(date('M d, Y h:i A', strtotime($saleDate))); ?></div>
        </div>

        <div class="divider"></div>

        <div>
            <div><strong>Status:</strong> <?php echo esc($status); ?></div>
        </div>

        <div class="divider"></div>

        <table>
            <thead>
                <tr>
                    <th class="qty">Qty</th>
                    <th>Item</th>
                    <th class="price">Price</th>
                    <th class="total">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="4">No items found.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <?php
                            $qty = (int) ($item['quantity'] ?? 0);
                            $unit = (float) ($item['unit_price'] ?? 0);
                            $line = (float) ($item['total_price'] ?? ($qty * $unit));
                            $name = $item['product_name'] ?? '';
                        ?>
                        <tr>
                            <td class="qty"><?php echo $qty; ?></td>
                            <td><?php echo esc($name); ?></td>
                            <td class="price"><?php echo esc(money($unit)); ?></td>
                            <td class="total"><?php echo esc(money($line)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="divider"></div>

        <div style="display:flex; justify-content:space-between;">
            <span><strong>Total</strong></span>
            <span><strong><?php echo esc(money($total)); ?></strong></span>
        </div>

        <div class="divider"></div>

        <div class="center" style="margin-top:8px; font-weight:700;">Thank you!</div>

        <div class="actions no-print">
            <button class="btn primary" onclick="window.print()">Print</button>
            <button class="btn" onclick="window.close()">Close</button>
        </div>
    </div>
</body>
</html>
<?php $conn->close(); ?>

