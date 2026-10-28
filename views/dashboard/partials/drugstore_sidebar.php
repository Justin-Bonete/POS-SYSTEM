<?php
$drugstoreActivePage = $drugstoreActivePage ?? 'dashboard';
$drugstoreSaleType = $drugstoreSaleType ?? 'retail';

function drugstoreNavClass(string $page, string $activePage, ?string $saleType = null, ?string $activeSaleType = null): string
{
    if ($page !== $activePage) {
        return 'nav-item';
    }
    if ($saleType !== null && $activeSaleType !== null && $saleType !== $activeSaleType) {
        return 'nav-item';
    }
    return 'nav-item active';
}
?>
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
    <div class="<?php echo drugstoreNavClass('dashboard', $drugstoreActivePage); ?>">
        <a href="drugstore_dashboard.php">
            <i class="fas fa-home"></i>
            <span>Dashboard</span>
        </a>
    </div>
    <div class="<?php echo drugstoreNavClass('inventory', $drugstoreActivePage); ?>">
        <a href="drugstore_inventory.php">
            <i class="fas fa-boxes"></i>
            <span>Inventory</span>
        </a>
    </div>
    <div class="<?php echo drugstoreNavClass('sales', $drugstoreActivePage, 'retail', $drugstoreSaleType); ?>">
        <a href="drugstore_sales.php?type=retail">
            <i class="fas fa-store"></i>
            <span>Retail Sales</span>
        </a>
    </div>
    <div class="<?php echo drugstoreNavClass('sales', $drugstoreActivePage, 'wholesale', $drugstoreSaleType); ?>">
        <a href="drugstore_sales.php?type=wholesale">
            <i class="fas fa-truck"></i>
            <span>Agent Sales</span>
        </a>
    </div>
    <div class="<?php echo drugstoreNavClass('expired', $drugstoreActivePage); ?>">
        <a href="drugstore_expired.php">
            <i class="fas fa-calendar-times"></i>
            <span>Expired List</span>
        </a>
    </div>
    <div class="<?php echo drugstoreNavClass('suppliers', $drugstoreActivePage); ?>">
        <a href="drugstore_suppliers.php">
            <i class="fas fa-truck"></i>
            <span>Suppliers</span>
        </a>
    </div>
    <div class="<?php echo drugstoreNavClass('staff', $drugstoreActivePage); ?>">
        <a href="drugstore_staff.php">
            <i class="fas fa-users"></i>
            <span>Staff</span>
        </a>
    </div>
    <div class="<?php echo drugstoreNavClass('history', $drugstoreActivePage); ?>">
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
