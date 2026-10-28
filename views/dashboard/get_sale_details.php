<?php
session_start();
require_once '../../config/database.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

$sale_id = isset($_GET['sale_id']) ? $_GET['sale_id'] : 0;

// Get sale details
$query = "SELECT s.*, u.username FROM drugstore_sales s 
          LEFT JOIN users u ON s.user_id = u.id 
          WHERE s.id = :id";
$stmt = $db->prepare($query);
$stmt->execute([':id' => $sale_id]);
$sale = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sale) {
    echo json_encode(['success' => false, 'message' => 'Sale not found']);
    exit();
}

// Get sale items
$query = "SELECT si.*, p.brand_name, p.generic_name 
          FROM drugstore_sale_items si 
          JOIN drugstore_inventory p ON si.product_id = p.id 
          WHERE si.sale_id = :sale_id";
$stmt = $db->prepare($query);
$stmt->execute([':sale_id' => $sale_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'sale' => $sale,
    'items' => $items
]);
?>