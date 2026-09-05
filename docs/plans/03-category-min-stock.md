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

**A new view, `product_groups_missing`, and no change to `stock_missing_products`.** For
each group with `min_stock_amount != 0`, one row carrying the group, its minimum and the
missing amount — the minimum less the summed stock of its active products.

This is Q1's response rather than the design this section first proposed, and the two
differ in a way worth stating plainly, because the earlier one is the obvious one. The
first draft added a *third `UNION` branch* to `stock_missing_products`, which is where
group shortfalls naturally belong if you are thinking about the view. The problem is what
that view is *for*: every one of its rows is keyed by a `products.id`, and
`AddMissingProductsToShoppingList` reads that key to add a shopping list item. A group
shortfall has no single product to add — that is the whole point of the feature, that you
do not care which milk — so a third branch would either emit a null-keyed row into a
consumer that dereferences the key, or invent a product to name. Putting the rows in
their own view removes the question instead of answering it carefully.

The sum is built from each product's **own, non-aggregated** stock across the group's
members, not from `stock_current`'s aggregated rows. Q3 has the trap: if the sum came from
rows that already fold children into parents *and* both are in the same group, the stock
counts twice — which is true today for nothing, and becomes true the moment
[07](07-nested-products.md) makes the tree deep. Building it the right way now costs
nothing.

Inactive products are excluded, matching the existing branches' `IFNULL(p.active, 0) = 1`
(Q4). Group minimums and per product minimums are independent: a product below its own
minimum is short regardless of its group, and a group below its minimum is short
regardless of its members (Q2).

### API

`product_groups` is in `ExposedEntity`, so `/objects/product_groups` gains a field —
additive.

**`/stock/volatile` does not change shape.** It is fed by `stock_missing_products`, which
this plan no longer touches; that is the second reason Q1 landed where it did, and it is
what keeps goal 1 intact without an argument. `product_groups_missing` is a new view and
reaches clients only if it is added to `ExposedEntity`, which is additive when it happens.

**Client impact: none.** No existing response changes shape or field set; a new column on
an existing entity and, optionally, a new entity. Per [17](17-ecosystem-clients.md)'s
mechanism, this line is here even though it reads "none".

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

Small. Q1 is settled, so this is one column, one new view and one form field, with no
shopping list interaction and no `/stock/volatile` compatibility question — both were
costs of the auto-adding design Q1 declined. The note-only shopping list row remains the
follow-up if the feature proves itself.

**If [07](07-nested-products.md)'s Q6 lands on *taxonomy*, this plan grows.** Q6's
response says that if the requirement is a classification rather than a packaging
relation, then nesting `product_groups` is the right change and most of 07 is
unnecessary — which puts a `parent_product_group_id` column in this plan's territory and
makes the group sum above recursive. That would move 03 from small to medium and pull it
to the front of wave 3. It cannot be settled from the code; it needs the real catalogue.
Until Q6 answers, this plan is scoped as written and the roadmap's Status row records the
contingency.
