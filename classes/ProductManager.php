<?php
// classes/ProductManager.php

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/Product.php';
require_once __DIR__ . '/Validator.php';

class ProductManager {
    private $db;
    private $product;
    private $validator;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->product = new Product();
        $this->validator = new Validator();
    }
    
    /**
     * Add new product
     */
    public function addProduct($data) {
        if (!$this->validator->validateProduct($data)) {
            return [
                'success' => false,
                'errors' => $this->validator->getErrors()
            ];
        }
        
        try {
            // Generate product code
            $productCode = 'PRD' . strtoupper(uniqid()) . rand(100, 999);
            
            $query = "INSERT INTO products 
                      (product_code, name, description, price, quantity, category) 
                      VALUES 
                      (:code, :name, :desc, :price, :qty, :cat)";
            
            $stmt = $this->db->getConnection()->prepare($query);
            
            $stmt->bindParam(':code', $productCode);
            $stmt->bindParam(':name', $data['name']);
            $stmt->bindParam(':desc', $data['description']);
            $stmt->bindParam(':price', $data['price']);
            $stmt->bindParam(':qty', $data['quantity']);
            $stmt->bindParam(':cat', $data['category']);
            
            if ($stmt->execute()) {
                $productId = $this->db->lastInsertId();
                
                return [
                    'success' => true,
                    'message' => 'Product added successfully',
                    'product' => [
                        'id' => $productId,
                        'product_code' => $productCode,
                        'name' => $data['name'],
                        'price' => $data['price'],
                        'quantity' => $data['quantity'],
                        'category' => $data['category']
                    ]
                ];
            }
            
            return ['success' => false, 'message' => 'Failed to add product'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update product
     */
    public function updateProduct($id, $data) {
        $existingProduct = $this->product->getById($id);
        
        if (!$existingProduct) {
            return ['success' => false, 'message' => 'Product not found'];
        }
        
        // Merge existing data with new data
        $productData = array_merge($existingProduct, $data);
        
        if (!$this->validator->validateProduct($productData)) {
            return [
                'success' => false,
                'errors' => $this->validator->getErrors()
            ];
        }
        
        try {
            $allowedFields = ['name', 'description', 'price', 'quantity', 'category', 'status'];
            $updateData = [];
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateData[$field] = $data[$field];
                }
            }

            if (empty($updateData)) {
                return ['success' => false, 'message' => 'No fields to update'];
            }

            if (array_key_exists('quantity', $updateData)) {
                $updateData['expected_quantity'] = (int) $existingProduct['quantity'];
            }

            if (!$this->product->update($id, $updateData)) {
                return ['success' => false, 'message' => 'Product changed or failed to update'];
            }

            return [
                'success' => true,
                'message' => 'Product updated successfully',
                'product' => $this->product->getById($id)
            ];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Delete product
     */
    public function deleteProduct($id, $permanent = false) {
        $product = $this->product->getById($id);
        
        if (!$product) {
            return ['success' => false, 'message' => 'Product not found'];
        }
        
        try {
            $hasDeletedAt = false;
            try {
                $colStmt = $this->db->getConnection()->query("SHOW COLUMNS FROM products LIKE 'deleted_at'");
                $hasDeletedAt = (bool) $colStmt->fetch();
            } catch (PDOException $e) {
                // Ignore: table may not have the column yet
            }

            if ($permanent) {
                $query = "DELETE FROM products WHERE id = :id";
            } else {
                if ($hasDeletedAt) {
                    $query = "UPDATE products SET status = 'inactive', deleted_at = NOW() WHERE id = :id";
                } else {
                    $query = "UPDATE products SET status = 'inactive' WHERE id = :id";
                }
            }

            $stmt = $this->db->getConnection()->prepare($query);
            $stmt->bindParam(':id', $id);

            if ($stmt->execute()) {
                return [
                    'success' => true,
                    'message' => $permanent ? 'Product permanently deleted' : 'Product deactivated successfully'
                ];
            }

            return ['success' => false, 'message' => 'Failed to delete product'];

        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get low stock alert
     */
    public function getLowStockAlert($threshold = 10) {
        $products = $this->product->getLowStock($threshold);
        
        return [
            'success' => true,
            'data' => $products,
            'count' => count($products),
            'threshold' => $threshold
        ];
    }
}
?>