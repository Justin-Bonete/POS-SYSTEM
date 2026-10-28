<?php
require_once __DIR__ . '/_bootstrap.php';

$conn = schoolStoreConnect();

$result = $conn->query("SELECT * FROM school_inventory ORDER BY id DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Agent Sales - School Store</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
  <style>
    *{margin:0;padding:0;box-sizing:border-box;font-family:Arial,sans-serif;}
    body{
      height:100vh;display:flex;flex-direction:column;
      background:url('https://images.unsplash.com/photo-1584362917165-81e48294d9a3?auto=format&fit=crop&w=1920&q=80') no-repeat center center fixed;
      background-size:cover;
    }
    header{height:60px;background:rgba(0,0,0,0.7);color:#fff;display:flex;justify-content:space-between;align-items:center;padding:0 20px;}
    header h1{font-size:22px;}
    header .logout{font-size:20px;cursor:pointer;}
    .main{flex:1;display:flex;}
    .sidebar{width:250px;background:rgba(0,0,0,0.8);padding-top:20px;transition:width 0.3s ease;overflow:hidden;}
    .sidebar.shrink{width:70px;}
    .sidebar .toggle-btn{color:#fff;font-size:20px;margin:10px 15px;cursor:pointer;}
    .sidebar ul{list-style:none;}
    .sidebar ul li a{display:flex;align-items:center;padding:15px 20px;color:#fff;text-decoration:none;white-space:nowrap;}
    .sidebar ul li a i{margin-right:10px;min-width:20px;text-align:center;}
    .sidebar ul li a:hover,.sidebar ul li a.active{background:#1a73e8;}
    .sidebar.shrink ul li span{display:none;}
    .content{flex:1;padding:20px;background:rgba(255,255,255,0.9);overflow-y:auto;}
    footer{height:40px;background:rgba(0,0,0,0.7);color:#fff;display:flex;justify-content:center;align-items:center;font-size:14px;}
    table{width:100%;border-collapse:collapse;margin-top:20px;background:#fff;}
    table th,table td{border:1px solid #ddd;padding:8px;text-align:center;}
    table th{background:#1a73e8;color:#fff;}
    .btn{padding:6px 12px;margin:2px;border:none;border-radius:3px;cursor:pointer;}
    .btn-add{background:#28a745;color:#fff;}
    .btn-view{background:#007bff;color:#fff;}
    .btn-checkout{background:#ffc107;color:#000;}
    .btn-remove{background:#dc3545;color:#fff;}
    .modal{display:none;position:fixed;z-index:1000;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.6);justify-content:center;align-items:center;}
    .modal-content{background:#fff;padding:20px;border-radius:8px;width:600px;max-width:95%;max-height:90%;overflow:auto;}
    .modal-header{display:flex;justify-content:space-between;align-items:center;}
    .modal-header h3{margin:0;}
    .close{cursor:pointer;font-size:20px;}
    .receipt-header{text-align:center;margin-bottom:10px;}
    .search-box{margin-top:15px;}
    .search-box input{width:100%;padding:8px;border:1px solid #ccc;border-radius:5px;}
    .receipt-buttons{display:flex;justify-content:flex-end;gap:10px;margin-top:10px;}
  </style>
</head>
<body>
<header>
  <h1>School Store Dashboard</h1>
  <div class="logout"><i class="fas fa-sign-out-alt"></i></div>
</header>

<div class="main">
  <!-- Sidebar -->
  <div class="sidebar" id="sidebar">
    <div class="toggle-btn" onclick="toggleSidebar()"><i class="fas fa-bars"></i></div>
    <ul>
      <li><a href="home.php"><i class="fas fa-home"></i><span> Home</span></a></li>
      <li><a href="inventory.php"><i class="fas fa-boxes"></i><span> Inventory</span></a></li>
      <li><a href="wholesale.php" class="active"><i class="fas fa-shopping-cart"></i><span> Sales</span></a></li>
      <li><a href="staff.php"><i class="fas fa-users"></i><span> Staff</span></a></li>
    </ul>
  </div>

  <!-- Content -->
  <div class="content">
    <h2>School Supplies Sales</h2>
    <button class="btn btn-view" onclick="openCart()">🛒 View Cart (<span id="cartCount">0</span>)</button>

    <!-- Unified Search Bar -->
    <div class="search-box">
      <input type="text" id="searchInput" placeholder="Search by Product Name or scan/type Barcode then press Enter" onkeyup="searchTableOrBarcode(event)" autofocus>
    </div>

    <!-- Inventory Table -->
    <table id="inventoryTable">
      <tr>
        <th>Barcode</th>
        <th>Product Name</th>
        <th>Capital Price</th>
        <th>Wholesale Price</th>
        <th>Quantity</th>
        <th>Action</th>
      </tr>
      <?php while($row = $result->fetch_assoc()): ?>
      <tr>
        <td><?= htmlspecialchars($row['barcode']) ?></td>
        <td><?= htmlspecialchars($row['product_name']) ?></td>
        <td>₱<?= number_format((float)$row['capital_price'],2) ?></td>
        <td>₱<?= number_format((float)$row['wholesale_price'],2) ?></td>
        <td><?= (int)$row['quantity'] ?></td>
        <td>
          <button class="btn btn-add addBtn"
            data-id="<?= (int)$row['id'] ?>"
            data-barcode="<?= htmlspecialchars($row['barcode']) ?>"
            data-product="<?= htmlspecialchars($row['product_name']) ?>"
            data-capital="<?= htmlspecialchars($row['capital_price']) ?>"
            data-price="<?= htmlspecialchars($row['wholesale_price']) ?>"
            data-stock="<?= (int)$row['quantity'] ?>">
            Add to Cart
          </button>
        </td>
      </tr>
      <?php endwhile; ?>
    </table>
  </div>
</div>

<!-- Cart Modal -->
<div id="cartModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Your Cart</h3>
      <span class="close" onclick="closeCart()">&times;</span>
    </div>
    <div id="cartItems"></div>
    <button class="btn btn-checkout" onclick="checkout()">Checkout</button>
  </div>
</div>

<!-- Receipt Modal -->
<div id="receiptModal" class="modal">
  <div class="modal-content">
    <div id="receiptContent"></div>
    <div class="receipt-buttons">
      <button class="btn btn-add" onclick="saveSale()">💾 Save to Sales</button>
      <button class="btn btn-view" onclick="printReceipt()">🖨 Print Receipt</button>
    </div>
  </div>
</div>

<footer>
  School Store | Created by: Ranie35
</footer>

<script>
// ✅ JavaScript is same as your original version, only field names adjusted
let cart=[];
let grandTotal=0;

function toggleSidebar(){document.getElementById("sidebar").classList.toggle("shrink");}
function openCart(){document.getElementById("cartModal").style.display="flex";renderCart();}
function closeCart(){document.getElementById("cartModal").style.display="none";}

// Attach event listeners to Add to Cart buttons
document.addEventListener("DOMContentLoaded",()=>{
  document.querySelectorAll(".addBtn").forEach(btn=>{
    btn.addEventListener("click",()=>{
      let qty = prompt("Enter quantity for "+btn.dataset.product+":", "1");
      if(qty !== null){
        qty = parseInt(qty,10);
        if(isNaN(qty) || qty<=0){alert("Invalid quantity!");return;}
        if(qty > parseInt(btn.dataset.stock,10)){alert("Not enough stock!");return;}
        let item={
          id:btn.dataset.id,
          barcode:btn.dataset.barcode,
          product:btn.dataset.product,
          capital:parseFloat(btn.dataset.capital),
          price:parseFloat(btn.dataset.price),
          stock:parseInt(btn.dataset.stock,10),
          qty:qty
        };
        addToCart(item);
      }
    });
  });
});

function addToCart(item){
  let found=cart.find(c=>c.id==item.id);
  if(found){
    if(found.qty + item.qty <= found.stock){
      found.qty += item.qty;
    }else{
      alert("Stock limit reached for "+item.product);
    }
  }else{
    if(item.stock > 0){
      cart.push(item);
    }else{
      alert("Out of stock!");
    }
  }
  document.getElementById("cartCount").innerText=cart.length;
}

function renderCart(){
  let html="<table><tr><th>Barcode</th><th>Product</th><th>Qty</th><th>Price</th><th>Total</th><th>Action</th></tr>";
  let total=0;
  cart.forEach((i,idx)=>{
    let t=i.qty*i.price;total+=t;
    html+=`<tr>
      <td>${escapeHtml(i.barcode)}</td>
      <td>${escapeHtml(i.product)}</td>
      <td><input type='number' min='1' max='${i.stock}' value='${i.qty}' onchange='updateQty(${idx},this.value)'></td>
      <td>₱${i.price.toFixed(2)}</td>
      <td>₱${t.toFixed(2)}</td>
      <td><button class="btn btn-remove" onclick="removeItem(${idx})">Cancel</button></td>
    </tr>`;
  });
  html+=`</table><h3>Total: ₱${total.toFixed(2)}</h3>`;
  document.getElementById("cartItems").innerHTML=html;
}
function updateQty(idx,val){
  let newQty=parseInt(val,10);
  if(isNaN(newQty) || newQty<1){ newQty = 1; }
  if(newQty > cart[idx].stock){
    alert("Not enough stock! Max: "+cart[idx].stock);
    newQty=cart[idx].stock;
  }
  cart[idx].qty=newQty;
  renderCart();
}
function removeItem(idx){
  cart.splice(idx,1);
  document.getElementById("cartCount").innerText=cart.length;
  renderCart();
}

// 🔹 Other functions (checkout, receipt, saveSale, numberToWords, search) remain same,
// just replace references to `generic`/`brand` with `product`.

function escapeHtml(text){
  const map = { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' };
  return String(text).replace(/[&<>"']/g, m => map[m]);
}
</script>
</body>
</html>
