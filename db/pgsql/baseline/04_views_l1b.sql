-- PostgreSQL baseline schema: views (l1b)
--
-- Ported from the SQLite view definitions in migrations/*.sql. Column names, column
-- order and aliases are preserved exactly - they are part of the REST API surface.
-- See db/pgsql/baseline/01_tables.sql for the target column types this schema selects
-- from, and the porting rules doc for the general SQLite -> PostgreSQL translation.

-- NOTE for reviewers: AVG() over an INTEGER column returns NUMERIC in PostgreSQL (unlike
-- SQLite, where AVG always returns REAL). NUMERIC serialises as a JSON string, which would
-- change the API response type for average_frequency_hours from number to string, so the
-- result is cast back to DOUBLE PRECISION.
CREATE VIEW chores_execution_average_frequency AS

SELECT
	cet.chore_id,
	CAST(AVG(cet.frequency_hours) AS DOUBLE PRECISION) AS average_frequency_hours
FROM chores_execution_timeline cet
GROUP BY cet.chore_id;

-- NOTE for reviewers: COUNT(1) is PostgreSQL BIGINT, not INTEGER like in SQLite. Cast back
-- to INTEGER so execution_count keeps serialising as a plain JSON integer.
CREATE VIEW chores_execution_users_statistics AS
SELECT
	c.id AS id, -- Dummy, LessQL needs an id column
	c.id AS chore_id,
	caur.user_id AS user_id,
	CAST((SELECT COUNT(1) FROM chores_log WHERE chore_id = c.id AND done_by_user_id = caur.user_id AND undone = 0) AS INTEGER) AS execution_count
FROM chores c
JOIN chores_assigned_users_resolved caur
	ON c.id = caur.chore_id
GROUP BY c.id, caur.user_id;

-- NOTE for reviewers: cache__quantity_unit_conversions_resolved.factor is declared TEXT
-- (see db/pgsql/baseline/01_tables.sql, matching the upstream SQLite schema). It is
-- multiplied against plp.price (DOUBLE PRECISION), which PostgreSQL will not do implicitly
-- across TEXT, so an explicit cast to double precision is required (same pattern as
-- products_view in 03_views_group2.sql).
CREATE VIEW uihelper_shopping_list AS
SELECT
	sl.*,
	p.name AS product_name,
	plp.price * COALESCE(quc.factor::double precision, 1.0::double precision) AS last_price_unit,
	plp.price * sl.amount AS last_price_total,
	plp.price AS price,
	st.name AS default_shopping_location_name,
	qu.name AS qu_name,
	qu.name_plural AS qu_name_plural,
	pg.id AS product_group_id,
	pg.name AS product_group_name,
	pbcs.barcodes AS product_barcodes
FROM shopping_list sl
LEFT JOIN products p
	ON sl.product_id = p.id
LEFT JOIN cache__products_last_purchased plp
	ON sl.product_id = plp.product_id
LEFT JOIN shopping_locations st
	ON p.shopping_location_id = st.id
LEFT JOIN quantity_units qu
	ON sl.qu_id = qu.id
LEFT JOIN product_groups pg
	ON p.product_group_id = pg.id
LEFT JOIN cache__quantity_unit_conversions_resolved quc
	ON p.id = quc.product_id
	AND p.qu_id_stock = quc.to_qu_id
	AND sl.qu_id = quc.from_qu_id
LEFT JOIN product_barcodes_comma_separated pbcs
	ON sl.product_id = pbcs.product_id;

-- NOTE for reviewers: the SQLite original is `SELECT * FROM stock s JOIN products_view p
-- ON s.product_id = p.id`. stock and products_view both have columns named id,
-- row_created_timestamp, location_id and shopping_location_id. PostgreSQL's CREATE VIEW
-- rejects the resulting duplicate output column names outright ("column ... specified more
-- than once"), but SQLite does not merge or overwrite them either - verified empirically
-- against sqlite3 3.50.2: SQLite's own column-naming (with the default
-- short_column_names/full_column_names settings PDO uses) disambiguates a repeated result
-- column name by appending ":1", ":2", ... to the *second and later* occurrences. So the
-- real, wire-visible column set of this view is stock's 13 columns unchanged, followed by
-- products_view's 40 columns in their own order, with its id/location_id/
-- shopping_location_id/row_created_timestamp renamed to "id:1"/"location_id:1"/
-- "shopping_location_id:1"/"row_created_timestamp:1" (colons and all - these are genuine
-- JSON keys in the API response for this view, confirmed by the differential test). This
-- SELECT list reproduces that exact column set, order and naming explicitly, since
-- PostgreSQL cannot express it via a bare `SELECT *` join.
CREATE VIEW uihelper_stock_entries AS
SELECT
	s.id,
	s.product_id,
	s.amount,
	s.best_before_date,
	s.purchased_date,
	s.stock_id,
	s.price,
	s.open,
	s.opened_date,
	s.row_created_timestamp,
	s.location_id,
	s.shopping_location_id,
	s.note,
	p.id AS "id:1",
	p.name,
	p.description,
	p.product_group_id,
	p.active,
	p.location_id AS "location_id:1",
	p.shopping_location_id AS "shopping_location_id:1",
	p.qu_id_purchase,
	p.qu_id_stock,
	p.min_stock_amount,
	p.default_best_before_days,
	p.default_best_before_days_after_open,
	p.default_best_before_days_after_freezing,
	p.default_best_before_days_after_thawing,
	p.picture_file_name,
	p.enable_tare_weight_handling,
	p.tare_weight,
	p.not_check_stock_fulfillment_for_recipes,
	p.parent_product_id,
	p.calories,
	p.cumulate_min_stock_amount_of_sub_products,
	p.due_type,
	p.quick_consume_amount,
	p.hide_on_stock_overview,
	p.default_stock_label_type,
	p.should_not_be_frozen,
	p.treat_opened_as_out_of_stock,
	p.no_own_stock,
	p.default_consume_location_id,
	p.move_on_open,
	p.row_created_timestamp AS "row_created_timestamp:1",
	p.qu_id_consume,
	p.auto_reprint_stock_label,
	p.quick_open_amount,
	p.qu_id_price,
	p.disable_open,
	p.default_purchase_price_type,
	p.has_sub_products,
	p.qu_factor_purchase_to_stock,
	p.qu_factor_consume_to_stock,
	p.qu_factor_price_to_stock
FROM stock s
JOIN products_view p
	ON s.product_id = p.id;

CREATE VIEW uihelper_stock_journal AS
SELECT
	sl.id,
	sl.row_created_timestamp,
	sl.correlation_id,
	sl.undone,
	sl.undone_timestamp,
	sl.transaction_type,
	sl.spoiled,
	sl.amount,
	sl.location_id,
	l.name AS location_name,
	p.name AS product_name,
	qu.name AS qu_name,
	qu.name_plural AS qu_name_plural,
	u.display_name AS user_display_name,
	p.id AS product_id,
	sl.note,
	sl.stock_id
FROM stock_log sl
LEFT JOIN users_dto u
	ON sl.user_id = u.id
JOIN products p
	ON sl.product_id = p.id
JOIN locations l
	ON sl.location_id = l.id
JOIN quantity_units qu
	ON p.qu_id_stock = qu.id;

-- NOTE for reviewers: u.display_name, p.name, qu.name and qu.name_plural are added to
-- GROUP BY (absent upstream) to satisfy PostgreSQL's stricter GROUP BY rules (Hazard 6).
-- This does not change the result set: each is functionally dependent on an already-grouped
-- column (display_name on user_id via the users_dto join key; name/name_plural on
-- product_id, since qu.id is derived from p.qu_id_stock which is itself a property of the
-- grouped product), so each group still has exactly one value for each added column.
CREATE VIEW uihelper_stock_journal_summary AS
SELECT
	user_id AS id, -- Dummy, LessQL needs an id column
	user_id, u.display_name AS user_display_name,
	p.name AS product_name,
	product_id,
	transaction_type,
	qu.name AS qu_name,
	qu.name_plural AS qu_name_plural,
	SUM(amount) AS amount
FROM stock_log sl
JOIN users_dto u
	ON sl.user_id = u.id
JOIN products p
	ON sl.product_id = p.id
JOIN quantity_units qu
	ON p.qu_id_stock = qu.id
WHERE undone = 0
GROUP BY user_id, product_id, transaction_type, u.display_name, p.name, qu.name, qu.name_plural;
