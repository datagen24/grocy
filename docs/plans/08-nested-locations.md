# 08. Deeply nested locations

**Goal:** Locations form a tree — floor / room / cabinet / shelf — rather than a flat list.
**Depends on:** [12](12-frontend-shared-core.md) and [14](14-contract-and-regression-scaffolding.md),
per the README. Worth doing before [07](07-nested-products.md), which needs the same
recursive pattern against far more call sites.
**Status:** draft for review.

## Today

`locations` is flat, and has been since `migrations/0002.sql`:

```
id, name (unique), description, row_created_timestamp, is_freezer, active
```

Stock rows carry a single `location_id`. `stock_current_locations` and
`stock_current_location_content` group by it directly. There is no notion of containment,
so "what is in the kitchen" can only be answered if every shelf is literally named
"Kitchen — …".

## Proposed change

### Schema

`ALTER TABLE locations ADD parent_location_id INTEGER` — the same shape as
`products.parent_product_id`, which keeps the two hierarchies conceptually identical and
lets 07 reuse whatever is built here.

One wrinkle: `locations.name` is `UNIQUE` today. In a tree, two different rooms each having
a "Top shelf" is entirely reasonable. Options in Q1.

### Views

New `locations_resolved` as a recursive CTE producing `(ancestor_location_id,
descendant_location_id, depth)`, plus a `path` string for display ("Kitchen / Pantry /
Top shelf"). `WITH RECURSIVE` works on both engines; `quantity_unit_conversions_resolved`
already ships a `path` column built this way, so there is a pattern to copy including the
string building.

`stock_current_locations` and `stock_current_location_content` stay as they are — they
answer "what is stored at exactly this location", which remains a valid question. Roll-up
becomes a separate concern, joined through `locations_resolved` where wanted, rather than
a change in meaning of an existing view. That keeps this additive.

### Triggers

Cycle prevention on insert and update, same as the recipe nesting guards. Deleting a
parent needs a decision — Q2.

### API

- `locations` gains `parent_location_id`. It **is** in `ExposedEntity`, so
  `/objects/locations` responses gain a field. Additive, and consistent with how upstream
  has added columns before.
- `locations_resolved` added to `ExposedEntity` so clients can fetch the tree in one call
  rather than walking parents.

### UI

Location dropdowns are the visible work: they appear on purchase, consume, transfer,
inventory, the product form and stock entry. They should show the path rather than the
bare name, or indent by depth. That is a shared partial, so it is one change in several
templates rather than several changes.

## Interaction with `is_freezer`

`is_freezer` is per location today. In a tree, a freezer compartment inside a freezer
inside a kitchen raises the question of whether the flag inherits. Grocy uses it for
freezing/thawing due date handling, so getting it wrong changes due dates. See Q3.

## Open questions

1. **Drop the `UNIQUE` on `locations.name`?** A tree makes duplicate leaf names normal.
   Options: drop it entirely; replace with `UNIQUE(parent_location_id, name)`, which allows
   "Top shelf" in two rooms but not twice in one; or keep it and make users disambiguate.
   I lean to `UNIQUE(parent_location_id, name)` — note that in PostgreSQL, NULLs are
   distinct by default, so several root locations could share a name unless
   `NULLS NOT DISTINCT` is used, which is PostgreSQL 15+.

   > **Response:** `UNIQUE(parent_location_id, name)` with `NULLS NOT DISTINCT`
   > (this fork can require 15+); on SQLite the equivalent is a unique
   > **expression** index on `(IFNULL(parent_location_id, -1), name)`. That makes
   > this the first migration pair where the two engines need genuinely different
   > DDL for the same rule — a good, small test of the per-engine migration
   > convention.
2. **Deleting a parent that has children and stock.** Reparent children to the deleted
   node's parent, block the delete, or cascade? Blocking is safest and easiest to explain.

   > **Response:** Block. Reparenting silently rewrites history; cascade deletes
   > stock's location. Blocking with a clear message is honest.
3. **Does `is_freezer` inherit from an ancestor?** If yes, the effective value comes from
   `locations_resolved` and due date logic must use that rather than the row's own flag.
   If no, the user ticks it on each compartment. Inheriting is friendlier but touches due
   date behaviour, which is stock-correctness territory.

   > **Response:** Don't inherit, v1 — keep the flag literal, and get 90% of the
   > friendliness by defaulting the checkbox from the parent when creating a child
   > location. Inheritance can be revisited if the explicit flag proves annoying.
4. **Should stock roll up by default anywhere in the UI?** For example, should the stock
   overview's location filter for "Kitchen" include everything beneath it? I would say yes
   for filtering, no for the location content report, but this is a taste call.

   > **Response:** Agreed with the lean: roll up for filtering, not for the location
   > content report.
5. **Depth cap?** Same question as 07 Q3. Floor/room/cabinet/shelf is four, so any cap
   should be comfortably above that.

   > **Response:** Share one constant with 07; something like 6 clears
   > floor/room/cabinet/shelf with headroom.

## Effort

Medium. The schema and view are small and well understood; the UI dropdowns and the
`is_freezer` decision are the bulk. Two focused sessions, and it de-risks 07.
