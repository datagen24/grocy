# 03. Category level minimum stock

**Goal:** Set a fallback minimum for a product group — "always have *some* milk" — instead
of having to set one on every individual product.
**Upstream:** [grocy/grocy#2616](https://github.com/grocy/grocy/issues/2616)
**Status:** draft for review.

> **Scope added by [07](07-nested-products.md)'s question 6: nested `product_groups`.**
> 07 set out to make the *product* tree deep, and answering Q6 established that the tree
> actually wanted is a taxonomy — `Dairy / Cheese / Cheddar` — which is a classification
> of groups, not a packaging relation between products. That makes it this plan's
> territory rather than 07's: one nullable self-referencing `parent_group_id` on
> `product_groups`, a cycle guard, and the group pickers and reports learning to walk it.
>
> It is a natural fit here because a group minimum and a group hierarchy raise the same
> question — does "always have some dairy" mean the node or the subtree? — and answering
> it once is cheaper than answering it twice. The two pieces are still separable if this
> plan gets large.
>
> The substitution half of 07 went elsewhere, to [16](16-product-substitutions.md).
> Nothing about the taxonomy touches `stock_current` or `/stock`.

## Today

Minimums are strictly per product: `products.min_stock_amount`, with
`cumulate_min_stock_amount_of_sub_products` letting a parent's minimum be satisfied by the
sum of its children.

`stock_missing_products` computes what is short. It is a `UNION` of two branches — products
that do not cumulate, and parents that do — and both filter on
`WHERE p.min_stock_amount != 0`. `StockService::AddMissingProductsToShoppingList()` reads
that view and is called after purchase, consume and product edits.

`product_groups` is a plain lookup table: `id, name, description, active`.

So the shape of the feature already exists — "a minimum satisfied by any of a set of
products" is exactly what cumulated parent products do. The gap is that it only works if
you model the group as a parent product, which forces a fake product into your master data.

## Proposed change

### Schema

`ALTER TABLE product_groups ADD min_stock_amount` — `DOUBLE PRECISION NOT NULL DEFAULT 0`
on PostgreSQL, matching how amounts are typed throughout (see `db/pgsql/README.md`
hazard 2 — this must not be `INTEGER`, since `products.min_stock_amount` demonstrably
holds fractions).

### Views

A third `UNION` branch in `stock_missing_products`: for each group with
`min_stock_amount != 0`, the missing amount is the group minimum less the summed stock of
its active products.

The existing two branches are untouched, so per product minimums behave exactly as before.

The interesting question is what the branch emits. Every existing row is keyed by a
`products.id`, and `AddMissingProductsToShoppingList` uses that to add a shopping list
item. A group shortfall has no single product to add — see Q1, which is the central
design decision in this plan.

### API

`product_groups` is in `ExposedEntity`, so `/objects/product_groups` gains a field —
additive.

`stock_missing_products` is not exposed directly, but it feeds
`/stock/volatile`. If group rows are emitted from it, that response changes shape, which
would break goal 1. Q1 has to resolve in a way that avoids that, most likely by keeping
group shortfalls out of the existing view and putting them in a new one.

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

## Effort

Small, once Q1 is settled — one column, one view branch, one form field. If Q1 lands on
auto-adding, add a day for the shopping list interaction and the `/stock/volatile`
compatibility question.
