-- Batch A: parent/child nesting rules and the cumulated min stock amount cascade.
INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock, cumulate_min_stock_amount_of_sub_products, min_stock_amount)
VALUES (711, 'Integration Parent', 1, 2, 2, 1, 4);
INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock, parent_product_id)
VALUES (712, 'Integration Child A', 1, 2, 2, 711);
INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock, parent_product_id)
VALUES (713, 'Integration Child B', 1, 2, 2, 711);
UPDATE products SET min_stock_amount = 9 WHERE id = 711;
-- @expect-error nesting
UPDATE products SET parent_product_id = 712 WHERE id = 711;
