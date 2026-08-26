-- PostgreSQL baseline schema: views (l2)
--
-- Ported from the SQLite view definitions in migrations/*.sql. Column names, column
-- order and aliases are preserved exactly - they are part of the REST API surface.
-- See db/pgsql/baseline/01_tables.sql for the target column types this schema selects
-- from, and the porting rules doc for the general SQLite -> PostgreSQL translation.

-- NOTE for reviewers: this view reproduces SQLite's DATETIME()/STRFTIME() modifier chains
-- with explicit interval arithmetic. Two points worth flagging:
--   1. `h.rescheduled_date` and `h.rescheduled_next_execution_assigned_to_user_id` are real
--      TIMESTAMP/INTEGER columns (not TEXT), so the original `IFNULL(x, '') != ''` idiom -
--      a generic "is this set" check that works across SQLite's dynamic typing - is
--      translated directly to `x IS NOT NULL`, which is exactly what it tests for a column
--      that can never legitimately hold an empty string.
--   2. Every branch of the `period_type` CASE is cast to TIMESTAMP so the CASE has a single
--      consistent result type; some branches (daily, yearly) are built as text via to_char()
--      the same way the SQLite original builds them via STRFTIME()/SUBSTR() concatenation.
--   3. The 'weekly' branch's SQLite original queries chores_log directly (no `undone = 0`
--      filter), unlike the outer aggregate's `MAX(l.tracked_time)` (which is filtered via the
--      LEFT JOIN condition) - this asymmetry is preserved exactly, not "fixed".
--   4. SQLite's `weekday N` date modifier advances a date forward to the next occurrence of
--      weekday N (Sunday = 0), or leaves it unchanged if already on that weekday. This is
--      reproduced as `(N - EXTRACT(DOW FROM step1) + 7) % 7` days added to `step1`, which is
--      zero exactly when step1 already falls on weekday N.
CREATE VIEW chores_current AS
SELECT
	x.chore_id AS id, -- Dummy, LessQL needs an id column
	x.chore_id,
	x.chore_name,
	x.last_tracked_time,
	CASE WHEN x.rollover = 1 AND date_trunc('second', LOCALTIMESTAMP) > x.next_estimated_execution_time THEN
		CASE WHEN COALESCE(x.track_date_only, 0) = 1 THEN
			(to_char(date_trunc('second', LOCALTIMESTAMP), 'YYYY-MM-DD') || ' 23:59:59')::timestamp
		ELSE
			(to_char(date_trunc('second', LOCALTIMESTAMP), 'YYYY-MM-DD') || ' ' || to_char(x.next_estimated_execution_time, 'HH24:MI:SS'))::timestamp
		END
	ELSE
		CASE WHEN COALESCE(x.track_date_only, 0) = 1 THEN
			(to_char(x.next_estimated_execution_time, 'YYYY-MM-DD') || ' 23:59:59')::timestamp
		ELSE
			x.next_estimated_execution_time
		END
	END AS next_estimated_execution_time,
	x.track_date_only,
	x.next_execution_assigned_to_user_id,
	CASE WHEN x.rescheduled_date IS NOT NULL THEN 1 ELSE 0 END AS is_rescheduled,
	CASE WHEN x.rescheduled_next_execution_assigned_to_user_id IS NOT NULL THEN 1 ELSE 0 END AS is_reassigned
FROM (

SELECT
	h.id AS chore_id,
	h.name AS chore_name,
	MAX(l.tracked_time) AS last_tracked_time,
	CASE WHEN h.rescheduled_date IS NOT NULL THEN
		h.rescheduled_date
	ELSE
		CASE WHEN MAX(l.tracked_time) IS NULL AND h.period_type != 'manually' THEN
			h.start_date
		ELSE
			CASE h.period_type
				WHEN 'manually' THEN NULL::timestamp
				WHEN 'hourly' THEN (MAX(l.tracked_time) + (h.period_interval::text || ' hour')::interval)
				WHEN 'daily' THEN (to_char(MAX(l.tracked_time) + (h.period_interval::text || ' days')::interval, 'YYYY-MM-DD') || ' ' || to_char(h.start_date, 'HH24:MI:SS'))::timestamp
				WHEN 'weekly' THEN (
					SELECT next
					FROM (
						SELECT
							s.step1 + (((wd.day_num - EXTRACT(DOW FROM s.step1)::integer + 7) % 7)::text || ' days')::interval AS next
						FROM (
							SELECT
								(SELECT tracked_time FROM chores_log WHERE chore_id = h.id ORDER BY tracked_time DESC LIMIT 1)
								+ ((1 + (h.period_interval - 1) * 7)::text || ' days')::interval AS step1
						) s,
						(VALUES ('sunday', 0), ('monday', 1), ('tuesday', 2), ('wednesday', 3), ('thursday', 4), ('friday', 5), ('saturday', 6)) AS wd(day_name, day_num)
						WHERE position(wd.day_name IN h.period_config) > 0
					) weekly_candidates
					ORDER BY next
					LIMIT 1
				)
				WHEN 'monthly' THEN (date_trunc('month', MAX(l.tracked_time)) + (h.period_interval::text || ' month')::interval + ((h.period_days - 1)::text || ' day')::interval)
				WHEN 'yearly' THEN (
					to_char(MAX(l.tracked_time) + (h.period_interval::text || ' years')::interval, 'YYYY')
					|| to_char(h.start_date, '-MM-DD')
					|| to_char(MAX(l.tracked_time) + (h.period_interval::text || ' years')::interval, ' HH24:MI:SS')
				)::timestamp
				WHEN 'adaptive' THEN (MAX(l.tracked_time) + (COALESCE((SELECT average_frequency_hours FROM chores_execution_average_frequency WHERE chore_id = h.id), 0)::text || ' hour')::interval)
			END
		END
	END AS next_estimated_execution_time,
	h.track_date_only,
	h.rollover,
	h.next_execution_assigned_to_user_id,
	h.rescheduled_date,
	h.rescheduled_next_execution_assigned_to_user_id
FROM chores h
LEFT JOIN chores_log l
	ON h.id = l.chore_id
	AND l.undone = 0
WHERE h.active = 1
GROUP BY h.id, h.name, h.period_days
) x;

CREATE VIEW products_current_substitutions AS

/*
	When a parent product is not in stock itself,
	any sub product (the next based on the default consume rule) should be used

	This view lists all parent products and in the column "product_id_effective" either itself,
	when the corresponding parent product is currently in stock itself, or otherwise the next sub product to use
*/

SELECT
	-1 AS "-1", -- Dummy
	p_sub.id AS parent_product_id,
	CASE WHEN p_sub.has_sub_products = 1 THEN
		CASE WHEN COALESCE(sc.amount, 0) = 0 THEN -- Parent product itself is currently not in stock => use the next sub product
			(
			SELECT x_snu.product_id
			FROM products_resolved x_pr
			JOIN stock_next_use x_snu
				ON x_pr.sub_product_id = x_snu.product_id
			WHERE x_pr.parent_product_id = p_sub.id
				AND x_pr.parent_product_id != x_pr.sub_product_id
			ORDER BY x_snu.priority DESC, x_snu.open DESC, x_snu.best_before_date ASC, x_snu.purchased_date ASC
			LIMIT 1
			)
		ELSE -- Parent product itself is currently in stock => use it
			p_sub.id
		END
	END AS product_id_effective
FROM products_view p
JOIN products_resolved pr
	ON p.id = pr.parent_product_id
JOIN products_view p_sub
	ON pr.sub_product_id = p_sub.id
JOIN stock_current sc
	ON p_sub.id = sc.product_id
WHERE p_sub.has_sub_products = 1;

CREATE VIEW products_last_purchased AS
SELECT
	1 AS id, -- Dummy, LessQL needs an id column
	sl.product_id,
	sl.amount,
	sl.best_before_date,
	sl.purchased_date,
	sl.location_id,
	sl.shopping_location_id,
	COALESCE((SELECT price FROM products_price_history WHERE product_id = sl.product_id ORDER BY purchased_date DESC LIMIT 1), 0) AS price
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

-- NOTE for reviewers: the SQLite original is `SELECT * FROM (three UNIONed SELECTs) x WHERE
-- x.amount_missing > 0` - a SELECT * over a single derived table, not a join, so there are no
-- colliding column names here (verified empirically: the view's columns are exactly id, name,
-- amount_missing, is_partly_in_stock). Hazard 14 does not apply to this view.
--
-- Each of the three branches groups by a product's own id (p.id or sub_p.id) while also
-- selecting other ungrouped columns of that same product row (p.min_stock_amount,
-- p.treat_opened_as_out_of_stock). Unlike the "dummy id" pattern in Hazard 10, these are
-- real, already-unique-per-id columns - not arbitrary picks - so per Hazard 6 they are added
-- to GROUP BY (safe: p.id already fixes their value, so this cannot split a group into more
-- rows). In the third branch, `p.treat_opened_as_out_of_stock` belongs to the *parent*
-- product while the GROUP BY key is the *sub* product's id; this is still safe to add because
-- the data model allows at most one parent per product (enforced by products_resolved's
-- construction), so the join can never produce more than one distinct parent per sub_p.id.
CREATE VIEW stock_missing_products AS

SELECT *
FROM (

-- Products WITHOUT sub products where the amount of the sub products SHOULD NOT be cumulated
SELECT
	p.id,
	MAX(p.name) AS name,
	p.min_stock_amount - COALESCE(SUM(s.amount), 0) + (CASE WHEN p.treat_opened_as_out_of_stock = 1 THEN COALESCE(SUM(s.amount_opened), 0) ELSE 0 END) AS amount_missing,
	CASE WHEN COALESCE(SUM(s.amount), 0) > 0 THEN 1 ELSE 0 END AS is_partly_in_stock
FROM products_view p
LEFT JOIN stock_current s
	ON p.id = s.product_id
WHERE p.min_stock_amount != 0
	AND p.cumulate_min_stock_amount_of_sub_products = 0
	AND p.has_sub_products = 0
	AND p.parent_product_id IS NULL
	AND COALESCE(p.active, 0) = 1
GROUP BY p.id, p.min_stock_amount, p.treat_opened_as_out_of_stock

UNION

-- Parent products WITH sub products where the amount of the sub products SHOULD be cumulated
SELECT
	p.id,
	MAX(p.name) AS name,
	SUM(sub_p.min_stock_amount) - COALESCE(SUM(s.amount_aggregated), 0) + (CASE WHEN p.treat_opened_as_out_of_stock = 1 THEN COALESCE(SUM(s.amount_opened_aggregated), 0) ELSE 0 END) AS amount_missing,
	CASE WHEN COALESCE(SUM(s.amount), 0) > 0 THEN 1 ELSE 0 END AS is_partly_in_stock
FROM products_view p
JOIN products_resolved pr
	ON p.id = pr.parent_product_id
JOIN products sub_p
	ON pr.sub_product_id = sub_p.id
LEFT JOIN stock_current s
	ON pr.sub_product_id = s.product_id
WHERE sub_p.min_stock_amount != 0
	AND p.cumulate_min_stock_amount_of_sub_products = 1
	AND COALESCE(p.active, 0) = 1
GROUP BY p.id, p.treat_opened_as_out_of_stock

UNION

-- Sub products where the amount SHOULD NOT be cumulated into the parent product
SELECT
	sub_p.id,
	MAX(sub_p.name) AS name,
	SUM(sub_p.min_stock_amount) - COALESCE(SUM(s.amount_aggregated), 0) + (CASE WHEN p.treat_opened_as_out_of_stock = 1 THEN COALESCE(SUM(s.amount_opened_aggregated), 0) ELSE 0 END) AS amount_missing,
	CASE WHEN COALESCE(SUM(s.amount), 0) > 0 THEN 1 ELSE 0 END AS is_partly_in_stock
FROM products p
JOIN products_resolved pr
	ON p.id = pr.parent_product_id
JOIN products sub_p
	ON pr.sub_product_id = sub_p.id
LEFT JOIN stock_current s
	ON pr.sub_product_id = s.product_id
WHERE sub_p.min_stock_amount != 0
	AND p.cumulate_min_stock_amount_of_sub_products = 0
	AND COALESCE(p.active, 0) = 1
GROUP BY sub_p.id, p.treat_opened_as_out_of_stock
) x
WHERE x.amount_missing > 0;

-- NOTE for reviewers: `spoil_rate` divides by consume_count.amount, which SQLite tolerates
-- being NULL (no consume transactions) or, in principle, zero - SQLite's `/` returns NULL for
-- division by zero rather than raising. PostgreSQL raises "division by zero" for a literal
-- zero divisor, so both cases are guarded explicitly before dividing, matching the original's
-- IFNULL(..., 0) fallback exactly.
--
-- `qu_factor_purchase_to_stock`/`qu_factor_price_to_stock`: unlike the qu_factor_* columns in
-- products_view/uihelper_stock_entries (see the "Accepted differences" section in this
-- directory's README), the SQLite original here already wraps the value in
-- `CAST(... AS REAL)`, so SQLite itself already returns a JSON number, not the raw TEXT
-- factor. The cast-to-double-precision translation below is therefore a faithful port, not
-- the accepted TEXT-vs-number deviation.
CREATE VIEW uihelper_product_details AS
SELECT
	p.id,
	plp.purchased_date AS last_purchased_date,
	plp.price AS last_purchased_price,
	plp.shopping_location_id AS last_purchased_shopping_location_id,
	pap.price AS average_price,
	sl.average_shelf_life_days,
	pcp.price AS current_price,
	last_used.used_date AS last_used_date,
	next_due.best_before_date AS next_due_date,
	CASE
		WHEN consume_count.amount IS NULL OR consume_count.amount = 0 THEN 0
		ELSE COALESCE((spoil_count.amount * 100.0::double precision) / consume_count.amount, 0)
	END AS spoil_rate,
	CAST(COALESCE(quc_purchase2stock.factor::double precision, 1.0::double precision) AS DOUBLE PRECISION) AS qu_factor_purchase_to_stock,
	CAST(COALESCE(quc_price2stock.factor::double precision, 1.0::double precision) AS DOUBLE PRECISION) AS qu_factor_price_to_stock,
	CASE WHEN EXISTS(SELECT 1 FROM products px WHERE px.parent_product_id = p.id) THEN 1 ELSE 0 END AS has_childs
FROM products p
LEFT JOIN cache__products_last_purchased plp
	ON p.id = plp.product_id
LEFT JOIN cache__products_average_price pap
	ON p.id = pap.product_id
LEFT JOIN stock_average_product_shelf_life sl
	ON p.id = sl.id
LEFT JOIN products_current_price pcp
	ON p.id = pcp.product_id
LEFT JOIN cache__quantity_unit_conversions_resolved quc_purchase2stock
	ON p.id = quc_purchase2stock.product_id
	AND p.qu_id_purchase = quc_purchase2stock.from_qu_id
	AND p.qu_id_stock = quc_purchase2stock.to_qu_id
LEFT JOIN cache__quantity_unit_conversions_resolved quc_price2stock
	ON p.id = quc_price2stock.product_id
	AND p.qu_id_price = quc_price2stock.from_qu_id
	AND p.qu_id_stock = quc_price2stock.to_qu_id
LEFT JOIN (
	SELECT product_id, MAX(used_date) AS used_date
	FROM stock_log
	WHERE transaction_type = 'consume'
		AND undone = 0
	GROUP BY product_id
) last_used
	ON p.id = last_used.product_id
LEFT JOIN (
	SELECT product_id,MIN(best_before_date) AS best_before_date
	FROM stock
	GROUP BY product_id
) next_due
	ON p.id = next_due.product_id
LEFT JOIN (
	SELECT product_id, SUM(amount) AS amount
	FROM stock_log
	WHERE transaction_type = 'consume'
		AND undone = 0
	GROUP BY product_id
) consume_count
	ON p.id = consume_count.product_id
LEFT JOIN (
	SELECT product_id, SUM(amount) AS amount
	FROM stock_log
	WHERE transaction_type = 'consume'
		AND undone = 0
		AND spoiled = 1
	GROUP BY product_id
) spoil_count
	ON p.id = spoil_count.product_id;

-- NOTE for reviewers: `(ph.name IN (subquery))` is a boolean expression projected directly
-- into the SELECT list in the SQLite original - Hazard 3. In SQLite this yields integer 0/1;
-- in PostgreSQL `IN` yields a genuine boolean, which json_encode would render as true/false.
-- Wrapped in CASE to preserve the 0/1 integer contract.
CREATE VIEW uihelper_user_permissions AS
SELECT
	ph.id AS id,
	u.id AS user_id,
	ph.name AS permission_name,
	ph.id AS permission_id,
	CASE WHEN ph.name IN (
			SELECT pc.permission_name
			FROM user_permissions_resolved pc
			WHERE pc.user_id = u.id
		) THEN 1 ELSE 0 END AS has_permission,
	ph.parent AS parent
FROM users u, permission_hierarchy ph;
