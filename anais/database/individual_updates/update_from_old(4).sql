-- ANAIS SRS Missing Parts Migration
-- Use this if you already imported the old prototype database.

USE anais_db;

ALTER TABLE users MODIFY role ENUM('Owner','OIC','Employee','Supplier') NOT NULL;
ALTER TABLE users ADD COLUMN supplier_id INT NULL AFTER status;

ALTER TABLE products ADD COLUMN sku VARCHAR(50) NULL AFTER product_id;
UPDATE products SET sku = CONCAT('SKU-', product_id) WHERE sku IS NULL OR sku = '';
ALTER TABLE products MODIFY sku VARCHAR(50) NOT NULL;
ALTER TABLE products ADD UNIQUE KEY uq_products_sku (sku);
ALTER TABLE products ADD COLUMN default_supplier_id INT NULL AFTER unit_price;

ALTER TABLE purchase_orders ADD COLUMN is_draft TINYINT(1) NOT NULL DEFAULT 0 AFTER po_status;
ALTER TABLE purchase_orders ADD COLUMN supplier_status ENUM('Pending Confirmation','Confirmed','Processing','Shipped','In Transit','Delivered') NOT NULL DEFAULT 'Pending Confirmation' AFTER is_draft;
ALTER TABLE purchase_orders ADD COLUMN confirmed_at DATETIME NULL AFTER supplier_status;
