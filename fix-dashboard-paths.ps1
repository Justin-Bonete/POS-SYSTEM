# fix-dashboard-paths.ps1
cd C:\xampp\htdocs\pos-system

Write-Host "?? Fixing dashboard include paths..." -ForegroundColor Yellow

$dashboardContent = @"
<?php
$pageTitle = 'Dashboard';
$pageScripts = ['dashboard.js'];

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Sale.php';
require_once __DIR__ . '/../../classes/Product.php';

requireAuth();

$sale = new Sale();
$product = new Product();

$today = date('Y-m-d');
$sales = $sale->getAll(['start_date' => $today, 'end_date' => $today]);
$lowStock = $product->getLowStock(10);
$recentSales = $sale->getAll([], 1, 10);
?>
<?php include __DIR__ . '/../layouts/header.php'; ?>

<div class="stats-row">
    <div class="stat-card primary">
        <div class="stat-info">
            <h3>Today's Sales</h3>
            <div class="stat-number">
                <?php 
                $total = 0;
                if (!empty($sales['data'])) {
                    foreach ($sales['data'] as $s) {
                        $total += $s['total_amount'];
                    }
                }
                echo formatCurrency($total);
                ?>
            </div>
            <small><?php echo count($sales['data'] ?? []); ?> transactions</small>
        </div>
        <div class="stat-icon">
            <i class="fas fa-cash-register"></i>
        </div>
    </div>
    
    <div class="stat-card success">
        <div class="stat-info">
            <h3>Low Stock Alert</h3>
            <div class="stat-number"><?php echo count($lowStock['data'] ?? []); ?></div>
            <small>Products below threshold</small>
        </div>
        <div class="stat-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
    </div>
    
    <div class="stat-card info">
        <div class="stat-info">
            <h3>Total Products</h3>
            <div class="stat-number">
                <?php
                $products = $product->getAll([], 1, 1000);
                echo count($products);
                ?>
            </div>
            <small>In inventory</small>
        </div>
        <div class="stat-icon">
            <i class="fas fa-box"></i>
        </div>
    </div>
    
    <div class="stat-card warning">
        <div class="stat-info">
            <h3>Recent Sales</h3>
            <div class="stat-number"><?php echo count($recentSales['data'] ?? []); ?></div>
            <small>Last 10 transactions</small>
        </div>
        <div class="stat-icon">
            <i class="fas fa-chart-line"></i>
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h3>Recent Sales</h3>
            </div>
            <div class="card-body">
                <div class="activity-feed">
                    <?php if (empty($recentSales['data'] ?? [])): ?>
                        <p class="text-center">No recent sales</p>
                    <?php else: ?>
                        <?php foreach (array_slice($recentSales['data'], 0, 5) as $sale): ?>
                        <div class="activity-item">
                            <div class="activity-icon sale">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                            <div class="activity-content">
                                <div class="activity-title">
                                    Sale #<?php echo $sale['invoice_number']; ?> - 
                                    <?php echo formatCurrency($sale['total_amount']); ?>
                                </div>
                                <div class="activity-time">
                                    <?php echo date('M d, Y h:i A', strtotime($sale['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h3>Low Stock Products</h3>
            </div>
            <div class="card-body">
                <?php if (empty($lowStock['data'] ?? [])): ?>
                    <p class="text-success text-center">All products have sufficient stock.</p>
                <?php else: ?>
                    <?php foreach (array_slice($lowStock['data'], 0, 5) as $product): ?>
                    <div class="inventory-alert-item <?php echo $product['quantity'] <= 5 ? 'inventory-alert-danger' : 'inventory-alert-warning'; ?>">
                        <div class="d-flex justify-between align-center">
                            <div>
                                <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                <br>
                                <small>Stock: <?php echo $product['quantity']; ?> units</small>
                            </div>
                            <a href="/pos-system/views/products/edit.php?id=<?php echo $product['id']; ?>" class="btn btn-sm btn-primary">
                                Restock
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row mt-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h3>Quick Actions</h3>
            </div>
            <div class="card-body">
                <div class="quick-actions-grid">
                    <div class="quick-action-card" onclick="window.location.href='/pos-system/views/sales/new.php'">
                        <div class="quick-action-icon">
                            <i class="fas fa-cash-register"></i>
                        </div>
                        <div>New Sale</div>
                    </div>
                    
                    <div class="quick-action-card" onclick="window.location.href='/pos-system/views/products/add.php'">
                        <div class="quick-action-icon">
                            <i class="fas fa-plus-circle"></i>
                        </div>
                        <div>Add Product</div>
                    </div>
                    
                    <div class="quick-action-card" onclick="window.location.href='/pos-system/views/products/list.php'">
                        <div class="quick-action-icon">
                            <i class="fas fa-list"></i>
                        </div>
                        <div>Products</div>
                    </div>
                    
                    <div class="quick-action-card" onclick="window.location.href='/pos-system/views/categories/list.php'">
                        <div class="quick-action-icon">
                            <i class="fas fa-tags"></i>
                        </div>
                        <div>Categories</div>
                    </div>
                    
                    <div class="quick-action-card" onclick="window.location.href='/pos-system/views/sales/history.php'">
                        <div class="quick-action-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <div>Sales History</div>
                    </div>
                    
                    <div class="quick-action-card" onclick="window.location.href='/pos-system/views/sales/report.php'">
                        <div class="quick-action-icon">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <div>Reports</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
"@

Set-Content -Path views\dashboard\index.php -Value $dashboardContent -Force
Write-Host "? Fixed dashboard paths" -ForegroundColor Green
Write-Host "`n?? Fix applied! Refresh your browser." -ForegroundColor Green
