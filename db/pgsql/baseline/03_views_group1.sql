-- PostgreSQL baseline schema: views (group 1)
--
-- Ported from the SQLite view definitions in migrations/*.sql. Column names, column
-- order and aliases are preserved exactly - they are part of the REST API surface.
-- See db/pgsql/baseline/01_tables.sql for the target column types this schema selects
-- from, and the porting rules doc for the general SQLite -> PostgreSQL translation.

-- Helper for meal_plan_internal_recipe_relation: replicates SQLite's strftime('%W', d),
-- i.e. "week of year, Monday as first day of week 1; days before the first Monday of
-- the year are week 00". PostgreSQL has no equivalent format spec (to_char('WW') is not
-- Monday-based, to_char('IW') is ISO-8601 week numbering which can shift a date into the
-- previous/next year at the boundary - neither matches SQLite's definition).
--
-- NOTE for reviewers: recipes of type 'mealplan-week' get their `name` written by SQL
-- triggers on meal_plan (see migrations/0071.sql, 0073.sql, 0096.sql, 0139.sql) using
-- the same LTRIM(STRFTIME('%Y-%W', ...), '0') expression. Whoever ports those triggers
-- to PostgreSQL MUST reuse this same function (or an expression that produces identical
-- output) or the join in meal_plan_internal_recipe_relation below will silently stop
-- matching rows.
CREATE FUNCTION grocy_sqlite_percent_w(d DATE) RETURNS INTEGER AS $$
	SELECT CASE
		WHEN d < fm.first_monday THEN 0
		ELSE (d - fm.first_monday) / 7 + 1
	END
	FROM (
		SELECT jan1 + ((8 - EXTRACT(ISODOW FROM jan1)::integer) % 7) AS first_monday
		FROM (SELECT make_date(EXTRACT(YEAR FROM d)::integer, 1, 1) AS jan1) j
	) fm;
$$ LANGUAGE sql IMMUTABLE;

CREATE VIEW batteries_current AS
SELECT
	b.id, -- Dummy, LessQL needs an id column
	b.id AS battery_id,
	MAX(l.tracked_time) AS last_tracked_time,
	CASE WHEN b.charge_interval_days = 0
		THEN '2999-12-31 23:59:59'::timestamp
		ELSE MAX(l.tracked_time) + (b.charge_interval_days::text || ' day')::interval
	END AS next_estimated_charge_time
FROM batteries b
LEFT JOIN battery_charge_cycles l
	ON b.id = l.battery_id
	AND l.undone = 0
WHERE b.active = 1
GROUP BY b.id, b.charge_interval_days;

CREATE VIEW chores_assigned_users_resolved AS
SELECT
	c.id AS chore_id,
	u.id AS user_id
FROM chores c
JOIN users u
	ON ',' || c.assignment_config || ',' LIKE '%,' || CAST(u.id AS TEXT) || ',%'
WHERE c.active = 1;

CREATE VIEW chores_execution_timeline AS

SELECT
	cl.chore_id,
	cl.tracked_time,
	(SELECT tracked_time FROM chores_log WHERE chore_id = cl.chore_id AND undone = 0 AND tracked_time < cl.tracked_time ORDER BY tracked_time DESC LIMIT 1) AS tracked_time_before,
	CAST(trunc(EXTRACT(EPOCH FROM (cl.tracked_time - (SELECT tracked_time FROM chores_log WHERE chore_id = cl.chore_id AND undone = 0 AND tracked_time < cl.tracked_time ORDER BY tracked_time DESC LIMIT 1))) / 3600.0) AS INTEGER) AS frequency_hours
FROM chores_log cl
WHERE cl.undone = 0;

CREATE VIEW meal_plan_internal_recipe_relation AS

-- Relation between a meal plan (day) and the corresponding internal recipe(s)

SELECT mp.day, r.id AS recipe_id
FROM meal_plan mp
JOIN recipes r
	ON r.name = CAST(mp.day AS TEXT)
	AND r.type = 'mealplan-day'

UNION

SELECT mp.day, r.id AS recipe_id
FROM meal_plan mp
JOIN recipes r
	ON r.name = LTRIM(EXTRACT(YEAR FROM mp.day)::integer::text || '-' || LPAD(grocy_sqlite_percent_w(mp.day)::text, 2, '0'), '0')
	AND r.type = 'mealplan-week'

UNION

SELECT mp.day, r.id AS recipe_id
FROM meal_plan mp
JOIN recipes r
	ON r.name = CAST(mp.day AS TEXT) || '#' || CAST(mp.id AS TEXT)
	AND r.type = 'mealplan-shadow';

CREATE VIEW permission_tree AS
WITH RECURSIVE perm AS (
	SELECT id AS root, id AS child, name, parent
	FROM permission_hierarchy
	UNION
	SELECT perm.root, ph.id, ph.name, ph.id
	FROM permission_hierarchy ph, perm
	WHERE ph.parent = perm.child
)
SELECT root AS id, name AS name
FROM perm;

CREATE VIEW product_barcodes_comma_separated AS
SELECT
	MIN(pb.id) AS id, -- Dummy, LessQL needs an id column. Not in GROUP BY upstream
	-- (SQLite tolerates bare non-grouped columns); PostgreSQL requires an aggregate, and
	-- MIN() is used rather than adding pb.id to GROUP BY because that would produce one
	-- row per barcode instead of one row per product_id, defeating the view's purpose.
	pb.product_id,
	string_agg(pb.barcode, ',') AS barcodes
FROM product_barcodes pb
JOIN products p
	ON pb.product_id = p.id
WHERE p.active = 1
GROUP BY pb.product_id;

CREATE VIEW product_barcodes_view AS
SELECT
	pb.id,
	pb.product_id,
	pb.barcode,
	pb.qu_id,
	pb.amount,
	pb.shopping_location_id,
	pb.last_price,
	pb.note
FROM product_barcodes pb

UNION ALL

-- Product Grocycodes
SELECT
	p.id,
	p.id AS product_id,
	'grcy:p:' || CAST(p.id AS TEXT) AS barcode,
	p.qu_id_stock AS qu_id,
	NULL AS amount,
	NULL AS shopping_location_id,
	NULL AS last_price,
	NULL AS note
FROM products p;
