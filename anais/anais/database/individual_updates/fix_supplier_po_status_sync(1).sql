-- ANAIS Supplier PO Status Sync Fix
-- Run this in phpMyAdmin if the Supplier Portal does not sync PO Status / Delivery Status.
-- This adds "Incomplete Delivery" to the supplier_status enum.

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

-- Optional repair: sync old/stale PO statuses based on actual received deliveries.
UPDATE purchase_orders po
LEFT JOIN (
    SELECT
        x.po_id,
        COALESCE(SUM(x.quantity_ordered),0) AS total_ordered,
        COALESCE(SUM(LEAST(x.quantity_received_total, x.quantity_ordered)),0) AS total_received,
        COALESCE(SUM(GREATEST(x.quantity_ordered - x.quantity_received_total, 0)),0) AS total_remaining
    FROM (
        SELECT
            pi.po_id,
            pi.product_id,
            SUM(pi.quantity_ordered) AS quantity_ordered,
            COALESCE(sd.quantity_received_total,0) AS quantity_received_total
        FROM po_items pi
        LEFT JOIN (
            SELECT po_id, product_id, SUM(quantity_received) AS quantity_received_total
            FROM supplier_deliveries
            GROUP BY po_id, product_id
        ) sd ON sd.po_id = pi.po_id AND sd.product_id = pi.product_id
        GROUP BY pi.po_id, pi.product_id, sd.quantity_received_total
    ) x
    GROUP BY x.po_id
) calc ON calc.po_id = po.po_id
SET
    po.po_status = CASE
        WHEN po.po_status = 'Cancelled' THEN po.po_status
        WHEN COALESCE(calc.total_ordered,0) > 0 AND COALESCE(calc.total_remaining,0) <= 0 THEN 'Fully Delivered'
        WHEN COALESCE(calc.total_received,0) > 0 THEN 'Partially Delivered'
        ELSE 'Pending'
    END,
    po.supplier_status = CASE
        WHEN po.po_status = 'Cancelled' THEN po.supplier_status
        WHEN COALESCE(calc.total_ordered,0) > 0 AND COALESCE(calc.total_remaining,0) <= 0 THEN 'Delivered'
        WHEN COALESCE(calc.total_received,0) > 0 THEN 'Incomplete Delivery'
        ELSE po.supplier_status
    END,
    po.updated_at = NOW()
WHERE po.is_draft = 0
  AND po.po_status <> 'Cancelled';
