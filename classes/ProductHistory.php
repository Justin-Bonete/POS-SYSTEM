<?php
require_once __DIR__ . '/../config/database.php';

class ProductHistory {
    private $conn;
    private $table = 'product_history';
    
    public function __construct(?PDO $connection = null) {
        if ($connection instanceof PDO) {
            $this->conn = $connection;
            return;
        }

        // Get database connection using the singleton pattern from ConfigDatabase
        $database = ConfigDatabase::getInstance();
        $this->conn = $database->getConnection();
    }
    
    /**
     * Log product history
     */
    public function logHistory($product_id, $action_type, $quantity_change, $previous_quantity, $new_quantity, $reference_type = null, $reference_id = null, $notes = null, $user_id = null) {
        try {
            // If user_id not provided, try to get from session
            if (!$user_id && isset($_SESSION['user_id'])) {
                $user_id = $_SESSION['user_id'];
            }
            
            $createdAtExpression = $this->conn->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
                ? 'CURRENT_TIMESTAMP'
                : 'NOW()';
            $query = "INSERT INTO " . $this->table . " 
                      (product_id, action_type, quantity_change, previous_quantity, new_quantity, 
                       reference_type, reference_id, notes, user_id, created_at) 
                      VALUES 
                      (:product_id, :action_type, :quantity_change, :previous_quantity, :new_quantity,
                       :reference_type, :reference_id, :notes, :user_id, " . $createdAtExpression . ")";
            
            $stmt = $this->conn->prepare($query);
            
            $stmt->bindParam(':product_id', $product_id);
            $stmt->bindParam(':action_type', $action_type);
            $stmt->bindParam(':quantity_change', $quantity_change);
            $stmt->bindParam(':previous_quantity', $previous_quantity);
            $stmt->bindParam(':new_quantity', $new_quantity);
            $stmt->bindParam(':reference_type', $reference_type);
            $stmt->bindParam(':reference_id', $reference_id);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':user_id', $user_id);
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error logging product history: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get recent product activities
     */
    public function getRecentActivities($limit = 20) {
        try {
            $query = "SELECT ph.*, 
                             p.name as product_name, 
                             p.product_code as product_sku,
                             COALESCE(u.username, 'System') as user_name,
                             COALESCE(u.full_name, 'System User') as user_full_name
                      FROM " . $this->table . " ph
                      LEFT JOIN products p ON ph.product_id = p.id
                      LEFT JOIN users u ON ph.user_id = u.id
                      ORDER BY ph.created_at DESC
                      LIMIT :limit";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting recent activities: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get stock movement stats for a date range
     */
    public function getStockMovementStats($start_date, $end_date) {
        $stats = [
            'total_in' => 0,
            'total_out' => 0,
            'total_adjustments' => 0,
            'total_updates' => 0
        ];

        try {
            $query = "SELECT 
                        COALESCE(SUM(CASE WHEN action_type = 'stock_in' THEN quantity_change ELSE 0 END), 0) as total_in,
                        COALESCE(SUM(CASE WHEN action_type = 'stock_out' THEN ABS(quantity_change) ELSE 0 END), 0) as total_out,
                        COALESCE(COUNT(CASE WHEN action_type = 'adjustment' THEN 1 END), 0) as total_adjustments,
                        COALESCE(COUNT(CASE WHEN action_type = 'update' THEN 1 END), 0) as total_updates
                      FROM " . $this->table . "
                      WHERE DATE(created_at) BETWEEN :start_date AND :end_date";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $stats['total_in'] = (int)($result['total_in'] ?? 0);
                $stats['total_out'] = (int)($result['total_out'] ?? 0);
                $stats['total_adjustments'] = (int)($result['total_adjustments'] ?? 0);
                $stats['total_updates'] = (int)($result['total_updates'] ?? 0);
            }
            
            return $stats;
        } catch (PDOException $e) {
            error_log("Error getting stock movement stats: " . $e->getMessage());
            return $stats;
        }
    }
    
    /**
     * Get top moving products
     */
    public function getTopMovingProducts($limit = 5) {
        try {
            $query = "SELECT 
                        ph.product_id,
                        p.name as product_name,
                        p.product_code as product_sku,
                        COALESCE(SUM(CASE WHEN ph.action_type = 'stock_in' THEN ph.quantity_change ELSE 0 END), 0) as total_in,
                        COALESCE(SUM(CASE WHEN ph.action_type = 'stock_out' THEN ABS(ph.quantity_change) ELSE 0 END), 0) as total_out,
                        COALESCE(SUM(ph.quantity_change), 0) as net_change,
                        COUNT(*) as total_movements
                      FROM " . $this->table . " ph
                      LEFT JOIN products p ON ph.product_id = p.id
                      WHERE ph.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      GROUP BY ph.product_id, p.name, p.product_code
                      HAVING total_in > 0 OR total_out > 0
                      ORDER BY (total_in + total_out) DESC
                      LIMIT :limit";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting top moving products: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get product history by product ID
     */
    public function getProductHistory($product_id, $limit = 50) {
        try {
            $query = "SELECT ph.*, 
                             COALESCE(u.username, 'System') as user_name,
                             COALESCE(u.full_name, 'System User') as user_full_name
                      FROM " . $this->table . " ph
                      LEFT JOIN users u ON ph.user_id = u.id
                      WHERE ph.product_id = :product_id
                      ORDER BY ph.created_at DESC
                      LIMIT :limit";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':product_id', $product_id);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting product history: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get history by date range
     */
    public function getHistoryByDateRange($start_date, $end_date, $action_type = null) {
        try {
            $query = "SELECT ph.*, 
                             p.name as product_name,
                             p.product_code as product_sku,
                             COALESCE(u.username, 'System') as user_name
                      FROM " . $this->table . " ph
                      LEFT JOIN products p ON ph.product_id = p.id
                      LEFT JOIN users u ON ph.user_id = u.id
                      WHERE DATE(ph.created_at) BETWEEN :start_date AND :end_date";
            
            if ($action_type && $action_type != 'all') {
                $query .= " AND ph.action_type = :action_type";
            }
            
            $query .= " ORDER BY ph.created_at DESC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            
            if ($action_type && $action_type != 'all') {
                $stmt->bindParam(':action_type', $action_type);
            }
            
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting history by date range: " . $e->getMessage());
            return [];
        }
    }
}
?>