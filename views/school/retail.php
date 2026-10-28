<?php
require_once __DIR__ . '/_bootstrap.php';

$conn = schoolStoreConnect();

// Create retail sales tables if they don't exist
$conn->query("CREATE TABLE IF NOT EXISTS retail_sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_date DATETIME NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'completed',
    canceled_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS retail_sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    barcode VARCHAR(100) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    total_price DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sale_id (sale_id),
    FOREIGN KEY (sale_id) REFERENCES retail_sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$result = $conn->query("SELECT * FROM school_inventory ORDER BY id DESC");

// Check for night mode cookie
$night_mode = isset($_COOKIE['night_mode']) && $_COOKIE['night_mode'] == 'enabled';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Retail Sales | SchoolStore Pro</title>
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
      --card-bg: white;
      --table-header-bg: #f8fafc;
      --table-border: #e2e8f0;
      --input-border: #e2e8f0;
      --modal-bg: white;
      --footer-bg: white;
    }

    body.night-mode {
      --bg-gradient-start: #1a1a2e;
      --bg-gradient-end: #16213e;
      --header-bg: rgba(26, 26, 46, 0.98);
      --sidebar-bg: rgba(26, 26, 46, 0.95);
      --text-primary: #e2e8f0;
      --text-secondary: #cbd5e1;
      --card-bg: #1e1e2e;
      --table-header-bg: #2d2d3a;
      --table-border: #3a3a4a;
      --input-border: #3a3a4a;
      --modal-bg: #1e1e2e;
      --footer-bg: #1a1a2e;
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

    /* Page Header */
    .page-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 30px;
    }

    .page-header h2 {
      font-size: 28px;
      font-weight: 700;
      color: white;
    }

    /* Cart Button */
    .cart-btn {
      background: var(--card-bg);
      border: none;
      padding: 12px 24px;
      border-radius: 12px;
      font-size: 16px;
      font-weight: 600;
      color: #667eea;
      cursor: pointer;
      transition: all 0.3s;
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
    }

    .cart-btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
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
      background: var(--card-bg);
      color: var(--text-primary);
    }

    .search-box input:focus {
      outline: none;
      border-color: #667eea;
      box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .search-box input::placeholder {
      color: var(--text-secondary);
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

    /* Buttons */
    .btn {
      padding: 8px 16px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-size: 13px;
      font-weight: 600;
      transition: all 0.3s;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .btn-add {
      background: linear-gradient(135deg, #10b981 0%, #059669 100%);
      color: white;
    }

    .btn-add:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
    }

    .btn-checkout {
      background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
      color: white;
      width: 100%;
      margin-top: 15px;
    }

    .btn-remove {
      background: #ef4444;
      color: white;
    }

    .btn-print {
      background: #17a2b8;
      color: white;
      margin-top: 10px;
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
      width: 700px;
      max-width: 90%;
      max-height: 90%;
      overflow: auto;
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

    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 20px;
      padding-bottom: 15px;
      border-bottom: 2px solid var(--table-border);
    }

    .modal-header h3 {
      font-size: 24px;
      color: var(--text-primary);
    }

    .close {
      font-size: 28px;
      cursor: pointer;
      color: #94a3b8;
      transition: color 0.3s;
    }

    .close:hover {
      color: #ef4444;
    }

    /* Cart Table */
    #cartItems table {
      margin-top: 0;
    }

    #cartItems input {
      padding: 6px 10px;
      border: 2px solid var(--input-border);
      border-radius: 8px;
      width: 80px;
      text-align: center;
      background: var(--card-bg);
      color: var(--text-primary);
    }

    #cartItems h3 {
      margin-top: 20px;
      text-align: right;
      color: #667eea;
      font-size: 20px;
    }

    /* Receipt Area */
    #receiptArea {
      margin-top: 20px;
      border-top: 2px dashed var(--table-border);
      padding-top: 20px;
      display: none;
    }

    #printSection {
      background: var(--table-header-bg);
      padding: 20px;
      border-radius: 12px;
      color: var(--text-primary);
    }

    /* Highlight for scanned items */
    .highlight {
      background: #fef3c7 !important;
      transition: background 0.3s;
    }

    body.night-mode .highlight {
      background: #f59e0b33 !important;
    }

    footer {
      background: var(--footer-bg);
      padding: 15px 30px;
      text-align: center;
      color: var(--text-secondary);
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
    }

    /* Receipt Print Styles */
    @media print {
      body * {
        visibility: hidden;
      }
      #printSection, #printSection * {
        visibility: visible;
      }
      #printSection {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        font-size: 12px;
      }
      .btn-print {
        display: none;
      }
    }
  </style>
</head>
<body class="<?php echo $night_mode ? 'night-mode' : ''; ?>">

<header>
  <h1><i class="fas fa-graduation-cap"></i> SchoolStore Pro</h1>
  <div class="header-actions">
    <button class="night-mode-toggle" onclick="toggleNightMode()" title="Toggle Night Mode">
      <i class="fas <?php echo $night_mode ? 'fa-sun' : 'fa-moon'; ?>"></i>
    </button>
    <i class="fas fa-bell" style="font-size: 20px; color: #667eea;"></i>
    <i class="fas fa-sign-out-alt logout" style="margin-left: 20px;"></i>
  </div>
</header>

<div class="main">
  <div class="sidebar" id="sidebar">
    <div class="toggle-btn" onclick="toggleSidebar()">
      <i class="fas fa-chevron-left"></i>
    </div>
    <ul>
      <li><a href="home.php"><i class="fas fa-home"></i><span> Dashboard</span></a></li>
      <li><a href="inventory.php"><i class="fas fa-boxes"></i><span> Inventory</span></a></li>
      <li><a href="wholesale.php"><i class="fas fa-shopping-cart"></i><span> Wholesale</span></a></li>
      <li><a href="retail.php" class="active"><i class="fas fa-store"></i><span> Retail</span></a></li>
      <li><a href="histo.php"><i class="fas fa-history"></i><span> Sales History</span></a></li>
    </ul>
  </div>

  <div class="content">
    <div class="page-header">
      <h2><i class="fas fa-store"></i> Retail Sales</h2>
      <button class="cart-btn" onclick="openCart()">
        <i class="fas fa-shopping-cart"></i> View Cart (<span id="cartCount">0</span>)
      </button>
    </div>

    <div class="search-section">
      <div class="search-box">
        <input type="text" id="searchInput" placeholder="🔍 Search by product name or scan/type barcode then press Enter..." onkeyup="searchTableOrBarcode(event)" autofocus>
      </div>
      <div style="margin-top: 12px; font-size: 13px; color: var(--text-secondary);">
        <i class="fas fa-info-circle"></i> Tip: Scan barcode and press Enter to quickly find products
      </div>
    </div>

    <div class="table-container">
      <table id="inventoryTable">
        <thead>
          <tr>
            <th><i class="fas fa-barcode"></i> Barcode</th>
            <th><i class="fas fa-tag"></i> Product Name</th>
            <th><i class="fas fa-shopping-bag"></i> Retail Price</th>
            <th><i class="fas fa-cubes"></i> Stock</th>
            <th><i class="fas fa-cart-plus"></i> Action</th>
          </tr>
        </thead>
        <tbody>
          <?php while($row = $result->fetch_assoc()): ?>
          <tr id="row-<?= htmlspecialchars($row['barcode']) ?>">
            <td><?= htmlspecialchars($row['barcode']) ?></td>
            <td><?= htmlspecialchars($row['product_name']) ?></td>
            <td>₱<?= number_format((float)$row['retail_price'],2) ?></td>
            <td><?= (int)$row['quantity'] ?></td>
            <td>
              <button class="btn btn-add addBtn"
                data-id="<?= (int)$row['id'] ?>"
                data-barcode="<?= htmlspecialchars($row['barcode']) ?>"
                data-product="<?= htmlspecialchars($row['product_name']) ?>"
                data-price="<?= htmlspecialchars($row['retail_price']) ?>"
                data-stock="<?= (int)$row['quantity'] ?>">
                <i class="fas fa-plus"></i> Add to Cart
              </button>
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Cart Modal -->
<div id="cartModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fas fa-shopping-cart"></i> Your Shopping Cart</h3>
      <span class="close" onclick="closeCart()">&times;</span>
    </div>
    <div id="cartItems"></div>
    <button class="btn btn-checkout" onclick="checkout()">
      <i class="fas fa-credit-card"></i> Checkout
    </button>
    <div id="receiptArea"></div>
  </div>
</div>

<footer>
  <p>© 2024 SchoolStore Pro | Smart Inventory Management System</p>
</footer>

<script>
let cart = [];

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

function toggleSidebar() {
  const sidebar = document.getElementById('sidebar');
  const toggleIcon = document.querySelector('.toggle-btn i');
  
  if (sidebar.classList.contains('shrink')) {
    sidebar.classList.remove('shrink');
    toggleIcon.classList.remove('fa-chevron-right');
    toggleIcon.classList.add('fa-chevron-left');
  } else {
    sidebar.classList.add('shrink');
    toggleIcon.classList.remove('fa-chevron-left');
    toggleIcon.classList.add('fa-chevron-right');
  }
}

function openCart() {
  document.getElementById("cartModal").style.display = "flex";
  renderCart();
}

function closeCart() {
  document.getElementById("cartModal").style.display = "none";
  document.getElementById("receiptArea").style.display = "none";
  cart = [];
  document.getElementById("cartCount").innerText = 0;
}

// Add to Cart
document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".addBtn").forEach(btn => {
    btn.addEventListener("click", () => {
      let qty = prompt("Enter quantity for " + btn.dataset.product + ":", "1");
      if (qty !== null) {
        qty = parseInt(qty, 10);
        if (isNaN(qty) || qty <= 0) {
          alert("Invalid quantity!");
          return;
        }
        if (qty > parseInt(btn.dataset.stock, 10)) {
          alert("Not enough stock! Available: " + btn.dataset.stock);
          return;
        }
        let item = {
          id: btn.dataset.id,
          barcode: btn.dataset.barcode,
          product: btn.dataset.product,
          price: parseFloat(btn.dataset.price),
          stock: parseInt(btn.dataset.stock, 10),
          qty: qty
        };
        addToCart(item);
      }
    });
  });
});

function addToCart(item) {
  let found = cart.find(c => c.id == item.id);
  if (found) {
    if (found.qty + item.qty <= found.stock) {
      found.qty += item.qty;
    } else {
      alert("Stock limit reached for " + item.product);
    }
  } else {
    if (item.stock > 0) {
      cart.push(item);
    } else {
      alert("Out of stock!");
    }
  }
  document.getElementById("cartCount").innerText = cart.length;
}

function renderCart() {
  let html = `<table style="width:100%">
    <thead>
      <tr>
        <th>Barcode</th>
        <th>Product</th>
        <th>Qty</th>
        <th>Retail Price</th>
        <th>Total</th>
        <th>Action</th>
      </tr>
    </thead>
    <tbody>`;
  let total = 0;
  
  cart.forEach((i, idx) => {
    let t = i.qty * i.price;
    total += t;
    html += `<tr>
        <td>${escapeHtml(i.barcode)}</td>
        <td>${escapeHtml(i.product)}</td>
        <td><input type='number' min='1' max='${i.stock}' value='${i.qty}' onchange='updateQty(${idx},this.value)'></td>
        <td>₱${i.price.toFixed(2)}</td>
        <td>₱${t.toFixed(2)}</td>
        <td><button class="btn btn-remove" onclick="removeItem(${idx})"><i class="fas fa-trash"></i> Remove</button></td>
      </tr>`;
  });
  
  html += `</tbody>
  </table>
  <h3>Total Amount: ₱${total.toFixed(2)}</h3>`;
  
  document.getElementById("cartItems").innerHTML = html;
}

function updateQty(idx, val) {
  let newQty = parseInt(val, 10);
  if (isNaN(newQty) || newQty < 1) {
    newQty = 1;
  }
  if (newQty > cart[idx].stock) {
    alert("Not enough stock! Max: " + cart[idx].stock);
    newQty = cart[idx].stock;
  }
  cart[idx].qty = newQty;
  renderCart();
}

function removeItem(idx) {
  cart.splice(idx, 1);
  document.getElementById("cartCount").innerText = cart.length;
  renderCart();
}

function checkout() {
  if (cart.length === 0) {
    alert("Your cart is empty!");
    return;
  }
  
  const checkoutBtn = document.querySelector('.btn-checkout');
  const originalText = checkoutBtn.innerHTML;
  checkoutBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
  checkoutBtn.disabled = true;
  
  fetch("process_retail.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ cart: cart })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      showReceipt(data.sale_id);
      cart = [];
      document.getElementById("cartCount").innerText = 0;
      renderCart();
    } else {
      alert("❌ " + data.message);
    }
  })
  .catch(error => {
    console.error("Error:", error);
    alert("Error processing checkout. Please try again.");
  })
  .finally(() => {
    checkoutBtn.innerHTML = originalText;
    checkoutBtn.disabled = false;
  });
}

function showReceipt(saleId) {
  let total = cart.reduce((a, i) => a + i.qty * i.price, 0);
  let dateTime = new Date().toLocaleString();
  let html = `<div id="printSection">
    <h2 style="text-align:center;">SchoolStore Pro</h2>
    <h3 style="text-align:center;">Retail Receipt</h3>
    <p style="text-align:center;">Receipt #: ${saleId}</p>
    <p style="text-align:center;">${dateTime}</p>
    <hr>
    <table style="width:100%">`;
  
  cart.forEach(i => {
    html += `<tr>
      <td colspan="2"><strong>${escapeHtml(i.product)}</strong> </td>
    </tr>
    <tr>
      <td>${i.qty} x ₱${i.price.toFixed(2)}</td>
      <td style="text-align:right;">₱${(i.qty * i.price).toFixed(2)}</td>
    </tr>`;
  });
  
  html += `<tr>
      <td colspan="2"><hr></td>
    </tr>
    <tr>
      <td><strong>TOTAL</strong></td>
      <td style="text-align:right;"><strong>₱${total.toFixed(2)}</strong></td>
    </tr>
    </table>
    <hr>
    <p style="text-align:center;">Thank you for your purchase!</p>
    <p style="text-align:center;">Please come again!</p>
    <p style="text-align:center;">© 2024 SchoolStore Pro</p>
    </div>
    <button id="printBtn" class="btn btn-print" onclick="printReceipt()">
      <i class="fas fa-print"></i> Print Receipt
    </button>`;
  
  document.getElementById("receiptArea").innerHTML = html;
  document.getElementById("receiptArea").style.display = "block";
  document.getElementById("receiptArea").scrollIntoView({ behavior: "smooth", block: "start" });
}

function printReceipt() {
  window.print();
}

function searchTableOrBarcode(e) {
  const inputEl = document.getElementById("searchInput");
  let input = inputEl.value.trim().toLowerCase();
  let rows = document.querySelectorAll("#inventoryTable tbody tr");

  rows.forEach(row => {
    const barcode = row.cells[0].textContent.toLowerCase();
    const product = row.cells[1].textContent.toLowerCase();
    if (input === "" || product.includes(input) || barcode.includes(input)) {
      row.style.display = "";
    } else {
      row.style.display = "none";
    }
  });

  if (e.key === "Enter" && input !== "") {
    let row = Array.from(rows).find(r => r.cells[0].textContent.toLowerCase() === input);
    if (row) {
      row.scrollIntoView({ behavior: "smooth", block: "center" });
      row.classList.add("highlight");
      setTimeout(() => row.classList.remove("highlight"), 2000);
    } else {
      alert("❌ Product not found for barcode: " + input);
    }
  }
}

function escapeHtml(text) {
  const map = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  };
  return String(text).replace(/[&<>"']/g, m => map[m]);
}

window.onclick = function(event) {
  let modal = document.getElementById("cartModal");
  if (event.target == modal) {
    modal.style.display = "none";
  }
}
</script>
</body>
</html>
