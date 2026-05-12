-- ANAIS Database Schema (minimum practical data types)
-- Autobox & Autophoria Inventory System
-- Default Owner Login: username owner / password Admin@1234

CREATE DATABASE IF NOT EXISTS anais_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE anais_db;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS supplier_deliveries;
DROP TABLE IF EXISTS po_items;
DROP TABLE IF EXISTS purchase_orders;
DROP TABLE IF EXISTS stock_transactions;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS suppliers;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    user_id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    full_name VARCHAR(80) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('Owner','OIC','Employee','Supplier') NOT NULL,
    branch ENUM('Autobox','Autophoria','Both') NOT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    supplier_id SMALLINT UNSIGNED NULL,
    created_by SMALLINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suppliers (
    supplier_id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    supplier_name VARCHAR(120) NOT NULL UNIQUE,
    contact_person VARCHAR(80) NOT NULL,
    contact_number VARCHAR(15) NOT NULL,
    email VARCHAR(120) NULL,
    address VARCHAR(255) NOT NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    remarks VARCHAR(255) NULL,
    created_by SMALLINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_suppliers_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
    product_id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    sku VARCHAR(50) NOT NULL UNIQUE,
    product_name VARCHAR(120) NOT NULL,
    category VARCHAR(60) NOT NULL,
    brand VARCHAR(60) NULL,
    unit VARCHAR(30) NOT NULL,
    branch ENUM('Autobox','Autophoria') NOT NULL,
    current_stock SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    reorder_level SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    unit_price DECIMAL(9,2) NOT NULL DEFAULT 0.00,
    default_supplier_id SMALLINT UNSIGNED NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_by SMALLINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_products_branch (branch),
    INDEX idx_products_category (category),
    INDEX idx_products_brand (brand),
    CONSTRAINT fk_products_supplier FOREIGN KEY (default_supplier_id) REFERENCES suppliers(supplier_id) ON DELETE SET NULL,
    CONSTRAINT fk_products_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_transactions (
    transaction_id MEDIUMINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    product_id SMALLINT UNSIGNED NOT NULL,
    transaction_type ENUM('Stock-In','Stock-Out') NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL,
    reference_no VARCHAR(60) NULL,
    transaction_date DATE NOT NULL,
    remarks VARCHAR(255) NULL,
    recorded_by SMALLINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_stock_type_date (transaction_type, transaction_date),
    CONSTRAINT fk_stock_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE,
    CONSTRAINT fk_stock_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_orders (
    po_id SMALLINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    po_number VARCHAR(50) NOT NULL UNIQUE,
    supplier_id SMALLINT UNSIGNED NOT NULL,
    branch ENUM('Autobox','Autophoria') NOT NULL,
    order_date DATE NOT NULL,
    expected_delivery_date DATE NOT NULL,
    po_status ENUM('Pending','Partially Delivered','Incomplete Delivery','Fully Delivered','Cancelled') NOT NULL DEFAULT 'Pending',
    is_draft TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    supplier_status ENUM('Pending Confirmation','Confirmed','Processing','Shipped','In Transit','Delivered','Incomplete Delivery') NOT NULL DEFAULT 'Pending Confirmation',
    confirmed_at TIMESTAMP NULL DEFAULT NULL,
    remarks VARCHAR(255) NULL,
    created_by SMALLINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_po_branch (branch),
    INDEX idx_po_status (po_status),
    CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id) ON DELETE RESTRICT,
    CONSTRAINT fk_po_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE po_items (
    po_item_id MEDIUMINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    po_id SMALLINT UNSIGNED NOT NULL,
    product_id SMALLINT UNSIGNED NOT NULL,
    quantity_ordered SMALLINT UNSIGNED NOT NULL,
    unit_cost DECIMAL(9,2) NOT NULL DEFAULT 0.00,
    CONSTRAINT fk_po_items_po FOREIGN KEY (po_id) REFERENCES purchase_orders(po_id) ON DELETE CASCADE,
    CONSTRAINT fk_po_items_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE supplier_deliveries (
    delivery_id MEDIUMINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    po_id SMALLINT UNSIGNED NOT NULL,
    product_id SMALLINT UNSIGNED NOT NULL,
    quantity_ordered SMALLINT UNSIGNED NOT NULL,
    quantity_received SMALLINT UNSIGNED NOT NULL,
    delivery_date DATE NOT NULL,
    delivery_receipt_no VARCHAR(60) NULL,
    received_by SMALLINT UNSIGNED NULL,
    remarks VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_delivery_po (po_id),
    CONSTRAINT fk_delivery_po FOREIGN KEY (po_id) REFERENCES purchase_orders(po_id) ON DELETE RESTRICT,
    CONSTRAINT fk_delivery_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE RESTRICT,
    CONSTRAINT fk_delivery_received_by FOREIGN KEY (received_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (full_name, username, password, role, branch, status)
VALUES ('Rodny Castillo', 'owner', '$2y$12$1Z5pulk4FffRZc39AE4VheZ0w5fTTE4BYuK7H3yfdeqhbIHRUVdLO', 'Owner', 'Both', 'Active');
