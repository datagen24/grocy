-- Fixture for the MQTT payload's both-engine check (engine-diff.sh).
--
-- Deliberately small and deliberately awkward: every row here exists because it exercises a
-- place where the two engines could disagree, or a rule the payload has to follow.
--
--   * a product with stock and a due date, and one with stock and none (the 2888-12-31
--     sentinel path)
--   * a product below its minimum stock amount with no stock at all, which the stock
--     overview lists with amount 0 - it must not be counted as "in stock"
--   * a purchase carrying a price, so that value, last_price and average_price are all
--     populated in the views the assembler reads and the deny-list has something to strip
--   * a shopping list item with a note, for the same reason
--   * an expired product (due type 2, date in the past) and one due soon, for the two count
--     sensors
--   * a chore, a battery and a task with due dates, plus a chore with period type "manually"
--     which has no next execution and must be left out rather than published as null
--   * two opted-in per-product entities, one of them for the product with no stock
--
-- Statements are split on a semicolon at end of line, so keep semicolons out of the ends of
-- comment lines.

INSERT INTO locations (id, name, description, is_freezer) VALUES (1, 'Pantry', 'MQTT fixture location', 0);
INSERT INTO shopping_locations (id, name, description) VALUES (1, 'MQTT Store', 'MQTT fixture store');
INSERT INTO product_groups (id, name, description) VALUES (1, 'MQTT Group', 'MQTT fixture group');

-- due_type 1 = best before date, 2 = expiration date
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, due_type)
	VALUES (1, 'Milk', 'Has stock and a due date', 1, 1, 2, 2, 0, 1);
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, due_type)
	VALUES (2, 'Salt', 'Has stock and no due date', 1, 1, 2, 2, 0, 1);
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, due_type)
	VALUES (3, 'Flour', 'Below min stock, none in stock', 1, 1, 2, 2, 5, 1);
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, due_type)
	VALUES (4, 'Yoghurt', 'Expired, due type 2', 1, 1, 2, 2, 0, 2);
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, due_type)
	VALUES (5, 'Bread', 'Due soon', 1, 1, 2, 2, 0, 1);

INSERT INTO stock (id, product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
	VALUES (1, 1, 2, '2030-01-15', '2026-01-01', 'mqtt-1', 1.29, 1, 1, 0);
INSERT INTO stock (id, product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
	VALUES (2, 2, 1, NULL, '2026-01-01', 'mqtt-2', 0.99, 1, 1, 0);
INSERT INTO stock (id, product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
	VALUES (3, 4, 3, '2020-06-01', '2026-01-01', 'mqtt-4', 2.50, 1, 1, 0);
INSERT INTO stock (id, product_id, amount, best_before_date, purchased_date, stock_id, price, location_id, shopping_location_id, open)
	VALUES (4, 5, 1, '2026-09-04', '2026-01-01', 'mqtt-5', 3.10, 1, 1, 0);

INSERT INTO stock_log (id, product_id, amount, best_before_date, purchased_date, stock_id, transaction_type, price, location_id, shopping_location_id, transaction_id, stock_row_id, user_id)
	VALUES (1, 1, 2, '2030-01-15', '2026-01-01', 'mqtt-1', 'purchase', 1.29, 1, 1, 'mqtt-tx-1', 1, 1);

INSERT INTO shopping_list (id, product_id, amount, note, shopping_list_id, qu_id)
	VALUES (1, 3, 4, 'Wholemeal, not white', 1, 2);
INSERT INTO shopping_list (id, product_id, amount, note, shopping_list_id, qu_id)
	VALUES (2, 1, 1, NULL, 1, 2);

INSERT INTO chores (id, name, description, period_type, period_days, period_interval, start_date, track_date_only)
	VALUES (1, 'Water the plants', 'Scheduled', 'daily', 1, 3, '2026-01-01 09:00:00', 0);
INSERT INTO chores (id, name, description, period_type, period_days, period_interval, start_date, track_date_only)
	VALUES (2, 'Descale the kettle', 'No schedule at all', 'manually', 1, 1, '2026-01-01 09:00:00', 0);
INSERT INTO chores_log (id, chore_id, tracked_time, done_by_user_id)
	VALUES (1, 1, '2026-08-30 09:00:00', 1);

INSERT INTO batteries (id, name, description, used_in, charge_interval_days)
	VALUES (1, 'Smoke detector', 'Hallway', 'Hallway', 180);
INSERT INTO batteries (id, name, description, used_in, charge_interval_days)
	VALUES (2, 'Wall clock', 'Never scheduled', 'Kitchen', 0);
INSERT INTO battery_charge_cycles (id, battery_id, tracked_time)
	VALUES (1, 1, '2026-05-01 12:00:00');

INSERT INTO tasks (id, name, description, due_date, done, assigned_to_user_id)
	VALUES (1, 'Renew the insurance', 'Has a due date', '2026-10-01', 0, 1);
INSERT INTO tasks (id, name, description, due_date, done, assigned_to_user_id)
	VALUES (2, 'Sort the shed', 'No due date', NULL, 0, 1);

-- Per-product opt-in: one product with stock, one without, so the "flagged but not in stock
-- reads zero rather than disappearing" path is exercised
INSERT INTO mqtt_product_entities (id, product_id) VALUES (1, 1);
INSERT INTO mqtt_product_entities (id, product_id) VALUES (2, 3);
