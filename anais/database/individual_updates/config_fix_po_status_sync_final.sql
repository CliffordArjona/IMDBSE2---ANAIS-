-- ANAIS PO Owner/Supplier Sync Final SQL Patch
-- Run this in phpMyAdmin if status updates are rejected by MySQL enum values.

ALTER TABLE purchase_orders
MODIFY po_status ENUM(
  'Pending',
  'Partially Delivered',
  'Incomplete Delivery',
  'Fully Delivered',
  'Cancelled'
) NOT NULL DEFAULT 'Pending';

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
