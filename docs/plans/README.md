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

1. **06 location barcodes** — smallest, self contained, and a useful warm up for writing
   the first dual engine migrations.
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
