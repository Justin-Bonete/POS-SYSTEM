<?php
session_start();

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

// Get sale type from URL
$sale_type = isset($_GET['type']) ? $_GET['type'] : 'retail';
$price_column = ($sale_type == 'wholesale') ? 'wholesale_price' : 'store_price';
$price_label = ($sale_type == 'wholesale') ? 'Agent/Wholesale Price' : 'Retail Price';
$title = ($sale_type == 'wholesale') ? 'Agent Sales' : 'Retail Sales';
$activePage = ($sale_type == 'wholesale') ? 'agent' : 'retail';

// Get inventory with positive stock from drugstore_inventory table
$stmt = $db->prepare("SELECT id, barcode, generic_name, brand_name, expiry_date, quantity, unit, $price_column as price 
                      FROM drugstore_inventory 
                      WHERE quantity > 0 
                      ORDER BY brand_name ASC");
$stmt->execute();
$inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
ob_start();
?>
<div class="sale-type-buttons">
    <a href="?type=retail" class="type-btn <?php echo $sale_type == 'retail' ? 'active' : ''; ?>">
        <i class="fas fa-store"></i>
        <span>Retail</span>
    </a>
    <a href="?type=wholesale" class="type-btn <?php echo $sale_type == 'wholesale' ? 'active' : ''; ?>">
        <i class="fas fa-truck"></i>
        <span>Agent/Wholesale</span>
    </a>
</div>
<?php
$drugstoreHeaderActions = ob_get_clean();
$drugstoreHeaderIcon = $sale_type == 'wholesale' ? 'fa-truck' : 'fa-store';
$drugstoreHeaderTitle = $title;
$drugstoreHeaderSubtitle = $sale_type == 'wholesale'
    ? 'Bulk sales for agents and wholesale customers.'
    : 'Process walk-in purchases with the same dashboard look and feel.';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drugstore - <?php echo $title; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/pos-system/assets/css/drugstore-dashboard-base.css">
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #f4f6f9;
            overflow-x: hidden;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            transition: all 0.3s;
            z-index: 100;
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

        .sidebar-header p {
            font-size: 12px;
            opacity: 0.8;
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

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 20px;
            min-height: 100vh;
        }

        /* Top Bar */
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
            font-size: 24px;
        }

        .page-title p {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }

        .sale-type-buttons {
            display: flex;
            gap: 10px;
        }

        .type-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        .type-btn.active {
            background: #28a745;
            color: white;
        }

        .type-btn:not(.active) {
            background: #e0e0e0;
            color: #333;
        }

        .type-btn:not(.active):hover {
            background: #ccc;
        }

        /* Search Section */
        .search-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .search-box {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }

        .search-box input {
            flex: 1;
            padding: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        .search-box input:focus {
            outline: none;
            border-color: #28a745;
        }

        .cart-btn {
            background: #ffc107;
            color: #333;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            font-size: 16px;
            transition: all 0.3s;
        }

        .cart-btn:hover {
            background: #e0a800;
            transform: translateY(-2px);
        }

        /* Inventory Table */
        .inventory-table {
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
            color: #555;
            position: sticky;
            top: 0;
        }

        tr:hover {
            background: #f5f5f5;
        }

        .btn-add {
            background: #28a745;
            color: white;
            padding: 6px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s;
        }

        .btn-add:hover {
            background: #218838;
            transform: scale(1.05);
        }

        .low-stock {
            color: #ffc107;
            font-weight: bold;
        }

        .expired {
            color: #dc3545;
            font-weight: bold;
        }

        .expiring-soon {
            color: #fd7e14;
            font-weight: bold;
        }

        /* Modal Styles */
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
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }

        .close {
            font-size: 28px;
            cursor: pointer;
            color: #999;
            transition: color 0.3s;
        }

        .close:hover {
            color: #333;
        }

        .cart-table {
            width: 100%;
            margin-bottom: 20px;
            border-collapse: collapse;
        }

        .cart-table th, .cart-table td {
            padding: 10px;
            text-align: center;
            border: 1px solid #e0e0e0;
        }

        .cart-table th {
            background: #f8f9fa;
        }

        .cart-table input {
            width: 70px;
            padding: 5px;
            text-align: center;
            border: 1px solid #ddd;
            border-radius: 3px;
        }

        .btn-remove {
            background: #dc3545;
            color: white;
            padding: 4px 8px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-remove:hover {
            background: #c82333;
        }

        .total-section {
            text-align: right;
            font-size: 20px;
            font-weight: bold;
            margin: 20px 0;
            padding-top: 10px;
            border-top: 2px solid #e0e0e0;
        }

        .btn-checkout {
            background: #28a745;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            width: 100%;
            transition: all 0.3s;
        }

        .btn-checkout:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .receipt-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .save-status {
            margin-top: 14px;
            font-size: 13px;
            color: #64748b;
            text-align: center;
        }

        .save-status.success {
            color: #15803d;
        }

        .save-status.error {
            color: #b91c1c;
        }

        .btn-save {
            background: #007bff;
            color: white;
            padding: 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            flex: 1;
            transition: all 0.3s;
        }

        .btn-save:hover {
            background: #0056b3;
        }

        .btn-print {
            background: #6c757d;
            color: white;
            padding: 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            flex: 1;
            transition: all 0.3s;
        }

        .btn-print:hover {
            background: #5a6268;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-info {
            background: #17a2b8;
            color: white;
        }

        .badge-warning {
            background: #ffc107;
            color: #333;
        }

        .payment-form {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .payment-form input {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            width: 100%;
            margin-top: 5px;
        }

        .receipt-content {
            font-family: monospace;
            line-height: 1.6;
        }
    </style>
    <link rel="stylesheet" href="/pos-system/assets/css/drugstore-dashboard-base.css">
</head>
<body>
    <div class="app">
    <?php include __DIR__ . '/partials/sidebar_dashboard.php'; ?>

    <div class="main-content">
        <?php include __DIR__ . '/partials/drugstore_page_header.php'; ?>

        <div class="search-section">
            <div class="search-box">
                <input type="text" id="searchInput" placeholder="🔍 Search by Barcode, Generic Name, or Brand Name..." onkeyup="searchProducts()" autofocus>
                <button class="cart-btn" onclick="openCart()">
                    <i class="fas fa-shopping-cart"></i> Cart (<span id="cartCount">0</span>)
                </button>
            </div>
            <div class="search-box">
                <input type="text" id="barcodeInput" placeholder="📷 Or scan barcode here and press Enter..." onkeypress="handleBarcode(event)">
            </div>
        </div>

        <div class="inventory-table">
            <div class="table-header">
                <h3 class="table-title">Available Products</h3>
                <div class="table-summary"><?php echo count($inventory); ?> items ready for checkout</div>
            </div>
            <table id="inventoryTable">
                <thead>
                    <tr>
                        <th>Barcode</th>
                        <th>Generic Name</th>
                        <th>Brand Name</th>
                        <th>Expiry Date</th>
                        <th>Stock</th>
                        <th><?php echo $price_label; ?></th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inventory as $item): 
                        $expiry_class = '';
                        $expiry_warning = '';
                        if ($item['expiry_date'] && $item['expiry_date'] != '0000-00-00') {
                            $days_until = ceil((strtotime($item['expiry_date']) - time()) / 86400);
                            if ($days_until < 0) {
                                $expiry_class = 'expired';
                                $expiry_warning = ' (EXPIRED)';
                            } elseif ($days_until <= 30) {
                                $expiry_class = 'expiring-soon';
                                $expiry_warning = ' (Expires in ' . $days_until . ' days)';
                            }
                        }
                        $stock_class = ($item['quantity'] <= 10) ? 'low-stock' : '';
                        $expiry_display = ($item['expiry_date'] && $item['expiry_date'] != '0000-00-00') ? date('Y-m-d', strtotime($item['expiry_date'])) . $expiry_warning : 'N/A';
                        $encodedItem = htmlspecialchars(
                            json_encode($item, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
                            ENT_QUOTES,
                            'UTF-8'
                        );
                    ?>
                    <tr data-barcode="<?php echo htmlspecialchars($item['barcode']); ?>">
                        <td><?php echo htmlspecialchars($item['barcode']); ?></td>
                        <td><?php echo htmlspecialchars($item['generic_name']); ?></td>
                        <td><?php echo htmlspecialchars($item['brand_name']); ?></td>
                        <td class="<?php echo $expiry_class; ?>">
                            <?php echo $expiry_display; ?>
                        </td>
                        <td class="<?php echo $stock_class; ?>">
                            <?php echo $item['quantity'] . ' ' . $item['unit']; ?>
                        </td>
                        <td>₱<?php echo number_format($item['price'], 2); ?></td>
                        <td>
                            <button class="btn-add" type="button" data-item="<?php echo $encodedItem; ?>" onclick="addToCartFromButton(this)">
                                <i class="fas fa-plus"></i> Add
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    </div>

    <!-- Cart Modal -->
    <div id="cartModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-shopping-cart"></i> Shopping Cart - <?php echo $title; ?></h3>
                <span class="close" onclick="closeCart()">&times;</span>
            </div>
            <div id="cartItems"></div>
            <div class="total-section">
                <strong>Total: ₱<span id="cartTotal">0.00</span></strong>
            </div>
            <div class="payment-form">
                <label><strong>👤 Customer Name (Optional):</strong></label>
                <input type="text" id="customerName" placeholder="Enter customer name">
                <?php if ($sale_type == 'wholesale'): ?>
                <label style="margin-top: 10px;"><strong>🏢 Agent/Company Name:</strong></label>
                <input type="text" id="agentName" placeholder="Enter agent or company name">
                <?php endif; ?>
                <label style="margin-top: 10px;"><strong>💰 Amount Tendered:</strong></label>
                <input type="number" id="amountTendered" placeholder="Enter amount" step="0.01">
            </div>
            <button class="btn-checkout" onclick="checkout()">
                <i class="fas fa-credit-card"></i> Checkout
            </button>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div id="receiptModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-receipt"></i> Official Receipt</h3>
                <span class="close" onclick="closeReceipt()">&times;</span>
            </div>
            <div id="receiptContent" class="receipt-content"></div>
            <div class="receipt-buttons">
                <button id="saveSaleBtn" class="btn-save" onclick="saveSale(false)"><i class="fas fa-save"></i> Save Sale</button>
                <button class="btn-print" onclick="printReceipt()"><i class="fas fa-print"></i> Print Receipt</button>
            </div>
            <div id="saveStatus" class="save-status"></div>
        </div>
    </div>

    <script>
        let cart = [];
        let currentSaleType = '<?php echo $sale_type; ?>';
        let grandTotal = 0;

        function setSaveStatus(message = '', type = '') {
            const status = document.getElementById('saveStatus');
            status.textContent = message;
            status.className = 'save-status' + (type ? ` ${type}` : '');
        }

        function setSaveButtonState(label, disabled, iconClass = 'fa-save') {
            const saveBtn = document.getElementById('saveSaleBtn');
            saveBtn.disabled = disabled;
            saveBtn.innerHTML = `<i class="fas ${iconClass}"></i> ${label}`;
        }

        function searchProducts() {
            let input = document.getElementById('searchInput').value.toLowerCase();
            let rows = document.querySelectorAll('#inventoryTable tbody tr');
            
            rows.forEach(row => {
                let text = row.textContent.toLowerCase();
                row.style.display = text.includes(input) ? '' : 'none';
            });
        }

        function handleBarcode(event) {
            if (event.key === 'Enter') {
                let barcode = event.target.value.trim();
                if (barcode) {
                    let rows = document.querySelectorAll('#inventoryTable tbody tr');
                    let found = false;
                    for (let row of rows) {
                        let barcodeCell = row.cells[0];
                        if (barcodeCell && barcodeCell.textContent.trim() === barcode) {
                            let addBtn = row.querySelector('.btn-add');
                            if (addBtn) addBtn.click();
                            found = true;
                            break;
                        }
                    }
                    if (!found) {
                        alert('❌ Product not found for barcode: ' + barcode);
                    }
                    event.target.value = '';
                }
            }
        }

        function addToCartFromButton(button) {
            const rawItem = button?.dataset?.item;
            if (!rawItem) {
                alert('Unable to load this product. Please refresh the page and try again.');
                return;
            }

            try {
                addToCart(JSON.parse(rawItem));
            } catch (error) {
                console.error('Failed to parse product data:', error, rawItem);
                alert('Unable to add this product right now. Please refresh the page and try again.');
            }
        }

        function addToCart(item) {
            let qty = prompt('Enter quantity for ' + item.brand_name + ':', '1');
            if (qty === null) return;
            
            qty = parseInt(qty);
            if (isNaN(qty) || qty <= 0) {
                alert('Invalid quantity!');
                return;
            }
            
            if (qty > item.quantity) {
                alert('Not enough stock! Available: ' + item.quantity);
                return;
            }
            
            let existingItem = cart.find(i => i.id === item.id);
            if (existingItem) {
                if (existingItem.qty + qty <= item.quantity) {
                    existingItem.qty += qty;
                } else {
                    alert('Stock limit reached! Max available: ' + item.quantity);
                    return;
                }
            } else {
                cart.push({
                    id: item.id,
                    barcode: item.barcode,
                    generic_name: item.generic_name,
                    brand_name: item.brand_name,
                    expiry_date: item.expiry_date,
                    price: parseFloat(item.price),
                    qty: qty,
                    max_stock: item.quantity,
                    unit: item.unit
                });
            }
            
            updateCartCount();
            showNotification(item.brand_name + ' added to cart!', 'success');
        }

        function showNotification(message, type) {
            // Simple alert for now - can be enhanced with toast notifications
            console.log(message);
        }

        function updateCartCount() {
            document.getElementById('cartCount').innerText = cart.length;
        }

        function openCart() {
            renderCart();
            document.getElementById('cartModal').style.display = 'flex';
        }

        function closeCart() {
            document.getElementById('cartModal').style.display = 'none';
        }

        function closeReceipt() {
            document.getElementById('receiptModal').style.display = 'none';
        }

        function renderCart() {
            if (cart.length === 0) {
                document.getElementById('cartItems').innerHTML = '<p style="text-align:center;padding:20px;">Your cart is empty.</p>';
                document.getElementById('cartTotal').innerText = '0.00';
                return;
            }
            
            let html = '<table class="cart-table"><thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th><th>Action</th></tr></thead><tbody>';
            let total = 0;
            
            cart.forEach((item, index) => {
                let subtotal = item.price * item.qty;
                total += subtotal;
                html += `<tr>
                    <td style="text-align:left;"><strong>${escapeHtml(item.brand_name)}</strong><br><small>${escapeHtml(item.generic_name)}</small></td>
                    <td><input type="number" min="1" max="${item.max_stock}" value="${item.qty}" onchange="updateQuantity(${index}, this.value)"></td>
                    <td>₱${item.price.toFixed(2)}</td>
                    <td>₱${subtotal.toFixed(2)}</td>
                    <td><button class="btn-remove" onclick="removeFromCart(${index})">Remove</button></td>
                </tr>`;
            });
            
            html += '</tbody></table>';
            document.getElementById('cartItems').innerHTML = html;
            document.getElementById('cartTotal').innerText = total.toFixed(2);
            grandTotal = total;
        }

        function updateQuantity(index, qty) {
            qty = parseInt(qty);
            if (isNaN(qty) || qty < 1) qty = 1;
            if (qty > cart[index].max_stock) {
                alert('Not enough stock! Max: ' + cart[index].max_stock);
                qty = cart[index].max_stock;
            }
            cart[index].qty = qty;
            renderCart();
        }

        function removeFromCart(index) {
            cart.splice(index, 1);
            renderCart();
            updateCartCount();
        }

        function checkout() {
            if (cart.length === 0) {
                alert('Cart is empty!');
                return;
            }
            
            let amountTendered = parseFloat(document.getElementById('amountTendered').value);
            if (isNaN(amountTendered) || amountTendered < grandTotal) {
                alert('Please enter a valid amount (must be at least ₱' + grandTotal.toFixed(2) + ')');
                return;
            }
            
            let change = amountTendered - grandTotal;
            let customerName = document.getElementById('customerName').value.trim() || 'Walk-in Customer';
            let agentName = currentSaleType === 'wholesale' ? document.getElementById('agentName')?.value.trim() || '' : '';
            
            let now = new Date();
            let dateTime = now.toLocaleString();
            let invoiceNumber = 'DR-' + now.getFullYear() + 
                               String(now.getMonth() + 1).padStart(2, '0') + 
                               String(now.getDate()).padStart(2, '0') + '-' +
                               String(now.getHours()).padStart(2, '0') +
                               String(now.getMinutes()).padStart(2, '0') +
                               String(now.getSeconds()).padStart(2, '0');
            
            let receiptHtml = `
                <div style="text-align:center;margin-bottom:20px;">
                    <p><strong>DATE:</strong> ${dateTime}</p>
                    ${agentName ? `<p><strong>AGENT/COMPANY:</strong> ${escapeHtml(agentName)}</p>` : ''}
                    <hr>
                </div>
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid #ddd;">
                            <th style="text-align:left;">Qty</th>
                            <th style="text-align:left;">Item Description</th>
                            <th style="text-align:right;">Unit Price</th>
                            <th style="text-align:right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>`;
            
            cart.forEach(item => {
                let subtotal = item.price * item.qty;
                receiptHtml += `
                    <tr>
                        <td style="text-align:left;">${item.qty} ${item.unit}</td>
                        <td style="text-align:left;">${escapeHtml(item.brand_name)}<br><small>${escapeHtml(item.generic_name)}</small></td>
                        <td style="text-align:right;">₱${item.price.toFixed(2)}</td>
                        <td style="text-align:right;">₱${subtotal.toFixed(2)}</td>
                    </tr>`;
            });
            
            receiptHtml += `
                    </tbody>
                    <tfoot>
                        <tr style="border-top:2px solid #ddd;">
                            <td colspan="3" style="text-align:right;"><strong>SUB-TOTAL:</strong></td>
                            <td style="text-align:right;"><strong>₱${grandTotal.toFixed(2)}</strong></td>
                        </tr>
                        <tr>
                            <td colspan="3" style="text-align:right;">AMOUNT TENDERED:</td>
                            <td style="text-align:right;">₱${amountTendered.toFixed(2)}</td>
                        </tr>
                        <tr>
                            <td colspan="3" style="text-align:right;">CHANGE:</td>
                            <td style="text-align:right;">₱${change.toFixed(2)}</td>
                        </tr>
                    </tfoot>
                </table>
                <div style="text-align:center;margin-top:20px;">
                    <p><strong>Thank you for your purchase!</strong></p>
                    <p>Please come again!</p>
                </div>`;
            
            // Store receipt data for saving
            window.currentReceipt = {
                invoice_number: invoiceNumber,
                customer_name: customerName,
                agent_name: agentName,
                sale_type: currentSaleType,
                total: grandTotal,
                amount_tendered: amountTendered,
                change: change,
                payment_method: 'cash',
                items: cart.map(item => ({ ...item })),
                date: now.toISOString(),
                saved: false,
                saving: false
            };
            
            document.getElementById('receiptContent').innerHTML = receiptHtml;
            setSaveButtonState('Saving...', true, 'fa-spinner fa-spin');
            setSaveStatus('Saving this sale to history...', '');
            closeCart();
            document.getElementById('receiptModal').style.display = 'flex';
            
            // Reset cart after checkout
            cart = [];
            updateCartCount();
            document.getElementById('customerName').value = '';
            if (document.getElementById('agentName')) document.getElementById('agentName').value = '';
            document.getElementById('amountTendered').value = '';
            saveSale(true);
        }

        function saveSale() {
            if (!window.currentReceipt) return;
            
            let data = window.currentReceipt;
            
            fetch('save_drugstore_sale.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('✅ Sale saved successfully!\nInvoice: ' + result.invoice_number);
                } else {
                    alert('❌ Error: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Failed to save sale. Please check console for details.');
            });
        }

        function printReceipt() {
            let printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <style>
                        body { font-family: monospace; padding: 20px; }
                        @media print {
                            body { margin: 0; padding: 10px; }
                            button { display: none; }
                        }
                    </style>
                </head>
                <body>
                    ${document.getElementById('receiptContent').innerHTML}
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
    </script>
    <script src="/pos-system/assets/js/drugstore-sidebar-active.js"></script>
</body>
</html>
