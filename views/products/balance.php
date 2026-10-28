<?php
// views/products/balance.php
// Balance report grouped per invoice (shows customer, outstanding balance, date, and items)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

requireAuth();

$pageTitle = 'Balance Report';
$db = getDB();
$isAdminUser = isAdmin();

// Ensure approval table exists so balance can surface pending approval items.
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

$search = trim((string) ($_GET['search'] ?? ''));
$limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 200;

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_sale') {
    header('Content-Type: application/json');
    
    if (!$isAdminUser) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Admin only.']);
        exit();
    }
    
    $sale_id = isset($_POST['sale_id']) ? (int)$_POST['sale_id'] : 0;
    
    if ($sale_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid sale ID']);
        exit();
    }
    
    try {
        // Start transaction
        $db->beginTransaction();
        
        // First, check if sale exists
        $checkQuery = "SELECT id, invoice_number FROM sales WHERE id = :id";
        $checkStmt = $db->prepare($checkQuery);
        $checkStmt->execute([':id' => $sale_id]);
        $sale = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sale) {
            echo json_encode(['success' => false, 'message' => 'Sale not found']);
            exit();
        }
        
        // Delete sale items first (due to foreign key constraint)
        $deleteItemsQuery = "DELETE FROM sale_items WHERE sale_id = :sale_id";
        $stmt = $db->prepare($deleteItemsQuery);
        $stmt->execute([':sale_id' => $sale_id]);
        
        // Delete sale approvals
        $deleteApprovalsQuery = "DELETE FROM sale_approvals WHERE sale_id = :sale_id";
        $stmt = $db->prepare($deleteApprovalsQuery);
        $stmt->execute([':sale_id' => $sale_id]);
        
        // Delete the sale
        $deleteSaleQuery = "DELETE FROM sales WHERE id = :id";
        $stmt = $db->prepare($deleteSaleQuery);
        $stmt->execute([':id' => $sale_id]);
        
        // Commit transaction
        $db->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Sale #' . htmlspecialchars($sale['invoice_number']) . ' has been deleted successfully.',
            'sale_id' => $sale_id
        ]);
        
    } catch (PDOException $e) {
        if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Fetch outstanding balances from sales with actual balance approval records
$query = "
    SELECT
        s.id,
        s.invoice_number,
        s.customer_name,
        s.customer_phone,
        s.total_amount,
        s.cash_amount,
        s.payment_status,
        s.payment_method,
        s.created_at,
        sa.id AS approval_id,
        sa.status AS approval_status,
        sa.total_amount AS approval_total_amount,
        sa.cash_amount AS approval_cash_amount,
        sa.short_amount AS approval_short_amount,
        CASE
            WHEN sa.status = 'approved' THEN GREATEST(COALESCE(sa.short_amount, 0), 0)
            ELSE GREATEST(COALESCE(s.total_amount, 0) - COALESCE(s.cash_amount, 0), 0)
        END AS outstanding_amount
    FROM sales s
    LEFT JOIN (
        SELECT latest.*
        FROM sale_approvals latest
        INNER JOIN (
            SELECT sale_id, MAX(id) AS max_id
            FROM sale_approvals
            GROUP BY sale_id
        ) grouped ON grouped.max_id = latest.id
    ) sa ON sa.sale_id = s.id
    WHERE (
        (sa.status IN ('pending', 'approved') AND GREATEST(COALESCE(sa.short_amount, 0), 0) > 0)
    )
";

if ($search !== '') {
    $query .= " AND (
        s.customer_name LIKE :search
        OR s.customer_phone LIKE :search
        OR s.invoice_number LIKE :search
    )";
}

$query .= " ORDER BY s.created_at DESC LIMIT :limit";

$stmt = $db->prepare($query);
if ($search !== '') {
    $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->execute();
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

$saleIds = array_values(array_filter(array_map(static fn ($row) => (int) ($row['id'] ?? 0), $sales)));
$itemsBySaleId = [];

if (!empty($saleIds)) {
    $placeholders = implode(',', array_fill(0, count($saleIds), '?'));
    $itemsQuery = "
        SELECT
            si.sale_id,
            si.product_id,
            COALESCE(p.product_code, '') as product_code,
            COALESCE(p.name, si.product_name, '') as product_name,
            si.quantity,
            si.price_per_unit,
            si.subtotal
        FROM sale_items si
        LEFT JOIN products p ON si.product_id = p.id
        WHERE si.sale_id IN ($placeholders)
        ORDER BY si.sale_id DESC, si.id ASC
    ";
    $itemsStmt = $db->prepare($itemsQuery);
    foreach ($saleIds as $idx => $saleId) {
        $itemsStmt->bindValue($idx + 1, $saleId, PDO::PARAM_INT);
    }
    $itemsStmt->execute();
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $saleId = (int) ($item['sale_id'] ?? 0);
        if ($saleId <= 0) continue;
        if (!isset($itemsBySaleId[$saleId])) $itemsBySaleId[$saleId] = [];
        $itemsBySaleId[$saleId][] = $item;
    }
}

$pendingApprovals = [];
$pendingApprovalsCount = 0;
$pendingApprovalsShortTotal = 0.0;
$pendingApprovalsTotal = 0.0;

$pendingApprovalsQuery = "
    SELECT
        sa.id AS approval_id,
        sa.sale_id,
        sa.total_amount,
        sa.cash_amount,
        sa.short_amount,
        sa.note,
        sa.created_at AS approval_requested_at,
        s.invoice_number,
        s.customer_name,
        s.customer_phone
    FROM sale_approvals sa
    JOIN sales s ON s.id = sa.sale_id
    WHERE sa.status = 'pending'
    ORDER BY sa.created_at DESC
    LIMIT 50
";
$pendingStmt = $db->prepare($pendingApprovalsQuery);
$pendingStmt->execute();
$pendingApprovals = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($pendingApprovals as $approval) {
    $pendingApprovalsCount++;
    $pendingApprovalsShortTotal += (float) ($approval['short_amount'] ?? 0);
    $pendingApprovalsTotal += (float) ($approval['total_amount'] ?? 0);
}

// CSV export (per invoice, with item rows)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="balance_report_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, [
        'Invoice',
        'Customer',
        'Phone',
        'Date',
        'Outstanding',
        'Payment Status',
        'Approval Status',
        'Item Code',
        'Item Name',
        'Qty',
        'Price',
        'Subtotal',
    ]);

    foreach ($sales as $sale) {
        $saleId = (int) ($sale['id'] ?? 0);
        $invoice = (string) ($sale['invoice_number'] ?? '');
        $customer = (string) ($sale['customer_name'] ?? '');
        $phone = (string) ($sale['customer_phone'] ?? '');
        $date = (string) ($sale['created_at'] ?? '');
        $outstanding = (float) ($sale['outstanding_amount'] ?? 0);
        $paymentStatus = (string) ($sale['payment_status'] ?? '');
        $approvalStatus = (string) ($sale['approval_status'] ?? '');

        $items = $itemsBySaleId[$saleId] ?? [];
        if (!$items) {
            fputcsv($output, [$invoice, $customer, $phone, $date, $outstanding, $paymentStatus, $approvalStatus, '', '', '', '', '']);
            continue;
        }

        foreach ($items as $item) {
            fputcsv($output, [
                $invoice,
                $customer,
                $phone,
                $date,
                $outstanding,
                $paymentStatus,
                $approvalStatus,
                (string) ($item['product_code'] ?? ''),
                (string) ($item['product_name'] ?? ''),
                (string) ($item['quantity'] ?? ''),
                (string) ($item['price_per_unit'] ?? ''),
                (string) ($item['subtotal'] ?? ''),
            ]);
        }
    }

    fclose($output);
    exit;
}

function fmtCurrency($amount): string {
    $n = is_numeric($amount) ? (float) $amount : 0.0;
    return '₱' . number_format($n, 2);
}

$totalOutstanding = 0.0;
$customersSummary = [];
foreach ($sales as $sale) {
    $outstanding = (float) ($sale['outstanding_amount'] ?? 0);
    $totalOutstanding += $outstanding;

    $name = trim((string) ($sale['customer_name'] ?? ''));
    if ($name === '') $name = 'Customer';
    if (!isset($customersSummary[$name])) $customersSummary[$name] = 0.0;
    $customersSummary[$name] += $outstanding;
}
arsort($customersSummary);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 1600px; margin: 0 auto; background: #fff; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); overflow: hidden; }
        .header { background: linear-gradient(135deg, #4361ee 0%, #3b82f6 100%); color: #fff; padding: 20px 30px; }
        .header h1 { font-size: 22px; margin-bottom: 4px; }
        .header p { opacity: 0.9; font-size: 13px; }

        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; padding: 18px 30px; background: #f8f9fa; border-bottom: 1px solid #e0e0e0; }
        .stat-card { background: #fff; padding: 12px 15px; border-radius: 10px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
        .stat-card .label { font-size: 11px; color: #666; text-transform: uppercase; margin-bottom: 6px; }
        .stat-card .value { font-size: 20px; font-weight: 800; color: #111827; }
        .stat-card .sub { margin-top: 6px; font-size: 12px; color: #6b7280; }

        .filters { padding: 14px 30px; background: #fff; border-bottom: 1px solid #e0e0e0; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .filters input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 13px; flex: 1; min-width: 240px; }
        .filters button, .filters a { padding: 8px 14px; border-radius: 8px; cursor: pointer; font-size: 13px; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
        .btn { background: #4361ee; color: #fff; }
        .btn:hover { background: #3b82f6; }
        .btn-secondary { background: #e5e7eb; color: #111827; }
        .btn-secondary:hover { background: #d1d5db; }
        .btn-export { background: #0ea5e9; color: #fff; }
        .btn-export:hover { background: #0284c7; }

        .table-container { overflow-x: auto; padding: 0 20px 20px 20px; max-height: 70vh; overflow-y: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th { position: sticky; top: 0; z-index: 10; background: #fff; border-bottom: 2px solid #e5e7eb; padding: 10px 8px; text-align: left; font-weight: 700; color: #374151; }
        td { border-bottom: 1px solid #e5e7eb; padding: 10px 8px; vertical-align: top; }
        tr.invoice-row:hover { background: #f8fafc; cursor: pointer; }
        .muted { color: #6b7280; }
        .pill { display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .pill.pending { background: #fff7ed; color: #9a3412; }
        .pill.approved { background: #ecfdf5; color: #065f46; }
        .pill.none { background: #f3f4f6; color: #4b5563; }
        .money { font-weight: 800; color: #0f172a; }
        .items-row { display: none; background: #fbfdff; }
        .items-wrap { padding: 12px; border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; }
        .items-header { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
        .items-header .left { display: flex; flex-direction: column; gap: 2px; }
        .items-header .title { font-weight: 800; color: #111827; }
        .items-header .meta { font-size: 12px; color: #6b7280; }
        .items-table th { position: static; background: #f8fafc; border-bottom: 1px solid #e5e7eb; }
        .items-table td, .items-table th { padding: 8px; font-size: 12px; }
        .right { text-align: right; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-mini { padding: 6px 10px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; border: none; }
        .btn-approve { background: #16a34a; color: #fff; }
        .btn-approve:hover { background: #15803d; }
        .btn-reject { background: #ef4444; color: #fff; }
        .btn-reject:hover { background: #dc2626; }
        .btn-settle { background: #4361ee; color: #fff; }
        .btn-settle:hover { background: #3b82f6; }
        .btn-delete { background: #dc2626; color: #fff; }
        .btn-delete:hover { background: #b91c1c; }
        
        /* Modal Styles for Delete Confirmation */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background: white;
            padding: 25px;
            border-radius: 12px;
            max-width: 450px;
            width: 90%;
            text-align: center;
        }
        .modal-content h3 { margin-bottom: 15px; color: #dc2626; }
        .modal-content p { margin-bottom: 20px; color: #4b5563; }
        .modal-buttons { display: flex; gap: 10px; justify-content: center; }
        .modal-buttons button { padding: 8px 20px; border-radius: 8px; cursor: pointer; border: none; font-weight: 600; }
        .btn-confirm-delete { background: #dc2626; color: white; }
        .btn-confirm-delete:hover { background: #b91c1c; }
        .btn-cancel { background: #e5e7eb; color: #111827; }
        .btn-cancel:hover { background: #d1d5db; }
        
        .toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            color: white;
            z-index: 1100;
            animation: slideIn 0.3s ease;
        }
        .toast.success { background: #16a34a; }
        .toast.error { background: #dc2626; }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        @media (max-width: 768px) {
            body { padding: 12px; }
            .filters { padding: 12px 16px; }
            .stats { padding: 14px 16px; }
            th, td { font-size: 11px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Balance Report (Per Invoice)</h1>
        <p>Click an invoice to view item list</p>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="label">Invoices With Balance</div>
            <div class="value"><?php echo number_format(count($sales)); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Total Outstanding</div>
            <div class="value"><?php echo htmlspecialchars(fmtCurrency($totalOutstanding)); ?></div>
            <div class="sub muted">All invoices combined</div>
        </div>
        <div class="stat-card">
            <div class="label">Top Customer</div>
            <?php
                $topCustomer = array_key_first($customersSummary);
                $topAmount = $topCustomer ? $customersSummary[$topCustomer] : 0;
            ?>
            <div class="value" style="font-size: 14px;"><?php echo htmlspecialchars($topCustomer ?: '-'); ?></div>
            <div class="sub"><?php echo htmlspecialchars(fmtCurrency($topAmount)); ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Pending Approvals</div>
            <div class="value"><?php echo number_format($pendingApprovalsCount); ?></div>
            <div class="sub muted">Pending admin review</div>
        </div>
    </div>

    <div class="filters">
        <form method="GET" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; width:100%;" id="filterForm">
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search customer, phone, or invoice">
            <input type="hidden" name="limit" value="<?php echo (int) $limit; ?>">
            <button type="submit" class="btn">Search</button>
            <a class="btn-secondary" href="/pos-system/views/products/balance.php">Reset</a>
            <a class="btn-secondary" href="/pos-system/views/dashboard/index.php">Dashboard</a>
            <?php
                $exportParams = [];
                if ($search !== '') $exportParams['search'] = $search;
                $exportParams['limit'] = $limit;
                $exportParams['export'] = 'csv';
                $exportHref = '/pos-system/views/products/balance.php?' . http_build_query($exportParams);
            ?>
            <a class="btn-export" href="<?php echo htmlspecialchars($exportHref); ?>">Export CSV</a>
        </form>
    </div>
    <div class="table-container">
        <table id="invoiceTable">
            <thead>
                <tr>
                    <th style="min-width: 150px;">Invoice</th>
                    <th style="min-width: 220px;">Customer</th>
                    <th style="min-width: 140px;">Phone</th>
                    <th style="min-width: 170px;">When</th>
                    <th style="min-width: 140px;" class="right">Outstanding</th>
                    <th style="min-width: 140px;">Status</th>
                    <th style="min-width: 90px;">Items</th>
                    <th style="min-width: 260px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($sales)): ?>
                <tr>
                    <td colspan="8" class="muted" style="padding:24px; text-align:center;">No balances found.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($sales as $sale): ?>
                    <?php
                        $saleId = (int) ($sale['id'] ?? 0);
                        $invoice = trim((string) ($sale['invoice_number'] ?? ''));
                        $customer = trim((string) ($sale['customer_name'] ?? ''));
                        $phone = trim((string) ($sale['customer_phone'] ?? ''));
                        $createdAt = (string) ($sale['created_at'] ?? '');
                        $outstanding = (float) ($sale['outstanding_amount'] ?? 0);
                        $paymentStatus = (string) ($sale['payment_status'] ?? '');
                        $approvalStatus = (string) ($sale['approval_status'] ?? '');
                        $approvalId = (int) ($sale['approval_id'] ?? 0);
                        $items = $itemsBySaleId[$saleId] ?? [];

                        $approvalStatus = strtolower(trim($approvalStatus));
                        $statusPill = 'none';
                        $statusLabel = 'Unknown';

                        if ($approvalStatus === 'pending') {
                            $statusPill = 'pending';
                            $statusLabel = 'Approval Pending';
                        } elseif ($approvalStatus === 'approved') {
                            $statusPill = 'approved';
                            $statusLabel = 'Approved';
                        } elseif (strtolower($paymentStatus) === 'pending') {
                            $statusPill = 'pending';
                            $statusLabel = 'Pending';
                        } elseif ($paymentStatus !== '') {
                            $statusLabel = ucfirst($paymentStatus);
                        }

                        $settleHref = '/pos-system/views/sales/new.php?' . http_build_query([
                            'balance_invoice' => $invoice ?: null,
                            'balance_sale_id' => $saleId,
                            'balance_open' => 1
                        ]);
                    ?>
                    <tr class="invoice-row" data-sale-id="<?php echo $saleId; ?>">
                        <td><strong><?php echo htmlspecialchars($invoice ?: ('#' . $saleId)); ?></strong></td>
                        <td><?php echo htmlspecialchars($customer ?: 'Customer'); ?></td>
                        <td class="muted"><?php echo htmlspecialchars($phone ?: '-'); ?></td>
                        <td class="muted"><?php echo htmlspecialchars($createdAt ? date('Y-m-d h:i A', strtotime($createdAt)) : '-'); ?></td>
                        <td class="right money"><?php echo htmlspecialchars(fmtCurrency($outstanding)); ?></td>
                        <td><span class="pill <?php echo htmlspecialchars($statusPill); ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                        <td class="muted"><?php echo number_format(count($items)); ?></td>
                        <td>
                            <div class="actions">
                                <?php if ($isAdminUser && $approvalId > 0 && $approvalStatus === 'pending'): ?>
                                    <button class="btn-mini btn-approve" type="button" data-action="approve" data-approval-id="<?php echo $approvalId; ?>">Approve</button>
                                    <button class="btn-mini btn-reject" type="button" data-action="reject" data-approval-id="<?php echo $approvalId; ?>">Reject</button>
                                <?php elseif ($isAdminUser && $approvalId > 0 && $approvalStatus === 'approved'): ?>
                                    <a class="btn-mini btn-settle" href="<?php echo htmlspecialchars($settleHref); ?>" data-action="settle">Settle</a>
                                    <button class="btn-mini btn-delete" type="button" data-action="delete" data-sale-id="<?php echo $saleId; ?>" data-invoice="<?php echo htmlspecialchars($invoice); ?>">Delete</button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <tr class="items-row" id="items-<?php echo $saleId; ?>">
                        <td colspan="8">
                            <div class="items-wrap">
                                <div class="items-header">
                                    <div class="left">
                                        <div class="title"><?php echo htmlspecialchars($customer ?: 'Customer'); ?> — <?php echo htmlspecialchars($invoice ?: ('#' . $saleId)); ?></div>
                                        <div class="meta"><?php echo htmlspecialchars($createdAt ? date('Y-m-d h:i A', strtotime($createdAt)) : '-'); ?> • Outstanding: <?php echo htmlspecialchars(fmtCurrency($outstanding)); ?></div>
                                    </div>
                                </div>

                                <?php if (empty($items)): ?>
                                    <div class="muted">No items found for this invoice.</div>
                                <?php else: ?>
                                    <table class="items-table" style="width:100%; border-collapse: collapse;">
                                        <thead>
                                            <tr>
                                                <th style="min-width:120px;">Code</th>
                                                <th>Item</th>
                                                <th style="min-width:80px;" class="right">Qty</th>
                                                <th style="min-width:120px;" class="right">Price</th>
                                                <th style="min-width:120px;" class="right">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                                $itemsTotal = 0.0;
                                                foreach ($items as $item):
                                                    $itemsTotal += (float) ($item['subtotal'] ?? 0);
                                            ?>
                                                <tr>
                                                    <td class="muted"><?php echo htmlspecialchars((string) ($item['product_code'] ?? '')); ?></td>
                                                    <td><?php echo htmlspecialchars((string) ($item['product_name'] ?? '')); ?></td>
                                                    <td class="right"><?php echo number_format((float) ($item['quantity'] ?? 0), 0); ?></td>
                                                    <td class="right"><?php echo htmlspecialchars(fmtCurrency((float) ($item['price_per_unit'] ?? 0))); ?></td>
                                                    <td class="right"><?php echo htmlspecialchars(fmtCurrency((float) ($item['subtotal'] ?? 0))); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <td colspan="4" class="right" style="font-weight:800;">Items Total</td>
                                                <td class="right" style="font-weight:800;"><?php echo htmlspecialchars(fmtCurrency($itemsTotal)); ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal">
    <div class="modal-content">
        <h3>⚠️ Confirm Delete</h3>
        <p>Are you sure you want to delete invoice <strong id="deleteInvoiceName"></strong>?</p>
        <p style="font-size: 12px; color: #dc2626;">This action cannot be undone. All items and records will be permanently removed.</p>
        <div class="modal-buttons">
            <button class="btn-confirm-delete" id="confirmDeleteBtn">Yes, Delete</button>
            <button class="btn-cancel" id="cancelDeleteBtn">Cancel</button>
        </div>
    </div>
</div>

<script>
let pendingDeleteSaleId = null;
let pendingDeleteInvoice = null;

document.querySelectorAll('.invoice-row').forEach(row => {
    row.addEventListener('click', (e) => {
        // Don't expand if clicking on action buttons
        if (e.target.closest('[data-action="settle"], [data-action="approve"], [data-action="reject"], [data-action="delete"]')) {
            return;
        }
        const saleId = row.getAttribute('data-sale-id');
        const detailRow = document.getElementById('items-' + saleId);
        if (!detailRow) return;
        const isOpen = detailRow.style.display === 'table-row';
        detailRow.style.display = isOpen ? 'none' : 'table-row';
    });
});

document.querySelectorAll('[data-action="settle"], [data-action="approve"], [data-action="reject"], [data-action="delete"]').forEach(el => {
    el.addEventListener('click', (event) => {
        event.stopPropagation();
    });
});

// Delete functionality
document.querySelectorAll('[data-action="delete"]').forEach(btn => {
    btn.addEventListener('click', (event) => {
        event.preventDefault();
        const saleId = btn.getAttribute('data-sale-id');
        const invoice = btn.getAttribute('data-invoice');
        
        if (!saleId) return;
        
        pendingDeleteSaleId = saleId;
        pendingDeleteInvoice = invoice;
        
        document.getElementById('deleteInvoiceName').textContent = invoice || ('#' + saleId);
        document.getElementById('deleteModal').style.display = 'flex';
    });
});

document.getElementById('confirmDeleteBtn').addEventListener('click', async () => {
    if (!pendingDeleteSaleId) return;
    
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Deleting...';
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_sale');
        formData.append('sale_id', pendingDeleteSaleId);
        
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showToast(result.message, 'success');
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showToast(result.message, 'error');
            closeModal();
        }
    } catch (error) {
        showToast('Error deleting sale: ' + error.message, 'error');
        closeModal();
    } finally {
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Yes, Delete';
    }
});

document.getElementById('cancelDeleteBtn').addEventListener('click', closeModal);

function closeModal() {
    document.getElementById('deleteModal').style.display = 'none';
    pendingDeleteSaleId = null;
    pendingDeleteInvoice = null;
}

function showToast(message, type) {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

async function decideApproval(approvalId, action) {
    const note = '';
    const res = await fetch('/pos-system/api/sales/approval-decide.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify({
            approval_id: Number(approvalId),
            action: String(action || '').toLowerCase(),
            note
        })
    });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data || !data.success) {
        const msg = (data && data.message) ? data.message : 'Approval request failed';
        throw new Error(msg);
    }
    return data;
}

document.querySelectorAll('[data-action="approve"], [data-action="reject"]').forEach(btn => {
    btn.addEventListener('click', async (event) => {
        event.preventDefault();
        const approvalId = btn.getAttribute('data-approval-id');
        const action = btn.getAttribute('data-action');
        if (!approvalId) return;
        const ok = confirm((action === 'approve' ? 'Approve' : 'Reject') + ' this request?');
        if (!ok) return;

        btn.disabled = true;
        try {
            await decideApproval(approvalId, action);
            window.location.reload();
        } catch (err) {
            alert(err.message || 'Unable to process approval');
            btn.disabled = false;
        }
    });
});

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('deleteModal');
    if (event.target === modal) {
        closeModal();
    }
}
</script>
</body>
</html>