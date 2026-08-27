-- @views stock_current stock_current_locations stock_current_location_content
-- @views stock_edited_entries stock_next_use stock_splits products_volatile_status
-- @views uihelper_stock_current_overview uihelper_stock_entries
--
-- Stock in the states the overview screens actually distinguish between: in date, due
-- soon, overdue, expired, and spread across two locations and two stores. The dates are
-- fixed rather than relative to today, because a fixture whose meaning changes with the
-- calendar is a fixture that fails on its own one morning.
--
-- Products 3 to 6 and locations 1 and 3 come from fixtures/00_base.sql.

-- Two entries of the same product in different locations, so the per-location views have
-- something to split and stock_current has something to aggregate.
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
VALUES (3, 6, '2027-03-01', '2026-08-01', 'view-stk-1', 2.50, 1, 1, 0);
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
VALUES (3, 4, '2027-03-01', '2026-08-05', 'view-stk-2', 2.75, 3, 2, 0);

-- An opened entry, which several views treat differently from an unopened one.
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open, opened_date)
VALUES (4, 2, '2027-01-15', '2026-08-10', 'view-stk-3', 1.20, 1, 1, 1, '2026-08-12');

-- Already past its best before date, which is what products_volatile_status exists to
-- pick out.
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
VALUES (5, 3, '2026-01-01', '2025-12-01', 'view-stk-4', 0.99, 1, 2, 0);

-- A product with stock but no price, so the price-derived views have a null case.
INSERT INTO stock (product_id, amount, best_before_date, purchased_date, stock_id, location_id, open)
VALUES (6, 8, '2028-01-01', '2026-08-01', 'view-stk-5', 1, 0);

-- The matching journal entries. Without these the stock views still work, but anything
-- reading stock_log sees an inventory that appeared from nowhere.
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, user_id)
VALUES (3, 6, '2027-03-01', '2026-08-01', 'view-stk-1', 'purchase', 2.50, 1, 1, 1);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, user_id)
VALUES (3, 4, '2027-03-01', '2026-08-05', 'view-stk-2', 'purchase', 2.75, 3, 2, 1);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, user_id)
VALUES (4, 2, '2027-01-15', '2026-08-10', 'view-stk-3', 'purchase', 1.20, 1, 1, 1);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, user_id)
VALUES (5, 3, '2026-01-01', '2025-12-01', 'view-stk-4', 'purchase', 0.99, 1, 2, 1);
INSERT INTO stock_log (product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, location_id, user_id)
VALUES (6, 8, '2028-01-01', '2026-08-01', 'view-stk-5', 'purchase', 1, 1);
