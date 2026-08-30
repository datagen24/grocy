# ADR-0008: PostgreSQL becomes the only runtime engine; SQLite becomes an import format

- **Status: Proposed.** Not accepted, not scheduled, not in the roadmap's wave order.
  Written to be argued with. Would **supersede
  [ADR-0001](0001-postgresql-alongside-sqlite.md)** if accepted.
- **Recorded:** 2026-08-30.
- **Relationship:** [ADR-0009](0009-database-as-the-logic-layer.md) is not viable unless
  this is accepted. This one is defensible on its own and should be judged on its own.
- **Would affect:** [10](../plans/10-cold-start-statelessness.md),
  [01](../plans/01-file-storage.md), [07](../plans/07-nested-products.md),
  [08](../plans/08-nested-locations.md),
  [14](../plans/14-contract-and-regression-scaffolding.md),
  [15](../plans/15-deliberate-cleanup.md).

## Context

[ADR-0001](0001-postgresql-alongside-sqlite.md) bought dual-engine support and, with it, a
standing per-change tax. Measured against the tree on 2026-08-30:

| | |
|---|---|
| Views maintained on both engines | **45** (`db/pgsql/baseline/0[345]_views_*.sql`) |
| Triggers maintained on both engines | **55**, as 21 PL/pgSQL functions plus their triggers |
| Baseline DDL | **4,893 lines** across 13 files |
| Differential test tooling | **2,086 lines** in `.devtools/pgsql/`, four phases, 5 view-test and 8 trigger-test fixture sets |
| Dialect layer | **1,692 lines** in `services/Database/` |
| Documented porting hazards | **17**, plus 2 accepted differences ([ADR-0005](0005-wire-contract-is-the-invariant.md)) |
| Migration files | **257**, of which 0001–0255 are stood in for by the baseline |
| CI | both `pdo_sqlite` and `pdo_pgsql` installed; `postgres:16` service container |

Every new view is written twice and proved equivalent. Every migration from 0256 on is
portable or a matched pair, with the marker discipline of
[ADR-0004](0004-engine-specific-migrations.md) and a `check-migrations.php` guard for when
someone forgets. That machinery is good — it was built because the hazards are real — but
it is a tax on exactly the kind of change this fork keeps making, and the deployment
target has been PostgreSQL-only since the fork began.

## Decision (proposed)

`DB_DRIVER` stops accepting `sqlite`. The SQLite code that survives is
`bin/victual-db-import`, reading a source file one time, in one direction, to move an
existing installation across.

**And — as part of the same decision, not a later idea — a frozen, migrated SQLite fixture
is retained as a test oracle in `.devtools/`.** The engine leaves the runtime; the
differential harness stays.

## Options considered

**A. Keep both engines.** Status quo. The tax is real but paid in small increments, and
small increments feel worse in aggregate than they are per change. Keeps the oracle for
free. Costs: everything below stays true, and every ceiling in *Consequences* stays in
place.

**B. Retire SQLite entirely — runtime and harness.** Cleanest deletion, largest loss.
Rejected in the proposal above, because it deletes the oracle at the moment the oracle is
most needed. See the first consequence.

**C. Retire SQLite as a runtime target, keep it as a test oracle.** The proposal. Gets
the authoring-tax reduction without giving up the equivalence claim, at the cost of one
checked-in fixture and the scripts that already exist. The oracle can be dropped later,
deliberately, once the schema stops changing shape.

## Consequences

### What it buys

**Plan 10 gets shorter, and in places turns from "make it conditional" into "delete it."**

| [Plan 10](../plans/10-cold-start-statelessness.md) item | Under two engines | Under one |
|---|---|---|
| `PrerequisiteChecker` opening `sqlite::memory:` every request | Make it driver-aware | Delete the branch |
| `pdo_sqlite` in the image | Conditional | Gone |
| Q3, where the SQLite `flock` lock file lives | A real question | Moot |
| Q6's boot check | Must be dialect-aware | One number, one comparison |
| Q7's `dialect` column | A migration and a backfill policy | Unnecessary |
| `DatabaseDialect::WithMigrationLock` | Two implementations | One |
| Q4's `MIGRATE_ON_ROOT_REQUEST` | Still wanted | Still wanted, unchanged |

**`SqliteDialect` shrinks rather than vanishing.** `bin/victual-db-import` still reads
SQLite, so the type-coercion knowledge in hazards 1–14 survives — read-only, one
direction, no write counterpart, no equivalence proof. Hazards 15 (`COLLATE NOCASE`
written into the PHP), 16 (`LIKE` case sensitivity) and 17 (LessQL identifier quoting) are
the ones that genuinely die, because all three are about the same code having to behave on
both engines.

**Migrations 0001–0255 become history that never executes anywhere** and can be archived
rather than maintained. The baseline stops being a stand-in and becomes the schema.

**The ceiling comes off.** Recursive CTEs with known semantics for
[07](../plans/07-nested-products.md) and [08](../plans/08-nested-locations.md) — which the
roadmap notes neither plan has ever exercised through the suite. `bytea` decided on its
merits for [01](../plans/01-file-storage.md) rather than as half of a portable pair. And
everything in [ADR-0009](0009-database-as-the-logic-layer.md), which is not seriously
available while this record is unaccepted.

### What it costs

**It deletes the oracle, and this is the cost to weigh hardest.** SQLite is not merely a
second engine here; it is the *reference implementation*. The claim that PostgreSQL still
behaves like grocy is not an assertion — it is `difftest.php` putting both engines into an
identical table state and comparing what the views return. Retire SQLite outright and
[ADR-0005](0005-wire-contract-is-the-invariant.md) becomes something the project says
rather than something it runs. This is the entire reason option C exists and the reason
the fixture is part of the decision rather than a follow-up.

**Upstream cherry-picks get harder.** Grocy's logic is PHP over SQLite views. The fork has
already accepted drift, but this widens it from "our schema is grocy's, typed" to "our
schema is ours."

**The importer needs a pinned understanding of a schema nobody here runs any more.** Today
`bin/victual-db-import` is exercised against a database this repository produces.
Afterwards it reads a format only *other* installations produce, drifting on someone
else's schedule. Mitigation: the importer supports grocy/Victual SQLite at a stated
migration number, checked against a committed fixture, and says so when handed something
newer.

**Dev ergonomics.** `run-app` and the demo mode boot on SQLite today, and a one-file dev
database is genuinely nicer than a container. Real loss, small — the compose file exists
and CI already runs `postgres:16`.

## Open questions

1. **Is the oracle kept, and for how long?** *Lean: kept, with no expiry set at the time of
   retirement — revisit once the schema stops changing shape. Setting an expiry now would
   be guessing.*
2. **What migration number does the importer pin to, and what does it do with a newer
   source?** *Lean: refuse with a message naming both numbers, rather than attempting a
   best-effort import. An importer that half-works on an unknown schema is worse than one
   that declines.*
3. **What happens to `run-app` and the demo mode?** *Lean: they move to the compose
   Postgres, costing a slower first boot and nothing else. Worth confirming rather than
   assuming — a demo that needs a container is a different thing from a demo that needs a
   file.*
4. **Does this change what `.devtools/pgsql/` is for, or just what it runs against?** The
   suite's purpose shifts from "prove two engines agree" to "prove this engine still agrees
   with the frozen oracle." *Lean: the scripts survive unchanged and only the fixture
   provenance changes, but this has not been checked against
   `migratedifftest.php`, which migrates both sides rather than copying one.* That phase
   specifically may not survive, and it is the phase that caught the missing seed data.

## Research

- **`pg_cron` and other extensions are not the argument here** — they belong to
  [ADR-0009](0009-database-as-the-logic-layer.md). This record stands on the authoring tax
  and the plan-10 simplification alone.
- Tree measurements above are reproducible with `grep` against `db/pgsql/baseline/`,
  `.devtools/pgsql/` and `services/Database/`, on the working copy of 2026-08-30.
