# 16. Product substitutions

**Goal:** Record that one product can stand in for another — sharp cheddar for medium, a
block for a bag of shredded — as an explicit, directed, curated relation, and surface it
where someone is deciding whether they can cook something.
**Depends on:** [14](14-contract-and-regression-scaffolding.md) for fixtures,
[12](12-frontend-shared-core.md) for the list/form pair. Independent of
[07](07-nested-products.md) and [03](03-category-min-stock.md), which is the point.
**Status:** draft for review.

## Where this came from

[07](07-nested-products.md)'s question 6. The nested-products plan assumed substitution
was a property of the product tree — siblings under a common parent substitute for one
another, deeper is better, nearest first. Working through the owner's real catalogue
showed it is not a property of any tree:

- **Sharp cheddar for medium is sibling-to-sibling.** Nothing in grocy expresses that
  today (see below), so this is not "extend the existing mechanism deeper".
- **A block for a bag of shredded is directed.** A block substitutes for shredded because
  you can shred it; shredded does not substitute back. Anything derived from tree position
  is symmetric among siblings by construction.
- **A tree would produce substitutions nobody wants.** Whole-subtree, depth-ordered
  substitution under a `Dairy` node offers heavy cream in place of whole milk.

So substitution is its own relation. The classification half of 07 went to nested
`product_groups` in [03](03-category-min-stock.md); this is the other half, and it is the
only genuinely new work either half produces.

## Today

Grocy has one substitution mechanism, and understanding exactly what it does is most of
this plan, because it is easy to mistake for something more general than it is.

`products_current_substitutions` (`migrations/0200.sql:57`) reads:

> When a *parent* product has none of its own stock, resolve it to the *child* that the
> default consume rule would use next.

Four properties follow, all limiting:

1. **Parent-down only.** It sits on `products_resolved` (`migrations/0106.sql:18`), which
   is a flat `CASE` mapping a product to its parent or itself — not a recursion. There is
   no path in it from one child to another, so sibling-to-sibling is not expressible.
2. **One candidate, not a list.** The view yields a single `product_id_effective` per
   parent, chosen by `stock_next_use` ordering (opened first, then first due, then FIFO).
   There is no notion of a second choice.
3. **All-or-nothing on the parent's own stock.** It fires only when the parent has
   *exactly zero* of its own stock. A parent with some stock but not enough for the recipe
   resolves to itself and no substitute is offered.
4. **Preference is the consume rule, not a judgement.** Which child you get is decided by
   expiry dates. There is nowhere to say "prefer sharp over mild".

### The part that is easy to get wrong

**The substitution view does not make a recipe show as fulfillable. The parent roll-up
does.** In `recipes_pos_resolved` (`migrations/0249.sql:5`):

- `need_fulfilled` and `missing_amount` are computed from `sc.amount_aggregated` keyed on
  `rp.product_id` — and `stock_current`'s first `UNION` arm (`migrations/0233.sql`)
  already sums every child's stock into the parent, quantity-unit-converted.
- `product_id_effective` from the substitution view is used for `costs` and `calories`,
  and is read by `views/recipes.blade.php:541` to draw the exchange icon and name the
  child.

So the existing feature is: *roll-up decides whether you have enough; substitution decides
which jar to open and what it costs.* That division matters here, because this plan
deliberately does not add a roll-up. Whatever fulfilment behaviour is wanted has to be
built, not inherited.

## The actual work

The table is an afternoon. The work is deciding how far the relation is allowed to reach
into fulfilment, and doing that without changing the shape of responses that already
exist.

## Proposed change

### The table

One new table, portable across both engines:

```
product_substitutions
	id                      INTEGER PRIMARY KEY
	product_id              INT NOT NULL   -- what the recipe or list asks for
	substitute_product_id   INT NOT NULL   -- what may be used instead
	priority                INT NOT NULL DEFAULT 0  -- higher wins; ties broken by name
	note                    TEXT           -- "shred it first"
	row_created_timestamp   DATETIME
```

**Directed, one row per direction.** Sharp ↔ medium is two rows, recorded deliberately;
block → shredded is one. This is the whole reason the relation cannot live in a tree, so
it is not something to optimise away into a symmetric flag (Q1).

Guards, as triggers so both engines agree, following the pattern the recipe-nesting guards
already establish:

- `product_id != substitute_product_id` — a product is not its own substitute.
- unique on `(product_id, substitute_product_id)`.
- **No transitive resolution and therefore no cycle problem.** A → B and B → C does not
  imply A → C (Q4). This is a deliberate limit: transitivity is exactly how "cheddar for
  milk" reappears, two hops at a time.

### The view

A new view, `products_substitutions_available`, listing for each product every substitute
that currently has stock, ordered by `priority` then the substitute's name — a list, not a
single winner, which is the first thing the existing view cannot do.

`products_current_substitutions` is left exactly as it is. It answers a different question
about a different relation, two shipped views join it, and the additive-API ground rule
means its columns cannot move. Two mechanisms coexisting is the honest outcome here; the
alternative is rewriting parent/child substitution to serve a relation it was not built
for, which is the mistake 07 was making.

### Where it surfaces

**Recipes — advisory, not auto-satisfying (Q3).** `recipes_pos_resolved` gains new columns
naming the best available substitute; `need_fulfilled` and `missing_amount` keep their
current meaning. So a recipe wanting 200 g of shredded, with none in stock and a block on
the shelf, still reads as *not* fulfilled — and says "you have a block". That is the
behaviour the owner described: be told, not silently satisfied.

**Stock overview — no change.** No roll-up, no new rows in `stock_current`, `/stock`
untouched. This also keeps the Home Assistant consumer out of it, which 07's review note
worried about.

**Shopping list — deferred.** "Do not add cheddar, you have a substitute" is a plausible
next step and a separate decision; nothing here forecloses it.

### Schema

One portable `NNNN.sql` — one table, its guards, one view. No engine-exclusive case: there
is nothing here PostgreSQL and SQLite disagree about. Numbering picks the next free value
at implementation time; `0256.sqlite.sql` is taken and `0257.pgsql.sql` is claimed by
[01](01-file-storage.md).

### API

**Additive.** `product_substitutions` joins the `ExposedEntity` enum in
`grocy.openapi.json`, giving it CRUD through the generic entity routes for free.

`recipes_pos_resolved` gains columns, which is additive by the ground rule — but it is
called out here rather than slipped in, because it is a view two other things read.
Nothing existing changes type or meaning, and specifically `need_fulfilled` does not.

### UI

A list/form pair for maintaining substitutions, built on [12](12-frontend-shared-core.md)
rather than copied from an existing page — the substance of 12's own case. The recipe view
already has the exchange-icon idiom at `views/recipes.blade.php:548`; the substitute
suggestion should read as visibly different from it, because "you may use this instead" and
"this is what will be consumed" are not the same claim.

## Verification

Fixtures in [14](14-contract-and-regression-scaffolding.md)'s suite, which is what makes
this checkable at all:

1. **A view seed** exercising `products_substitutions_available` and the changed
   `recipes_pos_resolved`, compared across both engines by `difftest.php`. Cases: no
   substitute; one with stock; one without stock; two competing on `priority`; a directed
   pair where only one direction is recorded — the last is the one that would silently
   pass if direction were dropped.
2. **A trigger script** for the guards, compared by `trigdifftest.php`: self-reference and
   duplicate rejected on both engines.
3. **A regression check that `need_fulfilled` did not move.** The point of Q3's answer is
   that fulfilment semantics are unchanged; the recipe seeds that exist today should
   produce identical `need_fulfilled` and `missing_amount` before and after.
4. **Deleting a product** removes rows pointing at it from both directions, checked as
   trigger behaviour rather than assumed from foreign keys.

## Open questions

1. **Two rows per symmetric pair, or one row plus a `bidirectional` flag?** Two rows is
   proposed: the flag saves storage nobody is short of and costs a `UNION` in every read,
   and the UI has to present two directions either way.
2. **Units across a substitution.** Sharp and medium cheddar are both grams and need
   nothing. A block measured in pieces substituting for shredded measured in grams needs a
   factor, and `cache__quantity_unit_conversions_resolved` is per product, so it will not
   supply one. Options: a `factor` column on the row; requiring both products to share a
   stock unit and rejecting the row otherwise; or leaving amounts uncompared and only
   naming the substitute. **Needs answering before the view is written** — it decides
   whether the view can say "you have enough" or only "you have some".
3. **Advisory or auto-satisfying?** Proposed advisory, per the reasoning above. The
   opposite choice makes `need_fulfilled` substitution-aware, which changes an existing
   response and would need saying so loudly.
4. **Transitivity?** Proposed no. Recorded as a limit rather than an omission.
5. **Should a substitution be scoped to a context** — fine in a recipe, wrong on the
   shopping list, or fine for cooking but not for a specific recipe that depends on the
   texture? Proposed no, on the grounds that nobody has asked for it yet and a `context`
   column added later is additive.
6. **Where do substitutions get recorded in practice?** A dedicated page is the obvious
   answer and probably the wrong one — the moment you want a substitute is while looking at
   a product or a failing recipe. Worth deciding with 12's patterns in hand rather than now.

## Effort

Medium. The table, guards and view are small and portable. The cost is in the fixtures —
the directed-pair and priority-ordering cases are exactly the ones a careless
implementation passes by accident — and in Q2, which is a modelling question rather than a
coding one.

Worth doing after [03](03-category-min-stock.md)'s nested groups, so that browsing by class
and substituting within it can be judged together rather than one at a time.
