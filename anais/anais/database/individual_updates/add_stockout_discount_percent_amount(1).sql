-- ANAIS Stock-Out Discount Percent/Amount + Total
-- Run this in phpMyAdmin once.

ALTER TABLE stock_transactions
ADD COLUMN discount_type ENUM('amount','percent') NOT NULL DEFAULT 'amount' AFTER transaction_date,
ADD COLUMN discount_value DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_type,
ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_value,
ADD COLUMN total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER discount_amount;

-- Optional: compute totals for older Stock-Out records using current product unit_price.
UPDATE stock_transactions st
JOIN products p ON p.product_id = st.product_id
SET st.total_amount = (st.quantity * p.unit_price)
WHERE st.transaction_type = 'Stock-Out'
  AND st.total_amount = 0.00;
