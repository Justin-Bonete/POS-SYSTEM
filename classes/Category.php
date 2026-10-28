<?php
// classes/Category.php

require_once __DIR__ . '/database.php';

class Category {
    private $db;
    private $table = 'categories';
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * Create new category
     */
    public function create($data) {
        // Check if category already exists
        if ($this->getByName($data['name'])) {
            return ['success' => false, 'message' => 'Category already exists'];
        }
        
        $query = "INSERT INTO {$this->table} (name, description) VALUES (:name, :description)";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':description', $data['description']);
        
        if ($stmt->execute()) {
            return [
                'success' => true,
                'id' => $this->db->lastInsertId(),
                'message' => 'Category created successfully'
            ];
        }
        
        return ['success' => false, 'message' => 'Failed to create category'];
    }
    
    /**
     * Get all categories
     */
    public function getAll($status = 'active') {
        try {
            // Check if status column exists
            $checkQuery = "SHOW COLUMNS FROM {$this->table} LIKE 'status'";
            $checkStmt = $this->db->prepare($checkQuery);
            $checkStmt->execute();
            $hasStatus = $checkStmt->rowCount() > 0;
            
            if ($hasStatus) {
                $query = "SELECT c.*, 
                                 COUNT(p.id) as product_count 
                          FROM {$this->table} c
                          LEFT JOIN products p ON c.name = p.category AND p.status = 'active'
                          WHERE c.status = :status
                          GROUP BY c.id
                          ORDER BY c.name ASC";
                
                $stmt = $this->db->prepare($query);
                $stmt->bindParam(':status', $status);
            } else {
                // If status column doesn't exist, get all categories
                $query = "SELECT c.*, 
                                 COUNT(p.id) as product_count 
                          FROM {$this->table} c
                          LEFT JOIN products p ON c.name = p.category AND p.status = 'active'
                          GROUP BY c.id
                          ORDER BY c.name ASC";
                
                $stmt = $this->db->prepare($query);
            }
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            // Fallback to simple query if complex one fails
            $query = "SELECT * FROM {$this->table}";
            $stmt = $this->db->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    /**
     * Get category by ID
     */
    public function getById($id) {
        $query = "SELECT * FROM {$this->table} WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get category by name
     */
    public function getByName($name) {
        $query = "SELECT * FROM {$this->table} WHERE name = :name";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Update category
     */
    public function update($id, $data) {
        $query = "UPDATE {$this->table} SET name = :name, description = :description WHERE id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':name', $data['name']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':id', $id);
        
        return $stmt->execute();
    }
    
    /**
     * Delete category (soft delete if status column exists)
     */
    public function delete($id) {
        try {
            // Check if status column exists
            $checkQuery = "SHOW COLUMNS FROM {$this->table} LIKE 'status'";
            $checkStmt = $this->db->prepare($checkQuery);
            $checkStmt->execute();
            $hasStatus = $checkStmt->rowCount() > 0;

            // Check if category has products
            $query = "SELECT COUNT(*) as count FROM products WHERE category = (SELECT name FROM categories WHERE id = :id)";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $productCount = (int) ($result['count'] ?? 0);

            if ($productCount > 0) {
                if ($hasStatus) {
                    // Soft delete - just mark as inactive
                    $query = "UPDATE {$this->table} SET status = 'inactive' WHERE id = :id";
                    $stmt = $this->db->prepare($query);
                    $stmt->bindParam(':id', $id);

                    if ($stmt->execute()) {
                        return ['success' => true, 'message' => 'Category archived because products are still using it'];
                    }

                    return ['success' => false, 'message' => 'Failed to archive category'];
                }

                return ['success' => false, 'message' => 'Cannot delete category with products'];
            }

            // Hard delete - no products using this category
            $query = "DELETE FROM {$this->table} WHERE id = :id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $id);

            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Category deleted successfully'];
            }

            return ['success' => false, 'message' => 'Failed to delete category'];
        } catch (PDOException $e) {
            error_log("Error deleting category: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error while deleting category'];
        }
    }
    
    /**
     * Get category statistics
     */
    public function getStatistics() {
        // Check if status column exists
        $checkQuery = "SHOW COLUMNS FROM {$this->table} LIKE 'status'";
        $checkStmt = $this->db->prepare($checkQuery);
        $checkStmt->execute();
        $hasStatus = $checkStmt->rowCount() > 0;
        
        if ($hasStatus) {
            $query = "SELECT 
                        COUNT(*) as total_categories,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_categories,
                        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_categories,
                        (SELECT COUNT(DISTINCT category) FROM products WHERE category IS NOT NULL) as categories_with_products
                      FROM {$this->table}";
        } else {
            $query = "SELECT 
                        COUNT(*) as total_categories,
                        COUNT(*) as active_categories,
                        0 as inactive_categories,
                        (SELECT COUNT(DISTINCT category) FROM products WHERE category IS NOT NULL) as categories_with_products
                      FROM {$this->table}";
        }
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
