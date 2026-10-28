<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/sale_discount.php';

class Sale {
    private $conn;
    private $table_name = "sales";
    
    public function __construct() {
        $database = ConfigDatabase::getInstance();
        $this->conn = $database->getConnection();
    }

    private function ensureSaleItemsAllowsBalanceRows() {
        try {
            $columnStmt = $this->conn->query("SHOW COLUMNS FROM sale_items LIKE 'product_id'");
            $column = $columnStmt ? $columnStmt->fetch(PDO::FETCH_ASSOC) : null;
            if ($column && strtoupper((string) ($column['Null'] ?? 'NO')) !== 'YES') {
                $this->conn->exec("ALTER TABLE sale_items MODIFY product_id INT NULL");
            }
        } catch (Throwable $e) {
            error_log("Unable to ensure nullable sale_items.product_id: " . $e->getMessage());
        }
    }

    private function ensureSalesTellerColumn(): void
    {
        try {
            $stmt = $this->conn->query("SHOW COLUMNS FROM {$this->table_name} LIKE 'teller_name'");
            $column = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if (!$column) {
                $this->conn->exec("ALTER TABLE {$this->table_name} ADD COLUMN teller_name VARCHAR(120) NULL AFTER customer_phone");
            }
        } catch (Throwable $e) {
            error_log("Unable to ensure sales.teller_name column: " . $e->getMessage());
        }
    }

    private function ensureSalesDiscountColumn(): void
    {
        saleDiscountEnsureSalesColumn($this->conn);
    }
    
    /**
     * Create a new sale
     */
    public function create($data) {
        try {
            $this->ensureSaleItemsAllowsBalanceRows();
            $this->ensureSalesTellerColumn();
            $this->ensureSalesDiscountColumn();
            // Only start a transaction if one isn't already active to avoid nested transactions
            $managedTransaction = false;
            if (!$this->conn->inTransaction()) {
                $this->conn->beginTransaction();
                $managedTransaction = true;
            }
            
            // Insert sale record - REMOVED subtotal and tax_amount since they don't exist in table
            $query = "INSERT INTO " . $this->table_name . " 
                      (invoice_number, user_id, customer_name, customer_email, customer_phone, teller_name,
                       total_amount, discount_amount, discount_percent, payment_method, payment_status, 
                       cash_amount, change_amount, notes, created_by, created_at) 
                      VALUES 
                      (:invoice_number, :user_id, :customer_name, :customer_email, :customer_phone, :teller_name,
                       :total_amount, :discount_amount, :discount_percent, :payment_method, :payment_status,
                       :cash_amount, :change_amount, :notes, :created_by, NOW())";
            
            $stmt = $this->conn->prepare($query);
            
            $stmt->bindParam(':invoice_number', $data['invoice_number']);
            $stmt->bindParam(':user_id', $data['user_id']);
            $stmt->bindParam(':customer_name', $data['customer_name']);
            $stmt->bindParam(':customer_email', $data['customer_email']);
            $stmt->bindParam(':customer_phone', $data['customer_phone']);
            $teller_name = $data['teller_name'] ?? null;
            $stmt->bindParam(':teller_name', $teller_name);
            $stmt->bindParam(':total_amount', $data['total_amount']);
            $discount_amount = isset($data['discount_amount']) ? saleDiscountToFloat($data['discount_amount']) : 0.0;
            $discount_percent = isset($data['discount_percent']) ? saleDiscountNormalizePercent($data['discount_percent']) : 0.0;
            $stmt->bindParam(':discount_amount', $discount_amount);
            $stmt->bindParam(':discount_percent', $discount_percent);
            $stmt->bindParam(':payment_method', $data['payment_method']);
            $stmt->bindParam(':payment_status', $data['payment_status']);
            
            // Bind cash payment fields
            $cash_amount = isset($data['cash_amount']) ? $data['cash_amount'] : null;
            $change_amount = isset($data['change_amount']) ? $data['change_amount'] : null;
            $created_by = $_SESSION['username'] ?? 'System';
            
            $stmt->bindParam(':cash_amount', $cash_amount);
            $stmt->bindParam(':change_amount', $change_amount);
            $stmt->bindParam(':notes', $data['notes']);
            $stmt->bindParam(':created_by', $created_by);
            
            $stmt->execute();
            $sale_id = $this->conn->lastInsertId();
            
            // Insert sale items
            if (!empty($data['items'])) {
                $item_query = "INSERT INTO sale_items 
                               (sale_id, product_id, product_name, quantity, price_per_unit, subtotal) 
                               VALUES 
                               (:sale_id, :product_id, :product_name, :quantity, :price_per_unit, :subtotal)";
                
                $item_stmt = $this->conn->prepare($item_query);
                
                foreach ($data['items'] as $item) {
                    $productId = isset($item['product_id']) && (int) $item['product_id'] > 0
                        ? (int) $item['product_id']
                        : null;

                    $item_stmt->bindValue(':sale_id', $sale_id, PDO::PARAM_INT);
                    if ($productId === null) {
                        $item_stmt->bindValue(':product_id', null, PDO::PARAM_NULL);
                    } else {
                        $item_stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
                    }
                    $item_stmt->bindValue(':product_name', $item['product_name']);
                    $item_stmt->bindValue(':quantity', $item['quantity']);
                    $item_stmt->bindValue(':price_per_unit', $item['price_per_unit']);
                    $item_stmt->bindValue(':subtotal', $item['subtotal']);
                    $item_stmt->execute();
                }
            }
            
            // Commit transaction only if we started it here
            if ($managedTransaction) {
                $this->conn->commit();
            }

            return $sale_id;
            
        } catch (Exception $e) {
            // Rollback only if we started the transaction here
            if (isset($managedTransaction) && $managedTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error creating sale: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            // If caller manages the transaction, rethrow so the caller can handle it
            if (isset($managedTransaction) && !$managedTransaction) {
                throw $e;
            }
            return false;
        }
    }
    
    /**
     * Get all sales with optional filters
     */
    public function getAll($filters = [], $page = 1, $limit = 50) {
        $offset = 0;
        try {
            $query = "SELECT s.*, COALESCE(u.username, s.created_by, 'System') as cashier_name,
                              (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as item_count
                       FROM " . $this->table_name . " s
                       LEFT JOIN users u ON s.user_id = u.id
                       WHERE 1=1";
            
            // Apply filters
            if (!empty($filters['start_date'])) {
                $query .= " AND DATE(s.created_at) >= :start_date";
            }
            
            if (!empty($filters['end_date'])) {
                $query .= " AND DATE(s.created_at) <= :end_date";
            }
            
            if (!empty($filters['payment_method']) && $filters['payment_method'] !== 'all') {
                $query .= " AND s.payment_method = :payment_method";
            }
            
            if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
                $query .= " AND s.payment_status = :payment_status";
            }
            
            if (!empty($filters['search'])) {
                $query .= " AND (s.invoice_number LIKE :search OR s.customer_name LIKE :search OR s.customer_phone LIKE :search)";
            }
            
            if (!empty($filters['user_id'])) {
                $query .= " AND s.user_id = :user_id";
            }
            
            $query .= " ORDER BY s.created_at DESC";
            
            // Add pagination
            if ($limit > 0) {
                $offset = ($page - 1) * $limit;
                $query .= " LIMIT :limit OFFSET :offset";
            }
            
            $stmt = $this->conn->prepare($query);
            
            // Bind filter values
            if (!empty($filters['start_date'])) {
                $stmt->bindParam(':start_date', $filters['start_date']);
            }
            
            if (!empty($filters['end_date'])) {
                $stmt->bindParam(':end_date', $filters['end_date']);
            }
            
            if (!empty($filters['payment_method']) && $filters['payment_method'] !== 'all') {
                $stmt->bindParam(':payment_method', $filters['payment_method']);
            }
            
            if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
                $stmt->bindParam(':payment_status', $filters['payment_status']);
            }
            
            if (!empty($filters['search'])) {
                $searchTerm = "%{$filters['search']}%";
                $stmt->bindParam(':search', $searchTerm);
            }
            
            if (!empty($filters['user_id'])) {
                $stmt->bindParam(':user_id', $filters['user_id']);
            }
            
            if ($limit > 0) {
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            }
            
            $stmt->execute();
            
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total count using the same filters as the current page.
            $countQuery = "SELECT COUNT(*) as total FROM " . $this->table_name . " s WHERE 1=1";
            $countParams = [];
            if (!empty($filters['start_date'])) {
                $countQuery .= " AND DATE(s.created_at) >= :start_date";
                $countParams[':start_date'] = $filters['start_date'];
            }
            if (!empty($filters['end_date'])) {
                $countQuery .= " AND DATE(s.created_at) <= :end_date";
                $countParams[':end_date'] = $filters['end_date'];
            }
            if (!empty($filters['payment_method']) && $filters['payment_method'] !== 'all') {
                $countQuery .= " AND s.payment_method = :payment_method";
                $countParams[':payment_method'] = $filters['payment_method'];
            }
            if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
                $countQuery .= " AND s.payment_status = :payment_status";
                $countParams[':payment_status'] = $filters['payment_status'];
            }
            if (!empty($filters['search'])) {
                $countQuery .= " AND (s.invoice_number LIKE :search OR s.customer_name LIKE :search OR s.customer_phone LIKE :search)";
                $countParams[':search'] = "%{$filters['search']}%";
            }
            if (!empty($filters['user_id'])) {
                $countQuery .= " AND s.user_id = :user_id";
                $countParams[':user_id'] = $filters['user_id'];
            }
            $countStmt = $this->conn->prepare($countQuery);
            $countStmt->execute($countParams);
            $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
            $total = $countResult['total'] ?? 0;
            
            return [
                'data' => $data,
                'total' => $total,
                'page' => $page,
                'limit' => $limit
            ];
            
        } catch (PDOException $e) {
            error_log("Error getting sales: " . $e->getMessage());
            return ['data' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
        }
    }
    
    /**
     * Get sales summary with filters
     */
    public function getSummary($filters = []) {
        try {
            $query = "SELECT 
                        COUNT(*) as total_transactions,
                        COALESCE(SUM(total_amount), 0) as total_sales,
                        COALESCE(AVG(total_amount), 0) as average_sale,
                        COUNT(DISTINCT CASE WHEN DATE(created_at) = CURDATE() THEN id END) as today_transactions,
                        COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE() THEN total_amount ELSE 0 END), 0) as today_sales,
                        COALESCE(SUM(CASE WHEN payment_method = 'cash' AND DATE(created_at) = CURDATE() THEN cash_amount ELSE 0 END), 0) as today_cash_collected,
                        COALESCE(SUM(CASE WHEN payment_method = 'cash' AND DATE(created_at) = CURDATE() THEN change_amount ELSE 0 END), 0) as today_change_given
                      FROM " . $this->table_name . "
                      WHERE 1=1";
            
            if (!empty($filters['start_date'])) {
                $query .= " AND DATE(created_at) >= :start_date";
            }
            
            if (!empty($filters['end_date'])) {
                $query .= " AND DATE(created_at) <= :end_date";
            }
            
            if (!empty($filters['payment_method']) && $filters['payment_method'] !== 'all') {
                $query .= " AND payment_method = :payment_method";
            }
            
            if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
                $query .= " AND payment_status = :payment_status";
            }
            
            $stmt = $this->conn->prepare($query);
            
            if (!empty($filters['start_date'])) {
                $stmt->bindParam(':start_date', $filters['start_date']);
            }
            
            if (!empty($filters['end_date'])) {
                $stmt->bindParam(':end_date', $filters['end_date']);
            }
            
            if (!empty($filters['payment_method']) && $filters['payment_method'] !== 'all') {
                $stmt->bindParam(':payment_method', $filters['payment_method']);
            }
            
            if (!empty($filters['payment_status']) && $filters['payment_status'] !== 'all') {
                $stmt->bindParam(':payment_status', $filters['payment_status']);
            }
            
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return [
                'total_sales' => floatval($result['total_sales'] ?? 0),
                'total_transactions' => intval($result['total_transactions'] ?? 0),
                'average_sale' => floatval($result['average_sale'] ?? 0),
                'today_sales' => floatval($result['today_sales'] ?? 0),
                'today_transactions' => intval($result['today_transactions'] ?? 0),
                'today_cash_collected' => floatval($result['today_cash_collected'] ?? 0),
                'today_change_given' => floatval($result['today_change_given'] ?? 0)
            ];
            
        } catch (PDOException $e) {
            error_log("Error getting sales summary: " . $e->getMessage());
            return [
                'total_sales' => 0,
                'total_transactions' => 0,
                'average_sale' => 0,
                'today_sales' => 0,
                'today_transactions' => 0,
                'today_cash_collected' => 0,
                'today_change_given' => 0
            ];
        }
    }
    
    /**
     * Get sale by ID with items
     */
    public function getById($id) {
        try {
            // Get sale details
            $query = "SELECT s.*, COALESCE(u.username, s.created_by, 'System') as cashier_name 
                      FROM " . $this->table_name . " s
                      LEFT JOIN users u ON s.user_id = u.id
                      WHERE s.id = :id";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            $sale = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($sale) {
                // Get sale items
                $item_query = "SELECT * FROM sale_items WHERE sale_id = :sale_id";
                $item_stmt = $this->conn->prepare($item_query);
                $item_stmt->bindParam(':sale_id', $id);
                $item_stmt->execute();
                
                $sale['items'] = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            return $sale;
            
        } catch (PDOException $e) {
            error_log("Error getting sale by ID: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update sale status
     */
    public function updateStatus($id, $status) {
        try {
            $query = "UPDATE " . $this->table_name . " SET payment_status = :status WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $id);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error updating sale status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get daily sales summary
     */
    public function getDailySummary($date = null) {
        if (!$date) {
            $date = date('Y-m-d');
        }
        
        try {
            $query = "SELECT 
                        COUNT(*) as total_transactions,
                        SUM(total_amount) as total_sales,
                        AVG(total_amount) as average_sale,
                        SUM(CASE WHEN payment_method = 'cash' THEN total_amount ELSE 0 END) as cash_sales,
                        SUM(CASE WHEN payment_method = 'card' THEN total_amount ELSE 0 END) as card_sales,
                        SUM(CASE WHEN payment_method = 'online' THEN total_amount ELSE 0 END) as online_sales,
                        SUM(CASE WHEN payment_method = 'cash' THEN cash_amount ELSE 0 END) as total_cash_collected,
                        SUM(CASE WHEN payment_method = 'cash' THEN change_amount ELSE 0 END) as total_change_given
                      FROM " . $this->table_name . "
                      WHERE DATE(created_at) = :date
                      AND payment_status = 'paid'";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':date', $date);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting daily summary: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get sales chart data
     */
    public function getChartData($period = 'week') {
        try {
            if ($period === 'week') {
                $query = "SELECT 
                            DAYNAME(created_at) as label,
                            DATE(created_at) as date,
                            SUM(total_amount) as total
                          FROM " . $this->table_name . "
                          WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                          GROUP BY DATE(created_at)
                          ORDER BY created_at";
            } else if ($period === 'month') {
                $query = "SELECT 
                            DATE_FORMAT(created_at, '%Y-%m-%d') as label,
                            DATE(created_at) as date,
                            SUM(total_amount) as total
                          FROM " . $this->table_name . "
                          WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                          GROUP BY DATE(created_at)
                          ORDER BY created_at";
            } else {
                $query = "SELECT 
                            DATE_FORMAT(created_at, '%Y-%m') as label,
                            SUM(total_amount) as total
                          FROM " . $this->table_name . "
                          WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                          GROUP BY YEAR(created_at), MONTH(created_at)
                          ORDER BY created_at";
            }
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            error_log("Error getting chart data: " . $e->getMessage());
            return [];
        }
    }
}
?>
