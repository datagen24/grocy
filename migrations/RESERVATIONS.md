# Migration numbers above the baseline

Every migration number after `DatabaseMigrationService::BASELINE_MIGRATION_ID` (0255) is
claimed here before it is written, and stays claimed after it lands. This is a record
rather than a courtesy: `.devtools/pgsql/check-migrations.php` parses the table below and
fails when the numbers on disk and the numbers claimed here disagree.

**Why the record exists.** Plans are worked in parallel branches, and each one needs a
migration number before any of them merges. Numbers handed out on a branch collide or leave
holes, and a hole is worse than a collision because nothing complains: a database migrated
through a tree that has 0257 and 0259 but not 0258 records `MAX(migration) = 259`, so
anything that asks "is this database at the latest number?" — `GetLatestMigrationNumber()`,
`DatabaseImporter`'s two-sided comparison, plan 10's boot check — is satisfied by a database
that never ran 0258. The migration *runner* is not fooled (it asks per number whether a row
exists, so a 0258 arriving later is applied), but every gate built on the maximum is, and it
is a gate that decides whether a deployment is allowed to serve.

**So the sequence above the baseline has no holes in a mergeable tree.** A branch that
carries 0259 while 0258 lives in another branch is *not independently mergeable*, and the
check says so by name rather than leaving it to be noticed in review. The branch owning the
lower number merges first; alternatively the higher number is moved down at merge time,
which is only safe while no database anywhere has run it.

**A number is retired, never reused.** Once a file has existed under a number in `master`,
that number is spent even if the migration is later reverted — some database somewhere may
have recorded it.

## Claimed numbers

| Number | Owner | State |
|---|---|---|
| 0256 | dual-engine hazard fix (`products_view` `qu_factor_*` cast) | in `master` |
| 0257 | [plan 18](../docs/plans/18-mqtt-state-publication.md) — `mqtt_product_entities`, `mqtt_published_entities` | in this tree |
| 0258 | [plan 01](../docs/plans/01-file-storage.md) — the files table | in `master` (PR #39) |
| 0259 | [plan 18](../docs/plans/18-mqtt-state-publication.md) — `outbox` | in this tree |
| 0260 | [plan 21](../docs/plans/21-frontend-sink-discipline.md) — purify stored rich text that predates the API purifier | in this tree |
| 0261 | [issue #46](https://github.com/datagen24/victual/issues/46) — a total order for `products_last_purchased.price`, and SQLite's integer division in `products_average_price` | in this tree |
| 0262 | [plan 23](../docs/plans/23-storage-classes.md) — `storage_classes`, `locations.storage_class_id` | **claimed, unwritten** |
| 0263 | [plan 22](../docs/plans/22-medication-tracking.md) — `medication_products`, `medication_stock_attributes`, `subjects` | **claimed, unwritten** |
| 0264 | [plan 22](../docs/plans/22-medication-tracking.md) — `regimens`, `regimen_doses`, `administrations`, `storage_excursions` | **claimed, unwritten** |

## The merge order this implies — discharged

    #33 (boot check)  →  #34/#39 (0258, files)  →  #36 (0257 + 0259, plan 18)

**All of it has happened.** #33 landed first, so the boot check verifies the complete
required migration set rather than the highest recorded number. #34's work reached `master`
through #39 — #34 had merged into a branch that was itself already consumed, which is why
0258 appeared to be in `master` before it was — and `migrations/0258.pgsql.sql` is there now.
#36 merged `master` afterwards, so this tree has 0256 through 0259 with no hole, and
`check-migrations.php` passes without `--allow-reserved-holes`.

0260 is a data migration, not a schema one: it is PHP rather than SQL, it adds and alters
nothing, and it runs `StoredHtmlPurifier` over the five columns in
`BaseApiController::HTML_RENDERED_COLUMNS`. It is portable in one file because PDO is, so it
needs no engine pair under [ADR-0004](../docs/adr/0004-engine-specific-migrations.md).

The next migration takes **0265** and claims it here first.

**0262 to 0264 are claimed by drafts and no file exists for any of them yet**, which is this
record working as designed rather than a hole: the rule is that a number is claimed before it
is written, and [22](../docs/plans/22-medication-tracking.md) and
[23](../docs/plans/23-storage-classes.md) are drafts under review. `check-migrations.php` never
sees them — its hole scan runs below the highest number on disk, which is 0261 — so no waiver
is involved and none should be added for them.

They do imply an order once the code starts. **23 owns 0262 and merges before 22**, because 22
depends on it for `locations.storage_class_id`; 22's own two are consecutive and land together
or in order. If 22's work starts first for any reason, the numbers are re-claimed here rather
than swapped on a branch — this table is the authority, not the branch that got there first.

These three replaced an earlier claim of 0261 and 0262, made while `master` was landing 0261
for [#46](https://github.com/datagen24/victual/issues/46). Nothing was written to disk under
the wrong numbers, so the correction cost a table edit — which is the whole argument for
claiming here before writing, demonstrated at the smallest possible scale.

**The waiver stays.** `--allow-reserved-holes` (and `SUITE_ALLOW_RESERVED_HOLES=1`) is not
scaffolding for this one branch: the situation recurs by construction, because parallel plan
branches each need a number before any of them merges, and the roadmap has several waves of
those left. Removing it would not make the check any stricter — a tree with a hole still
fails without it — it would only take away the thing that let this branch run its own suite
for the three rounds it spent waiting, which is the difference between an enforcement and a
wall. It is opt-in, it prints what it waived, and CI does not set it.

Note that 0257 and 0259 are both plan 18's while 0258 is not. That is not a mistake and is
not fixable by renumbering within one branch: 0258 was claimed by plan 01 while plan 18's
first migration was already written, and moving plan 18's second migration down to 0258
would collide rather than close the hole.

[Plan 01](../docs/plans/01-file-storage.md) was written calling its migration
`0257.pgsql.sql`, before plan 18 took 0257; what it ships is `0258.pgsql.sql`, which is now
in `master` and settles the question. Whether that plan's own body still says otherwise is
for a reader of it to check — this table is the authority on the number either way.
