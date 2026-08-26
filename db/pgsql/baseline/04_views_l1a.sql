-- PostgreSQL baseline schema: views (l1a)
--
-- Ported from the SQLite view definitions in migrations/*.sql. Column names, column
-- order and aliases are preserved exactly - they are part of the REST API surface.
-- See db/pgsql/baseline/01_tables.sql for the target column types this schema selects
-- from, and the porting rules doc for the general SQLite -> PostgreSQL translation.

-- NOTE for reviewers: two deliberate deviations from a literal translation, both required
-- for PostgreSQL's stricter GROUP BY rules rather than being "improvements":
--   1. ROUND(double precision, integer) does not exist in PostgreSQL - only
--      round(double precision) [1-arg] and round(numeric, integer) [2-arg] do. `value` in
--      both halves of the UNION is cast to numeric to call the 2-arg form and cast back to
--      double precision afterwards so a NUMERIC (which serialises as a JSON string) never
--      leaks out.
--   2. First half of the UNION: `qucr.factor` is joined per sub-product (pr.sub_product_id
--      = qucr.product_id) but the GROUP BY key is pr.parent_product_id, so a parent with
--      several sub-products can see several different qucr.factor values within one group.
--      The original SQLite bare-column-in-aggregate-query behaviour picks one arbitrarily;
--      MIN() is used here to get a single deterministic value per Hazard 10's guidance,
--      without adding the column to GROUP BY (which would multiply the row count).
--      Second half of the UNION: the correlated subqueries for amount_opened and
--      amount_opened_aggregated originally correlate on the bare `s.product_id` column,
--      which is not itself the GROUP BY key (`pr.sub_product_id` is, though the two are
--      always equal via the JOIN condition). PostgreSQL's GROUP BY validation does not
--      infer that equality from a join predicate, so the correlation is rewritten against
--      `pr.sub_product_id` - the literal GROUP BY column - which is guaranteed equal.
CREATE VIEW stock_current AS
SELECT
	pr.parent_product_id AS product_id,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.parent_product_id), 0) AS amount,
	SUM(s.amount * COALESCE(qucr.factor::double precision, 1.0::double precision)) AS amount_aggregated,
	COALESCE(CAST(ROUND(CAST((SELECT SUM(COALESCE(price,0) * amount) FROM stock WHERE product_id = pr.parent_product_id) AS numeric), 2) AS double precision), 0) AS value,
	MIN(s.best_before_date) AS best_before_date,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.parent_product_id AND open = 1), 0) AS amount_opened,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id IN (SELECT sub_product_id FROM products_resolved WHERE parent_product_id = pr.parent_product_id) AND open = 1), 0) * COALESCE(MIN(qucr.factor::double precision), 1.0) AS amount_opened_aggregated,
	CASE WHEN COUNT(p_sub.parent_product_id) > 0  THEN 1 ELSE 0 END AS is_aggregated_amount,
	MAX(p_parent.due_type) AS due_type
FROM products_resolved pr
JOIN stock s
	ON pr.sub_product_id = s.product_id
JOIN products p_parent
	ON pr.parent_product_id = p_parent.id
	AND p_parent.active = 1
JOIN products p_sub
	ON pr.sub_product_id = p_sub.id
	AND p_sub.active = 1
LEFT JOIN cache__quantity_unit_conversions_resolved qucr
	ON pr.sub_product_id = qucr.product_id
	AND p_sub.qu_id_stock = qucr.from_qu_id
	AND p_parent.qu_id_stock = qucr.to_qu_id
GROUP BY pr.parent_product_id
HAVING SUM(s.amount) > 0

UNION

-- This is the same as above but sub products not rolled up (no QU conversion and column is_aggregated_amount = 0 here)
SELECT
	pr.sub_product_id AS product_id,
	SUM(s.amount) AS amount,
	SUM(s.amount) AS amount_aggregated,
	CAST(ROUND(CAST(SUM(COALESCE(s.price, 0) * s.amount) AS numeric), 2) AS double precision) AS value,
	MIN(s.best_before_date) AS best_before_date,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.sub_product_id AND open = 1), 0) AS amount_opened,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = pr.sub_product_id AND open = 1), 0) AS amount_opened_aggregated,
	0 AS is_aggregated_amount,
	MAX(p_sub.due_type) AS due_type
FROM products_resolved pr
JOIN stock s
	ON pr.sub_product_id = s.product_id
JOIN products p_sub
	ON pr.sub_product_id = p_sub.id
	AND p_sub.active = 1
WHERE pr.parent_product_id != pr.sub_product_id
GROUP BY pr.sub_product_id
HAVING SUM(s.amount) > 0;

CREATE VIEW products_average_price AS
SELECT
	1 AS id, -- Dummy, LessQL needs an id column
	sl.product_id,
	SUM(COALESCE(sl.edited_origin_amount, sl.amount) * sl.price) / SUM(COALESCE(sl.edited_origin_amount, sl.amount)) as price
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
	AND COALESCE(sl.amount, 0) > 0
GROUP BY sl.product_id;

CREATE VIEW products_current_price AS

/*
	Current price per product,
	based on the stock entry to use next,
	or on the last price if the product is currently not in stock
*/

-- NOTE for reviewers: the SQLite original selects `price` as a bare column alongside
-- `MAX(priority)` in the same GROUP BY product_id query, relying on the documented SQLite
-- behaviour that a bare column takes its value from the row that produced the sole MIN/MAX
-- aggregate in the query (https://www.sqlite.org/lang_select.html#bare_columns_in_an_aggregate_query).
-- PostgreSQL has no equivalent, so this is rewritten as DISTINCT ON, ordered exactly like
-- the original's ORDER BY, to pick the price from the same (highest-priority) row per
-- product_id. `priority` is unique per product_id (it's a per-partition ROW_NUMBER() in
-- stock_next_use), so the additional tie-break columns can never actually be needed, but
-- are kept for fidelity with the original ORDER BY.
SELECT
	-1 AS id, -- Dummy,
	p.id AS product_id,
	COALESCE(snu.price, plp.price) AS price
FROM products p
LEFT JOIN (
	SELECT DISTINCT ON (product_id)
		product_id,
		price
	FROM stock_next_use
	ORDER BY product_id, priority DESC, open DESC, best_before_date ASC, purchased_date ASC
	) snu
	ON p.id = snu.product_id
LEFT JOIN cache__products_last_purchased plp
	ON p.id = plp.product_id;

CREATE VIEW products_price_history AS
SELECT
	sl.product_id AS id, -- Dummy, LessQL needs an id column
	sl.product_id,
	sl.price,
	COALESCE(sl.edited_origin_amount, sl.amount) AS amount,
	sl.purchased_date,
	sl.shopping_location_id,
	sl.transaction_type
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

-- NOTE for reviewers: JULIANDAY(a) - JULIANDAY(b) is translated per the porting rules as
-- EXTRACT(EPOCH FROM (a::timestamp - b::timestamp)) / 86400.0. EXTRACT() itself returns
-- NUMERIC in PostgreSQL (not double precision), and dividing by the numeric literal 86400.0
-- keeps it NUMERIC, so the whole expression is cast to double precision explicitly -
-- otherwise AVG() below would return NUMERIC too, which serialises as a JSON string instead
-- of a number. Also, `CASE WHEN x.product_id IS NULL ...` is rewritten as
-- `CASE WHEN COUNT(x.product_id) = 0 ...`: x.product_id is not the GROUP BY key (p.id is,
-- though the two are always equal via the LEFT JOIN condition) and PostgreSQL's GROUP BY
-- validation does not infer that equality from a join predicate, so the bare reference is
-- replaced with the equivalent aggregate (COUNT ignores the NULLs a non-matching LEFT JOIN
-- produces, so it is 0 exactly when the original bare column would have been NULL).
CREATE VIEW stock_average_product_shelf_life AS
SELECT
	p.id,
	CASE WHEN COUNT(x.product_id) = 0 THEN -1 ELSE AVG(x.shelf_life_days) END AS average_shelf_life_days
FROM products p
LEFT JOIN (
		SELECT
			sl_p.product_id,
			(EXTRACT(EPOCH FROM (sl_p.best_before_date::timestamp - sl_p.purchased_date::timestamp)) / 86400.0)::double precision AS shelf_life_days
		FROM stock_log sl_p
		WHERE sl_p.undone = 0
			AND (
				(sl_p.transaction_type IN ('purchase', 'inventory-correction', 'self-production') AND sl_p.stock_id NOT IN (SELECT stock_id FROM stock_edited_entries))
				OR (sl_p.transaction_type = 'stock-edit-new' AND sl_p.stock_id IN (SELECT stock_id FROM stock_edited_entries))
			)
	) x
	ON p.id = x.product_id
GROUP BY p.id;

CREATE VIEW product_qu_relations AS
-- This view builds which product is related to which QU, direct or indirect, based on QU conversions

-- The products stock QU
SELECT
	-1 AS id, -- Dummy, LessQL needs an id column
	p.id AS product_id,
	p.qu_id_stock AS qu_id
FROM products p

UNION

-- The products purchase QU
SELECT
	-1 AS id, -- Dummy, LessQL needs an id column
	p.id AS product_id,
	p.qu_id_purchase AS qu_id
FROM products p

UNION

-- All (direct) product conversions (product overrides)
SELECT
	-1 AS id, -- Dummy, LessQL needs an id column
	quc.product_id,
	quc.to_qu_id AS qu_id
FROM quantity_unit_conversions quc
WHERE quc.product_id IS NOT NULL

UNION

-- All (indirect) default QU conversions
SELECT
	-1 AS id, -- Dummy, LessQL needs an id column
	p.id AS product_id,
	qur2.qu_id
from products p
JOIN quantity_unit_conversions quc
	ON (p.qu_id_stock = quc.from_qu_id OR p.qu_id_purchase = quc.from_qu_id)
	AND p.id = quc.product_id
JOIN quantity_units_resolved qur1
	ON quc.to_qu_id = qur1.qu_id
JOIN quantity_units_resolved qur2
	ON qur1.related_qu_id = qur2.qu_id;

CREATE VIEW user_permissions_resolved AS
SELECT
	u.id AS id, -- Dummy for LessQL
	u.id AS user_id,
	pt.name AS permission_name
FROM permission_tree pt, users u
WHERE pt.id IN (SELECT permission_id FROM user_permissions sub_up WHERE sub_up.user_id = u.id);
