-- ANAIS Supplier Action Status Sync SQL
-- Run this in phpMyAdmin so supplier_status accepts all supplier dropdown values.

ALTER TABLE purchase_orders
MODIFY supplier_status ENUM(
  'Pending Confirmation',
  'Confirmed',
  'Processing',
  'Shipped',
  'In Transit',
  'Delivered',
  'Incomplete Delivery'
) NOT NULL DEFAULT 'Pending Confirmation';
