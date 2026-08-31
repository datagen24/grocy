# Fork roadmap

Action plans for the work this fork exists to do. Most are drafts for review, not
commitments — the **Open questions** sections are numbered, and each carries its review
answer inline as a `> **Response:**` block, so question and answer read together. Some
have since been built; the **Status** column below is the authority on which, and a plan
that has landed carries an **Executed** section recording what actually shipped, including
where it diverged from the plan above it. A landed plan's body is kept in the present
tense it was written in — the Executed section, not the prose, is the record of the code.

## Status

| # | Plan | Upstream | Depends on | Size | Status |
|---|---|---|---|---|---|
| — | [Database abstraction / PostgreSQL](../../db/pgsql/README.md) | — | — | — | **landed** |
| 01 | [File storage in the database](01-file-storage.md) | — | PostgreSQL | small | draft |
| 02 | [MCP endpoint](02-mcp-endpoint.md) ([interface spec](../mcp-interface-spec.md)) | — | 11, 13, 14 piece 2, 15-C1 | medium | draft — body superseded by the spec |
| 03 | [Category level minimum stock](03-category-min-stock.md) | [#2616](https://github.com/grocy/grocy/issues/2616) | — | small | draft — may grow a parent column, per 07-Q6 |
| 04 | [Seed product datasets](04-seed-datasets.md) | [#2679](https://github.com/grocy/grocy/issues/2679) | — | medium | draft |
| 05 | [Store specific shopping lists](05-store-shopping-lists.md) | [#2702](https://github.com/grocy/grocy/issues/2702) | 12 | medium | draft |
| 06 | [Location barcodes](06-location-barcodes.md) | — | 12 | small | draft |
| 07 | [Deeply nested products](07-nested-products.md) | — | — | **large**, or very small | **blocked on its own Q6** |
| 08 | [Deeply nested locations](08-nested-locations.md) | — | 12, 14 | medium | draft |
| 09 | [Barcode lookup sources for US products](09-barcode-lookup-sources.md) | — | — | small | **deferred** |
| 18 | [MQTT state publication](18-mqtt-state-publication.md) | — | 13 (landed) | small | draft — from [17](17-ecosystem-clients.md)'s Q2; **Q1–Q8 answered 2026-08-31** |

| 19 | [Roles and data-visibility permissions](19-rbac.md) | — | wave 2's S5/S6; then 11, 12, 14 (per piece) | medium, **split across two waves** | draft — **blocked on its own Q8** |

## Hardening

Remedial work from [docs/architecture-review.md](../architecture-review.md). The review's
own defects table (items 1–13) is already fixed in `36650cd`; these are everything else it
found, plus the 2026-08-29 [security sweep](../security-sweep.md). They add no features
and block no feature plan, but 12 and 14 should land before the plans noted below start
minting more of what they clean up, and the sweep's four High findings land before
anything at all — **which they did**, on 2026-08-29; see the hotfix in wave 0.5 below.

That sentence was written when the hotfix carried three of the four. S4 was deferred to
wave 2 with the rest of the auth work, which made "all four High findings land first" false
as stated, and the gap was caught in review rather than by the roadmap noticing its own
inconsistency. S4 is now in the hotfix too, so the sentence is true again — see wave 0.5.
The lesson is cheaper than the fix: a deferral that contradicts a stated gate has to change
the gate's wording at the same time, or the wording quietly becomes a claim nobody checks.

There is a third input and it is about the plans rather than the code: the 2026-08-29
[architectural rigor review](../architecture-rigor-review.md), which read the corpus
against the tree and found twenty-nine places where the two had drifted. It gets less
attention here than the sweep does because it produces no code, and that turned out to be
the problem — the sweep's S-numbers are tracked item by item in this file and the rigor
review's were tracked nowhere, so ten of its findings were quietly fixed and eleven
quietly were not, with no way to tell which was which. It now carries [its own status
table](../architecture-rigor-review.md#status-as-of-2026-08-30), re-verified against the
tree, and the open rows are routed to owning plans the same way sweep findings are. Two of
them are routing sentences in *this file* that were never true.

| # | Plan | From | Depends on | Size | Status |
|---|---|---|---|---|---|
| 10 | [Cold start and statelessness](10-cold-start-statelessness.md) | Review §Statelessness, order item 2 | — | medium | draft (its `bin/victual-migrate` landed early, in wave 0) |
| 11 | [API error handling, auth surface and error logging](11-api-error-handling.md) | Review §API surface, order item 3, deferred defect 9 | 14 (soft) | medium | draft |
| 12 | [Frontend shared core](12-frontend-shared-core.md) | Review §Frontend, order item 4, oddities list, **sweep S29** | — | medium | draft — **carries a High finding** since 2026-08-30 |
| 13 | [Write-path transactions](13-write-path-transactions.md) | Review §Services, order item 5 | — | small | **landed** (`7abfd2fa`, `782289b8`, `96f9ec99`) |
| 14 | [Contract and regression scaffolding](14-contract-and-regression-scaffolding.md) | Review §API surface, order item 6 | — | medium | **pieces 1, 3, 4 landed** (wave 0); piece 2 outstanding |
| 15 | [Deliberate cleanup batch](15-deliberate-cleanup.md) | Review §Backend, §Uniformity, parked 05-Q4, sweep S4–S6, S17–S19 | 11, 13, 14 (per item) | small + one large open question | draft |
| — | [Security sweep hotfix](../security-sweep.md) (S1, S2, S3, **S4**, S7, S23, S28, R1) | [docs/security-sweep.md](../security-sweep.md) | — | small | **landed** — see the sweep's [What the hotfix changed](../security-sweep.md#what-the-hotfix-changed) |

## Meta

| # | Plan | Upstream | Depends on | Size | Status |
|---|---|---|---|---|---|
| 16 | [Project rename](16-project-rename.md) | — | before first deployment | medium | **landed in the codebase**; registry/domain claims wait for announcement |
| 17 | [Ecosystem clients](17-ecosystem-clients.md) | — | 14 supplies the mechanism; was to be read before 11 and 16 | small, ongoing | **Q2 and Q4 answered** (2026-08-29); Q1 open, Q3 half — see below |

## Decisions

**Plans describe work; [ADRs](../adr/README.md) describe decisions.** A plan is done when
the code lands and its Executed section records what shipped. A decision outlives the plan
that made it and gets read by people who never open that plan — so it goes in
[docs/adr/](../adr/README.md) and is cited from here, rather than living in a Response
block where only a reader of one plan will find it.

Seven decisions already standing in this codebase are recorded there, backfilled from
[db/pgsql/README.md](../../db/pgsql/README.md) and the
[security sweep](../security-sweep.md). Two more are **proposed and under consideration**,
and neither is in the wave order below:

- **[ADR-0008](../adr/0008-postgresql-only-runtime-engine.md)** — retire SQLite as a
  runtime engine, keep it as an import format behind fixture-based importer tests. Would
  supersede
  [ADR-0001](../adr/0001-postgresql-alongside-sqlite.md) and would materially shorten
  [10](10-cold-start-statelessness.md).
- **[ADR-0009](../adr/0009-database-as-the-logic-layer.md)** — move report and read logic
  into views, so the always-awake component can answer without waking the pod. Depends on
  0008. Claims on [18](18-mqtt-state-publication.md), [02](02-mcp-endpoint.md) and
  [19](19-rbac.md).

Two findings recorded in 0009 apply to [10](10-cold-start-statelessness.md) and
[18](18-mqtt-state-publication.md) **whether or not either proposal is accepted**: 10's
`pg_advisory_lock` is session-scoped and unsafe under transaction-mode connection pooling,
and `LISTEN` does not survive that pooling mode either. If both proposals are rejected,
those two are lifted into their owning plans rather than discarded with them.

The fork is **Victual**. Tiers 1–3 of 16 all landed while nothing was deployed,
so `GROCY_*` is `VICTUAL_*`, the namespace is `Victual\`, the database file is
`victual.db`, the bin scripts are `bin/victual-*`, the spec is
`victual.openapi.json`, and `GET /api/system/info` answers `victual_version`.
Anything written from here forward uses those names. What is *not* renamed, ever,
is the `grcy:` grocycode magic and the format's name — see 16's Tier 0. The repo
rename and the registry claims happen at announcement time, not in a commit.

**Blocking and de-risking, in one place:**

- **12 before [05](05-store-shopping-lists.md), [06](06-location-barcodes.md),
  [08](08-nested-locations.md) and [19](19-rbac.md)** — each adds a list/form pair, and 12
  is what stops them being copies of the old pattern. 19 is the partial case: its `/roles`
  list is a factory call, but `/role/{id}` is the same checkbox tree as
  `userpermissions.js` bound to a different table, and 19 modifies that file too — so 12's
  partial-clone list grows by one rather than its factory list growing by two.
- **14 before [07](07-nested-products.md) and [08](08-nested-locations.md)** — both plan
  their fixtures against tooling this makes runnable, and neither has ever exercised a
  recursive CTE through it.
- **13 and 11 before [02](02-mcp-endpoint.md)** — 13 before MCP writes, 11 because an
  assistant cannot recover from an API that answers 400 for "not allowed" and 500 for
  "bad filter".
- **14 before 11** — 11 changes status codes across ~74 routes; better shown as a diff
  than asserted by hand.
- **The API's read surface grows before 14 piece 2 freezes it.** Measured in 14's section
  2b: the web UI reads the database directly in 173 places, and eight pages have no API
  path in the shape they render — a stock overview among them. That is a dependency of the
  Swift client [17](17-ecosystem-clients.md) commits to, not a tier-split ambition, and a
  snapshot taken first would freeze an incomplete contract and turn every addition into a
  snapshot change. Two of the gaps are decisions rather than code: exposing
  `products_price_history` widens who can see the household's purchase history, and the
  permissions page's `ADMIN`-versus-`USERS_READ` mismatch may be deliberate.

  **[19](19-rbac.md) is now a contributor to this rule and the answer to both decisions.**
  Its piece 1 adds `/roles`, `/roles/{id}/permissions`, `/users/{id}/roles`, a `roles`
  exposed entity and a `via_roles` field on an existing response — all read surface, and all
  of it has to exist before the freeze, which is why piece 1 is in wave 3 rather than
  later. And the two parked decisions are 19's: `STOCK_PRICES_VIEW` is what makes exposing
  `products_price_history` a bounded widening rather than an open one, and 19's question 9
  carries the permissions-page mismatch that 14's 2b handed it. The first needs no waiting
  at all. The second is available to wave 2 as an *extension* of the rule 19 states for its
  own role endpoints — read behind `USERS_READ`, write behind `USERS_EDIT` — rather than as
  an answer 19 has recorded, since 19 still lists `GET /users/{id}/permissions` as
  unchanged. Taking it early is a decision wave 2 makes, not one it inherits.
- **10 pairs with [01](01-file-storage.md)** — 01 removes `data/storage`, 10 removes
  everything else writable; only both together give a pod with no volume.
- **18 wants 10 to be real, and 10 wants 18 to exist.** 18's whole justification is a pod
  that actually sleeps, and 10's scale-to-zero is not achieved while an ambient client
  polls. Neither blocks the other's code — 18 is a publish path and 10 is a boot path — but
  the pair is what delivers the deployment, and 18 is the cheaper half.
- **19 landed as a plan on 2026-08-30 and took the number the tail below had promised it
  — and reading it against the code sent four of the five findings parked on it back to
  wave 2.** The parking's claim was that `DEFAULT_PERMISSIONS`, `USERS_EDIT` escalation,
  the unvalidated `permission_id`, the `userpictures` residual and the permissions page's
  `ADMIN`-versus-`USERS_READ` mismatch are one finding wearing five hats, because there is
  no permission *model* here — thirty constants and a hierarchy view, not one of which
  gates a *field*. Four of them turn out to need only the rule, which existing views
  already support, rather than the model; see the tail for the reversal and its reasoning.
  Wave 2's auth work is still read against 19 before it starts.
- **19 before [02](02-mcp-endpoint.md)'s stock tools, and 19's Q5 is owed by
  [18](18-mqtt-state-publication.md) *now* rather than when 19 lands.** Both are channels
  that carry prices, and 19 exists because "who can see what things cost" has no answer
  today. 02 is in wave 5 alongside 19's piece 2, and 02's own Q1/Q6 responses already
  answer 19's Q4 — the sidecar's key resolves to a user, so redaction is inherited — so
  that half is settled rather than sequenced. 18 is not: it is wave 1 track D, so it merges two waves *before* the plan
  whose question it is supposed to answer, and a retained topic published without the
  question settled is household pricing sitting on the broker until someone re-publishes.
  18 therefore owns it, as its own question 8, rather than deferring to 19 — see 18's
  Sequencing — and 19-Q5 records the reassignment instead of asking 18 for an answer it
  would give too late. Moved, and answered 2026-08-31: no price or cost field on any
  topic, with pricing history going to InfluxDB as commit-time events instead (18's Q7) —
  19's piece 2 can read 18's question 8 as settled.
- **Anything that keeps state between requests is 10's problem too.** On a pod that scales
  to zero, in-process state is state until the next idle window. Sweep S12's login throttle
  is the live case and is recorded in [11](11-api-error-handling.md)'s sequencing: Redis or
  a table, never process memory.
- **10's `bin/victual-migrate` precedes 14**, not the other way round — the one place the
  wave order below overrides the plan numbering, and why the CLI is pulled into wave 0.
- **17 before [11](11-api-error-handling.md), [16](16-project-rename.md) and
  [10](10-cold-start-statelessness.md)** — the first two break third-party clients and 17
  is where the cost of each candidate decision is written down. 10 is there for a different
  reason: the Home Assistant integration polls every thirty seconds, so scale-to-zero is not
  achieved by 10 alone. 17 also asks 14 for client endpoint manifests asserted against the
  snapshot, so it wants reading before 14 piece 2 is built.

  **17's Q2 and Q4 are answered (2026-08-29), and the rule relaxes for two of the three.**
  There are no third-party clients left to break: Home Assistant becomes a first-party
  integration fed by [18](18-mqtt-state-publication.md) over MQTT, and the Apple client is
  a Swift module written here rather than a fork of Grocy-SwiftUI. So 11 no longer waits on
  17 for the compatibility question (Q4: no shim, nothing unmodified reaches the server),
  and 10's conflict with the Home Assistant poll loop is removed by 18 rather than
  scheduled around. What survives is 17's *mechanism* half — the client-impact line per
  plan, and manifests covering request headers and response keys rather than paths — which
  now protects clients this household maintains instead of strangers'.

  **This rule was broken, on 16.** Both landed 2026-08-29; 16 went first and renamed the
  `GROCY-API-KEY` header and the `grocy_version` response field on the recorded premise
  that "no client exists", while 17 — written the same day — documents two external
  clients that use both. The premise was true of *deployed instances of this fork* and
  false of *clients*, and 17 is exactly the document that would have said so. Nothing is
  deployed, so the cost is a decision deferred rather than an outage; the decision is
  now 17's, taken after the fact instead of before it, and is written up under
  "Coupling 0" there. The rule stands unchanged for 11 and 10, both of which are still
  ahead.
- **15 is last**, except its auth refactor, which wants to precede
  [02](02-mcp-endpoint.md), and which carries the parked `shopping_locations` rename —
  and except 15-B2 (cookie flags), which the sweep pulls into the hotfix: it is one
  line, nothing reads the cookie from JavaScript, and it is what turns the sweep's two
  stored-XSS findings from "script runs" into "session stolen".
- **The sweep's auth findings ride with wave 2, not ahead of it — except S4.** S5
  (`DEFAULT_PERMISSIONS`), S6 (`USERS_EDIT` escalation), S17 (dead iCal branch,
  cross-instance construction), S18 (`AUTH_CLASS` type check) and S19 all live in the files
  11 and 15-C1/B1 rewrite. Fixing them first means doing the auth refactor twice; they are
  added to 15's tables and land in that wave.

  **S4 came out of that set in review and landed with the hotfix.** The
  do-the-refactor-twice argument is about the *refactor*, and it does not transfer to the
  *hole*: a High finding is only safely deferred while the backend it affects is
  unconfigured, which is a fact about the current deployment rather than a property of the
  code, and a config change is all it takes to falsify. The guard is a few lines and moves
  with the class when 15-C1 rewrites it. S5 remains the reason S4 mattered — an
  auto-created reverse-proxy user still gets ADMIN — and stays in this wave.
- **S29 is 12's, and changes what deferring 12 costs.** `bootbox` renders its message with
  `.html()` and `toastr` defaults to `escapeHtml: false`, so every delete confirmation and
  every success toast is an HTML sink — and ~45 of them interpolate a name from a text
  column that can contain markup. It is 12's because the factories that plan builds absorb
  the confirmations structurally rather than 31 times over. The ordering does not change;
  the reasoning does. "12 before 05/06/08" was about duplication and is now also about
  exposure, since every list/form pair added first is another copy of the vulnerable
  dialog.
- **Three sweep items are constraints on plans, not work of their own.** S11 (the
  query-string API key path) must not be inherited by [02](02-mcp-endpoint.md)'s bearer
  seam, and S4's trusted-proxy pattern is the model for it; S14 (barcode filename and
  image fetch) is inherited by [09](09-barcode-lookup-sources.md) before it adds lookup
  sources; S15, S16 and R1's `/system/config` contract test go into 14 piece 2.

Each plan carries a **Verification** section: booted-instance checks and result-set diffs
against a real database, following the standard the defects pass set. Lint is not
verification.

Each plan also carries a **client-impact line**, as of 2026-08-30 — the mechanism
[17](17-ecosystem-clients.md) defined and which nothing adopted until the rigor review
noticed (its D2). One line per plan, even where it reads "none", because absent is not the
same as none and [16](16-project-rename.md) is the proof: it broke both tracked clients on
a recorded premise of "no client exists", and a line it could not have written as "none"
is the cheapest thing that would have caught it. Writing the other eighteen turned up
three more that were not "none" — see 17's note on item 2.

## Ground rules these plans assume

**Compatibility.** The constraint is not to break someone pulling from this fork today.
It is not a permanent commitment to SQLite — this is a hard fork and will drift. Where a
feature is cheaper or only sensible on PostgreSQL, say so and make it PostgreSQL only
rather than contorting the design (plan 01 is the first case).

**Additive API.** New entities go in the `ExposedEntity` enum in `victual.openapi.json`;
existing endpoints keep their response shape. Anything that would change an existing
response is called out explicitly in the plan rather than slipped in.

**Migrations from 0256 on work on every supported engine** — a portable `NNNN.sql`, a
per engine pair, or a documented engine-exclusive migration. See `db/pgsql/README.md`.

The third case is new. `0256.sqlite.sql` is the first to use it — a SQLite-only cast fix
that PostgreSQL never needed — and [01](01-file-storage.md) is the second, shipping
`0257.pgsql.sql` with no SQLite counterpart rather than a no-op pair that pretends
otherwise. The rule for it is that the exemption is *recorded*, in the migration itself
and in `db/pgsql/README.md`, not merely taken.

The consequence turned out to bite immediately rather than later, and is worth stating
plainly: once a number exists on one engine only, the two engines sit at different
migration numbers while both being fully migrated, so nothing may compare one engine's
number to the other's. `DatabaseImporter` did exactly that and refused every import the
moment `0256.sqlite.sql` landed. It now checks each side against
`DatabaseMigrationService::GetLatestMigrationNumber($dialect)` for that side's own engine,
and no longer copies the `migrations` table into the target — a target carrying the
source's numbers would skip a future migration of its own believing it had already run.
Anything else that reasons about schema versions, including
[10](10-cold-start-statelessness.md)'s boot check, has to do the same.

**Verification.** Schema changes are checked with `.devtools/pgsql/difftest.php` (views)
and `trigdifftest.php` (trigger behaviour). New views must return identical output on both
engines unless the plan says otherwise and explains why.

**Cite symbols, not line numbers.** `ApiKeyAuthMiddleware::IsValidApiKey` rather than
`ApiKeyAuthMiddleware.php:50` — which the MCP spec cited, and which was already `:49` by
the time anyone read it. Where a line is worth quoting, quote the code next to it so the
reference survives the shift. The security sweep adopted this in its own preamble and the
plans have not; the older bare line numbers are left alone rather than swept, because
rewriting a hundred of them by hand is how a wrong one gets introduced. New citations
follow the rule. This is the rigor review's D5.

**What the always-on cluster services are for.** The k3s cluster runs an MQTT broker,
Redis and InfluxDB, all of them always on while the application pod is usually asleep.
That asymmetry is the useful thing about them, and it is easy to reach for the wrong one,
so the division is by capability rather than by taste:

- **MQTT** — *tell someone something happened*, and hold the last thing said. Retained
  topics are how a consumer stays correct across an arbitrarily long pod absence
  ([18](18-mqtt-state-publication.md)). It is not a datastore and has no atomic
  operations; nothing that needs to count or lock belongs here.
- **Redis** — *count, lock or expire something, atomically*, where the value must outlive
  the pod but is not the household's data. `INCR`, `SETNX`, TTLs. Sweep S12's login
  throttle is the first real case and is not optional: on this deployment an in-process
  counter is reset for free by an attacker who waits out an idle window.
- **InfluxDB** — *record how a number changed over time*, written as events at the
  moment they commit, never as state sampled on a schedule — a mostly-asleep pod cannot
  sample honestly, but an event written at commit is true forever. Home Assistant's own
  integration records entity history; the server writes price and valuation events
  directly ([18](18-mqtt-state-publication.md)'s Q7). Queried with credentials, not
  subscribed to — which is why it may carry the prices MQTT must not (18's Q8).
- **PostgreSQL** — anything that must still be true after everything restarts, which is
  the household's actual data and, today, its sessions.

The corollary is worth stating because the intuition runs the other way: **splitting the
web UI from the application would not create a need for Redis.** The usual reason a
separated tier wants a shared store is session state across replicas, and sessions are
already rows in PostgreSQL — shared, and surviving the pod by construction. A static
frontend holding a bearer token, which is the seam [02](02-mcp-endpoint.md) needs built
anyway, has no server-side session at that tier at all. Redis earns its place on the
three jobs above and should be adopted when one of them lands, not as architecture.

## Order of operations

The single sequence to work from, features and hardening interleaved. Constraints it
encodes: 14's suite unblocks every other plan's verification; 12 must precede the plans
that add list/form pairs (05/06/08/19); 11, 13, 15-C1 and 14's snapshot precede 02; 08
proves the recursive pattern before 07 spends it; 10 then 01 produce the volume-less
pod; 19 splits across two waves because its roles half grows the read surface 14 has yet
to freeze while its visibility half changes response shapes and cannot precede the
snapshot that proves it. Waves are strictly ordered; tracks inside a wave touch disjoint
files and can run as parallel sessions.

Wave 0 is complete and wave 1's track C is done; the rest is unstarted. Wave 4's shape
is no longer settled — see 07-Q6 there. Two things sat between wave 0 and wave 1:
a hotfix the security sweep forced, and a decision 17 has been owed since 16 landed.
Neither is a wave; both are a single sitting, and wave 1 does not start until both are
done. **Both are now done** — the hotfix landed and 17-Q2 and 17-Q4 carry responses, so
wave 1 is open. Q2's answer added [18](18-mqtt-state-publication.md) to the roadmap and
took the Home Assistant conflict out of 10's path; see wave 0.5 below.

A third thing has since been added without a wave of its own: [19](19-rbac.md), written
2026-08-30. It is placed rather than pending — piece 1 in wave 3, piece 2 in wave 5 — and
its arrival unparks four permission findings back into wave 2, which is the one change it
makes to work already scheduled. See the tail for that reversal and its reasoning.

### Wave 0 — decisions and scaffolding (one sitting) — **complete**

Landed 2026-08-27 to 2026-08-29: `40e1f57f` (container + `bin/victual-migrate`),
`d80a88f0` (14 piece 1), `fd506a85` (14 piece 3), plus `31401f0`, `4ae6990`, `d2524a3`
and `36a3032` for the phases the suite grew in the doing. The 09-Q1 experiment is still
unscheduled, as this wave always said it would be. See 14's Executed section.

- **A dev/CI container, in this repo.** Both `.devtools/pgsql` scripts ran under a
  `victual-dev` image, and there was no Dockerfile, compose file or Makefile anywhere
  in the tree — nor a vendored `packages/`. 14's verification 6 ("one command from a
  clean checkout") was unmeetable until that existed, so it was the first thing built: a
  Dockerfile (PHP 8.5, `pdo_sqlite` + `pdo_pgsql`, composer install) and a compose file
  with a PostgreSQL service. [10](10-cold-start-statelessness.md) later bakes its view
  cache into this same build.
- **`bin/victual-migrate`, pulled forward from [10](10-cold-start-statelessness.md).**
  `trigdifftest.php` needs a migrated SQLite database and nothing in the tree could make
  one from a command line: `bin/victual-db-import` returns early on `sqlite`
  (`bin/victual-db-import:68`) and migrations otherwise only ran from `GET /`. Without
  this, 14 piece 1 could not run — the roadmap's own ordering inverted. Only the CLI
  moved; the lock and the cache work stay in 10.
- **14 piece 1**: the runnable diff suite (recover or rewrite the seeds), plus its
  recursive-CTE tool check (14 verification 7) — done now so wave 4 never waits on it.
  It also extracts `difftest.php`'s `normalise()` into `services/`, which
  [13](13-write-path-transactions.md) then consumes rather than duplicating.
- **14 piece 3**: CI (lint + the suite) the same day piece 1 exists.
- **09-Q1 experiment — deferred, not scheduled.** Twenty pantry barcodes against each
  candidate source; thirty minutes, but the barcodes have to come off real shelves, so
  it waits on a trip to the kitchen rather than on a wave. Nothing else depends on it:
  09 is parked until the data exists, and 04-Q2 (ship no barcodes) already stands on
  its own reasoning.

### Wave 0.5 — the hotfix and the decision

- **Security hotfix, one PR.** Four items from [docs/security-sweep.md](../security-sweep.md),
  each a few lines, each in a file no wave 1 track opens:
  - **S1** — `BaseApiController::GetParsedAndFilteredRequestBody` `str_replace`s
    `&lt;`/`&gt;`/`&amp;` back to raw characters *after* HTMLPurifier, so entity-encoded
    script is stored literally and rendered with `{!! !!}` in the stock overview,
    recipes, shopping list and userfields. Delete the three lines; the S7 allow-list
    trim (`iframe`, `id`) goes in the same edit.
  - **S2** — `FilesApiController` has no `CheckPermission` on upload, serve or delete,
    accepts any content under any extension, and serves it `inline` with a sniffed
    MIME type. Permission per group, extension allow-list per group, `attachment`
    unless the type is a safe image, `X-Content-Type-Options: nosniff`.
  - **S3 / 15-B2** — `BaseAuthMiddleware::SetSessionCookie` gains `HttpOnly`,
    `SameSite=Lax`, `Secure` when HTTPS, and a real expiry. 15-B2's open question about
    `Strict` versus embedded mode is answered `Lax` here; 15 records it.
  - **S4** — added in review, out of wave 2. `ReverseProxyAuthMiddleware` refuses the
    username header unless `REMOTE_ADDR` matches `REVERSE_PROXY_AUTH_TRUSTED_PROXIES`,
    and an unset list refuses everything rather than trusting everything. Not applied to
    `USE_ENV` mode, where the value is server-populated and a proxy list would break a
    correct Apache-authenticates-locally setup.
  - **R1** — `BaseController` and `SystemApiController::GetConfig` test
    `substr($constant, 0, 19)` against a 21-character prefix, so every feature flag is
    dropped from the UI and the API. `str_starts_with` and `substr(…, 8)`. A regression
    from 16, recorded in 16's Executed section.

  Verification is a booted instance, not a diff: upload an `.svg` and confirm it
  downloads rather than renders; read the `Set-Cookie` header; POST a product
  description of `&lt;script&gt;` and confirm it comes back as text; open the consume
  form with location tracking enabled and confirm the field is there.

  **Landed 2026-08-29**, all four verified that way and each one stronger than the
  check above asked for: the `.svg` is refused at upload rather than served as a
  download, the `Set-Cookie` was read for all three of its cases against the
  `sessions` rows, the stored `&lt;script&gt;` was confirmed inert in a real browser
  (no dialog, no script node, present as text), and the consume form was screenshotted
  with its location field back. Two adjacent one-liners rode along: **S7** as the
  roadmap says, and **S23** under the S20–S24 rule. Three items departed from the
  sweep's proposed remediation — the sanitiser is fixed per column rather than by
  deleting three lines, equipment manuals are gated on `EQUIPMENT` rather than
  `MASTER_DATA_EDIT`, and PDFs are still served inline — each recorded, with the
  evidence, in the sweep's [What the hotfix
  changed](../security-sweep.md#what-the-hotfix-changed).
- **17-Q2 and 17-Q4, answered.** Q4 (does the server keep accepting `GROCY-API-KEY`,
  and for how long) gates 11; Q2 (does the Home Assistant fork poll through grocy-py or
  reimplement against `/system/db-changed-time`) gates 10, because thirty-second polling
  defeats the scale-to-zero 10 exists for. Q1 and Q3 can wait; these two cannot, and the
  "17 before 11 and 10" rule above is otherwise broken a second time.

  **Answered 2026-08-29.** **Q4: no shim** — every client that will exist is first-party,
  so nothing unmodified reaches the server and `GROCY-API-KEY` is simply gone. **Q2:
  neither option** — the question presupposed a polling HTTP client, and the household's
  cluster already runs an always-on MQTT broker that Home Assistant speaks natively. The
  server publishes retained state and discovery configs after every commit and on boot;
  Home Assistant polls nothing and holds last-known state through arbitrarily long sleeps.
  That is [18](18-mqtt-state-publication.md), new on the roadmap, and it removes 10's
  Coupling 1 rather than mitigating it. Q3 is now half-answered (the Apple client is
  written here, not forked, so the licence question is closed and only distribution is
  open); Q1, the version string, is still open and still blocks nothing.

### Wave 1 — platform (four parallel tracks, disjoint files; C is done)

- **Track A: 10 cold start**, then **01 file storage**. 10 first — 01's importer is
  easier to reason about once cold start no longer rewrites requests. Together they end
  the PVC. 10 is the first plan to publish an image from the Dockerfile, so sweep S25
  (`.dockerignore`, non-root `USER`) is 10's — settled there, and in the sweep, after
  spending a day assigned to 10 by this file and to 15 by the sweep and carried by
  neither — and 01 inherits S2's per-group
  extension allow-list and S10's upload cap when it moves storage into the database —
  a blob column with no size limit is the same DoS with a different disk.
- **Track B: 12 frontend shared core**, which now also carries sweep **S29** as its step
  3a. If steps 1–2 land alone and the factories wait, S29 waits with them — so if that gap
  is going to be long, pull the ~20 toast sites forward on their own, since they need no
  factory and close most of the exposure. Land steps 1–2 as their own PR: the four latent
  bug fixes plus the `request()` core with `timeout`/`onerror`. Note what that PR does
  *not* deliver — all 157 `console.error` handlers are passed explicitly, so the default
  error toast cannot fire until they are deleted, and those deletions belong to the
  files step 3 rewrites wholesale. The handler deletions therefore ride with the factory
  conversions, per file, so each one is exercised as it lands.
- **Track C: 13 write-path transactions** — **done**, ahead of the rest of this wave
  (`7abfd2fa`, `782289b8`, `96f9ec99`, 2026-08-29). All seven entrypoints, webhook after
  commit, and the importer made atomic. Tracks A and B are still open.
- **Track D: 18 MQTT state publication.** New, from 17's Q2. Disjoint from A, B and C: it
  adds a service and a call at the end of the write paths 13 already centralised, and
  touches no file the other tracks open. It belongs in this wave rather than later because
  track A's whole point is a pod that sleeps, and until 18 exists the household's Home
  Assistant is the reason it will not. 13 is its prerequisite and is already in — the
  publish hangs off the same after-commit seam the label-printer webhook uses.

  **18 takes [19](19-rbac.md)'s Q5 here rather than 19 waiting on 18.** 19 asked 18 to
  record whether published state carries prices, and gated itself on 18 to make sure — but
  18 is this wave and 19 is wave 3, so the gate was unachievable in the direction it was
  written. It is also unnecessary: 18's own security note already says publish nothing that
  would not be shown on a wall tablet, and a broker subscriber is not a logged-in user and
  cannot be made into one. The note now names prices explicitly, because 18's stock summary
  would otherwise ship `value`, `last_price` and `average_price` by default if its
  attributes are assembled from `uihelper_stock_current_overview`. It is 18's question 8,
  reassigned to the plan that could answer it — and answered there on 2026-08-31, with
  the rest of 18's questions: no prices, on any topic; pricing history is InfluxDB's
  (18's Q7), not the broker's.

### Wave 2 — API correctness

- **11 API error handling**, presented as a before/after diff from 14's sweep. Then,
  while the auth files are open: **15-C1** (authenticator extraction, which also closes
  sweep S17 — the dead iCal `secret` branch exists *because* middlewares construct each
  other), **15-B1** (LDAP removal + the `AUTH_CLASS` type check, sweep S18) and the
  sweep's auth findings **S4** (trusted-proxy allowlist for `ReverseProxyAuthMiddleware`,
  refuse when unset), **S5** (`DEFAULT_PERMISSIONS` no longer `['ADMIN']`; never grant
  a permission the creator lacks), **S6** (`USERS_EDIT` cannot edit a user holding
  permissions the caller lacks; current password required for self password change)
  and **S19** (dummy-hash verify for unknown users, cookie cleared on logout, expired

  sessions pruned) as one changelogged follow-on. 15-B2 already landed in the hotfix.
  **S27** and the **`userpictures` residual** join them here rather than waiting on
  [19](19-rbac.md) — see the tail below for why that parking was wrong. Wave 2 should be
  read against 19 before it starts, in the way 17 was supposed to be read before 11 and 16,
  but as a consistency check on the rule it writes rather than as a blocker: 19's questions
  4, 5 and 9 land in 02, 18 and here respectively, and the read/write split 19 states
  for its own role endpoints is available to the permissions page as an extension wave 2
  may take, not an answer 19 has already recorded.

  **S6 is worse than the sweep first recorded**, and wave 2 should be written against the
  fact rather than the finding: the `USERS` subtree is a chain and the tree resolves
  downward, so `USERS_CREATE` alone already resolves to `USERS_EDIT` and an account that
  may create users may today rewrite any admin's password without holding `USERS_EDIT` at
  all. Written up under S6 in the sweep. The subset-of-caller check is the fix either way;
  the chain is why "just require `USERS_EDIT`" would not have been.
  The remaining 15 one-liners (C3–C9) ride along with whatever wave has the file open;
  C10 stays deferred until after 13, then folds in here or later.
- **S8, S9, S12** land here too, as they are 11's territory: `Origin` check on
  cookie-authenticated non-GET API requests and `GET /manageapikeys/new` / `GET /logout`
  made POST (S8); the 500 page's trace and system info gated on `dev` and escaped
  (S9 — 11 already owns the error surface); login throttling and a forced change while
  the seeded `admin`/`admin` hash is in use (S12).

### Wave 3 — first features on the new platform (four tracks)

- **09 implementation**, if wave 0's experiment justified it — inheriting sweep S14
  first (filter `__barcode` to a filename-safe class, allow-list the image extension,
  refuse loopback and private hosts before fetching `__image_url`), since every source
  09 adds is another party that chooses that URL.
- **06 location barcodes** — the first shipped dual-engine migration (deliberately
  small), on the locations list/form pair 12 just converted. Codes, printing, UUID, QR;
  camera ingest stays unscoped.
- **03 category minimums** — one column, one new view, group shortfalls kept out of
  `stock_missing_products`. If 07-Q6 lands on *taxonomy*, this row grows a
  `parent_product_group_id` column and most of 07 with it; see wave 4.
- **[19](19-rbac.md) piece 1 — roles**, as its own track, and **only if its Q8 answers
  (b) or (c)**. It is here rather than later because it grows the API read surface, which
  the rule above requires to happen before 14 piece 2 freezes the contract. It touches
  `User.php`, `0110`-successor views, `UsersApiController` and the users views, none of
  which 03, 06 or 09 open; the two files the tracks share are `routes.php` and
  `victual.openapi.json`, additively in every case, which is worth naming because the wave
  rule says disjoint rather than mostly disjoint. If Q8 answers (a) — gate reads in piece 1
  — this is not a wave 3 track at all but a model change with real upgrade risk, and the
  wave is re-planned around it.

### Wave 4 — the hierarchy work

- **08 nested locations** — recursive pattern on the simpler tree, fixtures in 14's
  suite first. Unconditional: containment is exactly what `parent_location_id` would
  mean, so 08 has none of 07's modelling doubt.
- **07-Q6, answered before anything in this wave is scheduled.** 07's own question 6
  asks whether the requirement is a taxonomy or a packaging relation, and its recorded
  response says plainly that if it is a taxonomy then **nesting `product_groups` is the
  right change and 07 is mostly unnecessary** — one nullable parent column on a lookup
  table, in [03](03-category-min-stock.md)'s territory, costing none of 07's stock
  aggregation, substitution semantics or one-level audit. This wave was written before
  that response existed and still treats 07 as its centrepiece. It is not one until Q6
  says so. The question cannot be answered from the code; it needs the real catalogue.
- **Then whichever of these Q6 selects:**
  - *Taxonomy* — nested `product_groups` folds into **03**, which moves to wave 3 with
    a parent column added to its scope, and 07 shrinks to whatever genuine
    same-product-different-packaging cases remain. Wave 4 stops being the large wave.
  - *Packaging relation* — **07 nested products** as written: only after 08 is merged
    and used, fixtures before any change per its own verification section, the largest
    item on the roadmap and the one to be careful with — an audit of every one-level
    assumption, not "make the view recursive". Note that 07's Q1 and Q4 responses are
    written against the taxonomy reading and are rewritten against the narrower relation
    if this is where it lands.

### Wave 5 — the assistant and the lists

- **14 piece 2** — the response-contract snapshot, now that 11 has stabilized the
  failure paths it records. This freezes the API surface 02 builds on, **and the surface
  has to be complete first** — 14's section 2b lists the eight reads the web UI does
  directly today with no API equivalent in the shape it renders, and the Swift client
  needs the same ones. It also takes
  three sweep items that are contract work rather than fixes: body validation against
  the entity's OpenAPI schema (S16 — `id` and `row_created_timestamp` stop being
  writable through the generic endpoints), a length/complexity bound on the `§` regex
  operator written into the filter contract `filterdifftest.php` already measures
  (S15), and a test that `/system/config` returns at least `FEATURE_FLAG_STOCK` so R1
  cannot recur. It also grows two more comparison legs and a second fixture identity, for
  19 piece 2 below — built here rather than there, because a per-identity snapshot is an
  extension of the snapshot mechanism and not a consumer of it.
- **[19](19-rbac.md) piece 2 — price visibility**, co-scheduled with 14 piece 2 and
  landing before the freeze is signed off, since removing fields from `required` changes
  existing response shapes. It waits this long for two reasons: 11 owns the error helper
  and the filter refusal it needs, and the double snapshot — every path called as Admin and
  as a user without `STOCK_PRICES_VIEW`, asserted equal minus exactly the `x-visibility`
  fields — is the proof the feature works. It must also precede the Swift transport
  generation, per 17's Coupling 5: a field that may be absent per user is the additive
  rule's blind spot, and whether it breaks the client outright depends on how the Swift
  model declares those properties, which 17's verification 5 answers rather than assumes.
- **02 MCP, read-only v1** — separate container per its Q6 response, bearer key
  behind the credential→user seam per the IdP note. [19](19-rbac.md)'s Q4 asked which of
  those two this is, and 02's own Q1 and Q6 responses had already answered: the key
  resolves to a Victual user and every REST call is permission-checked as that user, so
  19's redaction is inherited and there is no MCP-specific mechanism to build. What is
  left is 02's to decide rather than 19's — three of the six read tools carry prices, so
  one shared key means one user's price visibility for every household member the
  assistant talks to, and the key is per person or its user holds no
  `STOCK_PRICES_VIEW`. Two sweep constraints: the seam
  does not accept a key from the query string (S11 — the server's own query path is
  removed in wave 2 and the sidecar must not reintroduce it), and sidecar→server trust
  follows S4's trusted-proxy pattern rather than a shared header alone.
- **05 A + C** — store on lists, default list per product/recipe. B (store-layout
  ordering) waits for real shopping trips to prove it wanted.

**The client work is not in any wave, and that is deliberate.** 17's answers commit this
household to two first-party clients — a Home Assistant integration and a Swift module
with per-platform UI targets — and neither lives in this repository. What this roadmap
owes them is on it: 18 for the ambient read path, 11 for the error contract, 14 piece 2
for the response snapshot the Swift module's transport is generated from. Sequence the
Swift generation after 11, which moves status codes across ~74 routes; before that, any
generated client is generated twice. Sequence the Swift *UI* after [19](19-rbac.md)'s
piece 2 for the same reason in a different layer: it renders `price` and `costs`
unconditionally, and piece 2 is what makes them optional.

### Usage-driven tail — no scheduled slot

- **02 writes** (`MCP_WRITE`-gated) once read-only has proven the transport — 13 is
  already in place by then.
- **05 B** if filtering alone turns out not to be enough.
- **04 seed importer** the first time a seeded disposable instance is wanted; the
  curated dataset content stays open-ended and unscheduled.
- **Declined**: the `shopping_locations` rename (15-Q5) — revisit only if a breaking
  batch happens for other reasons.
- **Deleted, whenever a PR next touches the root**: `update.sh` (sweep S13, rigor
  review H3) — it wipes the install and unpacks an unsigned upstream Grocy zip. This
  bullet said it was "added to 15's non-breaking table so it has a home" for a day and a
  half while 15 had no such row; it is now **15-C11**, with
  `.devtools/create_release_package.bat` alongside it. It needs no wave.
- **S27**, found while verifying the hotfix — the permissions API accepts an unvalidated
  `permission_id` and silently grants nothing when it is not a real one. Small, and in

  the file 15-C1 opens, so it rides with wave 2 rather than getting a slot.
- **The RBAC plan has landed as [19](19-rbac.md)** (2026-08-30), and **four of the five
  permission findings parked against it come back to wave 2.** The parking said they were
  one finding wearing five hats — no permission *model* here, only thirty constants and a
  hierarchy view, so each fix would be a guess at what the model is about to say. Reading
  19 against the code says otherwise, and 19 itself said so from its first draft, before
  this pass touched it: its Depends-on line puts it *after* wave 2's S5/S6, "because role
  assignment is a grant". Parking S5 and S6
  on 19 inverts 19's own stated dependency, and would leave `DEFAULT_PERMISSIONS = ADMIN`
  standing through two more waves. That is precisely the residual the hotfix knowingly
  accepted when S4 landed without S5: an auto-created reverse-proxy user is refused unless
  it comes from a trusted proxy, and is still made an admin once it does.

  The distinction the parking missed is between the *rule* and the *model*. The
  subset-of-caller rule needs only a caller's resolved set, a target's resolved set, and
  the closure of a proposed grant — `user_permissions_resolved` and `permission_tree`, both
  of which exist today and neither of which 19 changes the shape of. 19 widens the view's
  `IN (…)` subquery with a union over `role_permissions`; a comparison written against that
  view in wave 2 keeps working verbatim once roles land. So:

  - **S5** — wave 2. The config half (`DEFAULT_PERMISSIONS` stops being `['ADMIN']`) is
    not a model question; `[]` is what 19 is written on, since its `VICTUAL_DEFAULT_ROLES`
    also defaults to empty. Note that two of the three call sites have no creator to
    compare against — `ReverseProxyAuthMiddleware.php:79` and `LdapAuthMiddleware.php:108`,
    the latter of which 15-B1 deletes — so for those the config default *is* the whole
    answer, and only `POST /api/users` gets the subset rule.
  - **S6** — wave 2, and more urgent than the sweep recorded: see the chain noted in that
    wave. A set comparison over an existing view, not a model.
  - **S27** — wave 2. One existence check against `permission_hierarchy`, whose rows are
    fixed. 19 adds rows to that table; it does not change what validating one means. 19's
    verification now asserts the same check on the two id-taking endpoints it adds.
  - **The `userpictures` residual** — wave 2, with S6, and it is a *route* gap rather than
    a model gap: the route carries no user id, but `users.picture_file_name` recovers the
    owner, so the check is owner-is-caller → `USERS_EDIT_SELF`, else `USERS_EDIT`.
  - **The permissions page's `ADMIN`-versus-`USERS_READ` mismatch** is the one that is
    genuinely 19's, and 19 carries it as its question 9 — but wave 2 can extend the rule 19
    states for its own role endpoints (read behind `USERS_READ`, write behind `USERS_EDIT`)
    to this endpoint rather than wait, which is a decision wave 2 takes rather than one it
    inherits: 19's API block still lists `GET /users/{id}/permissions` as unchanged.

  Wave 2 should still be read against 19 before it starts, in the way 17 was supposed to be
  read before 11 and 16. The lesson is the mirror of S4's: **a finding is only safely
  parked on a plan that does not depend on it.** Where the plan and the parking each name
  the other as prerequisite, one of them is wrong, and it is worth checking which before
  the wave that would have fixed it goes past.
- **Not scheduled, recorded**: sweep S20–S22 and S24 (Host-header redirects, wildcard
  CORS, integer ids concatenated into SQL behind `FILTER_VALIDATE_INT`, Actions pinned to
  tags). Each is a one-liner that rides with whichever wave opens the file; S21 waits on
  17 to say which browser clients exist. S23 (`Content-Disposition` quoting) is the rule
  working as intended: the hotfix had that line open and took it.

Every wave ends mergeable: nothing in a later wave reworks what an earlier wave
shipped, and each track lands through its own PR with its plan's Verification section
executed on a booted instance.
