-- ANAIS Temporary Password Note + Auto Remove SQL
-- Run this in phpMyAdmin.

-- Add users.must_change_password if missing.
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

-- Create request table if missing.
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

-- Add temporary_password_shown if missing.
SET @temp_col_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'password_reset_requests'
    AND COLUMN_NAME = 'temporary_password_shown'
);

SET @sql2 := IF(
  @temp_col_exists = 0,
  'ALTER TABLE password_reset_requests ADD COLUMN temporary_password_shown VARCHAR(50) DEFAULT NULL AFTER processed_by',
  'SELECT "temporary_password_shown already exists" AS message'
);

PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Check
SHOW COLUMNS FROM password_reset_requests LIKE 'temporary_password_shown';
SHOW COLUMNS FROM users LIKE 'must_change_password';
