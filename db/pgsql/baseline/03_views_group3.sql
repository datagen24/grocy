CREATE VIEW stock_current_locations AS
SELECT
	1 AS id, -- Dummy, LessQL needs an id column
	s.product_id,
	SUM(s.amount) as amount,
	s.location_id AS location_id,
	l.name AS location_name,
	l.is_freezer AS location_is_freezer
FROM stock s
JOIN locations l
	ON s.location_id = l.id
GROUP BY s.product_id, s.location_id, l.name, l.is_freezer;

CREATE VIEW stock_edited_entries AS
/*
	Returns stock_id's which have been edited manually
*/
SELECT
	x.stock_id,
	x.stock_log_id_of_newest_edited_entry,

	-- When an origin entry was edited, the new origin amount is the one of the newest "stock-edit-new" + all
	-- previous consume transactions (mind that consume transaction amounts are negative, hence here - instead of +)
	(
		SELECT amount
		FROM stock_log sli
		WHERE sli.id = x.stock_log_id_of_newest_edited_entry
	)
	-
	COALESCE((
		SELECT SUM(amount)
		FROM stock_log sli_consumed
		WHERE sli_consumed.stock_id = x.stock_id
			AND sli_consumed.transaction_type IN ('consume', 'inventory-correction')
			AND sli_consumed.id < x.stock_log_id_of_newest_edited_entry
			AND sli_consumed.amount < 0
			AND sli_consumed.undone = 0), 0) AS edited_origin_amount
FROM (
	SELECT
		sl_add.stock_id,
		MAX(sl_edit.id) AS stock_log_id_of_newest_edited_entry
	FROM stock_log sl_add
	JOIN stock_log sl_edit
		ON sl_add.stock_id = sl_edit.stock_id
		AND sl_edit.transaction_type = 'stock-edit-new'
	WHERE sl_add.transaction_type IN ('purchase', 'inventory-correction', 'self-production')
		AND sl_add.amount > 0
GROUP BY sl_add.stock_id
) x
JOIN stock_log sl_edit
	ON x.stock_log_id_of_newest_edited_entry = sl_edit.id;

CREATE VIEW stock_next_use AS

/*
	The default consume rule is:
	Opened first, then first due first, then first in first out
	Apart from that products at their default consume location should be consumed first

	This orders the stock entries by that
	=> Highest "priority" per product = the stock entry to use next
	=> ORDER BY clause = ORDER BY priority DESC, open DESC, best_before_date ASC, purchased_date ASC
*/

SELECT
	(CAST(ROW_NUMBER() OVER(PARTITION BY s.product_id ORDER BY CASE WHEN COALESCE(p.default_consume_location_id, -1) = s.location_id THEN 0 ELSE 1 END ASC, s.open DESC, s.best_before_date ASC, s.purchased_date ASC) AS INTEGER)) * -1 AS priority,
	s.*
FROM stock s
JOIN products p
	ON p.id = s.product_id
ORDER BY CASE WHEN COALESCE(p.default_consume_location_id, -1) = s.location_id THEN 0 ELSE 1 END ASC, s.open DESC, s.best_before_date ASC, s.purchased_date ASC;

CREATE VIEW stock_splits AS

/*
	Helper view which shows splitted stock rows which could be compacted

	Stock entries with a stock_id starting with "x"
	and those with userfields shouldn't be compacted
*/

SELECT
	s.product_id,
	SUM(s.amount) AS total_amount,
	MIN(s.stock_id) AS stock_id_to_keep,
	MAX(s.id) AS id_to_keep,
	string_agg(s.id::text, ',') AS id_group,
	string_agg(s.stock_id::text, ',') AS stock_id_group,
	MIN(s.id) AS id -- Dummy
FROM stock s
WHERE s.stock_id NOT LIKE 'x%'
	AND NOT EXISTS(
		SELECT 1 FROM userfield_values
		WHERE object_id = s.stock_id
			AND field_id IN (SELECT id FROM userfields WHERE entity = 'stock')
			AND COALESCE(value, '') != ''
		)
GROUP BY s.product_id, s.best_before_date, s.purchased_date, s.price, s.open, s.opened_date, s.location_id, s.shopping_location_id, COALESCE(s.note, '')
HAVING COUNT(*) > 1;

CREATE VIEW tasks_current AS
SELECT *
FROM tasks
WHERE done = 0;

CREATE VIEW userfield_values_resolved AS
SELECT
	u.id, -- Dummy, LessQL needs an id column
	u.entity,
	u.name,
	u.caption,
	u.type,
	u.show_as_column_in_tables,
	u.row_created_timestamp,
	u.config,
	uv.object_id,
	uv.value
FROM userfields u
JOIN userfield_values uv
	ON u.id = uv.field_id

UNION

-- Kind of a hack, include userentity userfields also for the table userobjects
SELECT
	u.id, -- Dummy, LessQL needs an id column,
	'userobjects',
	u.name,
	u.caption,
	u.type,
	u.show_as_column_in_tables,
	u.row_created_timestamp,
	u.config,
	uv.object_id,
	uv.value
FROM userfields u
JOIN userfield_values uv
	ON u.id = uv.field_id
WHERE u.entity like 'userentity-%';

CREATE VIEW users_dto AS
SELECT
	id,
	username,
	first_name,
	last_name,
	row_created_timestamp,
	(CASE
		WHEN COALESCE(first_name, '') = '' AND COALESCE(last_name, '') != '' THEN last_name
		WHEN COALESCE(last_name, '') = '' AND COALESCE(first_name, '') != '' THEN first_name
		WHEN COALESCE(last_name, '') != '' AND COALESCE(first_name, '') != '' THEN first_name || ' ' || last_name
		ELSE username
	END
	) AS display_name,
	picture_file_name
FROM users;
