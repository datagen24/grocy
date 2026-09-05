# 24. SQLite runtime retirement

**Goal:** PostgreSQL is the only engine a Victual installation can be configured for, and
SQLite is an input format that committed fixtures hold the importer to.
**Depends on:** [ADR-0008](../adr/0008-postgresql-only-runtime-engine.md), accepted
2026-08-31, for the decision. This plan is the retirement work that record's own
"Consequences" describes and its wave 2.5 row in the [work order](README.md) schedules.
**Status:** **landed, 2026-09-05.** See **Executed** below for what shipped and where it
differs from the design above it.

## Today

`DB_DRIVER` accepts `sqlite` and `pgsql`, and `sqlite` is the default — a `config.php`
that says nothing about the database opens a file. Every view exists twice and is proved
equivalent by the differential harness in `.devtools/pgsql/`; every migration from 0256 on
is portable or a matched pair under
[ADR-0004](../adr/0004-engine-specific-migrations.md). The deployment target has been
PostgreSQL since the fork began.

ADR-0008 decided that this stops. What it did not do is say how, and the gap between the
decision and the work is where the interesting part is: the record's own option C keeps the
differential harness through the transition, and the harness *is* a SQLite runtime. A
retirement that deletes the engine deletes the check that the retirement changed nothing.

## What this changes

### The runtime

`DB_DRIVER` accepts `pgsql` and nothing else, and the default becomes `pgsql`. An
installation whose `config.php` still says `sqlite` is refused at startup by
`ConfigurationValidator`, by name rather than through the generic "invalid driver" message
— it was the default until this change, so an operator upgrading meets the check with a
file that was correct the day before, and the message has to say what to do rather than
only that the value is wrong.

The default changing is louder than it looks and is the retirement rather than a side
effect of it. There is no engine left for `sqlite` to mean.

### The migration line

The SQLite line freezes at 0265, its highest migration at retirement time, as
`DatabaseMigrationService::SQLITE_FROZEN_MIGRATION_ID`. Above it a `NNNN.sqlite.sql` is a
file no engine here can run and no importable source can have applied — coverage that looks
like coverage and is none — so `.devtools/pgsql/check-migrations.php` refuses one, and
refuses a freeze constant that no file in the tree reaches. Below it nothing changes: 0256
to 0265 keep the two-engine rules they were written under, because that range is history
and the suite still replays it.

This needs the driver list to split in two. `DatabaseDialect::SUPPORTED_DRIVERS` was doing
two jobs — which engines may be configured, and which suffixes a migration file may carry —
and the retirement makes those different answers. They become `RUNTIME_DRIVERS` and
`MIGRATION_DRIVERS`.

### The import span

`bin/victual-db-import` accepts a source whose recorded schema version is between 0255 and
0265 inclusive and refuses anything outside it, naming both numbers. That is ADR-0008
question 1's answer made executable, and it replaces a check that demanded the source be at
*exactly* the SQLite line's latest migration — which was right while this repository
produced that number and is wrong once the input is a foreign format.

Widening the check to a span opens something the exact check closed by accident, and it is
the substantive part of this piece rather than a detail. The importer migrates the target
*before* copying rows into it, so the migrations that rewrite **rows** rather than schema
run against an empty database and find nothing. Migration 0260's HTML purifier was already
re-applied after the copy for exactly this reason (review finding P1 on #41). Migration
0264's API key hashing was not, and did not need to be: a source at 0265 had run 0264 on its
own side. A source at 0255 has not — 0255 is where upstream grocy stops, and grocy stores
its keys in plaintext — so an import from the lower end of the span would land readable keys
in a target whose every authenticated request hashes what it is given. The keys stop working
silently, and the table that was sweep finding S12's whole subject is a set of live
credentials again.

So the importer applies both after the copy, and `StoredApiKeyHasher` is 0264's rule stated
where a second caller can reach it.

### The demo mode

Demo data generation ran on SQLite and nothing else — `SystemController::Root()` skipped it
on any other driver with a line on stderr — because the generator's raw SQL was SQLite
flavoured. With SQLite gone that branch has no engine to be true on, so a demo instance
would be an empty one.

The generator becomes portable, which is smaller than it sounds: one `DELETE FROM
sqlite_sequence` and a dozen date expressions, replaced by dates computed in PHP and
interpolated as literals. That is the convention `DatabaseService` already documents for
date cut-offs — the engines disagree about date arithmetic and PHP agrees with itself.

It gains one thing it never needed on SQLite: `ResyncGeneratedIdCounters()` after the
reference rows. PostgreSQL's identity columns are `GENERATED BY DEFAULT`, so the explicit
ids the generator inserts do not move the sequence, and the next id the application
generates collides with one of them. SQLite's `AUTOINCREMENT` moved its counter on its own.

This is ADR-0008 question 3, answered by doing it rather than by leaning: the demo moves to
PostgreSQL, and the cost is a database where there was a file.

### What deliberately survives

Three things, and each is a decision rather than an omission.

**`SqliteDialect`, reachable only through an environment variable.** ADR-0008's option C
keeps the differential harness until
[14](14-contract-and-regression-scaffolding.md) piece 2's response snapshot exists, and the
harness builds SQLite databases by running this fork's own migration path. So the dialect
stays constructible — but only when `DIFFTEST_SQLITE_RUNTIME` is set, which
`run-tests.sh` exports once for a whole run. An environment variable rather than a
`Setting()`, deliberately: a setting is exactly the thing being retired, and anything
spelled that way would be a supported way to run this fork on SQLite. It goes when the
harness goes.

**Migrations 0001–0255 stay in `migrations/`.** ADR-0008 says they "can be archived rather
than maintained", and archiving them is the change this plan does not make. The suite
replays them to build its SQLite side, and moving them would mean teaching
`GetRequiredMigrationNumbers()` to synthesise 1–255 for PostgreSQL so the schema gate does
not read a baseline-loaded database as carrying 255 unknown migrations. That is a real
change to the gate that decides whether a deployment serves, made for tidiness, in the same
pull request as the retirement. It belongs to [15](15-deliberate-cleanup.md) or to whatever
retires the harness.

**`pdo_sqlite` in the dev image and in `victual-migrate`.** The importer reads SQLite and
the suite writes it. The serving images already dropped it with plan 10 and stay as they
are.

The dialect seam itself outlives all three, and what happens to it is a separate decision:
[ADR-0017](../adr/0017-doctrine-dbal-is-the-persistence-seam.md) (Proposed) argues that
`DatabaseDialect` stays as a boundary and becomes Doctrine DBAL, so that engine choice is a
structural affordance for a successor rather than a supported feature. That record has to be
decided before [14](14-contract-and-regression-scaffolding.md) piece 2 removes the
differential suite, which is currently the only remaining caller with a second dialect.

## Alternatives

**Retire the harness in the same change** (ADR-0008's option B). Rejected there and again
here for the same reason: it discards, in one change, the one tool that could show the
retirement was behaviour-preserving. It is also no longer only a sequencing argument —
the import fixtures this plan commits were produced by the SQLite migration path, so a
change that removed the path and added the fixtures would be asserting its own output.

**Keep `sqlite` configurable and merely deprecate it.** A deprecation nobody enforces is a
second engine with a warning attached, and every future PostgreSQL-only improvement then
has to argue with it. The whole benefit ADR-0008 claims is the ceiling coming off.

**Make the tooling escape hatch a `Setting()` rather than an environment variable.** Simpler
to wire and wrong: it would appear in `config-dist.php` and be, in every way a reader can
check, a supported configuration.

## Open questions

ADR-0008 left three of its four open questions for the work to answer. They are answered
here rather than there, because a record is not edited into a different decision.

1. **How many import fixtures, and how are they generated?** (ADR-0008 question 2; its lean
   was two.)

   > **Response:** Two, at 0255 and 0265, and the lean's reasoning holds up under building
   > them: the importer's failure modes are schema-shaped rather than version-shaped — a
   > table the source does not have, a column the target gained, a row transformation the
   > target's migration ran before the rows arrived — and every one of those is at its most
   > extreme between the two ends. The 0255 fixture has 36 comparable tables and the 0265
   > fixture 40; a fixture at 0260 would re-ask the same questions with a smaller delta.
   >
   > They are generated by `.devtools/pgsql/fixtures/import/make-fixtures.sh`, which
   > hard-links the tree into a scratch directory, unlinks the migrations above the target
   > number there, and runs the real migration runner against the copy. The runner resolves
   > `migrations/` relative to its own file, so there is no "migrate as far as N" switch to
   > pass it; hard-linking is what makes a per-version tree free and makes it impossible for
   > the script to damage the real one.
   >
   > They are committed as real `.db` files rather than SQL dumps, at about 600 KB each.
   > What the importer knows about SQLite is type affinity (porting hazards 1–14): what a
   > value comes back as depends on how its column was declared and on what was stored in
   > it, and a dump reconstituted through a different writer is one step removed from the
   > thing under test.

2. **What happens to `run-app` and the demo mode?** (ADR-0008 question 3; its lean was that
   they move to the compose PostgreSQL.)

   > **Response:** They move, and the lean understated the work by one item: the demo *data
   > generator* had to become portable first, which the lean did not mention because
   > `SystemController` hid it — a PostgreSQL demo instance already "worked" in the sense of
   > serving pages, while generating nothing. See **The demo mode** above.
   >
   > `run-app` now starts a local cluster rather than assuming a service: on a container that
   > has the PostgreSQL packages, `pg_ctlcluster 16 main start` plus one `CREATE ROLE` is the
   > whole difference, which is a smaller price than "needs a container" suggested.

3. **Which parts of `.devtools/pgsql/` survive the transition, and for how long?**
   (ADR-0008 question 4; its lean was to accept losing `migratedifftest.php`.)

   > **Response:** All of them, including `migratedifftest.php`, and this is the answer that
   > differs from its lean.
   >
   > The lean's reasoning was that `migratedifftest.php` migrates *both* sides from nothing
   > and so needs a live SQLite migration path. It does, and it has one: the tooling
   > environment variable above keeps that path working, because option C needs it for the
   > other phases too — `difftest.php` and the rest populate PostgreSQL from an
   > already-migrated SQLite database, and something has to migrate it. Once the path exists
   > for them, the phase that caught the missing baseline seed data costs nothing to keep,
   > and it is precisely the phase that answers "did this retirement change anything?".
   >
   > Its useful life is finite and shorter than the others'. The freeze means the first
   > PostgreSQL-only migration that changes a *column* will make the two migrated schemas
   > legitimately differ, and this phase will then report a real difference as a failure.
   > A migration that adds a table already has a mechanism —
   > `ENGINE_EXCLUSIVE_TABLES` at the top of the script. When a column change comes, retiring
   > this phase is the right response and is a smaller decision than making it now, blind.
   >
   > Everything in `.devtools/pgsql/` goes when 14 piece 2 lands, which is the ordering
   > constraint ADR-0008 actually has.

## Verification

1. **The differential suite passes, unchanged in what it asserts.** Its SQLite side is now
   built through the tooling escape hatch rather than through a default; if the retirement
   changed behaviour, this is where it shows.
2. **`DB_DRIVER=sqlite` is refused**, with a message naming `bin/victual-db-import`.
3. **The import phase passes**: both fixtures import, the row counts match per table, the
   two row migrations are applied, a second import does not hash a hash, and a source
   outside the span is refused with both numbers named.
4. **A demo instance boots on PostgreSQL and generates data.** Pages, not just a 200: an
   application that fails a prerequisite answers 200 with an error page, which looks like
   success to anything counting status codes.
5. **`check-migrations.php` refuses a `NNNN.sqlite.sql` above the freeze**, and refuses a
   freeze constant no file reaches.
6. **The `frontend-security` job still runs the S29 probe**, against a demo instance that is
   now PostgreSQL-backed.

## Executed — 2026-09-05

All six verification criteria are met. Measured against this working copy, PHP 8.4.19 and
PostgreSQL 16.13.

**The suite passes with its SQLite side built through the escape hatch**, ten phases
including the new `import` one. Two things had to be told where their engine went, and both
failed in a way worth recording: a data directory with no `config.php` used to produce a
SQLite database and now produces an attempt to reach a PostgreSQL server, so
`run-tests.sh`'s five SQLite data directories and `.devtools/mqtt/engine-diff.sh` each gained
an explicit two-line config. `engine-diff.sh` reported that as
`fe_sendauth: no password supplied` — a connection error, several steps away from the cause,
and the argument for a `write_sqlite_config` helper rather than five copies of it.

**Three defects, none of them in the retirement, all of them found by writing the import
phase.**

| # | Defect | Where |
|---|---|---|
| 1 | `bin/victual-db-import` printed its refusals and exited **0**. `exit('...')` with a string argument prints the string and exits zero, so a source file that did not exist, an invalid `config.php` and a target that was itself SQLite each reported success. An operator chaining it with `&&` carried on. | `bin/victual-db-import` |
| 2 | The same command `require`d `data/config.php` unconditionally, so on a deployment configured entirely through `VICTUAL_*` environment variables — which is what `deploy/` describes — the one command an operator reaches for to move their data across failed on a missing file. | `bin/victual-db-import` |
| 3 | `ApplicationService::getSqliteLocaltime()` opened `new PDO('sqlite::memory:')` unconditionally, so `GET /api/system/time` was a fatal "could not find driver" on every image without `pdo_sqlite` — which since plan 10 is every serving image. This is the *same defect* plan 20's verification found in `GetSqliteVersion()` and fixed one method away; it survived because that verification walked the pages and endpoints a browser reaches, and nothing in the UI calls this one. | `services/ApplicationService.php` |

Defect 3 is the one worth carrying forward. The fix for its twin was made with the reasoning
written out in a docblock, and the sibling method four lines below it was not checked. A
grep for the string would have found it; reading the fix did not.

**The demo mode.** Verified by booting it: `bin/victual-migrate` against a fresh PostgreSQL
database, then the built-in server, then `GET /`, which returns 302 after generating 29
products, 83 stock rows, 23 recipes, 12 meal plan entries, 7 tasks, 6 chores and 4 batteries.
All 21 top-level pages and `GET /api/stock` answer 200. The first attempt appeared to work
and had generated nothing: PHP 8.4 is below `REQUIRED_PHP_VERSION`, and a failed prerequisite
answers **200** with an error page, so every page in the walk was that page. Hence
verification criterion 4's second sentence, and a paragraph in the `run-app` skill saying so.

**The fixtures are 580 KB and 632 KB.** That is a real cost — about 15% of the repository's
history — and it is what ADR-0008's acceptance gate asks for. Both are almost entirely
schema: 42 tables, their indexes, views and triggers, over 158 pages of 4 KB.

**What did not change, and should be checked before anyone assumes it did.** The
`ENGINE_EXCLUSIVE_TABLES` list in `migratedifftest.php`, the seventeen porting hazards in
[db/pgsql/README.md](../../db/pgsql/README.md), and hazards 15–17 in particular — ADR-0008
says those three "genuinely die" with the retirement, and they do not die yet, because the
harness that depends on them outlives it. They die with 14 piece 2.
