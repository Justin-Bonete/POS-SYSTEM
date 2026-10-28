<?php
require_once __DIR__ . '/_bootstrap.php';

$conn = schoolStoreConnect();

// Get statistics
$stats = $conn->query("SELECT 
    COUNT(*) as total_products,
    SUM(quantity) as total_quantity,
    SUM(quantity * capital_price) as total_inventory_value,
    SUM(quantity * retail_price) as total_retail_value,
    SUM(quantity * wholesale_price) as total_wholesale_value,
    AVG(quantity) as avg_quantity
FROM school_inventory");
$stats_data = $stats->fetch_assoc();

// Get low stock items (less than 10)
$low_stock = $conn->query("SELECT * FROM school_inventory WHERE quantity < 10 ORDER BY quantity ASC LIMIT 5");

// Get top products by stock value
$top_products = $conn->query("SELECT product_name, quantity, retail_price, (quantity * retail_price) as stock_value 
                              FROM school_inventory 
                              ORDER BY stock_value DESC 
                              LIMIT 5");

// Get product category distribution
$categories = $conn->query("SELECT 
    CASE 
        WHEN product_name LIKE '%ballpen%' OR product_name LIKE '%pen%' OR product_name LIKE '%marker%' THEN 'Writing Instruments'
        WHEN product_name LIKE '%paper%' OR product_name LIKE '%folder%' OR product_name LIKE '%envelope%' OR product_name LIKE '%notebook%' OR product_name LIKE '%pad%' THEN 'Paper Products'
        WHEN product_name LIKE '%tape%' OR product_name LIKE '%glue%' OR product_name LIKE '%staple%' OR product_name LIKE '%puncher%' THEN 'Office Supplies'
        WHEN product_name LIKE '%pencil%' OR product_name LIKE '%crayon%' OR product_name LIKE '%color%' OR product_name LIKE '%paint%' THEN 'Art Supplies'
        WHEN product_name LIKE '%eraser%' OR product_name LIKE '%sharpener%' OR product_name LIKE '%case%' THEN 'School Essentials'
        ELSE 'Others'
    END as category,
    COUNT(*) as count
FROM school_inventory 
GROUP BY category
ORDER BY count DESC");

// Get recent items (last 10 added)
$recent_items = $conn->query("SELECT * FROM school_inventory ORDER BY created_at DESC LIMIT 10");

// Check for night mode cookie
$night_mode = isset($_COOKIE['night_mode']) && $_COOKIE['night_mode'] == 'enabled';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | SchoolStore Pro</title>
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
            --welcome-bg: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.9) 100%);
            --stat-card-bg: white;
            --chart-card-bg: white;
            --table-header-bg: #f8fafc;
            --table-border: #e2e8f0;
            --footer-bg: white;
            --badge-danger-bg: #fee2e2;
            --badge-danger-text: #ef4444;
            --badge-warning-bg: #fef3c7;
            --badge-warning-text: #f59e0b;
            --badge-success-bg: #d1fae5;
            --badge-success-text: #10b981;
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
            --welcome-bg: linear-gradient(135deg, #1e1e2e 0%, #2d2d3a 100%);
            --stat-card-bg: #1e1e2e;
            --chart-card-bg: #1e1e2e;
            --table-header-bg: #2d2d3a;
            --table-border: #3a3a4a;
            --footer-bg: #1a1a2e;
            --badge-danger-bg: #7f1a1a;
            --badge-danger-text: #fca5a5;
            --badge-warning-bg: #78350f;
            --badge-warning-text: #fcd34d;
            --badge-success-bg: #064e3b;
            --badge-success-text: #6ee7b7;
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

        .date-time {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .date-time span {
            color: var(--text-secondary);
            font-size: 14px;
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

        .logout-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #667eea;
            text-decoration: none;
            font-size: 16px;
            font-weight: 600;
            padding: 8px 12px;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: rgba(102, 126, 234, 0.1);
            transform: translateY(-1px);
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

        /* Welcome Section */
        .welcome-section {
            background: var(--welcome-bg);
            border-radius: 20px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .welcome-section h2 {
            font-size: 28px;
            color: var(--text-primary);
            margin-bottom: 10px;
        }

        .welcome-section p {
            color: var(--text-muted);
            font-size: 16px;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--stat-card-bg);
            border-radius: 20px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            position: relative;
            overflow: hidden;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .stat-card .stat-icon {
            font-size: 40px;
            color: #667eea;
            margin-bottom: 15px;
        }

        .stat-card h3 {
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
        }

        .stat-card .value {
            font-size: 32px;
            font-weight: bold;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .stat-card .sub-value {
            color: var(--text-muted);
            font-size: 12px;
        }

        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            background: var(--chart-card-bg);
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .chart-card h3 {
            color: var(--text-primary);
            margin-bottom: 20px;
            font-size: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        canvas {
            max-height: 300px;
        }

        /* Tables */
        .table-container {
            background: var(--card-bg);
            border-radius: 20px;
            overflow-x: auto;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .table-header {
            padding: 20px;
            border-bottom: 2px solid var(--table-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .table-header h3 {
            color: var(--text-primary);
            font-size: 18px;
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
        }

        table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--table-border);
            color: var(--text-primary);
        }

        table tr:hover {
            background: var(--table-header-bg);
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-danger {
            background: var(--badge-danger-bg);
            color: var(--badge-danger-text);
        }

        .badge-warning {
            background: var(--badge-warning-bg);
            color: var(--badge-warning-text);
        }

        .badge-success {
            background: var(--badge-success-bg);
            color: var(--badge-success-text);
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
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="<?php echo $night_mode ? 'night-mode' : ''; ?>">

<header>
    <h1><i class="fas fa-graduation-cap"></i> SchoolStore Pro</h1>
    <div class="header-actions">
        <div class="date-time">
            <span id="currentDate"></span>
            <span id="currentTime"></span>
        </div>
        <button class="night-mode-toggle" onclick="toggleNightMode()" title="Toggle Night Mode">
            <i class="fas <?php echo $night_mode ? 'fa-sun' : 'fa-moon'; ?>"></i>
        </button>
        <i class="fas fa-bell" style="font-size: 20px; color: #667eea;"></i>
        <a href="/pos-system/index.php" class="logout-btn" title="Logout">
            <i class="fas fa-sign-out-alt"></i>
            <span>Logout</span>
        </a>
    </div>
</header>

<div class="main">
    <div class="sidebar" id="sidebar">
        <div class="toggle-btn" onclick="toggleSidebar()">
            <i class="fas fa-chevron-left"></i>
        </div>
        <ul>
            <li><a href="home.php" class="active"><i class="fas fa-home"></i><span> Dashboard</span></a></li>
            <li><a href="inventory.php"><i class="fas fa-boxes"></i><span> Inventory</span></a></li>
            <li><a href="wholesale.php"><i class="fas fa-shopping-cart"></i><span> Wholesale</span></a></li>
            <li><a href="retail.php"><i class="fas fa-store"></i><span> Retail</span></a></li>
            <li><a href="histo.php"><i class="fas fa-history"></i><span> Sale History</span></a></li>
        </ul>
    </div>

    <div class="content">
        <!-- Welcome Section -->
        <div class="welcome-section">
            <h2>Welcome back, Admin! 👋</h2>
            <p>Here's what's happening with your inventory today.</p>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-boxes"></i></div>
                <h3>Total Products</h3>
                <div class="value"><?= number_format($stats_data['total_products'] ?? 0) ?></div>
                <div class="sub-value">Unique items in inventory</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-cubes"></i></div>
                <h3>Total Quantity</h3>
                <div class="value"><?= number_format($stats_data['total_quantity'] ?? 0) ?></div>
                <div class="sub-value">Items in stock</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
                <h3>Inventory Value (Cost)</h3>
                <div class="value">₱ <?= number_format($stats_data['total_inventory_value'] ?? 0, 2) ?></div>
                <div class="sub-value">Total capital investment</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-tags"></i></div>
                <h3>Retail Value</h3>
                <div class="value">₱ <?= number_format($stats_data['total_retail_value'] ?? 0, 2) ?></div>
                <div class="sub-value">Potential revenue</div>
            </div>
        </div>

        <!-- Charts -->
        <div class="charts-grid">
            <div class="chart-card">
                <h3><i class="fas fa-chart-pie"></i> Product Categories</h3>
                <canvas id="categoryChart"></canvas>
            </div>
            <div class="chart-card">
                <h3><i class="fas fa-chart-bar"></i> Top Products by Value</h3>
                <canvas id="topProductsChart"></canvas>
            </div>
        </div>

        <!-- Low Stock Alert -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-exclamation-triangle"></i> Low Stock Alert</h3>
                <span class="badge badge-danger">Critical: Less than 10 units</span>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Barcode</th>
                        <th>Product Name</th>
                        <th>Current Stock</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($low_stock->num_rows > 0): ?>
                        <?php while($row = $low_stock->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['barcode']) ?></td>
                            <td><?= htmlspecialchars($row['product_name']) ?></td>
                            <td><?= $row['quantity'] ?></td>
                            <td>
                                <?php if ($row['quantity'] <= 5): ?>
                                    <span class="badge badge-danger">Critical</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Low Stock</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center;">No low stock items found</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Recent Items -->
        <div class="table-container">
            <div class="table-header">
                <h3><i class="fas fa-clock"></i> Recently Added Items</h3>
                <a href="inventory.php" style="color: #667eea; text-decoration: none;">View All →</a>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Barcode</th>
                        <th>Product Name</th>
                        <th>Quantity</th>
                        <th>Retail Price</th>
                        <th>Added Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $recent_items->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['barcode']) ?></td>
                        <td><?= htmlspecialchars($row['product_name']) ?></td>
                        <td><?= $row['quantity'] ?></td>
                        <td>₱ <?= number_format($row['retail_price'], 2) ?></td>
                        <td><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<footer>
    <p>© 2024 SchoolStore Pro | Smart Inventory Management System</p>
</footer>

<script>
// Sidebar Toggle
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

// Date and Time
function updateDateTime() {
    const now = new Date();
    const options = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    document.getElementById('currentDate').innerHTML = now.toLocaleDateString('en-US', options);
    document.getElementById('currentTime').innerHTML = now.toLocaleTimeString('en-US');
}
updateDateTime();
setInterval(updateDateTime, 1000);

// Category Chart
<?php
$categories_data = [];
$categories_count = [];
while($row = $categories->fetch_assoc()) {
    $categories_data[] = $row['category'];
    $categories_count[] = $row['count'];
}
?>

const categoryCtx = document.getElementById('categoryChart').getContext('2d');
new Chart(categoryCtx, {
    type: 'pie',
    data: {
        labels: <?= json_encode($categories_data) ?>,
        datasets: [{
            data: <?= json_encode($categories_count) ?>,
            backgroundColor: [
                '#667eea',
                '#764ba2',
                '#f59e0b',
                '#10b981',
                '#ef4444',
                '#8b5cf6'
            ],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    color: getComputedStyle(document.body).getPropertyValue('--text-primary').trim() || '#1e293b'
                }
            }
        }
    }
});

// Top Products Chart
<?php
$top_products_data = [];
$top_products_values = [];
while($row = $top_products->fetch_assoc()) {
    $top_products_data[] = $row['product_name'];
    $top_products_values[] = $row['stock_value'];
}
?>

const topCtx = document.getElementById('topProductsChart').getContext('2d');
new Chart(topCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($top_products_data) ?>,
        datasets: [{
            label: 'Stock Value (₱)',
            data: <?= json_encode($top_products_values) ?>,
            backgroundColor: 'rgba(102, 126, 234, 0.7)',
            borderColor: '#667eea',
            borderWidth: 1,
            borderRadius: 8
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '₱' + value.toLocaleString();
                    },
                    color: getComputedStyle(document.body).getPropertyValue('--text-primary').trim() || '#1e293b'
                },
                grid: {
                    color: getComputedStyle(document.body).getPropertyValue('--table-border').trim() || '#e2e8f0'
                }
            },
            x: {
                ticks: {
                    color: getComputedStyle(document.body).getPropertyValue('--text-primary').trim() || '#1e293b'
                },
                grid: {
                    color: getComputedStyle(document.body).getPropertyValue('--table-border').trim() || '#e2e8f0'
                }
            }
        },
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return '₱' + context.parsed.y.toLocaleString();
                    }
                }
            },
            legend: {
                labels: {
                    color: getComputedStyle(document.body).getPropertyValue('--text-primary').trim() || '#1e293b'
                }
            }
        }
    }
});
</script>
</body>
</html>
