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
| 0258 | [plan 01](../docs/plans/01-file-storage.md) — the files table (PR #34) | **claimed, not in this tree** |
| 0259 | [plan 18](../docs/plans/18-mqtt-state-publication.md) — `outbox` | in this tree |

## The merge order this implies

    #33 (boot check)  →  #34 (0258, files)  →  #36 (0257 + 0259, plan 18)

#34 before #36 is the part this file enforces. #33 before either is a separate requirement
and is enforced by review rather than here: it is what makes the boot check verify the
complete required migration set instead of the maximum recorded number, so that a database
which did slip through with a hole is refused rather than reported current. Nothing in
`migrations/` can check that, because it is a property of the checking code and not of the
files.

Note that 0257 and 0259 are both plan 18's while 0258 is not. That is not a mistake and is
not fixable by renumbering within one branch: 0258 was claimed by plan 01 while plan 18's
first migration was already written, and moving plan 18's second migration down to 0258
would collide rather than close the hole.

[Plan 01](../docs/plans/01-file-storage.md) still calls its migration `0257.pgsql.sql` in its
own body, written before plan 18 took 0257. The number it actually ships is 0258, and this
table is the authority on that. The [roadmap](../docs/plans/README.md) carries the same
correction as a note; plan 01's own text is left to the branch that carries the file, so the
correction and the file land together.
