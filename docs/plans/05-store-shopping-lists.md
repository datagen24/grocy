# 05. Store specific shopping lists

**Goal:** Segregate and sort shopping lists by store, and give products and recipes a
default list.
**Upstream:** [grocy/grocy#2702](https://github.com/grocy/grocy/issues/2702)
**Status:** draft for review.

## Today

Most of the pieces exist and are simply not connected:

- `shopping_locations` — stores. Referenced by `products.shopping_location_id` (a product's
  default store), `stock.shopping_location_id`, `stock_log.shopping_location_id` and
  `product_barcodes.shopping_location_id`.
- `shopping_lists` — named lists. `shopping_list.shopping_list_id` puts an item on one.
- `shopping_list` — items, with a nullable `product_id`, a `note`, `amount`, `qu_id`,
  `done`.

What is missing: a list has no store, an item's store is only implied by its product's
default, and there is no per-store ordering, so a list cannot be sorted to match how you
actually walk a shop.

## Proposed change

Three separable pieces. They can ship independently and probably should.

### A. Lists know their store

`ALTER TABLE shopping_lists ADD shopping_location_id INTEGER` — nullable, so existing lists
stay general purpose and nothing changes for anyone who ignores the feature.

Enables filtering the shopping list page by store and, with C, sorting it usefully.

### B. Store layout ordering

The point of a store specific list is walking the shop in order. That needs a per store
ordering of *something*. Two candidates, and this is the main design fork (Q1):

- **Order product groups per store.** A new table
  `(shopping_location_id, product_group_id, sort_number)`. Small — a store has maybe 15
  groups — and a product inherits its position from its group. Setup is one screen.
- **Order locations per store.** Reuses [08](08-nested-locations.md)'s tree but conflates
  where a thing is stored at home with where it sits in a shop, which are different things.

Product groups is the better fit. "Produce, bakery, dairy, frozen" is exactly a store
layout, and grocy users already maintain groups.

### C. Default list per product and per recipe

`ALTER TABLE products ADD default_shopping_list_id INTEGER` and the same on `recipes`, so
adding a product or a recipe's missing ingredients lands on the right list without
choosing every time. Falls back to the current behaviour when null.

### API

All additive: three nullable columns on existing exposed entities, plus one new entity for
the ordering table which goes in `ExposedEntity`. No existing response changes shape.

### UI

- Store selector on the shopping list form.
- A sort-by-store-layout toggle on the shopping list page.
- One screen to drag product groups into order per store.
- Default list pickers on the product and recipe forms.

## Open questions

1. **Order by product group or by something else?** See B. I recommend product groups. If
   your shops are laid out in a way groups do not capture, say so, because that changes the
   table.

   > **Response:** Product groups. Home-storage location is the wrong axis for shop
   > layout, and 08's tree should not be bent into that role.
2. **Is per-store ordering worth it at all for you**, or is filtering by store enough? A
   store filter alone is a fraction of the work and might be the whole benefit in practice.

   > **Response:** Ship A + C first exactly as the effort section proposes, and let
   > real shopping trips decide whether B happens. Filtering may well be 80% of it.
3. **What happens to an item whose product's default store is not the list's store?** Hide
   it, show it greyed, or show it normally? I lean to showing everything and only using the
   store for ordering, since "I am at this shop, buy what I can here" is better served by
   sorting than by hiding things you might still want.

   > **Response:** Show everything, use the store only for sort. Hiding rows from a
   > shopping list is how things fail to get bought.
4. **Should `shopping_locations` be renamed to `stores`?** It is the clearer word and this
   is a hard fork. But it is a table rename on an exposed entity, so it breaks goal 1 —
   probably a "later, deliberately, with the other breaking changes" item rather than
   something to slip in here.

   > **Response:** Not now — and [15](15-deliberate-cleanup.md) Q5's review landed
   > on declining the rename outright, including the compatibility-view middle path,
   > unless a breaking batch happens for other reasons.
5. **Recipe default lists** — per recipe, or per meal plan section? Per recipe is simpler
   and matches the issue.

   > **Response:** Per recipe.

## Review notes

- For the B ordering table: `(shopping_location_id, product_group_id, sort_number)`
  with a unique constraint on the pair; the drag-to-order UI already exists in spirit
  on meal plan sections, so copy that pattern rather than inventing one.
- `product_groups` is quietly becoming a hub — [03](03-category-min-stock.md) gives it
  a minimum, this plan a per-store position. Fine (it is the natural place), but it
  moves the group picker from "optional taxonomy" to load-bearing master data.

## Effort

Medium. A alone is an afternoon. C is another. B is the bulk, mostly UI for the ordering
screen. Recommend shipping A + C first and treating B as its own change once you have used
A for a bit and know whether ordering is worth it.
