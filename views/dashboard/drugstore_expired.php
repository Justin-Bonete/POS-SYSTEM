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
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

$today = date('Y-m-d');
$expiry_filter = isset($_GET['filter']) ? $_GET['filter'] : 'expired';

if ($expiry_filter == 'expired') {
    $query = "SELECT * FROM drugstore_inventory WHERE expiry_date < :today AND expiry_date IS NOT NULL AND expiry_date != '0000-00-00' ORDER BY expiry_date ASC";
    $stmt = $conn->prepare($query);
    $stmt->execute([':today' => $today]);
    $title = "Expired Products";
} else {
    $query = "SELECT * FROM drugstore_inventory WHERE expiry_date BETWEEN :today AND DATE_ADD(:today, INTERVAL 30 DAY) AND quantity > 0 AND expiry_date IS NOT NULL AND expiry_date != '0000-00-00' ORDER BY expiry_date ASC";
    $stmt = $conn->prepare($query);
    $stmt->execute([':today' => $today]);
    $title = "Products Expiring Soon (Next 30 Days)";
}

$expired_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total_value = 0;
foreach ($expired_items as $item) {
    $total_value += $item['quantity'] * $item['store_price'];
}

$activePage = 'expired';
$drugstoreHeaderIcon = 'fa-calendar-times';
$drugstoreHeaderTitle = $title;
$drugstoreHeaderSubtitle = 'Monitor product expiry dates and take action before stock goes to waste.';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> - Lhundren Drugstore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/pos-system/assets/css/drugstore-dashboard-base.css">
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

        .greeting h1 i {
            margin-right: 12px;
            color: #ef4444;
        }

        .greeting p {
            font-size: 14px;
            color: #64748b;
            margin-top: 6px;
        }

        .filter-section {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }

        .filter-btn {
            padding: 10px 20px;
            border-radius: 40px;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .filter-btn.active {
            background: #ef4444;
            color: white;
        }

        .filter-btn:not(.active) {
            background: white;
            color: #64748b;
            border: 1px solid #e2e8f0;
        }

        .filter-btn:not(.active):hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            border: 1px solid #e9eef3;
            transition: all 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px -6px rgba(0, 0, 0, 0.08);
        }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .stat-title {
            font-size: 13px;
            font-weight: 500;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            background: #fef2f2;
            color: #ef4444;
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .stat-change {
            font-size: 12px;
            color: #64748b;
        }

        /* Data Table */
        .data-table {
            background: white;
            border-radius: 20px;
            padding: 24px;
            border: 1px solid #e9eef3;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: #0f172a;
            margin: 0;
        }

        .table-summary {
            font-size: 14px;
            color: #64748b;
        }

        .expired-table {
            width: 100%;
            border-collapse: collapse;
        }

        .expired-table th,
        .expired-table td {
            padding: 12px 8px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
        }

        .expired-table th {
            font-weight: 600;
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: white;
        }

        .expired-table td {
            color: #1e293b;
        }

        .expired-table tr:hover {
            background: #f8fafc;
        }

        .product-cell {
            font-weight: 500;
        }

        .expired {
            color: #ef4444;
            font-weight: 600;
        }

        .expiring-soon {
            color: #f59e0b;
            font-weight: 600;
        }

        .quantity-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
        }

        .quantity-low {
            background: #fef3c7;
            color: #d97706;
        }

        .quantity-out {
            background: #fee2e2;
            color: #dc2626;
        }

        .value-cell {
            font-weight: 600;
            color: #059669;
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
            .stats-grid {
                grid-template-columns: 1fr;
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

            <!-- Filter Buttons -->
            <div class="filter-section">
                <a href="?filter=expired" class="filter-btn <?php echo $expiry_filter === 'expired' ? 'active' : ''; ?>">
                    <i class="fas fa-exclamation-triangle"></i> Expired Products
                </a>
                <a href="?filter=expiring" class="filter-btn <?php echo $expiry_filter === 'expiring' ? 'active' : ''; ?>">
                    <i class="fas fa-clock"></i> Expiring Soon
                </a>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Total Items</div>
                        <div class="stat-icon">
                            <i class="fas fa-boxes"></i>
                        </div>
                    </div>
                    <div class="stat-value"><?php echo count($expired_items); ?></div>
                    <div class="stat-change"><?php echo $expiry_filter === 'expired' ? 'Expired' : 'Expiring Soon'; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Total Value</div>
                        <div class="stat-icon">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                    </div>
                    <div class="stat-value">₱<?php echo number_format($total_value, 2); ?></div>
                    <div class="stat-change">At store price</div>
                </div>
                <?php if ($expiry_filter === 'expired'): ?>
                <div class="stat-card">
                    <div class="stat-header">
                        <div class="stat-title">Days Overdue</div>
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                    </div>
                    <div class="stat-value">
                        <?php
                        if (count($expired_items) > 0) {
                            $oldest = min(array_map(function($item) {
                                return strtotime($item['expiry_date']);
                            }, $expired_items));
                            $days = floor((time() - $oldest) / (60*60*24));
                            echo $days;
                        } else {
                            echo '0';
                        }
                        ?>
                    </div>
                    <div class="stat-change">Since expiry</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Data Table -->
            <div class="data-table">
                <div class="table-header">
                    <h3 class="table-title"><?php echo htmlspecialchars($title); ?></h3>
                    <div class="table-summary">
                        <?php echo count($expired_items); ?> items • Total value: ₱<?php echo number_format($total_value, 2); ?>
                    </div>
                </div>

                <?php if (empty($expired_items)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle"></i>
                        <p><?php echo $expiry_filter === 'expired' ? 'No expired products found!' : 'No products expiring soon!'; ?></p>
                    </div>
                <?php else: ?>
                    <table class="expired-table">
                        <thead>
                            <tr>
                                <th>Barcode</th>
                                <th>Generic Name</th>
                                <th>Brand Name</th>
                                <th>Quantity</th>
                                <th>Unit</th>
                                <th>Expiry Date</th>
                                <th>Store Price</th>
                                <th>Total Value</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($expired_items as $item):
                                $isExpired = strtotime($item['expiry_date']) < time();
                                $daysDiff = floor((time() - strtotime($item['expiry_date'])) / (60*60*24));
                                $itemValue = $item['quantity'] * $item['store_price'];
                            ?>
                            <tr>
                                <td class="product-cell"><?php echo htmlspecialchars($item['barcode']); ?></td>
                                <td class="product-cell"><?php echo htmlspecialchars($item['generic_name']); ?></td>
                                <td class="product-cell"><?php echo htmlspecialchars($item['brand_name']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($item['quantity']); ?>
                                    <?php if ($item['quantity'] <= 0): ?>
                                        <span class="quantity-badge quantity-out">Out</span>
                                    <?php elseif ($item['quantity'] <= ($item['reorder_level'] ?? 5)): ?>
                                        <span class="quantity-badge quantity-low">Low</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?></td>
                                <td class="<?php echo $isExpired ? 'expired' : 'expiring-soon'; ?>">
                                    <?php echo htmlspecialchars($item['expiry_date']); ?>
                                    <?php if ($isExpired): ?>
                                        <br><small><?php echo $daysDiff; ?> days ago</small>
                                    <?php endif; ?>
                                </td>
                                <td>₱<?php echo number_format($item['store_price'], 2); ?></td>
                                <td class="value-cell">₱<?php echo number_format($itemValue, 2); ?></td>
                                <td>
                                    <span class="quantity-badge <?php echo $isExpired ? 'quantity-out' : 'quantity-low'; ?>">
                                        <?php echo $isExpired ? 'Expired' : 'Expiring Soon'; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="/pos-system/assets/js/drugstore-sidebar-active.js"></script>
</body>
</html>
