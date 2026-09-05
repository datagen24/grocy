# 03. Category level minimum stock

**Goal:** Set a fallback minimum for a product group — "always have *some* milk" — instead
of having to set one on every individual product.
**Upstream:** [grocy/grocy#2616](https://github.com/grocy/grocy/issues/2616)
**Status:** draft for review; scheduled for wave 3b **as written**. Decided 2026-09-04:
this plan does not wait on [07](07-nested-products.md)'s Q6. If Q6 lands on *taxonomy*,
the nullable `parent_product_group_id` column lands as an additive follow-on to this plan,
not as a change to its scope now.

## Today

Minimums are strictly per product: `products.min_stock_amount`, with
`cumulate_min_stock_amount_of_sub_products` letting a parent's minimum be satisfied by the
sum of its children.

`stock_missing_products` computes what is short. It is a `UNION` of two branches — products
that do not cumulate, and parents that do — and both filter on
`WHERE p.min_stock_amount != 0`. `StockService::AddMissingProductsToShoppingList()` reads
that view and is called after purchase, consume and product edits.

`product_groups` is a plain lookup table: `id, name, description, active`.

A group minimum would support the same requirement without creating a parent product
solely to represent a category.

## Proposed change

### Schema

`ALTER TABLE product_groups ADD min_stock_amount` — `DOUBLE PRECISION NOT NULL DEFAULT 0`
on PostgreSQL, matching how amounts are typed throughout (see `db/pgsql/README.md`
hazard 2 — this must not be `INTEGER`, since `products.min_stock_amount` demonstrably
holds fractions).

### Views

**A new view, `product_groups_missing`, and no change to `stock_missing_products`.** For
each group with `min_stock_amount != 0`, one row carrying the group, its minimum and the
missing amount — the minimum less the summed stock of its active products.

`stock_missing_products` must retain product-keyed rows because
`StockService::AddMissingProductsToShoppingList()` uses each row's product id. A group
shortfall has no single product to add, so it needs a separate view (Q1).

Sum each member product's own, non-aggregated stock. Summing rows that already include
child stock would double-count it when both parent and child belong to the group (Q3).

Inactive products are excluded, matching the existing branches' `IFNULL(p.active, 0) = 1`
(Q4). Group minimums and per product minimums are independent: a product below its own
minimum is short regardless of its group, and a group below its minimum is short
regardless of its members (Q2).

### API

`product_groups` is in `ExposedEntity`, so `/objects/product_groups` gains a field —
additive.

`/stock/volatile` retains its existing shape because `stock_missing_products` is
unchanged. Exposing `product_groups_missing` through `ExposedEntity` would add a new
read entity.

**Client impact:** additive `min_stock_amount` field on `product_groups`, and optionally
a new read entity. Existing fields and `/stock/volatile` remain unchanged.

### UI

A minimum field on the product group form, and some indication on the stock overview that
a group is short. Reusing the existing "below minimum stock" styling is the cheap path.

## Open questions

1. **What does a group shortfall resolve to on the shopping list?** This is the whole
   design. Options:
   - **Do not auto-add.** Show the group as short on the overview; the user picks what to
     buy. Simplest, no API shape change, and arguably correct — the point of the feature is
     that you do not care *which* milk.
   - **Add the group's default product.** Needs a new `product_groups.default_product_id`.
     Concrete and automatable, but reintroduces "which one" in a different place.
   - **Add a note-only shopping list row.** `shopping_list.product_id` is nullable and
     `note` exists, so "Milk (any)" is already representable. Nice fit, but such a row
     cannot be checked off against stock automatically.

   I lean to the first for v1, with the third as a follow-up. It keeps the change to one
   view and one column.

   > **Response:** Option 1 for v1 — and keep group shortfalls in a **new** view
   > (`product_groups_missing` or similar) rather than a third branch of
   > `stock_missing_products`: that removes the `/stock/volatile` question entirely
   > instead of resolving it carefully. The note-only row is a good follow-up once
   > the feature has proven itself.
2. **Does a group minimum interact with per product minimums, or override them?** I
   propose independent: a product below its own minimum is short regardless of group stock,
   and a group below its minimum is short regardless of individual products. Overlap is
   possible and probably fine.

   > **Response:** Agreed, independent.
3. **Should group stock count sub products?** If a group contains a parent product,
   presumably its children's stock counts toward the group. That falls out naturally if the
   branch aggregates through `products_resolved` — and becomes a real question once
   [07](07-nested-products.md) makes that recursive.

   > **Response:** Aggregate per product, not via `stock_current`'s aggregated rows.
   > Concrete trap: if the group sum is built from rows that already aggregate
   > children into parents *and* the children are themselves in the same group, the
   > stock counts twice. Sum each product's own non-aggregated stock across the
   > group's members; that stays correct when 07 makes the tree deep.
4. **Do inactive products count?** Proposing no, matching the existing branches'
   `IFNULL(p.active, 0) = 1`.

   > **Response:** Agreed, exclude.

## Scope and dependencies

Small: one column, one view, a form field, and an overview indication. Automatic
shopping-list entries are out of scope for v1.

This plan is scheduled for wave 3b and does not wait for [07](07-nested-products.md)'s Q6.
If Q6 selects taxonomy, nested product groups are an additive follow-up. That follow-up
would require a parent-group column and recursive aggregation; it does not expand this
plan's current scope.
