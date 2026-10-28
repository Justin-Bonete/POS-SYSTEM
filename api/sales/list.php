<?php
// api/sales/list.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Sale.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

try {
    $sale = new Sale();
    
    // Get filters from query string
    $filters = [];
    
    if (isset($_GET['start_date']) && !empty($_GET['start_date'])) {
        $filters['start_date'] = $_GET['start_date'];
    }
    
    if (isset($_GET['end_date']) && !empty($_GET['end_date'])) {
        $filters['end_date'] = $_GET['end_date'];
    }
    
    if (isset($_GET['payment_method']) && !empty($_GET['payment_method']) && $_GET['payment_method'] !== 'all') {
        $filters['payment_method'] = $_GET['payment_method'];
    }
    
    if (isset($_GET['status']) && !empty($_GET['status']) && $_GET['status'] !== 'all') {
        $filters['payment_status'] = $_GET['status'];
    }
    
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $filters['search'] = $_GET['search'];
    }
    
    // Pagination
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    
    // Get sales data
    $result = $sale->getAll($filters, $page, $limit);
    
    // Get summary stats
    $summary = $sale->getSummary($filters);
    
    echo json_encode([
        'success' => true,
        'data' => $result['data'] ?? [],
        'total' => $result['total'] ?? 0,
        'page' => $page,
        'limit' => $limit,
        'summary' => $summary
    ]);
    
} catch (Exception $e) {
    error_log("Sales list error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error loading sales data: ' . $e->getMessage()
    ]);
}
?>