-- What a database at the NEWEST supported source schema carries, on top of 00_base.sql.
--
-- 0265 is the SQLite line's freeze, so this file's job is to look like a Victual
-- installation that has run every migration this fork ever shipped for that engine: keys
-- already hashed by 0264, descriptions already purified by 0260, and rows in the tables the
-- migrations above the baseline added, which the 0255 fixture does not have at all.
--
-- The pair is the point. Between them they put the importer's two hard cases side by side:
-- a source missing tables and columns the target has, and a source that has everything.
--
-- One trap when writing a seed: apply-sql.php splits statements on a semicolon at end of
-- line, so a comment whose last character is ";" ends the statement above it.

-- A key as migration 0264 leaves one: the SHA-256 of the plaintext, with the last four
-- characters kept separately so it can still be identified. The hash below is
-- hash('sha256', 'ZZZZplaintextkeyfromgrocy0123456789abcdefghijklmn') - the same plaintext
-- the 0255 fixture stores readable, so the two fixtures' keys can be compared after import
-- and the older one has to have become the newer one.
INSERT INTO api_keys (id, api_key, user_id, key_type, description, key_hint)
	VALUES (1, 'a464b2b9027dfcc34322f44c76763cf102aa254607e7167f01e44744f8fb0547', 1, 'default', 'Already hashed by 0264', 'klmn');

-- Purified already, because 0260 ran on this side.
INSERT INTO recipes (id, name, description, base_servings, desired_servings)
	VALUES (4, 'Recipe from Victual', '<p>Bread</p>', 1, 1);

INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days)
	VALUES (7, 'Import Fixture Flour', 'Carries stock across the import', 1, 1, 2, 2, 0, 0);
INSERT INTO stock (id, product_id, amount, best_before_date, purchased_date, stock_id, price, location_id)
	VALUES (1, 7, 3, '2030-01-01', '2026-01-01', 'fixture-265-1', 1.5, 1);
INSERT INTO stock_log (id, product_id, amount, best_before_date, purchased_date, spoiled, stock_id, transaction_type, price, location_id, user_id)
	VALUES (1, 7, 3, '2030-01-01', '2026-01-01', 0, 'fixture-265-1', 'purchase', 1.5, 1, 1);

-- An empty string that is not a NULL, for the reason the 0255 seed states.
INSERT INTO shopping_locations (id, name, description) VALUES (3, 'Corner shop', '');

-- Rows in tables that exist only above the baseline, so the copy has something to carry
-- across that the 0255 fixture cannot: the per-product MQTT opt-in (0257), the event outbox
-- (0259) and the failed-login counter (0262). All three are tables a real installation
-- accumulates, and all three are absent from a grocy database, which is what makes the pair
-- of fixtures worth having.
INSERT INTO mqtt_product_entities (id, product_id) VALUES (1, 1);
INSERT INTO outbox (id, event_type, payload, row_created_timestamp)
	VALUES (1, 'influx.booking', '{"fixture":true}', '2026-01-01 00:00:00');
INSERT INTO login_attempts (id, username, row_created_timestamp) VALUES (1, 'admin', '2026-01-01 00:00:00');

-- The flag migration 0265 added, set on the seeded account, which is what a login against
-- the seeded password does.
UPDATE users SET must_change_password = 1 WHERE id = 1;
