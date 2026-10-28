<?php
// views/products/history.php
$pageTitle = 'Product History';
$pageScripts = ['product-history.js'];

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Product.php';

requireAuth();
requireAccess('history');

$product = new Product();
$db = getDB();

// Get all products for dropdown
$allProducts = $product->getAll(['status' => 'active'], 1, 0);

// Detect optional columns
$phColumns = [];
try {
    $colsStmt = $db->query("SHOW COLUMNS FROM product_history");
    $phColumns = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_COLUMN, 0) : [];
} catch (Throwable $e) {
    $phColumns = [];
}
$hasOldData = in_array('old_data', $phColumns, true);
$hasNewData = in_array('new_data', $phColumns, true);

// Get filter parameters
$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;
$actionType = isset($_GET['action_type']) ? $_GET['action_type'] : '';
$searchTerm = isset($_GET['search']) ? trim($_GET['search']) : '';
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Build query
$query = "SELECT ph.*, 
                 p.name as product_name, 
                 p.product_code,
                 u.username as user_name,
                 u.full_name as user_fullname,
                 s.invoice_number as sale_invoice_number
          FROM product_history ph
          LEFT JOIN products p ON ph.product_id = p.id
          LEFT JOIN users u ON ph.user_id = u.id
          LEFT JOIN invoices s ON (ph.reference_type = 'sale' AND ph.reference_id = s.id)
          WHERE 1=1";

$countQuery = "SELECT COUNT(*) as total
               FROM product_history ph
               LEFT JOIN products p ON ph.product_id = p.id
               LEFT JOIN users u ON ph.user_id = u.id
               LEFT JOIN invoices s ON (ph.reference_type = 'sale' AND ph.reference_id = s.id)
               WHERE 1=1";
$params = [];

if ($productId) {
    $query .= " AND ph.product_id = :product_id";
    $countQuery .= " AND ph.product_id = :product_id";
    $params[':product_id'] = $productId;
}

if ($actionType) {
    $query .= " AND ph.action_type = :action_type";
    $countQuery .= " AND ph.action_type = :action_type";
    $params[':action_type'] = $actionType;
}

if ($searchTerm !== '') {
    $searchClauses = [
        "p.name LIKE :search",
        "p.product_code LIKE :search",
        "COALESCE(s.invoice_number, '') LIKE :search",
        "COALESCE(ph.notes, '') LIKE :search",
    ];
    if ($hasOldData) {
        $searchClauses[] = "COALESCE(ph.old_data, '') LIKE :search";
    }
    if ($hasNewData) {
        $searchClauses[] = "COALESCE(ph.new_data, '') LIKE :search";
    }
    $searchClauses[] = "COALESCE(ph.reference_type, '') LIKE :search";
    $searchClauses[] = "COALESCE(ph.action_type, '') LIKE :search";

    $query .= " AND (" . implode(" OR ", $searchClauses) . ")";
    $countQuery .= " AND (" . implode(" OR ", $searchClauses) . ")";
    $params[':search'] = '%' . $searchTerm . '%';
}

if ($startDate) {
    $query .= " AND DATE(ph.created_at) >= :start_date";
    $countQuery .= " AND DATE(ph.created_at) >= :start_date";
    $params[':start_date'] = $startDate;
}

if ($endDate) {
    $query .= " AND DATE(ph.created_at) <= :end_date";
    $countQuery .= " AND DATE(ph.created_at) <= :end_date";
    $params[':end_date'] = $endDate;
}

$query .= " ORDER BY ph.created_at DESC LIMIT :limit OFFSET :offset";

// Get total count
$countStmt = $db->prepare($countQuery);
foreach ($params as $key => $value) {
    if ($key !== ':limit' && $key !== ':offset') {
        $countStmt->bindValue($key, $value);
    }
}
$countStmt->execute();
$totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = ceil($totalRecords / $limit);

// Get history records
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$statsQuery = "SELECT 
                COUNT(*) as total_actions,
                COUNT(DISTINCT product_id) as products_affected,
                SUM(CASE WHEN action_type = 'stock_in' THEN 1 ELSE 0 END) as stock_ins,
                SUM(CASE WHEN action_type = 'stock_out' THEN 1 ELSE 0 END) as stock_outs,
                SUM(CASE WHEN action_type = 'update' THEN 1 ELSE 0 END) as updates,
                SUM(CASE WHEN action_type = 'create' THEN 1 ELSE 0 END) as creations,
                SUM(CASE WHEN action_type = 'delete' THEN 1 ELSE 0 END) as deletions
               FROM product_history
               WHERE DATE(created_at) BETWEEN :start_date AND :end_date";

$statsStmt = $db->prepare($statsQuery);
$statsStmt->bindValue(':start_date', $startDate);
$statsStmt->bindValue(':end_date', $endDate);
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get selected product name
$selectedProductName = '';
if ($productId) {
    foreach ($allProducts as $p) {
        if ($p['id'] == $productId) {
            $selectedProductName = $p['name'];
            break;
        }
    }
}
?>

<?php include __DIR__ . '/../layouts/header.php'; ?>

<style>
.history-container {
    padding: 20px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    flex-wrap: wrap;
    gap: 15px;
}

.page-header h1 {
    margin: 0;
    color: #2b2d42;
}

.header-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 20px;
}

.stat-card.primary .stat-icon { background: rgba(78, 115, 223, 0.1); color: #4e73df; }
.stat-card.success .stat-icon { background: rgba(28, 200, 138, 0.1); color: #1cc88a; }
.stat-card.info .stat-icon { background: rgba(54, 185, 204, 0.1); color: #36b9cc; }
.stat-card.warning .stat-icon { background: rgba(246, 194, 62, 0.1); color: #f6c23e; }

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.stat-content {
    flex: 1;
}

.stat-label {
    display: block;
    color: #666;
    font-size: 14px;
    margin-bottom: 5px;
}

.stat-value {
    display: block;
    font-size: 28px;
    font-weight: bold;
    color: #2b2d42;
    line-height: 1.2;
}

.stat-period {
    display: block;
    color: #999;
    font-size: 12px;
    margin-top: 5px;
}

.filter-section {
    margin-bottom: 30px;
}

.filter-section.card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    overflow: hidden;
}

.card-header {
    padding: 15px 20px;
    border-bottom: 1px solid #e3e6f0;
    background: #f8f9fc;
}

.card-header h3 {
    margin: 0;
    font-size: 18px;
    color: #4e73df;
}

.card-body {
    padding: 20px;
}

.filter-form {
    width: 100%;
}

.filter-row {
    display: grid;
    grid-template-columns: 1.5fr 1.5fr 1fr 1fr 1fr;
    gap: 15px;
    align-items: flex-end;
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-group label {
    margin-bottom: 5px;
    color: #666;
    font-weight: 500;
    font-size: 13px;
}

.form-control {
    padding: 8px 12px;
    border: 1px solid #d1d3e2;
    border-radius: 6px;
    font-size: 14px;
}

.filter-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary {
    background: #4e73df;
    color: white;
}

.btn-primary:hover {
    background: #2e59d9;
}

.btn-secondary {
    background: #858796;
    color: white;
}

.btn-secondary:hover {
    background: #6b6d7a;
}

.history-table-section {
    margin-bottom: 30px;
}

.table-responsive {
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th {
    text-align: left;
    padding: 15px;
    background: #f8f9fc;
    color: #4e73df;
    font-weight: 600;
    border-bottom: 2px solid #e3e6f0;
}

.table td {
    padding: 15px;
    border-bottom: 1px solid #e3e6f0;
    vertical-align: top;
}

.datetime-cell {
    white-space: nowrap;
}

.datetime-cell .date {
    display: block;
    font-weight: 500;
}

.datetime-cell .time {
    display: block;
    font-size: 12px;
    color: #666;
}

.product-cell {
    display: flex;
    flex-direction: column;
}

.product-cell strong {
    font-weight: 600;
}

.product-cell small {
    color: #666;
    font-size: 12px;
}

.badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 10px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 500;
}

.badge-success { background: #d4edda; color: #155724; }
.badge-info { background: #d1ecf1; color: #0c5460; }
.badge-warning { background: #fff3cd; color: #856404; }
.badge-danger { background: #f8d7da; color: #721c24; }
.badge-primary { background: #cce5ff; color: #004085; }
.badge-secondary { background: #e2e3e5; color: #383d41; }

.changes-cell {
    max-width: 300px;
}

.stock-change,
.price-change,
.update-change {
    font-size: 13px;
    padding: 3px 0;
}

.change-label {
    color: #666;
    margin-right: 5px;
}

.old-value {
    color: #999;
    text-decoration: line-through;
    margin: 0 5px;
}

.new-value {
    font-weight: 500;
    margin-left: 5px;
}

.new-value.text-success { color: #1cc88a; }
.new-value.text-danger { color: #e74a3b; }

.user-info {
    display: flex;
    flex-direction: column;
}

.user-name {
    font-weight: 500;
}

.user-role {
    font-size: 11px;
    color: #666;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
}

.empty-icon {
    font-size: 64px;
    color: #ddd;
    margin-bottom: 20px;
}

.empty-state h3 {
    color: #666;
    margin-bottom: 10px;
}

.empty-state p {
    color: #999;
}

.pagination-wrapper {
    margin-top: 20px;
    display: flex;
    justify-content: center;
}

.pagination {
    display: flex;
    gap: 5px;
    list-style: none;
    padding: 0;
    margin: 0;
}

.page-item .page-link {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 5px;
    color: #4e73df;
    text-decoration: none;
    transition: all 0.3s;
}

.page-item.active .page-link {
    background: #4e73df;
    color: white;
    border-color: #4e73df;
}

.page-item .page-link:hover {
    background: #f8f9fc;
}

.record-count {
    font-size: 13px;
    color: #666;
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
}

.modal-content {
    background: white;
    margin: 50px auto;
    max-width: 600px;
    border-radius: 10px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.2);
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid #e3e6f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    color: #2b2d42;
}

.close-btn {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: #999;
}

.modal-body {
    padding: 20px;
    max-height: 400px;
    overflow-y: auto;
}

.modal-footer {
    padding: 20px;
    border-top: 1px solid #e3e6f0;
    text-align: right;
}

.detail-section {
    margin-bottom: 20px;
}

.detail-section h4 {
    margin: 0 0 10px 0;
    color: #4e73df;
    font-size: 16px;
}

.detail-table {
    width: 100%;
}

.detail-table td, .detail-table th {
    padding: 8px;
    text-align: left;
    vertical-align: top;
}

.detail-table th {
    width: 100px;
    color: #666;
    font-weight: 500;
}

@media (max-width: 1200px) {
    .filter-row {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
}
</style>

<div class="history-container">
    <!-- Header -->
    <div class="page-header">
        <h1><i class="fas fa-history"></i> Product History</h1>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="exportHistory()">
                <i class="fas fa-download"></i> Export Report
            </button>
            <a href="/pos-system/views/products/list.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Products
            </a>
            <a href="/pos-system/views/dashboard/index.php" class="btn btn-secondary">
                <i class="fas fa-home"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-icon"><i class="fas fa-clock"></i></div>
            <div class="stat-content">
                <span class="stat-label">Total Actions</span>
                <span class="stat-value"><?php echo number_format($stats['total_actions'] ?? 0); ?></span>
                <span class="stat-period">Selected Period</span>
            </div>
        </div>
        <div class="stat-card success">
            <div class="stat-icon"><i class="fas fa-boxes"></i></div>
            <div class="stat-content">
                <span class="stat-label">Products Affected</span>
                <span class="stat-value"><?php echo number_format($stats['products_affected'] ?? 0); ?></span>
                <span class="stat-period">Unique products</span>
            </div>
        </div>
        <div class="stat-card info">
            <div class="stat-icon"><i class="fas fa-arrow-up"></i></div>
            <div class="stat-content">
                <span class="stat-label">Stock Ins</span>
                <span class="stat-value"><?php echo number_format($stats['stock_ins'] ?? 0); ?></span>
                <span class="stat-period">+ movements</span>
            </div>
        </div>
        <div class="stat-card warning">
            <div class="stat-icon"><i class="fas fa-arrow-down"></i></div>
            <div class="stat-content">
                <span class="stat-label">Stock Outs</span>
                <span class="stat-value"><?php echo number_format($stats['stock_outs'] ?? 0); ?></span>
                <span class="stat-period">- movements</span>
            </div>
        </div>
    </div>

    <!-- Search Section with Product Selector -->
    <div class="filter-section card">
        <div class="card-header">
            <h3><i class="fas fa-search"></i> Search History</h3>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-form">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="product_id">Select Product</label>
                        <select name="product_id" id="product_id" class="form-control">
                            <option value="">-- All Products --</option>
                            <?php foreach ($allProducts as $p): ?>
                            <option value="<?php echo $p['id']; ?>" <?php echo ($productId == $p['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($p['name']); ?> (<?php echo htmlspecialchars($p['product_code']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="action_type">Action Type</label>
                        <select name="action_type" id="action_type" class="form-control">
                            <option value="">All Actions</option>
                            <option value="create" <?php echo $actionType == 'create' ? 'selected' : ''; ?>>Create</option>
                            <option value="update" <?php echo $actionType == 'update' ? 'selected' : ''; ?>>Update</option>
                            <option value="delete" <?php echo $actionType == 'delete' ? 'selected' : ''; ?>>Delete</option>
                            <option value="stock_in" <?php echo $actionType == 'stock_in' ? 'selected' : ''; ?>>Stock In</option>
                            <option value="stock_out" <?php echo $actionType == 'stock_out' ? 'selected' : ''; ?>>Stock Out</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" name="start_date" id="start_date" class="form-control" value="<?php echo $startDate; ?>">
                    </div>

                    <div class="filter-group">
                        <label for="end_date">End Date</label>
                        <input type="date" name="end_date" id="end_date" class="form-control" value="<?php echo $endDate; ?>">
                    </div>

                    <div class="filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Search
                        </button>
                        <a href="/pos-system/views/products/history.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </div>

                <div class="filter-row" style="margin-top: 15px;">
                    <div class="filter-group filter-search-group" style="grid-column: 1 / -1;">
                        <label for="search">Or Search Keyword</label>
                        <input type="text" name="search" id="search" class="form-control" 
                               value="<?php echo htmlspecialchars($searchTerm); ?>"
                               placeholder="Search product name, code, invoice #, notes...">
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Selected Product Info (if a product is selected) -->
    <?php if ($productId && $selectedProductName): ?>
    <div class="filter-section card" style="background: #eef2ff; border-left: 4px solid #4e73df;">
        <div class="card-body" style="padding: 12px 20px;">
            <i class="fas fa-box"></i> 
            <strong>Showing history for:</strong> <?php echo htmlspecialchars($selectedProductName); ?>
            <a href="/pos-system/views/products/history.php" style="float: right; color: #4e73df;">Clear filter</a>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- History Table -->
    <div class="history-table-section card">
        <div class="card-header">
            <h3><i class="fas fa-list"></i> History Records</h3>
            <span class="record-count">Showing <?php echo count($history); ?> of <?php echo number_format($totalRecords); ?> records</span>
        </div>
        <div class="card-body">
            <?php if (empty($history)): ?>
                <div class="empty-state">
                    <i class="fas fa-history empty-icon"></i>
                    <h3>No History Found</h3>
                    <p>No product history records match your search.</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table history-table">
                        <thead>
                            <tr>
                                <th>Date/Time</th>
                                <th>Product</th>
                                <th>Action</th>
                                <th>Changes</th>
                                <th>User</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history as $record): ?>
                            <tr>
                                <td class="datetime-cell">
                                    <span class="date"><?php echo date('M d, Y', strtotime($record['created_at'])); ?></span>
                                    <span class="time"><?php echo date('h:i A', strtotime($record['created_at'])); ?></span>
                                </td>
                                <td class="product-cell">
                                    <strong><?php echo htmlspecialchars($record['product_name']); ?></strong>
                                    <small><?php echo htmlspecialchars($record['product_code']); ?></small>
                                </td>
                                <td class="action-cell">
                                    <?php
                                    $actionClass = '';
                                    $actionIcon = '';
                                    switch ($record['action_type']) {
                                        case 'create':
                                            $actionClass = 'badge-success';
                                            $actionIcon = 'fa-plus-circle';
                                            $actionLabel = 'Created';
                                            break;
                                        case 'update':
                                            $actionClass = 'badge-info';
                                            $actionIcon = 'fa-edit';
                                            $actionLabel = 'Updated';
                                            break;
                                        case 'delete':
                                            $actionClass = 'badge-danger';
                                            $actionIcon = 'fa-trash';
                                            $actionLabel = 'Deleted';
                                            break;
                                        case 'stock_in':
                                            $actionClass = 'badge-success';
                                            $actionIcon = 'fa-arrow-up';
                                            $actionLabel = 'Stock In';
                                            break;
                                        case 'stock_out':
                                            $actionClass = 'badge-warning';
                                            $actionIcon = 'fa-arrow-down';
                                            $actionLabel = 'Stock Out';
                                            break;
                                        default:
                                            $actionClass = 'badge-secondary';
                                            $actionIcon = 'fa-circle';
                                            $actionLabel = ucfirst($record['action_type']);
                                    }
                                    ?>
                                    <span class="badge <?php echo $actionClass; ?>">
                                        <i class="fas <?php echo $actionIcon; ?>"></i> <?php echo $actionLabel; ?>
                                    </span>
                                    <?php
                                    $referenceType = $record['reference_type'] ?? null;
                                    $referenceId = isset($record['reference_id']) ? (int) $record['reference_id'] : 0;
                                    $referenceLabel = '';
                                    if ($referenceType) {
                                        if ($referenceType === 'sale') {
                                            $invoiceNumber = trim((string) ($record['sale_invoice_number'] ?? ''));
                                            $referenceLabel = $invoiceNumber !== ''
                                                ? "Invoice: {$invoiceNumber}"
                                                : ($referenceId > 0 ? "Sale #{$referenceId}" : "Sale");
                                        } elseif ($referenceType === 'return') {
                                            $referenceLabel = $referenceId > 0 ? "Return #{$referenceId}" : 'Return';
                                        } elseif ($referenceType === 'manual') {
                                            $referenceLabel = 'Manual';
                                        } else {
                                            $referenceLabel = ucfirst((string) $referenceType);
                                        }
                                    }
                                    ?>
                                    <?php if ($referenceLabel !== ''): ?>
                                        <div style="margin-top:4px; font-size:12px; color:#64748b;">
                                            <?php echo htmlspecialchars($referenceLabel); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="changes-cell">
                                    <?php
                                    if ($record['action_type'] == 'stock_in' || $record['action_type'] == 'stock_out'):
                                        $quantity = $record['new_quantity'];
                                        $oldQuantity = $record['previous_quantity'];
                                        $change = $record['quantity_change'];
                                    ?>
                                        <div class="stock-change">
                                            <span class="change-label">Quantity:</span>
                                            <span class="old-value"><?php echo $oldQuantity; ?></span>
                                            <i class="fas fa-arrow-right"></i>
                                            <span class="new-value <?php echo $change > 0 ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo $quantity; ?>
                                                (<?php echo $change > 0 ? '+' : ''; ?><?php echo $change; ?>)
                                            </span>
                                        </div>
                                    <?php elseif ($record['action_type'] == 'update'): ?>
                                        <div class="update-change">
                                            <span class="change-label">Updated:</span>
                                            <?php if (!empty($record['notes'])): ?>
                                                <span class="text-muted"><?php echo htmlspecialchars($record['notes']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Qty: <?php echo $record['previous_quantity']; ?> → <?php echo $record['new_quantity']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif ($record['action_type'] == 'create'): ?>
                                        <div class="update-change">
                                            <span class="change-label">Created:</span>
                                            <span class="text-muted">Initial qty: <?php echo $record['new_quantity']; ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="user-cell">
                                    <div class="user-info">
                                        <span class="user-name"><?php echo htmlspecialchars($record['user_fullname'] ?: $record['user_name'] ?: 'System'); ?></span>
                                    </div>
                                </td>
                                <td class="details-cell">
                                    <button class="btn btn-sm btn-outline-info" onclick="viewDetails(<?php echo htmlspecialchars(json_encode($record)); ?>)">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="pagination-wrapper">
                    <ul class="pagination">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div id="detailsModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Record Details</h3>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody"></div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal()">Close</button>
        </div>
    </div>
</div>

<script>
function viewDetails(record) {
    const modal = document.getElementById('detailsModal');
    const modalBody = document.getElementById('modalBody');
    
    let html = `
        <div class="detail-section">
            <h4>Record Information</h4>
            <table class="detail-table">
                <tr><th>Date/Time:</th><td>${new Date(record.created_at).toLocaleString()}</td></tr>
                <tr><th>Product:</th><td>${record.product_name} (${record.product_code})</td></tr>
                <tr><th>Action Type:</th><td><span class="badge badge-${record.action_type}">${record.action_type}</span></td></tr>
                <tr><th>User:</th><td>${record.user_fullname || record.user_name || 'System'}</td></tr>
            </table>
        </div>
    `;
    
    if (record.action_type == 'stock_in' || record.action_type == 'stock_out') {
        html += `
            <div class="detail-section">
                <h4>Quantity Change</h4>
                <table class="detail-table">
                    <tr><th>Previous Quantity:</th><td>${record.previous_quantity}</td></tr>
                    <tr><th>New Quantity:</th><td class="${record.quantity_change > 0 ? 'text-success' : 'text-danger'}"><strong>${record.new_quantity}</strong></td></tr>
                    <tr><th>Change:</th><td>${record.quantity_change > 0 ? '+' : ''}${record.quantity_change}</td></tr>
                </table>
            </div>
        `;
    }
    
    if (record.sale_invoice_number) {
        html += `
            <div class="detail-section">
                <h4>Sale Information</h4>
                <table class="detail-table">
                    <tr><th>Invoice Number:</th><td>${record.sale_invoice_number}</td></tr>
                </table>
            </div>
        `;
    }
    
    if (record.notes) {
        html += `
            <div class="detail-section">
                <h4>Notes</h4>
                <p>${record.notes}</p>
            </div>
        `;
    }
    
    modalBody.innerHTML = html;
    modal.style.display = 'block';
}

function closeModal() {
    document.getElementById('detailsModal').style.display = 'none';
}

function exportHistory() {
    const params = new URLSearchParams(window.location.search);
    window.location.href = '/pos-system/api/export-history.php?' + params.toString();
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('detailsModal');
    if (event.target == modal) {
        modal.style.display = 'none';
    }
}
</script>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
