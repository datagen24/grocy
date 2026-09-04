-- @views products_view products_resolved products_average_price products_current_price
-- @views products_last_purchased products_price_history products_current_substitutions
-- @views product_barcodes_view product_barcodes_comma_separated product_qu_relations
-- @views quantity_units_resolved quantity_unit_conversions_resolved uihelper_product_details
--
-- Products, pricing and quantity units in the states those views actually distinguish
-- between: a parent product with a sub product, a non-1 QU conversion (both a
-- product-specific override and a default one), more than one purchase of the same
-- product so price history has something to average, and a product with neither stock
-- nor a barcode so the price-derived views keep their null case. Dates are fixed rather
-- than relative to today, for the same reason as 01_stock_basics.sql.
--
-- Products 3-6, locations 1/3 and quantity units 2 "Piece"/3 "Pack" come from
-- fixtures/00_base.sql. New rows use ids 700+ to stay clear of both that fixture and the
-- trigger tests.

-- A purchase-only unit ("Box"), used below for a product-specific conversion with a
-- factor other than 1.
INSERT INTO quantity_units (id, name, description, name_plural) VALUES (700, 'Seed Box', 'Purchase-only unit for the seed', 'Seed Boxes');

-- 700 is the parent, in stock only through its sub product (see the stock section
-- below) -- this is what gives products_current_substitutions something to resolve.
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days, parent_product_id)
VALUES (700, 'Seed Parent Product', 'Not stocked itself; substituted by its sub product', 1, 1, 2, 2, 0, 0, NULL);

-- 701 is the sub product. Purchased in Boxes, stocked in Pieces -- the product-specific
-- QU conversion below gives it a factor of 6, not 1.
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days, parent_product_id)
VALUES (701, 'Seed Sub Product', 'Purchased by the box, stocked by the piece', 1, 1, 700, 2, 0, 0, 700);

-- 702 has no stock, no purchase and no barcode at all -- the null case for every
-- price-derived view below (it simply produces no row, or a null column, depending on
-- the view).
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days, parent_product_id)
VALUES (702, 'Seed No-History Product', 'Never purchased, no barcode', NULL, 3, 3, 2, 0, 0, NULL);

-- Product-specific QU conversion: one Box of product 701 is 6 Pieces. This is what
-- products_view.qu_factor_purchase_to_stock resolves for product 701 below.
--
-- UPDATE, not INSERT: products_default_qu_conversions_INS (migrations/0255.sql) already
-- fired when product 701 was inserted above, because its qu_id_purchase (700) differs
-- from its qu_id_stock (2) -- it created a 1:1 placeholder conversion for exactly this
-- (from_qu_id, to_qu_id, product_id) triple. Inserting a second row for the same triple
-- is rejected by qu_conversions_custom_constraint_INS ("QU conversion already exists");
-- editing the factor on the placeholder, the way the UI would, is what actually happens.
UPDATE quantity_unit_conversions SET factor = 6 WHERE from_qu_id = 700 AND to_qu_id = 2 AND product_id = 701;

-- A default (non-product) conversion, so quantity_units_resolved and the "indirect"
-- branch of product_qu_relations have something to chain through: a Pack is 12 Pieces.
INSERT INTO quantity_unit_conversions (from_qu_id, to_qu_id, factor, product_id) VALUES (2, 3, 12, NULL);

-- Barcodes. Product 701 gets two, so product_barcodes_comma_separated has something to
-- join with a comma; product 3 (base fixture) gets one, in a different store, so the
-- view is exercised on an existing product too. Product 700 and 702 keep the null case.
INSERT INTO product_barcodes (product_id, barcode, qu_id, amount, shopping_location_id, last_price, note)
VALUES (701, '4006381333931', 700, 1, 1, 12.00, 'Box barcode');
INSERT INTO product_barcodes (product_id, barcode, qu_id, amount, shopping_location_id, last_price, note)
VALUES (701, '4006381333948', 2, 1, 2, 2.10, 'Single-piece barcode, other store');
INSERT INTO product_barcodes (product_id, barcode, qu_id, amount, shopping_location_id, last_price, note)
VALUES (3, '9501101530003', 2, 1, 1, 2.50, 'Base fixture barcode');

-- Two purchases of product 701 at different prices, so products_average_price has
-- something to average and products_price_history/products_last_purchased have more
-- than one row to pick the latest of. Both entries are still in stock.
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
VALUES (701, 5, '2027-06-01', '2026-07-01', 'view-pp-701-1', 2.00, 1, 1, 0);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
VALUES (701, 3, '2027-07-01', '2026-07-15', 'view-pp-701-2', 2.40, 1, 2, 0);

INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, user_id)
VALUES (701, 5, '2027-06-01', '2026-07-01', 'view-pp-701-1', 'purchase', 2.00, 1, 1, 1);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, user_id)
VALUES (701, 3, '2027-07-01', '2026-07-15', 'view-pp-701-2', 'purchase', 2.40, 1, 2, 1);

-- Product 703 exists to make this file able to fail on issue #46, which it could not
-- before: the two bugs that issue is about both need a fixture shape the rows above
-- happen not to have.
--
-- 1. THE TIE. products_last_purchased.price is a subquery ordered by purchased_date with
--    LIMIT 1. Product 701's two purchases are on different days (2026-07-01 and
--    2026-07-15), so that ORDER BY happens to be a total order there and the two engines
--    cannot disagree. 703 books three times on ONE day, which is what a household buying
--    twice in an afternoon looks like and what the parity suite produces for every
--    product it creates. Without the tie-break added in migration 0261, the engines pick
--    different rows here.
--
-- 2. INTEGER DIVISION. products_average_price divides SUM by SUM, and amount/price are
--    DECIMAL(15,2) on SQLite, which is NUMERIC affinity - so whole numbers are stored as
--    INTEGER and the division truncates. 701's 2.40 makes the sums real and hides it.
--    703's amounts and prices are all whole numbers on purpose: 4 @ 2, 3 @ 2 and 2 @ 3
--    average to 20/9, which SQLite answered as 2 before 0261 and PostgreSQL has always
--    answered as 2.2222222222222223.
--
-- Only the second of those two is something this file can actually gate, and the
-- distinction is worth stating rather than leaving for somebody to assume. Integer
-- division makes the engines disagree, so removing migration 0261 makes this phase fail
-- with exactly the values issue #46 reported (SQLite 2, PostgreSQL 2.222222) -- measured
-- 2026-09-04 by moving 0261 aside and running `run-tests.sh views`.
--
-- The tie cannot be gated the same way. A differential suite asks whether the two engines
-- agree, and two engines can agree on an undefined answer by luck -- they do here, with
-- 0261 removed, both picking the same row. What makes the tie-break right is that the
-- ORDER BY became total, which is a property of the SQL rather than of a comparison.
-- These rows are still worth having: they are the shape the defect needs, so a future
-- plan change that makes the engines diverge on it is caught rather than invisible.
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days, parent_product_id)
VALUES (703, 'Seed Same-Day Product', 'Three purchases on one day, all whole numbers', NULL, 1, 2, 2, 0, 0, NULL);

INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
VALUES (703, 4, '2027-08-01', '2026-07-20', 'view-pp-703-1', 2.00, 1, 1, 0);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
VALUES (703, 3, '2027-08-02', '2026-07-20', 'view-pp-703-2', 2.00, 1, 1, 0);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
VALUES (703, 2, '2027-08-03', '2026-07-20', 'view-pp-703-3', 3.00, 1, 2, 0);

INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, user_id)
VALUES (703, 4, '2027-08-01', '2026-07-20', 'view-pp-703-1', 'purchase', 2.00, 1, 1, 1);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, user_id)
VALUES (703, 3, '2027-08-02', '2026-07-20', 'view-pp-703-2', 'purchase', 2.00, 1, 1, 1);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, user_id)
VALUES (703, 2, '2027-08-03', '2026-07-20', 'view-pp-703-3', 'purchase', 3.00, 1, 2, 1);
