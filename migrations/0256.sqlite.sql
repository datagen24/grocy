-- Make products_view return qu_factor_* as a number on SQLite, as it already does on
-- PostgreSQL.
--
-- cache__quantity_unit_conversions_resolved.factor is declared TEXT upstream, so SQLite
-- hands the value back as the JSON string "1.0" where PostgreSQL returns the number 1.
-- The difference reaches uihelper_stock_entries and uihelper_stock_current_overview too,
-- both of which take these columns straight from products_view.
--
-- PostgreSQL is the conforming side: grocy.openapi.json documents this field as
-- "type: number", and uihelper_product_details has always wrapped the same expression in
-- CAST(... AS REAL) — so the cast below is applying an existing convention to the view
-- that was missed, not inventing one.
--
-- @engine-exclusive
-- SQLite only, deliberately, because PostgreSQL is already correct: its baseline defines
-- these columns as COALESCE(quc_purchase.factor::double precision, 1.0::double precision).
-- Adding a no-op pgsql migration to preserve numbering symmetry would suggest a change
-- that is not happening. See the "documented engine-exclusive" case in
-- docs/plans/README.md and the accepted-differences section of db/pgsql/README.md, which
-- this migration removes an entry from.

DROP VIEW products_view;
CREATE VIEW products_view
AS
SELECT
	p.*,
	CASE WHEN (SELECT 1 FROM products WHERE parent_product_id = p.id) NOTNULL THEN 1 ELSE 0 END AS has_sub_products,
	CAST(IFNULL(quc_purchase.factor, 1.0) AS REAL) AS qu_factor_purchase_to_stock,
	CAST(IFNULL(quc_consume.factor, 1.0) AS REAL) AS qu_factor_consume_to_stock,
	CAST(IFNULL(quc_price.factor, 1.0) AS REAL) AS qu_factor_price_to_stock
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
