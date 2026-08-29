## What and why

<!-- What changed and what problem it solves. Link the plan or review item it comes from
     (docs/plans/*.md, docs/architecture-review.md) if there is one. -->

## Compatibility

<!-- Answer each line, or write "none" if nothing here applies. -->

- **Schema change?** State the migration shape — a portable `NNNN.sql`, a per engine
  `NNNN.sqlite.sql` / `NNNN.pgsql.sql` pair, or a documented engine-exclusive migration
  carrying `@engine-exclusive` (or `@overrides-generic`, if it shadows a portable file of
  the same number). See `db/pgsql/README.md`.
- **Changes an existing API response?** Say which endpoint and which field or status code.
  New entities and fields are additive and just need a note; anything that changes an
  existing response shape is called out explicitly rather than slipped in.
- **Needs a manual step on upgrade?** Config, auth, data migration, anything that does not
  survive a plain pull and restart.

## Verification

<!-- What was actually run, on a booted instance against a real database. "It loads
     cleanly" and "lint passes" are not verification. -->

- [ ] Exercised the changed paths on a running instance
- [ ] Both engines, where the change touches SQL — `.devtools/pgsql/difftest.php` for
      views, `trigdifftest.php` for trigger behaviour
- [ ] Result sets compared before and after, where the change rewrites a query

## Notes for review

<!-- Decisions that could reasonably have gone the other way, and anything deliberately
     left undone. Optional. -->
