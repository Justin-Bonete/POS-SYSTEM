<?php
// Shared dashboard-style sidebar for all drugstore pages
if (!isset($activePage)) {
    $activePage = 'dashboard';
}

function ds_is_active($key, $activePage) {
    return $activePage === $key ? 'active' : '';
}
?>
<div class="sidebar" id="sidebar">
    <div class="logo-area">
        <div class="logo">
            <div class="logo-icon">
                <i class="fas fa-pills"></i>
            </div>
            <div class="logo-text">
                <h2>Lhundren Drugstore</h2>
                <p>Pharmacy Management</p>
            </div>
        </div>
    </div>
    <div class="nav-menu">
        <div class="nav-item <?php echo ds_is_active('dashboard', $activePage); ?>">
            <a href="drugstore_dashboard.php">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
        </div>
        <div class="nav-item <?php echo ds_is_active('inventory', $activePage); ?>">
            <a href="drugstore_inventory.php">
                <i class="fas fa-boxes"></i>
                <span>Inventory</span>
            </a>
        </div>
        <div class="nav-item <?php echo ds_is_active('retail', $activePage); ?>">
            <a href="drugstore_sales.php?type=retail">
                <i class="fas fa-store"></i>
                <span>Retail Sales</span>
            </a>
        </div>
        <div class="nav-item <?php echo ds_is_active('agent', $activePage); ?>">
            <a href="drugstore_sales.php?type=wholesale">
                <i class="fas fa-truck"></i>
                <span>Agent Sales</span>
            </a>
        </div>
        <div class="nav-item <?php echo ds_is_active('expired', $activePage); ?>">
            <a href="drugstore_expired.php">
                <i class="fas fa-calendar-times"></i>
                <span>Expired List</span>
            </a>
        </div>
        <div class="nav-item <?php echo ds_is_active('suppliers', $activePage); ?>">
            <a href="drugstore_suppliers.php">
                <i class="fas fa-truck"></i>
                <span>Suppliers</span>
            </a>
        </div>
        <div class="nav-item <?php echo ds_is_active('staff', $activePage); ?>">
            <a href="drugstore_staff.php">
                <i class="fas fa-users"></i>
                <span>Staff</span>
            </a>
        </div>
        <div class="nav-item <?php echo ds_is_active('history', $activePage); ?>">
            <a href="drugstore_history.php">
                <i class="fas fa-history"></i>
                <span>Sale History</span>
            </a>
        </div>
        <div class="nav-item">
            <a href="/pos-system/index.php">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
    </div>
</div>
