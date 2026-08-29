# PostgreSQL support

Victual stores its data in SQLite by default. This directory holds what a PostgreSQL
installation needs instead.

## Layout

    baseline/01_tables.sql        37 tables
    baseline/02_indexes.sql       11 indexes
    baseline/03_functions.sql     victual_user_setting() and its defaults table
    baseline/03_views_group*.sql  views with no dependencies on other views
    baseline/04_views_*.sql       views layered on top of those
    baseline/06_triggers_*.sql    the SQLite triggers as PL/pgSQL

`baseline/` is DDL only. Together with `services/Database/InitialDataSeeder.php` it
reaches the state SQLite reaches after migrations 0001-0255 - schema and rows both.
PostgreSQL installations load the pair once instead of replaying a migration history they
were never part of; `DatabaseMigrationService` then records migrations 1-255 as applied
and continues from 0256 onwards.

## Both halves, or the database is not usable

The seeder is not an optional extra. A third of the migrations the baseline stands in for
insert rows as well as changing the schema: `0027.php` creates the admin account,
`0031.php` the default quantity units and location, `0062`/`0063` the default shopping
list, `0110.sql` the thirty-row permission hierarchy, `0149.sql` the internal meal plan
section. Recording them as applied without running them leaves a PostgreSQL database that
migrates successfully, reports itself up to date, and has nobody who can log into it.

It also degrades quietly rather than loudly. With no rows in `quantity_units`, the final
join in `quantity_unit_conversions_resolved` matches nothing, so the view is empty for
every product, so `products_ins` copies nothing into
`cache__quantity_unit_conversions_resolved`, and anything resolving a quantity unit fails
somewhere far from the cause - the report that led here was `recipes_pos` rejecting an
ingredient with "Provided qu_id doesn't have a related conversion for that product". The
trigger was faithful; it had nothing to copy.

Two consequences worth knowing about the seed data:

- **It has to be PHP, not SQL in `baseline/`.** Four of the six names are translated
  through `LocalizationService` into `VICTUAL_DEFAULT_LOCALE` (Piece, Pack, Fridge,
  Shopping list) and the admin password is hashed with a fresh Argon2id salt per
  installation. None of that can be a literal in a `.sql` file.
- **Some of the ids are historical accidents, reproduced deliberately.** `0006.sql` seeds
  a placeholder location and quantity unit which `0021.sql` deletes, so the real defaults
  land at location 2 and quantity units 2 and 3 with nothing at id 1. That gap is load
  bearing: `migrations/8888.php` creates a location with the literal id 1 when
  `FEATURE_FLAG_STOCK_LOCATION_TRACKING` is off, and would find id 1 already taken if
  PostgreSQL had numbered "Fridge" from 1.

## Supported ways to get a PostgreSQL database

Both, and they are checked:

    php bin/victual-migrate          a new installation, from nothing
    php bin/victual-db-import        an existing SQLite installation, moved

`bin/victual-db-import` calls `MigrateDatabase(false)` - schema, no seed - because it is
about to fill the database from the source and every seeded row would be one it replaces.
That also keeps its "target already contains data" check meaning what it says. Migrating
first and importing afterwards therefore needs `--force`, and the error message says so.

**Every migration from 0256 on has to leave both engines correct.** Write a portable
`migrations/0256.sql`, or a pair of `migrations/0256.sqlite.sql` and
`migrations/0256.pgsql.sql` - an engine specific file wins over a generic one with the
same number.

There is a third case, and it needs to be a deliberate one: a migration that applies to a
single engine because the other genuinely needs no change. `0256.sqlite.sql` is the first,
fixing a SQLite-only type defect that PostgreSQL never had. Ship one only when you can say
in the file why the other engine is already correct, and say it there rather than here —
literally, with an `@engine-exclusive` comment, because `.devtools/pgsql/check-migrations.php`
refuses a lone engine-specific file that does not carry one. A missing counterpart and a
deliberate omission look identical in a directory listing; the marker is what tells them
apart.

The same script rejects an engine-specific file that silently shadows a portable one of the
same number. Overriding is still legal — the loader prefers the specific file — but it has
to say `@overrides-generic`, because left implicit it means one engine never runs the
portable migration while both record the same number. With only two engines, a complete
per-engine pair is usually the clearer way to write that anyway.

The runtime loader enforces the other half: a migration whose name does not parse, or whose
suffix is not a real driver, now aborts the migration run instead of being skipped in
silence. `0256.sqlight.sql` used to be a file that ran nowhere and told nobody.

The consequence to keep in mind is that the two engines then sit at different migration
numbers while both being fully migrated, so nothing may compare one engine's number to the
other's. `DatabaseMigrationService::GetLatestMigrationNumber()` takes a dialect for this
reason; use it rather than assuming the highest file in `migrations/` applies everywhere.

## Testing a change

Loading cleanly proves very little. The suite is one command:

    .devtools/pgsql/run-tests.sh [migrate|views|triggers]

Four phases. `migratedifftest.php` migrates a database on each engine, touches neither
afterwards, and compares every table - that is the equivalence claim above, written as a
test, and it is the phase the missing seed data would have failed. The other three all
populate PostgreSQL by copying an already-migrated SQLite database, which is why none of
them could ever have caught it.

`.devtools/pgsql/difftest.php` puts both engines into an identical table state and
compares what their views actually return:

    docker run --rm --network victualnet \
      -v "$PWD":/app -v /path/to/scratch:/scratch -v /path/to/scratch/data:/data \
      -e DIFFTEST_SQLITE_DSN=sqlite:/data/difftest.db \
      -e DIFFTEST_PGSQL_DSN='pgsql:host=victual-pg;port=5432;dbname=victual_full' \
      victual-dev php /app/.devtools/pgsql/difftest.php seed.sql <view> [<view> ...]

It seeds SQLite only, so SQLite's triggers fire, then copies the resulting tables into
PostgreSQL. That isolates view logic from trigger behaviour.

---

# Porting rules

Authoritative reference for porting Victual's schema. Every rule below exists because
breaking it changes what the REST API returns, which would break the iOS app and the
Home Assistant integration. **API compatibility is the hard constraint.**

Target: PostgreSQL 13+ (tested on 17).

## The overriding rule: the JSON on the wire must not change

Victual serialises rows straight to JSON with `json_encode`. So the PHP type PDO produces
for each column *is* the API contract. Verified empirically:

| Postgres type       | PHP type from PDO | JSON        | Verdict |
|---------------------|-------------------|-------------|---------|
| `SMALLINT`          | `integer`         | `1`         | use for 0/1 flags |
| `INTEGER`           | `integer`         | `1`         | use for ids, counts, day counts |
| `NUMERIC(15,2)`     | **`string`**      | **`"2.50"`**| **NEVER USE** - breaks `type: number` |
| `DOUBLE PRECISION`  | `double`          | `2.5`       | use for all amounts/prices |
| `TIMESTAMP`         | `string`          | `"2026-08-26 00:56:15"` | matches SQLite |
| `BOOLEAN`           | `bool`            | `true`      | **NEVER USE** - spec says `type: integer` |

## Type mapping

| SQLite | PostgreSQL | Notes |
|---|---|---|
| `INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT UNIQUE` | `INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY` | `BY DEFAULT`, not `ALWAYS` - Victual inserts explicit ids |
| `INTEGER` / `INT` | `INTEGER` | |
| `TEXT` | `TEXT` | |
| `TINYINT` **with** `CHECK(x IN (0,1))` or `(1,2)` etc. | `SMALLINT` + same CHECK | |
| `TINYINT` used as a flag, no CHECK | `SMALLINT` | |
| `TINYINT` holding an **id** | `INTEGER` | see hazard 1 |
| `DATETIME` | `TIMESTAMP` | |
| `DATE` | `DATE` | |
| `DECIMAL(p,s)` | `DOUBLE PRECISION` | never NUMERIC |
| `REAL` | `DOUBLE PRECISION` | |
| `DEFAULT (datetime('now', 'localtime'))` | `DEFAULT date_trunc('second', LOCALTIMESTAMP)` | |

### Hazard 1: `TINYINT` columns that are actually ids
SQLite's `TINYINT` has INTEGER affinity, i.e. 64-bit. PostgreSQL's `SMALLINT` caps at
32767. `chores.product_id` is declared `TINYINT` upstream but holds a product id, so it
**must** become `INTEGER` or installs with many products break.

### Hazard 2: `INTEGER` columns that actually hold fractions
SQLite is loosely typed - an `INTEGER` column happily stores `2.5`. `products.min_stock_amount`
is declared `INTEGER` yet the OpenAPI spec says `type: number`, and a live database really
does contain `2.5` there. PostgreSQL would round or reject.

Rule: **amount / servings / quantity / price / weight / factor -> `DOUBLE PRECISION`**,
regardless of what SQLite declares. Day counts, sort numbers, ids and flags stay `INTEGER`.

Known cases: `products.min_stock_amount`, `products.calories`, `recipes.base_servings`,
`recipes.desired_servings`, `meal_plan.recipe_servings`.

## Function and expression mapping

| SQLite | PostgreSQL |
|---|---|
| `IFNULL(a, b)` | `COALESCE(a, b)` |
| `datetime('now', 'localtime')` | `date_trunc('second', LOCALTIMESTAMP)` |
| `datetime(x)` | `x::timestamp` |
| `date('now', 'localtime')` | `CURRENT_DATE` |
| `date(x)` | `x::date` |
| `strftime('%Y-%m-%d', x)` | `to_char(x::timestamp, 'YYYY-MM-DD')` |
| `strftime('%s', x)` | `EXTRACT(EPOCH FROM x::timestamp)` |
| `julianday(a) - julianday(b)` | `EXTRACT(EPOCH FROM (a::timestamp - b::timestamp)) / 86400.0` |
| `group_concat(x, sep)` | `string_agg(x::text, sep)` |
| `instr(haystack, needle)` | `position(needle IN haystack)` |
| `IIF(c, a, b)` | `CASE WHEN c THEN a ELSE b END` |
| `x ISNULL` / `x NOTNULL` | `x IS NULL` / `x IS NOT NULL` |
| `substr`, `ceil`, `abs`, `round`, `min`, `max`, `length` | same, native |
| `CAST(x AS INT)` | `CAST(x AS INTEGER)` |

### Hazard 3: boolean expressions leak into the output
This is the easiest way to silently break the API.

In SQLite `SELECT (a > b) AS flag` yields integer `0`/`1`. In PostgreSQL it yields a
boolean, which `json_encode` renders as `true`/`false`.

**Every** projected expression that is a comparison, `AND`/`OR`/`NOT`, `IN`, `EXISTS`,
`IS NULL` or `LIKE` must be wrapped:

```sql
-- WRONG                              -- RIGHT
SELECT p.amount > 0 AS in_stock       SELECT CASE WHEN p.amount > 0 THEN 1 ELSE 0 END AS in_stock
```

Conditions inside `WHERE`, `ON`, `HAVING` and `CASE WHEN` need no wrapping - only values
that end up in the SELECT list.

### Hazard 4: integer division
`5 / 2` is `2` in both engines when both operands are integers. Where SQLite operands were
REAL (and so divided as floats), make sure the Postgres operand really is
`DOUBLE PRECISION` - cast with `::double precision` if unsure.

### Hazard 5: `||` with non-text operands
PostgreSQL needs at least one side to be text. Cast: `x::text || ' ' || y::text`.

### Hazard 6: `GROUP BY` strictness
PostgreSQL requires every non-aggregated select column to appear in `GROUP BY`. SQLite does
not. Add the missing columns; do not wrap them in an aggregate, which would change results.

### Hazard 7: `CAST(x AS INT)` truncates in SQLite but rounds in PostgreSQL
SQLite truncates toward zero; PostgreSQL rounds to nearest. A 30.5 hour gap becomes `30`
on SQLite and `31` on PostgreSQL. Use `CAST(trunc(x) AS INTEGER)` to preserve behaviour.

### Hazard 8: window functions return `bigint`
`ROW_NUMBER()`, `RANK()`, `COUNT()` etc. return `bigint` in PostgreSQL. Wrap in
`CAST(... AS INTEGER)` where the column is a sort number or a count that SQLite produced
as a plain integer.

### Hazard 9: `strftime('%Y-%W', ...)` has no PostgreSQL equivalent
SQLite's `%W` is a Monday-based week number where the days before the year's first Monday
are week `00`. PostgreSQL's `to_char(..., 'WW')` is not Monday-based and `'IW'` is ISO-8601
(which can push a date into the adjacent year). The baseline therefore ships a helper,
`victual_sqlite_percent_w(date) RETURNS INTEGER`, in `03_views_group1.sql`.

**Use that helper - do not reinvent it.** `recipes.name` for `type = 'mealplan-week'` is
written by triggers on `meal_plan` using this same expression and read back by
`meal_plan_internal_recipe_relation`. If the trigger and the view compute the week
differently the join silently stops matching and meal plans quietly break.

### Hazard 10: "dummy id" columns and `GROUP BY`
Several views select a bare `id` column that is not in the `GROUP BY`, with a comment
saying "Dummy, LessQL needs an id column". SQLite tolerates this and picks an arbitrary
row. Do **not** resolve it by adding the column to `GROUP BY` - that changes the view's
row count, which is the one thing that must not change. Use `MIN(x)` instead and leave an
inline comment.

### Hazard 11: scalar subqueries that can return several rows
`(SELECT 1 FROM products WHERE parent_product_id = p.id) IS NOT NULL` works on SQLite,
which silently takes the first row. PostgreSQL raises "more than one row returned by a
subquery used as an expression" and the whole query fails. Rewrite as
`EXISTS(SELECT 1 ...)`. This already bit `products_view`.

### Hazard 12: date-only values in DATETIME columns
SQLite echoes back exactly the string that was stored, so a `DATETIME` column holding
`"2026-08-26"` returns `"2026-08-26"`. PostgreSQL's `TIMESTAMP` normalises it to
`"2026-08-26 00:00:00"`, which changes the API response.

Already handled in the table DDL (`stock.opened_date`, `stock_log.opened_date` and
`tasks.due_date` are `DATE`). If a view exposes another such column and the differential
test shows a `" 00:00:00"` suffix, that is this hazard - report it rather than papering
over it with `to_char`, because the underlying column type is what needs to change.

### Hazard 13: aggregates that return `NUMERIC`
`AVG(integer)` and `EXTRACT(EPOCH FROM ...)` both return `NUMERIC` in PostgreSQL, and
`NUMERIC` reaches PHP as a string. Anything built on them stays `NUMERIC` too, so
`AVG(EXTRACT(EPOCH FROM ...) / 86400.0)` leaks a JSON string. Cast the expression to
`double precision`.

`COUNT()`, `ROW_NUMBER()` and friends return `bigint` - see hazard 8.

### Hazard 14: `SELECT *` over a join with colliding column names
`CREATE VIEW ... AS SELECT * FROM stock s JOIN products_view p ON ...` is rejected by
PostgreSQL when both sides share a column name. SQLite accepts it and disambiguates the
second occurrence by appending `:1`, so the view really does have columns literally named
`id:1`, `location_id:1` and so on - verified against a live database.

Reproduce them exactly with quoted aliases (`AS "id:1"`) and an explicit column list. Do
not "clean this up": the names are part of what the view returns today.

**Only `uihelper_stock_entries` is actually affected.** Establish that empirically before
assuming a view has this problem - fetch a row on SQLite and look at the keys:

    php -r '$p=new PDO("sqlite:victual.db"); print_r(array_keys($p->query("SELECT * FROM <view> LIMIT 1")->fetch(PDO::FETCH_ASSOC)));'

`stock_missing_products` and `uihelper_stock_current_overview` were both wrongly suspected
of it during the port: the first is `SELECT *` over a *derived table* rather than over a
join, and the second lists all 47 of its columns explicitly. Neither has duplicates.

### Hazard 15: `COLLATE NOCASE` is written into the PHP, not just the schema
Victual sorts and compares names case insensitively using SQLite's built in `NOCASE`
collation, spelled directly into its queries - 116 times across eleven PHP files. Almost
all are `ORDER BY` on list pages and API responses; one is a barcode lookup in
`StockService`. PostgreSQL has no such collation and rejects the query outright, so this
breaks ordering nearly everywhere without touching the schema at all.

Rather than rewriting 116 call sites, `03_functions.sql` creates a collation under that
name, so the `COLLATE NOCASE` the PHP already emits resolves to it (identifiers fold to
lower case). No PHP change is needed. Verified that both ordering and the equality
comparison behave the same as SQLite.

It needs a PostgreSQL built with ICU, which the official images are. Without ICU the
migration fails at that statement, which is the right place to find out.

### Hazard 16: `LIKE` is case insensitive on SQLite and case sensitive on PostgreSQL
**Fixed in the dialect - kept here because the shape recurs.** This was the one hazard on
the list that produced a wrong *answer* rather than an error, on a public endpoint, with
nothing to notice it.

SQLite's `LIKE` ignores ASCII case by default (`PRAGMA case_sensitive_like` is off).
PostgreSQL's `LIKE` is case sensitive; `ILIKE` is the case insensitive form. The two engines
therefore disagree on every `LIKE` the application emits, and the application emits it in
exactly one place - `BaseApiController::FilterData()`, for the `~` and `!~` operators of the
generic list filter:

```php
case '~':
    $data = $data->where($matches['field'] . ' LIKE ?', '%' . $matches['value'] . '%');
```

That reaches every `GET /api/objects/{entity}` and everything else routed through
`FilteredApiResponse`. Measured on a three row table (`Milk`, `milk chocolate`, `Butter`)
with `name LIKE '%milk%'`:

| Engine | Rows returned |
|---|---|
| SQLite | `Milk`, `milk chocolate` |
| PostgreSQL | `milk chocolate` |
| PostgreSQL with `ILIKE` | `Milk`, `milk chocolate` |

No error, no log line, no failing view diff - the differential suite drives SQL at each
engine and never enters `BaseApiController`, which is the blind spot
[14](../../docs/plans/14-contract-and-regression-scaffolding.md)'s coverage section was
added to make visible.

**Fixed.** The fix is the one `GetRegexpCondition()` already models:
`GetLikeCondition(string $field, bool $negated)` on the dialect, returning `LIKE` /
`NOT LIKE` on SQLite and `ILIKE` / `NOT ILIKE` on PostgreSQL, with `FilterData` calling it
instead of spelling the operator itself. SQLite's behaviour is the reference and PostgreSQL
now matches it, because SQLite's is what the API has always documented and what any existing
client was written against.

Verified by instantiating both dialects and running the SQL they actually emit against a
real SQLite and a real PostgreSQL 16, on the fixture above plus a `NULL` row:

| Operator | SQLite | PostgreSQL before | PostgreSQL after |
|---|---|---|---|
| `~` | 1, 2 | 2 | 1, 2 |
| `!~` | 3 | - | 3 |

The `!~` row is the one worth having checked rather than assumed: `NOT ILIKE` leaves the
`NULL` name out on both engines, so negation did not quietly become three-valued on one side.

The OpenAPI spec described these operators as "LIKE" and "not LIKE", which was the SQLite
spelling rather than the contract. It now says "contains, case insensitive", which is what
both engines do.

Two things this does **not** fix, recorded so they are not mistaken for it:

- **`~` against a non-text column still diverges.** SQLite coerces, so `?query[]=id~2`
  matches; PostgreSQL has no `~~` or `~~*` operator for `integer` and raises
  `operator does not exist`, reaching the 500 path. That was true of `LIKE` before this
  change and is equally true of `ILIKE` after it - the failure is identical, so this is a
  pre-existing difference rather than a regression, and it is
  [11](../../docs/plans/11-api-error-handling.md)'s to answer with a status code.
- **The suite still cannot see any of this.** The fix landed on the strength of a
  hand-written check, not a regression test, because the differential suite has no phase
  that can express "the same API request returns the same rows on both engines". See
  [14](../../docs/plans/14-contract-and-regression-scaffolding.md).

Do not reach for the `nocase` collation of hazard 15 to solve this. It is nondeterministic,
and PostgreSQL rejects `LIKE` against a nondeterministic collation outright.

### Hazard 17: an identifier that reaches LessQL is quoted, and quoting is case sensitive
`PostgresDialect::GetIdentifierDelimiter()` returns `"` with the comment "Victual's tables
and columns are all lower case, so quoting them is safe". That is true of the schema - all
37 tables, all their columns and all 45 views are lower case, verified by parsing every
migration and the whole baseline - and it is not true of the *inputs*.

LessQL quotes every identifier it is handed:

```php
// Result::orderBy()
$clone->orderBy[] = $this->db->quoteIdentifier($column) . " " . $direction;
```

and `BaseApiController::QueryData()` hands it a request parameter verbatim:

```php
$data = $data->orderBy($parts[0]);   // $parts[0] is ?order=<field>
```

So `?order=Name` becomes `` ORDER BY `Name` `` on SQLite, where backtick quoting still
resolves case insensitively, and `ORDER BY "Name"` on PostgreSQL, where it does not:

    ERROR: column "Name" does not exist
    HINT: Perhaps you meant to reference the column "t.name".

Same request, 200 on one engine and 500 on the other. The frontend never sends `order=`, so
this is reachable only by an API client - which is to say by both of the clients
[17](../../docs/plans/17-ecosystem-clients.md) tracks.

The `query[]` filter is *not* affected, and the reason is worth knowing because it is
accidental: `FilterData` interpolates the field into a raw condition string
(`$matches['field'] . ' = ?'`) rather than passing it as an identifier, so LessQL never
quotes it and PostgreSQL folds it to lower case like any other bare identifier.
`?query[]=Name=Milk` works on both engines. One code path is safe because it builds SQL by
string concatenation and the other is broken because it does the tidier thing.

Whatever fixes this should normalise or reject the identifier rather than widen the quoting,
and rejecting it is also a `400` that [11](../../docs/plans/11-api-error-handling.md) already
wants instead of the current `500`.

## What was checked and found clean

The audit behind hazards 16 and 17 swept the whole tree for case sensitivity differences.
The negative results are recorded here so the next person does not repeat them:

- **No mixed case tables, views or columns exist on either engine.** Every `CREATE TABLE`,
  `CREATE VIEW` and `ALTER TABLE … ADD COLUMN` across all 256 migrations and the baseline was
  parsed; every identifier is lower case. The premise `GetIdentifierDelimiter()` relies on
  holds for the schema.
- **Trigger names are mixed case and it does not matter.** `products_INS`,
  `trg_stock_log_DEL` and the rest are created unquoted, so PostgreSQL folds them, and
  nothing ever names one: `DatabaseImporter::SetTriggersEnabled()` uses
  `ALTER TABLE … DISABLE TRIGGER USER`, which names no trigger at all.
- **`newest_Id`** (`migrations/0054.sql`) is the only mixed case alias in any SQL. It is a
  derived table's output column, joined as `slg.newest_id`, unquoted on both sides, in a
  migration that runs on SQLite only. Harmless, and it would stop being harmless the moment
  someone quoted either spelling.
- **`sl.NAME`** (`StockReportsController.php:118`) is the only upper case column reference in
  PHP. Unquoted, so it folds. Cosmetic.
- **Hazard 15's `nocase` collation does what it claims.** `deterministic = false` makes `=`
  case insensitive, so `StockService`'s barcode lookup matches the same rows as SQLite -
  checked directly, not assumed.
- **The `§` regexp operator agrees across engines.** SQLite's `REGEXP` is backed by
  `mb_ereg`, which is case sensitive; PostgreSQL's `~` is case sensitive. Consistent, and
  `~*` was correctly not used.
- **User defined entity and userfield names are values, not identifiers.** They are compared
  with `=` against a column with no `COLLATE`, so both engines are case sensitive and agree.

## Accepted differences

Two differences are known, deliberate and judged harmless. Do not try to "fix" them.

**Float accumulation order.** `products_average_price.price` can come out as
`4.124499999999999` on SQLite and `4.1245` on PostgreSQL. Summing floating point values in
a different order gives a different last bit; the discrepancy is around 1e-15 and is not
stable on SQLite either. Rounding would change the documented value, so it stands.

The same artifact reaches `uihelper_product_details.average_price`,
`uihelper_stock_current_overview.average_price` and `recipes_resolved.costs` /
`costs_per_serving`, which are computed from it. Of these only `products_average_price` is
in the `ExposedEntity` enum.

**`chores.start_date` where the stored value has no time.** SQLite returns exactly the
string it stored, so a value written as `"2025-01-01"` comes back as `"2025-01-01"`;
PostgreSQL's `TIMESTAMP` renders it `"2025-01-01 00:00:00"`. This one *is* on a public
endpoint (`chores` is in the `ExposedEntity` enum), so it is worth being explicit about.

`DATE` is not an option: the chore form is a datetimepicker with format
`YYYY-MM-DD HH:mm:ss` and the `default_start_date_when_empty` triggers write
`DATETIME('now', 'localtime')`, so real chores genuinely carry a time and `DATE` would
discard it. Anything the UI creates therefore matches exactly on both engines. Only a
date-only string - the demo data generator, or an API client posting one - differs, and
`"2025-01-01 00:00:00"` is the more conformant rendering of the documented
`format: date-time` anyway.

Verified with `trigdifftest.php` that this is the *only* such column left across all 37
tables.

**`qu_factor_*` in `products_view`, `uihelper_stock_entries` and
`uihelper_stock_current_overview` - fixed, no longer a difference.**
`cache__quantity_unit_conversions_resolved.factor` is `TEXT` upstream, so SQLite used to
return the JSON string `"1.0"` where PostgreSQL returns the number `1`. This was recorded
here as accepted on the grounds that none of the three views is in the `ExposedEntity`
enum. That reasoning was too generous: PostgreSQL was already the conforming side (the
OpenAPI spec documents the field as `type: number`) and `uihelper_product_details` had
always wrapped the same expression in `CAST(... AS REAL)`, so one view was conforming and
its siblings were not, for no reason anyone had chosen.

`migrations/0256.sqlite.sql` applies that same cast in `products_view`, which the other
two inherit the columns from. SQLite now returns a number as well. The differential suite
covers all three views and would catch a regression.

**The `migrations` table.** Excluded from `trigdifftest.php`'s table comparison, and no
longer copied by `DatabaseImporter`. It records how a particular database's schema was
built, which is per engine by design: PostgreSQL replaces migrations 0001-0255 with the
squashed baseline, and an engine-exclusive migration such as `0256.sqlite.sql` applies to
one side only. Two fully migrated databases therefore hold different rows here and always
will.

This is why `DatabaseImporter` checks each side against the latest migration for *its own*
engine rather than comparing the two numbers to each other, and why it leaves the target's
migrations table alone. Copying the source's history into the target was harmless only
while the engines happened to number alike; once they do not, a target carrying the
source's numbers would skip a future migration of its own believing it had already run.

## Triggers

Each SQLite trigger becomes a PL/pgSQL function plus a `CREATE TRIGGER`. Naming: function
`trg_<trigger_name>()`, trigger keeps its original name.

| SQLite | PostgreSQL |
|---|---|
| `SELECT CASE WHEN cond THEN RAISE(ABORT, 'msg') END;` | `IF cond THEN RAISE EXCEPTION 'msg'; END IF;` |
| `RAISE(ABORT, 'msg')` | `RAISE EXCEPTION 'msg'` |
| `BEFORE ... WHEN cond` | same, PostgreSQL supports `WHEN` on row triggers |
| `NEW.x`, `OLD.x` | same |

Rules:
- `BEFORE` row triggers must `RETURN NEW` (or `RETURN NULL` to cancel the row).
- `AFTER` row triggers must `RETURN NULL`.
- A SQLite `AFTER INSERT` trigger that fixes up the row it just inserted
  (`UPDATE t SET c = ... WHERE id = NEW.id`) should become a **`BEFORE INSERT`** trigger
  assigning `NEW.c := ...` directly. That avoids recursion and is the idiomatic form.
  Only do this when the trigger touches its own row; leave genuinely cross-table
  `AFTER` triggers as `AFTER`.
- Statements are separated by `;` inside `BEGIN ... END;` in both, but the PL/pgSQL body
  must be dollar-quoted: `AS $$ BEGIN ... END; $$ LANGUAGE plpgsql;`
- Watch trigger firing order: PostgreSQL fires row triggers in **name order**. Where two
  SQLite triggers on the same table/event must run in a particular order, name them so the
  alphabetical order matches.

## Style
- Tabs for indentation, matching the existing migration files.
- Preserve original column order, names, nullability and comments exactly.
- Do not "improve" anything. A faithful port is the goal; behaviour changes are bugs.

## Moving an existing installation

    php bin/victual-db-import [/path/to/victual.db] [--force]

Point `config.php` at the target database first (`DB_DRIVER`, `DB_HOST`, ...). The command
creates the schema if it is not there yet, so an empty PostgreSQL database is a valid
target, then copies every row across.

It refuses to run when the target already holds data (pass `--force` to replace it) and
when the two schemas are at different migration levels - start the old installation once
so it migrates itself up to date first.

A target that `bin/victual-migrate` has already been run against holds data too: the initial
data of a fresh installation. `--force` is the right answer there - the import truncates
before it copies, so nothing is duplicated - but it is deliberately not automatic, because
this command cannot tell those rows from a month of real use.

Triggers are disabled for the duration of the copy. The rows being copied were already
shaped by the source's triggers, so letting the target's fire again would cascade deletes
and recompute derived values a second time.

Two details that are easy to get wrong and are handled here: values are read with
`PDO::NULL_NATURAL`, because the application's usual `NULL_EMPTY_STRING` would turn every
empty string into NULL on the way through (Victual stores an empty name for the internal
meal plan section, which is enough to violate a NOT NULL column); and the generated id
counters are resynced afterwards, since every row arrives with an explicit id.

## Testing triggers

Views are checked by comparing what they return; triggers cannot be, because what they do
is change *other* rows. `.devtools/pgsql/trigdifftest.php` starts both engines from an
identical table state, applies the same statements to each, and then compares every table:

    docker run --rm --network victualnet \
      -v "$PWD":/app -v /path/to/scratch:/scratch -v /path/to/scratch/data:/data \
      -e TRIGTEST_SQLITE_PATH=/data/trigtest.db \
      -e TRIGTEST_PRISTINE_PATH=/scratch/demodata/victual_en.db \
      -e TRIGTEST_PGSQL_DSN='pgsql:host=victual-pg;port=5432;dbname=victual_trig' \
      victual-dev php /app/.devtools/pgsql/trigdifftest.php script.sql [script.sql ...]

A script is plain SQL, one statement per `;` at end of line. To check a constraint that a
trigger is supposed to enforce, precede the statement with

    -- @expect-error qu_id_stock can only be changed

which requires *both* engines to reject it with a message containing that substring. That
is how `RAISE(ABORT, ...)` constraints are verified.

`row_created_timestamp` is excluded from the comparison, since it comes from the clock.

**Internal meal plan recipes accumulate fewer orphan rows.** Victual generates hidden
`recipes` rows of type `mealplan-day`, `mealplan-week` and `mealplan-shadow` from triggers
on `meal_plan`. In SQLite a single `INSERT INTO meal_plan` re-fires `update_internal_recipe`
several more times, minting a new internal recipe id on each pass and abandoning the
previous one, so `recipes_nestings` and `recipes_pos` fill up with rows pointing at
internal recipe ids that no longer exist. The port collapses that to one deterministic
generation.

This is not a difference in what the application can see. Measured after the same meal plan
statements on both engines: the reachable state - rows whose `recipe_id` still resolves - is
identical (27 recipes, 24 `recipes_pos`, 20 `recipes_nestings`, matching on name, type,
servings, product and amount). Only the count of unreachable rows differs, and PostgreSQL
has fewer.

Worth knowing that this is pre-existing upstream behaviour rather than something the port
introduced: Victual's own demo dataset ships with 146 of its 166 `recipes_nestings` rows
already dangling. A client listing `/objects/recipes_nestings` or `/objects/recipes_pos`
therefore sees slightly fewer junk rows on PostgreSQL.
