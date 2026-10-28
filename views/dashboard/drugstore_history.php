<?php
session_start();

// Check if this is an AJAX request for sale items
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_sale_items' && isset($_GET['sale_id'])) {
    header('Content-Type: application/json');
    
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Not logged in']);
        exit();
    }
    
    $sale_id = (int)$_GET['sale_id'];
    
    if ($sale_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid sale ID']);
        exit();
    }
    
    // Database connection
    $servername = "localhost";
    $username = "root";
    $password = "";
    $dbname = "drugstore1_db";
    
    try {
        $db = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit();
    }
    
    try {
        // Get sale details
        $sale_query = "SELECT * FROM drugstore_sales WHERE id = :sale_id";
        $stmt = $db->prepare($sale_query);
        $stmt->execute([':sale_id' => $sale_id]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$sale) {
            echo json_encode(['success' => false, 'message' => 'Sale not found']);
            exit();
        }
        
        // Get sale items with product details and batch usage
        $items_query = "
            SELECT 
                dsi.id,
                dsi.sale_id,
                dsi.product_id,
                dsi.quantity,
                dsi.price,
                dsi.subtotal,
                di.brand_name,
                di.generic_name,
                GROUP_CONCAT(CONCAT(dsb.batch_number, ' (', dsb.quantity, ')') SEPARATOR ', ') AS batch_details
            FROM drugstore_sale_items dsi
            LEFT JOIN drugstore_inventory di ON dsi.product_id = di.id
            LEFT JOIN drugstore_sale_batches dsb_items ON dsb_items.sale_item_id = dsi.id
            LEFT JOIN drugstore_batches dsb ON dsb.id = dsb_items.batch_id
            WHERE dsi.sale_id = :sale_id
            GROUP BY dsi.id, dsi.sale_id, dsi.product_id, dsi.quantity, dsi.price, dsi.subtotal, di.brand_name, di.generic_name
        ";
        $stmt = $db->prepare($items_query);
        $stmt->execute([':sale_id' => $sale_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'sale' => $sale,
            'items' => $items
        ]);
        
    } catch(PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['selected_system']) || $_SESSION['selected_system'] !== 'drugstore') {
    header('Location: /pos-system/index.php');
    exit();
}

// Database connection for drugstore1_db
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "drugstore1_db";

try {
    $db = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Filters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$sale_type = isset($_GET['sale_type']) ? $_GET['sale_type'] : 'all';

// Get sales from drugstore_sales table
$sales_query = "SELECT * FROM drugstore_sales 
                WHERE DATE(sale_date) BETWEEN :start_date AND :end_date";

if ($sale_type != 'all') {
    $sales_query .= " AND sale_type = :sale_type";
}

$sales_query .= " ORDER BY sale_date DESC";

$stmt = $db->prepare($sales_query);
$params = [':start_date' => $start_date, ':end_date' => $end_date];
if ($sale_type != 'all') {
    $params[':sale_type'] = $sale_type;
}
$stmt->execute($params);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

$activePage = 'history';

// Calculate totals
$total_sales = 0;
$total_items = 0;
foreach ($sales as $sale) {
    $total_sales += $sale['grand_total'];
    $total_items++;
}
ob_start();
?>
<button class="btn btn-secondary" onclick="printReport()">
    <i class="fas fa-print"></i>
    <span>Print Report</span>
</button>
<?php
$drugstoreHeaderActions = ob_get_clean();
$drugstoreHeaderIcon = 'fa-history';
$drugstoreHeaderTitle = 'Sale History';
$drugstoreHeaderSubtitle = 'Review transactions across retail and wholesale activity.';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drugstore - Sale History</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/pos-system/assets/css/drugstore-dashboard-base.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f4f6f9;
        }

        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 25px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-header i {
            font-size: 50px;
        }

        .sidebar-header h3 {
            margin: 10px 0 5px;
        }

        .sidebar-menu {
            list-style: none;
            padding: 20px 0;
        }

        .sidebar-menu li a {
            display: flex;
            align-items: center;
            padding: 12px 25px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            transition: all 0.3s;
        }

        .sidebar-menu li a:hover,
        .sidebar-menu li.active a {
            background: rgba(255,255,255,0.1);
            color: white;
            border-left: 4px solid #ffc107;
        }

        .sidebar-menu li a i {
            width: 25px;
            margin-right: 15px;
        }

        .main-content {
            margin-left: 280px;
            padding: 20px;
        }

        .top-bar {
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .page-title h2 {
            color: #333;
        }

        .filter-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .filter-form {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: #666;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #007bff;
            color: white;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .summary-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .summary-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .summary-card .number {
            font-size: 28px;
            font-weight: bold;
            color: #333;
        }

        .sales-table {
            background: white;
            border-radius: 10px;
            overflow-x: auto;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        th {
            background: #f8f9fa;
            font-weight: 600;
        }

        tr:hover {
            background: #f5f5f5;
        }

        .view-details {
            background: #17a2b8;
            color: white;
            padding: 4px 8px;
            border-radius: 3px;
            text-decoration: none;
            font-size: 12px;
            cursor: pointer;
            border: none;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: white;
            padding: 25px;
            border-radius: 10px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .close {
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-retail {
            background: #28a745;
            color: white;
        }

        .badge-wholesale {
            background: #ffc107;
            color: #333;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-receipt {
            background: #ffc107;
            color: #333;
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .items-table th,
        .items-table td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .items-table th {
            background: #f8f9fa;
        }

        .receipt-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .receipt-info p {
            margin: 5px 0;
        }

        .print-receipt-btn {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
        }
    </style>
    <link rel="stylesheet" href="/pos-system/assets/css/drugstore-dashboard-base.css">
</head>
<body>
    <div class="app">
    <?php include __DIR__ . '/partials/sidebar_dashboard.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/partials/drugstore_page_header.php'; ?>

        <div class="filter-section">
            <form method="GET" class="filter-form">
                <div class="filter-group">
                    <label>Start Date</label>
                    <input type="date" name="start_date" value="<?php echo $start_date; ?>">
                </div>
                <div class="filter-group">
                    <label>End Date</label>
                    <input type="date" name="end_date" value="<?php echo $end_date; ?>">
                </div>
                <div class="filter-group">
                    <label>Sale Type</label>
                    <select name="sale_type">
                        <option value="all" <?php echo $sale_type == 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="retail" <?php echo $sale_type == 'retail' ? 'selected' : ''; ?>>Retail</option>
                        <option value="wholesale" <?php echo $sale_type == 'wholesale' ? 'selected' : ''; ?>>Wholesale/Agent</option>
                    </select>
                </div>
                <div class="filter-group">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="drugstore_history.php" class="btn btn-success">Reset</a>
                </div>
            </form>
        </div>

        <div class="summary-cards">
            <div class="summary-card">
                <h3>Total Sales</h3>
                <div class="number">₱<?php echo number_format($total_sales, 2); ?></div>
            </div>
            <div class="summary-card">
                <h3>Total Transactions</h3>
                <div class="number"><?php echo count($sales); ?></div>
            </div>
            <div class="summary-card">
                <h3>Total Items Sold</h3>
                <div class="number"><?php echo number_format($total_items); ?></div>
            </div>
        </div>

        <div class="data-table sales-table" id="printArea">
            <div class="table-header">
                <h3 class="table-title">Transactions</h3>
                <div class="table-summary"><?php echo count($sales); ?> records in the selected period</div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Date & Time</th>
                        <th>Type</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sales as $sale): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($sale['invoice_number']); ?></td>
                        <td><?php echo date('M d, Y h:i A', strtotime($sale['sale_date'])); ?></td>
                        <td>
                            <span class="badge <?php echo $sale['sale_type'] == 'retail' ? 'badge-retail' : 'badge-wholesale'; ?>">
                                <?php echo ucfirst($sale['sale_type']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer'); ?></td>
                        <td>₱<?php echo number_format($sale['grand_total'], 2); ?></td>
                        <td><?php echo ucfirst($sale['payment_method']); ?></td>
                        <td>
                            <div class="action-buttons">
                                <button class="view-details" onclick="viewSaleDetails(<?php echo $sale['id']; ?>)">
                                    <i class="fas fa-eye"></i> View Items
                                </button>
                                <button class="view-details btn-receipt" onclick="reprintReceipt(<?php echo $sale['id']; ?>)">
                                    <i class="fas fa-print"></i> Reprint
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($sales)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px;">No sales found for the selected period.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    </div>

    <!-- Sale Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Sale Details</h3>
                <span class="close" onclick="closeModal()">&times;</span>
            </div>
            <div id="detailsContent"></div>
            <div class="print-receipt-btn">
                <button class="btn btn-info" onclick="printCurrentReceipt()">
                    <i class="fas fa-print"></i> Print Receipt
                </button>
            </div>
        </div>
    </div>

    <script>
        let currentSaleData = null;

        function viewSaleDetails(saleId) {
            // Show loading message
            document.getElementById('detailsContent').innerHTML = '<p style="text-align: center;">Loading sale details...</p>';
            document.getElementById('detailsModal').style.display = 'flex';
            
            // Fetch sale items via AJAX
            fetch(`?ajax=get_sale_items&sale_id=${saleId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        currentSaleData = data;
                        displaySaleDetails(data);
                    } else {
                        document.getElementById('detailsContent').innerHTML = `<p style="color: red;">Error: ${data.message}</p>`;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('detailsContent').innerHTML = '<p style="color: red;">Failed to load sale details. Please try again.</p>';
                });
        }
        
        function displaySaleDetails(data) {
            let itemsHtml = `
                <div class="receipt-info">
                    <p><strong>Invoice Number:</strong> ${escapeHtml(data.sale.invoice_number)}</p>
                    <p><strong>Date & Time:</strong> ${new Date(data.sale.sale_date).toLocaleString()}</p>
                    <p><strong>Customer Name:</strong> ${escapeHtml(data.sale.customer_name || 'Walk-in Customer')}</p>
                    <p><strong>Payment Method:</strong> ${escapeHtml(data.sale.payment_method)}</p>
                </div>
                <h4>Items Purchased:</h4>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Qty</th>
                            <th>Product</th>
                            <th>Price</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            
            if (data.items.length === 0) {
                itemsHtml += '<tr><td colspan="4" style="text-align: center;">No items found for this sale</td></tr>';
            } else {
                data.items.forEach(item => {
                    itemsHtml += `
                        <tr>
                            <td>${item.quantity}</td>
                            <td>${escapeHtml(item.brand_name || item.generic_name || 'Unknown Product')}<br><small>${escapeHtml(item.batch_details || 'Batch not recorded')}</small></td>
                            <td>₱${parseFloat(item.price).toFixed(2)}</td>
                            <td>₱${parseFloat(item.subtotal).toFixed(2)}</td>
                        </tr>
                    `;
                });
            }
            
            itemsHtml += `
                    </tbody>
                </table>
                <div style="margin-top: 20px; padding-top: 10px; border-top: 1px solid #ddd; text-align: right;">
                    <p><strong>Total Amount:</strong> ₱${parseFloat(data.sale.grand_total).toFixed(2)}</p>
                    <p><strong>Amount Tendered:</strong> ₱${parseFloat(data.sale.amount_tendered).toFixed(2)}</p>
                    <p><strong>Change:</strong> ₱${parseFloat(data.sale.change_amount).toFixed(2)}</p>
                </div>
            `;
            
            document.getElementById('detailsContent').innerHTML = itemsHtml;
        }
        
        function reprintReceipt(saleId) {
            // Show loading indicator
            const btn = event.target.closest('button');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
            btn.disabled = true;
            
            fetch(`?ajax=get_sale_items&sale_id=${saleId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        openReceiptPrintWindow(data);
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Failed to load receipt data');
                })
                .finally(() => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                });
        }
        
        function printCurrentReceipt() {
            if (currentSaleData) {
                openReceiptPrintWindow(currentSaleData);
            } else {
                alert('No receipt data available');
            }
        }
        
        function openReceiptPrintWindow(data) {
            let printWindow = window.open('', '_blank');
            let receiptHtml = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Receipt - ${data.sale.invoice_number}</title>
                    <style>
                        body {
                            font-family: 'Courier New', monospace;
                            font-size: 12px;
                            width: 300px;
                            margin: 0 auto;
                            padding: 20px;
                        }
                        .receipt {
                            text-align: center;
                        }
                        .receipt-header {
                            text-align: center;
                            margin-bottom: 15px;
                        }
                        .receipt-header h3 {
                            margin: 5px 0;
                        }
                        .receipt-items {
                            width: 100%;
                            margin: 10px 0;
                            border-collapse: collapse;
                        }
                        .receipt-items th,
                        .receipt-items td {
                            padding: 5px 0;
                            text-align: left;
                        }
                        .receipt-items th {
                            border-bottom: 1px dashed #000;
                        }
                        .receipt-totals {
                            text-align: right;
                            margin-top: 10px;
                            padding-top: 10px;
                            border-top: 1px dashed #000;
                        }
                        hr {
                            border: none;
                            border-top: 1px dashed #000;
                            margin: 10px 0;
                        }
                    </style>
                </head>
                <body>
                    <div class="receipt">
                        <div class="receipt-header">
                            <p><strong>${data.sale.invoice_number}</strong></p>
                            <p>${new Date(data.sale.sale_date).toLocaleString()}</p>
                            <p>Customer: ${data.sale.customer_name || 'Walk-in Customer'}</p>
                        </div>
                        <hr>
                        <table class="receipt-items">
                            <thead>
                                <tr>
                                    <th>Qty</th>
                                    <th>Item</th>
                                    <th>Price</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            data.items.forEach(item => {
                receiptHtml += `
                    <tr>
                        <td>${item.quantity}</td>
                        <td>${escapeHtml(item.brand_name || item.generic_name || 'Unknown Product')}<br><small>${escapeHtml(item.batch_details || 'Batch not recorded')}</small></td>
                        <td>₱${parseFloat(item.price).toFixed(2)}</td>
                        <td>₱${parseFloat(item.subtotal).toFixed(2)}</td>
                    </tr>
                `;
            });
            
            receiptHtml += `
                            </tbody>
                        </table>
                        <hr>
                        <div class="receipt-totals">
                            <p><strong>Total:</strong> ₱${parseFloat(data.sale.grand_total).toFixed(2)}</p>
                            <p>Amount Tendered: ₱${parseFloat(data.sale.amount_tendered).toFixed(2)}</p>
                            <p>Change: ₱${parseFloat(data.sale.change_amount).toFixed(2)}</p>
                            <p>Payment: ${data.sale.payment_method}</p>
                        </div>
                        <hr>
                        <p>Thank you for your purchase!</p>
                        <p>Please come again!</p>
                    </div>
                    <script>
                        window.onload = function() {
                            window.print();
                            setTimeout(function() {
                                window.close();
                            }, 500);
                        };
                    <\/script>
                </body>
                </html>
            `;
            
            printWindow.document.write(receiptHtml);
            printWindow.document.close();
        }
        
        function closeModal() {
            document.getElementById('detailsModal').style.display = 'none';
            currentSaleData = null;
        }
        
        function printReport() {
            let printWindow = window.open('', '_blank');
            let content = document.getElementById('printArea').innerHTML;
            printWindow.document.write(`
                <html>
                <head>
                    <title>Sale History Report</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        h2 { text-align: center; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background: #f5f5f5; }
                    </style>
                </head>
                <body>
                    <div style="text-align: center;">
                        <h2>LHUNDREN DRUGSTORE</h2>
                        <p>Paz St. Zone 5, Bangued, Abra</p>
                        <p>Tel. No. 09675182960</p>
                        <hr>
                        <h3>Sale History Report</h3>
                        <p>Period: <?php echo date('F d, Y', strtotime($start_date)); ?> - <?php echo date('F d, Y', strtotime($end_date)); ?></p>
                    </div>
                    ${content}
                    <div style="margin-top: 20px; text-align: center;">
                        <p>Total Sales: ₱<?php echo number_format($total_sales, 2); ?></p>
                        <p>Total Transactions: <?php echo count($sales); ?></p>
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, m => map[m]);
        }
        
        window.onclick = function(event) {
            let modal = document.getElementById('detailsModal');
            if (event.target == modal) {
                modal.style.display = 'none';
                currentSaleData = null;
            }
        }
    </script>
    <script src="/pos-system/assets/js/drugstore-sidebar-active.js"></script>
</body>
</html>
