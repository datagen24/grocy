-- PostgreSQL baseline schema: triggers, batch B (stock / shopping-list cluster)
--
-- One PL/pgSQL function per trigger, named trg_<trigger_name>(), plus a CREATE TRIGGER
-- keeping the original SQLite trigger name. See db/pgsql/README.md "Triggers" section for
-- the general porting rules.
--
-- Recursion note: unlike batch A's products/quantity-unit cluster, nothing in this batch
-- needs a `pg_trigger_depth() > 1` guard. Verified empirically against the pristine SQLite
-- demo database (recursive_triggers is OFF, Grocy's default) that
-- `INSERT INTO stock_next_use` -> INSTEAD OF trigger -> `INSERT INTO stock` DOES still fire
-- `set_products_default_location_if_empty_stock` in SQLite, i.e. recursive_triggers=OFF only
-- suppresses genuine self/mutual recursion, not a plain onward chain to an unrelated
-- trigger. None of the 11 triggers in this batch write to a table that one of THIS batch's
-- own triggers listens on in a way that could loop, so every trigger below fires
-- unconditionally, exactly like upstream SQLite.
--
-- Dependency note: `stock_log_INS` and `stock_log_UPD` read from the view
-- `products_last_purchased`, which is ported by a different (concurrent) batch and is not
-- guaranteed to exist yet in every environment that loads this file. That is intentional -
-- see the porting task notes. CREATE FUNCTION does not resolve relation names at
-- compile-time in PL/pgSQL (verified: creating a function that references a nonexistent
-- relation succeeds), so this file loads cleanly regardless of load order; the two
-- functions simply fail *at execution time* until the view exists.

-- ---------------------------------------------------------------------------------------
-- stock_log_INS / stock_log_UPD / stock_log_DEL
-- Rebuild cache__products_average_price and cache__products_last_purchased from the
-- products_average_price / products_last_purchased views. Cross-table (cache tables only,
-- not the row that fired the trigger) -> stays AFTER.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER stock_log_INS AFTER INSERT ON stock_log
-- BEGIN
-- 	-- Update products_average_price cache
-- 	INSERT OR REPLACE INTO cache__products_average_price
-- 		(product_id, price)
-- 	SELECT product_id, price
-- 	FROM products_average_price
-- 	WHERE product_id = NEW.product_id;
--
-- 	-- Update products_last_purchased cache
-- 	INSERT OR REPLACE INTO cache__products_last_purchased
-- 		(product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id)
-- 	SELECT product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id
-- 	FROM products_last_purchased
-- 	WHERE product_id = NEW.product_id;
-- END;
DROP TRIGGER IF EXISTS stock_log_INS ON stock_log;
CREATE OR REPLACE FUNCTION trg_stock_log_INS() RETURNS TRIGGER AS $$
BEGIN
	-- Update products_average_price cache
	INSERT INTO cache__products_average_price
		(product_id, price)
	SELECT product_id, price
	FROM products_average_price
	WHERE product_id = NEW.product_id
	ON CONFLICT (product_id) DO UPDATE SET
		price = EXCLUDED.price;

	-- Update products_last_purchased cache
	INSERT INTO cache__products_last_purchased
		(product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id)
	SELECT product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id
	FROM products_last_purchased
	WHERE product_id = NEW.product_id
	ON CONFLICT (product_id) DO UPDATE SET
		amount = EXCLUDED.amount,
		best_before_date = EXCLUDED.best_before_date,
		purchased_date = EXCLUDED.purchased_date,
		price = EXCLUDED.price,
		location_id = EXCLUDED.location_id,
		shopping_location_id = EXCLUDED.shopping_location_id;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER stock_log_INS AFTER INSERT ON stock_log
FOR EACH ROW EXECUTE FUNCTION trg_stock_log_INS();

-- CREATE TRIGGER stock_log_UPD AFTER UPDATE ON stock_log
-- BEGIN
-- 	-- Update products_average_price cache
-- 	INSERT OR REPLACE INTO cache__products_average_price
-- 		(product_id, price)
-- 	SELECT product_id, price
-- 	FROM products_average_price
-- 	WHERE product_id = NEW.product_id;
--
-- 	-- Update products_last_purchased cache
-- 	INSERT OR REPLACE INTO cache__products_last_purchased
-- 		(product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id)
-- 	SELECT product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id
-- 	FROM products_last_purchased
-- 	WHERE product_id = NEW.product_id;
-- END;
DROP TRIGGER IF EXISTS stock_log_UPD ON stock_log;
CREATE OR REPLACE FUNCTION trg_stock_log_UPD() RETURNS TRIGGER AS $$
BEGIN
	-- Update products_average_price cache
	INSERT INTO cache__products_average_price
		(product_id, price)
	SELECT product_id, price
	FROM products_average_price
	WHERE product_id = NEW.product_id
	ON CONFLICT (product_id) DO UPDATE SET
		price = EXCLUDED.price;

	-- Update products_last_purchased cache
	INSERT INTO cache__products_last_purchased
		(product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id)
	SELECT product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id
	FROM products_last_purchased
	WHERE product_id = NEW.product_id
	ON CONFLICT (product_id) DO UPDATE SET
		amount = EXCLUDED.amount,
		best_before_date = EXCLUDED.best_before_date,
		purchased_date = EXCLUDED.purchased_date,
		price = EXCLUDED.price,
		location_id = EXCLUDED.location_id,
		shopping_location_id = EXCLUDED.shopping_location_id;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER stock_log_UPD AFTER UPDATE ON stock_log
FOR EACH ROW EXECUTE FUNCTION trg_stock_log_UPD();

-- CREATE TRIGGER stock_log_DEL AFTER DELETE ON stock_log
-- BEGIN
-- 	-- Update products_average_price cache
-- 	DELETE FROM cache__products_average_price
-- 	WHERE product_id = OLD.id;
--
-- 	-- Update products_last_purchased cache
-- 	DELETE FROM cache__products_last_purchased
-- 	WHERE product_id = OLD.id;
-- END;
--
-- NOTE for reviewer: faithful to upstream (migrations/0226.sql) even though it looks like a
-- bug - it filters by OLD.id (the deleted stock_log row's own primary key), not
-- OLD.product_id. A real `DELETE FROM stock_log` will therefore essentially never clear the
-- matching cache row unless a stock_log id happens to coincide with a product id. Not
-- "fixed" here per the porting rule: reproduce upstream behaviour exactly, bug and all.
DROP TRIGGER IF EXISTS stock_log_DEL ON stock_log;
CREATE OR REPLACE FUNCTION trg_stock_log_DEL() RETURNS TRIGGER AS $$
BEGIN
	-- Update products_average_price cache
	DELETE FROM cache__products_average_price
	WHERE product_id = OLD.id;

	-- Update products_last_purchased cache
	DELETE FROM cache__products_last_purchased
	WHERE product_id = OLD.id;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER stock_log_DEL AFTER DELETE ON stock_log
FOR EACH ROW EXECUTE FUNCTION trg_stock_log_DEL();

-- ---------------------------------------------------------------------------------------
-- stock_next_use_INS / stock_next_use_UPD / stock_next_use_DEL
-- `stock_next_use` is a view (see baseline/03_views_group3.sql); these INSTEAD OF triggers
-- are what make it writable, redirecting all DML to the underlying `stock` table.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER stock_next_use_INS INSTEAD OF INSERT ON stock_next_use
-- BEGIN
-- 	INSERT INTO stock
-- 		(product_id, amount, best_before_date, purchased_date, stock_id,
-- 		price, open, opened_date, location_id, shopping_location_id, note)
-- 	VALUES
-- 		(NEW.product_id, NEW.amount, NEW.best_before_date, NEW.purchased_date, NEW.stock_id,
-- 		NEW.price, NEW.open, NEW.opened_date, NEW.location_id, NEW.shopping_location_id, NEW.note);
-- END;
DROP TRIGGER IF EXISTS stock_next_use_INS ON stock_next_use;
CREATE OR REPLACE FUNCTION trg_stock_next_use_INS() RETURNS TRIGGER AS $$
BEGIN
	INSERT INTO stock
		(product_id, amount, best_before_date, purchased_date, stock_id,
		price, open, opened_date, location_id, shopping_location_id, note)
	VALUES
		(NEW.product_id, NEW.amount, NEW.best_before_date, NEW.purchased_date, NEW.stock_id,
		NEW.price, NEW.open, NEW.opened_date, NEW.location_id, NEW.shopping_location_id, NEW.note);

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER stock_next_use_INS INSTEAD OF INSERT ON stock_next_use
FOR EACH ROW EXECUTE FUNCTION trg_stock_next_use_INS();

-- CREATE TRIGGER stock_next_use_UPD INSTEAD OF UPDATE ON stock_next_use
-- BEGIN
-- 	UPDATE stock
-- 	SET product_id = NEW.product_id,
-- 	amount = NEW.amount,
-- 	best_before_date = NEW.best_before_date,
-- 	purchased_date = NEW.purchased_date,
-- 	stock_id = NEW.stock_id,
-- 	price = NEW.price,
-- 	open = NEW.open,
-- 	opened_date = NEW.opened_date,
-- 	location_id = NEW.location_id,
-- 	shopping_location_id = NEW.shopping_location_id,
-- 	note = NEW.note
-- 	WHERE id = NEW.id;
-- END;
DROP TRIGGER IF EXISTS stock_next_use_UPD ON stock_next_use;
CREATE OR REPLACE FUNCTION trg_stock_next_use_UPD() RETURNS TRIGGER AS $$
BEGIN
	UPDATE stock
	SET product_id = NEW.product_id,
		amount = NEW.amount,
		best_before_date = NEW.best_before_date,
		purchased_date = NEW.purchased_date,
		stock_id = NEW.stock_id,
		price = NEW.price,
		open = NEW.open,
		opened_date = NEW.opened_date,
		location_id = NEW.location_id,
		shopping_location_id = NEW.shopping_location_id,
		note = NEW.note
	WHERE id = NEW.id;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER stock_next_use_UPD INSTEAD OF UPDATE ON stock_next_use
FOR EACH ROW EXECUTE FUNCTION trg_stock_next_use_UPD();

-- CREATE TRIGGER stock_next_use_DEL INSTEAD OF DELETE ON stock_next_use
-- BEGIN
-- 	DELETE FROM stock
-- 	WHERE id = OLD.id;
-- END;
DROP TRIGGER IF EXISTS stock_next_use_DEL ON stock_next_use;
CREATE OR REPLACE FUNCTION trg_stock_next_use_DEL() RETURNS TRIGGER AS $$
BEGIN
	DELETE FROM stock
	WHERE id = OLD.id;

	RETURN OLD;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER stock_next_use_DEL INSTEAD OF DELETE ON stock_next_use
FOR EACH ROW EXECUTE FUNCTION trg_stock_next_use_DEL();

-- ---------------------------------------------------------------------------------------
-- set_products_default_location_if_empty_stock
-- Originally AFTER INSERT ON stock, fixing up the very row it just inserted
-- (`UPDATE stock SET location_id = ... WHERE id = NEW.id AND location_id IS NULL`).
-- Per the porting rule, converted to BEFORE INSERT assigning NEW.location_id directly -
-- avoids the recursive UPDATE and is the idiomatic PL/pgSQL form for a self-fixup trigger.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER set_products_default_location_if_empty_stock AFTER INSERT ON stock
-- BEGIN
-- 	UPDATE stock
-- 	SET location_id = (SELECT location_id FROM products where id = product_id)
-- 	WHERE id = NEW.id
-- 		AND location_id IS NULL;
-- END;
DROP TRIGGER IF EXISTS set_products_default_location_if_empty_stock ON stock;
CREATE OR REPLACE FUNCTION trg_set_products_default_location_if_empty_stock() RETURNS TRIGGER AS $$
BEGIN
	IF NEW.location_id IS NULL THEN
		NEW.location_id := (SELECT location_id FROM products WHERE id = NEW.product_id);
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER set_products_default_location_if_empty_stock BEFORE INSERT ON stock
FOR EACH ROW EXECUTE FUNCTION trg_set_products_default_location_if_empty_stock();

-- ---------------------------------------------------------------------------------------
-- set_products_default_location_if_empty_stock_log
-- Same self-fixup pattern as above, on stock_log. Originally created (migrations/0095,
-- redefined 0157) *before* stock_log_INS/UPD/DEL (migration 0226), so in upstream SQLite it
-- always ran first among the AFTER INSERT triggers on stock_log, ensuring
-- cache__products_last_purchased.location_id is populated from the already-corrected
-- location. Converting this one to BEFORE INSERT (per the porting rule) preserves that
-- ordering unconditionally, since PostgreSQL always runs BEFORE row triggers ahead of AFTER
-- row triggers for the same statement - no name-order trick needed.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER set_products_default_location_if_empty_stock_log AFTER INSERT ON stock_log
-- BEGIN
-- 	UPDATE stock_log
-- 	SET location_id = (SELECT location_id FROM products where id = product_id)
-- 	WHERE id = NEW.id
-- 		AND location_id IS NULL;
-- END;
DROP TRIGGER IF EXISTS set_products_default_location_if_empty_stock_log ON stock_log;
CREATE OR REPLACE FUNCTION trg_set_products_default_location_if_empty_stock_log() RETURNS TRIGGER AS $$
BEGIN
	IF NEW.location_id IS NULL THEN
		NEW.location_id := (SELECT location_id FROM products WHERE id = NEW.product_id);
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER set_products_default_location_if_empty_stock_log BEFORE INSERT ON stock_log
FOR EACH ROW EXECUTE FUNCTION trg_set_products_default_location_if_empty_stock_log();

-- ---------------------------------------------------------------------------------------
-- shopping_list_defaults_INS / shopping_list_defaults_UPD
-- Both originally AFTER triggers fixing up the row that just changed
-- (`UPDATE shopping_list SET ... WHERE id = NEW.id`). Converted to BEFORE, assigning NEW
-- directly - the same self-fixup pattern the porting rule calls out for AFTER INSERT,
-- applied here to the analogous AFTER UPDATE case too (both statements in both original
-- triggers only ever touch the row identified by id = NEW.id).
--
-- The second half of each original trigger:
--     UPDATE shopping_list SET amount = 0
--     WHERE TYPEOF(amount) NOT IN ('integer', 'real') AND id = NEW.id;
-- guards against SQLite's dynamic typing letting a non-numeric value into `amount`.
-- shopping_list.amount is DOUBLE PRECISION NOT NULL in this schema, so PostgreSQL's type
-- system already makes that state impossible to reach - there is no PL/pgSQL equivalent of
-- TYPEOF to even express the same check, and none is needed. Intentionally omitted rather
-- than translated into a no-op.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER shopping_list_defaults_INS AFTER INSERT ON shopping_list
-- BEGIN
-- 	UPDATE shopping_list
-- 	SET qu_id = (SELECT qu_id_purchase FROM products WHERE id = product_id)
-- 	WHERE IFNULL(qu_id, '') = ''
-- 		AND id = NEW.id;
--
-- 	UPDATE shopping_list
-- 	SET amount = 0
-- 	WHERE TYPEOF(amount) NOT IN ('integer', 'real')
-- 		AND id = NEW.id;
-- END;
DROP TRIGGER IF EXISTS shopping_list_defaults_INS ON shopping_list;
CREATE OR REPLACE FUNCTION trg_shopping_list_defaults_INS() RETURNS TRIGGER AS $$
BEGIN
	IF NEW.qu_id IS NULL THEN
		NEW.qu_id := (SELECT qu_id_purchase FROM products WHERE id = NEW.product_id);
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER shopping_list_defaults_INS BEFORE INSERT ON shopping_list
FOR EACH ROW EXECUTE FUNCTION trg_shopping_list_defaults_INS();

-- CREATE TRIGGER shopping_list_defaults_UPD AFTER UPDATE ON shopping_list
-- BEGIN
-- 	UPDATE shopping_list
-- 	SET qu_id = (SELECT qu_id_purchase FROM products WHERE id = product_id)
-- 	WHERE IFNULL(qu_id, '') = ''
-- 		AND id = NEW.id;
--
-- 	UPDATE shopping_list
-- 	SET amount = 0
-- 	WHERE TYPEOF(amount) NOT IN ('integer', 'real')
-- 		AND id = NEW.id;
-- END;
DROP TRIGGER IF EXISTS shopping_list_defaults_UPD ON shopping_list;
CREATE OR REPLACE FUNCTION trg_shopping_list_defaults_UPD() RETURNS TRIGGER AS $$
BEGIN
	IF NEW.qu_id IS NULL THEN
		NEW.qu_id := (SELECT qu_id_purchase FROM products WHERE id = NEW.product_id);
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER shopping_list_defaults_UPD BEFORE UPDATE ON shopping_list
FOR EACH ROW EXECUTE FUNCTION trg_shopping_list_defaults_UPD();

-- ---------------------------------------------------------------------------------------
-- remove_items_from_deleted_shopping_list
-- Genuine cross-table AFTER trigger (deletes rows in a different table, not the one that
-- fired it) -> stays AFTER.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER remove_items_from_deleted_shopping_list AFTER DELETE ON shopping_lists
-- BEGIN
--     DELETE FROM shopping_list WHERE shopping_list_id = OLD.id;
-- END;
DROP TRIGGER IF EXISTS remove_items_from_deleted_shopping_list ON shopping_lists;
CREATE OR REPLACE FUNCTION trg_remove_items_from_deleted_shopping_list() RETURNS TRIGGER AS $$
BEGIN
	DELETE FROM shopping_list WHERE shopping_list_id = OLD.id;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER remove_items_from_deleted_shopping_list AFTER DELETE ON shopping_lists
FOR EACH ROW EXECUTE FUNCTION trg_remove_items_from_deleted_shopping_list();
