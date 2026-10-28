<?php
// classes/Validator.php

class Validator {
    private $errors = [];
    
    /**
     * Validate product data
     */
    public function validateProduct($data) {
        $this->errors = [];
        
        // Name validation
        if (empty($data['name'])) {
            $this->errors['name'] = 'Product name is required';
        } elseif (strlen($data['name']) < 2) {
            $this->errors['name'] = 'Product name must be at least 2 characters';
        } elseif (strlen($data['name']) > 255) {
            $this->errors['name'] = 'Product name must be less than 255 characters';
        }
        
        // Price validation
        if (!isset($data['price'])) {
            $this->errors['price'] = 'Price is required';
        } elseif (!is_numeric($data['price'])) {
            $this->errors['price'] = 'Price must be a number';
        } elseif ($data['price'] <= 0) {
            $this->errors['price'] = 'Price must be greater than 0';
        } elseif ($data['price'] > 999999.99) {
            $this->errors['price'] = 'Price is too high';
        }
        
        // Quantity validation
        if (!isset($data['quantity'])) {
            $this->errors['quantity'] = 'Quantity is required';
        } elseif (!is_numeric($data['quantity'])) {
            $this->errors['quantity'] = 'Quantity must be a number';
        } elseif ($data['quantity'] < 0) {
            $this->errors['quantity'] = 'Quantity cannot be negative';
        } elseif ($data['quantity'] > 999999) {
            $this->errors['quantity'] = 'Quantity is too high';
        }
        
        // Category validation
        if (!empty($data['category']) && strlen($data['category']) > 100) {
            $this->errors['category'] = 'Category must be less than 100 characters';
        }
        
        return empty($this->errors);
    }
    
    /**
     * Validate sale data
     */
    public function validateSale($data) {
        $this->errors = [];
        
        // Items validation
        if (empty($data['items']) || !is_array($data['items'])) {
            $this->errors['items'] = 'At least one item is required';
        } else {
            foreach ($data['items'] as $index => $item) {
                if (empty($item['product_id'])) {
                    $this->errors["items.$index.product_id"] = 'Product ID is required';
                }
                if (empty($item['quantity']) || $item['quantity'] <= 0) {
                    $this->errors["items.$index.quantity"] = 'Valid quantity is required';
                }
                if (isset($item['price']) && (!is_numeric($item['price']) || $item['price'] < 0)) {
                    $this->errors["items.$index.price"] = 'Valid price is required';
                }
            }
        }
        
        // Payment method validation
        $validMethods = ['cash', 'card', 'online', 'other'];
        if (!empty($data['payment_method']) && !in_array($data['payment_method'], $validMethods)) {
            $this->errors['payment_method'] = 'Invalid payment method';
        }
        
        // Customer email validation
        if (!empty($data['customer_email']) && !filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL)) {
            $this->errors['customer_email'] = 'Invalid email format';
        }
        
        // Customer phone validation
        if (!empty($data['customer_phone']) && !preg_match('/^[0-9+\-\s()]{10,20}$/', $data['customer_phone'])) {
            $this->errors['customer_phone'] = 'Invalid phone number format';
        }
        
        return empty($this->errors);
    }
    
    /**
     * Validate category data
     */
    public function validateCategory($data) {
        $this->errors = [];
        
        // Name validation
        if (empty($data['name'])) {
            $this->errors['name'] = 'Category name is required';
        } elseif (strlen($data['name']) < 2) {
            $this->errors['name'] = 'Category name must be at least 2 characters';
        } elseif (strlen($data['name']) > 100) {
            $this->errors['name'] = 'Category name must be less than 100 characters';
        }
        
        return empty($this->errors);
    }
    
    /**
     * Validate date range
     */
    public function validateDateRange($startDate, $endDate) {
        $this->errors = [];
        
        $start = strtotime($startDate);
        $end = strtotime($endDate);
        
        if ($start === false) {
            $this->errors['start_date'] = 'Invalid start date format';
        }
        
        if ($end === false) {
            $this->errors['end_date'] = 'Invalid end date format';
        }
        
        if ($start && $end && $start > $end) {
            $this->errors['date_range'] = 'Start date must be before end date';
        }
        
        return empty($this->errors);
    }
    
    /**
     * Get validation errors
     */
    public function getErrors() {
        return $this->errors;
    }
    
    /**
     * Sanitize input
     */
    public function sanitize($input) {
        if (is_array($input)) {
            return array_map([$this, 'sanitize'], $input);
        }
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }
}
?>