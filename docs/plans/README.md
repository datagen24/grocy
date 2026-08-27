# Fork roadmap

Action plans for the work this fork exists to do. Each is a draft for review, not a
commitment — the **Open questions** sections are numbered so they can be commented on
individually.

## Status

| # | Plan | Upstream | Depends on | Size |
|---|---|---|---|---|
| — | [Database abstraction / PostgreSQL](../../db/pgsql/README.md) | — | — | **done** |
| 01 | [File storage in the database](01-file-storage.md) | — | PostgreSQL | small |
| 02 | [MCP endpoint](02-mcp-endpoint.md) | — | — | medium |
| 03 | [Category level minimum stock](03-category-min-stock.md) | [#2616](https://github.com/grocy/grocy/issues/2616) | — | small |
| 04 | [Seed product datasets](04-seed-datasets.md) | [#2679](https://github.com/grocy/grocy/issues/2679) | — | medium |
| 05 | [Store specific shopping lists](05-store-shopping-lists.md) | [#2702](https://github.com/grocy/grocy/issues/2702) | — | medium |
| 06 | [Location barcodes](06-location-barcodes.md) | — | — | small |
| 07 | [Deeply nested products](07-nested-products.md) | — | — | **large** |
| 08 | [Deeply nested locations](08-nested-locations.md) | — | — | medium |
| 09 | [Barcode lookup sources for US products](09-barcode-lookup-sources.md) | — | — | small |

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

**Migrations from 0256 on work on every supported engine** — a portable `NNNN.sql`, or a
per engine pair. See `db/pgsql/README.md`.

**Verification.** Schema changes are checked with `.devtools/pgsql/difftest.php` (views)
and `trigdifftest.php` (trigger behaviour). New views must return identical output on both
engines unless the plan says otherwise and explains why.

## Suggested order

0. **09 barcode lookup**, or at least its Q1 — twenty barcodes off things in the pantry,
   run against each candidate source. Thirty minutes, and it decides both whether that plan
   is worth doing and whether 04 should ship barcode data at all.
1. **06 location barcodes** — smallest, self contained, and a useful warm up for writing
   the first dual engine migrations. Ship the codes and printing; the camera ingest API in
   its Q2 is unbounded until the camera side is specified and should not be scoped with it.
2. **03 category minimums** — one view change, one new column, no UI rework.
3. **08 nested locations** — introduces the recursive-hierarchy pattern on the simpler of
   the two trees.
4. **07 nested products** — same pattern, far more call sites. Doing 08 first means the
   pattern is already proven when the hard one starts.
5. **01 file storage** — independent of the above; slot in whenever the stateless
   deployment is wanted.
6. **05 store lists**, **04 seed datasets**, **02 MCP** — larger, more design freedom,
   and none of them block anything else.

07 is the one to be careful with: it is not "make the view recursive", it is an audit of
every place that assumes exactly one level of nesting.

The hardening plans interleave with this rather than queueing behind it: **14** wants to
come before 08 and 07 (step 3) so their fixtures have somewhere to live, **12** before 06
and 08 (steps 1 and 3), and **10** alongside 01 (step 5). **13**, **11** and **15** are
only ordered against 02, which is last anyway.
