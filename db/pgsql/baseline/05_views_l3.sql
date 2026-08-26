-- PostgreSQL baseline schema: views (l3)
--
-- Ported from the SQLite view definitions in migrations/*.sql. Column names, column
-- order and aliases are preserved exactly - they are part of the REST API surface.
-- See db/pgsql/baseline/01_tables.sql for the target column types this schema selects
-- from, and the porting rules doc for the general SQLite -> PostgreSQL translation.

-- NOTE for reviewers: JULIANDAY(a) - JULIANDAY('now', 'localtime') is translated per the
-- porting rules as EXTRACT(EPOCH FROM (a::timestamp - date_trunc('second', LOCALTIMESTAMP)))
-- / 86400.0. The result is only ever used inside CASE WHEN conditions here, never projected
-- as a value, so the NUMERIC type EXTRACT(EPOCH ...) produces (Hazard 13) never reaches the
-- output - current_due_status is a plain text result.
CREATE VIEW products_volatile_status AS
SELECT
	-1 AS id, -- Dummy
	p.id AS product_id,
	p.name AS product_name,
	CASE WHEN (EXTRACT(EPOCH FROM (sc.best_before_date::timestamp - date_trunc('second', LOCALTIMESTAMP))) / 86400.0) < 0 THEN
		CASE WHEN p.due_type = 1 THEN 'overdue' ELSE 'expired' END
	ELSE
		CASE WHEN (EXTRACT(EPOCH FROM (sc.best_before_date::timestamp - date_trunc('second', LOCALTIMESTAMP))) / 86400.0) < CAST(grocy_user_setting('stock_due_soon_days') AS INT) THEN
			'due_soon'
		ELSE
			'ok'
		END
	END AS current_due_status,
	CASE WHEN smp.id IS NOT NULL THEN 1 ELSE 0 END AS is_currently_below_min_stock_amount
FROM products p
LEFT JOIN stock_current sc
	ON p.id = sc.product_id
LEFT JOIN stock_missing_products smp
	ON p.id = smp.id;

-- NOTE for reviewers: this is NOT a `SELECT *` over a join (verified empirically against a
-- live SQLite database: `array_keys()` on a fetched row returns exactly the 47 explicitly
-- named columns below, no `:1`-suffixed duplicates). Hazard 14 does not apply - the SQLite
-- original already lists every column explicitly, so this is a plain line-by-line port.
-- Two Hazard 3 fixes are needed though: `product_missing` and `on_shopping_list` are
-- `EXISTS(...)` expressions projected directly into the SELECT list, which SQLite returns
-- as integer 0/1 but PostgreSQL's EXISTS is a genuine boolean - both are wrapped in
-- `CASE WHEN EXISTS(...) THEN 1 ELSE 0 END`.
CREATE VIEW uihelper_stock_current_overview AS
SELECT
	p.id,
	sc.amount_opened AS amount_opened,
	p.tare_weight AS tare_weight,
	p.enable_tare_weight_handling AS enable_tare_weight_handling,
	sc.amount AS amount,
	sc.value as value,
	sc.product_id AS product_id,
	COALESCE(sc.best_before_date, '2888-12-31') AS best_before_date,
	CASE WHEN EXISTS(SELECT id FROM stock_missing_products WHERE id = sc.product_id) THEN 1 ELSE 0 END AS product_missing,
	p.name AS product_name,
	pg.name AS product_group_name,
	sl.name AS default_store_name,
	CASE WHEN EXISTS(SELECT * FROM shopping_list WHERE shopping_list.product_id = sc.product_id) THEN 1 ELSE 0 END AS on_shopping_list,
	qu_stock.name AS qu_stock_name,
	qu_stock.name_plural AS qu_stock_name_plural,
	qu_purchase.name AS qu_purchase_name,
	qu_purchase.name_plural AS qu_purchase_name_plural,
	qu_consume.name AS qu_consume_name,
	qu_consume.name_plural AS qu_consume_name_plural,
	qu_price.name AS qu_price_name,
	qu_price.name_plural AS qu_price_name_plural,
	sc.is_aggregated_amount,
	sc.amount_opened_aggregated,
	sc.amount_aggregated,
	p.calories AS product_calories,
	sc.amount * p.calories AS calories,
	sc.amount_aggregated * p.calories AS calories_aggregated,
	p.quick_consume_amount,
	p.quick_consume_amount / p.qu_factor_consume_to_stock AS quick_consume_amount_qu_consume,
	p.quick_open_amount,
	p.quick_open_amount / p.qu_factor_consume_to_stock AS quick_open_amount_qu_consume,
	p.due_type,
	plp.purchased_date AS last_purchased,
	plp.price AS last_price,
	pap.price as average_price,
	p.min_stock_amount,
	pbcs.barcodes AS product_barcodes,
	p.description AS product_description,
	l.name AS product_default_location_name,
	p_parent.id AS parent_product_id,
	p_parent.name AS parent_product_name,
	p.picture_file_name AS product_picture_file_name,
	p.no_own_stock AS product_no_own_stock,
	p.qu_factor_purchase_to_stock AS product_qu_factor_purchase_to_stock,
	p.qu_factor_price_to_stock AS product_qu_factor_price_to_stock,
	sc.is_in_stock_or_below_min_stock,
	p.disable_open
FROM (
	SELECT *, 1 AS is_in_stock_or_below_min_stock
	FROM stock_current
	WHERE best_before_date IS NOT NULL
	UNION
	SELECT m.id, 0, 0, 0, NULL::date, 0, 0, 0, p.due_type, 1 AS is_in_stock_or_below_min_stock
	FROM stock_missing_products m
	JOIN products p
		ON m.id = p.id
	WHERE m.id NOT IN (SELECT product_id FROM stock_current)
	UNION
	SELECT p2.id, 0, 0, 0, NULL::date, 0, 0, 0, p2.due_type, 0 AS is_in_stock_or_below_min_stock
	FROM products p2
	WHERE active = 1
		AND p2.id NOT IN (SELECT product_id FROM stock_current UNION SELECT id FROM stock_missing_products)
	) sc
JOIN products_view p
    ON sc.product_id = p.id
JOIN locations l
	ON p.location_id = l.id
JOIN quantity_units qu_stock
	ON p.qu_id_stock = qu_stock.id
JOIN quantity_units qu_purchase
	ON p.qu_id_purchase = qu_purchase.id
JOIN quantity_units qu_consume
	ON p.qu_id_consume = qu_consume.id
JOIN quantity_units qu_price
	ON p.qu_id_price = qu_price.id
LEFT JOIN product_groups pg
	ON p.product_group_id = pg.id
LEFT JOIN shopping_locations sl
	ON p.shopping_location_id = sl.id
LEFT JOIN cache__products_last_purchased plp
	ON sc.product_id = plp.product_id
LEFT JOIN cache__products_average_price pap
	ON sc.product_id = pap.product_id
LEFT JOIN product_barcodes_comma_separated pbcs
	ON sc.product_id = pbcs.product_id
LEFT JOIN products p_parent
	ON p.parent_product_id = p_parent.id
WHERE p.hide_on_stock_overview = 0;

CREATE VIEW recipes_pos_resolved AS

-- Multiplication by 1.0 to force conversion to float (REAL)

-- Resolved amount (here used multiple times):
-- CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END

-- NOTE for reviewers, two translations beyond the mechanical IFNULL -> COALESCE:
--   1. `cache__quantity_unit_conversions_resolved.factor` is TEXT upstream (see the note on
--      products_view in 03_views_group2.sql). `IFNULL(qucr.factor, 1.0)` therefore becomes
--      `COALESCE(qucr.factor::double precision, 1.0::double precision)` everywhere it
--      appears - without the cast, COALESCE(text, numeric) is a hard error in PostgreSQL
--      ("COALESCE types text and numeric cannot be matched"), not merely a type change.
--   2. `ROUND(<double expr>, 2)` (2-argument form) does not exist for double precision in
--      PostgreSQL, only round(numeric, integer) does - see the porting rules doc and the
--      identical fix already applied in stock_current/stock_current_location_content. Both
--      ROUND() calls in need_fulfilled_with_shopping_list are rewritten as
--      ROUND(CAST(<expr> AS numeric), 2); the NUMERIC result is only ever compared, never
--      projected (the projected value is the CASE's integer 1/0), so no cast back to double
--      precision is needed here.
SELECT
	r.id AS recipe_id,
	rp.id AS recipe_pos_id,
	rp.product_id AS product_id,
	CASE WHEN rp.round_up = 1 THEN CEIL(CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END) ELSE CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END END AS recipe_amount,
	COALESCE(sc.amount_aggregated, 0) AS stock_amount,
	CASE WHEN COALESCE(sc.amount_aggregated, 0) >= CASE WHEN rp.only_check_single_unit_in_stock = 1 THEN 0.00000001 ELSE CASE WHEN rp.round_up = 1 THEN CEIL(CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END) ELSE CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END END END THEN 1 ELSE 0 END AS need_fulfilled,
	CASE WHEN COALESCE(sc.amount_aggregated, 0) - CASE WHEN rp.only_check_single_unit_in_stock = 1 THEN 0.00000001 ELSE CASE WHEN rp.round_up = 1 THEN CEIL(CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END) ELSE CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END END END < 0 THEN ABS(COALESCE(sc.amount_aggregated, 0) - (CASE WHEN rp.round_up = 1 THEN CEIL(CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END) ELSE CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END END)) ELSE 0 END AS missing_amount,
	COALESCE(sl.amount, 0) AS amount_on_shopping_list,
	CASE WHEN ROUND(CAST(COALESCE(sc.amount_aggregated, 0) + CASE WHEN r.not_check_shoppinglist = 1 THEN 0 ELSE COALESCE(sl.amount, 0) END AS numeric), 2) >= ROUND(CAST(CASE WHEN rp.only_check_single_unit_in_stock = 1 THEN 0.00000001 ELSE CASE WHEN rp.round_up = 1 THEN CEIL(CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END) ELSE CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END END END AS numeric), 2) THEN 1 ELSE 0 END AS need_fulfilled_with_shopping_list,
	rp.qu_id,
	(r.desired_servings*1.0 / r.base_servings*1.0) * CASE WHEN rp.only_check_single_unit_in_stock = 1 THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1 END * (rnr.includes_servings*1.0 / CASE WHEN rnr.recipe_id != rnr.includes_recipe_id THEN rnrr.base_servings*1.0 ELSE 1 END) * rp.amount * COALESCE(pcp.price, 0) * rp.price_factor * CASE WHEN rp.product_id != p_effective.id THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1.0 END AS costs,
	CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN 0 ELSE 1 END AS is_nested_recipe_pos,
	rp.ingredient_group,
	pg.name as product_group,
	rp.id, -- Just a dummy id column
	r.type as recipe_type,
	rnr.includes_recipe_id as child_recipe_id,
	rp.note,
	rp.variable_amount AS recipe_variable_amount,
	rp.only_check_single_unit_in_stock,
	rp.amount * CASE WHEN rp.only_check_single_unit_in_stock = 1 THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1 END / r.base_servings*1.0 * (rnr.includes_servings*1.0 / CASE WHEN rnr.recipe_id != rnr.includes_recipe_id THEN rnrr.base_servings*1.0 ELSE 1 END) * COALESCE(p_effective.calories, 0) * CASE WHEN rp.product_id != p_effective.id THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1.0 END AS calories,
	p.active AS product_active,
	CASE pvs.current_due_status
		WHEN 'ok' THEN 0
		WHEN 'due_soon' THEN 1
		WHEN 'overdue' THEN 10
		WHEN 'expired' THEN 20
	END AS due_score,
	COALESCE(pcs.product_id_effective, rp.product_id) AS product_id_effective,
	p.name AS product_name
FROM recipes r
JOIN recipes_nestings_resolved rnr
	ON r.id = rnr.recipe_id
JOIN recipes rnrr
	ON rnr.includes_recipe_id = rnrr.id
JOIN recipes_pos rp
	ON rnr.includes_recipe_id = rp.recipe_id
JOIN products p
	ON rp.product_id = p.id
JOIN products_volatile_status pvs
	ON rp.product_id = pvs.product_id
LEFT JOIN product_groups pg
	ON p.product_group_id = pg.id
LEFT JOIN (
	SELECT product_id, SUM(amount) AS amount
	FROM shopping_list
	GROUP BY product_id) sl
	ON rp.product_id = sl.product_id
LEFT JOIN stock_current sc
	ON rp.product_id = sc.product_id
LEFT JOIN products_current_substitutions pcs
	ON rp.product_id = pcs.parent_product_id
LEFT JOIN products_current_price pcp
	ON COALESCE(pcs.product_id_effective, rp.product_id) = pcp.product_id
LEFT JOIN products p_effective
	ON COALESCE(pcs.product_id_effective, rp.product_id) = p_effective.id
LEFT JOIN cache__quantity_unit_conversions_resolved qucr
	ON COALESCE(pcs.product_id_effective, rp.product_id) = qucr.product_id
	AND CASE WHEN rp.product_id != p_effective.id THEN p.qu_id_stock ELSE rp.qu_id END = qucr.from_qu_id
	AND COALESCE(p_effective.qu_id_stock, p.qu_id_stock) = qucr.to_qu_id
WHERE rp.not_check_stock_fulfillment = 0

UNION

-- Just add all recipe positions which should not be checked against stock with fulfilled need

SELECT
	r.id AS recipe_id,
	rp.id AS recipe_pos_id,
	rp.product_id AS product_id,
	CASE WHEN rp.round_up = 1 THEN CEIL(CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END) ELSE CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) ELSE rp.amount * ((r.desired_servings*1.0) / (r.base_servings*1.0)) * ((rnr.includes_servings*1.0) / (rnrr.base_servings*1.0)) END END AS recipe_amount,
	COALESCE(sc.amount_aggregated, 0) AS stock_amount,
	1 AS need_fulfilled,
	0 AS missing_amount,
	COALESCE(sl.amount, 0) AS amount_on_shopping_list,
	1 AS need_fulfilled_with_shopping_list,
	rp.qu_id,
	(r.desired_servings*1.0 / r.base_servings*1.0) * CASE WHEN rp.only_check_single_unit_in_stock = 1 THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1 END * (rnr.includes_servings*1.0 / CASE WHEN rnr.recipe_id != rnr.includes_recipe_id THEN rnrr.base_servings*1.0 ELSE 1 END) * rp.amount * COALESCE(pcp.price, 0) * rp.price_factor * CASE WHEN rp.product_id != p_effective.id THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1.0 END AS costs,
	CASE WHEN rnr.recipe_id = rnr.includes_recipe_id THEN 0 ELSE 1 END AS is_nested_recipe_pos,
	rp.ingredient_group,
	pg.name as product_group,
	rp.id, -- Just a dummy id column
	r.type as recipe_type,
	rnr.includes_recipe_id as child_recipe_id,
	rp.note,
	rp.variable_amount AS recipe_variable_amount,
	rp.only_check_single_unit_in_stock,
	rp.amount * CASE WHEN rp.only_check_single_unit_in_stock = 1 THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1 END / r.base_servings*1.0 * (rnr.includes_servings*1.0 / CASE WHEN rnr.recipe_id != rnr.includes_recipe_id THEN rnrr.base_servings*1.0 ELSE 1 END) * COALESCE(p_effective.calories, 0) * CASE WHEN rp.product_id != p_effective.id THEN COALESCE(qucr.factor::double precision, 1.0::double precision) ELSE 1.0 END AS calories,
	p.active AS product_active,
	CASE pvs.current_due_status
		WHEN 'ok' THEN 0
		WHEN 'due_soon' THEN 1
		WHEN 'overdue' THEN 10
		WHEN 'expired' THEN 20
	END AS due_score,
	COALESCE(pcs.product_id_effective, rp.product_id) AS product_id_effective,
	p.name AS product_name
FROM recipes r
JOIN recipes_nestings_resolved rnr
	ON r.id = rnr.recipe_id
JOIN recipes rnrr
	ON rnr.includes_recipe_id = rnrr.id
JOIN recipes_pos rp
	ON rnr.includes_recipe_id = rp.recipe_id
JOIN products p
	ON rp.product_id = p.id
JOIN products_volatile_status pvs
	ON rp.product_id = pvs.product_id
LEFT JOIN product_groups pg
	ON p.product_group_id = pg.id
LEFT JOIN (
	SELECT product_id, SUM(amount) AS amount
	FROM shopping_list
	GROUP BY product_id) sl
	ON rp.product_id = sl.product_id
LEFT JOIN stock_current sc
	ON rp.product_id = sc.product_id
LEFT JOIN products_current_substitutions pcs
	ON rp.product_id = pcs.parent_product_id
LEFT JOIN products_current_price pcp
	ON COALESCE(pcs.product_id_effective, rp.product_id) = pcp.product_id
LEFT JOIN products p_effective
	ON COALESCE(pcs.product_id_effective, rp.product_id) = p_effective.id
LEFT JOIN cache__quantity_unit_conversions_resolved qucr
	ON COALESCE(pcs.product_id_effective, rp.product_id) = qucr.product_id
	AND CASE WHEN rp.product_id != p_effective.id THEN p.qu_id_stock ELSE rp.qu_id END = qucr.from_qu_id
	AND COALESCE(p_effective.qu_id_stock, p.qu_id_stock) = qucr.to_qu_id
WHERE rp.not_check_stock_fulfillment = 1;

-- NOTE for reviewers: COUNT(*) is PostgreSQL BIGINT, not INTEGER like in SQLite (Hazard 8).
-- Cast back to INTEGER so missing_products_count keeps serialising as a plain JSON integer.
CREATE VIEW recipes_missing_product_counts AS
SELECT
	recipe_id,
	CAST(COUNT(*) AS INTEGER) AS missing_products_count
FROM recipes_pos_resolved
WHERE need_fulfilled = 0
GROUP BY recipe_id;

-- NOTE for reviewers: two Hazard 6/8 fixes beyond the mechanical IFNULL -> COALESCE and
-- GROUP_CONCAT -> string_agg:
--   1. `rmpc.missing_products_count` is referenced ungrouped/unaggregated (inside a
--      COALESCE) while the GROUP BY key is r.id. It IS single-valued per r.id (
--      recipes_missing_product_counts is itself grouped by recipe_id), but PostgreSQL only
--      infers that kind of functional dependency for a *table's own* declared primary key,
--      not for a column reached through a join to another view - so, per Hazard 6, it is
--      added to GROUP BY outright (safe: since it is already unique per r.id, this cannot
--      split any group into more rows, only satisfies the syntax check).
--   2. `SUM(rpr.due_score)` returns BIGINT (Hazard 8), cast back to INTEGER. `SUM(rpr.costs)`
--      and `SUM(rpr.calories)` need no such cast - SUM(double precision) stays double
--      precision in PostgreSQL, unlike SUM(integer).
CREATE VIEW recipes_resolved AS
SELECT
	1 AS id, -- Dummy, LessQL needs an id column
	r.id AS recipe_id,
	COALESCE(MIN(rpr.need_fulfilled), 1) AS need_fulfilled,
	COALESCE(MIN(rpr.need_fulfilled_with_shopping_list), 1) AS need_fulfilled_with_shopping_list,
	COALESCE(rmpc.missing_products_count, 0) AS missing_products_count,
	COALESCE(SUM(rpr.costs), 0) AS costs,
	COALESCE(SUM(rpr.costs) / CASE WHEN COALESCE(r.desired_servings, 0) = 0 THEN 1 ELSE r.desired_servings END, 0) AS costs_per_serving,
	COALESCE(SUM(rpr.calories), 0) AS calories,
	COALESCE(CAST(SUM(rpr.due_score) AS INTEGER), 0) AS due_score,
	string_agg(rpr.product_name, ',') AS product_names_comma_separated,
	CASE WHEN MIN(COALESCE(rpr.costs, 0)) = 0 THEN 1 ELSE 0 END AS prices_incomplete
FROM recipes r
LEFT JOIN recipes_pos_resolved rpr
	ON r.id = rpr.recipe_id
LEFT JOIN recipes_missing_product_counts rmpc
	ON r.id = rmpc.recipe_id
GROUP BY r.id, rmpc.missing_products_count;
