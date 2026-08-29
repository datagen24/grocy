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
| 02 | [MCP endpoint](02-mcp-endpoint.md) ([interface spec](../mcp-interface-spec.md)) | — | — | medium | draft |
| 03 | [Category level minimum stock](03-category-min-stock.md) | [#2616](https://github.com/grocy/grocy/issues/2616) | — | small | draft |
| 04 | [Seed product datasets](04-seed-datasets.md) | [#2679](https://github.com/grocy/grocy/issues/2679) | — | medium | draft |
| 05 | [Store specific shopping lists](05-store-shopping-lists.md) | [#2702](https://github.com/grocy/grocy/issues/2702) | — | medium | draft |
| 06 | [Location barcodes](06-location-barcodes.md) | — | — | small | draft |
| 07 | [Deeply nested products](07-nested-products.md) | — | — | **large**, or very small | **blocked on its own Q6** |
| 08 | [Deeply nested locations](08-nested-locations.md) | — | — | medium | draft |
| 09 | [Barcode lookup sources for US products](09-barcode-lookup-sources.md) | — | — | small | **deferred** |

## Hardening

Remedial work from [docs/architecture-review.md](../architecture-review.md). The review's
own defects table (items 1–13) is already fixed in `36650cd`; these are everything else it
found, plus the 2026-08-29 [security sweep](../security-sweep.md). They add no features
and block no feature plan, but 12 and 14 should land before the plans noted below start
minting more of what they clean up, and the sweep's four High findings land before
anything at all — see the hotfix in wave 0.5 below.

| # | Plan | From | Depends on | Size | Status |
|---|---|---|---|---|---|
| 10 | [Cold start and statelessness](10-cold-start-statelessness.md) | Review §Statelessness, order item 2 | — | medium | draft (its `bin/victual-migrate` landed early, in wave 0) |
| 11 | [API error handling, auth surface and error logging](11-api-error-handling.md) | Review §API surface, order item 3, deferred defect 9 | 14 (soft) | medium | draft |
| 12 | [Frontend shared core](12-frontend-shared-core.md) | Review §Frontend, order item 4, oddities list | — | medium | draft |
| 13 | [Write-path transactions](13-write-path-transactions.md) | Review §Services, order item 5 | — | small | **landed** (`7abfd2fa`, `782289b8`, `96f9ec99`) |
| 14 | [Contract and regression scaffolding](14-contract-and-regression-scaffolding.md) | Review §API surface, order item 6 | — | medium | **pieces 1, 3, 4 landed** (wave 0); piece 2 outstanding |
| 15 | [Deliberate cleanup batch](15-deliberate-cleanup.md) | Review §Backend, §Uniformity, parked 05-Q4, sweep S4–S6, S17–S19 | 11, 13, 14 (per item) | small + one large open question | draft |
| — | [Security sweep hotfix](../security-sweep.md) (S1, S2, S3, R1) | [docs/security-sweep.md](../security-sweep.md) | — | small | **open — lands before wave 1** |

## Meta

| # | Plan | Upstream | Depends on | Size | Status |
|---|---|---|---|---|---|
| 16 | [Project rename](16-project-rename.md) | — | before first deployment | medium | **landed in the codebase**; registry/domain claims wait for announcement |
| 17 | [Ecosystem clients](17-ecosystem-clients.md) | — | 14 supplies the mechanism; was to be read before 11 and 16 | small, ongoing | **draft, and overtaken by 16** — see below |

The fork is **Victual**. Tiers 1–3 of 16 all landed while nothing was deployed,
so `GROCY_*` is `VICTUAL_*`, the namespace is `Victual\`, the database file is
`victual.db`, the bin scripts are `bin/victual-*`, the spec is
`victual.openapi.json`, and `GET /api/system/info` answers `victual_version`.
Anything written from here forward uses those names. What is *not* renamed, ever,
is the `grcy:` grocycode magic and the format's name — see 16's Tier 0. The repo
rename and the registry claims happen at announcement time, not in a commit.

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
- **10's `bin/victual-migrate` precedes 14**, not the other way round — the one place the
  wave order below overrides the plan numbering, and why the CLI is pulled into wave 0.
- **17 before [11](11-api-error-handling.md), [16](16-project-rename.md) and
  [10](10-cold-start-statelessness.md)** — the first two break third-party clients and 17
  is where the cost of each candidate decision is written down. 10 is there for a different
  reason: the Home Assistant integration polls every thirty seconds, so scale-to-zero is not
  achieved by 10 alone. 17 also asks 14 for client endpoint manifests asserted against the
  snapshot, so it wants reading before 14 piece 2 is built.

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
- **The sweep's auth findings ride with wave 2, not ahead of it.** S4 (reverse-proxy
  trust), S5 (`DEFAULT_PERMISSIONS`), S6 (`USERS_EDIT` escalation), S17 (dead iCal
  branch, cross-instance construction), S18 (`AUTH_CLASS` type check) and S19 all live
  in the files 11 and 15-C1/B1 rewrite. Fixing them first means doing the auth refactor
  twice; they are added to 15's tables and land in that wave.
- **Three sweep items are constraints on plans, not work of their own.** S11 (the
  query-string API key path) must not be inherited by [02](02-mcp-endpoint.md)'s bearer
  seam, and S4's trusted-proxy pattern is the model for it; S14 (barcode filename and
  image fetch) is inherited by [09](09-barcode-lookup-sources.md) before it adds lookup
  sources; S15, S16 and R1's `/system/config` contract test go into 14 piece 2.

Each plan carries a **Verification** section: booted-instance checks and result-set diffs
against a real database, following the standard the defects pass set. Lint is not
verification.

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

## Order of operations

The single sequence to work from, features and hardening interleaved. Constraints it
encodes: 14's suite unblocks every other plan's verification; 12 must precede the plans
that add list/form pairs (05/06/08); 11, 13, 15-C1 and 14's snapshot precede 02; 08
proves the recursive pattern before 07 spends it; 10 then 01 produce the volume-less
pod. Waves are strictly ordered; tracks inside a wave touch disjoint files and can run
as parallel sessions.

Wave 0 is complete and wave 1's track C is done; the rest is unstarted. Wave 4's shape
is no longer settled — see 07-Q6 there. Two things now sit between wave 0 and wave 1:
a hotfix the security sweep forced, and a decision 17 has been owed since 16 landed.
Neither is a wave; both are a single sitting, and wave 1 does not start until both are
done.

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
  - **R1** — `BaseController` and `SystemApiController::GetConfig` test
    `substr($constant, 0, 19)` against a 21-character prefix, so every feature flag is
    dropped from the UI and the API. `str_starts_with` and `substr(…, 8)`. A regression
    from 16, recorded in 16's Executed section.

  Verification is a booted instance, not a diff: upload an `.svg` and confirm it
  downloads rather than renders; read the `Set-Cookie` header; POST a product
  description of `&lt;script&gt;` and confirm it comes back as text; open the consume
  form with location tracking enabled and confirm the field is there.
- **17-Q2 and 17-Q4, answered.** Q4 (does the server keep accepting `GROCY-API-KEY`,
  and for how long) gates 11; Q2 (does the Home Assistant fork poll through grocy-py or
  reimplement against `/system/db-changed-time`) gates 10, because thirty-second polling
  defeats the scale-to-zero 10 exists for. Q1 and Q3 can wait; these two cannot, and the
  "17 before 11 and 10" rule above is otherwise broken a second time.

### Wave 1 — platform (three parallel tracks, disjoint files)

- **Track A: 10 cold start**, then **01 file storage**. 10 first — 01's importer is
  easier to reason about once cold start no longer rewrites requests. Together they end
  the PVC. 10 is the first plan to publish an image from the Dockerfile, so sweep S25
  (`.dockerignore`, non-root `USER`) is 10's, and 01 inherits S2's per-group
  extension allow-list and S10's upload cap when it moves storage into the database —
  a blob column with no size limit is the same DoS with a different disk.
- **Track B: 12 frontend shared core.** Land steps 1–2 as their own PR: the four latent
  bug fixes plus the `request()` core with `timeout`/`onerror`. Note what that PR does
  *not* deliver — all 148 `console.error` handlers are passed explicitly, so the default
  error toast cannot fire until they are deleted, and those deletions belong to the
  files step 3 rewrites wholesale. The handler deletions therefore ride with the factory
  conversions, per file, so each one is exercised as it lands.
- **Track C: 13 write-path transactions** — **done**, ahead of the rest of this wave
  (`7abfd2fa`, `782289b8`, `96f9ec99`, 2026-08-29). All seven entrypoints, webhook after
  commit, and the importer made atomic. Tracks A and B are still open.

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
  The remaining 15 one-liners (C3–C9) ride along with whatever wave has the file open;
  C10 stays deferred until after 13, then folds in here or later.
- **S8, S9, S12** land here too, as they are 11's territory: `Origin` check on
  cookie-authenticated non-GET API requests and `GET /manageapikeys/new` / `GET /logout`
  made POST (S8); the 500 page's trace and system info gated on `dev` and escaped
  (S9 — 11 already owns the error surface); login throttling and a forced change while
  the seeded `admin`/`admin` hash is in use (S12).

### Wave 3 — first features on the new platform

- **09 implementation**, if wave 0's experiment justified it — inheriting sweep S14
  first (filter `__barcode` to a filename-safe class, allow-list the image extension,
  refuse loopback and private hosts before fetching `__image_url`), since every source
  09 adds is another party that chooses that URL.
- **06 location barcodes** — the first shipped dual-engine migration (deliberately
  small), on the locations list/form pair 12 just converted. Codes, printing, UUID, QR;
  camera ingest stays unscoped.
- **03 category minimums** — one column, one new view, group shortfalls kept out of
  `stock_missing_products`.

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
  failure paths it records. This freezes the API surface 02 builds on. It also takes
  three sweep items that are contract work rather than fixes: body validation against
  the entity's OpenAPI schema (S16 — `id` and `row_created_timestamp` stop being
  writable through the generic endpoints), a length/complexity bound on the `§` regex
  operator written into the filter contract `filterdifftest.php` already measures
  (S15), and a test that `/system/config` returns at least `FEATURE_FLAG_STOCK` so R1
  cannot recur.
- **02 MCP, read-only v1** — separate container per its Q6 response, bearer key
  behind the credential→user seam per the IdP note. Two sweep constraints: the seam
  does not accept a key from the query string (S11 — the server's own query path is
  removed in wave 2 and the sidecar must not reintroduce it), and sidecar→server trust
  follows S4's trusted-proxy pattern rather than a shared header alone.
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
- **Deleted, whenever a PR next touches the root**: `update.sh` (sweep S13, rigor
  review H3) — it wipes the install and unpacks an unsigned upstream Grocy zip. Added
  to 15's non-breaking table so it has a home; it needs no wave.
- **Not scheduled, recorded**: sweep S20–S24 (Host-header redirects, wildcard CORS,
  integer ids concatenated into SQL behind `FILTER_VALIDATE_INT`, `Content-Disposition`
  quoting, Actions pinned to tags). Each is a one-liner that rides with whichever wave
  opens the file; S21 waits on 17 to say which browser clients exist.

Every wave ends mergeable: nothing in a later wave reworks what an earlier wave
shipped, and each track lands through its own PR with its plan's Verification section
executed on a booted instance.
