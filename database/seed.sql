-- database/seed.sql - Initial data

USE pos_system;

-- Insert default admin user (password: admin123)
INSERT INTO users (username, email, password_hash, full_name, role) VALUES 
('admin', 'admin@example.com', '$2y$10$YourHashedPasswordHere', 'System Administrator', 'admin');

-- Insert default categories
INSERT INTO categories (name, description) VALUES 
('Electronics', 'Electronic devices and accessories'),
('Clothing', 'Apparel and fashion items'),
('Food & Beverages', 'Food and drink products'),
('Books', 'Books and publications'),
('Sports', 'Sports equipment and accessories');

-- Insert settings
INSERT INTO settings (setting_key, setting_value, description) VALUES 
('company_name', 'POS System', 'Company/Business name'),
('company_address', '', 'Company address'),
('company_phone', '', 'Company phone number'),
('company_email', '', 'Company email'),
('tax_rate', '0.00', 'Tax rate percentage'),
('currency_symbol', '$', 'Currency symbol'),
('receipt_footer', '', 'Footer text for receipts'),
('default_payment_method', 'cash', 'Default payment method for sales'); 
