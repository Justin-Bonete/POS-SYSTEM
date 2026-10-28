-- Add cost_price and wholesale_price to products table (safe to run once).
-- If your products table already has these columns, this migration is not needed.

ALTER TABLE `products`
  ADD COLUMN `cost_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `price`,
  ADD COLUMN `wholesale_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `cost_price`;

