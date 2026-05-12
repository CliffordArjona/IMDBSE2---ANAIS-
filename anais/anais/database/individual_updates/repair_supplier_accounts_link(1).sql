-- ANAIS Supplier Registration Verification / Repair SQL
-- Run only if you already created Supplier user accounts that are not visible in Suppliers tab.
-- This creates missing supplier records for Supplier users with no supplier_id,
-- then links users.supplier_id.

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

-- Check results:
SELECT supplier_id, supplier_name, status FROM suppliers ORDER BY supplier_id DESC;
SELECT user_id, full_name, username, role, supplier_id FROM users WHERE role='Supplier' ORDER BY user_id DESC;
