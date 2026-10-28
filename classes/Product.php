<?php
// classes/Product.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/ProductHistory.php';

class Product {
    private $conn;
    private $table_name = "products";

    private function tableHasColumn($column) {
        static $cache = [];
        $key = strtolower($this->table_name . '.' . $column);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $this->conn->prepare("SHOW COLUMNS FROM `{$this->table_name}` LIKE :col");
            $stmt->execute([':col' => $column]);
            $cache[$key] = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $cache[$key] = false;
        }

        return $cache[$key];
    }
    
    public function __construct(?PDO $connection = null) {
        if ($connection instanceof PDO) {
            $this->conn = $connection;
            return;
        }

        $database = ConfigDatabase::getInstance();
        $this->conn = $database->getConnection();
    }
    
    /**
     * Get all products with optional filters
     */
    public function getAll($filters = [], $page = 1, $limit = 1000) {
        $offset = 0;
        try {
            $selectColumns = '*';
            if (!empty($filters['fields'])) {
                $requestedFields = is_array($filters['fields'])
                    ? $filters['fields']
                    : explode(',', (string)$filters['fields']);

                $allowedColumns = [
                    'id', 'product_code', 'name', 'description', 'price', 'quantity', 'category',
                    'qr_code_path', 'status', 'cost_price', 'wholesale_price', 'image_path',
                    'created_at', 'updated_at', 'deleted_at', 'user_id', 'created_by'
                ];

                $cleanFields = [];
                foreach ($requestedFields as $field) {
                    $field = trim((string)$field);
                    if ($field !== '' && in_array($field, $allowedColumns, true)) {
                        $cleanFields[] = $field;
                    }
                }

                if (!empty($cleanFields)) {
                    if (!in_array('id', $cleanFields, true)) {
                        $cleanFields[] = 'id';
                    }
                    $selectColumns = implode(', ', array_unique($cleanFields));
                }
            }

            $query = "SELECT {$selectColumns} FROM " . $this->table_name . " WHERE 1=1";
            
            // Apply filters
            if (isset($filters['id']) && $filters['id'] !== '' && $filters['id'] !== null) {
                $query .= " AND id = :id";
            }

            if (!empty($filters['product_code'])) {
                $query .= " AND product_code = :product_code";
            }

            if (!empty($filters['category'])) {
                $query .= " AND category = :category";
            }
            
            if (!empty($filters['status'])) {
                $query .= " AND status = :status";
            }
            
            if (!empty($filters['search'])) {
                $query .= " AND (name LIKE :search OR product_code LIKE :search)";
            }
            
            // Add pagination
            if ($limit > 0) {
                $offset = ($page - 1) * $limit;
                $query .= " LIMIT :limit OFFSET :offset";
            }
            
            $stmt = $this->conn->prepare($query);
            
            // Bind filter values
            if (isset($filters['id']) && $filters['id'] !== '' && $filters['id'] !== null) {
                $id = (int)$filters['id'];
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            }

            if (!empty($filters['product_code'])) {
                $stmt->bindParam(':product_code', $filters['product_code']);
            }

            if (!empty($filters['category'])) {
                $stmt->bindParam(':category', $filters['category']);
            }
            
            if (!empty($filters['status'])) {
                $stmt->bindParam(':status', $filters['status']);
            }
            
            if (!empty($filters['search'])) {
                $searchTerm = "%{$filters['search']}%";
                $stmt->bindParam(':search', $searchTerm);
            }
            
            if ($limit > 0) {
                $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
            }
            
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting products: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get product by ID
     */
    public function getById($id) {
        try {
            $query = "SELECT * FROM " . $this->table_name . " WHERE id = :id LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting product by ID: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get low stock products
     */
    public function getLowStock($threshold = 10) {
        try {
            $query = "SELECT * FROM " . $this->table_name . " 
                      WHERE quantity <= :threshold 
                      AND status = 'active'
                      ORDER BY quantity ASC";
            
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':threshold', $threshold, PDO::PARAM_INT);
            $stmt->execute();
            
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            return [
                'data' => $data,
                'total' => count($data)
            ];
        } catch (PDOException $e) {
            error_log("Error getting low stock products: " . $e->getMessage());
            return ['data' => [], 'total' => 0];
        }
    }
    
    /**
     * Get products by category
     */
    public function getByCategory($category) {
        try {
            $query = "SELECT * FROM " . $this->table_name . " WHERE category = :category AND status = 'active'";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':category', $category);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting products by category: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create new product
     */
    public function create($data) {
        try {
            $cols = ['product_code', 'name', 'description', 'price', 'quantity', 'category', 'qr_code_path', 'status'];
            $placeholders = [':product_code', ':name', ':description', ':price', ':quantity', ':category', ':qr_code_path', ':status'];
            $params = [
                ':product_code' => $data['product_code'] ?? '',
                ':name' => $data['name'] ?? '',
                ':description' => $data['description'] ?? '',
                ':price' => $data['price'] ?? 0,
                ':quantity' => $data['quantity'] ?? 0,
                ':category' => $data['category'] ?? '',
                ':qr_code_path' => $data['qr_code_path'] ?? '',
                ':status' => $data['status'] ?? 'active',
            ];

            if ($this->tableHasColumn('cost_price')) {
                $cols[] = 'cost_price';
                $placeholders[] = ':cost_price';
                $params[':cost_price'] = $data['cost_price'] ?? 0;
            }

            if ($this->tableHasColumn('wholesale_price')) {
                $cols[] = 'wholesale_price';
                $placeholders[] = ':wholesale_price';
                $params[':wholesale_price'] = $data['wholesale_price'] ?? 0;
            }

            $userCol = null;
            if ($this->tableHasColumn('user_id')) {
                $userCol = 'user_id';
            } elseif ($this->tableHasColumn('created_by')) {
                $userCol = 'created_by';
            }

            if ($userCol !== null) {
                $cols[] = $userCol;
                $placeholders[] = ':user_id';
                $params[':user_id'] = $data['user_id'] ?? null;
            }

            if ($this->tableHasColumn('created_at')) {
                $cols[] = 'created_at';
                $placeholders[] = 'NOW()';
            }
            if ($this->tableHasColumn('updated_at')) {
                $cols[] = 'updated_at';
                $placeholders[] = 'NOW()';
            }

            $query = "INSERT INTO " . $this->table_name . " (" . implode(', ', $cols) . ")
                      VALUES (" . implode(', ', $placeholders) . ")";

            $stmt = $this->conn->prepare($query);

            if ($stmt->execute($params)) {
                $product_id = $this->conn->lastInsertId();
                
                // Log to history
                if (class_exists('ProductHistory')) {
                    $history = new ProductHistory();
                    $history->logHistory(
                        $product_id,
                        'create',
                        $data['quantity'],
                        0,
                        $data['quantity'],
                        null,
                        null,
                        'Product created',
                        $data['user_id'] ?? null
                    );
                }
                
                return $product_id;
            }
            return false;
        } catch (PDOException $e) {
            error_log("Error creating product: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update product - FIXED: Now handles partial updates
     */
    public function update($id, $data) {
        try {
            // Get old product data for history
            $oldProduct = $this->getById($id);
            
            if (!$oldProduct) {
                error_log("Product ID $id not found for update");
                return false;
            }
            
            // Build update query dynamically
            $updateFields = [];
            $params = [':id' => $id];
            $expectedQuantity = null;

            if (array_key_exists('quantity', $data)) {
                $expectedQuantity = array_key_exists('expected_quantity', $data)
                    ? (int) $data['expected_quantity']
                    : (int) $oldProduct['quantity'];
                $params[':expected_quantity'] = $expectedQuantity;
            }
            
            // List of allowed fields
            $allowedFields = ['product_code', 'name', 'description', 'price', 'quantity', 'category', 'qr_code_path', 'status'];
            if ($this->tableHasColumn('cost_price')) {
                $allowedFields[] = 'cost_price';
            }
            if ($this->tableHasColumn('wholesale_price')) {
                $allowedFields[] = 'wholesale_price';
            }
            
            foreach ($allowedFields as $field) {
                if (array_key_exists($field, $data)) {
                    $updateFields[] = "`$field` = :$field";
                    $params[":$field"] = $data[$field];
                }
            }
            
            // Always update the updated_at timestamp if the column exists
            if ($this->tableHasColumn('updated_at')) {
                $updateFields[] = "updated_at = NOW()";
            }
            
            // If no fields to update, return true
            if (empty($updateFields)) {
                return true;
            }
            
            $quantityGuard = $expectedQuantity === null ? '' : ' AND quantity = :expected_quantity';
            $query = "UPDATE " . $this->table_name . " SET " . implode(', ', $updateFields) . " WHERE id = :id" . $quantityGuard;
            
            $stmt = $this->conn->prepare($query);
            
            foreach ($params as $key => $value) {
                if (is_int($value)) {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            
            $result = $stmt->execute();

            if ($result && $expectedQuantity !== null && $stmt->rowCount() === 0) {
                $currentProduct = $this->getById($id);
                if (!$currentProduct || (int) $currentProduct['quantity'] !== $expectedQuantity) {
                    return false;
                }
            }
            
            // Log quantity changes if quantity changed
            if ($result && isset($data['quantity']) && $oldProduct['quantity'] != $data['quantity']) {
                if (class_exists('ProductHistory')) {
                    try {
                        $history = new ProductHistory();
                        $quantity_change = $data['quantity'] - $oldProduct['quantity'];
                        $action_type = $quantity_change > 0 ? 'stock_in' : 'stock_out';
                        
                        $history->logHistory(
                            $id,
                            $action_type,
                            $quantity_change,
                            $oldProduct['quantity'],
                            $data['quantity'],
                            'manual',
                            null,
                            'Product updated',
                            $_SESSION['user_id'] ?? null
                        );
                    } catch (Exception $e) {
                        error_log("Failed to log product history: " . $e->getMessage());
                    }
                }
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error updating product: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete product
     */
    public function delete($id) {
        try {
            // Get product data for history
            $product = $this->getById($id);
            
            $query = "DELETE FROM " . $this->table_name . " WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':id', $id);
            
            $result = $stmt->execute();
            
            // Log deletion
            if ($result && $product && class_exists('ProductHistory')) {
                $history = new ProductHistory();
                $history->logHistory(
                    $id,
                    'delete',
                    -$product['quantity'],
                    $product['quantity'],
                    0,
                    null,
                    null,
                    'Product deleted',
                    $_SESSION['user_id'] ?? null
                );
            }
            
            return $result;
        } catch (PDOException $e) {
            error_log("Error deleting product: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update product quantity only - FIXED: Now properly updates
     */
    public function updateQuantity($id, $new_quantity, $action_type = 'adjustment', $reference_type = null, $reference_id = null, $notes = null) {
        try {
            // Get current quantity
            $product = $this->getById($id);
            if (!$product) {
                error_log("Product ID $id not found for quantity update");
                return false;
            }
            
            $old_quantity = $product['quantity'];
            $quantity_change = $new_quantity - $old_quantity;
            
            // Update product quantity
            $query = "UPDATE " . $this->table_name . " SET quantity = :quantity, updated_at = NOW() WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':quantity', $new_quantity);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                error_log("Quantity updated for product ID $id from $old_quantity to $new_quantity");
                
                // Log to history
                if (class_exists('ProductHistory')) {
                    try {
                        $history = new ProductHistory();
                        $history->logHistory(
                            $id,
                            $action_type,
                            $quantity_change,
                            $old_quantity,
                            $new_quantity,
                            $reference_type,
                            $reference_id,
                            $notes,
                            $_SESSION['user_id'] ?? null
                        );
                    } catch (Exception $e) {
                        error_log("Failed to log product history: " . $e->getMessage());
                    }
                }
                return true;
            }
            error_log("Failed to execute quantity update for product ID $id");
            return false;
        } catch (PDOException $e) {
            error_log("Error updating product quantity: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Apply a stock movement from the current locked quantity.
     */
    public function adjustStock($id, $quantity_change, $action_type = 'adjustment', $reference_type = null, $reference_id = null, $notes = null) {
        $quantity_change = (int) $quantity_change;
        if ($quantity_change === 0) {
            return true;
        }

        $ownsTransaction = !$this->conn->inTransaction();

        try {
            if ($ownsTransaction) {
                $this->conn->beginTransaction();
            }

            $driver = $this->conn->getAttribute(PDO::ATTR_DRIVER_NAME);
            $lockClause = $driver === 'mysql' ? ' FOR UPDATE' : '';
            $timestampExpression = $driver === 'sqlite' ? 'CURRENT_TIMESTAMP' : 'NOW()';
            $select = $this->conn->prepare("SELECT quantity FROM " . $this->table_name . " WHERE id = :id" . $lockClause);
            $select->execute([':id' => $id]);
            $product = $select->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                if ($ownsTransaction) {
                    $this->conn->rollBack();
                }
                return false;
            }

            $previous_quantity = (int) $product['quantity'];
            $new_quantity = $previous_quantity + $quantity_change;
            if ($new_quantity < 0) {
                if ($ownsTransaction) {
                    $this->conn->rollBack();
                }
                return false;
            }

            $update = $this->conn->prepare("UPDATE " . $this->table_name . " SET quantity = :quantity, updated_at = " . $timestampExpression . " WHERE id = :id");
            $update->execute([':quantity' => $new_quantity, ':id' => $id]);

            if (class_exists('ProductHistory')) {
                $history = new ProductHistory($this->conn);
                if (!$history->logHistory(
                    $id,
                    $action_type,
                    $quantity_change,
                    $previous_quantity,
                    $new_quantity,
                    $reference_type,
                    $reference_id,
                    $notes,
                    $_SESSION['user_id'] ?? null
                )) {
                    throw new RuntimeException('Stock history could not be recorded');
                }
            }

            if ($ownsTransaction) {
                $this->conn->commit();
            }
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log("Error adjusting stock for product ID $id: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Add stock to product
     */
    public function addStock($id, $quantity_to_add, $reference_type = null, $reference_id = null, $notes = null) {
        $quantity_to_add = (int) $quantity_to_add;
        if ($quantity_to_add <= 0) {
            return false;
        }

        return $this->adjustStock($id, $quantity_to_add, 'stock_in', $reference_type, $reference_id, $notes);
    }

    /**
     * Remove stock from product
     */
    public function removeStock($id, $quantity_to_remove, $reference_type = null, $reference_id = null, $notes = null) {
        $quantity_to_remove = (int) $quantity_to_remove;
        if ($quantity_to_remove <= 0) {
            return false;
        }

        return $this->adjustStock($id, -$quantity_to_remove, 'stock_out', $reference_type, $reference_id, $notes);
    }
    
    /**
     * Search products
     */
    public function search($term, $limit = 50) {
        try {
            $limit = max(1, min(200, (int)$limit));
            $query = "SELECT * FROM " . $this->table_name . " 
                      WHERE (name LIKE :term OR product_code LIKE :term OR category LIKE :term OR description LIKE :term)
                      AND status = 'active'
                      ORDER BY name ASC
                      LIMIT :limit";
            
            $stmt = $this->conn->prepare($query);
            $searchTerm = "%{$term}%";
            $stmt->bindParam(':term', $searchTerm);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error searching products: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get total product count
     */
    public function getTotalCount($filters = []) {
        try {
            $query = "SELECT COUNT(*) as total FROM " . $this->table_name . " WHERE 1=1";
            
            if (!empty($filters['category'])) {
                $query .= " AND category = :category";
            }
            
            if (!empty($filters['status'])) {
                $query .= " AND status = :status";
            }
            
            $stmt = $this->conn->prepare($query);
            
            if (!empty($filters['category'])) {
                $stmt->bindParam(':category', $filters['category']);
            }
            
            if (!empty($filters['status'])) {
                $stmt->bindParam(':status', $filters['status']);
            }
            
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result['total'] ?? 0;
        } catch (PDOException $e) {
            error_log("Error getting product count: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get categories
     */
    public function getCategories() {
        try {
            $query = "SELECT DISTINCT category FROM " . $this->table_name . " WHERE category IS NOT NULL AND category != '' ORDER BY category";
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Error getting categories: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get product stats
     */
    public function getStats() {
        $stats = [
            'total_products' => 0,
            'total_value' => 0,
            'low_stock' => 0,
            'out_of_stock' => 0
        ];

        try {
            $query = "SELECT 
                        COUNT(*) as total_products,
                        SUM(price * quantity) as total_value,
                        SUM(CASE WHEN quantity <= 10 AND quantity > 0 THEN 1 ELSE 0 END) as low_stock,
                        SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) as out_of_stock
                      FROM " . $this->table_name . "
                      WHERE status = 'active'";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute();
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $stats['total_products'] = (int)($result['total_products'] ?? 0);
                $stats['total_value'] = (float)($result['total_value'] ?? 0);
                $stats['low_stock'] = (int)($result['low_stock'] ?? 0);
                $stats['out_of_stock'] = (int)($result['out_of_stock'] ?? 0);
            }
            
            return $stats;
        } catch (PDOException $e) {
            error_log("Error getting product stats: " . $e->getMessage());
            return $stats;
        }
    }
}
?>
