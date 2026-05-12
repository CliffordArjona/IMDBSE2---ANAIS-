-- ============================================================
-- ANAIS FINAL UPDATE FOR EXISTING DATABASE
-- Run this only if you already have an old anais_db database.
-- If you are starting fresh, import 01_clean_empty_default_owner.sql instead.
-- ============================================================

USE anais_db;
SET @dbname = DATABASE();

-- ------------------------------------------------------------
-- USERS: Supplier role support, linked supplier, forced password change
-- ------------------------------------------------------------
ALTER TABLE users MODIFY role ENUM('Owner','OIC','Employee','Supplier') NOT NULL;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='users' AND COLUMN_NAME='supplier_id');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE users ADD COLUMN supplier_id MEDIUMINT UNSIGNED NULL AFTER status',
  'SELECT "users.supplier_id already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='users' AND COLUMN_NAME='must_change_password');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE users ADD COLUMN must_change_password TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER supplier_id',
  'SELECT "users.must_change_password already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- PASSWORD RESET REQUESTS: Employee/OIC/Supplier temporary password flow
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_reset_requests (
  request_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id MEDIUMINT UNSIGNED NOT NULL,
  username VARCHAR(50) NOT NULL,
  role ENUM('Employee','OIC','Supplier') NOT NULL DEFAULT 'Employee',
  status ENUM('Pending','Processed','Cancelled') NOT NULL DEFAULT 'Pending',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME DEFAULT NULL,
  processed_by MEDIUMINT UNSIGNED DEFAULT NULL,
  temporary_password_shown VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (request_id),
  KEY user_id (user_id),
  KEY status (status),
  KEY requested_at (requested_at),
  KEY processed_by (processed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='password_reset_requests' AND COLUMN_NAME='role');
SET @sql := IF(@col_exists>0,
  'ALTER TABLE password_reset_requests MODIFY role ENUM('Employee','OIC','Supplier') NOT NULL DEFAULT 'Employee'',
  'SELECT "password_reset_requests.role missing" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='password_reset_requests' AND COLUMN_NAME='temporary_password_shown');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE password_reset_requests ADD COLUMN temporary_password_shown VARCHAR(50) DEFAULT NULL AFTER processed_by',
  'SELECT "password_reset_requests.temporary_password_shown already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- PRODUCTS: SKU + default supplier for Auto PO
-- ------------------------------------------------------------
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='products' AND COLUMN_NAME='sku');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE products ADD COLUMN sku VARCHAR(50) NULL AFTER product_id',
  'SELECT "products.sku already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE products SET sku = CONCAT('SKU-', product_id) WHERE sku IS NULL OR sku = '';
ALTER TABLE products MODIFY sku VARCHAR(50) NOT NULL;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='products' AND INDEX_NAME='uq_products_sku');
SET @sql := IF(@idx_exists=0,
  'ALTER TABLE products ADD UNIQUE KEY uq_products_sku (sku)',
  'SELECT "uq_products_sku already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='products' AND COLUMN_NAME='default_supplier_id');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE products ADD COLUMN default_supplier_id MEDIUMINT UNSIGNED NULL AFTER unit_price',
  'SELECT "products.default_supplier_id already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- STOCK OUT: discount by amount/percent and total amount
-- ------------------------------------------------------------
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='stock_transactions' AND COLUMN_NAME='discount_type');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE stock_transactions ADD COLUMN discount_type ENUM(''amount'',''percent'') NOT NULL DEFAULT ''amount'' AFTER transaction_date',
  'SELECT "stock_transactions.discount_type already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='stock_transactions' AND COLUMN_NAME='discount_value');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE stock_transactions ADD COLUMN discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_type',
  'SELECT "stock_transactions.discount_value already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='stock_transactions' AND COLUMN_NAME='discount_amount');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE stock_transactions ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_value',
  'SELECT "stock_transactions.discount_amount already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='stock_transactions' AND COLUMN_NAME='total_amount');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE stock_transactions ADD COLUMN total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_amount',
  'SELECT "stock_transactions.total_amount already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE stock_transactions st
JOIN products p ON p.product_id = st.product_id
SET st.total_amount = (st.quantity * p.unit_price)
WHERE st.transaction_type = 'Stock-Out'
  AND st.total_amount = 0.00;

-- ------------------------------------------------------------
-- PURCHASE ORDER: draft PO, supplier status, incomplete delivery status
-- ------------------------------------------------------------
ALTER TABLE purchase_orders MODIFY po_status ENUM('Pending','Partially Delivered','Incomplete Delivery','Fully Delivered','Cancelled') NOT NULL DEFAULT 'Pending';

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='purchase_orders' AND COLUMN_NAME='is_draft');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE purchase_orders ADD COLUMN is_draft TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER po_status',
  'SELECT "purchase_orders.is_draft already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='purchase_orders' AND COLUMN_NAME='supplier_status');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE purchase_orders ADD COLUMN supplier_status ENUM(''Pending Confirmation'',''Confirmed'',''Processing'',''Shipped'',''In Transit'',''Delivered'',''Incomplete Delivery'',''Replacement Required'',''Replacement Scheduled'') NOT NULL DEFAULT ''Pending Confirmation'' AFTER is_draft',
  'SELECT "purchase_orders.supplier_status already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE purchase_orders MODIFY supplier_status ENUM('Pending Confirmation','Confirmed','Processing','Shipped','In Transit','Delivered','Incomplete Delivery') NOT NULL DEFAULT 'Pending Confirmation';

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME='purchase_orders' AND COLUMN_NAME='confirmed_at');
SET @sql := IF(@col_exists=0,
  'ALTER TABLE purchase_orders ADD COLUMN confirmed_at DATETIME NULL AFTER supplier_status',
  'SELECT "purchase_orders.confirmed_at already exists" AS message');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Recompute existing PO statuses based on received deliveries.
UPDATE purchase_orders po
LEFT JOIN (
    SELECT
        x.po_id,
        COALESCE(SUM(x.quantity_ordered),0) AS total_ordered,
        COALESCE(SUM(LEAST(x.quantity_received_total, x.quantity_ordered)),0) AS total_received,
        COALESCE(SUM(GREATEST(x.quantity_ordered - x.quantity_received_total, 0)),0) AS total_remaining
    FROM (
        SELECT
            pi.po_id,
            pi.product_id,
            SUM(pi.quantity_ordered) AS quantity_ordered,
            COALESCE(sd.quantity_received_total,0) AS quantity_received_total
        FROM po_items pi
        LEFT JOIN (
            SELECT po_id, product_id, SUM(quantity_received) AS quantity_received_total
            FROM supplier_deliveries
            GROUP BY po_id, product_id
        ) sd ON sd.po_id = pi.po_id AND sd.product_id = pi.product_id
        GROUP BY pi.po_id, pi.product_id, sd.quantity_received_total
    ) x
    GROUP BY x.po_id
) calc ON calc.po_id = po.po_id
SET
    po.po_status = CASE
        WHEN po.po_status = 'Cancelled' THEN po.po_status
        WHEN COALESCE(calc.total_ordered,0) > 0 AND COALESCE(calc.total_remaining,0) <= 0 THEN 'Fully Delivered'
        WHEN COALESCE(calc.total_received,0) > 0 THEN 'Partially Delivered'
        ELSE 'Pending'
    END,
    po.supplier_status = CASE
        WHEN po.po_status = 'Cancelled' THEN po.supplier_status
        WHEN COALESCE(calc.total_ordered,0) > 0 AND COALESCE(calc.total_remaining,0) <= 0 THEN 'Delivered'
        WHEN COALESCE(calc.total_received,0) > 0 THEN 'Incomplete Delivery'
        ELSE po.supplier_status
    END,
    po.updated_at = NOW()
WHERE po.po_status <> 'Cancelled';

-- ------------------------------------------------------------
-- REPAIR: link old supplier user accounts to supplier records
-- ------------------------------------------------------------
INSERT INTO suppliers
    (supplier_name, contact_person, contact_number, email, address, status, remarks, created_by, created_at, updated_at)
SELECT
    u.full_name,
    u.full_name,
    '',
    '',
    '',
    'Active',
    'Created by repair script for Supplier user account.',
    NULL,
    NOW(),
    NOW()
FROM users u
LEFT JOIN suppliers s ON s.supplier_name = u.full_name
WHERE u.role = 'Supplier'
  AND u.status = 'Active'
  AND (u.supplier_id IS NULL OR u.supplier_id = 0)
  AND s.supplier_id IS NULL;

UPDATE users u
JOIN suppliers s ON s.supplier_name = u.full_name
SET u.supplier_id = s.supplier_id,
    u.updated_at = NOW()
WHERE u.role = 'Supplier'
  AND u.status = 'Active'
  AND (u.supplier_id IS NULL OR u.supplier_id = 0);

-- Check important columns/tables.
SHOW COLUMNS FROM users LIKE 'must_change_password';
SHOW TABLES LIKE 'password_reset_requests';
SHOW COLUMNS FROM password_reset_requests LIKE 'temporary_password_shown';
SHOW COLUMNS FROM stock_transactions LIKE 'total_amount';
SHOW COLUMNS FROM purchase_orders LIKE 'supplier_status';


-- ============================================================
-- SRS ALIGNMENT UPDATE: Account details, barcode/warranty, PO payment/returns
-- ============================================================

ALTER TABLE users ADD COLUMN IF NOT EXISTS contact_number VARCHAR(30) NULL AFTER supplier_id;
ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(150) NULL AFTER contact_number;
ALTER TABLE users ADD COLUMN IF NOT EXISTS address TEXT NULL AFTER email;
ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER address;

ALTER TABLE products ADD COLUMN IF NOT EXISTS barcode VARCHAR(100) NULL AFTER sku;
ALTER TABLE products ADD COLUMN IF NOT EXISTS warranty_months SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER unit_price;

ALTER TABLE stock_transactions ADD COLUMN IF NOT EXISTS customer_id_no VARCHAR(80) NULL AFTER reference_no;
ALTER TABLE stock_transactions ADD COLUMN IF NOT EXISTS warranty_until DATE NULL AFTER transaction_date;

ALTER TABLE purchase_orders MODIFY branch ENUM('Autobox','Autophoria','Both') NOT NULL;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS payment_status ENUM('Unpaid','Partial','Paid') NOT NULL DEFAULT 'Unpaid' AFTER supplier_status;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS payment_method VARCHAR(50) NULL AFTER payment_status;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_method;
ALTER TABLE purchase_orders ADD COLUMN IF NOT EXISTS payment_remarks TEXT NULL AFTER amount_paid;

CREATE TABLE IF NOT EXISTS po_returns (
  return_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_id INT UNSIGNED NOT NULL,
  product_id MEDIUMINT UNSIGNED NOT NULL,
  quantity_returned MEDIUMINT UNSIGNED NOT NULL,
  return_type ENUM('Damaged','Wrong Item','Other') NOT NULL DEFAULT 'Damaged',
  return_date DATE NOT NULL,
  remarks TEXT DEFAULT NULL,
  recorded_by MEDIUMINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (return_id),
  KEY po_id (po_id),
  KEY product_id (product_id),
  KEY recorded_by (recorded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Supplier account usage tracking (optional but recommended)
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME DEFAULT NULL AFTER updated_at;


-- Supplier enters unit cost, Owner accepts/rejects price
-- ANAIS update: Supplier enters unit cost, Owner accepts/rejects price
-- Run this on an existing anais_db if you already imported an older database.

ALTER TABLE purchase_orders
MODIFY supplier_status ENUM(
  'Pending Confirmation',
  'Waiting for Supplier Price',
  'Supplier Prices Submitted',
  'Owner Accepted',
  'Owner Rejected',
  'Confirmed',
  'Processing',
  'Shipped',
  'In Transit',
  'Delivered',
  'Incomplete Delivery',
  'Replacement Required',
  'Replacement Scheduled'
) NOT NULL DEFAULT 'Pending Confirmation';

ALTER TABLE purchase_orders
ADD COLUMN IF NOT EXISTS price_response_note TEXT NULL AFTER payment_remarks;

-- If the ADD COLUMN line says Duplicate column name, ignore it.


-- ============================================================
-- SUPPLIER REDELIVERY / REPLACEMENT UPDATE
-- ============================================================
-- ANAIS update: Supplier redelivery/replacement for damaged or wrong returned PO items
-- Run this on an existing database if your supplier portal does not show redelivery scheduling.

ALTER TABLE purchase_orders
MODIFY supplier_status ENUM(
  'Pending Confirmation',
  'Waiting for Supplier Price',
  'Supplier Prices Submitted',
  'Owner Accepted',
  'Owner Rejected',
  'Confirmed',
  'Processing',
  'Shipped',
  'In Transit',
  'Delivered',
  'Incomplete Delivery',
  'Replacement Required',
  'Replacement Scheduled'
) NOT NULL DEFAULT 'Pending Confirmation';

ALTER TABLE po_returns
ADD COLUMN replacement_status ENUM('Required','Scheduled','Received','Cancelled') NOT NULL DEFAULT 'Required' AFTER recorded_by;

ALTER TABLE po_returns
ADD COLUMN replacement_qty MEDIUMINT UNSIGNED NULL AFTER replacement_status;

ALTER TABLE po_returns
ADD COLUMN estimated_redelivery_date DATE NULL AFTER replacement_qty;

ALTER TABLE po_returns
ADD COLUMN supplier_redelivery_note TEXT NULL AFTER estimated_redelivery_date;

ALTER TABLE po_returns
ADD COLUMN redelivery_scheduled_at DATETIME NULL AFTER supplier_redelivery_note;

-- If any ADD COLUMN line says Duplicate column name, remove that line and run the rest.
