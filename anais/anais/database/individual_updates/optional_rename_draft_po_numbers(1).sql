-- OPTIONAL: Rename old DRAFT-PO numbers to PO numbers.
-- Run this only if you already generated Auto Purchase Orders with DRAFT-PO prefix
-- and you want existing records to display as PO-YYYYMMDD-###.

UPDATE purchase_orders
SET po_number = REPLACE(po_number, 'DRAFT-PO-', 'PO-')
WHERE po_number LIKE 'DRAFT-PO-%';

-- If this causes duplicate key error, do NOT force it.
-- It means you already have a PO with the same number.
-- New generated Auto Purchase Orders will no longer use DRAFT-PO.
