-- ANAIS Supplier Forced Password Change Update

ALTER TABLE users
ADD COLUMN IF NOT EXISTS must_change_password TINYINT UNSIGNED NOT NULL DEFAULT 0
AFTER supplier_id;

CREATE TABLE IF NOT EXISTS password_reset_requests (
  request_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id MEDIUMINT UNSIGNED NOT NULL,
  username VARCHAR(50) NOT NULL,
  role ENUM('Supplier') NOT NULL DEFAULT 'Supplier',
  status ENUM('Pending','Processed','Cancelled') NOT NULL DEFAULT 'Pending',
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME DEFAULT NULL,
  processed_by MEDIUMINT UNSIGNED DEFAULT NULL,
  temporary_password_shown VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (request_id),
  KEY user_id (user_id),
  KEY status (status),
  KEY requested_at (requested_at),
  KEY processed_by (processed_by),
  CONSTRAINT fk_password_reset_requests_user
    FOREIGN KEY (user_id) REFERENCES users(user_id)
    ON DELETE CASCADE,
  CONSTRAINT fk_password_reset_requests_processed_by
    FOREIGN KEY (processed_by) REFERENCES users(user_id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
