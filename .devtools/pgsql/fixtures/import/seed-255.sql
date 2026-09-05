-- What a database at the OLDEST supported source schema carries, on top of 00_base.sql.
--
-- 0255 is where upstream grocy 4.x stops, so this file's job is to look like grocy rather
-- than like this fork: rows written by software that never had the API purifier and never
-- had migration 0264, sitting in columns that still mean what they meant then.
--
-- Every row here is something import-tests.php makes an assertion about. A fixture with
-- rows nothing checks is a larger fixture, not a better one.
--
-- One trap when writing a seed: apply-sql.php splits statements on a semicolon at end of
-- line, so a comment whose last character is ";" ends the statement above it.

-- An API key exactly as grocy stores one: the key itself, in plaintext, in the column the
-- application looks a request up by. Migration 0264 is what stops this being readable, and
-- a source at 0255 has never run it - so this row is the reason DatabaseImporter re-applies
-- that migration's work to what it copies. See StoredApiKeyHasher.
INSERT INTO api_keys (id, api_key, user_id, key_type, description)
	VALUES (1, 'ZZZZplaintextkeyfromgrocy0123456789abcdefghijklmn', 1, 'default', 'Plaintext, as grocy stored it');

-- A calendar key, which migration 0264 deliberately leaves readable: the application has
-- to hand that sharing URL back to whoever asks for it, and cannot do that from a hash.
-- Here so that the import test can assert the distinction survives rather than assuming it.
INSERT INTO api_keys (id, api_key, user_id, key_type, description)
	VALUES (2, 'YYYYcalendarkeyfromgrocy0123456789abcdefghijklmno', 1, 'special-purpose-calendar-ical', 'Calendar sharing key');

-- Rich text that never met a purifier, in one of the five columns
-- BaseApiController::HTML_RENDERED_COLUMNS names. Migration 0260 is what cleans these up on
-- an in-place upgrade; an import migrates an empty target, so 0260 finds nothing and the
-- payload would land untouched. Review finding P1 on #41 is the same row in a different
-- database.
INSERT INTO recipes (id, name, description, base_servings, desired_servings)
	VALUES (4, 'Recipe from grocy', '<p>Bread</p><script>alert(1)</script><img src=x onerror=alert(2)>', 1, 1);

-- Stock, so the copy has something with real foreign keys and a view computed over it
-- rather than only reference rows.
INSERT INTO products (id, name, description, product_group_id, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days)
	VALUES (7, 'Import Fixture Flour', 'Carries stock across the import', 1, 1, 2, 2, 0, 0);
INSERT INTO stock (id, product_id, amount, best_before_date, purchased_date, stock_id, price, location_id)
	VALUES (1, 7, 3, '2030-01-01', '2026-01-01', 'fixture-255-1', 1.5, 1);
INSERT INTO stock_log (id, product_id, amount, best_before_date, purchased_date, spoiled, stock_id, transaction_type, price, location_id, user_id)
	VALUES (1, 7, 3, '2030-01-01', '2026-01-01', 0, 'fixture-255-1', 'purchase', 1.5, 1, 1);

-- An empty string that is not a NULL. grocy really does store one - meal_plan_sections holds
-- the internal section with an empty name - and the importer sets PDO::NULL_NATURAL on the
-- source for exactly this reason. A second instance here keeps the assertion about it in the
-- test rather than in a comment.
INSERT INTO shopping_locations (id, name, description) VALUES (3, 'Corner shop', '');
