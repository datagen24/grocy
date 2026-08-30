# ADR-0005: The JSON on the wire is the invariant — with two accepted exceptions

- **Status:** **Accepted.** The overriding rule of the porting work.
- **Recorded:** 2026-08-30, retrospectively.
- **Referenced by:** [14](../plans/14-contract-and-regression-scaffolding.md),
  [17](../plans/17-ecosystem-clients.md), and every porting hazard in
  [db/pgsql/README.md](../../db/pgsql/README.md).

## Context

Seventeen documented hazards exist because the same query returns subtly different values
on the two engines: booleans leaking into output, integer division, `CAST` truncating on
one side and rounding on the other, window functions returning `bigint`, aggregates
returning `NUMERIC`. Each needed a rule for which side was correct.

## Decision

**The engine may change; the JSON on the wire may not.** Where the two engines disagree,
the conforming answer is the one the OpenAPI spec documents, and the porting work moves
whichever engine is wrong.

## Accepted exceptions

Two differences are known, deliberate, judged harmless, and **must not be "fixed"**:

- **Float accumulation order.** `products_average_price.price` can be
  `4.124499999999999` on SQLite and `4.1245` on PostgreSQL. Summing floats in a different
  order changes the last bit; the discrepancy is ~1e-15 and is not stable on SQLite either.
  Rounding would change the documented value. It reaches
  `uihelper_product_details.average_price`, `uihelper_stock_current_overview.average_price`
  and `recipes_resolved.costs` / `costs_per_serving`; only `products_average_price` is an
  `ExposedEntity`.
- **`chores.start_date` where the stored value has no time.** SQLite returns
  `"2025-01-01"`; PostgreSQL's `TIMESTAMP` renders `"2025-01-01 00:00:00"`. `chores` *is*
  an `ExposedEntity`. `DATE` is not an option — the chore form is a datetimepicker and the
  `default_start_date_when_empty` triggers write a real time — and
  `"2025-01-01 00:00:00"` is the more conformant rendering of the documented
  `format: date-time` anyway. Only a date-only string differs, and
  `trigdifftest.php` confirmed this is the only such column across all 37 tables.

A third was recorded as accepted and then withdrawn, which is worth keeping visible: the
`qu_factor_*` `TEXT`-versus-number difference was excused on the grounds that no affected
view was an `ExposedEntity`. **That reasoning was too generous** — PostgreSQL was already
the conforming side, and a sibling view had always cast correctly, so one view conformed
and two did not for no reason anyone had chosen. `0256.sqlite.sql` fixed it. The lesson is
that "not on a public endpoint" is not by itself grounds for accepting a difference.

## Consequences

- An accepted difference has to name what it touches and whether any of it is exposed.
- The differential suite is what makes this rule testable rather than aspirational, which
  is the dependency [ADR-0008](0008-postgresql-only-runtime-engine.md) has to answer for.
