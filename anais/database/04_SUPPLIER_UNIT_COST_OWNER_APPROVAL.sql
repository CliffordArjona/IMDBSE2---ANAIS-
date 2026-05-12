-- ANAIS update: Supplier enters unit cost, Owner accepts/rejects price
-- Run this on an existing anais_db if you already imported an older database.

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

ALTER TABLE purchase_orders
ADD COLUMN IF NOT EXISTS price_response_note TEXT NULL AFTER payment_remarks;

-- If the ADD COLUMN line says Duplicate column name, ignore it.
