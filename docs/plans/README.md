# Fork roadmap

Action plans for the work this fork exists to do. Each is a draft for review, not a
commitment — the **Open questions** sections are numbered, and each carries its review
answer inline as a `> **Response:**` block, so question and answer read together.

## Status

| # | Plan | Upstream | Depends on | Size |
|---|---|---|---|---|
| — | [Database abstraction / PostgreSQL](../../db/pgsql/README.md) | — | — | **done** |
| 01 | [File storage in the database](01-file-storage.md) | — | PostgreSQL | small |
| 02 | [MCP endpoint](02-mcp-endpoint.md) ([interface spec](../mcp-interface-spec.md)) | — | — | medium |
| 03 | [Category level minimum stock](03-category-min-stock.md) | [#2616](https://github.com/grocy/grocy/issues/2616) | — | small |
| 04 | [Seed product datasets](04-seed-datasets.md) | [#2679](https://github.com/grocy/grocy/issues/2679) | — | medium |
| 05 | [Store specific shopping lists](05-store-shopping-lists.md) | [#2702](https://github.com/grocy/grocy/issues/2702) | — | medium |
| 06 | [Location barcodes](06-location-barcodes.md) | — | — | small |
| 07 | [Deeply nested products](07-nested-products.md) | — | — | **large** |
| 08 | [Deeply nested locations](08-nested-locations.md) | — | — | medium |
| 09 | [Barcode lookup sources for US products](09-barcode-lookup-sources.md) | — | — | small, **deferred** |

## Hardening

Remedial work from [docs/architecture-review.md](../architecture-review.md). The review's
own defects table (items 1–13) is already fixed in `36650cd`; these are everything else it
found. They add no features and block no feature plan, but 12 and 14 should land before
the plans noted below start minting more of what they clean up.

| # | Plan | From | Depends on | Size |
|---|---|---|---|---|
| 10 | [Cold start and statelessness](10-cold-start-statelessness.md) | Review §Statelessness, order item 2 | — | medium |
| 11 | [API error handling, auth surface and error logging](11-api-error-handling.md) | Review §API surface, order item 3, deferred defect 9 | 14 (soft) | medium |
| 12 | [Frontend shared core](12-frontend-shared-core.md) | Review §Frontend, order item 4, oddities list | — | medium |
| 13 | [Write-path transactions](13-write-path-transactions.md) | Review §Services, order item 5 | — | small |
| 14 | [Contract and regression scaffolding](14-contract-and-regression-scaffolding.md) | Review §API surface, order item 6 | — | medium |
| 15 | [Deliberate cleanup batch](15-deliberate-cleanup.md) | Review §Backend, §Uniformity, parked 05-Q4 | 11, 13, 14 (per item) | small + one large open question |

## Meta

| # | Plan | Upstream | Depends on | Size |
|---|---|---|---|---|
| 16 | [Project rename](16-project-rename.md) | — | before first deployment | small–medium |
| 17 | [Ecosystem clients](17-ecosystem-clients.md) | — | 14 supplies the mechanism; read before 11 and 16 | small, ongoing |

**Blocking and de-risking, in one place:**

- **12 before [05](05-store-shopping-lists.md), [06](06-location-barcodes.md),
  [08](08-nested-locations.md)** — each adds a list/form pair, and 12 is what stops them
  being copies of the old pattern.
- **14 before [07](07-nested-products.md) and [08](08-nested-locations.md)** — both plan
  their fixtures against tooling this makes runnable, and neither has ever exercised a
  recursive CTE through it.
- **13 and 11 before [02](02-mcp-endpoint.md)** — 13 before MCP writes, 11 because an
  assistant cannot recover from an API that answers 400 for "not allowed" and 500 for
  "bad filter".
- **14 before 11** — 11 changes status codes across ~74 routes; better shown as a diff
  than asserted by hand.
- **10 pairs with [01](01-file-storage.md)** — 01 removes `data/storage`, 10 removes
  everything else writable; only both together give a pod with no volume.
- **10's `bin/grocy-migrate` precedes 14**, not the other way round — the one place the
  wave order below overrides the plan numbering, and why the CLI is pulled into wave 0.
- **17 before [11](11-api-error-handling.md), [16](16-project-rename.md) and
  [10](10-cold-start-statelessness.md)** — the first two break third-party clients and 17
  is where the cost of each candidate decision is written down. 10 is there for a different
  reason: the Home Assistant integration polls every thirty seconds, so scale-to-zero is not
  achieved by 10 alone. 17 also asks 14 for client endpoint manifests asserted against the
  snapshot, so it wants reading before 14 piece 2 is built.
- **15 is last**, except its auth refactor, which wants to precede
  [02](02-mcp-endpoint.md), and which carries the parked `shopping_locations` rename.

Each plan carries a **Verification** section: booted-instance checks and result-set diffs
against a real database, following the standard the defects pass set. Lint is not
verification.

## Ground rules these plans assume

**Compatibility.** The constraint is not to break someone pulling from this fork today.
It is not a permanent commitment to SQLite — this is a hard fork and will drift. Where a
feature is cheaper or only sensible on PostgreSQL, say so and make it PostgreSQL only
rather than contorting the design (plan 01 is the first case).

**Additive API.** New entities go in the `ExposedEntity` enum in `grocy.openapi.json`;
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

## Order of operations

The single sequence to work from, features and hardening interleaved. Constraints it
encodes: 14's suite unblocks every other plan's verification; 12 must precede the plans
that add list/form pairs (05/06/08); 11, 13, 15-C1 and 14's snapshot precede 02; 08
proves the recursive pattern before 07 spends it; 10 then 01 produce the volume-less
pod. Waves are strictly ordered; tracks inside a wave touch disjoint files and can run
as parallel sessions.

### Wave 0 — decisions and scaffolding (one sitting)

- **A dev/CI container, in this repo.** Both `.devtools/pgsql` scripts run under a
  `grocy-fork-dev` image, and there is no Dockerfile, compose file or Makefile anywhere
  in the tree — nor a vendored `packages/`. 14's verification 6 ("one command from a
  clean checkout") is unmeetable until that exists, so it is the first thing built: a
  Dockerfile (PHP 8.5, `pdo_sqlite` + `pdo_pgsql`, composer install) and a compose file
  with a PostgreSQL service. [10](10-cold-start-statelessness.md) later bakes its view
  cache into this same build.
- **`bin/grocy-migrate`, pulled forward from [10](10-cold-start-statelessness.md).**
  `trigdifftest.php` needs a migrated SQLite database and nothing in the tree can make
  one from a command line: `bin/grocy-db-import` returns early on `sqlite`
  (`bin/grocy-db-import:68`) and migrations otherwise only run from `GET /`. Without
  this, 14 piece 1 cannot run — the roadmap's own ordering is inverted. Only the CLI
  moves; the lock and the cache work stay in 10.
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

### Wave 1 — platform (three parallel tracks, disjoint files)

- **Track A: 10 cold start**, then **01 file storage**. 10 first — 01's importer is
  easier to reason about once cold start no longer rewrites requests. Together they end
  the PVC.
- **Track B: 12 frontend shared core.** Land steps 1–2 as their own PR: the four latent
  bug fixes plus the `request()` core with `timeout`/`onerror`. Note what that PR does
  *not* deliver — all 148 `console.error` handlers are passed explicitly, so the default
  error toast cannot fire until they are deleted, and those deletions belong to the
  files step 3 rewrites wholesale. The handler deletions therefore ride with the factory
  conversions, per file, so each one is exercised as it lands.
- **Track C: 13 write-path transactions.** All seven entrypoints, webhook after commit.

### Wave 2 — API correctness

- **11 API error handling**, presented as a before/after diff from 14's sweep. Then,
  while the auth files are open: **15-C1** (authenticator extraction), **15-B1** (LDAP
  removal + the `AUTH_CLASS` validation check) and **15-B2** (cookie flags) as one
  small, changelogged follow-on. The remaining 15 one-liners (C3–C9) ride along with
  whatever wave has the file open; C10 stays deferred until after 13, then folds in
  here or later.

### Wave 3 — first features on the new platform

- **09 implementation**, if wave 0's experiment justified it.
- **06 location barcodes** — the first shipped dual-engine migration (deliberately
  small), on the locations list/form pair 12 just converted. Codes, printing, UUID, QR;
  camera ingest stays unscoped.
- **03 category minimums** — one column, one new view, group shortfalls kept out of
  `stock_missing_products`.

### Wave 4 — the hierarchy work

- **08 nested locations** — recursive pattern on the simpler tree, fixtures in 14's
  suite first.
- **07 nested products** — only after 08 is merged and used. Fixtures before any
  change, per its own verification section; this is the largest item on the roadmap
  and the one to be careful with — it is an audit of every one-level assumption, not
  "make the view recursive".

### Wave 5 — the assistant and the lists

- **14 piece 2** — the response-contract snapshot, now that 11 has stabilized the
  failure paths it records. This freezes the API surface 02 builds on.
- **02 MCP, read-only v1** — separate container per its Q6 response, bearer key
  behind the credential→user seam per the IdP note.
- **05 A + C** — store on lists, default list per product/recipe. B (store-layout
  ordering) waits for real shopping trips to prove it wanted.

### Usage-driven tail — no scheduled slot

- **02 writes** (`MCP_WRITE`-gated) once read-only has proven the transport — 13 is
  already in place by then.
- **05 B** if filtering alone turns out not to be enough.
- **04 seed importer** the first time a seeded disposable instance is wanted; the
  curated dataset content stays open-ended and unscheduled.
- **Declined**: the `shopping_locations` rename (15-Q5) — revisit only if a breaking
  batch happens for other reasons.

Every wave ends mergeable: nothing in a later wave reworks what an earlier wave
shipped, and each track lands through its own PR with its plan's Verification section
executed on a booted instance.
