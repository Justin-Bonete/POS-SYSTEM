<?php
session_start();

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

// Handle Add Staff
if (isset($_POST['add_staff'])) {
    $stmt = $db->prepare("INSERT INTO staff (name, address, birthday, sex, position, contact_number, email, hire_date, salary) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$_POST['name'], $_POST['address'], $_POST['birthday'], $_POST['sex'], $_POST['position'], $_POST['contact_number'], $_POST['email'], $_POST['hire_date'], $_POST['salary']]);
    header('Location: drugstore_staff.php');
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $stmt = $db->prepare("DELETE FROM staff WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    header('Location: drugstore_staff.php');
    exit();
}

$staff = $db->query("SELECT * FROM staff ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$activePage = 'staff';
$totalStaff = count($staff);
$positions = [];
$withContact = 0;
foreach ($staff as $member) {
    $position = trim((string)($member['position'] ?? ''));
    if ($position !== '') {
        $positions[$position] = true;
    }
    if (!empty($member['contact_number']) || !empty($member['email'])) {
        $withContact++;
    }
}
$positionCount = count($positions);
ob_start();
?>
<button class="btn btn-primary" onclick="openModal()">
    <i class="fas fa-user-plus"></i>
    <span>Add Staff</span>
</button>
<?php
$drugstoreHeaderActions = ob_get_clean();
$drugstoreHeaderIcon = 'fa-users';
$drugstoreHeaderTitle = 'Staff Management';
$drugstoreHeaderSubtitle = 'Manage employee records in the same workspace style as the dashboard.';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Drugstore - Staff Management</title>
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
            max-height: 80vh;
            overflow-y: auto;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .form-group input, .form-group select {
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
                <h3>Total Staff</h3>
                <div class="number"><?php echo $totalStaff; ?></div>
                <div class="summary-note">Employees currently listed in the system</div>
            </div>
            <div class="summary-card">
                <h3>Unique Positions</h3>
                <div class="number"><?php echo $positionCount; ?></div>
                <div class="summary-note">Roles represented across the team</div>
            </div>
            <div class="summary-card">
                <h3>With Contact Info</h3>
                <div class="number"><?php echo $withContact; ?></div>
                <div class="summary-note">Records with phone number or email</div>
            </div>
        </div>

        <div class="data-table">
            <div class="table-header">
                <h3 class="table-title">Staff Directory</h3>
                <div class="table-summary"><?php echo $totalStaff; ?> staff members</div>
            </div>
            <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Position</th>
                    <th>Contact</th>
                    <th>Email</th>
                    <th>Hire Date</th>
                    <th>Salary</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($staff as $member): ?>
                <tr>
                    <td><?php echo htmlspecialchars(trim((string)($member['name'] ?? '')) ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars(trim((string)($member['position'] ?? '')) ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars(trim((string)($member['contact_number'] ?? '')) ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars(trim((string)($member['email'] ?? '')) ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars(trim((string)($member['hire_date'] ?? '')) ?: '-'); ?></td>
                    <?php $salary = $member['salary'] ?? null; ?>
                    <td><?php echo ($salary === null || $salary === '') ? '-' : '&#8369;' . number_format((float)$salary, 2); ?></td>
                    <td>
                        <a href="?delete=<?php echo $member['id']; ?>" class="btn btn-danger" onclick="return confirm('Delete this staff?')">Delete</a>
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
            <h3>Add Staff Member</h3>
            <form method="POST">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="name" required>
                </div>
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" name="address">
                </div>
                <div class="form-group">
                    <label>Birthday</label>
                    <input type="date" name="birthday">
                </div>
                <div class="form-group">
                    <label>Sex</label>
                    <select name="sex">
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Position *</label>
                    <input type="text" name="position" required>
                </div>
                <div class="form-group">
                    <label>Contact Number</label>
                    <input type="text" name="contact_number">
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email">
                </div>
                <div class="form-group">
                    <label>Hire Date</label>
                    <input type="date" name="hire_date">
                </div>
                <div class="form-group">
                    <label>Salary</label>
                    <input type="number" step="0.01" name="salary">
                </div>
                <button type="submit" name="add_staff" class="btn btn-primary">Save Staff</button>
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
