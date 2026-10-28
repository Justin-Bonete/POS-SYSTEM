-- database/migrations/2026_03_16_add_deleted_at_to_products.sql
-- Add deleted_at column to products table for soft-deletes

ALTER TABLE products
  ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP NULL AFTER updated_at;
