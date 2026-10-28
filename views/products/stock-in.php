<?php
// views/products/stock-in.php
session_start();

// Include auth and check permissions
require_once __DIR__ . '/../../includes/auth.php';
requireAuth();
requireAccess('inventory_manage');

// Prevent managers from accessing dashboard - redirect to appropriate page
if (isset($_GET['redirect_to']) && $_GET['redirect_to'] === 'dashboard' && isManager()) {
    header('Location: /pos-system/views/products/stock-in.php');
    exit();
}

// Use central database connection
require_once __DIR__ . '/../../config/database.php';
$pdo = ConfigDatabase::getInstance()->getConnection();

// Handle AJAX request for adding stock (JSON input)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (isset($input['action']) && $input['action'] === 'add_stock_ajax') {
        header('Content-Type: application/json');
        $items = $input['items'] ?? [];
        $note = trim($input['note'] ?? '');
        $supplier = trim($input['supplier'] ?? '');
        $custom_txn = trim($input['transaction_id'] ?? '');
        $created_by = $_SESSION['username'] ?? 'System';
        $user_id = $_SESSION['user_id'] ?? null;

        if (empty($items)) {
            echo json_encode(['success' => false, 'error' => 'No items selected.']);
            exit;
        }

        $transaction_id = $custom_txn ?: ('TXN-' . date('Ymd') . '-' . strtoupper(uniqid()));

        $pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $product_id = intval($item['id']);
                $quantity = intval($item['quantity']);
                $cost = isset($item['cost']) && $item['cost'] !== '' ? floatval($item['cost']) : null;
                if ($quantity <= 0) continue;

                $currentQtyStmt = $pdo->prepare("SELECT quantity FROM products WHERE id = :id FOR UPDATE");
                $currentQtyStmt->execute([':id' => $product_id]);
                $previousQty = $currentQtyStmt->fetchColumn();
                if ($previousQty === false) {
                    throw new RuntimeException('Product not found: ' . $product_id);
                }

                // Update product quantity while the row is locked for this transaction.
                $stmt = $pdo->prepare("UPDATE products SET quantity = quantity + :qty WHERE id = :id");
                $stmt->execute([':qty' => $quantity, ':id' => $product_id]);

                // Insert into stock_additions
                $stmt2 = $pdo->prepare("INSERT INTO stock_additions (product_id, quantity_added, note, created_by, cost_per_unit, supplier, transaction_id) VALUES (:pid, :qty, :note, :by, :cost, :supplier, :txn)");
                $stmt2->execute([
                    ':pid' => $product_id,
                    ':qty' => $quantity,
                    ':note' => $note,
                    ':by' => $created_by,
                    ':cost' => $cost,
                    ':supplier' => $supplier ?: null,
                    ':txn' => $transaction_id
                ]);

                // Get the stock_additions ID for reference
                $stock_addition_id = $pdo->lastInsertId();

                $currentQty = (int) $previousQty + $quantity;

                // Insert into product_history - FIXED: using user_id instead of user_name
                $stmt3 = $pdo->prepare("
                    INSERT INTO product_history 
                    (product_id, action_type, quantity_change, previous_quantity, new_quantity, reference_type, reference_id, notes, user_id, created_at) 
                    VALUES (?, 'stock_in', ?, ?, ?, 'purchase', ?, ?, ?, NOW())
                ");
                $stmt3->execute([
                    $product_id,
                    $quantity,
                    $previousQty,
                    $currentQty,
                    $stock_addition_id,
                    $note ?: 'Stock added',
                    $user_id
                ]);
            }
            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'Stock added successfully! Transaction ID: ' . $transaction_id]);
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Handle AJAX request for transaction details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_transaction' && isset($_GET['txn'])) {
    header('Content-Type: application/json');
    $txn = $_GET['txn'];
    $stmt = $pdo->prepare("
        SELECT sa.*, p.product_code, p.name as product_name
        FROM stock_additions sa
        JOIN products p ON sa.product_id = p.id
        WHERE sa.transaction_id = :txn
        ORDER BY p.name
    ");
    $stmt->execute([':txn' => $txn]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($items);
    exit;
}

// Regular page load
$search = $_GET['search'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$stockFilter = $_GET['stock'] ?? '';

$sql = "SELECT p.*, c.name as category_name 
        FROM products p
        LEFT JOIN categories c ON p.category = c.name
        WHERE p.status = 'active' ";
$params = [];
if (!empty($search)) {
    $sql .= " AND (p.name LIKE :search OR p.product_code LIKE :search OR p.description LIKE :search)";
    $params[':search'] = "%$search%";
}
if (!empty($categoryFilter)) {
    $sql .= " AND p.category = :category";
    $params[':category'] = $categoryFilter;
}
if ($stockFilter === 'low') {
    $sql .= " AND p.quantity > 0 AND p.quantity < 10";
} elseif ($stockFilter === 'out') {
    $sql .= " AND p.quantity = 0";
}
$sql .= " ORDER BY p.name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("SELECT name FROM categories WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

$totalProducts = count($products);
$totalStockValue = 0;
$totalItems = 0;
foreach ($products as $p) {
    $totalStockValue += $p['price'] * $p['quantity'];
    $totalItems += $p['quantity'];
}

$allProducts = $pdo->query("SELECT id, product_code, name, quantity FROM products WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch transaction summary for history with pagination
// History search/filter
// History search/filter
$historySearch = trim($_GET['history_search'] ?? '');

// Date range filter: default to current month when not provided
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
if ($dateFrom === '' && $dateTo === '') {
    $dateFrom = date('Y-m-01');
    $dateTo = date('Y-m-t');
}

// Pagination setup
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50; // transactions per page
$offset = ($page - 1) * $perPage;

// Build base WHERE clause
// Build base WHERE clause with date range and optional search
$whereSql = "WHERE sa.transaction_id IS NOT NULL AND DATE(sa.created_at) BETWEEN :df AND :dt";
if ($historySearch !== '') {
    $whereSql .= " AND (sa.supplier LIKE :hs OR p.name LIKE :hs)";
}

// Count total distinct transactions for pagination
$countSql = "SELECT COUNT(DISTINCT sa.transaction_id) FROM stock_additions sa JOIN products p ON sa.product_id = p.id " . $whereSql;
$countStmt = $pdo->prepare($countSql);
$binds = [':df' => $dateFrom, ':dt' => $dateTo];
if ($historySearch !== '') {
    $like = "%" . $historySearch . "%";
    $binds[':hs'] = $like;
}
$countStmt->execute($binds);
$totalTransactions = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalTransactions / $perPage));

// Build transaction summary query. Aggregate product names and total quantity.
$txnSql = "
    SELECT
        sa.transaction_id,
        COALESCE(MAX(sa.supplier), '') AS supplier,
        MIN(sa.created_at) AS created_at,
        GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS product_names,
        SUM(sa.quantity_added) AS total_quantity
    FROM stock_additions sa
    JOIN products p ON sa.product_id = p.id
    " . $whereSql . "
    GROUP BY sa.transaction_id
    ORDER BY created_at DESC
    LIMIT :limit OFFSET :offset
";

$txnStmt = $pdo->prepare($txnSql);
$txnStmt->bindValue(':df', $dateFrom, PDO::PARAM_STR);
$txnStmt->bindValue(':dt', $dateTo, PDO::PARAM_STR);
if ($historySearch !== '') {
    $txnStmt->bindValue(':hs', '%' . $historySearch . '%', PDO::PARAM_STR);
}
$txnStmt->bindValue(':limit', (int) $perPage, PDO::PARAM_INT);
$txnStmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);
$txnStmt->execute();

$transactions = $txnStmt->fetchAll(PDO::FETCH_ASSOC);

$activeTab = $_GET['tab'] ?? 'inventory';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Stock Management - Inventory System</title>
    <style>
        * { box-sizing: border-box; font-family: Arial, sans-serif; }
        body { background: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 1400px; margin: auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 20px; }
        
        /* Header with back button */
        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e9ecef;
        }
        
        .header-section h1 {
            margin: 0;
            color: #333;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .back-btn:hover {
            background: #5a6268;
            transform: translateX(-5px);
        }
        
        .summary { display: flex; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }
        .summary-card { background: #e9f7fe; border-left: 4px solid #2196F3; padding: 15px; border-radius: 4px; flex: 1 1 200px; }
        .summary-card h3 { margin: 0 0 5px; font-size: 14px; color: #555; }
        .summary-card .value { font-size: 24px; font-weight: bold; color: #2196F3; }
        
        .tabs { display: flex; gap: 5px; margin-bottom: 20px; border-bottom: 2px solid #ddd; }
        .tab { padding: 10px 20px; cursor: pointer; background: #f8f8f8; border: 1px solid #ddd; border-bottom: none; border-radius: 5px 5px 0 0; text-decoration: none; color: #333; }
        .tab.active { background: white; border-bottom: 2px solid white; margin-bottom: -2px; font-weight: bold; color: #2196F3; }
        .tab-content { display: none; padding: 20px 0; }
        .tab-content.active { display: block; }
        .message { background: #d4edda; color: #155724; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .error { background: #f8d7da; color: #721c24; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .filters { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
        .filters input, .filters select { padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .filters button { padding: 8px 16px; background: #2196F3; color: white; border: none; border-radius: 4px; cursor: pointer; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f2f2f2; }
        tr:hover { background: #f9f9f9; cursor: pointer; }
        .low-stock { background-color: #fff3cd; }
        .out-of-stock { background-color: #f8d7da; font-weight: bold; }
        .price { color: #28a745; font-weight: 600; }
        .status-badge { padding: 3px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .status-active { background: #d4edda; color: #155724; }
        .status-inactive { background: #f8d7da; color: #721c24; }
        .footer { margin-top: 30px; text-align: center; color: #777; font-size: 13px; }

        /* Two-panel layout with resizable divider */
        .add-stock-panels { display: flex; height: 600px; border: 1px solid #ddd; border-radius: 4px; overflow: hidden; }
        .left-panel { flex: 0 0 50%; overflow: auto; padding: 10px; background: #f9f9f9; }
        .right-panel { flex: 1 1 0; overflow: auto; padding: 10px; background: #fff; }
        .divider { width: 5px; background: #ccc; cursor: col-resize; user-select: none; }
        .divider:hover { background: #999; }
        .product-list { max-height: none; overflow-y: auto; }
        .selected-items { max-height: 400px; overflow-y: auto; margin-bottom: 20px; }
        .product-item { padding: 8px; border-bottom: 1px solid #eee; cursor: pointer; }
        .product-item:hover { background: #f0f0f0; }
        .selected-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; padding: 8px; background: #f9f9f9; border-radius: 4px; }
        .selected-row span { flex: 2; }
        .selected-row input { flex: 1; padding: 5px; border: 1px solid #ccc; border-radius: 4px; }
        .selected-row button { background: #dc3545; color: white; border: none; border-radius: 4px; padding: 5px 10px; cursor: pointer; }
        .selected-row button:hover { background: #c82333; }
        .global-note, .supplier-input, .txn-input { width: 100%; padding: 8px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; }
        .btn-add-stock { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; }
        .btn-add-stock:hover { background: #218838; }
        .search-box { width: 100%; padding: 8px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 4px; }

        /* Modal styles */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); }
        .modal-content { background-color: #fefefe; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 800px; border-radius: 8px; }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: black; }
        #modal-body table { width: 100%; border-collapse: collapse; }
        #modal-body th { background: #f2f2f2; }
        
        /* Notification for POS update */
        .pos-notification {
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .pos-notification i {
            color: #2196F3;
            font-size: 20px;
        }
        
        .pos-notification span {
            color: #0d47a1;
            font-weight: 500;
        }
    </style>
</head>
<body>
<div class="container">
  <!-- Header with Back Button - Only visible to Admin -->
<div class="header-section">
    <h1>📦 Stock Management</h1>
    <?php if (isAdmin()): ?>
        <a href="/pos-system/views/dashboard/index.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    <?php endif; ?>
</div>

    <!-- POS Update Notification -->
    <div class="pos-notification">
        <i class="fas fa-sync-alt"></i>
        <span>Products added here will automatically appear in the Point of Sale (POS) page.</span>
    </div>

    <!-- Summary Cards -->
    <div class="summary">
        <div class="summary-card"><h3>Total Products</h3><div class="value"><?= $totalProducts ?></div></div>
        <div class="summary-card"><h3>Total Items in Stock</h3><div class="value"><?= number_format($totalItems) ?></div></div>
        <div class="summary-card"><h3>Inventory Value</h3><div class="value">₱<?= number_format($totalStockValue, 2) ?></div></div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <a href="?tab=inventory&<?= http_build_query(array_filter(['search'=>$search, 'category'=>$categoryFilter, 'stock'=>$stockFilter])) ?>" class="tab <?= $activeTab === 'inventory' ? 'active' : '' ?>">Inventory</a>
        <a href="?tab=add_stock" class="tab <?= $activeTab === 'add_stock' ? 'active' : '' ?>">Add Stock</a>
        <a href="?tab=history" class="tab <?= $activeTab === 'history' ? 'active' : '' ?>">Stock History</a>
    </div>

    <!-- Dynamic message area (for AJAX) -->
    <div id="ajax-message"></div>

    <!-- Tab: Inventory -->
    <div id="inventory" class="tab-content <?= $activeTab === 'inventory' ? 'active' : '' ?>">
        <form method="get" class="filters">
            <input type="hidden" name="tab" value="inventory">
            <input type="text" name="search" placeholder="Search products..." value="<?= htmlspecialchars($search) ?>">
            <select name="category">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>><?= htmlspecialchars($cat) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="stock">
                <option value="">All Stock</option>
                <option value="low" <?= $stockFilter === 'low' ? 'selected' : '' ?>>Low Stock (<10)</option>
                <option value="out" <?= $stockFilter === 'out' ? 'selected' : '' ?>>Out of Stock</option>
            </select>
            <button type="submit">Apply</button>
            <a href="?tab=inventory" style="padding:8px 16px; background:#6c757d; color:white; text-decoration:none; border-radius:4px;">Reset</a>
        </form>
        <?php if (count($products) > 0): ?>
            <table>
                <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Price</th><th>Stock</th><th>Value</th><th>Status</th></tr></thead>
                <tbody>
                    <?php foreach ($products as $p): 
                        $rowClass = $p['quantity'] == 0 ? 'out-of-stock' : ($p['quantity'] < 10 ? 'low-stock' : '');
                        $stockValue = $p['price'] * $p['quantity'];
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td><?= htmlspecialchars($p['product_code']) ?></td>
                        <td><?= htmlspecialchars($p['name']) ?></td>
                        <td><?= htmlspecialchars($p['category_name'] ?: $p['category']) ?></td>
                        <td class="price">₱<?= number_format($p['price'], 2) ?></td>
                        <td><?= $p['quantity'] ?></td>
                        <td>₱<?= number_format($stockValue, 2) ?></td>
                        <td><span class="status-badge status-<?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align:center; padding:40px;">No products found.</p>
        <?php endif; ?>
    </div>

    <!-- Tab: Add Stock with resizable divider -->
    <div id="add_stock" class="tab-content <?= $activeTab === 'add_stock' ? 'active' : '' ?>">
        <div class="add-stock-panels" id="addStockPanels">
            <!-- Left panel -->
            <div class="left-panel" id="leftPanel">
                <h3>Select Products</h3>
                <input type="text" id="product-search" class="search-box" placeholder="Search by code or name...">
                <div class="product-list" id="product-list"></div>
            </div>
            <!-- Resizable divider -->
            <div class="divider" id="divider"></div>
            <!-- Right panel -->
            <div class="right-panel" id="rightPanel">
                <h3>Selected Items</h3>
                <div id="selected-items-container" class="selected-items"></div>
                <input type="text" id="transaction-id" class="txn-input" placeholder="Transaction ID (optional - e.g., receipt #)">
                <input type="text" id="supplier" class="supplier-input" placeholder="Supplier (optional)">
                <textarea id="stock-note" class="global-note" rows="2" placeholder="Note (optional)"></textarea>
                <button id="submit-stock" class="btn-add-stock">Add Stock</button>
            </div>
        </div>
    </div>

    <!-- Tab: Stock History (Summary) -->
    <div id="history" class="tab-content <?= $activeTab === 'history' ? 'active' : '' ?>">
        <h3>Stock Additions History</h3>
        <form method="get" class="filters" style="margin-bottom:12px;">
            <input type="hidden" name="tab" value="history">
            <input type="text" name="history_search" placeholder="Search by product or supplier..." value="<?= htmlspecialchars($historySearch) ?>" style="flex:1;">
            <label style="display:flex;align-items:center;gap:6px;">
                From: <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
            </label>
            <label style="display:flex;align-items:center;gap:6px;">
                To: <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
            </label>
            <button type="submit">Apply</button>
            <a href="?tab=history" style="padding:8px 12px; background:#6c757d; color:white; text-decoration:none; border-radius:4px; margin-left:8px;">Reset</a>
        </form>
        <?php if (count($transactions) > 0): ?>
            <table id="transaction-table">
                <thead><tr><th>Date</th><th>Transaction ID</th><th>Supplier</th><th>Products</th><th>Total Qty</th></tr></thead>
                <tbody>
                    <?php foreach ($transactions as $t): ?>
                    <tr data-txn="<?= htmlspecialchars($t['transaction_id']) ?>" style="cursor:pointer;">
                        <td><?= date('F j Y', strtotime($t['created_at'])) ?></td>
                        <td><?= htmlspecialchars($t['transaction_id']) ?></td>
                        <td><?= htmlspecialchars($t['supplier'] ?: '-') ?></td>
                        <td style="max-width:360px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= htmlspecialchars($t['product_names'] ?: '-') ?></td>
                        <td><?= $t['total_quantity'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination controls -->
            <?php if ($totalPages > 1): ?>
                <div style="display:flex;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap;">
                    <?php
                        $baseQuery = array_filter(['tab' => 'history', 'history_search' => $historySearch, 'date_from' => $dateFrom, 'date_to' => $dateTo]);
                    ?>
                    <a href="?<?= http_build_query(array_merge($baseQuery, ['page' => max(1, $page - 1)])) ?>" style="padding:6px 10px;background:#6c757d;color:white;text-decoration:none;border-radius:4px;<?= $page <= 1 ? 'opacity:0.6;pointer-events:none;' : '' ?>">&laquo; Prev</a>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <a href="?<?= http_build_query(array_merge($baseQuery, ['page' => $i])) ?>" style="padding:6px 10px;text-decoration:none;border-radius:4px;<?= $i === $page ? 'background:#2196F3;color:white;' : 'background:#f0f0f0;color:#333;' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <a href="?<?= http_build_query(array_merge($baseQuery, ['page' => min($totalPages, $page + 1)])) ?>" style="padding:6px 10px;background:#6c757d;color:white;text-decoration:none;border-radius:4px;<?= $page >= $totalPages ? 'opacity:0.6;pointer-events:none;' : '' ?>">Next &raquo;</a>
                    <span style="margin-left:8px;color:#555;">Page <?= $page ?> of <?= $totalPages ?></span>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p>No stock additions yet.</p>
        <?php endif; ?>
    </div>

    <div class="footer">&copy; <?= date('Y') ?> Inventory System</div>
</div>

<!-- Modal for transaction details -->
<div id="txnModal" class="modal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h3 id="modal-title">Transaction Details</h3>
        <div id="modal-body">Loading...</div>
    </div>
</div>

<!-- Font Awesome for icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<script>
    // All active products for Add Stock
    const allProducts = <?= json_encode($allProducts) ?>;
    let selectedItems = [];

    // Render left product list
    function renderProductList(filterText = '') {
        const listDiv = document.getElementById('product-list');
        const filter = filterText.toLowerCase();
        const filtered = allProducts.filter(p => 
            p.product_code.toLowerCase().includes(filter) || 
            p.name.toLowerCase().includes(filter)
        );
        listDiv.innerHTML = '';
        filtered.forEach(p => {
            const div = document.createElement('div');
            div.className = 'product-item';
            div.textContent = `${p.product_code} - ${p.name} (Stock: ${p.quantity})`;
            div.onclick = () => addProduct(p);
            listDiv.appendChild(div);
        });
        if (filtered.length === 0) {
            listDiv.innerHTML = '<div class="product-item">No products found</div>';
        }
    }

    function addProduct(product) {
        if (selectedItems.some(item => item.id === product.id)) {
            alert('Product already added.');
            return;
        }
        selectedItems.push({
            id: product.id,
            code: product.product_code,
            name: product.name,
            quantity: 1,
            cost: ''
        });
        renderSelectedItems();
    }

    function removeProduct(index) {
        selectedItems.splice(index, 1);
        renderSelectedItems();
    }

    function updateItem(index, field, value) {
        selectedItems[index][field] = field === 'quantity' ? parseInt(value) || 0 : value;
    }

    function renderSelectedItems() {
        const container = document.getElementById('selected-items-container');
        container.innerHTML = '';
        selectedItems.forEach((item, idx) => {
            const row = document.createElement('div');
            row.className = 'selected-row';
            row.innerHTML = `
                <span>${item.code} - ${item.name}</span>
                <input type="number" min="1" value="${item.quantity}" placeholder="Qty" data-index="${idx}" data-field="quantity">
                <input type="number" step="0.01" min="0" value="${item.cost}" placeholder="Cost" data-index="${idx}" data-field="cost">
                <button onclick="removeProduct(${idx})">✖</button>
            `;
            container.appendChild(row);
        });
        document.querySelectorAll('.selected-row input[data-field="quantity"]').forEach(inp => {
            inp.addEventListener('change', (e) => {
                const idx = e.target.dataset.index;
                updateItem(idx, 'quantity', e.target.value);
            });
        });
        document.querySelectorAll('.selected-row input[data-field="cost"]').forEach(inp => {
            inp.addEventListener('change', (e) => {
                const idx = e.target.dataset.index;
                updateItem(idx, 'cost', e.target.value);
            });
        });
    }

    document.getElementById('product-search').addEventListener('input', (e) => {
        renderProductList(e.target.value);
    });

    document.getElementById('submit-stock').addEventListener('click', () => {
        if (selectedItems.length === 0) {
            alert('No items selected.');
            return;
        }
        const items = selectedItems.map(item => ({
            id: item.id,
            quantity: item.quantity,
            cost: item.cost === '' ? null : parseFloat(item.cost)
        }));
        const note = document.getElementById('stock-note').value;
        const supplier = document.getElementById('supplier').value;
        const transaction_id = document.getElementById('transaction-id').value;

        fetch('?tab=add_stock', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'add_stock_ajax', items, note, supplier, transaction_id })
        })
        .then(res => res.json())
        .then(data => {
            const msgDiv = document.getElementById('ajax-message');
            if (data.success) {
                msgDiv.className = 'message';
                msgDiv.innerHTML = `<i class="fas fa-check-circle"></i> ${data.message}<br>
                                   <small>Products will now appear in the POS page.</small>`;
                selectedItems = [];
                renderSelectedItems();
                document.getElementById('stock-note').value = '';
                document.getElementById('supplier').value = '';
                document.getElementById('transaction-id').value = '';
                setTimeout(() => location.reload(), 2000);
            } else {
                msgDiv.className = 'error';
                msgDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${data.error}`;
            }
        })
        .catch(err => {
            document.getElementById('ajax-message').className = 'error';
            document.getElementById('ajax-message').innerHTML = `<i class="fas fa-exclamation-circle"></i> Request failed: ${err}`;
        });
    });

    renderProductList();

    // Resizable divider functionality
    const divider = document.getElementById('divider');
    const leftPanel = document.getElementById('leftPanel');
    const rightPanel = document.getElementById('rightPanel');
    const container = document.getElementById('addStockPanels');

    let isDragging = false;
    let startX, startWidth;

    divider.addEventListener('mousedown', (e) => {
        isDragging = true;
        startX = e.clientX;
        startWidth = leftPanel.offsetWidth;
        document.body.style.cursor = 'col-resize';
        e.preventDefault();
    });

    document.addEventListener('mousemove', (e) => {
        if (!isDragging) return;
        const dx = e.clientX - startX;
        const newWidth = startWidth + dx;
        const containerWidth = container.offsetWidth;
        const minWidth = 100; // minimum width in px for left panel
        if (newWidth > minWidth && newWidth < containerWidth - minWidth) {
            leftPanel.style.flex = `0 0 ${newWidth}px`;
        }
    });

    document.addEventListener('mouseup', () => {
        isDragging = false;
        document.body.style.cursor = 'default';
    });

    // Modal functionality for transaction details
    const modal = document.getElementById('txnModal');
    const span = document.getElementsByClassName('close')[0];
    const modalBody = document.getElementById('modal-body');
    const modalTitle = document.getElementById('modal-title');

    span.onclick = () => modal.style.display = 'none';
    window.onclick = (event) => { if (event.target == modal) modal.style.display = 'none'; };

    document.querySelectorAll('#transaction-table tbody tr').forEach(row => {
        row.addEventListener('click', () => {
            const txn = row.dataset.txn;
            modalTitle.textContent = `Transaction: ${txn}`;
            modalBody.innerHTML = 'Loading...';
            modal.style.display = 'block';

            fetch(`?ajax=get_transaction&txn=${encodeURIComponent(txn)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.length === 0) {
                        modalBody.innerHTML = '<p>No details found.</p>';
                        return;
                    }
                    let html = '<table><thead><tr><th>Product</th><th>Qty Added</th><th>Cost/Unit</th><th>Note</th></tr></thead><tbody>';
                    data.forEach(item => {
                        html += `<tr>
                            <td>${item.product_code} - ${item.product_name}</td>
                            <td>${item.quantity_added}</td>
                            <td>${item.cost_per_unit ? '₱' + parseFloat(item.cost_per_unit).toFixed(2) : '-'}</td>
                            <td>${item.note ? item.note : '-'}</td>
                        </tr>`;
                    });
                    html += '</tbody></table>';
                    modalBody.innerHTML = html;
                })
                .catch(err => {
                    modalBody.innerHTML = '<p>Error loading details.</p>';
                });
        });
    });
</script>
</body>
</html>