# 07. Deeply nested products

**Goal:** Support product hierarchies more than one level deep.
**Depends on:** nothing, but do [08 nested locations](08-nested-locations.md) first — same
pattern, far fewer call sites.
**Status:** draft for review.

## Today

`products.parent_product_id` exists and is used for the parent/sub product feature, but
exactly one level is supported, and that is enforced deliberately. From
`migrations/0130.sql`:

```sql
CREATE TRIGGER enfore_product_nesting_level BEFORE UPDATE ON products
BEGIN
	-- Currently only 1 level is supported
	SELECT CASE WHEN(( SELECT 1 FROM products p
		WHERE IFNULL(NEW.parent_product_id, '') != ''
			AND IFNULL(parent_product_id, '') = NEW.id ) NOTNULL)
	THEN RAISE(ABORT, 'Unsupported product nesting level detected ...') END;
```

(The typo in the trigger name is upstream's and is preserved in the PostgreSQL port.)

The resolution view is a flat `CASE`, not a hierarchy — `migrations/0106.sql`:

```sql
CREATE VIEW products_resolved AS
SELECT CASE WHEN p.parent_product_id IS NULL THEN p.id ELSE p.parent_product_id END
	AS parent_product_id, p.id AS sub_product_id
FROM products p WHERE p.active = 1;
```

So "the parent of X" is a single hop, and everything downstream inherits that assumption.

## The actual work

Making `products_resolved` recursive is the easy part and works identically on both
engines. The work is auditing everything built on the one-level assumption:

| Place | Assumption |
|---|---|
| `products_resolved` | one hop, `CASE` not recursion |
| `stock_current` | both UNION branches group by `parent_product_id` to aggregate sub product stock |
| `products_view.has_sub_products` | direct children only |
| `stock_missing_products` | `cumulate_min_stock_amount_of_sub_products` sums direct children |
| `products_current_substitutions` | picks a substitute among direct children |
| `enfore_product_nesting_level` | actively forbids depth > 1 |
| `enforce_min_stock_amount_for_cumulated_childs_INS/UPD` | cascades to direct children |
| `cascade_change_qu_id_stock` | rescales amounts for a product and its children |

Each needs a decision about what the operation *means* at depth: does a grandparent's
cumulated minimum include grandchildren? Does stock aggregate all the way up? My default
answer to both is yes — a tree where roll-ups stop after one level would be more confusing
than no tree at all — but that is a product decision, not a technical one.

## Proposed change

### Schema
No new columns. `parent_product_id` already carries the tree.

### Views
Replace `products_resolved` with a recursive CTE producing `(root_product_id,
sub_product_id, depth)` for every ancestor/descendant pair, plus each product paired with
itself at depth 0 (which is what today's `CASE` effectively does for roots). Keep the
existing column names so dependent views need minimal edits.

`WITH RECURSIVE` is supported by SQLite 3.8.3+ and PostgreSQL, so one definition serves
both — the same approach already used for `quantity_unit_conversions_resolved` and
`recipes_nestings_resolved`, both of which are ported and verified.

### Triggers
Replace `enfore_product_nesting_level` with a **cycle** check rather than a depth check:
walk ancestors from `NEW.parent_product_id` and reject if `NEW.id` appears. `recipes` has
exactly this in `prevent_infinite_nested_recipes_*`, which is ported and can be copied.

Optionally cap depth at something sane (5?) so a mis-click cannot create a pathological
tree — see Q3.

### API
Additive: `products_resolved` gains a `depth` column. It is not in `ExposedEntity`, so no
public response changes. `products.parent_product_id` semantics widen but its shape does
not change.

### UI
The product form's parent picker currently lists candidate parents; it must exclude the
product's own descendants, not just itself. Product lists that show a parent/child
indent will need to indent by depth.

## Verification

This is the change most likely to break something quietly. Before touching anything,
extend `.devtools/pgsql/` with a fixture that builds a three level tree and asserts on
`stock_current`, `stock_missing_products` and `products_current_substitutions` — so there
is a baseline of what one-level behaviour produces, and the deep-tree behaviour can be
compared against a deliberate expectation rather than against whatever falls out.

## Open questions

1. **Do roll-ups aggregate the whole subtree or only direct children?** I propose whole
   subtree for stock aggregation and for `cumulate_min_stock_amount_of_sub_products`.

   > **Response:** Whole subtree — a partial tree is worse than no tree.
   >
   > **Superseded by Q6:** with the taxonomy moving to `product_groups`, nothing rolls
   > up through `stock_current` at all.
2. **Can a product with its own children also be a child?** Required for depth > 2. Any
   reason to forbid mixed nodes?

   > **Response:** None — they must be allowed (depth > 2 requires them). The
   > fixtures should include a middle node that itself holds stock, because that is
   > where aggregation is most likely to double-count.
   >
   > **Superseded by Q6:** the intermediates become groups, which never hold stock.
3. **Cap the depth?** A cycle check is required for correctness; a depth cap is only
   guard-rail. I lean toward a cap of 5, configurable, mainly so a bad import cannot
   produce a 200 deep chain that makes every view slow.

   > **Response:** Yes, cap ~5 — and prefer a plain hard-coded sane cap over a
   > config knob if reaching config from the trigger is awkward: dual-engine
   > trigger-pair simplicity wins. The cycle check is the correctness item; copy the
   > recipe guards as planned.
4. **Substitution at depth.** When a grandparent is out of stock, should
   `products_current_substitutions` reach past direct children into grandchildren?

   > **Response:** Whole subtree, ordered by depth (nearest first).
   > Direct-children-only would be the one roll-up that stops early, violating Q1's own answer.
   >
   > **Superseded by Q6:** substitution is not derived from the tree at all. Depth
   > ordering is what offers heavy cream for whole milk.
5. **Is there a real use case beyond two levels** for you specifically? If the answer is
   "brand > variant > size", that is three, and worth designing for concretely rather
   than in the abstract.

   > **Response:** Answered — the shape is `MajorClass / SubClass / Item / Variant`,
   > three levels normally and four where a variant matters:
   >
   > ```
   > Dairy / Milk   / Whole
   > Dairy / Creme  / Heavy
   > Dairy / Cheese / Cheddar / Sharp
   > ```
   >
   > So: **four levels, not arbitrary recursion** — the same depth as 08's locations
   > tree. The recursive CTE is still the right implementation — a fixed four-level
   > join would be worse to read and no faster — but the plan is sized for a bounded,
   > shallow tree, and the depth cap in Q3 is a real bound rather than a formality.
   >
   > It also raises something larger than depth, which is now question 6.

6. **Is this a classification, or is it what `parent_product_id` means?** The tree in
   Q5 is a taxonomy: `Dairy` is a kind-of relation, not a packaging relation. Only the
   leaves are things you buy, hold and consume; `Dairy` and `Dairy/Milk` are labels.
   Upstream, `parent_product_id` means the opposite — a parent and its children are the
   *same product in different packagings*, which is precisely why stock rolls up to the
   parent and why siblings substitute for one another. Putting a taxonomy into that
   column reuses the mechanism for something it was not built for, and three of the
   answers already recorded above change on the real data:

   - **Q1's whole-subtree roll-up collides with quantity units.** `stock_current`
     aggregates in the parent's `qu_id_stock`. `Dairy/Cheese` can plausibly total in
     grams. `Dairy` cannot total milk in litres, cream in millilitres and cheese in
     grams — there is no unit for the parent to aggregate into. Either intermediate
     nodes carry a real stock unit and roll-up stops where the units stop agreeing,
     or roll-up is display-only and never enters `stock_current`. This needs deciding
     before the recursive view is written — it is the difference between changing
     `/stock`'s row set and not touching it. Note this also settles the Review note
     below: if roll-up stays out of `stock_current`, the Home Assistant consumer sees
     nothing new.
   - **Q4's whole-subtree substitution is wrong here.** Nearest-first correctly makes
     `Sharp` a substitute for `Cheddar`. It also makes `Heavy` cream a substitute for
     `Whole` milk, because both sit under `Dairy`. That is not a substitution anyone
     wants offered. Either substitution is capped at a small relative depth (1, i.e.
     today's behaviour), or it is opt-in per product, or the taxonomy does not live
     in `parent_product_id` at all.
   - **Q2's mixed middle node is now hypothetical rather than typical.** Nothing in
     the real tree stocks an intermediate node. The fixture should still cover it — a
     three-level tree with a stocked middle node is the double-counting case — but it
     is a robustness fixture, not a model of the real catalogue.

   Neither of the first two is a flaw in the recursive mechanics; both say the
   mechanics are being pointed at the wrong relation.

   > **Response:** Settle this before any of 07 starts — it decides whether 07 is the
   > largest item on the roadmap or one of the smallest, and it cannot be answered
   > from the code.
   >
   > If the requirement is purely taxonomy — browse and report by class, group the
   > shopping list by aisle — then **nesting `product_groups` is the right change and
   > this plan is mostly unnecessary**. That is [03](03-category-min-stock.md)'s
   > territory, one nullable parent column on a lookup table, and it costs none of
   > what 07 costs: no stock aggregation, no substitution semantics, no
   > `cascade_change_qu_id_stock`, none of the one-level audit at the top of this
   > plan.
   >
   > `parent_product_id` earns its cost only if the real requirement is that
   > `Dairy/Cheese/Cheddar/Sharp` and a plain `Cheddar` **share stock** — one pool
   > consumed and purchased through either name. That is a packaging relation, and it
   > is the only thing the existing column is built to express.
   >
   > The two are not exclusive. The likely honest answer is nested `product_groups`
   > for the taxonomy, and `parent_product_id` left at its current depth for the few
   > genuine same-product-different-packaging cases. If that is where it lands, 07
   > shrinks to whatever the packaging cases actually need, and Q1 and Q4 above are
   > rewritten against that narrower relation rather than against the taxonomy.
   >
   > The locations tree in [08](08-nested-locations.md) has no equivalent problem —
   > containment is exactly what `parent_location_id` would mean — which is one more
   > reason 08 goes first.
   >
   > **Answered — it is mostly taxonomy, and substitution is a third relation, not a
   > property of the tree.**
   >
   > The real requirement, in the owner's words: the tree is classification, but
   > substitution genuinely exists — sharp cheddar in place of medium; a block in
   > place of a bag of shredded, *because you can shred a block*.
   >
   > Those two examples are what settles this, and neither of them is
   > `parent_product_id`:
   >
   > - **Sharp for medium is sibling-to-sibling, which grocy cannot express at all
   >   today.** `products_current_substitutions` reads parent-down and one level
   >   only: when a *parent* has no stock of its own, consume the next *child*
   >   (`products_resolved` is a flat `CASE`, not a recursion). There is no path in
   >   it from one child to another, so this requirement is not "extend the existing
   >   substitution deeper" — the mechanism to extend does not do this at all.
   > - **Block for shredded is directed.** A block substitutes for shredded because
   >   shredding is a step you can take. Shredded does not substitute for a block.
   >   Every tree-derived substitution is symmetric among siblings by construction,
   >   so no amount of depth tuning expresses a one-way edge.
   >
   > So there are three relations here, and the plan as written conflated them:
   >
   > | Relation | Example | Where it belongs |
   > | --- | --- | --- |
   > | **Classification** | `Dairy / Cheese / Cheddar` | nested `product_groups` — a nullable `parent_group_id` on a lookup table, [03](03-category-min-stock.md)'s territory |
   > | **Substitution** | sharp → medium, block → shredded | a new explicit, directed edge table; curated, never inferred from tree position |
   > | **Same product, different packaging** | the upstream meaning | `parent_product_id`, left at its current depth |
   >
   > Consequences for the answers above, which are superseded rather than refined:
   >
   > - **Q1 (whole-subtree roll-up) does not happen.** With the taxonomy in
   >   `product_groups`, nothing rolls up through `stock_current` and the quantity-unit
   >   collision above disappears with it. `/stock` is untouched, which also settles
   >   the Review note: the Home Assistant consumer sees no new rows.
   > - **Q4 (whole subtree, nearest first) is wrong and is replaced.** Depth-ordered
   >   subtree substitution is exactly what produces heavy cream as a substitute for
   >   whole milk. Substitution becomes an explicit list with a direction and a
   >   preference order, so "nearest first" is something the owner records rather than
   >   something the tree infers.
   > - **Q3's depth cap becomes a `product_groups` concern**, not a `products` one.
   > - **Q2's mixed middle node stops mattering.** Nothing stocks an intermediate
   >   node once the intermediates are groups.
   >
   > **Closed: block and shredded are two products.** Different prices, different
   > barcodes, different nutrition facts.
   >
   > One qualification, because the obvious objection is that grocy already handles
   > two of those on a single product. It does: `product_barcodes` carries `barcode`,
   > `qu_id`, `amount`, `shopping_location_id` and `last_price` per row, so several
   > barcodes at different pack sizes and prices under one product is exactly what
   > that table is for. Nutrition is what settles it — `calories` is a single column
   > on `products`, one value per product, with nowhere to vary per barcode. A block
   > and a bag of shredded cannot both be described by one row, so they are not one
   > product, whatever the barcode table could absorb.
   >
   > This removes the last candidate for a roll-up in this catalogue, and with it the
   > last reason for any of 07's machinery. It does **not** make `parent_product_id`
   > dead: a genuine case is one where the differing attributes are only pack size and
   > price — the same milk in a 1 L and a 2 L carton, same unit, same nutrition. Those
   > exist and the column means the right thing for them. The cheese example simply
   > was never one of them, which is why it kept resisting the model.

7. **What replaces this plan?** Q6 removes most of it. What remains is worth stating so
   the roadmap is honest about the size:

   - **Nested `product_groups`** — one nullable self-referencing column, a cycle guard,
     and the group pickers and reports learning to walk it. Belongs to
     [03](03-category-min-stock.md).
   - **An explicit substitution table** — `product_id`, `substitute_product_id`, a
     preference rank, and whatever the recipe-fulfilment and stock views need to consume
     it. Directed: a symmetric pair is two rows, recorded deliberately. This is new work
     and does not exist upstream in any form.
   - **An audit of the existing one-level `parent_product_id` behaviour**, which was the
     first item of this plan and is the only part that survives unchanged.

   The recursive-CTE machinery, the stock aggregation, the substitution semantics and
   `cascade_change_qu_id_stock` all fall away with the taxonomy moving out of `products`.

   > **Response:** Accepted, now that Q6 is fully closed. Sizes: nested
   > `product_groups` is small and belongs to 03; the substitution table is medium and
   > needs its own plan; the one-level audit is small and is all that stays here.
   >
   > The audit's purpose changes slightly. It was "find every place that assumes one
   > level, before making the tree deeper". Since the tree is not becoming deeper, it
   > is now "confirm what `parent_product_id` is actually used for in this catalogue,
   > and leave the one-level behaviour alone" — including checking whether it is used
   > at all. Same reading, much smaller consequence: nothing is being built on top of
   > what it finds.

## Review notes

- Middle-node semantics leak into the API: `stock_current` gaining aggregated rows for
  intermediate parents changes what `/stock` returns for consumers like the Home
  Assistant integration — technically additive (new rows, same shape), but it should
  be a deliberate decision in this plan's API section, not a side effect.

## Effort

**Was large — the largest item on the roadmap. Q6 removes most of it.**

The size came from putting a taxonomy into `parent_product_id`: recursive stock
aggregation, subtree substitution, `cascade_change_qu_id_stock` and the quantity-unit
question all followed from that one decision. With the taxonomy moving to nested
`product_groups`, none of them arises.

What is left splits in two, and neither half is this plan:

- **small** — nested `product_groups`, in [03](03-category-min-stock.md).
- **medium** — an explicit directed substitution table, which is new work with no
  upstream equivalent, and which needs its own plan.
- **small** — the one-level `parent_product_id` audit at the top of this document, the
  only part of 07 that survives as written.

08 still goes first: it establishes the recursive-tree pattern that nested
`product_groups` then reuses, and containment is the one relation where a parent column
means what it appears to mean.
