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
