<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['selected_system']) || $_SESSION['selected_system'] !== 'drugstore') {
    header('Location: /pos-system/index.php');
    exit();
}

$servername = "localhost";
$username = "root";
$password = "";
$dbname = "drugstore1_db";

try {
    $db = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Handle Add Supplier
if (isset($_POST['add_supplier'])) {
    $stmt = $db->prepare("INSERT INTO suppliers (supplier_name, contact_person, contact_number, address, email) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['supplier_name'], $_POST['contact_person'], $_POST['contact_number'], $_POST['address'], $_POST['email']]);
    header('Location: drugstore_suppliers.php');
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $stmt = $db->prepare("DELETE FROM suppliers WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    header('Location: drugstore_suppliers.php');
    exit();
}

// Fetch suppliers
$suppliers = $db->query("SELECT * FROM suppliers ORDER BY supplier_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$activePage = 'suppliers';
$totalSuppliers = count($suppliers);
$withPhone = 0;
$withEmail = 0;
foreach ($suppliers as $supplier) {
    if (!empty($supplier['contact_number'] ?? '')) {
        $withPhone++;
    }
    if (!empty($supplier['email'] ?? '')) {
        $withEmail++;
    }
}
ob_start();
?>
<button class="btn btn-primary" onclick="openModal()">
    <i class="fas fa-plus"></i>
    <span>Add Supplier</span>
</button>
<?php
$drugstoreHeaderActions = ob_get_clean();
$drugstoreHeaderIcon = 'fa-truck';
$drugstoreHeaderTitle = 'Supplier Management';
$drugstoreHeaderSubtitle = 'Manage drugstore supplier records from the same dashboard workspace.';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drugstore - Suppliers</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="/pos-system/assets/css/drugstore-dashboard-base.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        body { background: #f4f6f9; }
        .sidebar {
            width: 280px;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        .sidebar-header {
            padding: 25px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-header i { font-size: 50px; }
        .sidebar-header h3 { margin: 10px 0 5px; }
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
        }
        .sidebar-menu li a:hover,
        .sidebar-menu li.active a {
            background: rgba(255,255,255,0.1);
            border-left: 4px solid #ffc107;
        }
        .sidebar-menu li a i {
            width: 25px;
            margin-right: 15px;
        }
        .main-content {
            margin-left: 280px;
            padding: 20px;
        }
        .top-bar {
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #007bff; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-warning { background: #ffc107; color: #333; }
        table {
            width: 100%;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        th { background: #f8f9fa; }
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background: white;
            padding: 25px;
            border-radius: 10px;
            width: 500px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .form-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .close {
            float: right;
            font-size: 24px;
            cursor: pointer;
        }
    </style>
    <link rel="stylesheet" href="/pos-system/assets/css/drugstore-dashboard-base.css">
</head>
<body>
    <div class="app">
        <?php include __DIR__ . '/partials/sidebar_dashboard.php'; ?>

        <div class="main-content">
            <?php include __DIR__ . '/partials/drugstore_page_header.php'; ?>

            <div class="summary-cards">
                <div class="summary-card">
                    <h3>Total Suppliers</h3>
                    <div class="number"><?php echo $totalSuppliers; ?></div>
                    <div class="summary-note">Suppliers currently registered</div>
                </div>
                <div class="summary-card">
                    <h3>With Phone</h3>
                    <div class="number"><?php echo $withPhone; ?></div>
                    <div class="summary-note">Suppliers with a saved contact number</div>
                </div>
                <div class="summary-card">
                    <h3>With Email</h3>
                    <div class="number"><?php echo $withEmail; ?></div>
                    <div class="summary-note">Suppliers with an email address</div>
                </div>
            </div>

            <div class="data-table">
                <div class="table-header">
                    <h3 class="table-title">Supplier Directory</h3>
                    <div class="table-summary"><?php echo $totalSuppliers; ?> supplier records</div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Supplier Name</th>
                            <th>Contact Person</th>
                            <th>Contact Number</th>
                            <th>Address</th>
                            <th>Email</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($suppliers)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px;">No suppliers found.</td>
                        </tr>
                        <?php endif; ?>
                        <?php foreach ($suppliers as $supplier): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(trim((string)($supplier['supplier_name'] ?? '')) ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars(trim((string)($supplier['contact_person'] ?? '')) ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars(trim((string)($supplier['contact_number'] ?? '')) ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars(trim((string)($supplier['address'] ?? '')) ?: '-'); ?></td>
                            <td><?php echo htmlspecialchars(trim((string)($supplier['email'] ?? '')) ?: '-'); ?></td>
                            <td>
                                <a href="?delete=<?php echo (int)($supplier['id'] ?? 0); ?>" class="btn btn-danger" onclick="return confirm('Delete this supplier?')">Delete</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="addModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
            <h3>Add Supplier</h3>
            <form method="POST">
                <div class="form-group">
                    <label>Supplier Name *</label>
                    <input type="text" name="supplier_name" required>
                </div>
                <div class="form-group">
                    <label>Contact Person</label>
                    <input type="text" name="contact_person">
                </div>
                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="text" name="contact_number">
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" name="address">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email">
                </div>
                <button type="submit" name="add_supplier" class="btn btn-primary">Save Supplier</button>
            </form>
        </div>
    </div>

    <script>
        function openModal() {
            document.getElementById('addModal').style.display = 'flex';
        }
        function closeModal() {
            document.getElementById('addModal').style.display = 'none';
        }
        window.onclick = function(event) {
            let modal = document.getElementById('addModal');
            if (event.target == modal) modal.style.display = 'none';
        }
    </script>
    <script src="/pos-system/assets/js/drugstore-sidebar-active.js"></script>
</body>
</html>
