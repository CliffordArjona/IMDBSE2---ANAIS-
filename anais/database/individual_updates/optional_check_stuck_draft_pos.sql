-- ANAIS PO Confirm + Supplier Visibility Check
-- Run this only if you want to inspect stuck draft POs.

SELECT po_id, po_number, supplier_id, is_draft, po_status, supplier_status
FROM purchase_orders
ORDER BY po_id DESC;

-- Optional release if you want existing draft POs to appear immediately:
-- UPDATE purchase_orders
-- SET is_draft = 0,
--     supplier_status = 'Pending Confirmation',
--     updated_at = NOW()
-- WHERE is_draft = 1;
