-- ============================================================
-- ANAIS CLEAN EMPTY DATABASE
-- Latest structure only + default Owner login
-- No products, no suppliers, no transactions, no PO records
-- ============================================================

DROP DATABASE IF EXISTS anais_db;
CREATE DATABASE anais_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE anais_db;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- SUPPLIERS
-- ============================================================

CREATE TABLE suppliers (
  supplier_id MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
  supplier_name VARCHAR(150) NOT NULL,
  contact_person VARCHAR(100) NOT NULL DEFAULT '',
  contact_number VARCHAR(20) NOT NULL DEFAULT '',
  email VARCHAR(150) DEFAULT NULL,
  address TEXT,
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  remarks TEXT DEFAULT NULL,
  created_by MEDIUMINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id),
  UNIQUE KEY supplier_name (supplier_name),
  KEY status (status),
  KEY created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- USERS / ACCOUNTS
-- ============================================================

CREATE TABLE users (
  user_id MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('Owner','OIC','Employee','Supplier') NOT NULL,
  branch ENUM('Autobox','Autophoria','Both') NOT NULL DEFAULT 'Both',
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  supplier_id MEDIUMINT UNSIGNED DEFAULT NULL,
  created_by MEDIUMINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id),
  UNIQUE KEY username (username),
  KEY role (role),
  KEY status (status),
  KEY supplier_id (supplier_id),
  KEY created_by (created_by),
  CONSTRAINT fk_users_supplier
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_users_created_by
    FOREIGN KEY (created_by) REFERENCES users(user_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE suppliers
  ADD CONSTRAINT fk_suppliers_created_by
  FOREIGN KEY (created_by) REFERENCES users(user_id)
  ON DELETE SET NULL;

-- ============================================================
-- PRODUCTS
-- ============================================================

CREATE TABLE products (
  product_id MEDIUMINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku VARCHAR(50) NOT NULL,
  product_name VARCHAR(150) NOT NULL,
  category VARCHAR(100) NOT NULL,
  brand VARCHAR(100) DEFAULT NULL,
  unit VARCHAR(30) NOT NULL,
  branch ENUM('Autobox','Autophoria') NOT NULL,
  current_stock MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
  reorder_level MEDIUMINT UNSIGNED NOT NULL DEFAULT 0,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  default_supplier_id MEDIUMINT UNSIGNED DEFAULT NULL,
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_by MEDIUMINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (product_id),
  UNIQUE KEY sku (sku),
  KEY product_name (product_name),
  KEY category (category),
  KEY brand (brand),
  KEY branch (branch),
  KEY status (status),
  KEY default_supplier_id (default_supplier_id),
  KEY low_stock_lookup (status, branch, current_stock, reorder_level),
  CONSTRAINT fk_products_supplier
    FOREIGN KEY (default_supplier_id) REFERENCES suppliers(supplier_id)
    ON DELETE SET NULL,
  CONSTRAINT fk_products_created_by
    FOREIGN KEY (created_by) REFERENCES users(user_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- STOCK TRANSACTIONS
-- product_id is intentionally nullable/no FK so old transaction
-- history can still show if a product is deleted.
-- ============================================================

CREATE TABLE stock_transactions (
  transaction_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  product_id MEDIUMINT UNSIGNED DEFAULT NULL,
  transaction_type ENUM('Stock-In','Stock-Out') NOT NULL,
  quantity MEDIUMINT UNSIGNED NOT NULL,
  reference_no VARCHAR(100) DEFAULT NULL,
  transaction_date DATE NOT NULL,
  discount_type ENUM('amount','percent') NOT NULL DEFAULT 'amount',
  discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  remarks TEXT DEFAULT NULL,
  recorded_by MEDIUMINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (transaction_id),
  KEY product_id (product_id),
  KEY transaction_type (transaction_type),
  KEY transaction_date (transaction_date),
  KEY created_at (created_at),
  KEY recorded_by (recorded_by),
  CONSTRAINT fk_stock_transactions_recorded_by
    FOREIGN KEY (recorded_by) REFERENCES users(user_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PURCHASE ORDERS
-- ============================================================

CREATE TABLE purchase_orders (
  po_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_number VARCHAR(50) NOT NULL,
  supplier_id MEDIUMINT UNSIGNED NOT NULL,
  branch ENUM('Autobox','Autophoria') NOT NULL,
  order_date DATE NOT NULL,
  expected_delivery_date DATE NOT NULL,
  po_status ENUM('Pending','Partially Delivered','Incomplete Delivery','Fully Delivered','Cancelled') NOT NULL DEFAULT 'Pending',
  is_draft TINYINT UNSIGNED NOT NULL DEFAULT 0,
  supplier_status ENUM(
    'Pending Confirmation',
    'Confirmed',
    'Processing',
    'Shipped',
    'In Transit',
    'Delivered',
    'Incomplete Delivery'
  ) NOT NULL DEFAULT 'Pending Confirmation',
  confirmed_at DATETIME DEFAULT NULL,
  remarks TEXT DEFAULT NULL,
  created_by MEDIUMINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (po_id),
  UNIQUE KEY po_number (po_number),
  KEY supplier_id (supplier_id),
  KEY branch (branch),
  KEY po_status (po_status),
  KEY supplier_status (supplier_status),
  KEY is_draft (is_draft),
  KEY created_by (created_by),
  CONSTRAINT fk_purchase_orders_supplier
    FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_purchase_orders_created_by
    FOREIGN KEY (created_by) REFERENCES users(user_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PURCHASE ORDER ITEMS
-- ============================================================

CREATE TABLE po_items (
  po_item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_id INT UNSIGNED NOT NULL,
  product_id MEDIUMINT UNSIGNED NOT NULL,
  quantity_ordered MEDIUMINT UNSIGNED NOT NULL,
  unit_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (po_item_id),
  KEY po_id (po_id),
  KEY product_id (product_id),
  CONSTRAINT fk_po_items_po
    FOREIGN KEY (po_id) REFERENCES purchase_orders(po_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_po_items_product
    FOREIGN KEY (product_id) REFERENCES products(product_id)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SUPPLIER DELIVERIES
-- ============================================================

CREATE TABLE supplier_deliveries (
  delivery_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  po_id INT UNSIGNED NOT NULL,
  product_id MEDIUMINT UNSIGNED NOT NULL,
  quantity_ordered MEDIUMINT UNSIGNED NOT NULL,
  quantity_received MEDIUMINT UNSIGNED NOT NULL,
  delivery_date DATE NOT NULL,
  delivery_receipt_no VARCHAR(100) DEFAULT NULL,
  received_by MEDIUMINT UNSIGNED DEFAULT NULL,
  remarks TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (delivery_id),
  KEY po_id (po_id),
  KEY product_id (product_id),
  KEY delivery_date (delivery_date),
  KEY received_by (received_by),
  CONSTRAINT fk_supplier_deliveries_po
    FOREIGN KEY (po_id) REFERENCES purchase_orders(po_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_supplier_deliveries_product
    FOREIGN KEY (product_id) REFERENCES products(product_id)
    ON DELETE RESTRICT,
  CONSTRAINT fk_supplier_deliveries_received_by
    FOREIGN KEY (received_by) REFERENCES users(user_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ACTIVITY LOGS
-- ============================================================

CREATE TABLE activity_logs (
  log_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id MEDIUMINT UNSIGNED DEFAULT NULL,
  action VARCHAR(100) NOT NULL,
  description TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (log_id),
  KEY user_id (user_id),
  KEY action (action),
  KEY created_at (created_at),
  CONSTRAINT fk_activity_logs_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- DEFAULT OWNER ACCOUNT ONLY
-- Username: owner
-- Password: Admin@1234
-- ============================================================

INSERT INTO users
  (full_name, username, password, role, branch, status, supplier_id, created_by, created_at, updated_at)
VALUES
  ('Rodny Castillo', 'owner', '$2y$12$1Z5pulk4FffRZc39AE4VheZ0w5fTTE4BYuK7H3yfdeqhbIHRUVdLO', 'Owner', 'Both', 'Active', NULL, NULL, NOW(), NOW());

-- ============================================================
-- END OF CLEAN EMPTY DATABASE
-- ============================================================
