-- Crosses batch A (product/QU triggers) and batch B (stock defaults), then deletes the
-- product, which cascades into barcodes, conversions, shopping list and userfield values.
INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock, min_stock_amount)
VALUES (701, 'Integration Product', 1, 3, 2, 2.5);
INSERT INTO product_barcodes (product_id, barcode) VALUES (701, 'INT-BARCODE-1');
INSERT INTO shopping_list (product_id, amount, shopping_list_id) VALUES (701, 3, 1);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price)
VALUES (701, 5, '2027-06-01', '2026-08-01', 'int-stk-701', 1.10);
DELETE FROM products WHERE id = 701;
