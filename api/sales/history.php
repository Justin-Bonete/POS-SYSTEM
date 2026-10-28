<?php
// api/sales/history.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../config/database.php';
require_once '../../classes/Sale.php';

try {
    $db = Database::getInstance()->getConnection();
    $sale = new Sale();
    
    // Get parameters
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
    $startDate = isset($_GET['start_date']) ? $_GET['start_date'] : null;
    $endDate = isset($_GET['end_date']) ? $_GET['end_date'] : null;
    $paymentMethod = isset($_GET['payment_method']) ? $_GET['payment_method'] : null;
    $paymentStatus = isset($_GET['payment_status']) ? $_GET['payment_status'] : null;
    $search = isset($_GET['search']) ? $_GET['search'] : null;
    
    // Build query
    $query = "SELECT s.*, 
                     COUNT(si.id) as item_count,
                     SUM(si.quantity) as total_items
              FROM sales s
              LEFT JOIN sale_items si ON s.id = si.sale_id
              WHERE 1=1";
    
    $countQuery = "SELECT COUNT(DISTINCT s.id) as total FROM sales s WHERE 1=1";
    
    $params = [];
    
    // Add filters
    if ($startDate && $endDate) {
        $query .= " AND DATE(s.created_at) BETWEEN :start_date AND :end_date";
        $countQuery .= " AND DATE(s.created_at) BETWEEN :start_date AND :end_date";
        $params[':start_date'] = $startDate;
        $params[':end_date'] = $endDate;
    }
    
    if ($paymentMethod) {
        $query .= " AND s.payment_method = :method";
        $countQuery .= " AND s.payment_method = :method";
        $params[':method'] = $paymentMethod;
    }
    
    if ($paymentStatus) {
        $query .= " AND s.payment_status = :status";
        $countQuery .= " AND s.payment_status = :status";
        $params[':status'] = $paymentStatus;
    }
    
    if ($search) {
        $query .= " AND (s.invoice_number LIKE :search OR s.customer_name LIKE :search)";
        $countQuery .= " AND (s.invoice_number LIKE :search OR s.customer_name LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    // Group by and order
    $query .= " GROUP BY s.id ORDER BY s.created_at DESC";
    
    // Get total count
    $countStmt = $db->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalItems = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalItems / $limit);
    
    // Add pagination
    $offset = ($page - 1) * $limit;
    $query .= " LIMIT :limit OFFSET :offset";
    
    // Prepare and execute main query
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $sales,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_pages' => $totalPages,
            'total_items' => (int)$totalItems
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