<?php
// save_sale.php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_bootstrap.php';

$conn = schoolStoreConnect(true);

$amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0.0;

// Create sales table if not exists
$create = "CREATE TABLE IF NOT EXISTS sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  amount DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$conn->query($create);

$stmt = $conn->prepare("INSERT INTO sales (amount) VALUES (?)");
if (!$stmt) { echo json_encode(["success"=>false,"message"=>"Prepare failed"]); exit; }
$stmt->bind_param("d", $amount);
if ($stmt->execute()) {
  echo json_encode(["success"=>true,"message"=>"Sale recorded."]);
} else {
  echo json_encode(["success"=>false,"message"=>"Failed to record sale."]);
}
$stmt->close();
$conn->close();
