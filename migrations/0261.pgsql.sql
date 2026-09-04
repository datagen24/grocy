-- Issue #46, PostgreSQL side. See 0261.sqlite.sql for the full reasoning; in short,
-- products_last_purchased.price orders by purchased_date alone and takes LIMIT 1, which
-- is not a total order, so the answer depends on the plan. The ledger row id becomes the
-- tie-break on both engines.
--
-- This side does NOT change products_average_price. That view's defect is SQLite's
-- integer division over NUMERIC-affinity columns; PostgreSQL's amount and price are
-- DOUBLE PRECISION and have always divided correctly. An engine pair whose two halves do
-- different amounts of work is the normal case under ADR-0004 - what has to match is the
-- state the two engines end up in, not the number of statements taken to get there.
--
-- The drop order is load-bearing. products_last_purchased selects from
-- products_price_history, and PostgreSQL refuses to drop a view another view depends on:
--   ERROR: cannot drop view products_price_history because other objects depend on it
-- CASCADE would obey, but it would also take stock_edited_entries and
-- products_average_price with it and leave the database short of two views nobody
-- recreated. So the dependent goes first and is rebuilt last.
--
-- The baseline in db/pgsql/baseline/ is deliberately not edited: it is defined as the
-- state SQLite reaches after migrations 0001-0255, and a fresh PostgreSQL database loads
-- it and then runs 0256 onwards. Changing both would apply this twice.

DROP VIEW products_last_purchased;
DROP VIEW products_price_history;

CREATE VIEW products_price_history AS
SELECT
	sl.product_id AS id, -- Dummy, LessQL needs an id column
	sl.product_id,
	sl.price,
	COALESCE(sl.edited_origin_amount, sl.amount) AS amount,
	sl.purchased_date,
	sl.shopping_location_id,
	sl.transaction_type,
	sl.id AS stock_log_id
FROM (
	SELECT sl.*, CASE WHEN sl.transaction_type = 'stock-edit-new' THEN see.edited_origin_amount END AS edited_origin_amount
	FROM stock_log sl
	LEFT JOIN stock_edited_entries see
		ON sl.stock_id = see.stock_id
) sl
WHERE sl.undone = 0
	AND (
		(sl.transaction_type IN ('purchase', 'inventory-correction', 'self-production') AND sl.stock_id NOT IN (SELECT stock_id FROM stock_edited_entries)) -- Unedited origin entries
		OR (sl.transaction_type = 'stock-edit-new' AND sl.id IN (SELECT stock_log_id_of_newest_edited_entry FROM stock_edited_entries)) -- Edited origin entries => take the newest "stock-edit-new" one
	)
	AND COALESCE(sl.price, 0) > 0
	AND COALESCE(sl.amount, 0) > 0;

CREATE VIEW products_last_purchased AS
SELECT
	1 AS id, -- Dummy, LessQL needs an id column
	sl.product_id,
	sl.amount,
	sl.best_before_date,
	sl.purchased_date,
	sl.location_id,
	sl.shopping_location_id,
	COALESCE((SELECT price FROM products_price_history WHERE product_id = sl.product_id ORDER BY purchased_date DESC, stock_log_id DESC LIMIT 1), 0) AS price
FROM stock_log sl
JOIN (
	/*
		This subquery gets the ID of the stock_log row (per product) which referes to the last purchase transaction,
		while taking undone and edited transactions into account
	*/
	SELECT
		sl1.product_id,
		MAX(sl1.id) stock_log_id_of_last_purchase
	FROM stock_log sl1
	JOIN (
		/*
			This subquery finds the last purchased date per product,
			there can be multiple purchase transactions per day, therefore a JOIN by purchased_date
			for the outer query on this and then take MAX id of stock_log (of that day)
		*/
		SELECT
			sl2.product_id,
			MAX(sl2.purchased_date) AS last_purchased_date
		FROM stock_log sl2
		WHERE sl2.undone = 0
			AND (
				(sl2.transaction_type IN ('purchase', 'inventory-correction', 'self-production') AND sl2.stock_id NOT IN (SELECT stock_id FROM stock_edited_entries))
				OR (sl2.transaction_type = 'stock-edit-new' AND sl2.stock_id IN (SELECT stock_id FROM stock_edited_entries) AND sl2.id IN (SELECT stock_log_id_of_newest_edited_entry FROM stock_edited_entries))
			)
		GROUP BY sl2.product_id
	) x2
		ON sl1.product_id = x2.product_id
		AND sl1.purchased_date = x2.last_purchased_date
	WHERE sl1.undone = 0
		AND (
			(sl1.transaction_type IN ('purchase', 'inventory-correction', 'self-production') AND sl1.stock_id NOT IN (SELECT stock_id FROM stock_edited_entries))
			OR (sl1.transaction_type = 'stock-edit-new' AND sl1.stock_id IN (SELECT stock_id FROM stock_edited_entries) AND sl1.id IN (SELECT stock_log_id_of_newest_edited_entry FROM stock_edited_entries))
		)
	GROUP BY sl1.product_id
) x
	ON sl.product_id = x.product_id
	AND sl.id = x.stock_log_id_of_last_purchase;

-- Rebuild both caches, because the API reads those rather than these views.
-- products_average_price is refreshed too: its rows are unchanged by this migration, but
-- a cache that is only ever written by the stock_log triggers is worth reasserting from
-- the view while we are here, and the statement is a no-op when they already agree.
INSERT INTO cache__products_average_price (product_id, price)
SELECT product_id, price
FROM products_average_price
ON CONFLICT (product_id) DO UPDATE SET
	price = EXCLUDED.price;

INSERT INTO cache__products_last_purchased
	(product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id)
SELECT product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id
FROM products_last_purchased
ON CONFLICT (product_id) DO UPDATE SET
	amount = EXCLUDED.amount,
	best_before_date = EXCLUDED.best_before_date,
	purchased_date = EXCLUDED.purchased_date,
	price = EXCLUDED.price,
	location_id = EXCLUDED.location_id,
	shopping_location_id = EXCLUDED.shopping_location_id;
