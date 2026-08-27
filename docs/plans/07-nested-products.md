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
2. **Can a product with its own children also be a child?** Required for depth > 2. Any
   reason to forbid mixed nodes?

   > **Response:** None — they must be allowed (depth > 2 requires them). The
   > fixtures should include a middle node that itself holds stock, because that is
   > where aggregation is most likely to double-count.
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
5. **Is there a real use case beyond two levels** for you specifically? If the answer is
   "brand > variant > size", that is three, and worth designing for concretely rather
   than in the abstract.

   > **Response:** Write the concrete tree down before starting. This is the largest
   > fixed cost on the roadmap and its value is proportional to how deep the real
   > data goes — decide on data, not symmetry with 08.
   >
   > Unresolved, and deliberately so: this is the one open question on the roadmap
   > that no amount of code reading answers, because the input is the maintainer's
   > own catalogue. **07 does not start until a real example tree is written into
   > this plan** — actual products, actual depth. Until then the plan cannot say
   > whether it is designing for three levels or for arbitrary recursion, and that
   > difference is most of its cost. It sits behind 08 anyway, so there is time.

## Review notes

- Middle-node semantics leak into the API: `stock_current` gaining aggregated rows for
  intermediate parents changes what `/stock` returns for consumers like the Home
  Assistant integration — technically additive (new rows, same shape), but it should
  be a deliberate decision in this plan's API section, not a side effect.

## Effort

Large — the largest item on the roadmap. The recursive view is an afternoon; the audit,
the decisions above and the regression fixtures are the rest. Worth doing after 08 has
established the pattern.
