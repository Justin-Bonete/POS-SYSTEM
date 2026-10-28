<?php
session_start();

// Check if user is logged in and system type is drugstore
if (!isset($_SESSION['user_id']) || !isset($_SESSION['selected_system']) || $_SESSION['selected_system'] !== 'drugstore') {
    header('Location: /pos-system/index.php');
    exit();
}

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "drugstore1_db";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get dashboard statistics
$today = date('Y-m-d');

// Total products
$result = $conn->query("SELECT COUNT(*) as total FROM drugstore_inventory");
$total_products = $result->fetch_assoc()['total'];

// Today's sales
$result = $conn->query("SELECT SUM(grand_total) as total FROM drugstore_sales WHERE DATE(sale_date) = '$today'");
$today_sales = $result->fetch_assoc()['total'] ?? 0;

// Total sales
$result = $conn->query("SELECT SUM(grand_total) as total FROM drugstore_sales");
$total_sales = $result->fetch_assoc()['total'] ?? 0;

// Low stock items
$result = $conn->query("SELECT COUNT(*) as total FROM drugstore_inventory WHERE quantity <= reorder_level AND quantity > 0");
$low_stock = $result->fetch_assoc()['total'] ?? 0;

// Expired items
$result = $conn->query("SELECT COUNT(*) as total FROM drugstore_inventory WHERE expiry_date < CURDATE() AND expiry_date IS NOT NULL");
$expired = $result->fetch_assoc()['total'] ?? 0;

// Total suppliers
$result = $conn->query("SELECT COUNT(*) as total FROM suppliers");
$total_suppliers = $result->fetch_assoc()['total'] ?? 0;

// Total staff
$result = $conn->query("SELECT COUNT(*) as total FROM staff");
$total_staff = $result->fetch_assoc()['total'] ?? 0;

// Recent sales (last 5)
$recent_sales = $conn->query("SELECT * FROM drugstore_sales ORDER BY sale_date DESC LIMIT 5");

// Get low stock products
$low_stock_products = $conn->query("SELECT generic_name, brand_name, quantity, reorder_level FROM drugstore_inventory WHERE quantity <= reorder_level AND quantity > 0 ORDER BY quantity ASC LIMIT 5");

// Get expired products
$expired_products = $conn->query("SELECT generic_name, brand_name, expiry_date, quantity FROM drugstore_inventory WHERE expiry_date < CURDATE() AND expiry_date IS NOT NULL ORDER BY expiry_date ASC LIMIT 5");
$activePage = 'dashboard';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drugstore Dashboard - Lhundren Drugstore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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

        .logo-area {
            padding: 28px 24px;
            border-bottom: 1px solid #e9eef3;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .logo-text h2 {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
        }

        .logo-text p {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }

        .nav-menu {
            flex: 1;
            padding: 24px 16px;
        }

        .nav-item {
            margin-bottom: 4px;
        }

        .nav-item a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 16px;
            color: #475569;
            text-decoration: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .nav-item a i {
            width: 20px;
            font-size: 16px;
        }

        .nav-item a:hover {
            background: #f1f5f9;
            color: #6366f1;
        }

        .nav-item.active a {
            background: #f1f5f9;
            color: #6366f1;
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
            gap: 20px;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 16px;
            background: white;
            border-radius: 40px;
            border: 1px solid #e9eef3;
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-size: 14px;
            font-weight: 600;
            color: #0f172a;
        }

        .user-role {
            font-size: 11px;
            color: #64748b;
        }

        .logout-btn {
            background: none;
            border: 1px solid #e9eef3;
            padding: 8px 16px;
            border-radius: 40px;
            cursor: pointer;
            color: #64748b;
            font-size: 14px;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn:hover {
            background: #fee2e2;
            border-color: #fecaca;
            color: #dc2626;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
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

        /* Color Variations */
        .stat-card.blue .stat-icon { background: #eef2ff; color: #6366f1; }
        .stat-card.green .stat-icon { background: #ecfdf5; color: #10b981; }
        .stat-card.orange .stat-icon { background: #fff7ed; color: #f59e0b; }
        .stat-card.red .stat-icon { background: #fef2f2; color: #ef4444; }
        .stat-card.purple .stat-icon { background: #f5f3ff; color: #8b5cf6; }

        /* Reports Section */
        .reports-section {
            margin-bottom: 32px;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .section-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: #0f172a;
        }

        .section-header a {
            color: #6366f1;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
        }

        .reports-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .report-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            border: 1px solid #e9eef3;
        }

        .report-card h3 {
            font-size: 15px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 16px;
        }

        .report-list {
            list-style: none;
        }

        .report-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .report-list li:last-child {
            border-bottom: none;
        }

        .report-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .report-name {
            font-size: 14px;
            font-weight: 500;
            color: #0f172a;
        }

        .report-meta {
            font-size: 11px;
            color: #94a3b8;
        }

        .report-badge {
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 500;
        }

        .badge-ready {
            background: #ecfdf5;
            color: #10b981;
        }

        .badge-failed {
            background: #fef2f2;
            color: #ef4444;
        }

        .badge-generating {
            background: #fff7ed;
            color: #f59e0b;
        }

        .badge-subscribed {
            background: #eef2ff;
            color: #6366f1;
        }

        /* Two Column Layout */
        .two-columns {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }

        .data-table {
            background: white;
            border-radius: 20px;
            padding: 20px;
            border: 1px solid #e9eef3;
        }

        .data-table h3 {
            font-size: 15px;
            font-weight: 600;
            color: #0f172a;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 12px 8px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
            font-size: 13px;
        }

        th {
            font-weight: 600;
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            color: #1e293b;
        }

        .product-cell {
            font-weight: 500;
        }

        .low-stock {
            color: #f59e0b;
            font-weight: 600;
        }

        .expired {
            color: #ef4444;
            font-weight: 600;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 12px;
            opacity: 0.5;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                z-index: 1000;
            }
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            .stats-grid,
            .reports-grid,
            .two-columns {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
  <div class="app">
        <!-- Sidebar -->
        <?php include __DIR__ . '/partials/sidebar_dashboard.php'; ?>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div class="greeting">
                    <h1>Hi, <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?>! 🎉</h1>
                    <p>Welcome to your Drugstore Management Workspace</p>
                </div>
                <div class="user-menu">
                    <div class="user-info">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
                        </div>
                        <div class="user-details">
                            <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username']); ?></div>
                            <div class="user-role"><?php echo ucfirst($_SESSION['role'] ?? 'Staff'); ?></div>
                        </div>
                    </div>
                    <a href="/pos-system/index.php" class="logout-btn">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card blue">
                    <div class="stat-header">
                        <span class="stat-title">Total Products</span>
                        <div class="stat-icon"><i class="fas fa-capsules"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $total_products; ?></div>
                    <div class="stat-change">Active inventory items</div>
                </div>
                <div class="stat-card green">
                    <div class="stat-header">
                        <span class="stat-title">Today's Sales</span>
                        <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                    </div>
                    <div class="stat-value">₱<?php echo number_format($today_sales, 2); ?></div>
                    <div class="stat-change">Today's revenue</div>
                </div>
                <div class="stat-card purple">
                    <div class="stat-header">
                        <span class="stat-title">Total Sales</span>
                        <div class="stat-icon"><i class="fas fa-chart-bar"></i></div>
                    </div>
                    <div class="stat-value">₱<?php echo number_format($total_sales, 2); ?></div>
                    <div class="stat-change">Lifetime revenue</div>
                </div>
                <div class="stat-card orange">
                    <div class="stat-header">
                        <span class="stat-title">Low Stock</span>
                        <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $low_stock; ?></div>
                    <div class="stat-change">Items below reorder level</div>
                </div>
                <div class="stat-card red">
                    <div class="stat-header">
                        <span class="stat-title">Expired</span>
                        <div class="stat-icon"><i class="fas fa-skull-crossbones"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $expired; ?></div>
                    <div class="stat-change">Products past expiry date</div>
                </div>
                <div class="stat-card blue">
                    <div class="stat-header">
                        <span class="stat-title">Suppliers</span>
                        <div class="stat-icon"><i class="fas fa-truck"></i></div>
                    </div>
                    <div class="stat-value"><?php echo $total_suppliers; ?></div>
                    <div class="stat-change">Active suppliers</div>
                </div>
            </div>

            <!-- Reports Section -->
            <div class="reports-section">
                <div class="section-header">
                    <h2>Reports Center</h2>
                </div>
                <div class="reports-grid">
                    <div class="report-card">
                        <h3>Report Wizard</h3>
                        <ul class="report-list">
                            <li>
                                <div class="report-info">
                                    <span class="report-name">Performance Report</span>
                                    <span class="report-meta">Daily sales analysis</span>
                                </div>
                                <span class="report-badge badge-ready">Ready</span>
                            </li>
                            <li>
                                <div class="report-info">
                                    <span class="report-name">Financial Project</span>
                                    <span class="report-meta">Monthly revenue forecast</span>
                                </div>
                                <span class="report-badge badge-generating">Generating</span>
                            </li>
                            <li>
                                <div class="report-info">
                                    <span class="report-name">Inventory Summary</span>
                                    <span class="report-meta">Stock level analysis</span>
                                </div>
                                <button class="report-badge" style="background:#eef2ff; color:#6366f1; border:none; cursor:pointer;">Generate</button>
                            </li>
                        </ul>
                    </div>
                    <div class="report-card">
                        <h3>Report Archive</h3>
                        <ul class="report-list">
                            <li>
                                <div class="report-info">
                                    <span class="report-name">Monthly Sales Report</span>
                                    <span class="report-meta">March 2025</span>
                                </div>
                                <span class="report-badge badge-ready">Ready</span>
                            </li>
                            <li>
                                <div class="report-info">
                                    <span class="report-name">Expired Products</span>
                                    <span class="report-meta">February 2025</span>
                                </div>
                                <span class="report-badge badge-ready">Ready</span>
                            </li>
                            <li>
                                <div class="report-info">
                                    <span class="report-name">Inventory Valuation</span>
                                    <span class="report-meta">Q1 2025</span>
                                </div>
                                <span class="report-badge badge-ready">Ready</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Recent Sales and Alerts -->
            <div class="two-columns">
                <!-- Recent Sales Table -->
                <div class="data-table">
                    <h3><i class="fas fa-receipt"></i> Recent Transactions</h3>
                     <table>
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Customer</th>
                                <th>Type</th>
                                <th>Total</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent_sales && $recent_sales->num_rows > 0): ?>
                                <?php while ($sale = $recent_sales->fetch_assoc()): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($sale['invoice_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($sale['customer_name'] ?? 'Walk-in'); ?></td>
                                    <td><span class="report-badge <?php echo $sale['sale_type'] == 'retail' ? 'badge-ready' : 'badge-subscribed'; ?>"><?php echo ucfirst($sale['sale_type']); ?></span></td>
                                    <td><strong>₱<?php echo number_format($sale['grand_total'], 2); ?></strong></td>
                                    <td><?php echo date('M d, Y', strtotime($sale['sale_date'])); ?></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="empty-state">
                                        <i class="fas fa-receipt"></i>
                                        <p>No recent sales found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="section-header" style="margin-top: 16px; margin-bottom: 0;">
                        <a href="drugstore_history.php">View All →</a>
                    </div>
                </div>

                <!-- Subscribed Reports / Alerts -->
                <div class="data-table">
                    <h3><i class="fas fa-bell"></i> Stock Alerts</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Stock</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($low_stock_products && $low_stock_products->num_rows > 0): ?>
                                <?php while ($product = $low_stock_products->fetch_assoc()): ?>
                                <tr>
                                    <td class="product-cell">
                                        <?php echo htmlspecialchars($product['brand_name']); ?>
                                        <div style="font-size: 11px; color: #94a3b8;"><?php echo htmlspecialchars($product['generic_name']); ?></div>
                                    </td>
                                    <td class="low-stock"><?php echo $product['quantity']; ?> units</td>
                                    <td><span class="report-badge badge-generating">Low Stock</span></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                            <?php if ($expired_products && $expired_products->num_rows > 0): ?>
                                <?php while ($product = $expired_products->fetch_assoc()): ?>
                                <tr>
                                    <td class="product-cell">
                                        <?php echo htmlspecialchars($product['brand_name']); ?>
                                        <div style="font-size: 11px; color: #94a3b8;"><?php echo htmlspecialchars($product['generic_name']); ?></div>
                                    </td>
                                    <td class="expired"><?php echo $product['quantity']; ?> units</td>
                                    <td><span class="report-badge badge-failed">Expired</span></td>
                                </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                            <?php if (($low_stock_products && $low_stock_products->num_rows == 0) && ($expired_products && $expired_products->num_rows == 0)): ?>
                                <tr>
                                    <td colspan="3" class="empty-state">
                                        <i class="fas fa-check-circle"></i>
                                        <p>No stock alerts</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
    </div>
  </div>
    <script src="/pos-system/assets/js/drugstore-sidebar-active.js"></script>
</body>
</html>
