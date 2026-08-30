# ADR-0003: Seed data lives in PHP, not in the baseline DDL

- **Status:** **Accepted**, with [ADR-0002](0002-squashed-baseline.md).
- **Decider:** datagen24 (maintainer), retrospectively — see the lifecycle rule in [the index](README.md).
- **Recorded:** 2026-08-30, retrospectively.
- **Referenced by:** [db/pgsql/README.md](../../db/pgsql/README.md), `InitialDataSeeder`.

## Context

A third of the migrations the baseline stands in for insert rows as well as changing
schema: the admin account, the default quantity units and location, the default shopping
list, the thirty-row permission hierarchy, the internal meal plan section. Recording those
migrations as applied without running them produces a database that migrates successfully,
reports itself up to date, and has nobody who can log into it.

## Decision

Seed rows are created by `services/Database/InitialDataSeeder.php`, not by SQL literals in
`db/pgsql/baseline/`. `baseline/` is DDL only.

## Consequences

- **It could not have been SQL anyway.** Four of the six default names are translated
  through `LocalizationService` into `VICTUAL_DEFAULT_LOCALE`, and the admin password is
  hashed with a fresh Argon2id salt per installation. Neither is expressible as a literal.
- `bin/victual-db-import` calls `MigrateDatabase(false)` — schema, no seed — because it is
  about to fill the database from the source and every seeded row would be one it replaces.
  Migrating first and importing afterwards therefore needs `--force`.
- **The failure mode when the seeder does not run is quiet, not loud**, which is why this
  is worth a record. With no rows in `quantity_units`, the final join in
  `quantity_unit_conversions_resolved` matches nothing, so the view is empty, so
  `products_ins` copies nothing into its cache table, and the symptom surfaces as
  `recipes_pos` rejecting an ingredient for a missing conversion. The trigger was faithful;
  it had nothing to copy.
