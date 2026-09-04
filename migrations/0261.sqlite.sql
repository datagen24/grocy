-- Issue #46: two engine bugs in the price views, neither of them a porting change.
--
-- The SQL of products_average_price and products_last_purchased in this tree is
-- byte-identical to upstream grocy 4.6.0, so the issue's two hypotheses - that the port
-- changed which bookings count, or that the fork changed them on purpose - are both
-- wrong. What is wrong is that one of these views never had a defined answer, and the
-- other is wrong on SQLite.
--
-- 1. products_last_purchased.price is a correlated subquery ordered by purchased_date
--    alone, with LIMIT 1. Several bookings for one product commonly share a date - the
--    parity suite books a whole scenario in one run, and a household buying twice in one
--    day does the same - so the ORDER BY is not a total order and LIMIT 1 returns
--    whichever row the plan reaches first. Against the issue's booking sequence that is
--    1.23 here and 2.50 upstream, and SQLite alone gives 1.23, 4.56 or 2.50 depending on
--    whether an index is present. Adding the ledger row id as a tie-break makes "the last
--    price" mean the newest booking on the newest day, on both engines and under every
--    plan; it also lands on the 2.50 upstream happened to return, which is what "the
--    price of the last purchase" means.
--
--    This is not only a reporting defect. StockService::InventoryProduct() uses
--    last_price as the default price of a new inventory booking, so the undefined answer
--    has been writing itself into the ledger.
--
-- 2. products_average_price divides SUM by SUM. stock_log.amount and .price are
--    DECIMAL(15,2), which is NUMERIC affinity, so SQLite stores whole numbers as INTEGER
--    and the division truncates: 4 @ 2, 3 @ 2 and 2 @ 3 average to 20/9 = 2 rather than
--    2.2222. PostgreSQL's columns are DOUBLE PRECISION and never had this, which is why
--    only the SQLite side of the pair changes it. This is hazard 4 (integer division)
--    reaching an ExposedEntity, and it is an upstream bug: any household whose purchases
--    all have whole-number amounts and prices has been reading a truncated average.
--
-- ADR-0005 decides both: the JSON on the wire is the invariant, and where the engines
-- disagree the porting work moves whichever one is wrong. Neither difference is the
-- ~1e-15 float accumulation that ADR accepts - these differ in the units column.
--
-- The cache rebuilds at the end are not optional. uihelper_product_details reads
-- cache__products_last_purchased and cache__products_average_price, not these views, so
-- without them every existing product keeps reporting the old value until its next
-- booking - which would look exactly like the fix not working.

-- 1. The ledger row id, so the price lookup in products_last_purchased can order totally.
--    Not on the wire: products_price_history is not an ExposedEntity,
--    GetProductPriceHistory() projects date, price and shopping location only, and the
--    spendings report names the columns it selects.
DROP VIEW products_price_history;
CREATE VIEW products_price_history
AS
SELECT
	sl.product_id AS id, -- Dummy, LessQL needs an id column
	sl.product_id,
	sl.price,
	IFNULL(sl.edited_origin_amount, sl.amount) AS amount,
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
	AND IFNULL(sl.price, 0) > 0
	AND IFNULL(sl.amount, 0) > 0;

-- 2. Real division, so a product whose amounts and prices are all whole numbers gets
--    an average rather than a truncated one. SQLite only.
DROP VIEW products_average_price;
CREATE VIEW products_average_price
AS
SELECT
	1 AS id, -- Dummy, LessQL needs an id column
	sl.product_id,
	SUM(IFNULL(sl.edited_origin_amount, sl.amount) * sl.price) / CAST(SUM(IFNULL(sl.edited_origin_amount, sl.amount)) AS REAL) as price
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
	AND IFNULL(sl.price, 0) > 0
	AND IFNULL(sl.amount, 0) > 0
GROUP BY sl.product_id;

-- 3. The tie-break. Every other line here is 0236's text unchanged.
DROP VIEW products_last_purchased;
CREATE VIEW products_last_purchased
AS
SELECT
	1 AS id, -- Dummy, LessQL needs an id column
	sl.product_id,
	sl.amount,
	sl.best_before_date,
	sl.purchased_date,
	sl.location_id,
	sl.shopping_location_id,
	IFNULL((SELECT price FROM products_price_history WHERE product_id = sl.product_id ORDER BY purchased_date DESC, stock_log_id DESC LIMIT 1), 0) AS price
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
INSERT OR REPLACE INTO cache__products_average_price
	(product_id, price)
SELECT product_id, price
FROM products_average_price;

INSERT OR REPLACE INTO cache__products_last_purchased
	(product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id)
SELECT product_id, amount, best_before_date, purchased_date, price, location_id, shopping_location_id
FROM products_last_purchased;
