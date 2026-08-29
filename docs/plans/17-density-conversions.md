# 17. US customary volume↔weight (density) conversions

**Goal:** Give the conversion model somewhere to keep a density, so that a cup of flour and
a gram of flour are relatable without the claim being smuggled into whatever row happened
to need it.
**Depends on:** [14](14-contract-and-regression-scaffolding.md) for fixtures. Paired with
[16](16-product-substitutions.md), whose Q2 factors are where these claims currently land.
**Status:** stub promoted to a numbered plan; every question below is open.

## Problem

Grocy's `quantity_unit_conversions` assumes a conversion is either global (tsp→tbsp) or
per-product, and in both cases scalar. US customary cooking crosses volume and weight
constantly, and the true conversion is density-dependent — per product *and* per form:

- packed vs. sifted flour differ by ~20%
- whole vs. ground spices differ enormously by volume
- brown sugar packed vs. loose

A single per-product factor cannot represent this. Today the gap is papered over per-pair
in [16](16-product-substitutions.md)'s substitution factors, which works but scatters
density claims across substitution rows instead of owning them in one place.

## Today

Worth stating precisely, because it turns the first two questions below from stylistic into
forced.

`quantity_unit_conversions` (`migrations/0082.sql`) is
`(from_qu_id, to_qu_id, factor, product_id)`, where a null `product_id` is the global
conversion and a set one is a per-product override. Two mechanisms then act on every row:

- **Uniqueness is enforced per `(from_qu_id, to_qu_id, product_id)`** by the
  `qu_conversions_custom_constraint_INS` trigger (`migrations/0254.sql:133`), which exists
  because SQLite's unique constraints ignore nulls. **This is the structural blocker.**
  Packed and sifted flour are the same cup→gram pair for the same product, and the schema
  can hold exactly one row for that. Form-qualified densities are not something the current
  table can express by being used more carefully; it has to change or be joined by
  something else.
- **Inverses and transitive paths are derived automatically.** An insert generates the
  reciprocal row, then rebuilds `cache__quantity_unit_conversions_resolved` for every path
  through either unit (`migrations/0225.sql`). That cache carries a `path` column and is
  what the application actually reads — `RecipesService` and `StockService` query it
  directly in half a dozen places.

The second point cuts both ways and is the thing to be careful of. Anything inserted as a
conversion is propagated through every chain touching that unit, so one density claim can
reach conversions nobody entered — useful when it is right, and a wide blast radius when it
is wrong.

## Open questions

All open. This plan exists to hold them, not to have answered them.

1. **Does density live on the product (one canonical g/cup), or as a set of form-qualified
   conversions (product + preparation state)?** The uniqueness trigger above means the
   second cannot reuse the existing table unqualified — a `form` discriminator would have to
   join the key, which changes an existing constraint rather than adding to it.
2. **Relationship to `cache__quantity_unit_conversions_resolved`** — extend the existing
   model, or a parallel table the resolver consults? Extending inherits the transitive
   resolver for free, and inherits its blast radius with it. A parallel table keeps density
   claims isolated and auditable, at the cost of teaching every reader about a second
   source.
3. **Who maintains densities?** Hand-entered, Hermes via MCP, or seeded from USDA FDC —
   which carries gram weights per household measure and is already the candidate source in
   [09](09-barcode-lookup-sources.md). If FDC is a real seed source, this plan and 09 share
   a dependency worth noticing early.
4. **Should [16](16-product-substitutions.md)'s substitution factors eventually derive from
   densities rather than store independent ratios?** One source of truth against two places
   to be wrong. Note 16-Q2 already accepted that a factor crossing volume and weight *is* a
   density claim and put the accuracy burden on whoever maintains it; this question asks
   whether that burden can be moved here instead.
5. **Migration portability** — pure additive tables, so a portable `NNNN.sql` is expected;
   confirm nothing engine-specific creeps in. The existing conversion machinery is trigger
   heavy and the triggers are per engine, so anything touching them lands in dual-engine
   territory even when the table does not.

## Constraints

- **Additive only.** Existing conversion API response shapes unchanged.
- **Dual engine** (SQLite and PostgreSQL), with booted-instance verification, per the
  README's ground rules.
- Any change to the conversion triggers is compared with `trigdifftest.php`, since trigger
  behaviour is the one thing view diffing cannot see.

## Effort

Unknown until Q1 and Q2 are answered — they are the difference between a new nullable
column and a second resolver. Not scheduled; it sits behind
[16](16-product-substitutions.md), which is what will show whether hand-maintained per-pair
factors are actually painful enough to need this.
