-- Crosses batch B (stock_log cache maintenance) and batch A (stock validation).
-- A purchase, then a consume, then undoing the consume.
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id)
VALUES (3, 10, '2027-01-01', '2026-08-01', 'int-stk-1', 2.50, 1, 1);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, user_id)
VALUES (3, 10, '2027-01-01', '2026-08-01', 'int-stk-1', 'purchase', 2.50, 1, 1, 1);
UPDATE stock SET amount = amount - 4 WHERE stock_id = 'int-stk-1';
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, user_id, used_date)
VALUES (3, -4, '2027-01-01', '2026-08-01', 'int-stk-1', 'consume', 2.50, 1, 1, '2026-08-20');
UPDATE stock_log SET undone = 1, undone_timestamp = '2026-08-21 10:00:00' WHERE stock_id = 'int-stk-1' AND transaction_type = 'consume';
