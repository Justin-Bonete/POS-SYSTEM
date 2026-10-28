<?php
// api/sales/details.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../includes/auth.php';
require_once '../../config/database.php';

requireAuth();

try {
    $db = ConfigDatabase::getInstance()->getConnection();
    
    // Get sale ID or invoice number
    $saleId = isset($_GET['id']) ? (int)$_GET['id'] : null;
    $invoice = isset($_GET['invoice']) ? $_GET['invoice'] : null;
    
    if (!$saleId && !$invoice) {
        echo json_encode([
            'success' => false,
            'message' => 'Sale ID or invoice number required'
        ]);
        exit;
    }
    
    // Get sale details
    if ($saleId) {
        $query = "SELECT * FROM sales WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->execute([':id' => $saleId]);
    } else {
        $query = "SELECT * FROM sales WHERE invoice_number = :invoice";
        $stmt = $db->prepare($query);
        $stmt->execute([':invoice' => $invoice]);
    }
    
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$sale) {
        echo json_encode([
            'success' => false,
            'message' => 'Sale not found'
        ]);
        exit;
    }
    
    // Get sale items
    $query = "SELECT * FROM sale_items WHERE sale_id = :sale_id";
    $stmt = $db->prepare($query);
    $stmt->execute([':sale_id' => $sale['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'sale' => $sale,
            'items' => $items
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
