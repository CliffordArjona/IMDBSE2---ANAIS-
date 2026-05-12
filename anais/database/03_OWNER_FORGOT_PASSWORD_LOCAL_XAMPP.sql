-- ANAIS Owner Forgot Password Reset for LOCAL XAMPP only
-- Use this only if the Owner forgot the password and you cannot log in.
-- It resets the first Owner account to temporary password: Owner@12345
-- After login, the system will force the Owner to create a new password.

USE anais_db;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS must_change_password TINYINT UNSIGNED NOT NULL DEFAULT 0;

UPDATE users
SET password = '$2y$12$E4k/YEwXRdpJjgE.7Yxq8ua7GK6qoiUijfsYf6ql7JqvQqqjjcJWu',
    must_change_password = 1,
    status = 'Active',
    updated_at = NOW()
WHERE role = 'Owner'
ORDER BY user_id ASC
LIMIT 1;
