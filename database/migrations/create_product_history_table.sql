-- database/migrations/create_product_history_table.sql
CREATE TABLE IF NOT EXISTS `product_history` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `product_id` INT NOT NULL,
    `action_type` ENUM('create', 'update', 'delete', 'stock_in', 'stock_out', 'price_change') NOT NULL,
    `old_data` JSON,
    `new_data` JSON,
    `user_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_product_id (`product_id`),
    INDEX idx_action_type (`action_type`),
    INDEX idx_created_at (`created_at`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create trigger for automatic history tracking on product updates
DELIMITER //

CREATE TRIGGER product_update_history
AFTER UPDATE ON products
FOR EACH ROW
BEGIN
    INSERT INTO product_history (product_id, action_type, old_data, new_data, user_id)
    VALUES (
        NEW.id,
        CASE 
            WHEN OLD.quantity != NEW.quantity AND OLD.price = NEW.price THEN 'stock_out'
            WHEN OLD.price != NEW.price AND OLD.quantity = NEW.quantity THEN 'price_change'
            ELSE 'update'
        END,
        JSON_OBJECT(
            'name', OLD.name,
            'description', OLD.description,
            'price', OLD.price,
            'quantity', OLD.quantity,
            'category', OLD.category,
            'status', OLD.status
        ),
        JSON_OBJECT(
            'name', NEW.name,
            'description', NEW.description,
            'price', NEW.price,
            'quantity', NEW.quantity,
            'category', NEW.category,
            'status', NEW.status
        ),
        @current_user_id
    );
END//

CREATE TRIGGER product_insert_history
AFTER INSERT ON products
FOR EACH ROW
BEGIN
    INSERT INTO product_history (product_id, action_type, new_data, user_id)
    VALUES (
        NEW.id,
        'create',
        JSON_OBJECT(
            'name', NEW.name,
            'description', NEW.description,
            'price', NEW.price,
            'quantity', NEW.quantity,
            'category', NEW.category,
            'status', NEW.status
        ),
        @current_user_id
    );
END//

CREATE TRIGGER product_delete_history
BEFORE DELETE ON products
FOR EACH ROW
BEGIN
    INSERT INTO product_history (product_id, action_type, old_data, user_id)
    VALUES (
        OLD.id,
        'delete',
        JSON_OBJECT(
            'name', OLD.name,
            'description', OLD.description,
            'price', OLD.price,
            'quantity', OLD.quantity,
            'category', OLD.category,
            'status', OLD.status
        ),
        @current_user_id
    );
END//

DELIMITER ;