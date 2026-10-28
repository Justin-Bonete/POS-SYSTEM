<?php
// classes/SalesManager.php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/Product.php';
require_once __DIR__ . '/Sale.php';
require_once __DIR__ . '/Validator.php';

class SalesManager {
    private $db;
    private $product;
    private $sale;
    private $validator;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->product = new Product();
        $this->sale = new Sale();
        $this->validator = new Validator();
    }
    
    /**
     * Process a new sale
     */
    public function processSale($data) {
        // Validate sale data
        if (!$this->validator->validateSale($data)) {
            return [
                'success' => false,
                'errors' => $this->validator->getErrors()
            ];
        }
        
        try {
            $this->db->beginTransaction();
            
            // Calculate total and validate stock
            $totalAmount = 0;
            $items = [];
            
            foreach ($data['items'] as $item) {
                $product = $this->product->getById($item['product_id']);
                
                if (!$product) {
                    throw new Exception("Product not found: ID {$item['product_id']}");
                }
                
                // Check stock
                if ($product['quantity'] < $item['quantity']) {
                    throw new Exception("Insufficient stock for product: {$product['name']}");
                }
                
                // Use provided price or product price
                $price = isset($item['price']) ? $item['price'] : $product['price'];
                $subtotal = $price * $item['quantity'];
                $totalAmount += $subtotal;
                
                $items[] = [
                    'product_id' => $product['id'],
                    'product_name' => $product['name'],
                    'quantity' => $item['quantity'],
                    'price_per_unit' => $price,
                    'subtotal' => $subtotal
                ];
            }
            
            // Create sale record
            $saleData = [
                'invoice_number' => 'INV-' . date('Ymd-His') . '-' . strtoupper(substr(uniqid(), -6)),
                'user_id' => $data['user_id'] ?? ($_SESSION['user_id'] ?? null),
                'total_amount' => $totalAmount,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'payment_status' => 'paid',
                'customer_name' => $data['customer_name'] ?? null,
                'customer_email' => $data['customer_email'] ?? null,
                'customer_phone' => $data['customer_phone'] ?? null,
                'teller_name' => $data['teller_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'items' => $items,
            ];
            
            $saleId = $this->sale->create($saleData);
            
            if (!$saleId) {
                throw new Exception("Failed to create sale record");
            }
            
            // Sale::create inserts the sale items in the same transaction.
            foreach ($items as $item) {
                if (!$this->product->adjustStock(
                    $item['product_id'],
                    -(int) $item['quantity'],
                    'stock_out',
                    'sale',
                    $saleId,
                    "Sale #{$saleData['invoice_number']}"
                )) {
                    throw new Exception("Insufficient stock for product: {$item['product_name']}");
                }
            }
            
            $this->db->commit();
            
            // Get complete sale details
            $saleDetails = $this->sale->getById($saleId);
            $saleItems = $saleDetails['items'] ?? [];
            
            return [
                'success' => true,
                'message' => 'Sale processed successfully',
                'data' => [
                    'sale' => $saleDetails,
                    'items' => $saleItems,
                    'invoice' => $saleData['invoice_number'],
                    'total' => $totalAmount
                ]
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get sales history
     */
    public function getSalesHistory($filters = [], $page = 1, $limit = 20) {
        return $this->sale->getAll($filters, $page, $limit);
    }
    
    /**
     * Get sale details
     */
    public function getSaleDetails($saleId) {
        $sale = $this->sale->getById($saleId);
        
        if (!$sale) {
            return ['success' => false, 'message' => 'Sale not found'];
        }
        
        $items = $sale['items'] ?? [];
        
        return [
            'success' => true,
            'data' => [
                'sale' => $sale,
                'items' => $items
            ]
        ];
    }
    
    /**
     * Generate sales report
     */
    public function generateReport($startDate, $endDate, $type = 'summary') {
        if (!$this->validator->validateDateRange($startDate, $endDate)) {
            return [
                'success' => false,
                'errors' => $this->validator->getErrors()
            ];
        }
        
        switch ($type) {
            case 'summary':
                return $this->getSummaryReport($startDate, $endDate);
            case 'detailed':
                return $this->getDetailedReport($startDate, $endDate);
            case 'products':
                return $this->getProductSalesReport($startDate, $endDate);
            default:
                return ['success' => false, 'message' => 'Invalid report type'];
        }
    }
    
    /**
     * Get summary report
     */
    private function getSummaryReport($startDate, $endDate) {
                $query = "SELECT DATE(created_at) AS sale_date,
                                                 COUNT(*) AS total_sales,
                                                 COALESCE(SUM(total_amount), 0) AS total_revenue,
                                                 COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END), 0) AS cash_sales,
                                                 COALESCE(SUM(CASE WHEN payment_method = 'card' THEN total_amount ELSE 0 END), 0) AS card_sales,
                                                 COALESCE(SUM(CASE WHEN payment_method = 'online' THEN total_amount ELSE 0 END), 0) AS online_sales
                                    FROM sales
                                    WHERE DATE(created_at) BETWEEN :start_date AND :end_date
                                        AND payment_status = 'paid'
                                    GROUP BY DATE(created_at)
                                    ORDER BY sale_date";
                $stmt = $this->db->getConnection()->prepare($query);
                $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
                $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        $totals = [
            'total_sales' => 0,
            'total_revenue' => 0,
            'total_cash' => 0,
            'total_card' => 0,
            'total_online' => 0
        ];
        
        foreach ($summary as $day) {
            $totals['total_sales'] += $day['total_sales'];
            $totals['total_revenue'] += $day['total_revenue'];
            $totals['total_cash'] += $day['cash_sales'];
            $totals['total_card'] += $day['card_sales'];
            $totals['total_online'] += $day['online_sales'];
        }
        
        return [
            'success' => true,
            'data' => [
                'period' => [
                    'start' => $startDate,
                    'end' => $endDate
                ],
                'summary' => $summary,
                'totals' => $totals,
                'average_daily' => count($summary) > 0 ? $totals['total_revenue'] / count($summary) : 0
            ]
        ];
    }
    
    /**
     * Get detailed report
     */
    private function getDetailedReport($startDate, $endDate) {
        $filters = [
            'start_date' => $startDate,
            'end_date' => $endDate
        ];
        
        $salesResult = $this->sale->getAll($filters, 1, 1000);
        $sales = $salesResult['data'] ?? [];
        
        return [
            'success' => true,
            'data' => [
                'period' => [
                    'start' => $startDate,
                    'end' => $endDate
                ],
                'sales' => $sales,
                'total_sales' => count($sales),
                'total_revenue' => array_sum(array_column($sales, 'total_amount'))
            ]
        ];
    }
    
    /**
     * Get product sales report
     */
    private function getProductSalesReport($startDate, $endDate) {
        $query = "SELECT 
                    p.id,
                    p.name,
                    p.category,
                    SUM(si.quantity) as total_quantity_sold,
                    SUM(si.subtotal) as total_revenue,
                    COUNT(DISTINCT s.id) as number_of_transactions
                  FROM sale_items si
                  JOIN products p ON si.product_id = p.id
                  JOIN sales s ON si.sale_id = s.id
                  WHERE DATE(s.created_at) BETWEEN :start_date AND :end_date
                    AND s.payment_status = 'paid'
                  GROUP BY p.id, p.name, p.category
                  ORDER BY total_quantity_sold DESC";
        
        $stmt = $this->db->getConnection()->prepare($query);
        $stmt->bindParam(':start_date', $startDate);
        $stmt->bindParam(':end_date', $endDate);
        $stmt->execute();
        
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'success' => true,
            'data' => [
                'period' => [
                    'start' => $startDate,
                    'end' => $endDate
                ],
                'products' => $products,
                'total_products_sold' => array_sum(array_column($products, 'total_quantity_sold')),
                'total_revenue' => array_sum(array_column($products, 'total_revenue'))
            ]
        ];
    }
    
    /**
     * Cancel sale
     */
    public function cancelSale($saleId, $reason = '') {
        try {
            $this->db->beginTransaction();
            
            $sale = $this->sale->getById($saleId);
            if (!$sale) {
                throw new Exception('Sale not found');
            }

            $status = $sale['payment_status'] ?? '';
            if (!in_array($status, ['paid', 'pending'], true)) {
                throw new Exception('Only paid or pending sales can be cancelled');
            }

            if ($status === 'paid') {
                foreach ($sale['items'] ?? [] as $item) {
                    $productId = (int) ($item['product_id'] ?? 0);
                    $quantity = (int) ($item['quantity'] ?? 0);
                    if ($productId <= 0 || $quantity <= 0) {
                        continue;
                    }
                    if (!$this->product->adjustStock(
                        $productId,
                        $quantity,
                        'stock_in',
                        'sale',
                        (int) $saleId,
                        $reason !== '' ? 'Sale cancelled: ' . $reason : 'Sale cancelled'
                    )) {
                        throw new Exception("Failed restoring stock for product ID {$productId}");
                    }
                }
            }

            $statusUpdate = $this->db->getConnection()->prepare(
                "UPDATE sales
                 SET payment_status = 'cancelled',
                     notes = CONCAT(IFNULL(notes, ''), :note)
                 WHERE id = :id AND payment_status = :status"
            );
            $statusUpdate->execute([
                ':note' => $reason !== '' ? "\nCancelled: " . $reason : "\nCancelled",
                ':id' => $saleId,
                ':status' => $status,
            ]);
            if ($statusUpdate->rowCount() === 0) {
                throw new Exception('Sale status changed before cancellation completed');
            }
            
            $this->db->commit();
            
            return [
                'success' => true,
                'message' => 'Sale cancelled successfully'
            ];
            
        } catch (Exception $e) {
            $this->db->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
?>