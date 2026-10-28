<?php
// search_barcode.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_bootstrap.php';

$conn = schoolStoreConnect(true);

$barcode = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $barcode = isset($_POST['barcode']) ? $conn->real_escape_string(trim($_POST['barcode'])) : '';
} else {
  $barcode = isset($_GET['barcode']) ? $conn->real_escape_string(trim($_GET['barcode'])) : '';
}

if ($barcode === '') {
  echo json_encode(["success"=>false,"message"=>"No barcode provided"]);
  $conn->close(); exit;
}

$sql = "SELECT id, barcode, product_name, wholesale_price, capital_price, quantity
        FROM school_inventory
        WHERE barcode = '{$barcode}' LIMIT 1";
$res = $conn->query($sql);
if ($res && $res->num_rows > 0) {
  $row = $res->fetch_assoc();
  echo json_encode(["success"=>true, "product"=>[
    "id" => (int)$row['id'],
    "barcode" => $row['barcode'],
    "product_name" => $row['product_name'],
    "wholesale_price" => (float)$row['wholesale_price'],
    "capital_price" => (float)$row['capital_price'],
    "quantity" => (int)$row['quantity']
  ]]);
} else {
  echo json_encode(["success"=>false,"message"=>"Product not found"]);
}
$conn->close();
