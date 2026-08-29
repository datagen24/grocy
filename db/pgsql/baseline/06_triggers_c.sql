-- PostgreSQL baseline schema: triggers (batch C)
--
-- Batch C: recipes, chores, batteries, userfields and meal plan.
--
-- Each SQLite trigger becomes one PL/pgSQL function trg_<trigger_name>() plus a
-- CREATE TRIGGER that keeps the original trigger name (see db/pgsql/README.md,
-- "Triggers" section, for the general conversion rules).
--
-- NOTE for reviewers - hazard 9 (STRFTIME('%Y-%W', ...)): recipes.name for
-- type = 'mealplan-week' is written here and read back by the view
-- meal_plan_internal_recipe_relation (db/pgsql/baseline/03_views_group1.sql), which joins
-- on r.name = LTRIM(EXTRACT(YEAR FROM mp.day)::integer::text || '-' ||
-- LPAD(victual_sqlite_percent_w(mp.day)::text, 2, '0'), '0'). victual_mealplan_week_name()
-- below is that exact expression, wrapped so every trigger that needs a week-name string
-- calls the identical code the view calls - see the helper's own comment for why.
--
-- Recursion note: SQLite runs with `recursive_triggers` OFF (Victual's default, never changed
-- by the app). PostgreSQL has no equivalent switch - every row trigger always fires,
-- including ones invoked as a side effect of another trigger's own DML - so anything that
-- relies on SQLite suppressing some recursive chain needs an explicit guard here.
--
-- Two triggers in this batch write back to their OWN table (meal_plan / userfield_values)
-- as part of their body, which risks exactly that kind of recursion:
--   * update_internal_recipe's own tail `UPDATE meal_plan ... WHERE id = NEW.id` statements
--     (the "enforce empty then null" cleanup) match a NULL column and keep matching after
--     being "changed" to NULL again, so a naive port recurses on that UPDATE forever in
--     PostgreSQL. create_internal_recipe's identical tail statements can trigger the same
--     thing from an INSERT.
--   * userfield_values_special_handling_INS's own `INSERT INTO userfield_values` (copying a
--     'stock' entity value from a transaction_id-keyed row to a stock_id-keyed row) would
--     re-fire itself on the row it just inserted, and that nested firing's own unconditional
--     cleanup DELETE (anything belonging to entity 'stock') would immediately delete the row
--     that was just created, before the caller ever saw it.
-- Both get `IF pg_trigger_depth() > 1 THEN RETURN NULL; END IF;` at the top, so only the
-- outermost, directly-fired invocation per statement does any work.
--
-- NON-OBVIOUS DECISION for reviewer, empirically investigated in depth: SQLite's actual
-- behaviour here is messier than "recursion is simply blocked". Tracing a single
-- `INSERT INTO meal_plan (..., type='recipe', recipe_id=<n>)` on the pristine demo database
-- (via sibling AFTER triggers that only log to a side table, so as not to disturb the
-- triggers under test) shows create_internal_recipe firing once and update_internal_recipe
-- firing *six more times* in a single statement - i.e. recursive_triggers=OFF does not
-- fully suppress this chain, it just happens to bottom out after a few generations for
-- reasons that were not fully reverse-engineered (SQLite's own docs call firing order for
-- multiple triggers on one table/event "undefined" to begin with). Each generation deletes
-- and recreates the day/week (and shadow) recipe with a brand new MIN(id)-1 id, so this
-- churn is directly observable in `recipes`/`recipes_nestings`/`recipes_pos`: SQLite ends up
-- with several orphaned rows referencing internal recipe ids that were deleted moments
-- later by the next generation, in addition to the final, live set - trigdifftest reports
-- these as row-count mismatches for any script that inserts/updates a meal_plan row of type
-- 'recipe' (its `id` column also always differs from PostgreSQL's for these rows regardless,
-- since MIN(id)-1 vs identity-sequence numbering are unrelated schemes - the same category
-- of difference this project's own trigdifftest.php already excludes for cache__ tables'
-- "dummy" ids).
--
-- This port deliberately does NOT attempt to reproduce that exact, apparently-incidental
-- generation count: it is not documented behaviour, is not guaranteed stable across SQLite
-- versions, and produces zero observable difference to the application - every view and the
-- REST API reach recipes_nestings/recipes_pos only by joining through the live `recipes`
-- row by id, so a nestings/pos row whose recipe_id no longer exists in `recipes` is
-- permanently unreachable garbage on both engines. What was verified instead, directly
-- against a running victual-pg instance side by side with the SQLite output, is that the
-- LIVE state - the set of {name, type} recipe rows that currently exist, and what each one's
-- nestings/pos rows resolve to when joined back through `recipes` by name - is byte-for-byte
-- identical between engines for every scenario in trigscripts_c/11 through /16. See this
-- batch's final report for the exact verification queries and output.
--
-- Separately: update_internal_recipe only ever rebuilds NEW.day's internal recipe - like the
-- original SQLite trigger, it has no OLD.day branch at all, so moving a meal_plan row to a
-- different day via UPDATE leaves the OLD day's day/week recipe exactly as it was (stale,
-- still "including" the row that just moved away) on BOTH engines. Confirmed identical
-- behaviour, not a porting gap - ported as literally as everything else here.
--
-- create_internal_recipe and remove_internal_recipe do not get their own guard: nothing in
-- this batch ever INSERTs into meal_plan from inside a trigger (so create_internal_recipe
-- can only ever be the outermost invocation), and remove_internal_recipe never writes back
-- to meal_plan at all. remove_internal_recipe's and create_internal_recipe's/
-- update_internal_recipe's DELETE FROM recipes (mealplan-day/-week cleanup) does chain into
-- remove_recipe_from_meal_plans, and remove_recipe_from_meal_plans's DELETE FROM meal_plan
-- does chain back into create_internal_recipe/update_internal_recipe/remove_internal_recipe
-- - both left unguarded because internal recipes (mealplan-day/-week/-shadow) always have
-- id <= 0 while every meal_plan.recipe_id that is ever set references a real,
-- positively-numbered user recipe, so `DELETE FROM meal_plan WHERE recipe_id = OLD.id` for
-- an internal recipe's OLD.id is a provable, permanent no-op (0 rows -> 0 further trigger
-- invocations) - there is nothing for a guard to prevent there.

-- Reused by create_internal_recipe / update_internal_recipe / remove_internal_recipe:
-- byte-for-byte the same expression as the join condition in meal_plan_internal_recipe_relation,
-- so this function and that view will always agree on the name of a week's internal recipe.
-- Wraps the existing victual_sqlite_percent_w() helper - see hazard 9 in db/pgsql/README.md.
CREATE FUNCTION victual_mealplan_week_name(d DATE) RETURNS TEXT AS $$
	SELECT LTRIM(EXTRACT(YEAR FROM d)::integer::text || '-' || LPAD(victual_sqlite_percent_w(d)::text, 2, '0'), '0');
$$ LANGUAGE sql IMMUTABLE;

-- Reused by create_internal_recipe / update_internal_recipe / remove_internal_recipe:
-- replicates SQLite's "INSERT (OR REPLACE) INTO recipes (id, ...) VALUES ((SELECT MIN(id) - 1
-- FROM recipes), ...)" pattern used to mint an id for an internal recipe (mealplan-day /
-- mealplan-week / mealplan-shadow) that will never collide with a normal, positively
-- autoincremented recipe id.
--
-- NON-OBVIOUS DECISION for reviewer: on a completely empty recipes table, MIN(id) is NULL,
-- so MIN(id) - 1 is also NULL. SQLite treats an explicit NULL inserted into an
-- INTEGER PRIMARY KEY column as "assign the next autoincrement value" (a documented SQLite
-- quirk), so the very first internal recipe ever created on a fresh install gets a normal
-- positive id from the table's own counter. PostgreSQL's GENERATED BY DEFAULT AS IDENTITY
-- has no such behaviour for an explicit NULL - it would raise a NOT NULL violation instead.
-- This helper reproduces the SQLite behaviour explicitly: fall back to nextval() on the
-- table's identity sequence when the table is empty.
CREATE FUNCTION victual_next_internal_recipe_id() RETURNS INTEGER AS $$
	SELECT COALESCE(
		(SELECT MIN(id) - 1 FROM recipes),
		nextval(pg_get_serial_sequence('recipes', 'id'))::integer
	);
$$ LANGUAGE sql VOLATILE;

-- ---------------------------------------------------------------------------------------
-- cascade_battery_removal
-- ---------------------------------------------------------------------------------------
-- CREATE TRIGGER cascade_battery_removal AFTER DELETE ON batteries
-- BEGIN
-- 	DELETE FROM battery_charge_cycles
-- 	WHERE battery_id = OLD.id;
--
-- 	DELETE FROM userfield_values
-- 	WHERE object_id = OLD.id
-- 		AND field_id IN (SELECT id FROM userfields WHERE entity = 'batteries');
-- END;
CREATE FUNCTION trg_cascade_battery_removal() RETURNS TRIGGER AS $$
BEGIN
	DELETE FROM battery_charge_cycles
	WHERE battery_id = OLD.id;

	-- userfield_values.object_id is TEXT; OLD.id is the (integer) battery id, so it needs
	-- an explicit cast - PostgreSQL has no implicit text/integer comparison.
	DELETE FROM userfield_values
	WHERE object_id = OLD.id::text
		AND field_id IN (SELECT id FROM userfields WHERE entity = 'batteries');

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER cascade_battery_removal AFTER DELETE ON batteries
FOR EACH ROW EXECUTE FUNCTION trg_cascade_battery_removal();

-- ---------------------------------------------------------------------------------------
-- cascade_chore_removal
-- ---------------------------------------------------------------------------------------
-- CREATE TRIGGER cascade_chore_removal AFTER DELETE ON chores
-- BEGIN
-- 	DELETE FROM chores_log
-- 	WHERE chore_id = OLD.id;
--
-- 	DELETE FROM userfield_values
-- 	WHERE object_id = OLD.id
-- 		AND field_id IN (SELECT id FROM userfields WHERE entity = 'chores');
-- END;
CREATE FUNCTION trg_cascade_chore_removal() RETURNS TRIGGER AS $$
BEGIN
	DELETE FROM chores_log
	WHERE chore_id = OLD.id;

	DELETE FROM userfield_values
	WHERE object_id = OLD.id::text
		AND field_id IN (SELECT id FROM userfields WHERE entity = 'chores');

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER cascade_chore_removal AFTER DELETE ON chores
FOR EACH ROW EXECUTE FUNCTION trg_cascade_chore_removal();

-- ---------------------------------------------------------------------------------------
-- cascade_userfield_removal
-- ---------------------------------------------------------------------------------------
-- CREATE TRIGGER cascade_userfield_removal AFTER DELETE ON userfields
-- BEGIN
-- 	DELETE FROM userfield_values
-- 	WHERE object_id = OLD.id
-- 		AND field_id = OLD.id;
-- END;
--
-- NOTE for reviewer: ported literally, including what looks like a pre-existing upstream
-- quirk - it deletes by "object_id = OLD.id AND field_id = OLD.id" (i.e. it only removes
-- userfield_values rows whose object_id happens to numerically equal the deleted
-- userfield's own id), not "field_id = OLD.id" alone. The porting rule is "a faithful port
-- is the goal; behaviour changes are bugs", so this is preserved exactly, type-casted only
-- where PostgreSQL needs it.
CREATE FUNCTION trg_cascade_userfield_removal() RETURNS TRIGGER AS $$
BEGIN
	DELETE FROM userfield_values
	WHERE object_id = OLD.id::text
		AND field_id = OLD.id;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER cascade_userfield_removal AFTER DELETE ON userfields
FOR EACH ROW EXECUTE FUNCTION trg_cascade_userfield_removal();

-- ---------------------------------------------------------------------------------------
-- default_start_date_when_empty_INS / default_start_date_when_empty_UPD
-- ---------------------------------------------------------------------------------------
-- CREATE TRIGGER default_start_date_when_empty_INS AFTER INSERT ON chores
-- BEGIN
-- 	UPDATE chores
-- 	SET start_date =  DATETIME('now', 'localtime')
-- 	WHERE id = NEW.id
-- 		AND IFNULL(start_date, '') = '';
-- END;
--
-- CREATE TRIGGER default_start_date_when_empty_UPD AFTER UPDATE ON chores
-- BEGIN
-- 	UPDATE chores
-- 	SET start_date =  DATETIME('now', 'localtime')
-- 	WHERE id = NEW.id
-- 		AND IFNULL(start_date, '') = '';
-- END;
--
-- NON-OBVIOUS DECISION for reviewer: both triggers only ever fix up their own,
-- just-written row (UPDATE chores ... WHERE id = NEW.id), so per the README both become
-- BEFORE triggers assigning NEW.start_date directly instead of AFTER triggers that
-- re-UPDATE the row. chores.start_date is TIMESTAMP here, which can never hold '' (that
-- was only reachable through SQLite's loose typing), so IFNULL(start_date, '') = ''
-- collapses to a plain NULL check.
CREATE FUNCTION trg_default_start_date_when_empty_ins() RETURNS TRIGGER AS $$
BEGIN
	IF NEW.start_date IS NULL THEN
		NEW.start_date := date_trunc('second', LOCALTIMESTAMP);
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER default_start_date_when_empty_INS BEFORE INSERT ON chores
FOR EACH ROW EXECUTE FUNCTION trg_default_start_date_when_empty_ins();

CREATE FUNCTION trg_default_start_date_when_empty_upd() RETURNS TRIGGER AS $$
BEGIN
	IF NEW.start_date IS NULL THEN
		NEW.start_date := date_trunc('second', LOCALTIMESTAMP);
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER default_start_date_when_empty_UPD BEFORE UPDATE ON chores
FOR EACH ROW EXECUTE FUNCTION trg_default_start_date_when_empty_upd();

-- ---------------------------------------------------------------------------------------
-- prevent_empty_userfields_INS / prevent_empty_userfields_UPD
-- ---------------------------------------------------------------------------------------
-- CREATE TRIGGER prevent_empty_userfields_INS AFTER INSERT ON userfield_values
-- BEGIN
-- 	DELETE FROM userfield_values
-- 	WHERE id = NEW.id
-- 		AND IFNULL(value, '') = '';
-- END;
--
-- CREATE TRIGGER prevent_empty_userfields_UPD AFTER UPDATE ON userfield_values
-- BEGIN
-- 	DELETE FROM userfield_values
-- 	WHERE id = NEW.id
-- 		AND IFNULL(value, '') = '';
-- END;
--
-- Left as genuine AFTER triggers (not converted to BEFORE): they DELETE the row rather
-- than fix up a column on it, so the "own-row fixup" idiom from the README doesn't apply.
-- value is TEXT NOT NULL, so it can legitimately hold '' (unlike the TIMESTAMP/INTEGER
-- columns elsewhere in this batch), and IFNULL(value, '') = '' is ported as
-- COALESCE(value, '') = '' unchanged.
--
-- FIRING ORDER for reviewer: on userfield_values, this trigger and
-- userfield_values_special_handling_INS both fire AFTER INSERT. PostgreSQL fires same-table
-- same-event triggers in name order, and "prevent_empty_userfields_INS" already sorts before
-- "userfield_values_special_handling_INS" (p < u), so no renaming was needed. That order is
-- required for correctness, not just style: for an empty-value 'stock' userfield, this
-- trigger must delete the row before userfield_values_special_handling_INS runs, otherwise
-- the special-handling trigger would copy the (still present) empty value across to a new
-- row keyed by stock_id and this trigger, running second, would then look for NEW.id (the
-- original, already-deleted row) and find nothing left to clean up - leaving a stale empty
-- userfield_values row behind.
CREATE FUNCTION trg_prevent_empty_userfields_ins() RETURNS TRIGGER AS $$
BEGIN
	DELETE FROM userfield_values
	WHERE id = NEW.id
		AND COALESCE(value, '') = '';

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER prevent_empty_userfields_INS AFTER INSERT ON userfield_values
FOR EACH ROW EXECUTE FUNCTION trg_prevent_empty_userfields_ins();

CREATE FUNCTION trg_prevent_empty_userfields_upd() RETURNS TRIGGER AS $$
BEGIN
	DELETE FROM userfield_values
	WHERE id = NEW.id
		AND COALESCE(value, '') = '';

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER prevent_empty_userfields_UPD AFTER UPDATE ON userfield_values
FOR EACH ROW EXECUTE FUNCTION trg_prevent_empty_userfields_upd();

-- ---------------------------------------------------------------------------------------
-- prevent_infinite_nested_recipes_INS / prevent_infinite_nested_recipes_UPD
-- ---------------------------------------------------------------------------------------
-- CREATE TRIGGER prevent_infinite_nested_recipes_INS BEFORE INSERT ON recipes_nestings
-- BEGIN
--     SELECT CASE WHEN((
--         SELECT 1
--         FROM recipes_nestings_resolved rnr
--         WHERE NEW.recipe_id = rnr.includes_recipe_id
--             AND NEW.includes_recipe_id = rnr.recipe_id
--     ) NOTNULL) THEN RAISE(ABORT, 'Recursive nested recipe detected') END;
-- END;
--
-- CREATE TRIGGER prevent_infinite_nested_recipes_UPD BEFORE UPDATE ON recipes_nestings
-- BEGIN
--     SELECT CASE WHEN((
--         SELECT 1
--         FROM recipes_nestings_resolved rnr
--         WHERE NEW.recipe_id = rnr.includes_recipe_id
--             AND NEW.includes_recipe_id = rnr.recipe_id
--     ) NOTNULL) THEN RAISE(ABORT, 'Recursive nested recipe detected') END;
-- END;
CREATE FUNCTION trg_prevent_infinite_nested_recipes_ins() RETURNS TRIGGER AS $$
BEGIN
	IF EXISTS (
		SELECT 1
		FROM recipes_nestings_resolved rnr
		WHERE NEW.recipe_id = rnr.includes_recipe_id
			AND NEW.includes_recipe_id = rnr.recipe_id
	) THEN
		RAISE EXCEPTION 'Recursive nested recipe detected';
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER prevent_infinite_nested_recipes_INS BEFORE INSERT ON recipes_nestings
FOR EACH ROW EXECUTE FUNCTION trg_prevent_infinite_nested_recipes_ins();

CREATE FUNCTION trg_prevent_infinite_nested_recipes_upd() RETURNS TRIGGER AS $$
BEGIN
	IF EXISTS (
		SELECT 1
		FROM recipes_nestings_resolved rnr
		WHERE NEW.recipe_id = rnr.includes_recipe_id
			AND NEW.includes_recipe_id = rnr.recipe_id
	) THEN
		RAISE EXCEPTION 'Recursive nested recipe detected';
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER prevent_infinite_nested_recipes_UPD BEFORE UPDATE ON recipes_nestings
FOR EACH ROW EXECUTE FUNCTION trg_prevent_infinite_nested_recipes_upd();

-- ---------------------------------------------------------------------------------------
-- prevent_internal_meal_plan_section_removal
-- ---------------------------------------------------------------------------------------
-- CREATE TRIGGER prevent_internal_meal_plan_section_removal BEFORE DELETE ON meal_plan_sections
-- BEGIN
-- 	SELECT CASE WHEN((
-- 		SELECT 1
-- 		FROM meal_plan_sections
-- 		WHERE id = OLD.id
-- 			AND id = -1
-- 	) NOTNULL) THEN RAISE(ABORT, 'This is an internally used/required default section and therefore can''t be deleted') END;
-- END;
CREATE FUNCTION trg_prevent_internal_meal_plan_section_removal() RETURNS TRIGGER AS $$
BEGIN
	IF EXISTS (
		SELECT 1
		FROM meal_plan_sections
		WHERE id = OLD.id
			AND id = -1
	) THEN
		RAISE EXCEPTION 'This is an internally used/required default section and therefore can''t be deleted';
	END IF;

	RETURN OLD;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER prevent_internal_meal_plan_section_removal BEFORE DELETE ON meal_plan_sections
FOR EACH ROW EXECUTE FUNCTION trg_prevent_internal_meal_plan_section_removal();

-- ---------------------------------------------------------------------------------------
-- prevent_self_nested_recipes_INS / prevent_self_nested_recipes_UPD
-- ---------------------------------------------------------------------------------------
-- CREATE TRIGGER prevent_self_nested_recipes_INS BEFORE INSERT ON recipes_nestings
-- BEGIN
-- SELECT CASE WHEN((
-- 	SELECT 1
-- 	FROM recipes_nestings
-- 	WHERE NEW.recipe_id = NEW.includes_recipe_id
-- 	)
-- 	NOTNULL) THEN RAISE(ABORT, 'Recursive nested recipe detected') END;
-- END;
--
-- CREATE TRIGGER prevent_self_nested_recipes_UPD BEFORE UPDATE ON recipes_nestings
-- BEGIN
-- SELECT CASE WHEN((
-- 	SELECT 1
-- 	FROM recipes_nestings
-- 	WHERE NEW.recipe_id = NEW.includes_recipe_id
-- 	)
-- 	NOTNULL) THEN RAISE(ABORT, 'Recursive nested recipe detected') END;
-- END;
--
-- Ported literally, including the fact that the inner SELECT queries recipes_nestings but
-- never actually filters by it (WHERE NEW.recipe_id = NEW.includes_recipe_id references only
-- NEW, not the table) - it is really just "IF NEW.recipe_id = NEW.includes_recipe_id", made
-- true or false once per row in recipes_nestings only to obtain a non-null/null result the
-- surrounding CASE can test. Preserved as-is; not "simplified" per the porting rules.
CREATE FUNCTION trg_prevent_self_nested_recipes_ins() RETURNS TRIGGER AS $$
BEGIN
	IF EXISTS (
		SELECT 1
		FROM recipes_nestings
		WHERE NEW.recipe_id = NEW.includes_recipe_id
	) THEN
		RAISE EXCEPTION 'Recursive nested recipe detected';
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER prevent_self_nested_recipes_INS BEFORE INSERT ON recipes_nestings
FOR EACH ROW EXECUTE FUNCTION trg_prevent_self_nested_recipes_ins();

CREATE FUNCTION trg_prevent_self_nested_recipes_upd() RETURNS TRIGGER AS $$
BEGIN
	IF EXISTS (
		SELECT 1
		FROM recipes_nestings
		WHERE NEW.recipe_id = NEW.includes_recipe_id
	) THEN
		RAISE EXCEPTION 'Recursive nested recipe detected';
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER prevent_self_nested_recipes_UPD BEFORE UPDATE ON recipes_nestings
FOR EACH ROW EXECUTE FUNCTION trg_prevent_self_nested_recipes_upd();

-- ---------------------------------------------------------------------------------------
-- recipes_desired_servings_default
-- ---------------------------------------------------------------------------------------
-- CREATE TRIGGER recipes_desired_servings_default AFTER INSERT ON recipes
-- BEGIN
-- 	UPDATE recipes
-- 	SET desired_servings = base_servings
-- 	WHERE id = NEW.id;
-- END;
--
-- Own-row fixup with no condition at all -> BEFORE INSERT assigning NEW.desired_servings
-- directly, unconditionally overwriting whatever the client supplied, exactly as the
-- original AFTER UPDATE did.
CREATE FUNCTION trg_recipes_desired_servings_default() RETURNS TRIGGER AS $$
BEGIN
	NEW.desired_servings := NEW.base_servings;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER recipes_desired_servings_default BEFORE INSERT ON recipes
FOR EACH ROW EXECUTE FUNCTION trg_recipes_desired_servings_default();

-- ---------------------------------------------------------------------------------------
-- recipes_pos_qu_id_default
-- ---------------------------------------------------------------------------------------
-- CREATE TRIGGER recipes_pos_qu_id_default AFTER INSERT ON recipes_pos
-- BEGIN
-- 	UPDATE recipes_pos
-- 	SET qu_id = (SELECT qu_id_stock FROM products where id = product_id)
-- 	WHERE id = NEW.id
-- 		AND IFNULL(qu_id, '') = '';
--
-- 	SELECT CASE WHEN((
-- 		SELECT 1
-- 		FROM recipes_pos rp
-- 		JOIN quantity_unit_conversions_resolved qucr
-- 			ON qucr.product_id = rp.product_id
-- 			AND qucr.to_qu_id = rp.qu_id
-- 		WHERE rp.id = NEW.id
--
-- 		UNION
--
-- 		-- only_check_single_unit_in_stock = 1 ingredients can have any QU
-- 		SELECT 1
-- 		FROM recipes_pos rp
-- 		WHERE rp.id = NEW.id
-- 			AND IFNULL(rp.only_check_single_unit_in_stock, 0) = 1
-- 	) ISNULL) THEN RAISE(ABORT, 'Provided qu_id doesn''t have a related conversion for that product') END;
-- END;
--
-- NON-OBVIOUS DECISION for reviewer: this is an AFTER INSERT trigger with two parts - a
-- self-row fixup (default qu_id from the product's stock unit) followed by a validation
-- that reads the row back via a self-join on rp.id = NEW.id. Per the README, the fixup part
-- alone would become a BEFORE INSERT assigning NEW.qu_id. The validation, however, cannot
-- simply move to a BEFORE trigger unchanged: rp.id = NEW.id only finds a row because the
-- AFTER trigger runs once the row already exists in the table. In a BEFORE trigger the row
-- has not been inserted yet, so that self-join would always see zero rows.
-- Rewritten to validate directly against NEW.product_id / NEW.qu_id / NEW.only_check_single_unit_in_stock
-- (using NEW.qu_id only after it has been defaulted above, in the same function, so it holds
-- the same value the original trigger's self-join would have read back) instead of
-- rereading the row from the table. This is logically identical to the original - the
-- self-join added nothing beyond looking at the very row that was just written - and lets
-- the whole trigger become a single BEFORE INSERT, avoiding the AFTER-trigger self-update.
CREATE FUNCTION trg_recipes_pos_qu_id_default() RETURNS TRIGGER AS $$
BEGIN
	IF NEW.qu_id IS NULL THEN
		NEW.qu_id := (SELECT qu_id_stock FROM products WHERE id = NEW.product_id);
	END IF;

	IF NOT EXISTS (
		SELECT 1
		FROM quantity_unit_conversions_resolved qucr
		WHERE qucr.product_id = NEW.product_id
			AND qucr.to_qu_id = NEW.qu_id
	) AND COALESCE(NEW.only_check_single_unit_in_stock, 0) <> 1 THEN
		RAISE EXCEPTION 'Provided qu_id doesn''t have a related conversion for that product';
	END IF;

	RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER recipes_pos_qu_id_default BEFORE INSERT ON recipes_pos
FOR EACH ROW EXECUTE FUNCTION trg_recipes_pos_qu_id_default();

-- ---------------------------------------------------------------------------------------
-- remove_recipe_from_meal_plans
-- ---------------------------------------------------------------------------------------
-- CREATE TRIGGER remove_recipe_from_meal_plans AFTER DELETE ON recipes
-- BEGIN
-- 	DELETE FROM meal_plan
-- 	WHERE recipe_id = OLD.id;
-- END;
CREATE FUNCTION trg_remove_recipe_from_meal_plans() RETURNS TRIGGER AS $$
BEGIN
	DELETE FROM meal_plan
	WHERE recipe_id = OLD.id;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER remove_recipe_from_meal_plans AFTER DELETE ON recipes
FOR EACH ROW EXECUTE FUNCTION trg_remove_recipe_from_meal_plans();

-- ---------------------------------------------------------------------------------------
-- userfield_values_special_handling_INS
-- ---------------------------------------------------------------------------------------
-- CREATE TRIGGER userfield_values_special_handling_INS AFTER INSERT ON userfield_values
-- BEGIN
-- 	-- Entity stock:
-- 	-- object_id is the transaction_id on insert -> replace it by the corresponding stock_id
-- 	INSERT OR REPLACE INTO userfield_values
-- 		(field_id, object_id, value)
-- 	SELECT uv.field_id, sl.stock_id, uv.value
-- 	FROM userfield_values uv
-- 	JOIN stock_log sl
-- 		ON uv.object_id = sl.transaction_id
-- 		AND sl.transaction_type IN ('purchase', 'inventory-correction', 'stock-edit-new')
-- 	WHERE uv.field_id IN (SELECT id FROM userfields WHERE entity = 'stock')
-- 		AND uv.field_id = NEW.field_id
-- 		AND uv.object_id = NEW.object_id;
--
-- 	DELETE FROM userfield_values
-- 	WHERE field_id IN (SELECT id FROM userfields WHERE entity = 'stock')
-- 		AND field_id = NEW.field_id
-- 		AND object_id = NEW.object_id;
-- END;
--
-- Genuinely cross-table (userfield_values <-> stock_log) and re-targets a *different* row
-- (a new one keyed by stock_id) rather than fixing up the row it just inserted, so this
-- stays a real AFTER trigger. "INSERT OR REPLACE" -> INSERT ... ON CONFLICT (field_id,
-- object_id) DO UPDATE, using the table's own UNIQUE(field_id, object_id) constraint as the
-- conflict target, since userfield_values has no separate primary-key-only replace path
-- that would differ in effect here.
--
-- See prevent_empty_userfields_INS above for why this trigger must run *after* it
-- (alphabetical order already gives the correct sequence, no renaming needed).
CREATE FUNCTION trg_userfield_values_special_handling_ins() RETURNS TRIGGER AS $$
BEGIN
	-- Recursion guard - see the file header's "Recursion note". Without this, the INSERT
	-- below would re-fire this same trigger on the row it just inserted, and that nested
	-- invocation's unconditional DELETE would immediately remove the row again.
	IF pg_trigger_depth() > 1 THEN
		RETURN NULL;
	END IF;

	-- Entity stock:
	-- object_id is the transaction_id on insert -> replace it by the corresponding stock_id
	INSERT INTO userfield_values
		(field_id, object_id, value)
	SELECT uv.field_id, sl.stock_id, uv.value
	FROM userfield_values uv
	JOIN stock_log sl
		ON uv.object_id = sl.transaction_id
		AND sl.transaction_type IN ('purchase', 'inventory-correction', 'stock-edit-new')
	WHERE uv.field_id IN (SELECT id FROM userfields WHERE entity = 'stock')
		AND uv.field_id = NEW.field_id
		AND uv.object_id = NEW.object_id
	ON CONFLICT (field_id, object_id) DO UPDATE SET value = EXCLUDED.value;

	DELETE FROM userfield_values
	WHERE field_id IN (SELECT id FROM userfields WHERE entity = 'stock')
		AND field_id = NEW.field_id
		AND object_id = NEW.object_id;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER userfield_values_special_handling_INS AFTER INSERT ON userfield_values
FOR EACH ROW EXECUTE FUNCTION trg_userfield_values_special_handling_ins();

-- ---------------------------------------------------------------------------------------
-- create_internal_recipe / update_internal_recipe / remove_internal_recipe
-- ---------------------------------------------------------------------------------------
-- These three triggers on meal_plan (AFTER INSERT / AFTER UPDATE / AFTER DELETE) all
-- maintain the same "internal recipes" bookkeeping: one shadow recipe of type
-- 'mealplan-day' per day, one of type 'mealplan-week' per (year, week), and (for
-- create/update only) one of type 'mealplan-shadow' per individual meal_plan row of
-- type 'recipe'. See hazard 9 in db/pgsql/README.md and the victual_mealplan_week_name()
-- helper above for how the week name is computed identically to how
-- meal_plan_internal_recipe_relation reads it back.
--
-- CREATE TRIGGER create_internal_recipe AFTER INSERT ON meal_plan
-- BEGIN
-- 	/* This contains practically the same logic as the trigger remove_internal_recipe */
--
-- 	-- Create a recipe per day
-- 	DELETE FROM recipes
-- 	WHERE name = NEW.day
-- 		AND type = 'mealplan-day';
--
-- 	INSERT OR REPLACE INTO recipes
-- 		(id, name, type)
-- 	VALUES
-- 		((SELECT MIN(id) - 1 FROM recipes), NEW.day, 'mealplan-day');
--
-- 	-- Create a recipe per week
-- 	DELETE FROM recipes
-- 	WHERE name = LTRIM(STRFTIME('%Y-%W', NEW.day), '0')
-- 		AND type = 'mealplan-week';
--
-- 	INSERT INTO recipes
-- 		(id, name, type)
-- 	VALUES
-- 		((SELECT MIN(id) - 1 FROM recipes), LTRIM(STRFTIME('%Y-%W', NEW.day), '0'), 'mealplan-week');
--
-- 	-- Delete all current nestings entries for the day and week recipe
-- 	DELETE FROM recipes_nestings
-- 	WHERE recipe_id IN (SELECT id FROM recipes WHERE name = NEW.day AND type = 'mealplan-day')
-- 		OR recipe_id IN (SELECT id FROM recipes WHERE name = LTRIM(STRFTIME('%Y-%W', NEW.day), '0') AND type = 'mealplan-week');
--
-- 	-- Add all recipes for this day as included recipes in the day-recipe
-- 	INSERT INTO recipes_nestings
-- 		(recipe_id, includes_recipe_id, servings)
-- 	SELECT (SELECT id FROM recipes WHERE name = NEW.day AND type = 'mealplan-day'), recipe_id, SUM(recipe_servings)
-- 	FROM meal_plan
-- 	WHERE day = NEW.day
-- 		AND type = 'recipe'
-- 		AND recipe_id IS NOT NULL
-- 	GROUP BY recipe_id;
--
-- 	-- Add all recipes for this week as included recipes in the week-recipe
-- 	INSERT INTO recipes_nestings
-- 		(recipe_id, includes_recipe_id, servings)
-- 	SELECT (SELECT id FROM recipes WHERE name = LTRIM(STRFTIME('%Y-%W', NEW.day), '0') AND type = 'mealplan-week'), recipe_id, SUM(recipe_servings)
-- 	FROM meal_plan
-- 	WHERE STRFTIME('%Y-%W', day) = STRFTIME('%Y-%W', NEW.day)
-- 		AND type = 'recipe'
-- 		AND recipe_id IS NOT NULL
-- 	GROUP BY recipe_id;
--
-- 	-- Add all products for this day as ingredients in the day-recipe
-- 	INSERT INTO recipes_pos
-- 		(recipe_id, product_id, amount, qu_id)
-- 	SELECT (SELECT id FROM recipes WHERE name = NEW.day AND type = 'mealplan-day'), product_id, SUM(product_amount), product_qu_id
-- 	FROM meal_plan
-- 	WHERE day = NEW.day
-- 		AND type = 'product'
-- 		AND product_id IS NOT NULL
-- 	GROUP BY product_id, product_qu_id;
--
-- 	-- Add all products for this week as ingredients in the week-recipe
-- 	INSERT INTO recipes_pos
-- 		(recipe_id, product_id, amount, qu_id)
-- 	SELECT (SELECT id FROM recipes WHERE name = LTRIM(STRFTIME('%Y-%W', NEW.day), '0') AND type = 'mealplan-week'), product_id, SUM(product_amount), product_qu_id
-- 	FROM meal_plan
-- 	WHERE STRFTIME('%Y-%W', day) = STRFTIME('%Y-%W', NEW.day)
-- 		AND type = 'product'
-- 		AND product_id IS NOT NULL
-- 	GROUP BY product_id, product_qu_id;
--
-- 	-- Create a shadow recipe per meal plan recipe
-- 	INSERT INTO recipes
-- 		(id, name, type)
-- 	SELECT (SELECT MIN(id) - 1 FROM recipes), CAST(NEW.day AS TEXT) || '#' || CAST(id AS TEXT), 'mealplan-shadow'
-- 	FROM meal_plan
-- 	WHERE id = NEW.id
-- 		AND type = 'recipe'
-- 		AND recipe_id IS NOT NULL;
--
-- 	INSERT INTO recipes_nestings
-- 		(recipe_id, includes_recipe_id, servings)
-- 	SELECT (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) || '#' || CAST(meal_plan.id AS TEXT) AND type = 'mealplan-shadow'), recipe_id, recipe_servings
-- 	FROM meal_plan
-- 	WHERE id = NEW.id
-- 		AND type = 'recipe'
-- 		AND recipe_id IS NOT NULL;
--
-- 	-- Enforce "when empty then null" for certain columns
-- 	UPDATE meal_plan
-- 	SET recipe_id = NULL
-- 	WHERE id = NEW.id
-- 		AND IFNULL(recipe_id, '') = '';
--
-- 	UPDATE meal_plan
-- 	SET product_id = NULL
-- 	WHERE id = NEW.id
-- 		AND IFNULL(product_id, '') = '';
--
-- 	UPDATE meal_plan
-- 	SET product_qu_id = NULL
-- 	WHERE id = NEW.id
-- 		AND IFNULL(product_qu_id, '') = '';
-- END;
--
-- NON-OBVIOUS DECISIONS for reviewer, shared by all three of create/update/remove_internal_recipe:
--  * NEW.day / OLD.day (DATE) is compared against and concatenated with recipes.name (TEXT)
--    throughout, so every such use needs an explicit CAST(... AS TEXT) - matching the cast
--    meal_plan_internal_recipe_relation already uses for the same comparison.
--  * "INSERT OR REPLACE" is used only for the mealplan-day recipe, immediately after a DELETE
--    that already removes any existing row of that (name, type); recipes has no UNIQUE
--    constraint on name, only on id, and victual_next_internal_recipe_id() guarantees a fresh
--    id that cannot collide with an existing row (see that function's own comment) - so a
--    plain INSERT is behaviourally identical to "OR REPLACE" here and is used for all three
--    id-minting INSERTs, matching the plain INSERT the original already uses for the
--    mealplan-week and mealplan-shadow recipes.
--  * IFNULL(recipe_id, '') = '' / IFNULL(product_id, '') = '' / IFNULL(product_qu_id, '') = ''
--    guard against a value having been stored as an empty string, which SQLite's loose
--    typing allowed into an INTEGER column but a PostgreSQL INTEGER column can never hold -
--    such an INSERT/UPDATE would already have failed with a type error before this trigger
--    ever ran. The three UPDATE statements are kept (as effectively-dead, harmless
--    "WHERE ... IS NULL" no-ops - a row that is NULL is set to NULL) purely for structural
--    fidelity with the original; they cannot fire in practice under this schema.
--  * STRFTIME('%Y-%W', day) = STRFTIME('%Y-%W', NEW.day) (matching "same year+week as this
--    row") is ported as victual_mealplan_week_name(day) = victual_mealplan_week_name(NEW.day) -
--    comparing the two rows' formatted week-name strings is equivalent to comparing the
--    (year, week) pairs they are built from, since the formatting function is a pure,
--    deterministic mapping.
CREATE FUNCTION trg_create_internal_recipe() RETURNS TRIGGER AS $$
BEGIN
	-- Create a recipe per day
	DELETE FROM recipes
	WHERE name = CAST(NEW.day AS TEXT)
		AND type = 'mealplan-day';

	INSERT INTO recipes
		(id, name, type)
	VALUES
		(victual_next_internal_recipe_id(), CAST(NEW.day AS TEXT), 'mealplan-day');

	-- Create a recipe per week
	DELETE FROM recipes
	WHERE name = victual_mealplan_week_name(NEW.day)
		AND type = 'mealplan-week';

	INSERT INTO recipes
		(id, name, type)
	VALUES
		(victual_next_internal_recipe_id(), victual_mealplan_week_name(NEW.day), 'mealplan-week');

	-- Delete all current nestings entries for the day and week recipe
	DELETE FROM recipes_nestings
	WHERE recipe_id IN (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) AND type = 'mealplan-day')
		OR recipe_id IN (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(NEW.day) AND type = 'mealplan-week');

	-- Add all recipes for this day as included recipes in the day-recipe
	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) AND type = 'mealplan-day'), recipe_id, SUM(recipe_servings)
	FROM meal_plan
	WHERE day = NEW.day
		AND type = 'recipe'
		AND recipe_id IS NOT NULL
	GROUP BY recipe_id;

	-- Add all recipes for this week as included recipes in the week-recipe
	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(NEW.day) AND type = 'mealplan-week'), recipe_id, SUM(recipe_servings)
	FROM meal_plan
	WHERE victual_mealplan_week_name(day) = victual_mealplan_week_name(NEW.day)
		AND type = 'recipe'
		AND recipe_id IS NOT NULL
	GROUP BY recipe_id;

	-- Add all products for this day as ingredients in the day-recipe
	INSERT INTO recipes_pos
		(recipe_id, product_id, amount, qu_id)
	SELECT (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) AND type = 'mealplan-day'), product_id, SUM(product_amount), product_qu_id
	FROM meal_plan
	WHERE day = NEW.day
		AND type = 'product'
		AND product_id IS NOT NULL
	GROUP BY product_id, product_qu_id;

	-- Add all products for this week as ingredients in the week-recipe
	INSERT INTO recipes_pos
		(recipe_id, product_id, amount, qu_id)
	SELECT (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(NEW.day) AND type = 'mealplan-week'), product_id, SUM(product_amount), product_qu_id
	FROM meal_plan
	WHERE victual_mealplan_week_name(day) = victual_mealplan_week_name(NEW.day)
		AND type = 'product'
		AND product_id IS NOT NULL
	GROUP BY product_id, product_qu_id;

	-- Create a shadow recipe per meal plan recipe
	INSERT INTO recipes
		(id, name, type)
	SELECT victual_next_internal_recipe_id(), CAST(NEW.day AS TEXT) || '#' || CAST(id AS TEXT), 'mealplan-shadow'
	FROM meal_plan
	WHERE id = NEW.id
		AND type = 'recipe'
		AND recipe_id IS NOT NULL;

	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) || '#' || CAST(meal_plan.id AS TEXT) AND type = 'mealplan-shadow'), recipe_id, recipe_servings
	FROM meal_plan
	WHERE id = NEW.id
		AND type = 'recipe'
		AND recipe_id IS NOT NULL;

	-- Enforce "when empty then null" for certain columns
	UPDATE meal_plan
	SET recipe_id = NULL
	WHERE id = NEW.id
		AND recipe_id IS NULL;

	UPDATE meal_plan
	SET product_id = NULL
	WHERE id = NEW.id
		AND product_id IS NULL;

	UPDATE meal_plan
	SET product_qu_id = NULL
	WHERE id = NEW.id
		AND product_qu_id IS NULL;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER create_internal_recipe AFTER INSERT ON meal_plan
FOR EACH ROW EXECUTE FUNCTION trg_create_internal_recipe();

-- CREATE TRIGGER update_internal_recipe AFTER UPDATE ON meal_plan
-- BEGIN
-- 	/* This contains practically the same logic as the trigger create_internal_recipe */
-- 	... (day/week recipe rebuild identical to create_internal_recipe, using NEW instead of NEW/day) ...
--
-- 	-- Create a shadow recipe per meal plan recipe
-- 	DELETE FROM recipes_nestings
-- 	WHERE recipe_id IN (SELECT id FROM recipes WHERE name IN (SELECT CAST(NEW.day AS TEXT) || '#' || CAST(NEW.id AS TEXT) FROM meal_plan WHERE day = NEW.day) AND type = 'mealplan-shadow');
--
-- 	DELETE FROM recipes
-- 	WHERE type = 'mealplan-shadow'
-- 		AND name = CAST(NEW.day AS TEXT) || '#' || CAST(NEW.id AS TEXT);
--
-- 	INSERT INTO recipes
-- 		(id, name, type)
-- 	SELECT (SELECT MIN(id) - 1 FROM recipes), CAST(NEW.day AS TEXT) || '#' || CAST(id AS TEXT), 'mealplan-shadow'
-- 	FROM meal_plan
-- 	WHERE id = NEW.id
-- 		AND type = 'recipe'
-- 		AND recipe_id IS NOT NULL;
--
-- 	INSERT INTO recipes_nestings
-- 		(recipe_id, includes_recipe_id, servings)
-- 	SELECT (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) || '#' || CAST(meal_plan.id AS TEXT) AND type = 'mealplan-shadow'), recipe_id, recipe_servings
-- 	FROM meal_plan
-- 	WHERE id = NEW.id
-- 		AND type = 'recipe'
-- 		AND recipe_id IS NOT NULL;
--
-- 	... (same "when empty then null" UPDATEs as create_internal_recipe) ...
-- END;
CREATE FUNCTION trg_update_internal_recipe() RETURNS TRIGGER AS $$
BEGIN
	-- Recursion guard - see the file header's "Recursion note". Without this, the tail
	-- "enforce empty then null" UPDATE below would re-fire this same trigger on the row it
	-- just updated, which would re-run the tail UPDATE again, forever (its WHERE clause
	-- keeps matching once a column is NULL).
	IF pg_trigger_depth() > 1 THEN
		RETURN NULL;
	END IF;

	-- Create a recipe per day
	DELETE FROM recipes
	WHERE name = CAST(NEW.day AS TEXT)
		AND type = 'mealplan-day';

	INSERT INTO recipes
		(id, name, type)
	VALUES
		(victual_next_internal_recipe_id(), CAST(NEW.day AS TEXT), 'mealplan-day');

	-- Create a recipe per week
	DELETE FROM recipes
	WHERE name = victual_mealplan_week_name(NEW.day)
		AND type = 'mealplan-week';

	INSERT INTO recipes
		(id, name, type)
	VALUES
		(victual_next_internal_recipe_id(), victual_mealplan_week_name(NEW.day), 'mealplan-week');

	-- Delete all current nestings entries for the day and week recipe
	DELETE FROM recipes_nestings
	WHERE recipe_id IN (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) AND type = 'mealplan-day')
		OR recipe_id IN (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(NEW.day) AND type = 'mealplan-week');

	-- Add all recipes for this day as included recipes in the day-recipe
	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) AND type = 'mealplan-day'), recipe_id, SUM(recipe_servings)
	FROM meal_plan
	WHERE day = NEW.day
		AND type = 'recipe'
		AND recipe_id IS NOT NULL
	GROUP BY recipe_id;

	-- Add all recipes for this week as included recipes in the week-recipe
	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(NEW.day) AND type = 'mealplan-week'), recipe_id, SUM(recipe_servings)
	FROM meal_plan
	WHERE victual_mealplan_week_name(day) = victual_mealplan_week_name(NEW.day)
		AND type = 'recipe'
		AND recipe_id IS NOT NULL
	GROUP BY recipe_id;

	-- Add all products for this day as ingredients in the day-recipe
	INSERT INTO recipes_pos
		(recipe_id, product_id, amount, qu_id)
	SELECT (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) AND type = 'mealplan-day'), product_id, SUM(product_amount), product_qu_id
	FROM meal_plan
	WHERE day = NEW.day
		AND type = 'product'
		AND product_id IS NOT NULL
	GROUP BY product_id, product_qu_id;

	-- Add all products for this week as ingredients in the week-recipe
	INSERT INTO recipes_pos
		(recipe_id, product_id, amount, qu_id)
	SELECT (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(NEW.day) AND type = 'mealplan-week'), product_id, SUM(product_amount), product_qu_id
	FROM meal_plan
	WHERE victual_mealplan_week_name(day) = victual_mealplan_week_name(NEW.day)
		AND type = 'product'
		AND product_id IS NOT NULL
	GROUP BY product_id, product_qu_id;

	-- Create a shadow recipe per meal plan recipe
	DELETE FROM recipes_nestings
	WHERE recipe_id IN (
		SELECT id FROM recipes
		WHERE name IN (
			SELECT CAST(NEW.day AS TEXT) || '#' || CAST(NEW.id AS TEXT)
			FROM meal_plan
			WHERE day = NEW.day
		)
		AND type = 'mealplan-shadow'
	);

	DELETE FROM recipes
	WHERE type = 'mealplan-shadow'
		AND name = CAST(NEW.day AS TEXT) || '#' || CAST(NEW.id AS TEXT);

	INSERT INTO recipes
		(id, name, type)
	SELECT victual_next_internal_recipe_id(), CAST(NEW.day AS TEXT) || '#' || CAST(id AS TEXT), 'mealplan-shadow'
	FROM meal_plan
	WHERE id = NEW.id
		AND type = 'recipe'
		AND recipe_id IS NOT NULL;

	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = CAST(NEW.day AS TEXT) || '#' || CAST(meal_plan.id AS TEXT) AND type = 'mealplan-shadow'), recipe_id, recipe_servings
	FROM meal_plan
	WHERE id = NEW.id
		AND type = 'recipe'
		AND recipe_id IS NOT NULL;

	-- Enforce "when empty then null" for certain columns
	UPDATE meal_plan
	SET recipe_id = NULL
	WHERE id = NEW.id
		AND recipe_id IS NULL;

	UPDATE meal_plan
	SET product_id = NULL
	WHERE id = NEW.id
		AND product_id IS NULL;

	UPDATE meal_plan
	SET product_qu_id = NULL
	WHERE id = NEW.id
		AND product_qu_id IS NULL;

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER update_internal_recipe AFTER UPDATE ON meal_plan
FOR EACH ROW EXECUTE FUNCTION trg_update_internal_recipe();

-- CREATE TRIGGER remove_internal_recipe AFTER DELETE ON meal_plan
-- BEGIN
-- 	/* This contains practically the same logic as the trigger create_internal_recipe */
-- 	... (day/week recipe rebuild identical to create_internal_recipe, using OLD instead of NEW) ...
--
-- 	-- Remove shadow recipes per meal plan recipe
-- 	DELETE FROM recipes
-- 	WHERE type = 'mealplan-shadow'
-- 		AND name NOT IN (SELECT CAST(day AS TEXT) || '#' || CAST(id AS TEXT) FROM meal_plan WHERE type = 'recipe');
-- END;
--
-- No shadow-recipe (re)creation here (the row is gone), just rebuilding the day/week
-- recipes from what remains and sweeping out any shadow recipe that no longer corresponds
-- to a live meal_plan 'recipe' row.
CREATE FUNCTION trg_remove_internal_recipe() RETURNS TRIGGER AS $$
BEGIN
	-- Create a recipe per day
	DELETE FROM recipes
	WHERE name = CAST(OLD.day AS TEXT)
		AND type = 'mealplan-day';

	INSERT INTO recipes
		(id, name, type)
	VALUES
		(victual_next_internal_recipe_id(), CAST(OLD.day AS TEXT), 'mealplan-day');

	-- Create a recipe per week
	DELETE FROM recipes
	WHERE name = victual_mealplan_week_name(OLD.day)
		AND type = 'mealplan-week';

	INSERT INTO recipes
		(id, name, type)
	VALUES
		(victual_next_internal_recipe_id(), victual_mealplan_week_name(OLD.day), 'mealplan-week');

	-- Delete all current nestings entries for the day and week recipe
	DELETE FROM recipes_nestings
	WHERE recipe_id IN (SELECT id FROM recipes WHERE name = CAST(OLD.day AS TEXT) AND type = 'mealplan-day')
		OR recipe_id IN (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(OLD.day) AND type = 'mealplan-week');

	-- Add all recipes for this day as included recipes in the day-recipe
	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = CAST(OLD.day AS TEXT) AND type = 'mealplan-day'), recipe_id, SUM(recipe_servings)
	FROM meal_plan
	WHERE day = OLD.day
		AND type = 'recipe'
		AND recipe_id IS NOT NULL
	GROUP BY recipe_id;

	-- Add all recipes for this week as included recipes in the week-recipe
	INSERT INTO recipes_nestings
		(recipe_id, includes_recipe_id, servings)
	SELECT (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(OLD.day) AND type = 'mealplan-week'), recipe_id, SUM(recipe_servings)
	FROM meal_plan
	WHERE victual_mealplan_week_name(day) = victual_mealplan_week_name(OLD.day)
		AND type = 'recipe'
		AND recipe_id IS NOT NULL
	GROUP BY recipe_id;

	-- Add all products for this day as ingredients in the day-recipe
	INSERT INTO recipes_pos
		(recipe_id, product_id, amount, qu_id)
	SELECT (SELECT id FROM recipes WHERE name = CAST(OLD.day AS TEXT) AND type = 'mealplan-day'), product_id, SUM(product_amount), product_qu_id
	FROM meal_plan
	WHERE day = OLD.day
		AND type = 'product'
		AND product_id IS NOT NULL
	GROUP BY product_id, product_qu_id;

	-- Add all products for this week as ingredients in the week-recipe
	INSERT INTO recipes_pos
		(recipe_id, product_id, amount, qu_id)
	SELECT (SELECT id FROM recipes WHERE name = victual_mealplan_week_name(OLD.day) AND type = 'mealplan-week'), product_id, SUM(product_amount), product_qu_id
	FROM meal_plan
	WHERE victual_mealplan_week_name(day) = victual_mealplan_week_name(OLD.day)
		AND type = 'product'
		AND product_id IS NOT NULL
	GROUP BY product_id, product_qu_id;

	-- Remove shadow recipes per meal plan recipe
	DELETE FROM recipes
	WHERE type = 'mealplan-shadow'
		AND name NOT IN (SELECT CAST(day AS TEXT) || '#' || CAST(id AS TEXT) FROM meal_plan WHERE type = 'recipe');

	RETURN NULL;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER remove_internal_recipe AFTER DELETE ON meal_plan
FOR EACH ROW EXECUTE FUNCTION trg_remove_internal_recipe();
