<?php
// views/products/analytics.php
$pageTitle = 'Product Analytics';
$pageScripts = ['product-analytics.js'];

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../classes/Sale.php';

requireAuth();

$product = new Product();
$sale = new Sale();
$db = getDB();

// Get date range from request or default to last 30 days
$endDate = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$startDate = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$compareWith = isset($_GET['compare']) ? $_GET['compare'] : 'previous_period';

// Get all products for selection
$allProducts = $product->getAll(['status' => 'active'], 1, 1000);

// Get product categories
$categoriesQuery = "SELECT category, COUNT(*) as count, SUM(quantity) as total_stock 
                   FROM products WHERE status = 'active' GROUP BY category";
$categoriesStmt = $db->prepare($categoriesQuery);
$categoriesStmt->execute();
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

// Get top selling products
$topProductsQuery = "SELECT 
                        p.id,
                        p.name,
                        p.product_code,
                        p.price,
                        p.quantity as current_stock,
                        COUNT(si.id) as times_sold,
                        SUM(si.quantity) as total_quantity_sold,
                        SUM(si.subtotal) as total_revenue
                     FROM products p
                     LEFT JOIN sale_items si ON p.id = si.product_id
                     LEFT JOIN sales s ON si.sale_id = s.id 
                        AND s.created_at BETWEEN :start_date AND :end_date
                        AND s.status = 'completed'
                     WHERE p.status = 'active'
                     GROUP BY p.id
                     ORDER BY total_quantity_sold DESC
                     LIMIT 10";
$topStmt = $db->prepare($topProductsQuery);
$topStmt->bindValue(':start_date', $startDate . ' 00:00:00');
$topStmt->bindValue(':end_date', $endDate . ' 23:59:59');
$topStmt->execute();
$topProducts = $topStmt->fetchAll(PDO::FETCH_ASSOC);

// Get slow moving products (low sales)
$slowProductsQuery = "SELECT 
                        p.id,
                        p.name,
                        p.product_code,
                        p.price,
                        p.quantity as current_stock,
                        COALESCE(SUM(si.quantity), 0) as total_quantity_sold
                     FROM products p
                     LEFT JOIN sale_items si ON p.id = si.product_id
                     LEFT JOIN sales s ON si.sale_id = s.id 
                        AND s.created_at BETWEEN :start_date AND :end_date
                        AND s.status = 'completed'
                     WHERE p.status = 'active'
                     GROUP BY p.id
                     HAVING total_quantity_sold = 0 OR total_quantity_sold < 5
                     ORDER BY total_quantity_sold ASC, p.quantity DESC
                     LIMIT 10";
$slowStmt = $db->prepare($slowProductsQuery);
$slowStmt->bindValue(':start_date', $startDate . ' 00:00:00');
$slowStmt->bindValue(':end_date', $endDate . ' 23:59:59');
$slowStmt->execute();
$slowProducts = $slowStmt->fetchAll(PDO::FETCH_ASSOC);

// Get daily sales trend
$dailyTrendQuery = "SELECT 
                        DATE(s.created_at) as date,
                        COUNT(DISTINCT s.id) as transactions,
                        SUM(si.quantity) as items_sold,
                        SUM(s.total_amount) as revenue
                    FROM sales s
                    JOIN sale_items si ON s.id = si.sale_id
                    WHERE s.status = 'completed'
                        AND DATE(s.created_at) BETWEEN :start_date AND :end_date
                    GROUP BY DATE(s.created_at)
                    ORDER BY date ASC";
$trendStmt = $db->prepare($dailyTrendQuery);
$trendStmt->bindValue(':start_date', $startDate);
$trendStmt->bindValue(':end_date', $endDate);
$trendStmt->execute();
$dailyTrend = $trendStmt->fetchAll(PDO::FETCH_ASSOC);

// Get inventory value by category
$inventoryValueQuery = "SELECT 
                            category,
                            COUNT(*) as product_count,
                            SUM(quantity) as total_units,
                            SUM(price * quantity) as total_value
                        FROM products
                        WHERE status = 'active'
                        GROUP BY category
                        ORDER BY total_value DESC";
$valueStmt = $db->prepare($inventoryValueQuery);
$valueStmt->execute();
$inventoryValue = $valueStmt->fetchAll(PDO::FETCH_ASSOC);

// Get stock alerts
$stockAlertsQuery = "SELECT 
                        COUNT(*) as total_alerts,
                        SUM(CASE WHEN quantity <= 5 THEN 1 ELSE 0 END) as critical,
                        SUM(CASE WHEN quantity > 5 AND quantity <= 10 THEN 1 ELSE 0 END) as warning
                     FROM products
                     WHERE status = 'active' AND quantity <= 10";
$alertsStmt = $db->prepare($stockAlertsQuery);
$alertsStmt->execute();
$stockAlerts = $alertsStmt->fetch(PDO::FETCH_ASSOC);

// Get category performance
$categoryPerformanceQuery = "SELECT 
                                p.category,
                                COUNT(DISTINCT p.id) as products,
                                COUNT(DISTINCT s.id) as transactions,
                                SUM(si.quantity) as units_sold,
                                SUM(si.subtotal) as revenue
                             FROM products p
                             LEFT JOIN sale_items si ON p.id = si.product_id
                             LEFT JOIN sales s ON si.sale_id = s.id 
                                AND s.status = 'completed'
                                AND DATE(s.created_at) BETWEEN :start_date AND :end_date
                             WHERE p.status = 'active'
                             GROUP BY p.category
                             ORDER BY revenue DESC";
$categoryStmt = $db->prepare($categoryPerformanceQuery);
$categoryStmt->bindValue(':start_date', $startDate);
$categoryStmt->bindValue(':end_date', $endDate);
$categoryStmt->execute();
$categoryPerformance = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totalRevenue = array_sum(array_column($dailyTrend, 'revenue'));
$totalItems = array_sum(array_column($dailyTrend, 'items_sold'));
$totalTransactions = array_sum(array_column($dailyTrend, 'transactions'));
$avgTransactionValue = $totalTransactions > 0 ? $totalRevenue / $totalTransactions : 0;
$avgItemsPerTransaction = $totalTransactions > 0 ? $totalItems / $totalTransactions : 0;

// Get inventory summary
$inventorySummaryQuery = "SELECT 
                            COUNT(*) as total_products,
                            SUM(quantity) as total_stock,
                            SUM(price * quantity) as total_value,
                            AVG(price) as avg_price
                          FROM products WHERE status = 'active'";
$inventoryStmt = $db->prepare($inventorySummaryQuery);
$inventoryStmt->execute();
$inventorySummary = $inventoryStmt->fetch(PDO::FETCH_ASSOC);

// Get previous period for comparison
$previousStart = date('Y-m-d', strtotime($startDate . ' -' . (strtotime($endDate) - strtotime($startDate)) / (60*60*24) . ' days'));
$previousEnd = date('Y-m-d', strtotime($startDate . ' -1 day'));

$previousQuery = "SELECT 
                    COUNT(DISTINCT s.id) as transactions,
                    SUM(si.quantity) as items_sold,
                    SUM(s.total_amount) as revenue
                  FROM sales s
                  JOIN sale_items si ON s.id = si.sale_id
                  WHERE s.status = 'completed'
                    AND DATE(s.created_at) BETWEEN :start_date AND :end_date";
$prevStmt = $db->prepare($previousQuery);
$prevStmt->bindValue(':start_date', $previousStart);
$prevStmt->bindValue(':end_date', $previousEnd);
$prevStmt->execute();
$previousData = $prevStmt->fetch(PDO::FETCH_ASSOC);

$prevRevenue = $previousData['revenue'] ?? 0;
$prevItems = $previousData['items_sold'] ?? 0;
$prevTransactions = $previousData['transactions'] ?? 0;

// Calculate changes
$revenueChange = $prevRevenue > 0 ? (($totalRevenue - $prevRevenue) / $prevRevenue) * 100 : 0;
$itemsChange = $prevItems > 0 ? (($totalItems - $prevItems) / $prevItems) * 100 : 0;
$transactionsChange = $prevTransactions > 0 ? (($totalTransactions - $prevTransactions) / $prevTransactions) * 100 : 0;
?>

<?php include __DIR__ . '/../layouts/header.php'; ?>

<div class="analytics-container">
    <!-- Header -->
    <div class="page-header">
        <h1><i class="fas fa-chart-pie"></i> Product Analytics</h1>
        <div class="header-actions">
            <button class="btn btn-primary" onclick="exportAnalytics()">
                <i class="fas fa-download"></i> Export Report
            </button>
            <button class="btn btn-secondary" onclick="printAnalytics()">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    <!-- Date Range Filter -->
    <div class="filter-section card">
        <div class="card-header">
            <h3><i class="fas fa-calendar-alt"></i> Date Range</h3>
        </div>
        <div class="card-body">
            <form method="GET" class="date-filter-form" id="analyticsForm">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="start_date">Start Date</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo $startDate; ?>">
                    </div>
                    <div class="filter-group">
                        <label for="end_date">End Date</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo $endDate; ?>">
                    </div>
                    <div class="filter-group">
                        <label for="compare">Compare with</label>
                        <select id="compare" name="compare" class="form-control">
                            <option value="previous_period" <?php echo $compareWith == 'previous_period' ? 'selected' : ''; ?>>Previous Period</option>
                            <option value="previous_year" <?php echo $compareWith == 'previous_year' ? 'selected' : ''; ?>>Same Period Last Year</option>
                            <option value="none" <?php echo $compareWith == 'none' ? 'selected' : ''; ?>>No Comparison</option>
                        </select>
                    </div>
                    <div class="filter-group filter-actions">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-sync-alt"></i> Update
                        </button>
                        <a href="/pos-system/views/products/analytics.php" class="btn btn-secondary">
                            <i class="fas fa-redo"></i> Reset
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Key Metrics -->
    <div class="metrics-grid">
        <div class="metric-card primary">
            <div class="metric-icon">
                <i class="fas fa-dollar-sign"></i>
            </div>
            <div class="metric-content">
                <span class="metric-label">Total Revenue</span>
                <span class="metric-value"><?php echo formatCurrency($totalRevenue); ?></span>
                <span class="metric-change <?php echo $revenueChange >= 0 ? 'positive' : 'negative'; ?>">
                    <i class="fas fa-<?php echo $revenueChange >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                    <?php echo number_format(abs($revenueChange), 1); ?>%
                </span>
            </div>
        </div>

        <div class="metric-card success">
            <div class="metric-icon">
                <i class="fas fa-shopping-cart"></i>
            </div>
            <div class="metric-content">
                <span class="metric-label">Items Sold</span>
                <span class="metric-value"><?php echo number_format($totalItems); ?></span>
                <span class="metric-change <?php echo $itemsChange >= 0 ? 'positive' : 'negative'; ?>">
                    <i class="fas fa-<?php echo $itemsChange >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                    <?php echo number_format(abs($itemsChange), 1); ?>%
                </span>
            </div>
        </div>

        <div class="metric-card info">
            <div class="metric-icon">
                <i class="fas fa-exchange-alt"></i>
            </div>
            <div class="metric-content">
                <span class="metric-label">Transactions</span>
                <span class="metric-value"><?php echo number_format($totalTransactions); ?></span>
                <span class="metric-change <?php echo $transactionsChange >= 0 ? 'positive' : 'negative'; ?>">
                    <i class="fas fa-<?php echo $transactionsChange >= 0 ? 'arrow-up' : 'arrow-down'; ?>"></i>
                    <?php echo number_format(abs($transactionsChange), 1); ?>%
                </span>
            </div>
        </div>

        <div class="metric-card warning">
            <div class="metric-icon">
                <i class="fas fa-cubes"></i>
            </div>
            <div class="metric-content">
                <span class="metric-label">Inventory Value</span>
                <span class="metric-value"><?php echo formatCurrency($inventorySummary['total_value'] ?? 0); ?></span>
                <span class="metric-sub"><?php echo number_format($inventorySummary['total_products'] ?? 0); ?> products</span>
            </div>
        </div>
    </div>

    <!-- Charts Row 1 -->
    <div class="charts-row">
        <div class="chart-card">
            <div class="chart-header">
                <h3>Daily Sales Trend</h3>
                <div class="chart-legend">
                    <span><i class="fas fa-circle" style="color: #4e73df;"></i> Revenue</span>
                    <span><i class="fas fa-circle" style="color: #1cc88a;"></i> Items</span>
                </div>
            </div>
            <div class="chart-body">
                <canvas id="salesTrendChart" style="height: 300px;"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <h3>Category Distribution</h3>
                <div class="chart-legend">
                    <span><i class="fas fa-circle" style="color: #4e73df;"></i> Products</span>
                    <span><i class="fas fa-circle" style="color: #1cc88a;"></i> Value</span>
                </div>
            </div>
            <div class="chart-body">
                <canvas id="categoryChart" style="height: 300px;"></canvas>
            </div>
        </div>
    </div>

    <!-- Charts Row 2 -->
    <div class="charts-row">
        <div class="chart-card">
            <div class="chart-header">
                <h3>Top Selling Products</h3>
            </div>
            <div class="chart-body">
                <canvas id="topProductsChart" style="height: 300px;"></canvas>
            </div>
        </div>

        <div class="chart-card">
            <div class="chart-header">
                <h3>Inventory Status</h3>
            </div>
            <div class="chart-body">
                <canvas id="inventoryChart" style="height: 300px;"></canvas>
            </div>
        </div>
    </div>

    <!-- Tables Section -->
    <div class="tables-row">
        <!-- Top Products Table -->
        <div class="table-card">
            <div class="card-header">
                <h3><i class="fas fa-trophy"></i> Top 10 Products</h3>
                <span class="badge badge-success">Best Sellers</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table analytics-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Code</th>
                                <th>Price</th>
                                <th>Sold</th>
                                <th>Revenue</th>
                                <th>Stock</th>
                                <th>Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topProducts as $index => $product): ?>
                            <tr>
                                <td>
                                    <div class="product-info">
                                        <span class="rank">#<?php echo $index + 1; ?></span>
                                        <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($product['product_code']); ?></td>
                                <td><?php echo formatCurrency($product['price']); ?></td>
                                <td><?php echo number_format($product['total_quantity_sold'] ?? 0); ?></td>
                                <td><?php echo formatCurrency($product['total_revenue'] ?? 0); ?></td>
                                <td>
                                    <span class="stock-badge <?php echo $product['current_stock'] <= 10 ? 'stock-low' : 'stock-ok'; ?>">
                                        <?php echo $product['current_stock']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $sellRate = $product['total_quantity_sold'] > 0 ? 
                                        ($product['total_quantity_sold'] / $totalItems) * 100 : 0;
                                    ?>
                                    <div class="progress-bar">
                                        <div class="progress-fill" style="width: <?php echo $sellRate; ?>%"></div>
                                        <span><?php echo number_format($sellRate, 1); ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Slow Moving Products -->
        <div class="table-card">
            <div class="card-header">
                <h3><i class="fas fa-turtle"></i> Slow Moving Products</h3>
                <span class="badge badge-warning">Needs Attention</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table analytics-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Code</th>
                                <th>Price</th>
                                <th>Current Stock</th>
                                <th>Units Sold</th>
                                <th>Days of Stock</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($slowProducts as $product): ?>
                            <?php 
                                $dailySellRate = $totalItems > 0 ? $totalItems / count($dailyTrend) : 0;
                                $daysOfStock = $dailySellRate > 0 ? 
                                    floor($product['current_stock'] / $dailySellRate) : 999;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td><?php echo htmlspecialchars($product['product_code']); ?></td>
                                <td><?php echo formatCurrency($product['price']); ?></td>
                                <td><?php echo $product['current_stock']; ?></td>
                                <td><?php echo $product['total_quantity_sold'] ?? 0; ?></td>
                                <td>
                                    <?php if ($daysOfStock > 90): ?>
                                        <span class="badge badge-danger"><?php echo $daysOfStock; ?> days</span>
                                    <?php elseif ($daysOfStock > 30): ?>
                                        <span class="badge badge-warning"><?php echo $daysOfStock; ?> days</span>
                                    <?php else: ?>
                                        <span class="badge badge-success"><?php echo $daysOfStock; ?> days</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-primary" onclick="promoteProduct(<?php echo $product['id']; ?>)">
                                        <i class="fas fa-bullhorn"></i> Promote
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Category Performance Table -->
    <div class="table-card full-width">
        <div class="card-header">
            <h3><i class="fas fa-tags"></i> Category Performance</h3>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table category-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Products</th>
                            <th>Transactions</th>
                            <th>Units Sold</th>
                            <th>Revenue</th>
                            <th>Avg Price</th>
                            <th>Contribution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $totalCatRevenue = array_sum(array_column($categoryPerformance, 'revenue'));
                        foreach ($categoryPerformance as $category): 
                            $contribution = $totalCatRevenue > 0 ? 
                                ($category['revenue'] / $totalCatRevenue) * 100 : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($category['category'] ?: 'Uncategorized'); ?></strong></td>
                            <td><?php echo $category['products']; ?></td>
                            <td><?php echo number_format($category['transactions']); ?></td>
                            <td><?php echo number_format($category['units_sold']); ?></td>
                            <td><?php echo formatCurrency($category['revenue']); ?></td>
                            <td><?php echo formatCurrency($category['units_sold'] > 0 ? $category['revenue'] / $category['units_sold'] : 0); ?></td>
                            <td>
                                <div class="progress-bar contribution-bar">
                                    <div class="progress-fill" style="width: <?php echo $contribution; ?>%"></div>
                                    <span><?php echo number_format($contribution, 1); ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Inventory Value by Category -->
    <div class="inventory-grid">
        <div class="inventory-card">
            <h4>Inventory Summary</h4>
            <div class="summary-stats">
                <div class="summary-item">
                    <span class="summary-label">Total Products:</span>
                    <span class="summary-value"><?php echo number_format($inventorySummary['total_products'] ?? 0); ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Total Stock Units:</span>
                    <span class="summary-value"><?php echo number_format($inventorySummary['total_stock'] ?? 0); ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Average Price:</span>
                    <span class="summary-value"><?php echo formatCurrency($inventorySummary['avg_price'] ?? 0); ?></span>
                </div>
                <div class="summary-item highlight">
                    <span class="summary-label">Total Inventory Value:</span>
                    <span class="summary-value"><?php echo formatCurrency($inventorySummary['total_value'] ?? 0); ?></span>
                </div>
            </div>
        </div>

        <div class="inventory-card">
            <h4>Stock Alerts</h4>
            <div class="alert-stats">
                <div class="alert-item critical">
                    <i class="fas fa-exclamation-triangle"></i>
                    <div>
                        <span class="alert-count"><?php echo $stockAlerts['critical'] ?? 0; ?></span>
                        <span class="alert-label">Critical (≤5 units)</span>
                    </div>
                </div>
                <div class="alert-item warning">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <span class="alert-count"><?php echo $stockAlerts['warning'] ?? 0; ?></span>
                        <span class="alert-label">Warning (≤10 units)</span>
                    </div>
                </div>
                <div class="alert-item success">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <span class="alert-count"><?php echo ($inventorySummary['total_products'] ?? 0) - ($stockAlerts['critical'] ?? 0) - ($stockAlerts['warning'] ?? 0); ?></span>
                        <span class="alert-label">Healthy Stock</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Charts JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Daily Sales Trend Chart
const trendCtx = document.getElementById('salesTrendChart').getContext('2d');
const trendData = <?php echo json_encode($dailyTrend); ?>;

new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: trendData.map(item => new Date(item.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })),
        datasets: [{
            label: 'Revenue',
            data: trendData.map(item => item.revenue),
            borderColor: '#4e73df',
            backgroundColor: 'rgba(78, 115, 223, 0.05)',
            tension: 0.4,
            fill: true,
            yAxisID: 'y-revenue'
        }, {
            label: 'Items Sold',
            data: trendData.map(item => item.items_sold),
            borderColor: '#1cc88a',
            backgroundColor: 'rgba(28, 200, 138, 0.05)',
            tension: 0.4,
            fill: true,
            yAxisID: 'y-items'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            mode: 'index',
            intersect: false
        },
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            'y-revenue': {
                type: 'linear',
                display: true,
                position: 'left',
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '$' + value.toLocaleString();
                    }
                }
            },
            'y-items': {
                type: 'linear',
                display: true,
                position: 'right',
                beginAtZero: true,
                grid: {
                    drawOnChartArea: false
                }
            }
        }
    }
});

// Category Chart
const catCtx = document.getElementById('categoryChart').getContext('2d');
const catData = <?php echo json_encode($categories); ?>;

new Chart(catCtx, {
    type: 'bar',
    data: {
        labels: catData.map(item => item.category || 'Uncategorized'),
        datasets: [{
            label: 'Products',
            data: catData.map(item => item.count),
            backgroundColor: '#4e73df',
            yAxisID: 'y-products'
        }, {
            label: 'Stock Value ($)',
            data: catData.map(item => item.total_stock * item.avg_price || 0),
            backgroundColor: '#1cc88a',
            yAxisID: 'y-value'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            'y-products': {
                type: 'linear',
                display: true,
                position: 'left',
                beginAtZero: true
            },
            'y-value': {
                type: 'linear',
                display: true,
                position: 'right',
                beginAtZero: true,
                grid: {
                    drawOnChartArea: false
                },
                ticks: {
                    callback: function(value) {
                        return '$' + (value/1000).toFixed(0) + 'k';
                    }
                }
            }
        }
    }
});

// Top Products Chart
const topCtx = document.getElementById('topProductsChart').getContext('2d');
const topData = <?php echo json_encode($topProducts); ?>;

new Chart(topCtx, {
    type: 'bar',
    data: {
        labels: topData.map(item => item.name.length > 15 ? item.name.substring(0, 15) + '...' : item.name),
        datasets: [{
            label: 'Units Sold',
            data: topData.map(item => item.total_quantity_sold || 0),
            backgroundColor: '#4e73df'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: {
            legend: {
                display: false
            }
        }
    }
});

// Inventory Chart
const invCtx = document.getElementById('inventoryChart').getContext('2d');
const invData = <?php echo json_encode($inventoryValue); ?>;

new Chart(invCtx, {
    type: 'doughnut',
    data: {
        labels: invData.map(item => item.category || 'Uncategorized'),
        datasets: [{
            data: invData.map(item => item.total_value),
            backgroundColor: [
                '#4e73df', '#1cc88a', '#36b9cc', '#f6c23e', '#e74a3b',
                '#858796', '#5a5c69', '#2c9faf', '#6f42c1', '#fd7e14'
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

function exportAnalytics() {
    const params = new URLSearchParams(window.location.search);
    window.location.href = '/pos-system/api/export-analytics.php?' + params.toString();
}

function printAnalytics() {
    window.print();
}

function promoteProduct(productId) {
    window.location.href = '/pos-system/views/products/promote.php?id=' + productId;
}
</script>

<style>
.analytics-container {
    padding: 20px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.page-header h1 {
    margin: 0;
    color: #2b2d42;
    font-size: 24px;
}

.header-actions {
    display: flex;
    gap: 10px;
}

.filter-section {
    margin-bottom: 30px;
}

.filter-row {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 1fr;
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

.filter-actions {
    display: flex;
    gap: 10px;
}

.metrics-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

.metric-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    gap: 20px;
}

.metric-card.primary .metric-icon { background: rgba(78, 115, 223, 0.1); color: #4e73df; }
.metric-card.success .metric-icon { background: rgba(28, 200, 138, 0.1); color: #1cc88a; }
.metric-card.info .metric-icon { background: rgba(54, 185, 204, 0.1); color: #36b9cc; }
.metric-card.warning .metric-icon { background: rgba(246, 194, 62, 0.1); color: #f6c23e; }

.metric-icon {
    width: 60px;
    height: 60px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.metric-content {
    flex: 1;
}

.metric-label {
    display: block;
    color: #666;
    font-size: 13px;
    margin-bottom: 5px;
}

.metric-value {
    display: block;
    font-size: 24px;
    font-weight: bold;
    color: #2b2d42;
    line-height: 1.2;
    margin-bottom: 5px;
}

.metric-change {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 500;
}

.metric-change.positive {
    background: #d4edda;
    color: #155724;
}

.metric-change.negative {
    background: #f8d7da;
    color: #721c24;
}

.metric-sub {
    display: block;
    color: #999;
    font-size: 12px;
}

.charts-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

.chart-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.chart-header h3 {
    margin: 0;
    color: #2b2d42;
    font-size: 16px;
}

.chart-legend span {
    margin-left: 15px;
    font-size: 13px;
    color: #666;
}

.chart-body {
    height: 300px;
    position: relative;
}

.tables-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

.table-card {
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.table-card .card-header {
    padding: 20px;
    border-bottom: 1px solid #e3e6f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.table-card .card-header h3 {
    margin: 0;
    color: #2b2d42;
    font-size: 16px;
}

.table-card .card-body {
    padding: 20px;
}

.analytics-table {
    width: 100%;
    border-collapse: collapse;
}

.analytics-table th {
    text-align: left;
    padding: 12px;
    background: #f8f9fc;
    color: #4e73df;
    font-weight: 600;
    font-size: 13px;
    border-bottom: 2px solid #e3e6f0;
}

.analytics-table td {
    padding: 12px;
    border-bottom: 1px solid #e3e6f0;
    font-size: 14px;
}

.product-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.rank {
    width: 30px;
    height: 30px;
    background: #f8f9fc;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    color: #4e73df;
}

.stock-badge {
    padding: 5px 10px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 500;
}

.stock-ok {
    background: #d4edda;
    color: #155724;
}

.stock-low {
    background: #f8d7da;
    color: #721c24;
}

.progress-bar {
    width: 100px;
    height: 20px;
    background: #e9ecef;
    border-radius: 10px;
    position: relative;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: #4e73df;
    border-radius: 10px;
    transition: width 0.3s;
}

.progress-bar span {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: white;
    font-size: 11px;
    font-weight: 500;
    text-shadow: 0 1px 2px rgba(0,0,0,0.2);
}

.badge {
    padding: 5px 10px;
    border-radius: 15px;
    font-size: 12px;
    font-weight: 500;
}

.badge-success {
    background: #d4edda;
    color: #155724;
}

.badge-warning {
    background: #fff3cd;
    color: #856404;
}

.badge-danger {
    background: #f8d7da;
    color: #721c24;
}

.full-width {
    grid-column: 1 / -1;
    margin-bottom: 20px;
}

.category-table {
    width: 100%;
    border-collapse: collapse;
}

.category-table th {
    text-align: left;
    padding: 15px;
    background: #f8f9fc;
    color: #4e73df;
    font-weight: 600;
    border-bottom: 2px solid #e3e6f0;
}

.category-table td {
    padding: 15px;
    border-bottom: 1px solid #e3e6f0;
}

.contribution-bar {
    width: 150px;
}

.inventory-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-top: 20px;
}

.inventory-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.inventory-card h4 {
    margin: 0 0 20px 0;
    color: #2b2d42;
    font-size: 16px;
}

.summary-stats {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.summary-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #e3e6f0;
}

.summary-item.highlight {
    background: #f8f9fc;
    padding: 15px;
    border-radius: 8px;
    border: none;
    font-weight: bold;
}

.summary-label {
    color: #666;
}

.summary-value {
    font-weight: 600;
    color: #2b2d42;
}

.alert-stats {
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.alert-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    border-radius: 8px;
}

.alert-item.critical {
    background: #f8d7da;
    color: #721c24;
}

.alert-item.warning {
    background: #fff3cd;
    color: #856404;
}

.alert-item.success {
    background: #d4edda;
    color: #155724;
}

.alert-item i {
    font-size: 24px;
}

.alert-count {
    display: block;
    font-size: 20px;
    font-weight: bold;
}

.alert-label {
    font-size: 13px;
}

/* Print styles */
@media print {
    .header-actions,
    .filter-section,
    .btn {
        display: none !important;
    }
    
    .metric-card,
    .chart-card,
    .table-card {
        break-inside: avoid;
        box-shadow: none;
        border: 1px solid #ddd;
    }
}

/* Responsive */
@media (max-width: 1200px) {
    .metrics-grid,
    .charts-row,
    .tables-row,
    .inventory-grid {
        grid-template-columns: 1fr;
    }
    
    .filter-row {
        grid-template-columns: 1fr;
    }
}
</style>

<?php include __DIR__ . '/../layouts/footer.php'; ?>