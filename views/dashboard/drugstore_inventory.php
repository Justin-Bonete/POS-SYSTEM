<?php
session_start();

// Security check
if (!isset($_SESSION['user_id']) || $_SESSION['selected_system'] !== 'drugstore') {
    header('Location: /pos-system/');
    exit;
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

ds_ensure_drugstore_batch_support($db);

// Helper: HTML escape
function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function ds_barcode_slug(string $genericName, string $brandName = ''): string {
    $source = trim($genericName . ' ' . $brandName);
    if ($source === '') {
        return 'ITEM';
    }

    $normalized = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $source) : $source;
    if ($normalized === false || $normalized === '') {
        $normalized = $source;
    }

    $barcode = strtoupper((string)$normalized);
    $barcode = preg_replace('/[^A-Z0-9]+/', '-', $barcode);
    $barcode = trim((string)$barcode, '-');

    return $barcode !== '' ? substr($barcode, 0, 72) : 'ITEM';
}

function ds_generate_unique_barcode(PDO $db, string $genericName, string $brandName = '', ?int $excludeId = null): string {
    $base = ds_barcode_slug($genericName, $brandName);
    $candidate = $base;
    $counter = 1;

    while (true) {
        if ($excludeId !== null) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM drugstore_inventory WHERE barcode = ? AND id <> ?");
            $stmt->execute([$candidate, $excludeId]);
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM drugstore_inventory WHERE barcode = ?");
            $stmt->execute([$candidate]);
        }

        if ((int)$stmt->fetchColumn() === 0) {
            return $candidate;
        }

        $suffix = '-' . str_pad((string)$counter, 3, '0', STR_PAD_LEFT);
        $candidate = substr($base, 0, max(1, 72 - strlen($suffix))) . $suffix;
        $counter++;
    }
}

// Check if reorder_level column exists in drugstore_inventory
function ds_has_column($db, $table, $column) {
    try {
        $result = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $result && $result->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function ds_table_exists(PDO $db, string $table): bool {
    try {
        $result = $db->query("SHOW TABLES LIKE '" . str_replace("'", "\\'", $table) . "'");
        return $result && $result->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function ds_ensure_drugstore_batch_support(PDO $db): void {
    if (!ds_table_exists($db, 'drugstore_batches')) {
        $db->exec("CREATE TABLE drugstore_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inventory_id INT NOT NULL,
            batch_number VARCHAR(50) NOT NULL,
            expiration_date DATE NOT NULL,
            quantity INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (inventory_id) REFERENCES drugstore_inventory(id) ON DELETE CASCADE
        )");
    }

    if (!ds_table_exists($db, 'drugstore_sale_batches')) {
        $db->exec("CREATE TABLE drugstore_sale_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sale_item_id INT NOT NULL,
            batch_id INT NOT NULL,
            quantity INT NOT NULL,
            FOREIGN KEY (sale_item_id) REFERENCES drugstore_sale_items(id) ON DELETE CASCADE,
            FOREIGN KEY (batch_id) REFERENCES drugstore_batches(id) ON DELETE CASCADE
        )");
    }

    $inventoryStmt = $db->query("SELECT id, quantity, expiry_date FROM drugstore_inventory");
    if ($inventoryStmt) {
        while ($inventory = $inventoryStmt->fetch(PDO::FETCH_ASSOC)) {
            $inventoryId = (int)($inventory['id'] ?? 0);
            if ($inventoryId <= 0) {
                continue;
            }

            $batchCheckStmt = $db->prepare("SELECT COUNT(*) FROM drugstore_batches WHERE inventory_id = ?");
            $batchCheckStmt->execute([$inventoryId]);
            if ((int)$batchCheckStmt->fetchColumn() === 0) {
                $quantity = max(0, (int)($inventory['quantity'] ?? 0));
                $expiryDate = !empty($inventory['expiry_date']) && $inventory['expiry_date'] !== '0000-00-00'
                    ? $inventory['expiry_date']
                    : date('Y-m-d', strtotime('+1 year'));

                $insertBatchStmt = $db->prepare("INSERT INTO drugstore_batches (inventory_id, batch_number, expiration_date, quantity) VALUES (?, ?, ?, ?)");
                $insertBatchStmt->execute([$inventoryId, 'AUTO-' . str_pad((string)$inventoryId, 4, '0', STR_PAD_LEFT), $expiryDate, $quantity]);
            }

            $totalStmt = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM drugstore_batches WHERE inventory_id = ?");
            $totalStmt->execute([$inventoryId]);
            $totalQuantity = (int)$totalStmt->fetchColumn();

            $syncStmt = $db->prepare("UPDATE drugstore_inventory SET quantity = ? WHERE id = ?");
            $syncStmt->execute([$totalQuantity, $inventoryId]);
        }
    }
}

function ds_sync_inventory_quantity_from_batches(PDO $db, int $inventoryId): void {
    $stmt = $db->prepare("SELECT COALESCE(SUM(quantity), 0) FROM drugstore_batches WHERE inventory_id = ?");
    $stmt->execute([$inventoryId]);
    $totalQuantity = (int)$stmt->fetchColumn();

    $updateStmt = $db->prepare("UPDATE drugstore_inventory SET quantity = ? WHERE id = ?");
    $updateStmt->execute([$totalQuantity, $inventoryId]);
}

function ds_ensure_batch_for_inventory(PDO $db, int $inventoryId, string $expiryDate, int $quantity): void {
    $existingBatchStmt = $db->prepare("SELECT COUNT(*) FROM drugstore_batches WHERE inventory_id = ?");
    $existingBatchStmt->execute([$inventoryId]);
    if ((int)$existingBatchStmt->fetchColumn() > 0) {
        ds_sync_inventory_quantity_from_batches($db, $inventoryId);
        return;
    }

    $insertBatchStmt = $db->prepare("INSERT INTO drugstore_batches (inventory_id, batch_number, expiration_date, quantity) VALUES (?, ?, ?, ?)");
    $insertBatchStmt->execute([
        $inventoryId,
        'AUTO-' . str_pad((string)$inventoryId, 4, '0', STR_PAD_LEFT),
        $expiryDate !== '' ? $expiryDate : date('Y-m-d', strtotime('+1 year')),
        max(0, $quantity)
    ]);

    ds_sync_inventory_quantity_from_batches($db, $inventoryId);
}

function ds_build_inventory_csv(PDO $db, string $whereClause = '', string $searchTerm = ''): string {
    $sql = "SELECT id, barcode, generic_name, brand_name, quantity, unit, expiry_date, capital_price, wholesale_price, store_price, reorder_level FROM drugstore_inventory";
    if ($whereClause !== '') {
        $sql .= ' ' . $whereClause;
    }
    $sql .= " ORDER BY brand_name ASC, generic_name ASC";

    $stmt = $db->prepare($sql);
    if ($searchTerm !== '') {
        $stmt->bindValue(':search', $searchTerm);
    }
    $stmt->execute();
    $items = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $rows = [];
    $rows[] = ['Barcode', 'Generic Name', 'Brand Name', 'Total Quantity', 'Unit', 'Expiry Date', 'Capital Price', 'Wholesale Price', 'Store Price', 'Reorder Level', 'Batch Number', 'Batch Expiration', 'Batch Quantity'];

    foreach ($items as $item) {
        $inventoryId = (int)($item['id'] ?? 0);
        $batchStmt = $db->prepare("SELECT batch_number, expiration_date, quantity FROM drugstore_batches WHERE inventory_id = ? ORDER BY expiration_date ASC, id ASC");
        $batchStmt->execute([$inventoryId]);
        $batches = $batchStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($batches)) {
            $rows[] = [
                $item['barcode'] ?? '',
                $item['generic_name'] ?? '',
                $item['brand_name'] ?? '',
                $item['quantity'] ?? 0,
                $item['unit'] ?? '',
                $item['expiry_date'] ?? '',
                $item['capital_price'] ?? 0,
                $item['wholesale_price'] ?? 0,
                $item['store_price'] ?? 0,
                $item['reorder_level'] ?? 0,
                '',
                '',
                ''
            ];
            continue;
        }

        foreach ($batches as $batch) {
            $rows[] = [
                $item['barcode'] ?? '',
                $item['generic_name'] ?? '',
                $item['brand_name'] ?? '',
                $item['quantity'] ?? 0,
                $item['unit'] ?? '',
                $item['expiry_date'] ?? '',
                $item['capital_price'] ?? 0,
                $item['wholesale_price'] ?? 0,
                $item['store_price'] ?? 0,
                $item['reorder_level'] ?? 0,
                $batch['batch_number'] ?? '',
                $batch['expiration_date'] ?? '',
                $batch['quantity'] ?? 0,
            ];
        }
    }

    $handle = fopen('php://temp', 'r+');
    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    return $csv === false ? '' : $csv;
}

// Generate pagination URL
function ds_page_url($targetPage) {
    $params = [
        'page' => $targetPage,
        'search' => $_GET['search'] ?? '',
        'filter' => $_GET['filter'] ?? 'all'
    ];
    return '?' . http_build_query(array_filter($params));
}

// Handle POST: Add item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    try {
        $barcode = trim((string)($_POST['barcode'] ?? ''));
        if ($barcode === '') {
            $barcode = ds_generate_unique_barcode(
                $db,
                (string)($_POST['generic_name'] ?? ''),
                (string)($_POST['brand_name'] ?? '')
            );
        }

        $sql = "INSERT INTO drugstore_inventory 
                (barcode, quantity, unit, generic_name, brand_name, expiry_date, 
                 capital_price, wholesale_price, store_price, reorder_level) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $barcode,
            $_POST['quantity'] ?? 0,
            $_POST['unit'] ?? '',
            $_POST['generic_name'] ?? '',
            $_POST['brand_name'] ?? '',
            !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null,
            $_POST['capital_price'] ?? 0,
            $_POST['wholesale_price'] ?? 0,
            $_POST['store_price'] ?? 0,
            $_POST['reorder_level'] ?? 5
        ]);
        $inventoryId = (int)$db->lastInsertId();
        $batchQuantity = max(0, (int)($_POST['quantity'] ?? 0));
        $batchExpiry = !empty($_POST['expiry_date']) ? (string)$_POST['expiry_date'] : date('Y-m-d', strtotime('+1 year'));
        ds_ensure_batch_for_inventory($db, $inventoryId, $batchExpiry, $batchQuantity);
        $_SESSION['message'] = 'Item added successfully!';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error adding item: ' . $e->getMessage();
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Handle POST: Add batch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_batch') {
    try {
        $inventoryId = (int)($_POST['inventory_id'] ?? 0);
        $batchNumber = trim((string)($_POST['batch_number'] ?? ''));
        $expirationDate = trim((string)($_POST['expiration_date'] ?? ''));
        $quantity = max(0, (int)($_POST['quantity'] ?? 0));

        if ($inventoryId <= 0 || $quantity <= 0 || $expirationDate === '') {
            throw new InvalidArgumentException('Please provide a valid batch number, expiration date, and quantity.');
        }

        if ($batchNumber === '') {
            $batchNumber = 'BATCH-' . str_pad((string)$inventoryId, 4, '0', STR_PAD_LEFT);
        }

        $stmt = $db->prepare("INSERT INTO drugstore_batches (inventory_id, batch_number, expiration_date, quantity) VALUES (?, ?, ?, ?)");
        $stmt->execute([$inventoryId, $batchNumber, $expirationDate, $quantity]);
        ds_sync_inventory_quantity_from_batches($db, $inventoryId);
        $_SESSION['message'] = 'Batch added successfully!';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error adding batch: ' . $e->getMessage();
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// Handle GET: Delete batch
if (isset($_GET['delete_batch'])) {
    try {
        $batchId = (int)($_GET['delete_batch'] ?? 0);
        if ($batchId > 0) {
            $batchStmt = $db->prepare("SELECT inventory_id FROM drugstore_batches WHERE id = ?");
            $batchStmt->execute([$batchId]);
            $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
            if ($batch) {
                $db->prepare("DELETE FROM drugstore_batches WHERE id = ?")->execute([$batchId]);
                ds_sync_inventory_quantity_from_batches($db, (int)($batch['inventory_id'] ?? 0));
                $_SESSION['message'] = 'Batch deleted successfully.';
            } else {
                $_SESSION['error'] = 'Batch not found.';
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error deleting batch: ' . $e->getMessage();
    }
    header('Location: ' . ds_page_url($_GET['page'] ?? 1));
    exit;
}

// Handle POST: Update item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    try {
        $itemId = (int)($_POST['id'] ?? 0);
        $barcode = trim((string)($_POST['barcode'] ?? ''));
        if ($barcode === '') {
            $barcode = ds_generate_unique_barcode(
                $db,
                (string)($_POST['generic_name'] ?? ''),
                (string)($_POST['brand_name'] ?? ''),
                $itemId
            );
        }

        $sql = "UPDATE drugstore_inventory SET 
                barcode = ?, quantity = ?, unit = ?, generic_name = ?, brand_name = ?,
                expiry_date = ?, capital_price = ?, wholesale_price = ?, store_price = ?,
                reorder_level = ? WHERE id = ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            $barcode,
            $_POST['quantity'] ?? 0,
            $_POST['unit'] ?? '',
            $_POST['generic_name'] ?? '',
            $_POST['brand_name'] ?? '',
            !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null,
            $_POST['capital_price'] ?? 0,
            $_POST['wholesale_price'] ?? 0,
            $_POST['store_price'] ?? 0,
            $_POST['reorder_level'] ?? 5,
            $itemId
        ]);
        $batchQuantity = max(0, (int)($_POST['quantity'] ?? 0));
        $batchExpiry = !empty($_POST['expiry_date']) ? (string)$_POST['expiry_date'] : date('Y-m-d', strtotime('+1 year'));
        ds_ensure_batch_for_inventory($db, $itemId, $batchExpiry, $batchQuantity);
        $_SESSION['message'] = 'Item updated successfully!';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error updating item: ' . $e->getMessage();
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

if (isset($_GET['download_inventory_csv'])) {
    $filter = $_GET['filter'] ?? 'all';
    $search = trim((string)($_GET['search'] ?? ''));
    $filterConditions = [];

    if ($filter !== 'all') {
        switch ($filter) {
            case 'expired':
                $filterConditions[] = "expiry_date < CURDATE() AND expiry_date IS NOT NULL AND expiry_date != '0000-00-00'";
                break;
            case 'near':
                $filterConditions[] = "expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date IS NOT NULL AND expiry_date != '0000-00-00'";
                break;
            case 'low':
                $filterConditions[] = "(COALESCE(reorder_level, 5) > 0 AND quantity <= COALESCE(reorder_level, 5)) OR (quantity BETWEEN 1 AND 20)";
                break;
            case 'critical':
                $filterConditions[] = "quantity <= 10";
                break;
            case 'out':
                $filterConditions[] = "quantity <= 0";
                break;
        }
    }

    if ($search !== '') {
        $filterConditions[] = "(barcode LIKE :search OR generic_name LIKE :search OR brand_name LIKE :search)";
    }

    $whereClause = !empty($filterConditions) ? 'WHERE ' . implode(' AND ', $filterConditions) : '';
    $csv = ds_build_inventory_csv($db, $whereClause, $search !== '' ? "%$search%" : '');
    $filename = 'drugstore_inventory_batches_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $csv;
    exit;
}

// Handle GET: Delete item
if (isset($_GET['delete'])) {
    try {
        $stmt = $db->prepare("DELETE FROM drugstore_inventory WHERE id = ?");
        $stmt->execute([$_GET['delete']]);
        $_SESSION['message'] = 'Item deleted successfully!';
    } catch (Exception $e) {
        $_SESSION['error'] = 'Error deleting item: ' . $e->getMessage();
    }
    header('Location: ' . ds_page_url($_GET['page'] ?? 1));
    exit;
}

// Get filter and search parameters
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = (int)($_GET['page'] ?? 1);
$pageSize = 50;

// Build WHERE clause for filters
$filterConditions = [];

switch ($filter) {
    case 'expired':
        $filterConditions[] = "expiry_date < CURDATE() AND expiry_date IS NOT NULL AND expiry_date != '0000-00-00'";
        break;
    case 'near':
        $filterConditions[] = "expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date IS NOT NULL AND expiry_date != '0000-00-00'";
        break;
    case 'low':
        $filterConditions[] = "(COALESCE(reorder_level, 5) > 0 AND quantity <= COALESCE(reorder_level, 5)) OR (quantity BETWEEN 1 AND 20)";
        break;
    case 'critical':
        $filterConditions[] = "quantity <= 10";
        break;
    case 'out':
        $filterConditions[] = "quantity <= 0";
        break;
}

if (!empty($search)) {
    $filterConditions[] = "(barcode LIKE :search OR generic_name LIKE :search OR brand_name LIKE :search)";
}

$whereClause = !empty($filterConditions) ? 'WHERE ' . implode(' AND ', $filterConditions) : '';

// Count total and badge counts for drugstore_inventory
$countQueries = [
    'total' => "SELECT COUNT(*) as count FROM drugstore_inventory",
    'expired' => "SELECT COUNT(*) as count FROM drugstore_inventory WHERE expiry_date < CURDATE() AND expiry_date IS NOT NULL AND expiry_date != '0000-00-00'",
    'near' => "SELECT COUNT(*) as count FROM drugstore_inventory WHERE expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND expiry_date IS NOT NULL AND expiry_date != '0000-00-00'",
    'low' => "SELECT COUNT(*) as count FROM drugstore_inventory WHERE (COALESCE(reorder_level, 5) > 0 AND quantity <= COALESCE(reorder_level, 5)) OR (quantity BETWEEN 1 AND 20)",
    'critical' => "SELECT COUNT(*) as count FROM drugstore_inventory WHERE quantity <= 10",
    'out' => "SELECT COUNT(*) as count FROM drugstore_inventory WHERE quantity <= 0"
];

$badges = [];
foreach ($countQueries as $key => $query) {
    $result = $db->query($query);
    $badges[$key] = $result ? ($result->fetch(PDO::FETCH_ASSOC)['count'] ?? 0) : 0;
}

// Get items with pagination
$offset = ($page - 1) * $pageSize;
$sql = "SELECT * FROM drugstore_inventory $whereClause ORDER BY id DESC LIMIT $pageSize OFFSET $offset";

$stmt = $db->prepare($sql);
if (!empty($search)) {
    $searchTerm = "%$search%";
    $stmt->bindParam(':search', $searchTerm);
}
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count total items for pagination
$countSql = "SELECT COUNT(*) as total FROM drugstore_inventory $whereClause";
$countStmt = $db->prepare($countSql);
if (!empty($search)) {
    $countStmt->bindParam(':search', $searchTerm);
}
$countStmt->execute();
$totalItems = $countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
$totalPages = ceil($totalItems / $pageSize);

$hasReorderLevel = ds_has_column($db, 'drugstore_inventory', 'reorder_level');
$activePage = 'inventory';
ob_start();
?>
<button onclick="openAddModal()" class="btn btn-primary">
    <i class="fas fa-plus"></i>
    <span>Add Item</span>
</button>
<button onclick="printTable()" class="btn btn-secondary">
    <i class="fas fa-print"></i>
    <span>Print</span>
</button>
<a href="?download_inventory_csv=1" class="btn btn-secondary">
    <i class="fas fa-download"></i>
    <span>Download CSV</span>
</a>
<?php
$drugstoreHeaderActions = ob_get_clean();
$drugstoreHeaderIcon = 'fa-boxes';
$drugstoreHeaderTitle = 'Drugstore Inventory';
$drugstoreHeaderSubtitle = 'Manage your inventory items and stock levels.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drugstore Inventory</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/pos-system/assets/css/drugstore-dashboard-base.css">
    <link rel="stylesheet" href="/pos-system/assets/css/responsive.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: #f5f7fb;
            color: #1e293b;
        }

        /* Layout */
        .app {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: #ffffff;
            border-right: 1px solid #e9eef3;
            display: flex;
            flex-direction: column;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            margin-left: 280px;
            padding: 28px 32px;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }

        .greeting h1 {
            font-size: 28px;
            font-weight: 700;
            color: #0f172a;
        }

        .greeting p {
            font-size: 14px;
            color: #64748b;
            margin-top: 6px;
        }

        .user-menu {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            border: 1px solid #e9eef3;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #6366f1;
            color: white;
            border-color: #6366f1;
        }

        .btn-primary:hover {
            background: #5855eb;
            border-color: #5855eb;
        }

        .btn-secondary {
            background: white;
            color: #64748b;
        }

        .btn-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        /* Alerts */
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .alert-success {
            background: #ecfdf5;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Filter Badges */
        .filter-badges {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .badge {
            padding: 8px 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 30px;
            text-decoration: none;
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .badge:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        .badge.active {
            background: #6366f1;
            color: white;
            border-color: #6366f1;
        }

        /* Search Box */
        .search-box {
            margin-bottom: 24px;
            display: flex;
            gap: 8px;
        }

        .search-box input {
            flex: 1;
            padding: 10px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 40px;
            font-size: 14px;
            background: white;
        }

        .search-box input:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .search-box button {
            padding: 10px 20px;
            background: #6366f1;
            color: white;
            border: none;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .search-box button:hover {
            background: #5855eb;
        }

        /* Data Table */
        .data-table {
            background: white;
            border-radius: 20px;
            padding: 24px;
            border: 1px solid #e9eef3;
            margin-bottom: 32px;
            overflow-x: auto;
        }

        .inventory-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        .inventory-table th,
        .inventory-table td {
            padding: 12px 8px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
        }

        .inventory-table th {
            font-weight: 600;
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: white;
        }

        .inventory-table td {
            color: #1e293b;
        }

        .inventory-table tr:hover {
            background: #f8fafc;
        }

        .product-cell {
            font-weight: 500;
        }

        .low-stock {
            color: #eab308;
            font-weight: 600;
            text-shadow: 0 0 6px rgba(234, 179, 8, 0.35);
        }

        .expired {
            color: #ef4444;
            font-weight: 600;
        }

        .warning-expiry {
            color: #f59e0b;
            font-weight: 600;
        }

        .critical-expiry {
            color: #dc2626;
            font-weight: 600;
        }

        .out-stock {
            color: #dc2626;
            font-weight: 700;
            text-shadow: 0 0 8px rgba(220, 38, 38, 0.35);
        }

        .critically-low {
            color: #f97316;
            font-weight: 700;
            text-shadow: 0 0 8px rgba(249, 115, 22, 0.35);
        }

        .action-btns {
            display: flex;
            gap: 6px;
        }

        .action-btns button {
            padding: 6px 12px;
            font-size: 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }

        .edit-btn {
            background: #ecfdf5;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .edit-btn:hover {
            background: #d1fae5;
        }

        .delete-btn {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .delete-btn:hover {
            background: #fee2e2;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: white;
            padding: 24px;
            border-radius: 20px;
            min-width: 400px;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-close {
            float: right;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            color: #64748b;
            margin-bottom: 16px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: #374151;
            font-size: 14px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            background: white;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .field-note {
            display: block;
            margin-top: 6px;
            color: #64748b;
            font-size: 12px;
        }

        /* Pagination */
        .pagination {
            margin-top: 24px;
            text-align: center;
        }

        .pagination a,
        .pagination span {
            margin: 0 4px;
            padding: 8px 12px;
            border: 1px solid #e2e8f0;
            text-decoration: none;
            border-radius: 6px;
            color: #64748b;
            font-size: 13px;
            transition: all 0.2s;
        }

        .pagination a:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .pagination .current {
            background: #6366f1;
            color: white;
            border-color: #6366f1;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            .header {
                flex-direction: column;
                gap: 16px;
                align-items: flex-start;
            }
            .user-menu {
                width: 100%;
                justify-content: flex-end;
            }
            .filter-badges {
                flex-direction: column;
                align-items: stretch;
            }
            .badge {
                justify-content: center;
            }
            .search-box {
                flex-direction: column;
            }
            .data-table {
                padding: 16px;
            }
        }
    </style>
    <link rel="stylesheet" href="/pos-system/assets/css/drugstore-dashboard-base.css">
</head>
<body>
    <div class="app">
        <!-- Sidebar -->
        <?php include __DIR__ . '/partials/sidebar_dashboard.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <?php include __DIR__ . '/partials/drugstore_page_header.php'; ?>

            <?php if (isset($_SESSION['message'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?= h($_SESSION['message']) ?>
                </div>
                <?php unset($_SESSION['message']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?= h($_SESSION['error']) ?>
                </div>
                <?php unset($_SESSION['error']); ?>
            <?php endif; ?>

            <div class="filter-badges">
                <a href="?filter=all" class="badge <?= $filter === 'all' ? 'active' : '' ?>">
                    <i class="fas fa-list"></i> All (<?= $badges['total'] ?>)
                </a>
                <a href="?filter=expired" class="badge <?= $filter === 'expired' ? 'active' : '' ?>">
                    <i class="fas fa-exclamation-circle"></i> Expired (<?= $badges['expired'] ?>)
                </a>
                <a href="?filter=near" class="badge <?= $filter === 'near' ? 'active' : '' ?>">
                    <i class="fas fa-clock"></i> Expiring Soon (<?= $badges['near'] ?>)
                </a>
                <a href="?filter=low" class="badge <?= $filter === 'low' ? 'active' : '' ?>">
                    <i class="fas fa-minus-circle"></i> Low Stock (<?= $badges['low'] ?>)
                </a>
                <a href="?filter=critical" class="badge <?= $filter === 'critical' ? 'active' : '' ?>">
                    <i class="fas fa-exclamation-triangle"></i> Critical Low (<?= $badges['critical'] ?>)
                </a>
                <a href="?filter=out" class="badge <?= $filter === 'out' ? 'active' : '' ?>">
                    <i class="fas fa-ban"></i> Out of Stock (<?= $badges['out'] ?>)
                </a>
            </div>

            <div class="search-box">
                <input type="text" id="searchInput" placeholder="Search by barcode, generic name, or brand..." value="<?= h($search) ?>">
                <button onclick="search()"><i class="fas fa-search"></i> Search</button>
            </div>

            <div class="data-table">
                <table class="inventory-table">
                <thead>
                    <tr>
                        <th>Barcode</th>
                        <th>Generic Name</th>
                        <th>Brand Name</th>
                        <th>Quantity</th>
                        <th>Unit</th>
                        <th>Expiry Date</th>
                        <th>Capital Price</th>
                        <th>Wholesale Price</th>
                        <th>Store Price</th>
                        <?php if ($hasReorderLevel): ?>
                            <th>Reorder Level</th>
                        <?php endif; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item):
                        $itemBatchStmt = $db->prepare("SELECT id, batch_number, expiration_date, quantity FROM drugstore_batches WHERE inventory_id = ? ORDER BY expiration_date ASC, id ASC");
                        $itemBatchStmt->execute([$item['id']]);
                        $itemBatches = $itemBatchStmt->fetchAll(PDO::FETCH_ASSOC);

                        $isExpired = !empty($item['expiry_date']) && $item['expiry_date'] != '0000-00-00' && strtotime($item['expiry_date']) < time();
                        $isCriticalLow = $item['quantity'] > 0 && $item['quantity'] < 10;
                        $isLowStock = $item['quantity'] >= 10 && $item['quantity'] < 20;
                        $isOutStock = $item['quantity'] <= 0;
                        $expiryClass = '';
                        $expiryLabel = '';
                        if (!empty($item['expiry_date']) && $item['expiry_date'] != '0000-00-00') {
                            $expiryTimestamp = strtotime($item['expiry_date']);
                            $threeMonths = strtotime('+3 months');
                            $oneMonth = strtotime('+1 month');
                            if ($expiryTimestamp < time()) {
                                $expiryClass = 'expired';
                                $expiryLabel = 'Expired';
                            } elseif ($expiryTimestamp <= $oneMonth) {
                                $expiryClass = 'critical-expiry';
                                $expiryLabel = 'Expires Soon';
                            } elseif ($expiryTimestamp <= $threeMonths) {
                                $expiryClass = 'warning-expiry';
                                $expiryLabel = 'Near Expiry';
                            }
                        }
                        $nearExpiry = false;
                        $sellableBatches = 0;
                        foreach ($itemBatches as $batch) {
                            if (!empty($batch['expiration_date']) && $batch['expiration_date'] !== '0000-00-00') {
                                $daysRemaining = ceil((strtotime($batch['expiration_date']) - time()) / 86400);
                                if ($daysRemaining > 0 && $daysRemaining <= 30) {
                                    $nearExpiry = true;
                                }
                                if ($daysRemaining > 30) {
                                    $sellableBatches++;
                                }
                            }
                        }
                        $quantityClass = $isOutStock ? 'out-stock' : ($isCriticalLow ? 'critically-low' : ($isLowStock ? 'low-stock' : ''));
                        $expiryDisplay = ($item['expiry_date'] && $item['expiry_date'] != '0000-00-00') ? $item['expiry_date'] : 'N/A';
                        $batchStatusLabel = $sellableBatches > 0 ? 'Available for sale' : 'All batches near expiration';
                        $batchStatusColor = $sellableBatches > 0 ? '#166534' : '#f59e0b';
                    ?>
                    <tr>
                        <td class="product-cell"><?= h($item['barcode']) ?></td>
                        <td class="product-cell"><?= h($item['generic_name']) ?></td>
                        <td class="product-cell"><?= h($item['brand_name']) ?></td>
                        <td class="<?= $quantityClass ?>"><?= h($item['quantity']) ?><?php if ($nearExpiry): ?><br><span style="color:#f59e0b;font-size:11px;font-weight:600;">Near Expiration</span><?php endif; ?></td>
                        <td><?= h($item['unit']) ?></td>
                        <td class="<?= $expiryClass ?>"><?= h($expiryDisplay) ?><?php if ($expiryLabel !== ''): ?><br><span style="font-size:11px;"><?= h($expiryLabel) ?></span><?php endif; ?></td>
                        <td>₱<?= number_format($item['capital_price'], 2) ?></td>
                        <td>₱<?= number_format($item['wholesale_price'], 2) ?></td>
                        <td>₱<?= number_format($item['store_price'], 2) ?></td>
                        <?php if ($hasReorderLevel): ?>
                            <td><?= h($item['reorder_level'] ?? 5) ?></td>
                        <?php endif; ?>
                        <td>
                            <div class="action-btns">
                                <button class="edit-btn" onclick="editItem(<?= $item['id'] ?>)"><i class="fas fa-edit"></i> Edit</button>
                                <button class="delete-btn" onclick="deleteItem(<?= $item['id'] ?>)"><i class="fas fa-trash"></i> Delete</button>
                                <button class="btn btn-secondary" type="button" data-inventory-id="<?= (int)$item['id'] ?>" data-product-label="<?= h($item['generic_name'] . ' ' . $item['brand_name']) ?>" onclick="openBatchModalFromButton(this)"><i class="fas fa-plus"></i> Add Batch</button>
                                <button class="btn btn-secondary" type="button" onclick="toggleBatchView(<?= $item['id'] ?>)"><i class="fas fa-boxes"></i> View Batches</button>
                            </div>
                        </td>
                    </tr>
                    <tr id="batches-<?= $item['id'] ?>" style="display:none;">
                        <td colspan="<?= $hasReorderLevel ? 11 : 10 ?>">
                            <div style="padding:12px 0;">
                                <strong>Batch Details</strong>
                                <div style="margin-top:8px;color:<?= h($batchStatusColor) ?>;font-weight:600;"><?= h($batchStatusLabel) ?></div>
                                <?php if (empty($itemBatches)): ?>
                                    <div style="margin-top:8px;color:#64748b;">No batch records yet.</div>
                                <?php else: ?>
                                    <table style="width:100%;margin-top:8px;border-collapse:collapse;">
                                        <thead>
                                            <tr style="background:#f8fafc;">
                                                <th style="padding:8px;border:1px solid #e2e8f0;text-align:left;">Batch Number</th>
                                                <th style="padding:8px;border:1px solid #e2e8f0;text-align:left;">Expiration Date</th>
                                                <th style="padding:8px;border:1px solid #e2e8f0;text-align:left;">Remaining Qty</th>
                                                <th style="padding:8px;border:1px solid #e2e8f0;text-align:left;">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($itemBatches as $batch):
                                                $batchDaysRemaining = !empty($batch['expiration_date']) && $batch['expiration_date'] !== '0000-00-00' ? ceil((strtotime($batch['expiration_date']) - time()) / 86400) : null;
                                                $batchWarning = $batchDaysRemaining !== null && $batchDaysRemaining <= 30;
                                                $batchSellable = $batchDaysRemaining !== null && $batchDaysRemaining > 30;
                                            ?>
                                            <tr>
                                                <td style="padding:8px;border:1px solid #e2e8f0;"><?= h($batch['batch_number']) ?></td>
                                                <td style="padding:8px;border:1px solid #e2e8f0;"><?= h($batch['expiration_date']) ?></td>
                                                <td style="padding:8px;border:1px solid #e2e8f0;"><?= h($batch['quantity']) ?></td>
                                                <td style="padding:8px;border:1px solid #e2e8f0;"><?= $batchWarning ? '<span style="color:#f59e0b;font-weight:600;">Near Expiration</span>' : ($batchSellable ? '<span style="color:#166534;font-weight:600;">Available for Sale</span>' : '<span style="color:#64748b;font-weight:600;">Not Eligible</span>') ?></td>
                                                <td style="padding:8px;border:1px solid #e2e8f0;"><a href="?delete_batch=<?= (int)$batch['id'] ?>&page=<?= $page ?>" onclick="return confirm('Delete this batch?')" style="color:#dc2626;font-weight:600;">Delete</a></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <?php if (empty($items)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <p>No items found matching your criteria</p>
                </div>
            <?php endif; ?>

            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= ds_page_url(1) ?>"><i class="fas fa-step-backward"></i></a>
                    <a href="<?= ds_page_url($page - 1) ?>"><i class="fas fa-chevron-left"></i></a>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= ds_page_url($i) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="<?= ds_page_url($page + 1) ?>"><i class="fas fa-chevron-right"></i></a>
                    <a href="<?= ds_page_url($totalPages) ?>"><i class="fas fa-step-forward"></i></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add/Edit Modal -->
    <div id="itemModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeModal()">&times;</span>
            <h2 id="modalTitle">Add Item</h2>
            <form id="itemForm" method="POST">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId" value="">
                
                <div class="form-group">
                    <label>Barcode</label>
                    <input type="text" name="barcode" id="barcode" required>
                    <small class="field-note">Auto-generated from the item name for new products. You can still edit it if needed.</small>
                </div>
                <div class="form-group">
                    <label>Generic Name</label>
                    <input type="text" name="generic_name" id="generic_name" required>
                </div>
                <div class="form-group">
                    <label>Brand Name</label>
                    <input type="text" name="brand_name" id="brand_name">
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" id="quantity" value="0" required>
                </div>
                <div class="form-group">
                    <label>Unit</label>
                    <input type="text" name="unit" id="unit" placeholder="e.g., pcs, bottle, box">
                </div>
                <div class="form-group">
                    <label>Expiry Date</label>
                    <input type="date" name="expiry_date" id="expiry_date">
                </div>
                <div class="form-group">
                    <label>Capital Price</label>
                    <input type="number" name="capital_price" id="capital_price" step="0.01" value="0" required>
                </div>
                <div class="form-group">
                    <label>Wholesale Price</label>
                    <input type="number" name="wholesale_price" id="wholesale_price" step="0.01" value="0" required>
                </div>
                <div class="form-group">
                    <label>Store Price</label>
                    <input type="number" name="store_price" id="store_price" step="0.01" value="0" required>
                </div>
                <div class="form-group">
                    <label>Reorder Level</label>
                    <input type="number" name="reorder_level" id="reorder_level" value="5">
                </div>
                
                <button type="submit" class="btn btn-primary">Save Item</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            </form>
        </div>
    </div>

    <div id="batchModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeBatchModal()">&times;</span>
            <h2 id="batchModalTitle">Add Batch</h2>
            <form id="batchForm" method="POST">
                <input type="hidden" name="action" value="add_batch">
                <input type="hidden" name="inventory_id" id="batchInventoryId" value="">

                <div class="form-group">
                    <label>Product</label>
                    <input type="text" id="batchProductLabel" disabled>
                </div>
                <div class="form-group">
                    <label>Batch Number</label>
                    <input type="text" name="batch_number" id="batchNumber" placeholder="e.g. A001">
                </div>
                <div class="form-group">
                    <label>Expiration Date</label>
                    <input type="date" name="expiration_date" id="batchExpirationDate" required>
                </div>
                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" id="batchQuantity" min="1" value="1" required>
                </div>

                <button type="submit" class="btn btn-primary">Save Batch</button>
                <button type="button" class="btn btn-secondary" onclick="closeBatchModal()">Cancel</button>
            </form>
        </div>
    </div>

    <script src="/pos-system/assets/js/drugstore-sidebar-active.js"></script>
    <script>
        let barcodeSyncInProgress = false;

        function barcodeSlug(value) {
            const normalizedValue = typeof value.normalize === 'function'
                ? value.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                : value;

            return normalizedValue
                .toUpperCase()
                .replace(/[^A-Z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '')
                .slice(0, 72) || 'ITEM';
        }

        function generateInventoryBarcode(genericName = '', brandName = '') {
            return barcodeSlug(`${genericName} ${brandName}`.trim());
        }

        function syncBarcodeFromNames(force = false) {
            const barcodeInput = document.getElementById('barcode');
            const genericName = document.getElementById('generic_name').value;
            const brandName = document.getElementById('brand_name').value;
            const shouldAutoFill = force || barcodeInput.dataset.autoGenerated === 'true' || barcodeInput.value.trim() === '';

            if (!shouldAutoFill) {
                return;
            }

            barcodeSyncInProgress = true;
            barcodeInput.value = generateInventoryBarcode(genericName, brandName);
            barcodeInput.dataset.autoGenerated = 'true';
            barcodeSyncInProgress = false;
        }

        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add Item';
            document.getElementById('formAction').value = 'add';
            document.getElementById('itemForm').reset();
            document.getElementById('formId').value = '';
            document.getElementById('barcode').value = '';
            document.getElementById('barcode').dataset.autoGenerated = 'true';
            document.getElementById('reorder_level').value = '5';
            document.getElementById('itemModal').classList.add('show');
            document.getElementById('generic_name').focus();
        }

        function closeModal() {
            document.getElementById('itemModal').classList.remove('show');
        }

        function openBatchModalFromButton(button) {
            const id = button?.getAttribute('data-inventory-id') || '';
            const label = button?.getAttribute('data-product-label') || '';
            openBatchModal(id, label);
        }

        function openBatchModal(id, label) {
            document.getElementById('batchModalTitle').textContent = 'Add Batch';
            document.getElementById('batchInventoryId').value = id;
            document.getElementById('batchProductLabel').value = label || '';
            document.getElementById('batchNumber').value = '';
            document.getElementById('batchExpirationDate').value = '';
            document.getElementById('batchQuantity').value = '1';
            document.getElementById('batchModal').classList.add('show');
        }

        function closeBatchModal() {
            document.getElementById('batchModal').classList.remove('show');
        }

        function editItem(id) {
            // Fetch item data and populate form
            fetch(`get_item.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const generatedBarcode = generateInventoryBarcode(data.item.generic_name || '', data.item.brand_name || '');
                        const existingBarcode = (data.item.barcode || '').trim();

                        document.getElementById('modalTitle').textContent = 'Edit Item';
                        document.getElementById('formAction').value = 'update';
                        document.getElementById('formId').value = data.item.id;
                        document.getElementById('barcode').value = existingBarcode || generatedBarcode;
                        document.getElementById('barcode').dataset.autoGenerated = existingBarcode === '' ? 'true' : 'false';
                        document.getElementById('generic_name').value = data.item.generic_name || '';
                        document.getElementById('brand_name').value = data.item.brand_name || '';
                        document.getElementById('quantity').value = data.item.quantity || 0;
                        document.getElementById('unit').value = data.item.unit || '';
                        document.getElementById('expiry_date').value = data.item.expiry_date || '';
                        document.getElementById('capital_price').value = data.item.capital_price || 0;
                        document.getElementById('wholesale_price').value = data.item.wholesale_price || 0;
                        document.getElementById('store_price').value = data.item.store_price || 0;
                        document.getElementById('reorder_level').value = data.item.reorder_level || 5;
                        document.getElementById('itemModal').classList.add('show');
                    } else {
                        alert('Error loading item data');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading item data');
                });
        }

        function deleteItem(id) {
            if (confirm('Are you sure you want to delete this item?')) {
                window.location.href = '?delete=' + id + '&page=<?= $page ?>';
            }
        }

        function toggleBatchView(id) {
            const row = document.getElementById('batches-' + id);
            if (row) {
                row.style.display = row.style.display === 'none' ? '' : 'none';
            }
        }

        function search() {
            const searchTerm = document.getElementById('searchInput').value;
            window.location.href = '?search=' + encodeURIComponent(searchTerm) + '&filter=<?= $filter ?>';
        }

        function printTable() {
            const table = document.querySelector('.inventory-table').cloneNode(true);
            const newWindow = window.open('', '', 'width=1200, height=600');
            const now = new Date();
            const dateTime = now.toLocaleString();
            
            newWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Inventory Report</title>
                    <style>
                        * {
                            margin: 0;
                            padding: 0;
                            box-sizing: border-box;
                        }
                        body {
                            font-family: 'Courier New', monospace;
                            padding: 20px;
                            margin: 0;
                        }
                        @media print {
                            body {
                                padding: 10px;
                            }
                        }
                        .receipt {
                            max-width: 100%;
                            margin: 0 auto;
                        }
                        .receipt-header {
                            text-align: center;
                            margin-bottom: 20px;
                            padding-bottom: 10px;
                            border-bottom: 1px dashed #000;
                        }
                        .receipt-header h2 {
                            margin: 0 0 5px;
                            font-size: 18px;
                        }
                        .receipt-header p {
                            margin: 3px 0;
                            font-size: 12px;
                            color: #555;
                        }
                        table {
                            width: 100%;
                            border-collapse: collapse;
                            margin-top: 10px;
                        }
                        th, td {
                            border: 1px solid #000;
                            padding: 8px;
                            text-align: left;
                            font-size: 12px;
                        }
                        th {
                            background-color: #f2f2f2;
                            font-weight: bold;
                        }
                        .footer {
                            text-align: center;
                            margin-top: 20px;
                            font-size: 11px;
                            border-top: 1px dashed #000;
                            padding-top: 10px;
                        }
                    </style>
                </head>
                <body>
                    <div class="receipt">
                        <div class="receipt-header">
                            <h2>INVENTORY REPORT</h2>
                            <p>Date Generated: ${dateTime}</p>
                            <p>Total Items: <?php echo $totalItems; ?></p>
                        </div>
                        ${table.outerHTML}
                        <div class="footer">
                            <p>Thank you</p>
                        </div>
                    </div>
                    <script>
                        window.onload = function() {
                            window.print();
                            setTimeout(function() { window.close(); }, 500);
                        };
                    <\/script>
                </body>
                </html>
            `);
            newWindow.document.close();
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.target.closest('#itemModal')) {
                e.preventDefault();
                search();
            }
        });

        document.getElementById('generic_name').addEventListener('input', () => syncBarcodeFromNames());
        document.getElementById('brand_name').addEventListener('input', () => syncBarcodeFromNames());
        document.getElementById('barcode').addEventListener('input', (event) => {
            if (barcodeSyncInProgress) {
                return;
            }

            event.target.dataset.autoGenerated = event.target.value.trim() === '' ? 'true' : 'false';
        });
        document.getElementById('itemForm').addEventListener('submit', () => {
            const barcodeInput = document.getElementById('barcode');
            if (barcodeInput.value.trim() === '') {
                syncBarcodeFromNames(true);
            }
        });

        document.getElementById('batchForm').addEventListener('submit', () => {
            const quantityInput = document.getElementById('batchQuantity');
            if (quantityInput) {
                quantityInput.value = Math.max(1, parseInt(quantityInput.value || '1', 10) || 1);
            }
        });
    </script>
</body>
</html>