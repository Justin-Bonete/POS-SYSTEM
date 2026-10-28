<?php
require_once __DIR__ . '/_bootstrap.php';

$conn = schoolStoreConnect(true);

$cart = json_decode($_POST['cart'], true);

foreach ($cart as $item) {
  $id = (int)$item['id']; 
  $qty = (int)$item['qty'];

  $res = $conn->query("SELECT quantity FROM school_inventory WHERE id=$id");
  if ($res->num_rows==0) {
    echo json_encode(["success"=>false,"message"=>"Item not found."]); exit;
  }

  $row = $res->fetch_assoc(); 
  $stock=(int)$row['quantity'];
  if ($qty > $stock) {
    echo json_encode(["success"=>false,"message"=>"Not enough stock for item ID $id. Available: $stock"]); exit;
  }

  $newQty = $stock - $qty;
  $conn->query("UPDATE school_inventory SET quantity=$newQty WHERE id=$id");
}

echo json_encode(["success"=>true,"message"=>"Checkout successful!"]);
$conn->close();
?>
