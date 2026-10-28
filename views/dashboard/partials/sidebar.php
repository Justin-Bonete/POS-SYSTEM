<?php
// Shared sidebar for drugstore dashboard pages
if (!isset($activePage)) {
    $script = basename($_SERVER['PHP_SELF']);
    $type = $_GET['type'] ?? 'retail';

    if ($script === 'drugstore_inventory.php') {
        $activePage = 'inventory';
    } elseif ($script === 'drugstore_sales.php') {
        $activePage = ($type === 'wholesale') ? 'agent' : 'retail';
    } elseif ($script === 'drugstore_expired.php') {
        $activePage = 'expired';
    } elseif ($script === 'drugstore_suppliers.php') {
        $activePage = 'suppliers';
    } elseif ($script === 'drugstore_staff.php') {
        $activePage = 'staff';
    } elseif ($script === 'drugstore_history.php') {
        $activePage = 'history';
    } else {
        $activePage = 'dashboard';
    }
}

function sidebarActive($key, $activePage)
{
    return $activePage === $key ? 'active' : '';
}
?>

<aside id="sidebar" class="sidebar">
  <div class="toggle-btn" onclick="toggleSidebar()">
    <i class="fas fa-bars"></i><span>Menu</span>
  </div>
  <ul>
    <li><a class="<?php echo sidebarActive('dashboard', $activePage); ?>" href="drugstore_dashboard.php"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
    <li><a class="<?php echo sidebarActive('inventory', $activePage); ?>" href="drugstore_inventory.php"><i class="fas fa-boxes"></i><span>Inventory</span></a></li>
    <li><a class="<?php echo sidebarActive('retail', $activePage); ?>" href="drugstore_sales.php?type=retail"><i class="fas fa-store"></i><span>Retail Sales</span></a></li>
    <li><a class="<?php echo sidebarActive('agent', $activePage); ?>" href="drugstore_sales.php?type=wholesale"><i class="fas fa-truck"></i><span>Agent Sales</span></a></li>
    <li><a class="<?php echo sidebarActive('expired', $activePage); ?>" href="drugstore_expired.php"><i class="fas fa-calendar-times"></i><span>Expired List</span></a></li>
    <li><a class="<?php echo sidebarActive('suppliers', $activePage); ?>" href="drugstore_suppliers.php"><i class="fas fa-truck"></i><span>Suppliers</span></a></li>
    <li><a class="<?php echo sidebarActive('staff', $activePage); ?>" href="drugstore_staff.php"><i class="fas fa-users"></i><span>Staff</span></a></li>
    <li><a class="<?php echo sidebarActive('history', $activePage); ?>" href="drugstore_history.php"><i class="fas fa-history"></i><span>Sale History</span></a></li>
    <li><a href="/pos-system/index.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
  </ul>
</aside>
