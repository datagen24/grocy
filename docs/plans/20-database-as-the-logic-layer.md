# 20. Single engine, and the database as the logic layer

**Goal:** decide whether PostgreSQL should stop being *an* engine and become *the* engine
— with SQLite demoted to an import format — and whether the logic this fork keeps adding
belongs in views and functions rather than in PHP.

**Status: concept. Not a commitment, not scheduled, and deliberately not in the wave
order.** Nothing depends on it and it blocks nothing. It exists to be argued with. If it
is ever adopted it becomes a plan and acquires the sequencing this file refuses to claim.

**Relationship to the corpus:** it makes claims on [10](10-cold-start-statelessness.md),
[18](18-mqtt-state-publication.md), [02](02-mcp-endpoint.md), [19](19-rbac.md) and
[01](01-file-storage.md), and it would delete a good deal of what
[db/pgsql/README.md](../../db/pgsql/README.md) documents. Two of its findings apply to
plan 10 *whether or not this concept is ever adopted* — see **Findings that stand on their
own**.

---

## What this is, and what it is not

Two proposals that look separate and are not:

1. **Retire SQLite as a runtime engine.** `DB_DRIVER` stops accepting `sqlite`. The
   SQLite code that survives is `bin/victual-db-import`, reading a source file one time,
   one direction, to move an existing installation across.
2. **Move logic into the database.** Reports, derived state and some write paths become
   views, functions and (sparingly) extensions, rather than PHP orchestrating a
   conversation of small queries.

They are one decision because the second is not seriously available while the first is
false. Every `LATERAL`, every recursive CTE with PostgreSQL's semantics, every generated
column, every JSONB path, every RLS policy and every extension needs either a SQLite
counterpart or a PHP fallback — and then a differential test proving the two agree. The
dual-engine tax is not spread evenly across the codebase. It is concentrated precisely in
the layer proposal 2 wants to grow.

**What this is not:** a rewrite of `StockService`, an argument that PHP is the wrong
language, or a claim that the current architecture is failing. It is not failing. This is
about where the next several years of *added* logic should live.

---

## The mechanism, stated first, because it decides everything else

Scale-to-zero charges for exactly two things:

- **Time to first correct response** on a cold pod.
- **Anything that refuses to let the pod go cold.**

Views and functions barely touch the first. Cold start here is PHP bootstrap — autoload,
Slim, Blade, config, `PrerequisiteChecker` — and that cost is identical whether
`StockService` issues forty queries or one. Published measurements for KEDA-style HTTP
scale-to-zero put the first request after a scale-up at 2–5 seconds, and 15+ where the
image and its init logic are heavy. Against that, saving thirty round trips inside one
household-sized database is noise. **Any version of this concept that sells itself on cold
start latency is overselling.** The honest first-order win is round-trip count against a
connection that was opened milliseconds ago, and at this scale that is a nicety.

It hits the second decisively, and that is the entire prize.

**PostgreSQL is the one component of this deployment that cannot scale to zero.** Today
it holds the data while the app pod holds the knowledge of what the data *means*. That
asymmetry is the whole reason [18](18-mqtt-state-publication.md) exists: Home Assistant
wants to know what is expiring this week, only the sleeping pod can compute it, so it
polls every thirty seconds and the pod never sleeps. The roadmap already states the
circularity — "18 wants 10 to be real, and 10 wants 18 to exist."

Put the shape of that answer in a view and the always-awake component can answer without
the sleeping one. The publisher becomes a scheduled job plus a small bridge, and the app
pod is genuinely off rather than nominally scaled-to-zero and woken twice a minute. That
is an argument about **who is awake**, not about how fast a query runs, and it is the only
argument here strong enough to carry the rest.

---

## Part 1 — retiring SQLite

### What the second engine costs today

Measured against the tree on 2026-08-30:

| | |
|---|---|
| Views maintained on both engines | **45** (`db/pgsql/baseline/0[345]_views_*.sql`) |
| Triggers maintained on both engines | **55**, as 21 PL/pgSQL functions plus their triggers |
| Baseline DDL | **4,893 lines** across 13 files |
| Differential test tooling | **2,086 lines** in `.devtools/pgsql/`, four phases, 5 view-test and 8 trigger-test fixture sets |
| Dialect layer | **1,692 lines** in `services/Database/` |
| Documented porting hazards | **17**, plus 2 accepted behavioural differences |
| Migration files | **257**, of which 0001–0255 are stood in for by the baseline |
| CI | both `pdo_sqlite` and `pdo_pgsql` installed; `postgres:16` service container |

Every new view is written twice and proved equivalent. Every new migration is either
portable or a matched pair, with `@engine-exclusive` and `@overrides-generic` markers and
a `check-migrations.php` guard to catch the cases where someone forgets. That machinery is
good — it was built because the hazards are real — but it is a per-change tax on exactly
the kind of change this fork keeps making.

### What retiring it buys

**Plan 10 gets shorter, and in places turns from "make it conditional" into "delete it."**

| Plan 10 item | Under two engines | Under one |
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
direction, and no longer needing a *write* counterpart or an equivalence proof. Hazards 15
(`COLLATE NOCASE` written into PHP), 16 (`LIKE` case sensitivity) and 17 (LessQL
identifier quoting) are the ones that genuinely die, because all three are about the same
code having to behave on both engines.

**Migrations 0001–0255 become history that never executes anywhere.** They can be archived
rather than maintained. The baseline stops being a "stand-in for" and simply becomes the
schema.

**And the ceiling comes off.** Recursive CTEs with PostgreSQL semantics for
[07](07-nested-products.md) and [08](08-nested-locations.md) — which the roadmap notes
"neither has ever exercised through" the test suite. `bytea` decided on its merits for
[01](01-file-storage.md) rather than as half of a portable pair. RLS for
[19](19-rbac.md). Anything in Part 2.

### What retiring it costs

**You delete your oracle, and this is the cost I would weigh hardest.** SQLite is not
merely a second engine here; it is the *reference implementation*. The claim that
PostgreSQL still behaves like grocy is not an assertion, it is `difftest.php` putting both
engines into an identical table state and comparing what the views return. Retire SQLite
and "the JSON on the wire must not change" becomes something we say rather than something
we run — at precisely the moment Part 2 proposes rewriting the logic behind that JSON.

This is the cheapest thing in the whole concept to mitigate and the easiest to forget:
**retire SQLite as a runtime target while keeping a frozen, migrated SQLite fixture as a
test oracle.** The engine leaves `DB_DRIVER`; the harness stays in `.devtools/`. It costs
one checked-in fixture and the scripts that already exist, and it can be dropped later
once the PostgreSQL views stop changing shape.

**Upstream cherry-picks get harder.** Grocy's logic is PHP over SQLite views. The fork has
already accepted drift, but a single-engine schema widens the gap from "our schema is
grocy's, typed" to "our schema is ours."

**The importer needs a pinned understanding of a schema nobody here runs any more.** Today
`bin/victual-db-import` is exercised by CI against a database this repository produces.
Afterwards it reads a format that only *other* installations produce, which drifts on
someone else's schedule. The mitigation is to pin it: the importer supports grocy/Victual
SQLite at a stated migration number, checked against a committed fixture, and says so when
handed something newer.

**Dev ergonomics.** `run-app` boots on SQLite today. A one-file dev database is genuinely
nicer than a container. This is a real loss and a small one — the compose file already
exists, and CI already runs `postgres:16`.

---

## Part 2 — logic in the database

### Where it helps, honestly ranked

**1. It lets the pod sleep.** The one that matters. A scheduled recompute plus a bridge
publishing to MQTT means [18](18-mqtt-state-publication.md) does not need PHP awake, and
[02](02-mcp-endpoint.md)'s read tools become "select from a view" rather than "wake the
application." This is what makes scale-to-zero real instead of nominal.

**2. It gives [19](19-rbac.md) somewhere to put a field-level rule.** 19's stated problem
is that there is no permission *model* — "thirty constants and a hierarchy view, not one
of which gates a *field*." Price redaction expressed once in a view (or in RLS) is
inherited by the API, MCP and MQTT alike, instead of three channels each remembering to
redact. 19-Q5 and 18-Q8 are both circling this. It is the second-strongest argument here
and it arrives free with the first.

**3. It makes some write paths atomic by construction.** A consume as one statement
survives a pod being terminated mid-request in a way a PHP loop does not — and pods on a
scale-to-zero deployment get terminated at idle boundaries by design.
[13](13-write-path-transactions.md) already bought most of this in PHP, which is why this
ranks third rather than first.

**4. It shrinks what the HTTP layer has to be.** If the read surface is views, the thing
serving MCP or MQTT does not have to be PHP-FPM at all. That is a strategic option rather
than a plan, and it should not be used to justify the work — but it is real, and it is the
kind of option that closes quietly if the logic stays in `StockService`.

### Where it does not help, and saying so plainly

- **Cold start.** See the mechanism section. PHP bootstrap dominates.
- **The HTML pages.** 71 rendered templates and 173 direct `$this->DB->` call sites
  (measured in [14](14-contract-and-regression-scaffolding.md) §2b) still need Blade, the
  baked view cache, and a warm pod. Views do not make a page render.
- **The HTMLPurifier serializer path.** Plan 10's most annoying writable-path problem is
  untouched by any of this.
- **Performance.** At household scale the current queries are fast. Nobody is waiting on
  the database. Do not sell this as a speed-up, and specifically **do not reach for
  materialized views** — a few thousand stock rows is a plain view's territory, and a
  matview is refresh scheduling plus staleness bugs bought with no measured problem.

### What it costs

**Behaviour moves into the deploy path.** Today a bad release is an image rollback. With
logic in views, a bad release is a *schema state*: you can only roll forward, and two
image versions cannot share a database during any overlap. Plan 10-Q6 already wants
fail-fast on a database *ahead* of the code — under this concept that case goes from
theoretical to routine. Views make it sharper still: `CREATE OR REPLACE VIEW` may only
*append* columns. It cannot rename, reorder, retype or drop one. Any real change to a view
is a drop-and-recreate that cascades through five dependency layers, which is exactly the
shape of change a growing report layer produces.

**Failures move further from their causes.** This fork already has the canonical example
written down: an empty seed table made `quantity_unit_conversions_resolved` return
nothing, so `products_ins` copied nothing, and it surfaced as `recipes_pos` rejecting an
ingredient with a message about quantity-unit conversions. "The trigger was faithful; it
had nothing to copy." More logic in views means more of that, and the general industry
verdict on business logic in stored procedures is unkind for exactly this reason —
harder to test, harder to trace, poorly served by version control, and split across two
places so that neither reads as the whole story.

**Two of those four objections are answerable here and one is not.** Version control is a
non-issue: the DDL is already in `db/pgsql/baseline/` and reviewed like code. Testing is
answerable — pgTAP exists, runs in CI, and asserts on functions, views, constraints and
triggers. What is *not* answerable is the split: after this, understanding "what happens
when stock is consumed" means reading two languages in two repositories-worth of
convention, and no amount of tooling fixes that. It is the cost, and it should be paid
deliberately or not at all.

**Extensions pin the hosting, and one pin is load-bearing.** `pg_cron` cannot be enabled
by `CREATE EXTENSION` alone; it must be in `shared_preload_libraries` at server start,
which means a Postgres this project configures — self-hosted, or CloudNativePG with
`postInitSQL`. That is fine for k3s and it forecloses something worth naming: **a
serverless Postgres that itself scales to zero is incompatible with this design.** Neon
documents it directly — pg_cron jobs run only while the compute is awake, so it is
recommended only where scale-to-zero is disabled. If the database ever becomes the thing
that sleeps, the scheduler has to move back out to a k3s CronJob, and the whole "the
always-awake component answers" premise inverts. Decide which component is allowed to
sleep *before* building on the assumption.

**`LISTEN`/`NOTIFY` is best-effort, not a queue.** Notifications are dropped if no
listener is connected, and payloads cap at 8000 bytes with no way to raise it. For an MQTT
bridge that is usually fine — retained topics mean a missed notification is corrected by
the next publish — but it must be *designed* as best-effort. The durable pattern, if 18
ever needs one, is a table drained with `SELECT … FOR UPDATE SKIP LOCKED` with `NOTIFY`
used only to wake the drainer.

---

## Findings that stand on their own

Two things surfaced while researching this that apply to plan 10 **whether or not this
concept is ever adopted**, and they should be lifted out rather than left to die with it
if it is rejected.

**F1. Plan 10's migration lock is a trap under transaction-mode pooling.** 10 specifies
`pg_advisory_lock`, which is session-scoped, and correctly notes it is held on the
connection. If pgbouncer in transaction mode is ever introduced — and it is the obvious
thing to reach for when many short-lived pods each open connections — the unlock can land
on a different backend than the lock, leaking it permanently. `pg_advisory_xact_lock` is
the safe form when the scope fits inside a transaction; a session-mode pool entry is the
alternative when it does not. Plan 10 should say which it is relying on. This is cheap to
record now and expensive to discover later.

**F2. The same pooling mode breaks `LISTEN`.** `NOTIFY` survives transaction pooling
because it is a single statement; `LISTEN` does not, because it needs a stable session.
Any bridge built for 18 either connects directly or through a `pool_mode = session`
entry. Worth writing into 18 before something is built against a pooler that is not there
yet.

---

## What it would do to each affected plan

| Plan | Effect |
|---|---|
| [10](10-cold-start-statelessness.md) cold start | Materially shorter — see the table in Part 1. Q3 and Q7 disappear; Q6 simplifies. F1 applies regardless. |
| [18](18-mqtt-state-publication.md) MQTT | The point of the whole concept. Publication stops requiring an awake pod. Its Q8 (prices on retained topics) is answered by 19-in-views rather than deferred. |
| [02](02-mcp-endpoint.md) MCP | Read tools become view selects. Does not change the interface spec; changes what has to be running to serve it. |
| [19](19-rbac.md) RBAC | Gains a field-level enforcement point it currently lacks. Also gains a genuinely hard question about carrying identity into the connection — see Q4 below. |
| [01](01-file-storage.md) file storage | Simplifies: one engine's large-object story, decided on merits. |
| [07](07-nested-products.md), [08](08-nested-locations.md) | Recursive CTEs written once against known semantics. |
| [14](14-contract-and-regression-scaffolding.md) | Piece 2's snapshot is unaffected — the wire contract does not change. The differential suite's *purpose* changes from "prove two engines agree" to "prove this engine still agrees with the frozen oracle." |
| [15](15-deliberate-cleanup.md) | Absorbs a large deletion batch. |

---

## The strongest arguments against, in order

1. **It deletes the oracle at the moment the oracle is most needed.** Mitigable, and the
   mitigation must be part of the deal rather than a later idea.
2. **The split-brain cost is unfixable by tooling.** Two languages, two mental models, one
   behaviour.
3. **Rollback stops being an image swap.** On a single-replica household deployment this
   is mild; it is still a real property being given up.
4. **The prize is smaller than it sounds if the pod does not actually sleep for other
   reasons.** Health-check probes, log shippers and metrics scrapers keep pods awake just
   as effectively as Home Assistant does. **Before any of this: measure whether the pod
   would sleep with 18 alone.** If something else is holding it awake, the whole
   justification narrows to the tax-reduction argument in Part 1 — which is a decent
   argument, but a much smaller one.
5. **It is a lot of work for one household.** The tax being paid is real but it is paid in
   small increments, and small increments are exactly the kind of cost that feels worse in
   aggregate than it is per change.

---

## A staged shape, if it is ever adopted

Deliberately ordered so that each stage is useful alone and the expensive, irreversible
part comes last.

1. **Measure the premise.** Deploy 18 as designed and observe whether the pod sleeps. If
   it does not, stop and fix that first.
2. **Retire SQLite from `DB_DRIVER`, keep the oracle.** Freeze a migrated SQLite fixture,
   keep `.devtools/pgsql/` running against it, delete the dual-engine authoring tax. Pin
   the importer. This stage is independently worthwhile and does not commit to Part 2.
3. **Reads into views.** Additive, reversible, and where the payoff is. Start with the
   payload 18 publishes and the shapes 02 needs.
4. **19's redaction into those views.** Once there is a view layer, put the rule in it.
5. **Writes into functions — selectively, or never.** Only where atomicity is the point.
   There is no scale-to-zero argument for moving the rest, and 13 already banked most of
   the benefit.
6. **Extensions — minimally.** `pg_cron` and `pg_notify` earn their place because they buy
   the sleeping pod directly. Nothing more exotic without a specific reason.

Stages 2 and 3 are separable, and stage 2 is the one I would defend on its own merits.

---

## Open questions

Numbered for review in the usual way. Leans are stated; none has been reviewed.

1. **Does the pod actually sleep once 18 exists?** Everything here rests on it. Home
   Assistant is the *known* poller, not necessarily the only one — liveness and readiness
   probes, any metrics scrape, and a log shipper all count. *Lean: this must be measured
   before anything else in this file is taken seriously, and it is cheap to measure.*

2. **Is the SQLite oracle kept, and for how long?** *Lean: kept, as a frozen fixture, with
   no expiry date set at the time of retirement — revisit once the view layer stops
   changing shape. Setting an expiry now would be guessing.*

3. **Which component is allowed to sleep — the pod, or the database?** They are mutually
   exclusive under a `pg_cron`-based design. *Lean: the pod. The database is small, always
   cheap at this scale, and being the always-awake component is what makes it useful here.
   But this is the question that forecloses managed/serverless Postgres, and it should be
   answered out loud rather than by accident.*

4. **How does user identity reach the database if redaction lives in views?** `SET LOCAL`
   plus `current_setting` inside a transaction, or per-user database roles. The literature
   is unambiguous that plain `SET` on a pooled connection is the standard way tenants leak
   into each other, and that an application connecting as the table owner silently bypasses
   unforced RLS. *Lean: `SET LOCAL` inside the request's transaction, application role
   non-owner and without `BYPASSRLS`, `FORCE ROW LEVEL SECURITY` on the gated tables. But
   this interacts with F1/F2 and with LessQL's connection handling, and it is the single
   most likely place for this concept to produce a security bug rather than a cleanup.*

5. **Views through LessQL, or raw SQL?** The read layer is `morris/lessql` (berrnd's
   fork), and hazard 17 is already about LessQL's identifier quoting. A view layer that
   LessQL cannot address naturally is a view layer read by hand-written SQL, which changes
   what 14's contract tests are testing. *Lean: unresolved, and it deserves a spike before
   stage 3 rather than a guess here.*

6. **Does the MQTT bridge live in the database, beside it, or in the app?** `pg_cron` +
   `pg_notify` + a small listener is one shape; a k3s CronJob that selects and publishes is
   another and needs no extension at all. *Lean: the CronJob shape first. It needs no
   `shared_preload_libraries`, keeps Q3 open rather than answering it by accident, and can
   be replaced by the in-database version later without changing what is published.* This
   lean weakens the "extensions" half of the concept considerably, and that is deliberate
   — the views are what matter; the scheduler is an implementation detail that should be
   chosen last.

7. **Is the write path in scope at all?** *Lean: no, beyond two or three operations where
   atomicity is the argument. Framing this concept as "reports and reads into the
   database" rather than "logic into the database" would make it a much easier decision
   and lose very little.*

8. **What happens to `run-app` and the demo mode?** Both assume SQLite today. *Lean: they
   move to the compose Postgres, which costs a slower first boot and nothing else. Worth
   confirming rather than assuming — a demo that needs a container is a different thing
   from a demo that needs a file.*

---

## Effort

**Not estimated, on purpose.** Stage 2 alone is plausibly a medium plan of its own —
mostly deletion, but deletion with an importer to pin and a fixture to freeze. Stage 3 is
open-ended by construction: it is as large as the set of reports moved. Anything past
stage 4 should be re-argued rather than scheduled from here.

What *is* worth saying about effort: the verification cost dominates the implementation
cost, exactly as it does in [10](10-cold-start-statelessness.md). Every stage above wants
a booted instance and, in stage 2's case, a frozen fixture that does not exist yet.

---

## Research notes and sources

External claims in this document, with where they came from. Tree measurements are from
the working copy on 2026-08-30 and are reproducible with `grep` against
`db/pgsql/baseline/` and `.devtools/pgsql/`.

- **`CREATE OR REPLACE VIEW` may only append columns** — cannot rename, reorder, retype or
  drop. [PostgreSQL: CREATE VIEW](https://www.postgresql.org/docs/10/sql-createview.html),
  and the reasoning in
  [a -hackers thread on renaming view columns](https://www.postgresql.org/message-id/16099.1572530064%40sss.pgh.pa.us).
- **`pg_cron` requires `shared_preload_libraries`**; `CREATE EXTENSION` alone fails.
  [citusdata/pg_cron](https://github.com/citusdata/pg_cron),
  [CloudNativePG PostgreSQL configuration](https://cloudnative-pg.io/documentation/1.16/postgresql_conf/),
  [CloudNativePG discussion #1173](https://github.com/cloudnative-pg/cloudnative-pg/discussions/1173).
- **`pg_cron` is incompatible with a database that scales to zero** — jobs run only while
  the compute is awake. [Neon: the pg_cron extension](https://neon.com/docs/extensions/pg_cron),
  [Neon: scale to zero](https://neon.com/docs/introduction/scale-to-zero).
- **Session advisory locks are unsafe under transaction pooling** (F1); **`LISTEN` does not
  work in transaction mode while `NOTIFY` does** (F2).
  [PgBouncer is useful, important, and fraught with peril](https://jpcamara.com/2023/04/12/pgbouncer-is-useful.html),
  [pgbouncer issue #976](https://github.com/pgbouncer/pgbouncer/issues/976),
  [PgBouncer modes](https://podostack.com/p/postgres-connection-pooling-pgbouncer-modes).
- **`LISTEN`/`NOTIFY` is non-durable with an 8000-byte payload cap**; the durable pattern is
  a table drained with `SKIP LOCKED`.
  [PostgreSQL: NOTIFY](https://www.postgresql.org/docs/current/sql-notify.html),
  [Beyond LISTEN/NOTIFY](https://www.stacksync.com/blog/beyond-listen-notify-postgres-request-reply-real-time-sync).
- **RLS pooling footguns** — `SET` versus `SET LOCAL`, owner roles bypassing unforced RLS,
  and the indexing cost.
  [Postgres RLS in practice](https://queryplane.com/blog/postgres-row-level-security-in-practice/),
  [RLS for multi-tenancy: the pattern and the footguns](https://patotski.com/blog/postgres-row-level-security-multi-tenant/).
- **Scale-to-zero cold start is 2–5s, and more with heavy init** — and health probes are a
  common reason pods never sleep at all.
  [Running HTTP services on Kubernetes with KEDA scale-to-zero](https://medium.com/@swenushika/running-http-services-on-kubernetes-with-keda-scale-to-zero-lessons-from-production-e35f0df11fc8),
  [Your health checks are quietly killing scale-to-zero](https://bex.co/blog/2026/08/23/health-checks-defeating-scale-to-zero-paas).
- **pgTAP** covers functions, views, constraints and triggers, and runs in CI — the answer
  to "database logic cannot be unit tested." [pgTAP](https://pgtap.org/),
  [theory/pgtap](https://github.com/theory/pgtap).
- **The general case against logic in stored procedures** — testing, tracing, versioning,
  and the split-brain problem.
  [Drawbacks of stored procedures](https://dusted.codes/drawbacks-of-stored-procedures),
  [Stop writing business logic in stored procedures](https://nkdagility.com/resources/blog/stop-writing-business-logic-in-stored-procedures/).
