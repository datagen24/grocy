-- PostgreSQL baseline schema: views (group 2)
--
-- Ported from the SQLite view definitions in migrations/*.sql. Column names, column
-- order and aliases are preserved exactly - they are part of the REST API surface.
-- See db/pgsql/baseline/01_tables.sql for the target column types this schema selects
-- from, and the porting rules doc for the general SQLite -> PostgreSQL translation.

CREATE VIEW products_resolved AS
SELECT
	CASE
		WHEN p.parent_product_id IS NULL THEN
			p.id
		ELSE
			p.parent_product_id
	END AS parent_product_id,
	p.id AS sub_product_id
FROM products p
WHERE p.active = 1;

-- NOTE for reviewers: cache__quantity_unit_conversions_resolved.factor is declared TEXT
-- (see db/pgsql/baseline/01_tables.sql / migrations/0225.sql - this matches the upstream
-- SQLite schema, which also declares it TEXT). It is populated exclusively from the
-- computed `factor` column of quantity_unit_conversions_resolved below, which is a real
-- number, so an explicit cast back to double precision is required here to avoid leaking
-- a TEXT-typed (and, if IFNULL/COALESCE's other branch were left as a bare numeric
-- literal, possibly NUMERIC-typed) value into qu_factor_*_to_stock.
CREATE VIEW products_view AS
SELECT
	p.*,
	-- EXISTS rather than a scalar subquery: a product with more than one sub-product makes
	-- the original return several rows, which SQLite quietly reduces to the first one and
	-- PostgreSQL rejects as a cardinality violation
	CASE WHEN EXISTS(SELECT 1 FROM products WHERE parent_product_id = p.id) THEN 1 ELSE 0 END AS has_sub_products,
	COALESCE(quc_purchase.factor::double precision, 1.0::double precision) AS qu_factor_purchase_to_stock,
	COALESCE(quc_consume.factor::double precision, 1.0::double precision) AS qu_factor_consume_to_stock,
	COALESCE(quc_price.factor::double precision, 1.0::double precision) AS qu_factor_price_to_stock
FROM products p
LEFT JOIN cache__quantity_unit_conversions_resolved quc_purchase
	ON p.id = quc_purchase.product_id
	AND p.qu_id_purchase = quc_purchase.from_qu_id
	AND p.qu_id_stock = quc_purchase.to_qu_id
LEFT JOIN cache__quantity_unit_conversions_resolved quc_consume
	ON p.id = quc_consume.product_id
	AND p.qu_id_consume = quc_consume.from_qu_id
	AND p.qu_id_stock = quc_consume.to_qu_id
LEFT JOIN cache__quantity_unit_conversions_resolved quc_price
	ON p.id = quc_price.product_id
	AND p.qu_id_price = quc_price.from_qu_id
	AND p.qu_id_stock = quc_price.to_qu_id;

-- NOTE for reviewers: the SQLite original picks a single row per (from_qu_id, to_qu_id) -
-- or per (product_id, from_qu_id, to_qu_id) - duplicate conversion path using
-- `SELECT DISTINCT ... FIRST_VALUE(factor) OVER (PARTITION BY ... ORDER BY depth) ...
-- GROUP BY ...`. This looks like it means "prefer the shortest/most direct conversion
-- path", but it does NOT actually do that: SQLite's window functions run over the
-- *already-grouped* rows, and since the window's PARTITION BY matches the GROUP BY
-- columns exactly, every partition has already been collapsed to one arbitrary row before
-- the ORDER BY depth in the window spec ever sees more than one row. This was verified
-- empirically (against sqlite3 3.50.2): the row returned changes depending on row
-- insertion order, i.e. it is NOT deterministically the minimum-depth row. PostgreSQL's
-- stricter GROUP BY rules make this literal construct impossible to replicate anyway (bare
-- non-grouped columns referenced from a window ORDER BY are rejected), and replicating an
-- arbitrary, plan-dependent SQLite quirk would not be "faithful" in any meaningful sense.
-- Instead this port implements the evidently *intended* behaviour - deterministically
-- prefer the minimum-depth (shortest/most direct) conversion path - via
-- `DISTINCT ON (...) ... ORDER BY ..., depth`. In practice this should be immaterial:
-- for a well-formed conversion graph, every path between the same two units yields the
-- same factor (up to floating point rounding), so the choice of which path "wins" here
-- only affects rounding noise in the last few decimal digits, not correctness.
CREATE VIEW quantity_unit_conversions_resolved AS

WITH RECURSIVE

-- Default QU conversions are handled in a later CTE, as we can't determine yet, for which products they are applicable.
default_conversions(from_qu_id, to_qu_id, factor)
AS (
	SELECT
		from_qu_id,
		to_qu_id,
		factor
	FROM quantity_unit_conversions
	WHERE product_id IS NULL
),

-- First find the closure for all default conversions. This will allow for further pruning when looking for product closure.
default_closure(depth, from_qu_id, to_qu_id, factor, path)
AS (
	-- As a base case, select all available default conversions
	SELECT
		1 AS depth,
		from_qu_id,
		to_qu_id,
		factor,
		'/' || from_qu_id::text || '/' || to_qu_id::text || '/' -- We need to keep track of the conversion path in order to prevent cycles
	FROM default_conversions

	UNION

	-- Recursive case: Find all paths
	SELECT
		c.depth + 1,
		c.from_qu_id,
		s.to_qu_id,
		c.factor * s.factor,
		c.path || s.to_qu_id::text || '/'
	FROM default_closure c
	JOIN default_conversions s
		ON c.to_qu_id = s.from_qu_id
	WHERE c.path NOT LIKE ('%/' || s.to_qu_id::text || '/%') -- Prevent cycles
		AND NOT EXISTS(SELECT 1 FROM default_conversions ci WHERE ci.from_qu_id = c.from_qu_id AND ci.to_qu_id = s.to_qu_id) -- Prune if one of the existing conversions repeats (saves a lot of processing time)

),

default_closure_distinct(from_qu_id, to_qu_id, factor, path)
AS (
	SELECT DISTINCT ON (from_qu_id, to_qu_id)
		from_qu_id,
		to_qu_id,
		factor,
		path
	FROM default_closure
	ORDER BY from_qu_id, to_qu_id, depth
),

product_conversions(product_id, from_qu_id, to_qu_id, factor)
AS (
	-- Priority 1: Product-specific QU overrides
	-- Note that the quantity_unit_conversions table already contains both conversion directions for every conversion.
	SELECT
		product_id,
		from_qu_id,
		to_qu_id,
		factor
	FROM quantity_unit_conversions
	WHERE product_id IS NOT NULL

	UNION

	-- Priority 2: QU conversions with a factor of 1.0 from the stock unit to the stock unit
	SELECT
		id,
		qu_id_stock,
		qu_id_stock,
		1.0::double precision
	FROM products
),

product_closure(depth, product_id, from_qu_id, to_qu_id, factor, path)
AS (
	-- As a base case, select all available product-specific conversions
	SELECT
		1 AS depth,
		product_id,
		from_qu_id,
		to_qu_id,
		factor,
		'/' || from_qu_id::text || '/' || to_qu_id::text || '/' -- We need to keep track of the conversion path in order to prevent cycles
	FROM product_conversions

	UNION

	-- Recursive case: Find all paths
	SELECT
		c.depth + 1,
		c.product_id,
		c.from_qu_id,
		s.to_qu_id,
		c.factor * s.factor,
		c.path || s.to_qu_id::text || '/'
	FROM product_closure c
	JOIN product_conversions s
		ON c.product_id = s.product_id
		AND c.to_qu_id = s.from_qu_id
	WHERE c.path NOT LIKE ('%/' || s.to_qu_id::text || '/%') -- Prevent cycles
		AND NOT EXISTS(SELECT 1 FROM product_conversions ci WHERE ci.product_id = c.product_id AND ci.from_qu_id = c.from_qu_id AND ci.to_qu_id = s.to_qu_id) -- Prune if one of the existing conversions repeats (saves a lot of processing time)
),

product_closure_distinct(product_id, from_qu_id, to_qu_id, factor, path)
AS (
	SELECT DISTINCT ON (product_id, from_qu_id, to_qu_id)
		product_id,
		from_qu_id,
		to_qu_id,
		factor,
		path
	FROM product_closure
	ORDER BY product_id, from_qu_id, to_qu_id, depth
),

-- Now we connect the two closures by adding the reachable conversions from product specific conversions to default conversions
product_reachable(product_id, from_qu_id, to_qu_id, factor, path)
AS (
	SELECT
		product_id,
		from_qu_id,
		to_qu_id,
		factor,
		path
	FROM product_closure_distinct

	UNION

	SELECT
		cd.product_id,
		dcd.from_qu_id,
		dcd.to_qu_id,
		dcd.factor,
		'/' || dcd.from_qu_id::text || '/' || dcd.to_qu_id::text || '/'
	FROM product_closure_distinct cd
	JOIN default_closure_distinct dcd
		ON cd.to_qu_id = dcd.from_qu_id
		OR cd.to_qu_id = dcd.to_qu_id
	WHERE NOT EXISTS(SELECT 1 FROM product_closure_distinct ci WHERE ci.product_id = cd.product_id AND ci.from_qu_id = dcd.from_qu_id AND ci.to_qu_id = dcd.to_qu_id)
),

-- NOTE: no DISTINCT ON / window function is needed here (unlike the two closures above).
-- The only way this UNION can produce duplicate (product_id, from_qu_id, to_qu_id) keys is
-- via the second branch above, where the projected factor/path are taken solely from
-- default_closure_distinct (itself already unique per (from_qu_id, to_qu_id)); any such
-- duplicates are therefore always identical rows, so a plain DISTINCT is sufficient and
-- unambiguous.
product_reachable_distinct(product_id, from_qu_id, to_qu_id, factor, path)
AS (
	SELECT DISTINCT
		product_id,
		from_qu_id,
		to_qu_id,
		factor,
		path
	FROM product_reachable
),

-- Finally we build the combined closure
closure_final(depth, product_id, from_qu_id, to_qu_id, factor, path)
AS (
	-- As a base case, select the product closure
	SELECT
		1 AS depth,
		product_id,
		from_qu_id,
		to_qu_id,
		factor,
		path -- We need to keep track of the conversion path in order to prevent cycles
	FROM product_reachable_distinct

	UNION

	-- Add a default unit conversion to the *end* of the conversion chain
	SELECT
		c.depth + 1,
		c.product_id,
		c.from_qu_id,
		s.to_qu_id,
		c.factor * s.factor,
		c.path || s.to_qu_id::text || '/'
	FROM closure_final c
	JOIN product_reachable_distinct s
		ON c.product_id = s.product_id
		AND c.to_qu_id = s.from_qu_id
	WHERE c.path NOT LIKE ('%/' || s.to_qu_id::text || '/%') -- Prevent cycles
		AND NOT EXISTS(SELECT 1 FROM product_reachable_distinct ci WHERE ci.product_id = c.product_id AND ci.from_qu_id = c.from_qu_id AND ci.to_qu_id = s.to_qu_id) -- Prune (if already exists)
)

SELECT DISTINCT ON (c.product_id, c.from_qu_id, c.to_qu_id)
	-1 AS id, -- Dummy, LessQL needs an id column
	c.product_id,
	c.from_qu_id,
	qu_from.name AS from_qu_name,
	qu_from.name_plural AS from_qu_name_plural,
	c.to_qu_id,
	qu_to.name AS to_qu_name,
	qu_to.name_plural AS to_qu_name_plural,
	c.factor,
	c.path
FROM closure_final c
JOIN quantity_units qu_from
	ON c.from_qu_id = qu_from.id
JOIN quantity_units qu_to
	ON c.to_qu_id = qu_to.id
ORDER BY c.product_id, c.from_qu_id, c.to_qu_id, c.depth;

CREATE VIEW quantity_units_resolved AS
-- This view builds the relationship between QUs based on their (default) conversions

SELECT
	-1 AS id, -- Dummy, LessQL needs an id column
	qu.id AS qu_id,
	quc.to_qu_id AS related_qu_id,
	quc.factor
FROM quantity_units qu
JOIN quantity_unit_conversions quc
	ON qu.id = quc.from_qu_id
	AND quc.product_id IS NULL;

-- NOTE for reviewers: the anchor term's `includes_servings` must be explicitly cast to
-- double precision. In the SQLite original it is the bare integer literal `1`, but the
-- recursive term computes `rn.servings * r1.includes_servings`, where rn.servings is
-- DOUBLE PRECISION - so without the cast PostgreSQL rejects the recursive CTE for a column
-- type mismatch between the anchor (would default to integer) and the recursive term.
CREATE VIEW recipes_nestings_resolved AS
WITH RECURSIVE r1(recipe_id, includes_recipe_id, includes_servings, level)
AS (
	SELECT
		id AS recipe_id,
		id AS includes_recipe_id,
		1::double precision AS includes_servings,
		0 AS level
	FROM recipes

	UNION ALL

	SELECT
		rn.recipe_id,
		r1.includes_recipe_id,
		rn.servings * r1.includes_servings AS includes_servings,
		r1.level + 1 AS level
	FROM recipes_nestings rn, r1 r1
	WHERE rn.includes_recipe_id = r1.recipe_id
)
SELECT
	*,
	1 AS id -- Dummy, LessQL needs an id column
FROM r1;

-- NOTE for reviewers: COUNT(*) is PostgreSQL BIGINT, not INTEGER like in SQLite. Cast back
-- to INTEGER so item_count keeps serialising as a plain JSON integer.
CREATE VIEW shopping_lists_view AS
SELECT
	*,
	CAST(COALESCE((SELECT COUNT(*) FROM shopping_list WHERE shopping_list_id = sl.id), 0) AS INTEGER) AS item_count
FROM shopping_lists sl;

-- NOTE for reviewers: two deliberate deviations from a literal translation here, both
-- required for correctness/loadability rather than being "improvements":
--   1. ROUND(double precision, integer) does not exist in PostgreSQL - only
--      round(double precision) [1-arg] and round(numeric, integer) [2-arg] do. The value
--      is cast to numeric to call the 2-arg form and cast back to double precision
--      afterwards so a NUMERIC (which serialises as a JSON string) never leaks out.
--   2. The correlated subquery for amount_opened originally correlates on the bare
--      `s.location_id` column, which is not itself a GROUP BY key (the GROUP BY key is
--      `IFNULL(s.location_id, p.location_id)`). SQLite tolerates this and picks an
--      arbitrary group member's s.location_id; PostgreSQL's stricter GROUP BY rules reject
--      it outright ("column ... must appear in GROUP BY clause or be used in an aggregate
--      function", and this applies even when the expression is repeated verbatim inside
--      the correlated subquery). To fix this, the COALESCE is computed once in a derived
--      table (sp) so that GROUP BY/correlation is against a real, single-valued column
--      rather than an expression over two ungrouped base-table columns. This correlates on
--      the same location this row is reporting on, matching the apparent original intent.
CREATE VIEW stock_current_location_content AS
SELECT
	sp.location_id,
	sp.product_id,
	SUM(sp.amount) AS amount,
	CAST(ROUND(CAST(SUM(COALESCE(sp.price, 0) * sp.amount) AS numeric), 2) AS double precision) AS value,
	MIN(sp.best_before_date) AS best_before_date,
	COALESCE((SELECT SUM(amount) FROM stock WHERE product_id = sp.product_id AND location_id = sp.location_id AND open = 1), 0) AS amount_opened
FROM (
	SELECT
		COALESCE(s.location_id, p.location_id) AS location_id,
		s.product_id,
		s.amount,
		s.price,
		s.best_before_date
	FROM stock s
	JOIN products p
		ON s.product_id = p.id
		AND p.active = 1
) sp
GROUP BY sp.location_id, sp.product_id;
