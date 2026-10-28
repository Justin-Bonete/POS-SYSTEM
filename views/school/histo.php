<?php
// histo.php - Sales History System for SchoolStore Pro

session_start();
require_once __DIR__ . '/_bootstrap.php';

$conn = schoolStoreConnect();

// Create sales tables if they don't exist
$conn->query("CREATE TABLE IF NOT EXISTS wholesale_sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_date DATETIME NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    canceled_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS wholesale_sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    barcode VARCHAR(100) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    total_price DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES wholesale_sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS retail_sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_date DATETIME NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    canceled_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS retail_sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    barcode VARCHAR(100) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    total_price DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sale_id) REFERENCES retail_sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function ensure_sales_columns(mysqli $conn, string $table): void {
    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM $table");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $cols[$row['Field']] = true;
        }
    }
    if (!isset($cols['status'])) {
        $conn->query("ALTER TABLE $table ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'completed'");
    }
    if (!isset($cols['canceled_at'])) {
        $conn->query("ALTER TABLE $table ADD COLUMN canceled_at DATETIME NULL");
    }
}

ensure_sales_columns($conn, "wholesale_sales");
ensure_sales_columns($conn, "retail_sales");

// Handle cancel sale action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_sale') {
    header('Content-Type: application/json');
    $sale_id = isset($_POST['sale_id']) ? (int)$_POST['sale_id'] : 0;
    $sale_type = isset($_POST['sale_type']) ? $_POST['sale_type'] : '';

    if ($sale_id <= 0 || !in_array($sale_type, ['wholesale', 'retail'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid sale information.']);
        exit;
    }

    $sales_table = $sale_type . '_sales';
    $items_table = $sale_type . '_sale_items';

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT status FROM $sales_table WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!$res || $res->num_rows === 0) {
            throw new Exception('Sale not found.');
        }
        $status = $res->fetch_assoc()['status'];
        if ($status === 'canceled') {
            throw new Exception('Sale is already canceled.');
        }

        $stmt = $conn->prepare("SELECT product_id, quantity FROM $items_table WHERE sale_id = ?");
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();
        $itemsRes = $stmt->get_result();
        while ($item = $itemsRes->fetch_assoc()) {
            $qty = (int)$item['quantity'];
            $pid = (int)$item['product_id'];
            $up = $conn->prepare("UPDATE school_inventory SET quantity = quantity + ? WHERE id = ?");
            $up->bind_param("ii", $qty, $pid);
            $up->execute();
        }

        $stmt = $conn->prepare("UPDATE $sales_table SET status = 'canceled', canceled_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $sale_id);
        $stmt->execute();

        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Sale canceled. Stock returned.']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// Get filter parameters
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';
$filter_date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d', strtotime('-30 days'));
$filter_date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'sale_date';
$sort_order = isset($_GET['sort_order']) ? $_GET['sort_order'] : 'DESC';

// Whitelist sorting & type for safety
$allowedSort = ['id', 'sale_date', 'total_amount'];
if (!in_array($sort_by, $allowedSort, true)) { $sort_by = 'sale_date'; }
$sort_order = strtoupper($sort_order);
if (!in_array($sort_order, ['ASC', 'DESC'], true)) { $sort_order = 'DESC'; }
if (!in_array($filter_type, ['all', 'wholesale', 'retail'], true)) { $filter_type = 'all'; }

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build query for wholesale sales
$wholesale_query = "SELECT id, total_amount as amount, sale_date, status, canceled_at, 'wholesale' as sale_type FROM wholesale_sales WHERE 1=1";
if ($filter_date_from && $filter_date_to) {
    $wholesale_query .= " AND sale_date BETWEEN '$filter_date_from 00:00:00' AND '$filter_date_to 23:59:59'";
}
if ($search) {
    $searchEsc = $conn->real_escape_string($search);
    $wholesale_query .= " AND (CAST(total_amount AS CHAR) LIKE '%$searchEsc%' OR sale_date LIKE '%$searchEsc%')";
}

// Build query for retail sales
$retail_query = "SELECT id, total_amount as amount, sale_date, status, canceled_at, 'retail' as sale_type FROM retail_sales WHERE 1=1";
if ($filter_date_from && $filter_date_to) {
    $retail_query .= " AND sale_date BETWEEN '$filter_date_from 00:00:00' AND '$filter_date_to 23:59:59'";
}
if ($search) {
    $searchEsc = $conn->real_escape_string($search);
    $retail_query .= " AND (CAST(total_amount AS CHAR) LIKE '%$searchEsc%' OR sale_date LIKE '%$searchEsc%')";
}

// Combine queries based on filter type
if ($filter_type == 'wholesale') {
    $combined_query = $wholesale_query;
} elseif ($filter_type == 'retail') {
    $combined_query = $retail_query;
} else {
    $combined_query = "($wholesale_query) UNION ALL ($retail_query)";
}

// Add sorting
$combined_query_no_limit = $combined_query . " ORDER BY $sort_by $sort_order";

// Add pagination
$combined_query = $combined_query_no_limit . " LIMIT $offset, $per_page";

// Get total count for pagination
if ($filter_type == 'wholesale') {
    $count_query = "SELECT COUNT(*) as total FROM wholesale_sales WHERE 1=1";
} elseif ($filter_type == 'retail') {
    $count_query = "SELECT COUNT(*) as total FROM retail_sales WHERE 1=1";
} else {
    $count_wholesale = "SELECT COUNT(*) as total FROM wholesale_sales";
    $count_retail = "SELECT COUNT(*) as total FROM retail_sales";
    $count_result = $conn->query($count_wholesale);
    $wholesale_total = $count_result->fetch_assoc()['total'];
    $count_result = $conn->query($count_retail);
    $retail_total = $count_result->fetch_assoc()['total'];
    $total_records = $wholesale_total + $retail_total;
}

if ($filter_type != 'all') {
    $count_query .= " AND sale_date BETWEEN '$filter_date_from 00:00:00' AND '$filter_date_to 23:59:59'";
    if ($search) {
        $searchEsc = $conn->real_escape_string($search);
        $count_query .= " AND (CAST(total_amount AS CHAR) LIKE '%$searchEsc%' OR sale_date LIKE '%$searchEsc%')";
    }
    $count_result = $conn->query($count_query);
    $total_records = $count_result->fetch_assoc()['total'];
}

$total_pages = ceil($total_records / $per_page);

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportResult = $conn->query($combined_query_no_limit);
    $exportSales = [];
    $saleIdsByType = ["wholesale" => [], "retail" => []];
    if ($exportResult) {
        while ($row = $exportResult->fetch_assoc()) {
            $exportSales[] = $row;
            if ($row['sale_type'] === 'wholesale') {
                $saleIdsByType['wholesale'][] = (int)$row['id'];
            } else {
                $saleIdsByType['retail'][] = (int)$row['id'];
            }
        }
    }

    $itemsBySale = [];
    foreach (["wholesale", "retail"] as $t) {
        $tableName = $t . "_sale_items";
        if (!empty($saleIdsByType[$t])) {
            $idList = implode(',', array_unique($saleIdsByType[$t]));
            $itemsRes = $conn->query("SELECT sale_id, product_name, barcode, quantity, unit_price, total_price FROM $tableName WHERE sale_id IN ($idList) ORDER BY id ASC");
            if ($itemsRes) {
                while ($item = $itemsRes->fetch_assoc()) {
                    $key = $t . '_' . $item["sale_id"];
                    if (!isset($itemsBySale[$key])) { $itemsBySale[$key] = []; }
                    $itemsBySale[$key][] = $item;
                }
            }
        }
    }

    $filename = "sales_history_" . date("Ymd_His") . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Date', 'Type', 'Status', 'Canceled At', 'Amount', 'Items']);
    foreach ($exportSales as $row) {
        $itemsKey = $row['sale_type'] . '_' . $row['id'];
        $items = $itemsBySale[$itemsKey] ?? [];
        $itemsText = '';
        if (!empty($items)) {
            $parts = [];
            foreach ($items as $it) {
                $parts[] = $it['product_name'] . " (" . $it['barcode'] . ") x" . (int)$it['quantity'] .
                    " @ ₱" . number_format((float)$it['unit_price'], 2) .
                    " = ₱" . number_format((float)$it['total_price'], 2);
            }
            $itemsText = implode(' | ', $parts);
        }
        fputcsv($out, [
            $row['id'],
            $row['sale_date'],
            $row['sale_type'],
            $row['status'] ?? 'completed',
            $row['canceled_at'] ?? '',
            number_format((float)$row['amount'], 2),
            $itemsText
        ]);
    }
    fclose($out);
    exit;
}

// Execute main query
$result = $conn->query($combined_query);

// Fetch sales into array for item lookup
$sales = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sales[] = $row;
    }
}

$saleIdsByType = ["wholesale" => [], "retail" => []];
foreach ($sales as $s) {
    if ($s["sale_type"] === "wholesale") {
        $saleIdsByType["wholesale"][] = (int)$s["id"];
    } else {
        $saleIdsByType["retail"][] = (int)$s["id"];
    }
}

$itemsBySale = [];
foreach (["wholesale", "retail"] as $t) {
    $tableName = $t . "_sale_items";
    if (!empty($saleIdsByType[$t])) {
        $idList = implode(',', array_unique($saleIdsByType[$t]));
        $itemsRes = $conn->query("SELECT sale_id, product_name, barcode, quantity, unit_price, total_price FROM $tableName WHERE sale_id IN ($idList) ORDER BY id ASC");
        if ($itemsRes) {
            while ($item = $itemsRes->fetch_assoc()) {
                $key = $t . '_' . $item["sale_id"];
                if (!isset($itemsBySale[$key])) { $itemsBySale[$key] = []; }
                $itemsBySale[$key][] = $item;
            }
        }
    }
}

// Get totals for summary
$wholesale_total_sum = $conn->query("SELECT SUM(total_amount) as total FROM wholesale_sales WHERE (status IS NULL OR status <> 'canceled') AND sale_date BETWEEN '$filter_date_from 00:00:00' AND '$filter_date_to 23:59:59'")->fetch_assoc()['total'] ?? 0;
$retail_total_sum = $conn->query("SELECT SUM(total_amount) as total FROM retail_sales WHERE (status IS NULL OR status <> 'canceled') AND sale_date BETWEEN '$filter_date_from 00:00:00' AND '$filter_date_to 23:59:59'")->fetch_assoc()['total'] ?? 0;
$grand_total = $wholesale_total_sum + $retail_total_sum;

// Check for night mode cookie
$night_mode = isset($_COOKIE['night_mode']) && $_COOKIE['night_mode'] == 'enabled';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales History | SchoolStore Pro</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* Night Mode Variables */
        :root {
            --bg-gradient-start: #667eea;
            --bg-gradient-end: #764ba2;
            --header-bg: rgba(255, 255, 255, 0.98);
            --sidebar-bg: rgba(255, 255, 255, 0.95);
            --text-primary: #1e293b;
            --text-secondary: #4a5568;
            --text-muted: #64748b;
            --card-bg: white;
            --table-header-bg: #f8fafc;
            --table-border: #e2e8f0;
            --input-border: #e2e8f0;
            --modal-bg: white;
            --footer-bg: white;
            --badge-wholesale: #8b5cf6;
            --badge-retail: #10b981;
        }

        body.night-mode {
            --bg-gradient-start: #1a1a2e;
            --bg-gradient-end: #16213e;
            --header-bg: rgba(26, 26, 46, 0.98);
            --sidebar-bg: rgba(26, 26, 46, 0.95);
            --text-primary: #e2e8f0;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --card-bg: #1e1e2e;
            --table-header-bg: #2d2d3a;
            --table-border: #3a3a4a;
            --input-border: #3a3a4a;
            --modal-bg: #1e1e2e;
            --footer-bg: #1a1a2e;
            --badge-wholesale: #a78bfa;
            --badge-retail: #34d399;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, var(--bg-gradient-start) 0%, var(--bg-gradient-end) 100%);
            min-height: 100vh;
            transition: all 0.3s ease;
        }

        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #555;
        }

        body.night-mode ::-webkit-scrollbar-track {
            background: #2d2d3a;
        }
        body.night-mode ::-webkit-scrollbar-thumb {
            background: #667eea;
        }

        /* Header */
        header {
            background: var(--header-bg);
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 0 30px;
            height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            transition: all 0.3s ease;
        }

        header h1 {
            font-size: 24px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .header-actions {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .badge-date {
            background: var(--card-bg);
            padding: 8px 16px;
            border-radius: 12px;
            font-size: 14px;
            color: var(--text-primary);
        }

        .night-mode-toggle {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #667eea;
            padding: 8px;
            border-radius: 50%;
            transition: all 0.3s;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .night-mode-toggle:hover {
            background: rgba(102, 126, 234, 0.1);
            transform: scale(1.1);
        }

        .logout {
            font-size: 24px;
            cursor: pointer;
            color: #667eea;
            transition: all 0.3s;
        }

        .logout:hover {
            transform: scale(1.1);
        }

        /* Main Layout */
        .main {
            display: flex;
            min-height: calc(100vh - 110px);
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            backdrop-filter: blur(10px);
            box-shadow: 2px 0 20px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            position: relative;
        }

        .toggle-btn {
            position: absolute;
            right: -12px;
            top: 20px;
            background: var(--card-bg);
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            color: #667eea;
            transition: all 0.3s;
        }

        .sidebar.shrink {
            width: 80px;
        }

        .sidebar.shrink ul li a span {
            display: none;
        }

        .sidebar ul {
            list-style: none;
            padding: 20px 0;
        }

        .sidebar ul li {
            margin: 8px 15px;
        }

        .sidebar ul li a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 18px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s;
            font-weight: 500;
        }

        .sidebar ul li a i {
            width: 24px;
            font-size: 20px;
        }

        .sidebar ul li a:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            transform: translateX(5px);
        }

        .sidebar ul li a.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        /* Content Area */
        .content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 30px;
        }

        .page-header h2 {
            font-size: 28px;
            font-weight: 700;
            color: white;
            margin-bottom: 10px;
        }

        .page-header p {
            color: rgba(255, 255, 255, 0.8);
        }

        /* Summary Cards */
        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s;
            position: relative;
            overflow: hidden;
        }

        .summary-card:hover {
            transform: translateY(-5px);
        }

        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .summary-card h3 {
            font-size: 14px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 10px;
            letter-spacing: 0.5px;
        }

        .summary-card .amount {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .summary-card.wholesale .amount { color: var(--badge-wholesale); }
        .summary-card.retail .amount { color: var(--badge-retail); }
        .summary-card.total .amount { color: #f59e0b; }

        .summary-card small {
            color: var(--text-muted);
            font-size: 12px;
        }

        /* Filters */
        .filters {
            background: var(--card-bg);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 30px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: var(--text-muted);
            margin-bottom: 5px;
        }

        .filter-group input, .filter-group select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--input-border);
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s;
            background: var(--card-bg);
            color: var(--text-primary);
        }

        .filter-group input:focus, .filter-group select:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn-filter {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }

        .btn-filter:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-reset {
            background: var(--table-header-bg);
            color: var(--text-primary);
            border: none;
            padding: 10px 24px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        .btn-reset:hover {
            background: var(--table-border);
        }

        /* Table */
        .table-container {
            background: var(--card-bg);
            border-radius: 20px;
            overflow-x: auto;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th {
            background: var(--table-header-bg);
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: var(--text-primary);
            border-bottom: 2px solid var(--table-border);
            cursor: pointer;
            transition: background 0.3s;
        }

        table th:hover {
            background: var(--table-border);
        }

        table th a {
            color: var(--text-primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--table-border);
            color: var(--text-primary);
        }

        table tr:hover {
            background: var(--table-header-bg);
        }

        .badge-wholesale {
            background: var(--badge-wholesale);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-retail {
            background: var(--badge-retail);
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-completed {
            background: #16a34a;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .badge-canceled {
            background: #ef4444;
            color: white;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }

        .amount {
            font-weight: 700;
            color: var(--text-primary);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            background: none;
            border: none;
            cursor: pointer;
            padding: 6px;
            border-radius: 8px;
            transition: all 0.3s;
            color: #667eea;
        }

        .btn-icon.btn-cancel {
            color: #ef4444;
        }

        .btn-icon:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }

        .btn-icon:hover {
            background: var(--table-header-bg);
            transform: scale(1.1);
        }

        .items-toggle {
            background: none;
            border: none;
            color: #667eea;
            cursor: pointer;
            font-weight: 600;
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 6px;
        }

        .items-toggle:hover {
            background: var(--table-header-bg);
        }

        .details-row {
            display: none;
        }

        .details-row.open {
            display: table-row;
        }

        .items-box {
            padding: 16px;
            background: var(--table-header-bg);
        }

        .items-table {
            width: 100%;
            border-collapse: collapse;
        }

        .items-table th {
            background: var(--card-bg);
            color: var(--text-primary);
            padding: 8px;
            font-size: 12px;
            text-transform: uppercase;
            border-bottom: 1px solid var(--table-border);
        }

        .items-table td {
            padding: 8px;
            font-size: 13px;
            border-bottom: 1px solid var(--table-border);
            color: var(--text-primary);
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 20px;
        }

        .pagination a, .pagination span {
            padding: 8px 14px;
            background: var(--card-bg);
            border-radius: 8px;
            text-decoration: none;
            color: #667eea;
            font-weight: 500;
            transition: all 0.3s;
        }

        .pagination .active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .pagination a:hover {
            background: var(--table-header-bg);
            transform: translateY(-2px);
        }

        footer {
            background: var(--footer-bg);
            padding: 15px 30px;
            text-align: center;
            color: var(--text-muted);
            font-size: 14px;
            transition: all 0.3s ease;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 80px;
            }
            .sidebar ul li a span {
                display: none;
            }
            .content {
                padding: 20px;
            }
            .filters {
                flex-direction: column;
            }
            .filter-group {
                width: 100%;
            }
        }
    </style>
</head>
<body class="<?php echo $night_mode ? 'night-mode' : ''; ?>">

<header>
    <h1><i class="fas fa-graduation-cap"></i> SchoolStore Pro</h1>
    <div class="header-actions">
        <div class="badge-date"><i class="far fa-calendar-alt"></i> <?php echo date("F d, Y"); ?></div>
        <button class="night-mode-toggle" onclick="toggleNightMode()" title="Toggle Night Mode">
            <i class="fas <?php echo $night_mode ? 'fa-sun' : 'fa-moon'; ?>"></i>
        </button>
        <i class="fas fa-bell" style="font-size: 20px; color: #667eea;"></i>
        <i class="fas fa-sign-out-alt logout"></i>
    </div>
</header>

<div class="main">
    <div class="sidebar" id="sidebar">
        <div class="toggle-btn" onclick="toggleSidebar()">
            <i class="fas fa-chevron-left"></i>
        </div>
        <ul>
            <li><a href="home.php"><i class="fas fa-home"></i><span> Dashboard</span></a></li>
            <li><a href="inventory.php"><i class="fas fa-boxes"></i><span> Inventory</span></a></li>
            <li><a href="wholesale.php"><i class="fas fa-shopping-cart"></i><span> Wholesale</span></a></li>
            <li><a href="retail.php"><i class="fas fa-store"></i><span> Retail</span></a></li>
            <li><a href="histo.php" class="active"><i class="fas fa-history"></i><span> Sales History</span></a></li>
        </ul>
    </div>

    <div class="content">
        <div class="page-header">
            <h2><i class="fas fa-history"></i> Sales History</h2>
            <p>View and manage all wholesale and retail sales transactions</p>
        </div>

        <!-- Summary Cards -->
        <div class="summary-cards">
            <div class="summary-card wholesale">
                <h3><i class="fas fa-shopping-cart"></i> Wholesale Sales</h3>
                <div class="amount">₱<?php echo number_format($wholesale_total_sum, 2); ?></div>
                <small><?php echo date("M d, Y", strtotime($filter_date_from)); ?> - <?php echo date("M d, Y", strtotime($filter_date_to)); ?></small>
            </div>
            <div class="summary-card retail">
                <h3><i class="fas fa-store"></i> Retail Sales</h3>
                <div class="amount">₱<?php echo number_format($retail_total_sum, 2); ?></div>
                <small><?php echo date("M d, Y", strtotime($filter_date_from)); ?> - <?php echo date("M d, Y", strtotime($filter_date_to)); ?></small>
            </div>
            <div class="summary-card total">
                <h3><i class="fas fa-chart-line"></i> Total Sales</h3>
                <div class="amount">₱<?php echo number_format($grand_total, 2); ?></div>
                <small>Combined total for selected period</small>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="filters">
            <div class="filter-group">
                <label><i class="fas fa-calendar"></i> Date From</label>
                <input type="date" name="date_from" value="<?php echo $filter_date_from; ?>">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-calendar"></i> Date To</label>
                <input type="date" name="date_to" value="<?php echo $filter_date_to; ?>">
            </div>
            <div class="filter-group">
                <label><i class="fas fa-tag"></i> Sale Type</label>
                <select name="type">
                    <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>All Sales</option>
                    <option value="wholesale" <?php echo $filter_type == 'wholesale' ? 'selected' : ''; ?>>Wholesale</option>
                    <option value="retail" <?php echo $filter_type == 'retail' ? 'selected' : ''; ?>>Retail</option>
                </select>
            </div>
            <div class="filter-group">
                <label><i class="fas fa-search"></i> Search</label>
                <input type="text" name="search" placeholder="Amount or date..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Apply</button>
                <a href="histo.php" class="btn-reset" style="display: inline-block; margin-left: 8px;"><i class="fas fa-undo"></i> Reset</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv', 'page' => 1])); ?>" class="btn-reset" style="display: inline-block; margin-left: 8px;"><i class="fas fa-file-csv"></i> Export</a>
            </div>
        </form>

        <!-- Sales Table -->
        <div class="table-container">
            <table id="salesTable">
                <thead>
                    <tr>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'id', 'sort_order' => $sort_by == 'id' && $sort_order == 'DESC' ? 'ASC' : 'DESC'])); ?>">ID <i class="fas fa-sort"></i></a></th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'sale_date', 'sort_order' => $sort_by == 'sale_date' && $sort_order == 'DESC' ? 'ASC' : 'DESC'])); ?>">Date & Time <i class="fas fa-sort"></i></a></th>
                        <th>Type</th>
                        <th>Status</th>
                        <th><a href="?<?php echo http_build_query(array_merge($_GET, ['sort_by' => 'total_amount', 'sort_order' => $sort_by == 'total_amount' && $sort_order == 'DESC' ? 'ASC' : 'DESC'])); ?>">Amount <i class="fas fa-sort"></i></a></th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($sales)): ?>
                        <?php foreach ($sales as $row): ?>
                            <?php
                                $detailKey = $row['sale_type'] . '_' . $row['id'];
                                $itemsKey = $row['sale_type'] . '_' . $row['id'];
                                $items = $itemsBySale[$itemsKey] ?? [];
                            ?>
                            <tr>
                                <td>#<?php echo $row['id']; ?></td>
                                <td><?php echo date("M d, Y h:i A", strtotime($row['sale_date'])); ?></td>
                                <td>
                                    <?php if ($row['sale_type'] == 'wholesale'): ?>
                                        <span class="badge-wholesale"><i class="fas fa-shopping-cart"></i> Wholesale</span>
                                    <?php else: ?>
                                        <span class="badge-retail"><i class="fas fa-store"></i> Retail</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (($row['status'] ?? 'completed') === 'canceled'): ?>
                                        <span class="badge-canceled" title="Canceled at <?php echo htmlspecialchars($row['canceled_at'] ?? ''); ?>">
                                            <i class="fas fa-ban"></i> Canceled
                                        </span>
                                    <?php else: ?>
                                        <span class="badge-completed"><i class="fas fa-check-circle"></i> Completed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="amount">₱<?php echo number_format($row['amount'], 2); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button onclick="viewReceipt(<?php echo $row['id']; ?>, '<?php echo $row['sale_type']; ?>')" class="btn-icon" title="View Receipt">
                                            <i class="fas fa-receipt"></i>
                                        </button>
                                        <button type="button" class="items-toggle" onclick="toggleItems('<?php echo $detailKey; ?>', this)" title="View Items">
                                            <i class="fas fa-list"></i> Items
                                        </button>
                                        <?php if (($row['status'] ?? 'completed') !== 'canceled'): ?>
                                            <button type="button" class="btn-icon btn-cancel" onclick="cancelSale(<?php echo $row['id']; ?>, '<?php echo $row['sale_type']; ?>')" title="Cancel Sale">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn-icon btn-cancel" disabled title="Already Canceled">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <tr class="details-row" id="items-<?php echo $detailKey; ?>">
                                <td colspan="6" class="items-box">
                                    <?php if (!empty($items)): ?>
                                        <table class="items-table">
                                            <thead>
                                                <tr>
                                                    <th>Product</th>
                                                    <th>Barcode</th>
                                                    <th>Quantity</th>
                                                    <th>Unit Price</th>
                                                    <th>Total</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($items as $item): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($item['barcode']); ?></td>
                                                        <td><?php echo (int)$item['quantity']; ?></td>
                                                        <td>₱<?php echo number_format((float)$item['unit_price'], 2); ?></td>
                                                        <td>₱<?php echo number_format((float)$item['total_price'], 2); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    <?php else: ?>
                                        <div style="color: var(--text-muted); text-align: center; padding: 20px;">
                                            <i class="fas fa-info-circle"></i> No item details available for this sale.
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 60px;">
                                <i class="fas fa-chart-line" style="font-size: 64px; color: var(--text-muted);"></i>
                                <p style="margin-top: 15px; color: var(--text-muted);">No sales records found for the selected period.</p>
                                <p style="font-size: 14px; color: var(--text-muted);">Try adjusting your filters or add some sales first.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>"><i class="fas fa-chevron-left"></i> Prev</a>
            <?php endif; ?>
            
            <?php
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            for ($i = $start_page; $i <= $end_page; $i++):
            ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" class="<?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next <i class="fas fa-chevron-right"></i></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<footer>
    <p>© 2024 SchoolStore Pro | Smart Inventory Management System | Total Sales: ₱<?php echo number_format($grand_total, 2); ?></p>
</footer>

<script>
let sidebarShrink = false;

function toggleSidebar() {
    sidebarShrink = !sidebarShrink;
    const sidebar = document.getElementById('sidebar');
    const toggleIcon = document.querySelector('.toggle-btn i');
    
    if (sidebarShrink) {
        sidebar.classList.add('shrink');
        toggleIcon.classList.remove('fa-chevron-left');
        toggleIcon.classList.add('fa-chevron-right');
    } else {
        sidebar.classList.remove('shrink');
        toggleIcon.classList.remove('fa-chevron-right');
        toggleIcon.classList.add('fa-chevron-left');
    }
}

// Night Mode Toggle
function toggleNightMode() {
    const body = document.body;
    const icon = document.querySelector('.night-mode-toggle i');
    
    if (body.classList.contains('night-mode')) {
        body.classList.remove('night-mode');
        icon.classList.remove('fa-sun');
        icon.classList.add('fa-moon');
        document.cookie = "night_mode=disabled; path=/; max-age=" + 60*60*24*30;
    } else {
        body.classList.add('night-mode');
        icon.classList.remove('fa-moon');
        icon.classList.add('fa-sun');
        document.cookie = "night_mode=enabled; path=/; max-age=" + 60*60*24*30;
    }
}

function toggleItems(key, btn) {
    const row = document.getElementById("items-" + key);
    if (!row) return;
    row.classList.toggle("open");
    if (btn) {
        const icon = btn.querySelector('i');
        const text = row.classList.contains("open") ? "Hide" : "Items";
        btn.innerHTML = `<i class="fas ${row.classList.contains("open") ? 'fa-minus' : 'fa-list'}"></i> ${text}`;
    }
}

function viewReceipt(id, type) {
    if (!id || !type) return;
    window.open(`print_receipt.php?id=${encodeURIComponent(id)}&type=${encodeURIComponent(type)}`, '_blank');
}

function cancelSale(id, type) {
    if (!confirm('Cancel this sale? The stock will be returned to inventory.')) return;
    const formData = new FormData();
    formData.append('action', 'cancel_sale');
    formData.append('sale_id', id);
    formData.append('sale_type', type);

    fetch('histo.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            window.location.reload();
        } else {
            alert(data.message || 'Failed to cancel sale.');
        }
    })
    .catch(() => alert('Failed to cancel sale.'));
}

// Auto-submit filters on date/type change
document.querySelectorAll('.filters select, .filters input[type="date"]').forEach(el => {
    el.addEventListener('change', () => {
        document.querySelector('.filters').submit();
    });
});
</script>

</body>
</html>

<?php $conn->close(); ?>




