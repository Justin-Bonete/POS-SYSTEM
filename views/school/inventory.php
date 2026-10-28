<?php
require_once __DIR__ . '/_bootstrap.php';

$conn = schoolStoreConnect();

// CREATE
if (isset($_POST['add'])) {
  $stmt = $conn->prepare("INSERT INTO school_inventory 
    (barcode, quantity, product_name, capital_price, wholesale_price, retail_price) 
    VALUES (?, ?, ?, ?, ?, ?)");
  $stmt->bind_param("sissdd",
    $_POST['barcode'], $_POST['quantity'], $_POST['product_name'],
    $_POST['capital_price'], $_POST['wholesale_price'], $_POST['retail_price']
  );
  if ($stmt->execute()) {
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
  }
}

// UPDATE
if (isset($_POST['update'])) {
  $stmt = $conn->prepare("UPDATE school_inventory 
    SET barcode=?, quantity=?, product_name=?, capital_price=?, wholesale_price=?, retail_price=? 
    WHERE id=?");
  $stmt->bind_param("sisdddi",
    $_POST['barcode'], $_POST['quantity'], $_POST['product_name'],
    $_POST['capital_price'], $_POST['wholesale_price'], $_POST['retail_price'], $_POST['id']
  );
  if ($stmt->execute()) {
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
  }
}

// DELETE
if (isset($_GET['delete'])) {
  $id = intval($_GET['delete']);
  $stmt = $conn->prepare("DELETE FROM school_inventory WHERE id=?");
  $stmt->bind_param("i", $id);
  if ($stmt->execute()) {
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
  }
}

// SEARCH + READ
$search = "";
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = "%" . $conn->real_escape_string($_GET['search']) . "%";
    $stmt = $conn->prepare("SELECT * FROM school_inventory 
                            WHERE barcode LIKE ? OR product_name LIKE ? 
                            ORDER BY id DESC");
    $stmt->bind_param("ss", $search, $search);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query("SELECT * FROM school_inventory ORDER BY id DESC");
}

// Check for night mode cookie
$night_mode = isset($_COOKIE['night_mode']) && $_COOKIE['night_mode'] == 'enabled';
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Inventory Pro | School Supplies Store</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
      --stat-card-bg: white;
      --table-header-bg: #f8fafc;
      --table-border: #e2e8f0;
      --input-border: #e2e8f0;
      --input-bg: white;
      --modal-bg: white;
      --footer-bg: white;
      --highlight-bg: #fef3c7;
      --btn-secondary: #6c757d;
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
      --stat-card-bg: #1e1e2e;
      --table-header-bg: #2d2d3a;
      --table-border: #3a3a4a;
      --input-border: #3a3a4a;
      --input-bg: #1e1e2e;
      --modal-bg: #1e1e2e;
      --footer-bg: #1a1a2e;
      --highlight-bg: #f59e0b33;
      --btn-secondary: #4a5568;
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

    /* Cards */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }

    .stat-card {
      background: var(--stat-card-bg);
      border-radius: 20px;
      padding: 20px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
      transition: transform 0.3s;
    }

    .stat-card:hover {
      transform: translateY(-5px);
    }

    .stat-card h3 {
      color: var(--text-muted);
      font-size: 14px;
      margin-bottom: 10px;
    }

    .stat-card .value {
      font-size: 32px;
      font-weight: bold;
      color: #667eea;
    }

    /* Search Section */
    .search-section {
      background: var(--card-bg);
      border-radius: 20px;
      padding: 20px;
      margin-bottom: 30px;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
      transition: all 0.3s ease;
    }

    .search-box {
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }

    .search-box input {
      flex: 1;
      padding: 12px 16px;
      border: 2px solid var(--input-border);
      border-radius: 12px;
      font-size: 14px;
      transition: all 0.3s;
      background: var(--input-bg);
      color: var(--text-primary);
    }

    .search-box input:focus {
      outline: none;
      border-color: #667eea;
      box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .search-box input::placeholder {
      color: var(--text-muted);
    }

    .btn {
      padding: 12px 24px;
      border: none;
      border-radius: 12px;
      cursor: pointer;
      font-size: 14px;
      font-weight: 600;
      transition: all 0.3s;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .btn-primary {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
    }

    .btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }

    .btn-success {
      background: #10b981;
      color: white;
    }

    .btn-warning {
      background: #f59e0b;
      color: white;
    }

    .btn-danger {
      background: #ef4444;
      color: white;
    }

    .btn-secondary {
      background: var(--btn-secondary);
      color: white;
    }

    /* Table */
    .table-container {
      background: var(--card-bg);
      border-radius: 20px;
      overflow-x: auto;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
      transition: all 0.3s ease;
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

    table input {
      padding: 8px 12px;
      border: 1px solid var(--input-border);
      border-radius: 8px;
      width: 100%;
      transition: all 0.3s;
      background: var(--input-bg);
      color: var(--text-primary);
    }

    table input:focus {
      outline: none;
      border-color: #667eea;
    }

    /* Modal */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.6);
      backdrop-filter: blur(5px);
      justify-content: center;
      align-items: center;
    }

    .modal-content {
      background: var(--modal-bg);
      border-radius: 24px;
      padding: 30px;
      width: 500px;
      max-width: 90%;
      position: relative;
      animation: modalSlideIn 0.3s;
      color: var(--text-primary);
    }

    @keyframes modalSlideIn {
      from {
        transform: translateY(-50px);
        opacity: 0;
      }
      to {
        transform: translateY(0);
        opacity: 1;
      }
    }

    .close-btn {
      position: absolute;
      top: 20px;
      right: 20px;
      font-size: 24px;
      cursor: pointer;
      color: #94a3b8;
      transition: color 0.3s;
    }

    .close-btn:hover {
      color: #ef4444;
    }

    .modal-content h3 {
      margin-bottom: 20px;
      color: var(--text-primary);
    }

    .modal-content label {
      display: block;
      margin-bottom: 8px;
      font-weight: 500;
      color: var(--text-secondary);
    }

    .modal-content input {
      width: 100%;
      padding: 10px 12px;
      border: 2px solid var(--input-border);
      border-radius: 10px;
      margin-bottom: 15px;
      background: var(--input-bg);
      color: var(--text-primary);
    }

    .highlight {
      background: var(--highlight-bg) !important;
      transition: background 0.3s;
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
      .stats-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body class="<?php echo $night_mode ? 'night-mode' : ''; ?>">

<?php
// Calculate statistics
$stats = $conn->query("SELECT COUNT(*) as total, SUM(quantity) as total_qty, SUM(capital_price * quantity) as total_value FROM school_inventory");
$stats_data = $stats->fetch_assoc();
?>

<header>
  <h1><i class="fas fa-graduation-cap"></i> SchoolStore Pro</h1>
  <div class="header-actions">
    <button class="night-mode-toggle" onclick="toggleNightMode()" title="Toggle Night Mode">
      <i class="fas <?php echo $night_mode ? 'fa-sun' : 'fa-moon'; ?>"></i>
    </button>
    <i class="fas fa-bell" style="font-size: 20px; color: #667eea;"></i>
    <i class="fas fa-user-circle" style="font-size: 24px; color: #667eea;"></i>
  </div>
</header>

<div class="main">
  <div class="sidebar" id="sidebar">
    <div class="toggle-btn" onclick="toggleSidebar()">
      <i class="fas fa-chevron-left"></i>
    </div>
    <ul>
      <li><a href="home.php"><i class="fas fa-home"></i><span> Dashboard</span></a></li>
      <li><a href="inventory.php" class="active"><i class="fas fa-boxes"></i><span> Inventory</span></a></li>
      <li><a href="wholesale.php"><i class="fas fa-shopping-cart"></i><span> Wholesale</span></a></li>
      <li><a href="retail.php"><i class="fas fa-store"></i><span> Retail</span></a></li>
      <li><a href="histo.php"><i class="fas fa-history"></i><span> Sales History</span></a></li>
    </ul>
  </div>

  <div class="content">
    <div class="stats-grid">
      <div class="stat-card">
        <h3>Total Products</h3>
        <div class="value"><?= $stats_data['total'] ?? 0 ?></div>
      </div>
      <div class="stat-card">
        <h3>Total Quantity</h3>
        <div class="value"><?= $stats_data['total_qty'] ?? 0 ?></div>
      </div>
      <div class="stat-card">
        <h3>Inventory Value</h3>
        <div class="value">₱ <?= number_format($stats_data['total_value'] ?? 0, 2) ?></div>
      </div>
    </div>

    <div class="search-section">
      <div class="search-box">
        <form method="GET" style="display:flex; gap:12px; width:100%; flex-wrap:wrap;">
          <input type="text" name="search" placeholder="🔍 Search by barcode or product name..." 
                 value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>">
          <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
          <button type="button" class="btn btn-secondary" onclick="printTable()"><i class="fas fa-print"></i> Print</button>
          <button type="button" class="btn btn-success" onclick="openModal()"><i class="fas fa-plus"></i> Add Item</button>
        </form>
      </div>
      <div style="margin-top: 15px;">
        <input type="text" id="barcodeInput" placeholder="📷 Scan barcode here..." style="width:100%; padding:12px; border:2px solid var(--input-border); border-radius:12px; background: var(--input-bg); color: var(--text-primary);">
      </div>
    </div>

    <div class="table-container">
      <table id="inventoryTable">
        <thead>
            <tr>
            <th><i class="fas fa-barcode"></i> Barcode</th>
            <th><i class="fas fa-cubes"></i> Quantity</th>
            <th><i class="fas fa-tag"></i> Product Name</th>
            <th><i class="fas fa-coins"></i> Capital Price</th>
            <th><i class="fas fa-chart-line"></i> Wholesale</th>
            <th><i class="fas fa-shopping-bag"></i> Retail</th>
            <th><i class="fas fa-cog"></i> Actions</th>
            </tr>
        </thead>
        <tbody>
          <?php while($row = $result->fetch_assoc()): ?>
          <tr id="row-<?= htmlspecialchars($row['barcode']) ?>">
            <form method="POST">
                <td><input type="text" name="barcode" value="<?= htmlspecialchars($row['barcode']) ?>"></td>
                <td><input type="number" name="quantity" value="<?= htmlspecialchars($row['quantity']) ?>"></td>
                <td><input type="text" name="product_name" value="<?= htmlspecialchars($row['product_name']) ?>"></td>
                <td><input type="number" step="0.01" name="capital_price" value="<?= htmlspecialchars($row['capital_price']) ?>"></td>
                <td><input type="number" step="0.01" name="wholesale_price" value="<?= htmlspecialchars($row['wholesale_price']) ?>"></td>
                <td><input type="number" step="0.01" name="retail_price" value="<?= htmlspecialchars($row['retail_price']) ?>"></td>
                <td>
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <button type="submit" name="update" class="btn btn-warning" style="padding:6px 12px; font-size:12px;"><i class="fas fa-save"></i> Update</button>
                <a href="?delete=<?= $row['id'] ?>" class="btn btn-danger" style="padding:6px 12px; font-size:12px; text-decoration:none;" onclick="return confirm('Delete this item?')"><i class="fas fa-trash"></i> Delete</a>
                </td>
            </form>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div id="addModal" class="modal">
  <div class="modal-content">
    <span class="close-btn" onclick="closeModal()">&times;</span>
    <h3><i class="fas fa-plus-circle"></i> Add New Product</h3>
    <form method="POST">
      <label>Barcode:</label>
      <input type="text" name="barcode" required>
      <label>Quantity:</label>
      <input type="number" name="quantity" required>
      <label>Product Name:</label>
      <input type="text" name="product_name" required>
      <label>Capital Price (₱):</label>
      <input type="number" step="0.01" name="capital_price" required>
      <label>Wholesale Price (₱):</label>
      <input type="number" step="0.01" name="wholesale_price" required>
      <label>Retail Price (₱):</label>
      <input type="number" step="0.01" name="retail_price" required>
      <button type="submit" name="add" class="btn btn-success" style="width:100%;"><i class="fas fa-check"></i> Add Item</button>
    </form>
  </div>
</div>

<footer>
  <p>© 2024 SchoolStore Pro | Smart Inventory Management System</p>
</footer>

<script>
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

// Barcode scanner highlight
document.getElementById("barcodeInput").addEventListener("keypress", function(e) {
  if (e.key === "Enter") {
    e.preventDefault();
    let code = this.value.trim();
    if (code !== "") {
      let row = document.getElementById("row-" + code);
      if (row) {
        row.classList.add("highlight");
        row.scrollIntoView({behavior: "smooth", block: "center"});
        setTimeout(() => {
          row.classList.remove("highlight");
        }, 2000);
      } else {
        alert("❌ Barcode not found: " + code);
      }
      this.value = "";
    }
  }
});

function openModal() {
  document.getElementById("addModal").style.display = "flex";
}

function closeModal() {
  document.getElementById("addModal").style.display = "none";
}

function printTable() {
  var printContents = document.getElementById("inventoryTable").outerHTML;
  var originalTitle = document.title;
  document.title = "Inventory Report";
  var printWindow = window.open('', '_blank');
  printWindow.document.write(`
    <html>
      <head>
        <title>Inventory Report</title>
        <style>
          body { font-family: Arial, sans-serif; padding: 20px; background: white; }
          table { width: 100%; border-collapse: collapse; }
          th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
          th { background: #f2f2f2; }
        </style>
      </head>
      <body>
        <h2>School Supplies Inventory Report</h2>
        ${printContents}
        <p>Generated on: ${new Date().toLocaleString()}</p>
      </body>
    </html>
  `);
  printWindow.document.close();
  printWindow.print();
  printWindow.close();
  document.title = originalTitle;
}

// Close modal when clicking outside
window.onclick = function(event) {
  let modal = document.getElementById("addModal");
  if (event.target == modal) {
    modal.style.display = "none";
  }
}
</script>
</body>
</html>
