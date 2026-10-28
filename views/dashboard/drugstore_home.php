<?php
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['selected_system'] !== 'drugstore') {
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

// Get dashboard statistics
$today = date('Y-m-d');

// Total sales today (from both agent and store tables)
$query = "SELECT SUM(amount) as total FROM agent WHERE DATE(sale_date) = :today";
$stmt = $db->prepare($query);
$stmt->execute([':today' => $today]);
$agent_today = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$query = "SELECT SUM(amount) as total FROM store WHERE DATE(sale_date) = :today";
$stmt = $db->prepare($query);
$stmt->execute([':today' => $today]);
$store_today = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$today_sales = $agent_today + $store_today;

// Total sales this month (from both tables)
$query = "SELECT SUM(amount) as total FROM agent WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())";
$stmt = $db->prepare($query);
$stmt->execute();
$agent_month = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$query = "SELECT SUM(amount) as total FROM store WHERE MONTH(sale_date) = MONTH(CURDATE()) AND YEAR(sale_date) = YEAR(CURDATE())";
$stmt = $db->prepare($query);
$stmt->execute();
$store_month = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$month_sales = $agent_month + $store_month;

// Total products (using inventory3 table)
$query = "SELECT COUNT(*) as total FROM inventory3";
$stmt = $db->prepare($query);
$stmt->execute();
$total_products = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Low stock products (quantity <= 10 AND quantity > 0)
$query = "SELECT COUNT(*) as total FROM inventory3 WHERE quantity <= 10 AND quantity > 0";
$stmt = $db->prepare($query);
$stmt->execute();
$low_stock = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Expired products
$query = "SELECT COUNT(*) as total FROM inventory3 WHERE expiry_date < :today AND expiry_date IS NOT NULL AND expiry_date != '0000-00-00'";
$stmt = $db->prepare($query);
$stmt->execute([':today' => $today]);
$expired = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Out of stock
$query = "SELECT COUNT(*) as total FROM inventory3 WHERE quantity = 0";
$stmt = $db->prepare($query);
$stmt->execute();
$out_of_stock = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Expiring soon (within 30 days)
$query = "SELECT COUNT(*) as total FROM inventory3 WHERE expiry_date BETWEEN :today AND DATE_ADD(:today, INTERVAL 30 DAY) AND quantity > 0 AND expiry_date IS NOT NULL AND expiry_date != '0000-00-00'";
$stmt = $db->prepare($query);
$stmt->execute([':today' => $today]);
$expiring_soon = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Recent sales (last 5 from both tables combined)
$agent_sales = $db->prepare("SELECT 
    CONCAT('AG-', id) as invoice_number,
    'wholesale' as sale_type,
    'Agent Sale' as customer_name,
    amount as grand_total,
    amount as amount_tendered,
    'cash' as payment_method,
    sale_date,
    'agent' as source
    FROM agent ORDER BY sale_date DESC LIMIT 5");
$agent_sales->execute();
$agent_recent = $agent_sales->fetchAll(PDO::FETCH_ASSOC);

$store_sales = $db->prepare("SELECT 
    CONCAT('ST-', id) as invoice_number,
    'retail' as sale_type,
    'Store Sale' as customer_name,
    amount as grand_total,
    amount as amount_tendered,
    'cash' as payment_method,
    sale_date,
    'store' as source
    FROM store ORDER BY sale_date DESC LIMIT 5");
$store_sales->execute();
$store_recent = $store_sales->fetchAll(PDO::FETCH_ASSOC);

// Combine and sort recent sales
$recent_sales = array_merge($agent_recent, $store_recent);
usort($recent_sales, function($a, $b) {
    return strtotime($b['sale_date']) - strtotime($a['sale_date']);
});
$recent_sales = array_slice($recent_sales, 0, 5);

// Top selling products (from inventory3, no sales items table, so show popular items based on quantity sold or just show some products)
// Since we don't have detailed sales items, we'll show products with lowest quantity as "popular" (selling fast)
$query = "SELECT brand_name, generic_name, quantity, store_price 
          FROM inventory3 
          WHERE quantity > 0 
          ORDER BY quantity ASC 
          LIMIT 5";
$stmt = $db->prepare($query);
$stmt->execute();
$top_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drugstore Dashboard - Home</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Sidebar Styles */
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
            margin-bottom: 10px;
        }

        .sidebar-header h3 {
            margin: 10px 0 5px;
            font-size: 20px;
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
            font-size: 18px;
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

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .logout-btn {
            background: #dc3545;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .stat-info h3 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #333;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 30px;
        }

        .stat-card.primary .stat-icon { background: #e3f2fd; color: #1976d2; }
        .stat-card.success .stat-icon { background: #e8f5e9; color: #388e3c; }
        .stat-card.warning .stat-icon { background: #fff3e0; color: #f57c00; }
        .stat-card.danger .stat-icon { background: #ffebee; color: #d32f2f; }
        .stat-card.info .stat-icon { background: #e0f7fa; color: #0097a7; }

        /* Charts Row */
        .charts-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .chart-card h3 {
            margin-bottom: 20px;
            color: #333;
        }

        canvas {
            max-height: 300px;
        }

        /* Recent Sales Table */
        .recent-sales {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            margin-bottom: 30px;
        }

        .recent-sales h3 {
            margin-bottom: 20px;
            color: #333;
        }

        table {
            width: 100%;
            border-collapse: collapse;
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
        }

        tr:hover {
            background: #f5f5f5;
        }

        /* Top Products */
        .top-products {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .product-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e0e0e0;
        }

        .product-item:last-child {
            border-bottom: none;
        }

        .product-name {
            font-weight: 500;
        }

        .product-stats {
            color: #666;
            font-size: 14px;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-success { background: #d4edda; color: #155724; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-danger { background: #f8d7da; color: #721c24; }
        .badge-info { background: #d1ecf1; color: #0c5460; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-pills"></i>
            <h3>Lhundren Drugstore</h3>
            <p>Pharmacy Management System</p>
        </div>
        <ul class="sidebar-menu">
            <li class="active"><a href="drugstore_home.php"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="drugstore_inventory.php"><i class="fas fa-boxes"></i> Inventory</a></li>
            <li><a href="drugstore_sales.php?type=retail"><i class="fas fa-store"></i> Retail Sales</a></li>
            <li><a href="drugstore_sales.php?type=wholesale"><i class="fas fa-truck"></i> Agent Sales</a></li>
            <li><a href="drugstore_expired.php"><i class="fas fa-calendar-times"></i> Expired List</a></li>
            <li><a href="drugstore_suppliers.php"><i class="fas fa-truck"></i> Suppliers</a></li>
            <li><a href="drugstore_staff.php"><i class="fas fa-users"></i> Staff</a></li>
            <li><a href="drugstore_history.php"><i class="fas fa-history"></i> Sale History</a></li>
            <li><a href="/pos-system/index.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h2>Dashboard Overview</h2>
                <p>Welcome back, <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?>! Here's what's happening today.</p>
            </div>
            <div class="user-info">
                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></span>
                <a href="/pos-system/index.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-info">
                    <h3>Today's Sales</h3>
                    <div class="stat-number">₱<?php echo number_format($today_sales, 2); ?></div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
            <div class="stat-card success">
                <div class="stat-info">
                    <h3>Monthly Sales</h3>
                    <div class="stat-number">₱<?php echo number_format($month_sales, 2); ?></div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
            </div>
            <div class="stat-card info">
                <div class="stat-info">
                    <h3>Total Products</h3>
                    <div class="stat-number"><?php echo $total_products; ?></div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-capsules"></i>
                </div>
            </div>
            <div class="stat-card warning">
                <div class="stat-info">
                    <h3>Low Stock Items</h3>
                    <div class="stat-number"><?php echo $low_stock; ?></div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
            </div>
            <div class="stat-card danger">
                <div class="stat-info">
                    <h3>Expired Products</h3>
                    <div class="stat-number"><?php echo $expired; ?></div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-skull-crossbones"></i>
                </div>
            </div>
            <div class="stat-card warning">
                <div class="stat-info">
                    <h3>Expiring Soon (30 days)</h3>
                    <div class="stat-number"><?php echo $expiring_soon; ?></div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-hourglass-half"></i>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="charts-row">
            <div class="chart-card">
                <h3>Sales Overview (Last 7 Days)</h3>
                <canvas id="weeklySalesChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>Inventory Status</h3>
                <canvas id="inventoryStatusChart"></canvas>
            </div>
        </div>

        <!-- Recent Sales -->
        <div class="recent-sales">
            <h3><i class="fas fa-clock"></i> Recent Transactions</h3>
            <table>
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Date & Time</th>
                        <th>Type</th>
                        <th>Total</th>
                        <th>Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_sales as $sale): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($sale['invoice_number']); ?></td>
                        <td><?php echo date('M d, Y h:i A', strtotime($sale['sale_date'])); ?></td>
                        <td><span class="badge <?php echo $sale['sale_type'] == 'retail' ? 'badge-success' : 'badge-info'; ?>"><?php echo ucfirst($sale['sale_type']); ?></span></td>
                        <td>₱<?php echo number_format($sale['grand_total'], 2); ?></td>
                        <td><?php echo ucfirst($sale['payment_method']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recent_sales)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center;">No recent sales found.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Top Products (Fast Moving / Low Stock) -->
        <div class="top-products">
            <h3><i class="fas fa-fire"></i> Fast Moving Products (Low Stock Alert)</h3>
            <?php foreach ($top_products as $product): ?>
            <div class="product-item">
                <div>
                    <div class="product-name"><?php echo htmlspecialchars($product['brand_name']); ?></div>
                    <div class="product-stats"><?php echo htmlspecialchars($product['generic_name']); ?></div>
                </div>
                <div class="product-stats">
                    Remaining: <?php echo $product['quantity']; ?> units<br>
                    Price: ₱<?php echo number_format($product['store_price'], 2); ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($top_products)): ?>
            <p style="text-align: center; padding: 20px;">No product data available.</p>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Weekly Sales Chart (combining agent and store sales)
        <?php
        $weekly_sales = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            
            // Get agent sales for this day
            $query = "SELECT SUM(amount) as total FROM agent WHERE DATE(sale_date) = :date";
            $stmt = $db->prepare($query);
            $stmt->execute([':date' => $date]);
            $agent_total = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            
            // Get store sales for this day
            $query = "SELECT SUM(amount) as total FROM store WHERE DATE(sale_date) = :date";
            $stmt = $db->prepare($query);
            $stmt->execute([':date' => $date]);
            $store_total = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
            
            $weekly_sales[] = $agent_total + $store_total;
        }
        ?>
        
        const weeklyCtx = document.getElementById('weeklySalesChart').getContext('2d');
        new Chart(weeklyCtx, {
            type: 'line',
            data: {
                labels: ['7 days ago', '6 days ago', '5 days ago', '4 days ago', '3 days ago', 'Yesterday', 'Today'],
                datasets: [{
                    label: 'Sales (₱)',
                    data: <?php echo json_encode($weekly_sales); ?>,
                    borderColor: '#1976d2',
                    backgroundColor: 'rgba(25, 118, 210, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                }
            }
        });
        
        // Inventory Status Chart
        const inventoryCtx = document.getElementById('inventoryStatusChart').getContext('2d');
        new Chart(inventoryCtx, {
            type: 'doughnut',
            data: {
                labels: ['In Stock', 'Low Stock', 'Out of Stock', 'Expired'],
                datasets: [{
                    data: [
                        <?php echo max(0, $total_products - ($low_stock + $out_of_stock + $expired)); ?>,
                        <?php echo $low_stock; ?>,
                        <?php echo $out_of_stock; ?>,
                        <?php echo $expired; ?>
                    ],
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545', '#6c757d'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                    }
                }
            }
        });
        
        // Sidebar active state initialization
        document.addEventListener('DOMContentLoaded', function() {
            var currentPath = window.location.pathname.split("/").pop().split("#")[0];
            var currentFile = currentPath.split("?")[0];
            
            document.querySelectorAll('.sidebar-menu li a').forEach(function(link) {
                var href = link.getAttribute('href');
                if (!href) return;
                
                var clean = href.split("/").pop().split("#")[0];
                var cleanFile = clean.split("?")[0];
                
                if (cleanFile === currentFile) {
                    link.closest('li').classList.add('active');
                } else {
                    link.closest('li').classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>