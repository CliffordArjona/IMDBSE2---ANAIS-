-- ANAIS Supplier Temporary Password SQL Fix
-- Compatible with XAMPP/MariaDB/MySQL versions that do not support ADD COLUMN IF NOT EXISTS.
-- Run this in phpMyAdmin SQL tab.

-- 1. Add must_change_password column if it does not exist.
SET @dbname = DATABASE();

SET @col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'users'
    AND COLUMN_NAME = 'must_change_password'
);

SET @sql := IF(
  @col_exists = 0,
  'ALTER TABLE users ADD COLUMN must_change_password TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER supplier_id',
  'SELECT "must_change_password already exists" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Create password reset request table if missing.
CREATE TABLE IF NOT EXISTS password_reset_requests (
  request_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id MEDIUMINT UNSIGNED NOT NULL,
  username VARCHAR(50) NOT NULL,
  role ENUM('Supplier') NOT NULL DEFAULT 'Supplier',
  status ENUM('Pending','Processed','Cancelled') NOT NULL DEFAULT 'Pending',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME DEFAULT NULL,
  processed_by MEDIUMINT UNSIGNED DEFAULT NULL,
  PRIMARY KEY (request_id),
  KEY user_id (user_id),
  KEY status (status),
  KEY requested_at (requested_at),
  KEY processed_by (processed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Add foreign keys only if your database allows them.
-- If phpMyAdmin shows duplicate foreign key error, ignore it.
-- The system works even without these constraints.

-- 4. Check installation.
SHOW COLUMNS FROM users LIKE 'must_change_password';
SHOW TABLES LIKE 'password_reset_requests';
DESCRIBE password_reset_requests;
