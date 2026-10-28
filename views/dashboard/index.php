<?php
// views/dashboard/index.php

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include auth first - this has all the authentication functions
require_once __DIR__ . '/../../includes/auth.php';

// Check if user is logged in using the function from auth.php
if (!isLoggedIn()) {
    header('Location: /pos-system/views/auth/login.php');
    exit();
}

// Include helpers.php for additional helper functions
require_once __DIR__ . '/../../includes/helpers.php';

$pageTitle = 'Dashboard';
$pageScripts = ['dashboard.js', 'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js'];

// Include other necessary files with correct paths
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Sale.php';
require_once __DIR__ . '/../../classes/Product.php';
require_once __DIR__ . '/../../classes/ProductHistory.php';
require_once __DIR__ . '/../../classes/Category.php';

// Require authentication (this will redirect if not logged in)
requireAuth();

// Get current user info
$currentUser = getCurrentUser();
$userName = $currentUser['name'] ?? 'User';
$userRole = $currentUser['role'] ?? '';
$isCashier = ($userRole === 'cashier');
$isManager = ($userRole === 'manager');
$isLimited = ($isCashier || $isManager);

// Initialize classes
$sale = new Sale();
$product = new Product();
$productHistory = new ProductHistory();
$category = new Category();

// Define date variables
$today = date('Y-m-d');
$startOfWeek = date('Y-m-d', strtotime('monday this week'));
$startOfMonth = date('Y-m-01');
$endOfMonth = date('Y-m-t');
$thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));

$todayTotal = 0;
$todayCount = 0;
$weekTotal = 0;
$monthTotal = 0;
$totalProducts = 0;
$lowStock = ['data' => []];
$lowStockCount = 0;
$outOfStock = 0;
$totalValue = 0;
$recentSales = [];
$stockMovementStats = [];
$recentHistory = [];
$topProducts = [];

if (!$isLimited) {
    // Get sales data using aggregated summaries to avoid pagination/filter inconsistencies
    $todaySummary = $sale->getSummary(['start_date' => $today, 'end_date' => $today]);
    $weekSummary = $sale->getSummary(['start_date' => $startOfWeek, 'end_date' => $today]);
    $monthSummary = $sale->getSummary(['start_date' => $startOfMonth, 'end_date' => $endOfMonth]);

    $todayTotal = floatval($todaySummary['total_sales'] ?? 0);
    $todayCount = intval($todaySummary['total_transactions'] ?? 0);
    $weekTotal = floatval($weekSummary['total_sales'] ?? 0);
    $monthTotal = floatval($monthSummary['total_sales'] ?? 0);

    // Get product stats
    $allProducts = $product->getAll([], 1, 1000);
    $totalProducts = is_array($allProducts) ? count($allProducts) : 0;
    $lowStock = $product->getLowStock(10);
    $lowStockCount = isset($lowStock['data']) ? count($lowStock['data']) : 0;
    $outOfStock = 0;
    $totalValue = 0;

    if (is_array($allProducts)) {
        foreach ($allProducts as $p) {
            $totalValue += floatval($p['price'] ?? 0) * intval($p['quantity'] ?? 0);
            if (intval($p['quantity'] ?? 0) == 0) {
                $outOfStock++;
            }
        }
    }

    $recentSales = $sale->getAll([], 1, 10);
    $stockMovementStats = $productHistory->getStockMovementStats($thirtyDaysAgo, $today);
    $recentHistory = $productHistory->getRecentActivities(10);
    $topProducts = $productHistory->getTopMovingProducts(5);
}

// Include header
include __DIR__ . '/../layouts/header.php';
?>

<div class="dashboard-wrapper">
    <!-- Top Bar with User Info -->
    <div class="top-bar">
        <div class="page-title">
            <h1>Dashboard</h1>
            <p>Welcome back, <?php echo htmlspecialchars($userName); ?>!</p>
        </div>
        <div class="user-info">
            <span class="user-role-badge"><?php echo ucfirst($userRole); ?></span>
            <div class="user-actions">
                <?php if (isAdmin()): ?>
                <a href="/pos-system/views/settings/index.php" class="action-btn" title="Settings">
                    <i class="fas fa-cog"></i>
                </a>
                <?php endif; ?>
                <a href="/pos-system/index.php" class="action-btn" title="Logout">
                    <i class="fas fa-sign-out-alt"></i>
                </a>
            </div>
        </div>
    </div>

    <?php if (!$isLimited): ?>
        <!-- KPI Cards Row -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon blue">
                    <i class="fas fa-cash-register"></i>
                </div>
                <div class="kpi-content">
                    <h3>Today's Sales</h3>
                    <div class="kpi-value"><?php echo formatCurrency($todayTotal); ?></div>
                    <div class="kpi-footer"><?php echo $todayCount; ?> transactions</div>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-icon green">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="kpi-content">
                    <h3>Weekly Sales</h3>
                    <div class="kpi-value"><?php echo formatCurrency($weekTotal); ?></div>
                    <div class="kpi-footer">This week</div>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-icon purple">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="kpi-content">
                    <h3>Monthly Sales</h3>
                    <div class="kpi-value"><?php echo formatCurrency($monthTotal); ?></div>
                    <div class="kpi-footer">MTD</div>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-icon orange">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="kpi-content">
                    <h3>Inventory Value</h3>
                    <div class="kpi-value"><?php echo formatCurrency($totalValue); ?></div>
                    <div class="kpi-footer"><?php echo $totalProducts; ?> products</div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Two Column Layout -->
    <div class="two-column-layout">
        <!-- Left Column - Quick Actions -->
        <div class="left-column">
            <div class="quick-actions-card">
                <h3><i class="fas fa-bolt"></i> Quick Actions</h3>
                <div class="actions-grid">
                    <?php if ($isCashier): ?>
                        <a href="/pos-system/views/sales/new.php" class="action-item">
                            <i class="fas fa-cash-register"></i>
                            <span>New Sale</span>
                        </a>
                        <a href="/pos-system/views/sales/history.php" class="action-item">
                            <i class="fas fa-receipt"></i>
                            <span>Sales History</span>
                        </a>
                        <a href="/pos-system/views/sales/order.php" class="action-item">
                            <i class="fas fa-clipboard-list"></i>
                            <span>Orders</span>
                        </a>
                        <a href="/pos-system/views/products/balance.php" class="action-item">
                            <i class="fas fa-balance-scale"></i>
                            <span>Balance</span>
                        </a>
                    <?php elseif ($isManager): ?>
                        <a href="/pos-system/views/products/add.php" class="action-item">
                            <i class="fas fa-plus-circle"></i>
                            <span>Add Product</span>
                        </a>
                        <a href="/pos-system/views/products/stock-in.php" class="action-item">
                            <i class="fas fa-arrow-down"></i>
                            <span>Stock In</span>
                        </a>
                        <a href="/pos-system/views/products/list.php" class="action-item">
                            <i class="fas fa-list"></i>
                            <span>Products List</span>
                        </a>
                        <a href="/pos-system/views/products/history.php" class="action-item">
                            <i class="fas fa-history"></i>
                            <span>Product History</span>
                        </a>
                    <?php else: ?>
                        <a href="/pos-system/views/sales/new.php" class="action-item">
                            <i class="fas fa-cash-register"></i>
                            <span>New Sale</span>
                        </a>
                        <a href="/pos-system/views/sales/history.php" class="action-item">
                            <i class="fas fa-receipt"></i>
                            <span>Sales History</span>
                        </a>
                        <a href="/pos-system/views/products/add.php" class="action-item">
                            <i class="fas fa-plus-circle"></i>
                            <span>Add Product</span>
                        </a>
                        <a href="/pos-system/views/products/stock-in.php" class="action-item">
                            <i class="fas fa-arrow-down"></i>
                            <span>Stock In</span>
                        </a>
                        <a href="/pos-system/views/products/history.php" class="action-item">
                            <i class="fas fa-history"></i>
                            <span>Product History</span>
                        </a>
                        <a href="/pos-system/views/products/balance.php" class="action-item">
                            <i class="fas fa-balance-scale"></i>
                            <span>Product Balance</span>
                        </a>
 			<a href="/pos-system/views/sales/order.php" class="action-item">
                            <i class="fas fa-chart-pie"></i>
                            <span>Order</span>
                        </a>
                        <?php if (isAdmin() || isManager()): ?>
                        <a href="/pos-system/views/reports/index.php" class="action-item">
                            <i class="fas fa-chart-pie"></i>
                            <span>Reports</span>
                        </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!$isLimited): ?>
        <!-- Right Column - Low Stock Alerts -->
        <div class="right-column">
            <div class="low-stock-card">
                <h3><i class="fas fa-exclamation-triangle"></i> Low Stock Alerts</h3>
                <?php if ($lowStockCount == 0 && $outOfStock == 0): ?>
                    <div class="empty-state small">
                        <p>All products are well stocked</p>
                    </div>
                <?php else: ?>
                    <?php if ($outOfStock > 0): ?>
                    <div class="alert-warning">
                        <strong><?php echo $outOfStock; ?> products out of stock</strong>
                    </div>
                    <?php endif; ?>

                    <?php foreach (array_slice($lowStock['data'] ?? [], 0, 5) as $item): ?>
                    <div class="stock-item stock-<?php echo $item['quantity'] <= 5 ? 'critical' : 'low'; ?>">
                        <div class="stock-info">
                            <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                            <span class="stock-qty"><?php echo $item['quantity']; ?> units</span>
                        </div>
                        <a href="/pos-system/views/products/edit.php?id=<?php echo $item['id']; ?>" class="btn-icon" title="Restock">
                            <i class="fas fa-plus"></i>
                        </a>
                    </div>
                    <?php endforeach; ?>
                    
                    <a href="/pos-system/views/products/list.php?filter=lowstock" class="view-all-link">View All →</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (isAdmin()): ?>
<!-- Approval modal triggered by bell icon -->
<div id="approval-modal" class="approval-modal">
    <div class="approval-modal-backdrop" onclick="toggleApprovalModal(false)"></div>
    <div class="approval-modal-content">
        <div class="approval-modal-header">
            <div>
                <strong>Pending Borrow Approvals</strong>
                <div class="muted" id="approval-count-label">Loading...</div>
            </div>
            <div class="approval-header-actions">
                <button class="clear-btn" onclick="clearStaleApprovals()">Clear</button>
                <button class="close-btn" onclick="toggleApprovalModal(false)">&times;</button>
            </div>
        </div>
        <div id="approval-list" class="approval-list">
            <div class="empty-state small">Loading approvals...</div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (isAdmin()): ?>
<script>
(function() {
    const listEl = document.getElementById('approval-list');
    const bell = document.querySelector('.approval-notifications');
    const modal = document.getElementById('approval-modal');
    const countLabel = document.getElementById('approval-count-label');

    async function loadApprovals() {
        if (!listEl) return;
        try {
            const res = await fetch('/pos-system/api/sales/approval-list.php?status=pending&limit=20', { headers: { 'Accept': 'application/json' }});
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Failed to load approvals');
            renderApprovals(data.data || []);
        } catch (err) {
            listEl.innerHTML = `<div class="empty-state small">${err.message}</div>`;
            console.error(err);
        }
    }

    function renderApprovals(rows) {
        if (!rows.length) {
            listEl.innerHTML = '<div class="empty-state small">No pending approvals</div>';
            if (countLabel) countLabel.textContent = 'No pending items';
        } else {
            const html = rows.map(row => {
                const short = Number(row.short_amount || 0).toFixed(2);
                const cash = Number(row.cash_amount || 0).toFixed(2);
                const total = Number(row.total_amount || 0).toFixed(2);
                return `
                    <div class="approval-item">
                        <div class="approval-main">
                            <div>
                                <strong>${row.invoice_number || 'PENDING'}</strong>
                                <div class="muted">Requested by: ${row.requested_by_name || 'user #' + row.requested_by}</div>
                                <div class="muted">Customer: ${row.customer_name || 'N/A'}</div>
                            </div>
                            <div class="amounts">
                                <span class="pill">Cash ₱${cash}</span>
                                <span class="pill warning">Short ₱${short}</span>
                                <span class="pill">Total ₱${total}</span>
                            </div>
                        </div>
                        <div class="approval-actions">
                            <button class="btn-approve" onclick="decideApproval(${row.id}, 'approve')"><i class="fas fa-check"></i> Approve</button>
                            <button class="btn-reject" onclick="decideApproval(${row.id}, 'reject')"><i class="fas fa-times"></i> Reject</button>
                        </div>
                    </div>
                `;
            }).join('');
            listEl.innerHTML = html;
            if (countLabel) countLabel.textContent = `${rows.length} pending item(s)`;
        }

        if (bell) {
            let badge = bell.querySelector('.notif-badge');
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'notif-badge';
                bell.appendChild(badge);
            }
            badge.textContent = rows.length > 99 ? '99+' : rows.length;
            badge.style.display = rows.length ? 'inline-flex' : 'none';
        }
    }

    window.decideApproval = async function(id, action) {
        if (!['approve','reject'].includes(action)) return;
        const confirmMsg = action === 'approve' ? 'Approve this short-cash sale?' : 'Remove this notification?';
        if (!confirm(confirmMsg)) return;
        try {
            const res = await fetch('/pos-system/api/sales/approval-decide.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ approval_id: id, action })
            });
            const text = await res.text();
            let data;
            try {
                data = JSON.parse(text);
            } catch (parseErr) {
                throw new Error(text.trim() || 'Server returned non-JSON response');
            }
            if (!data.success) throw new Error(data.message || 'Failed to update');
            alert(data.message);
            loadApprovals();
        } catch (err) {
            alert(err.message);
            console.error(err);
        }
    };

    window.clearStaleApprovals = async function() {
        if (!confirm('Clear notifications for deleted orders?')) return;
        try {
            const res = await fetch('/pos-system/api/sales/approval-clear.php', {
                method: 'POST',
                headers: { 'Accept': 'application/json' }
            });
            const data = await res.json();
            if (!data.success) throw new Error(data.message || 'Failed to clear notifications');
            alert(data.message);
            loadApprovals();
        } catch (err) {
            alert(err.message);
            console.error(err);
        }
    };

    function toggleApprovalModal(show) {
        if (!modal) return;
        modal.classList.toggle('show', !!show);
        if (show) {
            loadApprovals();
        }
    }
    window.toggleApprovalModal = toggleApprovalModal;

    if (bell) {
        bell.style.cursor = 'pointer';
        bell.addEventListener('click', (e) => {
            e.preventDefault();
            toggleApprovalModal(!modal.classList.contains('show'));
        });
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') toggleApprovalModal(false);
    });

    document.addEventListener('DOMContentLoaded', loadApprovals);
    setInterval(loadApprovals, 30000);
})();
</script>
<?php endif; ?>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    /* Background Image - Replace the URL with your image path */
    background-image: url('/pos-system/assets/images/');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
    background-repeat: no-repeat;
    color: #2d3748;
    line-height: 1.5;
    min-height: 100vh;
}

/* Optional overlay to make content more readable */
body::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.78); /* White overlay with 90% opacity */
    z-index: -1;
}

.dashboard-wrapper {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px 24px;
    width: 100%;
}

/* Top Bar */
.top-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding: 20px 25px;
    background: rgba(255, 255, 255, 0.95);
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    backdrop-filter: blur(10px);
}

.page-title h1 {
    font-size: 28px;
    font-weight: 600;
    color: #1a202c;
    margin-bottom: 4px;
}

.page-title p {
    font-size: 15px;
    color: #718096;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 16px;
}

.user-role-badge {
    background: #4361ee;
    color: white;
    padding: 6px 16px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 500;
    letter-spacing: 0.3px;
}

.user-actions {
    display: flex;
    gap: 10px;
}

.action-btn {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: white;
    border-radius: 10px;
    color: #4a5568;
    text-decoration: none;
    transition: all 0.2s;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}

.action-btn:hover {
    background: #4361ee;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(67, 97, 238, 0.2);
}

/* KPI Grid */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

.kpi-card {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 16px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    border: 1px solid rgba(255,255,255,0.2);
    transition: all 0.2s;
    backdrop-filter: blur(10px);
}

.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}

.kpi-icon {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
}

.kpi-icon.blue { background: linear-gradient(135deg, #4361ee, #4cc9f0); }
.kpi-icon.green { background: linear-gradient(135deg, #06d6a0, #0cb892); }
.kpi-icon.purple { background: linear-gradient(135deg, #7209b7, #b5179e); }
.kpi-icon.orange { background: linear-gradient(135deg, #f72585, #b5179e); }

.kpi-content {
    flex: 1;
}

.kpi-content h3 {
    font-size: 13px;
    font-weight: 600;
    color: #718096;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.kpi-value {
    font-size: 24px;
    font-weight: 700;
    color: #1a202c;
    margin-bottom: 4px;
}

.kpi-footer {
    font-size: 13px;
    color: #a0aec0;
}

/* Two Column Layout */
.two-column-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-top: 20px;
}

/* Left Column - Quick Actions */
.left-column {
    height: fit-content;
}

.quick-actions-card {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    backdrop-filter: blur(10px);
}

.quick-actions-card h3 {
    font-size: 18px;
    font-weight: 600;
    color: #1a202c;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 15px;
    border-bottom: 2px solid #edf2f7;
}

.quick-actions-card h3 i {
    color: #4361ee;
}

.actions-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
}

.action-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px;
    background: #f8fafd;
    border-radius: 12px;
    color: #1a202c;
    text-decoration: none;
    transition: all 0.2s;
    border: 1px solid transparent;
}

.action-item:hover {
    background: white;
    border-color: #4361ee;
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(67, 97, 238, 0.15);
}

.action-item i {
    width: 24px;
    text-align: center;
    color: #4361ee;
    font-size: 18px;
}

.action-item span {
    font-weight: 500;
}

/* Right Column - Low Stock Alerts */
.right-column {
    height: fit-content;
}

.low-stock-card {
    background: rgba(255, 255, 255, 0.95);
    border-radius: 16px;
    padding: 25px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    backdrop-filter: blur(10px);
}

.low-stock-card h3 {
    font-size: 18px;
    font-weight: 600;
    color: #1a202c;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 15px;
    border-bottom: 2px solid #edf2f7;
}

.low-stock-card h3 i {
    color: #f72585;
}

/* Stock Items */
.stock-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    border-radius: 12px;
    margin-bottom: 10px;
    transition: all 0.2s;
}

.stock-item:hover {
    transform: translateX(5px);
}

.stock-low {
    background: #fff9e6;
    border-left: 4px solid #ffb703;
}

.stock-critical {
    background: #fee9e9;
    border-left: 4px solid #f72585;
}

.stock-info {
    flex: 1;
}

.stock-info strong {
    display: block;
    margin-bottom: 5px;
    color: #1a202c;
    font-size: 15px;
}

.stock-qty {
    font-size: 13px;
    color: #718096;
    font-weight: 500;
}

.btn-icon {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: white;
    border-radius: 10px;
    color: #4361ee;
    text-decoration: none;
    transition: all 0.2s;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.btn-icon:hover {
    background: #4361ee;
    color: white;
    transform: scale(1.1);
}

.alert-warning {
    padding: 15px;
    border-radius: 12px;
    margin-bottom: 20px;
    background: #fee9e9;
    color: #f72585;
    font-size: 14px;
    font-weight: 600;
    text-align: center;
}

.view-all-link {
    display: block;
    text-align: center;
    margin-top: 20px;
    color: #4361ee;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    padding: 12px;
    border-radius: 12px;
    background: #f8fafd;
    transition: all 0.2s;
}

.view-all-link:hover {
    background: #4361ee;
    color: white;
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 30px 20px;
    color: #a0aec0;
}

.empty-state.small {
    padding: 30px;
}

.empty-state p {
    margin-bottom: 8px;
    font-size: 15px;
}

.approval-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 1100;
}
.approval-modal.show { display: block; }
.approval-modal-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.4);
}
.approval-modal-content {
    position: absolute;
    top: 70px;
    right: 40px;
    width: min(480px, 90vw);
    max-height: 70vh;
    overflow-y: auto;
    background: #ffffff;
    border-radius: 14px;
    box-shadow: 0 12px 40px rgba(0,0,0,0.18);
    padding: 16px;
}
.approval-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
.approval-header-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}
.clear-btn {
    border: none;
    background: #f1f5f9;
    color: #334155;
    border-radius: 8px;
    height: 32px;
    padding: 0 12px;
    font-weight: 700;
    cursor: pointer;
}
.clear-btn:hover {
    background: #e2e8f0;
}
.close-btn {
    border: none;
    background: #e2e8f0;
    border-radius: 8px;
    width: 32px;
    height: 32px;
    font-size: 18px;
    cursor: pointer;
}
.approval-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.approval-item {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px;
    background: #f8fafc;
}
.approval-main {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
}
.approval-main .muted {
    color: #718096;
    font-size: 12px;
}
.amounts {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    align-items: center;
}
.pill {
    padding: 4px 8px;
    border-radius: 10px;
    background: #e2e8f0;
    font-size: 12px;
    font-weight: 600;
}
.pill.warning {
    background: #fff4e5;
    color: #d97706;
}
.approval-actions {
    margin-top: 10px;
    display: flex;
    gap: 8px;
}
.btn-approve, .btn-reject {
    flex: 1;
    border: none;
    border-radius: 10px;
    padding: 10px;
    font-weight: 700;
    cursor: pointer;
    color: white;
}
.btn-approve { background: #16a34a; }
.btn-reject { background: #ef4444; }
.btn-approve:hover { background: #15803d; }
.btn-reject:hover { background: #dc2626; }
/* Badge styling comes from the shared header (.notification-icon .notif-badge). */

/* Responsive Design */
@media (max-width: 1200px) {
    .kpi-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .two-column-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 768px) {
    .dashboard-wrapper {
        padding: 16px;
    }
    
    .top-bar {
        flex-direction: column;
        align-items: flex-start;
        gap: 16px;
    }
    
    .user-info {
        width: 100%;
        justify-content: space-between;
    }
    
    .kpi-grid {
        grid-template-columns: 1fr;
    }
    
    .actions-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 480px) {
    .kpi-card {
        flex-direction: column;
        text-align: center;
    }
    
    .stock-item {
        flex-direction: column;
        gap: 10px;
        text-align: center;
    }
    
    .stock-info strong {
        margin-bottom: 10px;
    }
}
</style>

<?php include __DIR__ . '/../layouts/footer.php'; ?>
