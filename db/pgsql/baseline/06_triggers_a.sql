-- PostgreSQL baseline schema: triggers, batch A (products / quantity-unit cluster)
--
-- One PL/pgSQL function per trigger, named trg_<trigger_name>(), plus a CREATE TRIGGER
-- keeping the original SQLite trigger name. See db/pgsql/README.md "Triggers" section for
-- the general porting rules.
--
-- Recursion note: SQLite runs with `recursive_triggers` OFF (the default, and Grocy never
-- turns it on). Verified empirically (via .devtools/pgsql/trigdifftest.php) against real
-- SQLite: this does NOT stop a DML statement issued from inside a trigger body from firing
-- a *different* trigger on its target table - e.g. products_default_qu_conversions_INS
-- inserting into quantity_unit_conversions from within its own body genuinely does fire
-- quantity_unit_conversions_INS in SQLite, creating the inverse row and updating the cache
-- exactly as a top-level insert would. What recursive_triggers=OFF actually prevents is a
-- trigger firing *itself* again while it is already running further up the call stack: when
-- quantity_unit_conversions_INS's own inverse-row insert would otherwise re-fire
-- quantity_unit_conversions_INS a second time (to create the "inverse of the inverse", i.e.
-- the original row again), that second firing is suppressed. Without reproducing that
-- specific self-suppression, PostgreSQL would either recurse into computing an inverse of an
-- inverse indefinitely-looking chain, or (since there is no real UNIQUE constraint backing
-- "OR REPLACE" here) trip qu_conversions_custom_constraint_INS's duplicate check on the
-- reflexive insert and abort the whole originating statement - neither of which SQLite does.
--
-- trg_quantity_unit_conversions_INS/_UPD/_DEL therefore guard against *only* this
-- self-reentrancy, using a transaction-local custom GUC as a "currently running" flag (reset
-- automatically when the implicit per-statement transaction ends). No other trigger in this
-- batch modifies the same table its own trigger function is defined on, so no other trigger
-- needs this guard - ordinary cross-table/cross-trigger cascades are left to fire freely,
-- matching what SQLite was shown to actually do.

-- ---------------------------------------------------------------------------------------
-- products_INS / products_UPD / products_DELETE
-- Rebuild the cache__quantity_unit_conversions_resolved rows for a product whenever it is
-- inserted, updated or removed. Cross-table (writes to the cache table only) -> stays AFTER.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER products_INS AFTER INSERT ON products
-- BEGIN
-- 	-- Update quantity_unit_conversions_resolved cache
-- 	DELETE FROM cache__quantity_unit_conversions_resolved
-- 	WHERE product_id = NEW.id;
--
-- 	INSERT INTO cache__quantity_unit_conversions_resolved
-- 		(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
-- 	SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path
-- 	FROM quantity_unit_conversions_resolved
-- 	WHERE product_id = NEW.id;
-- END;
DROP TRIGGER IF EXISTS products_INS ON products;
CREATE OR REPLACE FUNCTION trg_products_INS() RETURNS TRIGGER AS $$
BEGIN
	-- Update quantity_unit_conversions_resolved cache
	DELETE FROM cache__quantity_unit_conversions_resolved
	WHERE product_id = NEW.id;

	INSERT INTO cache__quantity_unit_conversions_resolved
		(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
	SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor::text, path
	FROM quantity_unit_conversions_resolved
	WHERE product_id = NEW.id;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER products_INS AFTER INSERT ON products
FOR EACH ROW EXECUTE FUNCTION trg_products_INS();

-- CREATE TRIGGER products_UPD AFTER UPDATE ON products
-- BEGIN
-- 	-- Update quantity_unit_conversions_resolved cache
-- 	DELETE FROM cache__quantity_unit_conversions_resolved
-- 	WHERE product_id = NEW.id;
--
-- 	INSERT INTO cache__quantity_unit_conversions_resolved
-- 		(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
-- 	SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path
-- 	FROM quantity_unit_conversions_resolved
-- 	WHERE product_id = NEW.id;
-- END;
DROP TRIGGER IF EXISTS products_UPD ON products;
CREATE OR REPLACE FUNCTION trg_products_UPD() RETURNS TRIGGER AS $$
BEGIN
	-- Update quantity_unit_conversions_resolved cache
	DELETE FROM cache__quantity_unit_conversions_resolved
	WHERE product_id = NEW.id;

	INSERT INTO cache__quantity_unit_conversions_resolved
		(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
	SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor::text, path
	FROM quantity_unit_conversions_resolved
	WHERE product_id = NEW.id;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER products_UPD AFTER UPDATE ON products
FOR EACH ROW EXECUTE FUNCTION trg_products_UPD();

-- CREATE TRIGGER products_DELETE AFTER DELETE ON products
-- BEGIN
-- 	-- Update quantity_unit_conversions_resolved cache
-- 	DELETE FROM cache__quantity_unit_conversions_resolved
-- 	WHERE product_id = OLD.id;
-- END;
DROP TRIGGER IF EXISTS products_DELETE ON products;
CREATE OR REPLACE FUNCTION trg_products_DELETE() RETURNS TRIGGER AS $$
BEGIN
	-- Update quantity_unit_conversions_resolved cache
	DELETE FROM cache__quantity_unit_conversions_resolved
	WHERE product_id = OLD.id;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER products_DELETE AFTER DELETE ON products
FOR EACH ROW EXECUTE FUNCTION trg_products_DELETE();

-- ---------------------------------------------------------------------------------------
-- products_default_qu_conversions_INS / _UPD
-- Auto-create product-specific 1:1 conversions when qu_id_stock differs from
-- purchase/consume/price and no conversion already resolves. Cross-table (writes to
-- quantity_unit_conversions) -> stays AFTER.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER products_default_qu_conversions_INS AFTER INSERT ON products
-- BEGIN
-- 	-- Create product specific 1:1 conversions when QU stock != QU purchase/consume/price
-- 	-- and when no default QU conversion apply
--
-- 	-- with qu_id_stock != qu_id_purchase
-- 	INSERT INTO quantity_unit_conversions
-- 		(from_qu_id, to_qu_id, factor, product_id)
-- 	SELECT p.qu_id_purchase, p.qu_id_stock, 1, p.id
-- 	FROM products p
-- 	WHERE p.id = NEW.id
-- 		AND p.qu_id_stock != qu_id_purchase
-- 		AND NOT EXISTS(SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = p.id AND from_qu_id = p.qu_id_stock AND to_qu_id = p.qu_id_purchase);
--
-- 	-- with qu_id_stock != qu_id_consume
-- 	INSERT INTO quantity_unit_conversions
-- 		(from_qu_id, to_qu_id, factor, product_id)
-- 	SELECT p.qu_id_consume, p.qu_id_stock, 1, p.id
-- 	FROM products p
-- 	WHERE p.id = NEW.id
-- 		AND p.qu_id_stock != qu_id_consume
-- 		AND NOT EXISTS(SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = p.id AND from_qu_id = p.qu_id_stock AND to_qu_id = p.qu_id_consume);
--
-- 	-- with qu_id_stock != qu_id_price
-- 	INSERT INTO quantity_unit_conversions
-- 		(from_qu_id, to_qu_id, factor, product_id)
-- 	SELECT p.qu_id_price, p.qu_id_stock, 1, p.id
-- 	FROM products p
-- 	WHERE p.id = NEW.id
-- 		AND p.qu_id_stock != qu_id_price
-- 		AND NOT EXISTS(SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = p.id AND from_qu_id = p.qu_id_stock AND to_qu_id = p.qu_id_price);
-- END;
DROP TRIGGER IF EXISTS products_default_qu_conversions_INS ON products;
CREATE OR REPLACE FUNCTION trg_products_default_qu_conversions_INS() RETURNS TRIGGER AS $$
BEGIN
	-- Create product specific 1:1 conversions when QU stock != QU purchase/consume/price
	-- and when no default QU conversion apply

	-- with qu_id_stock != qu_id_purchase
	INSERT INTO quantity_unit_conversions
		(from_qu_id, to_qu_id, factor, product_id)
	SELECT p.qu_id_purchase, p.qu_id_stock, 1, p.id
	FROM products p
	WHERE p.id = NEW.id
		AND p.qu_id_stock != p.qu_id_purchase
		AND NOT EXISTS(SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = p.id AND from_qu_id = p.qu_id_stock AND to_qu_id = p.qu_id_purchase);

	-- with qu_id_stock != qu_id_consume
	INSERT INTO quantity_unit_conversions
		(from_qu_id, to_qu_id, factor, product_id)
	SELECT p.qu_id_consume, p.qu_id_stock, 1, p.id
	FROM products p
	WHERE p.id = NEW.id
		AND p.qu_id_stock != p.qu_id_consume
		AND NOT EXISTS(SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = p.id AND from_qu_id = p.qu_id_stock AND to_qu_id = p.qu_id_consume);

	-- with qu_id_stock != qu_id_price
	INSERT INTO quantity_unit_conversions
		(from_qu_id, to_qu_id, factor, product_id)
	SELECT p.qu_id_price, p.qu_id_stock, 1, p.id
	FROM products p
	WHERE p.id = NEW.id
		AND p.qu_id_stock != p.qu_id_price
		AND NOT EXISTS(SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = p.id AND from_qu_id = p.qu_id_stock AND to_qu_id = p.qu_id_price);

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER products_default_qu_conversions_INS AFTER INSERT ON products
FOR EACH ROW EXECUTE FUNCTION trg_products_default_qu_conversions_INS();

-- CREATE TRIGGER products_default_qu_conversions_UPD AFTER UPDATE ON products (same body, NEW.id)
DROP TRIGGER IF EXISTS products_default_qu_conversions_UPD ON products;
CREATE OR REPLACE FUNCTION trg_products_default_qu_conversions_UPD() RETURNS TRIGGER AS $$
BEGIN
	-- Create product specific 1:1 conversions when QU stock != QU purchase/consume/price
	-- and when no default QU conversion apply

	-- with qu_id_stock != qu_id_purchase
	INSERT INTO quantity_unit_conversions
		(from_qu_id, to_qu_id, factor, product_id)
	SELECT p.qu_id_purchase, p.qu_id_stock, 1, p.id
	FROM products p
	WHERE p.id = NEW.id
		AND p.qu_id_stock != p.qu_id_purchase
		AND NOT EXISTS(SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = p.id AND from_qu_id = p.qu_id_stock AND to_qu_id = p.qu_id_purchase);

	-- with qu_id_stock != qu_id_consume
	INSERT INTO quantity_unit_conversions
		(from_qu_id, to_qu_id, factor, product_id)
	SELECT p.qu_id_consume, p.qu_id_stock, 1, p.id
	FROM products p
	WHERE p.id = NEW.id
		AND p.qu_id_stock != p.qu_id_consume
		AND NOT EXISTS(SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = p.id AND from_qu_id = p.qu_id_stock AND to_qu_id = p.qu_id_consume);

	-- with qu_id_stock != qu_id_price
	INSERT INTO quantity_unit_conversions
		(from_qu_id, to_qu_id, factor, product_id)
	SELECT p.qu_id_price, p.qu_id_stock, 1, p.id
	FROM products p
	WHERE p.id = NEW.id
		AND p.qu_id_stock != p.qu_id_price
		AND NOT EXISTS(SELECT 1 FROM quantity_unit_conversions_resolved WHERE product_id = p.id AND from_qu_id = p.qu_id_stock AND to_qu_id = p.qu_id_price);

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER products_default_qu_conversions_UPD AFTER UPDATE ON products
FOR EACH ROW EXECUTE FUNCTION trg_products_default_qu_conversions_UPD();

-- ---------------------------------------------------------------------------------------
-- quantity_unit_conversions_INS / _UPD / _DEL
-- Keep the inverse conversion row and the cache in sync. Genuinely cross-row (the inverse
-- is a *different* row of the same table) -> stays AFTER.
--
-- "INSERT OR REPLACE" in the original only matters if a UNIQUE/PK constraint would
-- otherwise be violated. There is no such constraint here (uniqueness of
-- from_qu_id/to_qu_id/product_id is enforced only by the qu_conversions_custom_constraint_*
-- triggers, specifically because SQLite unique indexes don't cover NULL product_id), so
-- "OR REPLACE" never actually replaces anything - it behaves exactly like a plain INSERT.
-- Ported as a plain INSERT.
--
-- Self-reentrancy guard: see the top-of-file note. Without it, inserting the inverse row
-- here would fire this same trigger again for that inverse row, which would try to insert
-- the "inverse of the inverse" (i.e. the original row, which already exists) and either loop
-- or trip the duplicate-conversion constraint check and abort the whole original statement -
-- neither of which SQLite does, because it suppresses exactly this self-recursion.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER quantity_unit_conversions_INS AFTER INSERT ON quantity_unit_conversions
-- BEGIN
-- 	-- Create the inverse QU conversion
-- 	INSERT OR REPLACE INTO quantity_unit_conversions
-- 		(from_qu_id, to_qu_id, factor, product_id)
-- 	VALUES
-- 		(NEW.to_qu_id, NEW.from_qu_id, 1 / IFNULL(NEW.factor, 1), NEW.product_id);
--
-- 	-- Update quantity_unit_conversions_resolved cache
-- 	DELETE FROM cache__quantity_unit_conversions_resolved
-- 	WHERE path LIKE '%/' || NEW.to_qu_id || '/%'
-- 		OR path LIKE '%/' || NEW.from_qu_id || '/%';
--
-- 	INSERT INTO cache__quantity_unit_conversions_resolved
-- 		(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
-- 	SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path
-- 	FROM quantity_unit_conversions_resolved
-- 	WHERE path LIKE '%/' || NEW.to_qu_id || '/%'
-- 		OR path LIKE '%/' || NEW.from_qu_id || '/%';
-- END;
DROP TRIGGER IF EXISTS quantity_unit_conversions_INS ON quantity_unit_conversions;
CREATE OR REPLACE FUNCTION trg_quantity_unit_conversions_INS() RETURNS TRIGGER AS $$
BEGIN
	IF current_setting('grocy.in_quc_ins', true) = '1' THEN
		RETURN NULL;
	END IF;
	PERFORM set_config('grocy.in_quc_ins', '1', true);

	-- Create the inverse QU conversion (see note above: plain INSERT is equivalent to the
	-- original "INSERT OR REPLACE", there being no real unique constraint to trigger on)
	INSERT INTO quantity_unit_conversions
		(from_qu_id, to_qu_id, factor, product_id)
	VALUES
		(NEW.to_qu_id, NEW.from_qu_id, 1 / COALESCE(NEW.factor, 1), NEW.product_id);

	-- Update quantity_unit_conversions_resolved cache
	DELETE FROM cache__quantity_unit_conversions_resolved
	WHERE path LIKE '%/' || NEW.to_qu_id::text || '/%'
		OR path LIKE '%/' || NEW.from_qu_id::text || '/%';

	INSERT INTO cache__quantity_unit_conversions_resolved
		(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
	SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor::text, path
	FROM quantity_unit_conversions_resolved
	WHERE path LIKE '%/' || NEW.to_qu_id::text || '/%'
		OR path LIKE '%/' || NEW.from_qu_id::text || '/%';

	PERFORM set_config('grocy.in_quc_ins', '0', true);
	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER quantity_unit_conversions_INS AFTER INSERT ON quantity_unit_conversions
FOR EACH ROW EXECUTE FUNCTION trg_quantity_unit_conversions_INS();

-- CREATE TRIGGER quantity_unit_conversions_UPD AFTER UPDATE ON quantity_unit_conversions
-- BEGIN
-- 	-- Update the inverse QU conversion
-- 	UPDATE quantity_unit_conversions
-- 	SET factor = 1 / IFNULL(NEW.factor, 1),
-- 	from_qu_id = NEW.to_qu_id,
-- 	to_qu_id = NEW.from_qu_id
-- 	WHERE from_qu_id = OLD.to_qu_id
-- 		AND to_qu_id = OLD.from_qu_id
-- 		AND IFNULL(product_id, -1) = IFNULL(NEW.product_id, -1);
--
-- 	-- Update quantity_unit_conversions_resolved cache
-- 	DELETE FROM cache__quantity_unit_conversions_resolved
-- 	WHERE path LIKE '%/' || NEW.to_qu_id || '/%'
-- 		OR path LIKE '%/' || NEW.from_qu_id || '/%';
--
-- 	INSERT INTO cache__quantity_unit_conversions_resolved
-- 		(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
-- 	SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path
-- 	FROM quantity_unit_conversions_resolved
-- 	WHERE path LIKE '%/' || NEW.to_qu_id || '/%'
-- 		OR path LIKE '%/' || NEW.from_qu_id || '/%';
-- END;
DROP TRIGGER IF EXISTS quantity_unit_conversions_UPD ON quantity_unit_conversions;
CREATE OR REPLACE FUNCTION trg_quantity_unit_conversions_UPD() RETURNS TRIGGER AS $$
BEGIN
	IF current_setting('grocy.in_quc_upd', true) = '1' THEN
		RETURN NULL;
	END IF;
	PERFORM set_config('grocy.in_quc_upd', '1', true);

	-- Update the inverse QU conversion
	UPDATE quantity_unit_conversions
	SET factor = 1 / COALESCE(NEW.factor, 1),
		from_qu_id = NEW.to_qu_id,
		to_qu_id = NEW.from_qu_id
	WHERE from_qu_id = OLD.to_qu_id
		AND to_qu_id = OLD.from_qu_id
		AND COALESCE(product_id, -1) = COALESCE(NEW.product_id, -1);

	-- Update quantity_unit_conversions_resolved cache
	DELETE FROM cache__quantity_unit_conversions_resolved
	WHERE path LIKE '%/' || NEW.to_qu_id::text || '/%'
		OR path LIKE '%/' || NEW.from_qu_id::text || '/%';

	INSERT INTO cache__quantity_unit_conversions_resolved
		(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
	SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor::text, path
	FROM quantity_unit_conversions_resolved
	WHERE path LIKE '%/' || NEW.to_qu_id::text || '/%'
		OR path LIKE '%/' || NEW.from_qu_id::text || '/%';

	PERFORM set_config('grocy.in_quc_upd', '0', true);
	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER quantity_unit_conversions_UPD AFTER UPDATE ON quantity_unit_conversions
FOR EACH ROW EXECUTE FUNCTION trg_quantity_unit_conversions_UPD();

-- CREATE TRIGGER quantity_unit_conversions_DEL AFTER DELETE ON quantity_unit_conversions
-- BEGIN
-- 	-- Delete the inverse QU conversion
-- 	DELETE FROM quantity_unit_conversions
-- 	WHERE from_qu_id = OLD.to_qu_id
-- 		AND to_qu_id = OLD.from_qu_id
-- 		AND IFNULL(product_id, -1) = IFNULL(OLD.product_id, -1);
--
-- 	-- Update quantity_unit_conversions_resolved cache
-- 	DELETE FROM cache__quantity_unit_conversions_resolved
-- 	WHERE path LIKE '%/' || OLD.to_qu_id || '/%'
-- 		OR path LIKE '%/' || OLD.from_qu_id || '/%';
--
-- 	INSERT INTO cache__quantity_unit_conversions_resolved
-- 		(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
-- 	SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path
-- 	FROM quantity_unit_conversions_resolved
-- 	WHERE path LIKE '%/' || OLD.to_qu_id || '/%'
-- 		OR path LIKE '%/' || OLD.from_qu_id || '/%';
-- END;
DROP TRIGGER IF EXISTS quantity_unit_conversions_DEL ON quantity_unit_conversions;
CREATE OR REPLACE FUNCTION trg_quantity_unit_conversions_DEL() RETURNS TRIGGER AS $$
BEGIN
	IF current_setting('grocy.in_quc_del', true) = '1' THEN
		RETURN NULL;
	END IF;
	PERFORM set_config('grocy.in_quc_del', '1', true);

	-- Delete the inverse QU conversion
	DELETE FROM quantity_unit_conversions
	WHERE from_qu_id = OLD.to_qu_id
		AND to_qu_id = OLD.from_qu_id
		AND COALESCE(product_id, -1) = COALESCE(OLD.product_id, -1);

	-- Update quantity_unit_conversions_resolved cache
	DELETE FROM cache__quantity_unit_conversions_resolved
	WHERE path LIKE '%/' || OLD.to_qu_id::text || '/%'
		OR path LIKE '%/' || OLD.from_qu_id::text || '/%';

	INSERT INTO cache__quantity_unit_conversions_resolved
		(product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor, path)
	SELECT product_id, from_qu_id, from_qu_name, from_qu_name_plural, to_qu_id, to_qu_name, to_qu_name_plural, factor::text, path
	FROM quantity_unit_conversions_resolved
	WHERE path LIKE '%/' || OLD.to_qu_id::text || '/%'
		OR path LIKE '%/' || OLD.from_qu_id::text || '/%';

	PERFORM set_config('grocy.in_quc_del', '0', true);
	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER quantity_unit_conversions_DEL AFTER DELETE ON quantity_unit_conversions
FOR EACH ROW EXECUTE FUNCTION trg_quantity_unit_conversions_DEL();

-- ---------------------------------------------------------------------------------------
-- cascade_change_qu_id_stock / cascade_change_qu_id_stock2
-- When a product's stock QU changes, rescale every amount that is expressed in that QU
-- everywhere, and enforce that a conversion path from the old to the new QU exists once the
-- product has stock history. cascade_change_qu_id_stock2 originally fixed up columns on the
-- product's *own* row via a nested AFTER UPDATE self-UPDATE; per the README rule that
-- becomes a BEFORE trigger assigning NEW.* directly. Naming keeps
-- "cascade_change_qu_id_stock" sorting before "cascade_change_qu_id_stock2" (it already
-- does, since it is a strict prefix), so PostgreSQL runs the constraint check and the
-- other-table cascades first, then the own-row rescale - matching the original BEFORE/AFTER
-- split's intent even though both are now BEFORE triggers.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER cascade_change_qu_id_stock BEFORE UPDATE ON products WHEN NEW.qu_id_stock != OLD.qu_id_stock
-- BEGIN
-- 	-- All amounts anywhere are related to the products stock QU,
-- 	-- so apply the appropriate unit conversion to all amounts everywhere on change
-- 	-- (and enforce that such a conversion need to exist when the product was once added to stock)
--
-- 	SELECT CASE WHEN((
-- 		SELECT 1
-- 		FROM quantity_unit_conversions_resolved
-- 		WHERE product_id = NEW.id
-- 			AND from_qu_id = OLD.qu_id_stock
-- 			AND to_qu_id = NEW.qu_id_stock
-- 	) ISNULL)
-- 	AND
-- 	((
--         SELECT 1
--         FROM stock_log
-- 		WHERE product_id = NEW.id
-- 			AND NEW.qu_id_stock != OLD.qu_id_stock
--     ) NOTNULL) THEN RAISE(ABORT, 'qu_id_stock can only be changed when a corresponding QU conversion (old QU => new QU) exists when the product was once added to stock') END;
--
-- 	UPDATE chores SET product_amount = product_amount * IFNULL((SELECT factor FROM quantity_unit_conversions_resolved WHERE product_id = NEW.id AND from_qu_id = OLD.qu_id_stock AND to_qu_id = NEW.qu_id_stock LIMIT 1), 1.0) WHERE product_id = NEW.id;
-- 	UPDATE meal_plan SET product_amount = product_amount * IFNULL((SELECT factor FROM quantity_unit_conversions_resolved WHERE product_id = NEW.id AND from_qu_id = OLD.qu_id_stock AND to_qu_id = NEW.qu_id_stock LIMIT 1), 1.0) WHERE type = 'product' AND product_id = NEW.id;
-- 	UPDATE recipes_pos SET amount = amount * IFNULL((SELECT factor FROM quantity_unit_conversions_resolved WHERE product_id = NEW.id AND from_qu_id = OLD.qu_id_stock AND to_qu_id = NEW.qu_id_stock LIMIT 1), 1.0) WHERE product_id = NEW.id;
-- 	UPDATE shopping_list SET amount = amount * IFNULL((SELECT factor FROM quantity_unit_conversions_resolved WHERE product_id = NEW.id AND from_qu_id = OLD.qu_id_stock AND to_qu_id = NEW.qu_id_stock LIMIT 1), 1.0) WHERE product_id = NEW.id AND product_id IS NOT NULL;
-- 	UPDATE stock SET amount = amount * IFNULL(...), price = price / IFNULL(...) WHERE product_id = NEW.id;
-- 	UPDATE stock_log SET amount = amount * IFNULL(...), price = price / IFNULL(...) WHERE product_id = NEW.id;
-- END;
DROP TRIGGER IF EXISTS cascade_change_qu_id_stock ON products;
CREATE OR REPLACE FUNCTION trg_cascade_change_qu_id_stock() RETURNS TRIGGER AS $$
DECLARE
	v_factor DOUBLE PRECISION;
BEGIN
	-- All amounts anywhere are related to the products stock QU,
	-- so apply the appropriate unit conversion to all amounts everywhere on change
	-- (and enforce that such a conversion need to exist when the product was once added to stock)

	-- (the "AND NEW.qu_id_stock != OLD.qu_id_stock" from the original stock_log subquery is
	-- dropped here - it is guaranteed true already by this trigger's WHEN clause)
	IF NOT EXISTS(
			SELECT 1
			FROM quantity_unit_conversions_resolved
			WHERE product_id = NEW.id
				AND from_qu_id = OLD.qu_id_stock
				AND to_qu_id = NEW.qu_id_stock
		)
		AND EXISTS(
			SELECT 1
			FROM stock_log
			WHERE product_id = NEW.id
		)
	THEN
		RAISE EXCEPTION 'qu_id_stock can only be changed when a corresponding QU conversion (old QU => new QU) exists when the product was once added to stock';
	END IF;

	v_factor := COALESCE((SELECT factor FROM quantity_unit_conversions_resolved WHERE product_id = NEW.id AND from_qu_id = OLD.qu_id_stock AND to_qu_id = NEW.qu_id_stock LIMIT 1), 1.0);

	UPDATE chores
	SET product_amount = product_amount * v_factor
	WHERE product_id = NEW.id;

	UPDATE meal_plan
	SET product_amount = product_amount * v_factor
	WHERE type = 'product'
		AND product_id = NEW.id;

	UPDATE recipes_pos
	SET amount = amount * v_factor
	WHERE product_id = NEW.id;

	UPDATE shopping_list
	SET amount = amount * v_factor
	WHERE product_id = NEW.id
		AND product_id IS NOT NULL;

	UPDATE stock
	SET amount = amount * v_factor,
		price = price / v_factor
	WHERE product_id = NEW.id;

	UPDATE stock_log
	SET amount = amount * v_factor,
		price = price / v_factor
	WHERE product_id = NEW.id;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER cascade_change_qu_id_stock BEFORE UPDATE ON products
FOR EACH ROW WHEN (NEW.qu_id_stock != OLD.qu_id_stock)
EXECUTE FUNCTION trg_cascade_change_qu_id_stock();

-- CREATE TRIGGER cascade_change_qu_id_stock2 AFTER UPDATE ON products WHEN NEW.qu_id_stock != OLD.qu_id_stock
-- BEGIN
-- 	-- See also the trigger "cascade_change_qu_id_stock BEFORE UPDATE ON products"
-- 	-- This here applies the needed changes to the products table itself only AFTER the update
--
-- 	UPDATE products
-- 	SET quick_consume_amount = quick_consume_amount * IFNULL((SELECT factor FROM quantity_unit_conversions_resolved WHERE product_id = NEW.id AND from_qu_id = OLD.qu_id_stock AND to_qu_id = NEW.qu_id_stock LIMIT 1), 1.0),
-- 	quick_open_amount = quick_open_amount * IFNULL((SELECT factor FROM quantity_unit_conversions_resolved WHERE product_id = NEW.id AND from_qu_id = OLD.qu_id_stock AND to_qu_id = NEW.qu_id_stock LIMIT 1), 1.0),
-- 	calories = calories / IFNULL((SELECT factor FROM quantity_unit_conversions_resolved WHERE product_id = NEW.id AND from_qu_id = OLD.qu_id_stock AND to_qu_id = NEW.qu_id_stock LIMIT 1), 1.0),
-- 	tare_weight = tare_weight * IFNULL((SELECT factor FROM quantity_unit_conversions_resolved WHERE product_id = NEW.id AND from_qu_id = OLD.qu_id_stock AND to_qu_id = NEW.qu_id_stock LIMIT 1), 1.0)
-- 	WHERE id = NEW.id;
-- END;
-- Ported as BEFORE UPDATE, assigning NEW.* directly instead of a nested self-UPDATE (own
-- row fixup - see README rule). The factor lookup only depends on OLD/NEW.qu_id_stock as
-- plain values, not on whether the products row has been physically written yet, so running
-- it BEFORE instead of AFTER the write changes nothing about the result.
DROP TRIGGER IF EXISTS cascade_change_qu_id_stock2 ON products;
CREATE OR REPLACE FUNCTION trg_cascade_change_qu_id_stock2() RETURNS TRIGGER AS $$
DECLARE
	v_factor DOUBLE PRECISION;
BEGIN
	v_factor := COALESCE((SELECT factor FROM quantity_unit_conversions_resolved WHERE product_id = NEW.id AND from_qu_id = OLD.qu_id_stock AND to_qu_id = NEW.qu_id_stock LIMIT 1), 1.0);

	NEW.quick_consume_amount := NEW.quick_consume_amount * v_factor;
	NEW.quick_open_amount := NEW.quick_open_amount * v_factor;
	NEW.calories := NEW.calories / v_factor;
	NEW.tare_weight := NEW.tare_weight * v_factor;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER cascade_change_qu_id_stock2 BEFORE UPDATE ON products
FOR EACH ROW WHEN (NEW.qu_id_stock != OLD.qu_id_stock)
EXECUTE FUNCTION trg_cascade_change_qu_id_stock2();

-- ---------------------------------------------------------------------------------------
-- remove_conversions
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER remove_conversions AFTER DELETE ON quantity_units
-- BEGIN
-- 	DELETE FROM quantity_unit_conversions
-- 	WHERE from_qu_id = OLD.id
-- 		OR to_qu_id = OLD.id;
-- END;
DROP TRIGGER IF EXISTS remove_conversions ON quantity_units;
CREATE OR REPLACE FUNCTION trg_remove_conversions() RETURNS TRIGGER AS $$
BEGIN
	DELETE FROM quantity_unit_conversions
	WHERE from_qu_id = OLD.id
		OR to_qu_id = OLD.id;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER remove_conversions AFTER DELETE ON quantity_units
FOR EACH ROW EXECUTE FUNCTION trg_remove_conversions();

-- ---------------------------------------------------------------------------------------
-- default_qu_INS / default_qu_UPD
-- Own-row fixup (sets product_barcodes.qu_id on the row just written) -> BEFORE, NEW
-- assignment, per README rule. Rewritten to look the product's qu_id_stock up directly via
-- NEW.product_id instead of re-reading product_barcodes by NEW.id.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER default_qu_INS AFTER INSERT ON product_barcodes
-- BEGIN
-- 	UPDATE product_barcodes
-- 	SET qu_id = (SELECT qu_id_stock FROM products WHERE id = product_barcodes.product_id)
-- 	WHERE id = NEW.id
-- 		AND IFNULL(qu_id, 0) = 0;
-- END;
DROP TRIGGER IF EXISTS default_qu_INS ON product_barcodes;
CREATE OR REPLACE FUNCTION trg_default_qu_INS() RETURNS TRIGGER AS $$
BEGIN
	IF COALESCE(NEW.qu_id, 0) = 0 THEN
		NEW.qu_id := (SELECT qu_id_stock FROM products WHERE id = NEW.product_id);
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER default_qu_INS BEFORE INSERT ON product_barcodes
FOR EACH ROW EXECUTE FUNCTION trg_default_qu_INS();

-- CREATE TRIGGER default_qu_UPD AFTER UPDATE ON product_barcodes (same body, same idea)
DROP TRIGGER IF EXISTS default_qu_UPD ON product_barcodes;
CREATE OR REPLACE FUNCTION trg_default_qu_UPD() RETURNS TRIGGER AS $$
BEGIN
	IF COALESCE(NEW.qu_id, 0) = 0 THEN
		NEW.qu_id := (SELECT qu_id_stock FROM products WHERE id = NEW.product_id);
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER default_qu_UPD BEFORE UPDATE ON product_barcodes
FOR EACH ROW EXECUTE FUNCTION trg_default_qu_UPD();

-- ---------------------------------------------------------------------------------------
-- default_qu_id_consume / default_qu_id_price
-- Own-row fixup on products itself -> BEFORE INSERT, NEW assignment.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER default_qu_id_consume AFTER INSERT ON products
-- BEGIN
-- 	UPDATE products
-- 	SET qu_id_consume = qu_id_stock
-- 	WHERE id = NEW.id
-- 		AND IFNULL(qu_id_consume, 0) = 0;
-- END;
DROP TRIGGER IF EXISTS default_qu_id_consume ON products;
CREATE OR REPLACE FUNCTION trg_default_qu_id_consume() RETURNS TRIGGER AS $$
BEGIN
	IF COALESCE(NEW.qu_id_consume, 0) = 0 THEN
		NEW.qu_id_consume := NEW.qu_id_stock;
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER default_qu_id_consume BEFORE INSERT ON products
FOR EACH ROW EXECUTE FUNCTION trg_default_qu_id_consume();

-- CREATE TRIGGER default_qu_id_price AFTER INSERT ON products
-- BEGIN
-- 	UPDATE products
-- 	SET qu_id_price = qu_id_purchase
-- 	WHERE id = NEW.id
-- 		AND IFNULL(qu_id_price, 0) = 0;
-- END;
DROP TRIGGER IF EXISTS default_qu_id_price ON products;
CREATE OR REPLACE FUNCTION trg_default_qu_id_price() RETURNS TRIGGER AS $$
BEGIN
	IF COALESCE(NEW.qu_id_price, 0) = 0 THEN
		NEW.qu_id_price := NEW.qu_id_purchase;
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER default_qu_id_price BEFORE INSERT ON products
FOR EACH ROW EXECUTE FUNCTION trg_default_qu_id_price();

-- ---------------------------------------------------------------------------------------
-- qu_conversions_custom_constraint_INS / _UPD
-- Pure validation (uniqueness of from_qu_id/to_qu_id/product_id, which a real UNIQUE
-- constraint can't express because SQLite - and this needs the same NULL-handling here -
-- treats NULL product_id specially). Already BEFORE; RAISE ABORT -> RAISE EXCEPTION.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER qu_conversions_custom_constraint_INS BEFORE INSERT ON quantity_unit_conversions
-- BEGIN
-- 	/* Necessary because unique constraints don't include NULL values in SQLite */
-- SELECT CASE WHEN((
-- 	SELECT 1
-- 	FROM quantity_unit_conversions
-- 	WHERE from_qu_id = NEW.from_qu_id
-- 		AND to_qu_id = NEW.to_qu_id
-- 		AND IFNULL(product_id, 0) = IFNULL(NEW.product_id, 0)
-- 	)
-- 	NOTNULL) THEN RAISE(ABORT, 'QU conversion already exists') END;
-- END;
DROP TRIGGER IF EXISTS qu_conversions_custom_constraint_INS ON quantity_unit_conversions;
CREATE OR REPLACE FUNCTION trg_qu_conversions_custom_constraint_INS() RETURNS TRIGGER AS $$
BEGIN
	-- Necessary because unique constraints don't include NULL values in SQLite
	IF EXISTS(
		SELECT 1
		FROM quantity_unit_conversions
		WHERE from_qu_id = NEW.from_qu_id
			AND to_qu_id = NEW.to_qu_id
			AND COALESCE(product_id, 0) = COALESCE(NEW.product_id, 0)
	) THEN
		RAISE EXCEPTION 'QU conversion already exists';
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER qu_conversions_custom_constraint_INS BEFORE INSERT ON quantity_unit_conversions
FOR EACH ROW EXECUTE FUNCTION trg_qu_conversions_custom_constraint_INS();

-- CREATE TRIGGER qu_conversions_custom_constraint_UPD BEFORE UPDATE ON quantity_unit_conversions
-- BEGIN
-- 	/* This contains practically the same logic as the trigger qu_conversions_custom_constraint_INS */
-- 	/* Necessary because unique constraints don't include NULL values in SQLite */
-- SELECT CASE WHEN((
-- 	SELECT 1
-- 	FROM quantity_unit_conversions
-- 	WHERE from_qu_id = NEW.from_qu_id
-- 		AND to_qu_id = NEW.to_qu_id
-- 		AND IFNULL(product_id, 0) = IFNULL(NEW.product_id, 0)
-- 		AND id != NEW.id
-- 	)
-- 	NOTNULL) THEN RAISE(ABORT, 'QU conversion already exists') END;
-- END;
DROP TRIGGER IF EXISTS qu_conversions_custom_constraint_UPD ON quantity_unit_conversions;
CREATE OR REPLACE FUNCTION trg_qu_conversions_custom_constraint_UPD() RETURNS TRIGGER AS $$
BEGIN
	-- This contains practically the same logic as trg_qu_conversions_custom_constraint_INS.
	-- Necessary because unique constraints don't include NULL values in SQLite
	IF EXISTS(
		SELECT 1
		FROM quantity_unit_conversions
		WHERE from_qu_id = NEW.from_qu_id
			AND to_qu_id = NEW.to_qu_id
			AND COALESCE(product_id, 0) = COALESCE(NEW.product_id, 0)
			AND id != NEW.id
	) THEN
		RAISE EXCEPTION 'QU conversion already exists';
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER qu_conversions_custom_constraint_UPD BEFORE UPDATE ON quantity_unit_conversions
FOR EACH ROW EXECUTE FUNCTION trg_qu_conversions_custom_constraint_UPD();

-- ---------------------------------------------------------------------------------------
-- enfore_product_nesting_level (name/typo preserved from upstream)
-- Pure validation, already BEFORE.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER enfore_product_nesting_level BEFORE UPDATE ON products
-- BEGIN
-- 	-- Currently only 1 level is supported
--     SELECT CASE WHEN((
--         SELECT 1
--         FROM products p
--         WHERE IFNULL(NEW.parent_product_id, '') != ''
--             AND IFNULL(parent_product_id, '') = NEW.id
--     ) NOTNULL) THEN RAISE(ABORT, 'Unsupported product nesting level detected (currently only 1 level is supported)') END;
-- END;
DROP TRIGGER IF EXISTS enfore_product_nesting_level ON products;
CREATE OR REPLACE FUNCTION trg_enfore_product_nesting_level() RETURNS TRIGGER AS $$
BEGIN
	-- Currently only 1 level is supported
	IF EXISTS(
		SELECT 1
		FROM products p
		WHERE NEW.parent_product_id IS NOT NULL
			AND p.parent_product_id = NEW.id
	) THEN
		RAISE EXCEPTION 'Unsupported product nesting level detected (currently only 1 level is supported)';
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER enfore_product_nesting_level BEFORE UPDATE ON products
FOR EACH ROW EXECUTE FUNCTION trg_enfore_product_nesting_level();

-- ---------------------------------------------------------------------------------------
-- enforce_min_stock_amount_for_cumulated_childs_INS / _UPD
-- Modifies CHILD rows, not its own row -> genuinely cross-row, stays AFTER.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER enforce_min_stock_amount_for_cumulated_childs_INS AFTER INSERT ON products
-- BEGIN
-- 	UPDATE products
-- 	SET min_stock_amount = 0
-- 	WHERE id IN (
-- 			SELECT p_child.id
-- 			FROM products p_parent
-- 			JOIN products p_child ON p_child.parent_product_id = p_parent.id
-- 			WHERE p_parent.id = NEW.id
-- 				AND IFNULL(p_parent.cumulate_min_stock_amount_of_sub_products, 0) = 1
-- 			)
-- 		AND min_stock_amount > 0;
-- END;
DROP TRIGGER IF EXISTS enforce_min_stock_amount_for_cumulated_childs_INS ON products;
CREATE OR REPLACE FUNCTION trg_enforce_min_stock_amount_for_cumulated_childs_INS() RETURNS TRIGGER AS $$
BEGIN
	-- When a parent product has cumulate_min_stock_amount_of_sub_products enabled,
	-- the child should not have any min_stock_amount
	UPDATE products
	SET min_stock_amount = 0
	WHERE id IN (
			SELECT p_child.id
			FROM products p_parent
			JOIN products p_child ON p_child.parent_product_id = p_parent.id
			WHERE p_parent.id = NEW.id
				AND COALESCE(p_parent.cumulate_min_stock_amount_of_sub_products, 0) = 1
			)
		AND min_stock_amount > 0;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER enforce_min_stock_amount_for_cumulated_childs_INS AFTER INSERT ON products
FOR EACH ROW EXECUTE FUNCTION trg_enforce_min_stock_amount_for_cumulated_childs_INS();

-- CREATE TRIGGER enforce_min_stock_amount_for_cumulated_childs_UPD AFTER UPDATE ON products (same body)
DROP TRIGGER IF EXISTS enforce_min_stock_amount_for_cumulated_childs_UPD ON products;
CREATE OR REPLACE FUNCTION trg_enforce_min_stock_amount_for_cumulated_childs_UPD() RETURNS TRIGGER AS $$
BEGIN
	-- When a parent product has cumulate_min_stock_amount_of_sub_products enabled,
	-- the child should not have any min_stock_amount
	UPDATE products
	SET min_stock_amount = 0
	WHERE id IN (
			SELECT p_child.id
			FROM products p_parent
			JOIN products p_child ON p_child.parent_product_id = p_parent.id
			WHERE p_parent.id = NEW.id
				AND COALESCE(p_parent.cumulate_min_stock_amount_of_sub_products, 0) = 1
			)
		AND min_stock_amount > 0;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER enforce_min_stock_amount_for_cumulated_childs_UPD AFTER UPDATE ON products
FOR EACH ROW EXECUTE FUNCTION trg_enforce_min_stock_amount_for_cumulated_childs_UPD();

-- ---------------------------------------------------------------------------------------
-- enforce_parent_product_id_null_when_empty_INS / _UPD
-- Own-row fixup -> BEFORE, NEW assignment, per README rule. Note: parent_product_id is
-- INTEGER in PostgreSQL, which cannot hold '' the way SQLite's loosely-typed column could,
-- so IFNULL(parent_product_id,'') != '' reduces to "IS NOT NULL" / "IS NULL". The trigger
-- degenerates to a no-op on this engine but is kept for structural/name parity.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER enforce_parent_product_id_null_when_empty_INS AFTER INSERT ON products
-- BEGIN
-- 	UPDATE products
-- 	SET parent_product_id = NULL
-- 	WHERE id = NEW.id
-- 		AND IFNULL(parent_product_id, '') = '';
-- END;
DROP TRIGGER IF EXISTS enforce_parent_product_id_null_when_empty_INS ON products;
CREATE OR REPLACE FUNCTION trg_enforce_parent_product_id_null_when_empty_INS() RETURNS TRIGGER AS $$
BEGIN
	IF NEW.parent_product_id IS NULL THEN
		NEW.parent_product_id := NULL;
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER enforce_parent_product_id_null_when_empty_INS BEFORE INSERT ON products
FOR EACH ROW EXECUTE FUNCTION trg_enforce_parent_product_id_null_when_empty_INS();

-- CREATE TRIGGER enforce_parent_product_id_null_when_empty_UPD AFTER UPDATE ON products (same body)
DROP TRIGGER IF EXISTS enforce_parent_product_id_null_when_empty_UPD ON products;
CREATE OR REPLACE FUNCTION trg_enforce_parent_product_id_null_when_empty_UPD() RETURNS TRIGGER AS $$
BEGIN
	IF NEW.parent_product_id IS NULL THEN
		NEW.parent_product_id := NULL;
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER enforce_parent_product_id_null_when_empty_UPD BEFORE UPDATE ON products
FOR EACH ROW EXECUTE FUNCTION trg_enforce_parent_product_id_null_when_empty_UPD();

-- ---------------------------------------------------------------------------------------
-- prevent_adding_barcodes_for_not_existing_products / prevent_adding_no_own_stock_products_to_stock
-- Pure validation triggers, literally AFTER INSERT in the original (functionally
-- indistinguishable from BEFORE for a plain RAISE-and-rollback check) -> kept AFTER as
-- written, for fidelity to the source.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER prevent_adding_barcodes_for_not_existing_products AFTER INSERT ON product_barcodes
-- BEGIN
-- 	SELECT CASE WHEN((
-- 		SELECT 1
-- 		FROM products p
-- 		WHERE id = NEW.product_id
-- 	) ISNULL) THEN RAISE(ABORT, 'product_id doesn''t reference a existing product') END;
-- END;
DROP TRIGGER IF EXISTS prevent_adding_barcodes_for_not_existing_products ON product_barcodes;
CREATE OR REPLACE FUNCTION trg_prevent_adding_barcodes_for_not_existing_products() RETURNS TRIGGER AS $$
BEGIN
	IF NOT EXISTS(
		SELECT 1
		FROM products p
		WHERE id = NEW.product_id
	) THEN
		RAISE EXCEPTION 'product_id doesn''t reference a existing product';
	END IF;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER prevent_adding_barcodes_for_not_existing_products AFTER INSERT ON product_barcodes
FOR EACH ROW EXECUTE FUNCTION trg_prevent_adding_barcodes_for_not_existing_products();

-- CREATE TRIGGER prevent_adding_no_own_stock_products_to_stock AFTER INSERT ON stock
-- BEGIN
-- 	SELECT CASE WHEN((
-- 		SELECT 1
-- 		FROM products p
-- 		WHERE id = NEW.product_id
-- 			AND no_own_stock = 1
-- 	) NOTNULL) THEN RAISE(ABORT, 'no_own_stock = 1 products can''t be added to stock') END;
-- END;
DROP TRIGGER IF EXISTS prevent_adding_no_own_stock_products_to_stock ON stock;
CREATE OR REPLACE FUNCTION trg_prevent_adding_no_own_stock_products_to_stock() RETURNS TRIGGER AS $$
BEGIN
	IF EXISTS(
		SELECT 1
		FROM products p
		WHERE id = NEW.product_id
			AND no_own_stock = 1
	) THEN
		RAISE EXCEPTION 'no_own_stock = 1 products can''t be added to stock';
	END IF;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER prevent_adding_no_own_stock_products_to_stock AFTER INSERT ON stock
FOR EACH ROW EXECUTE FUNCTION trg_prevent_adding_no_own_stock_products_to_stock();

-- ---------------------------------------------------------------------------------------
-- cascade_product_removal
-- Cross-table cascade delete, stays AFTER. userfield_values.object_id is TEXT, so OLD.id
-- needs an explicit cast.
-- ---------------------------------------------------------------------------------------

-- CREATE TRIGGER cascade_product_removal AFTER DELETE ON products
-- BEGIN
-- 	DELETE FROM stock WHERE product_id = OLD.id;
-- 	DELETE FROM stock_log WHERE product_id = OLD.id;
-- 	DELETE FROM product_barcodes WHERE product_id = OLD.id;
-- 	DELETE FROM quantity_unit_conversions WHERE product_id = OLD.id;
-- 	DELETE FROM recipes_pos WHERE product_id = OLD.id;
-- 	UPDATE recipes SET product_id = NULL WHERE product_id = OLD.id;
-- 	DELETE FROM meal_plan WHERE product_id = OLD.id AND type = 'product';
-- 	DELETE FROM shopping_list WHERE product_id = OLD.id;
-- 	DELETE FROM userfield_values WHERE object_id = OLD.id AND field_id IN (SELECT id FROM userfields WHERE entity = 'products');
-- END;
DROP TRIGGER IF EXISTS cascade_product_removal ON products;
CREATE OR REPLACE FUNCTION trg_cascade_product_removal() RETURNS TRIGGER AS $$
BEGIN
	DELETE FROM stock
	WHERE product_id = OLD.id;

	DELETE FROM stock_log
	WHERE product_id = OLD.id;

	DELETE FROM product_barcodes
	WHERE product_id = OLD.id;

	DELETE FROM quantity_unit_conversions
	WHERE product_id = OLD.id;

	DELETE FROM recipes_pos
	WHERE product_id = OLD.id;

	UPDATE recipes
	SET product_id = NULL
	WHERE product_id = OLD.id;

	DELETE FROM meal_plan
	WHERE product_id = OLD.id
		AND type = 'product';

	DELETE FROM shopping_list
	WHERE product_id = OLD.id;

	DELETE FROM userfield_values
	WHERE object_id = OLD.id::text
		AND field_id IN (SELECT id FROM userfields WHERE entity = 'products');

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER cascade_product_removal AFTER DELETE ON products
FOR EACH ROW EXECUTE FUNCTION trg_cascade_product_removal();
