# Architecture decision records

A decision that constrains later work is recorded here, once, and referenced from
wherever it applies. [Plans](../plans/README.md) describe *work*; ADRs describe
*decisions*. A plan may cite an ADR, and a plan that makes a new architectural decision
on its way to shipping should leave one behind rather than burying it in a Response block
where only a reader of that plan will find it.

The distinction that matters in practice: **a plan is done when the code lands, and an ADR
is never done — it is Accepted until something supersedes it.** Plan 13 landed and its
Executed section is the record of what shipped; the choice it made about transaction
nesting outlives it and will be read by people who never open plan 13.

## Format

```
# ADR-NNNN: Imperative title naming the decision

- **Status:** Proposed | Accepted (date) | Superseded by ADR-NNNN | Rejected (date)
- **Decider:** who accepts or rejects it
- **Recorded:** when this file was written, and whether that is when the decision was made
- **Referenced by:** the plans and documents that depend on it

## Context      what was true that forced a choice
## Decision     what was chosen, in the imperative
## Consequences what this costs, including what it forecloses
```

## Lifecycle

**Decider.** This fork serves one household and the maintainer decides. The field exists
anyway, because "who decides" is the question a corpus of proposals is worst at answering
implicitly, and because a Proposed record with no named decider is one nobody is obliged
to resolve. Backfilled records name the maintainer retrospectively.

**Accepting or rejecting is its own pull request**, carrying the lifecycle bookkeeping and
no substantive change. That means exactly four things: the record's status line, its index
row here, the forward pointer on any record it supersedes, and its own reference to what
it supersedes. Nothing else — no argument revised on the way through, no consequence
softened, no prerequisite quietly dropped. A record is not accepted by a plan that assumes
it, by a PR that implements it, or by not being argued with. This keeps the moment of
decision reviewable on its own terms and keeps a rejected proposal from being adopted by
momentum.

The bookkeeping is part of the acceptance rather than a follow-up because a superseded
record with no forward pointer is the failure this corpus is meant to prevent: the reader
who finds [0001](0001-postgresql-alongside-sqlite.md) first and never learns it was
replaced.

**A Proposed record may name acceptance prerequisites** — measurements to take, spikes to
run, questions to answer — under a heading of that name. Where it does, those are gates
rather than suggestions, and the accepting pull request says how each was met. A proposal
whose prerequisites are unmet is not ready to accept even if everyone agrees with it.

**Superseding.** A record is superseded, never edited into a different decision. The new
record names the old, the old points forward, and both keep their files — all of which the
accepting pull request does, per the rule above.

**Options considered** and **Research** sections appear where the decision was contested
enough to be worth showing the work. Backfilled records mostly skip them — the reasoning
already exists in prose elsewhere, and these point at it rather than restating it.

Numbers are permanent. A rejected or superseded ADR keeps its number and its file; it is
marked, not deleted, because the reason something was *not* done is the thing future
readers most often need and least often have.

## Index

| # | Decision | Status | Source |
|---|---|---|---|
| [0001](0001-postgresql-alongside-sqlite.md) | PostgreSQL is supported alongside SQLite | **Superseded by 0008** 2026-08-31 | [db/pgsql](../../db/pgsql/README.md) |
| [0002](0002-squashed-baseline.md) | PostgreSQL loads a squashed baseline, not a replayed migration history | **Accepted** 2026 | [db/pgsql](../../db/pgsql/README.md) |
| [0003](0003-seed-data-in-php.md) | Seed data lives in PHP, not in the baseline DDL | **Accepted** 2026 | [db/pgsql](../../db/pgsql/README.md) |
| [0004](0004-engine-specific-migrations.md) | Engine-specific migrations are marked, and migration numbers are never compared across engines | **Accepted** 2026 | [db/pgsql](../../db/pgsql/README.md) |
| [0005](0005-wire-contract-is-the-invariant.md) | The JSON on the wire is the invariant — with two accepted exceptions | **Accepted** 2026 | [db/pgsql](../../db/pgsql/README.md) |
| [0006](0006-authenticated-issues-in-scope.md) | Issues requiring an authenticated account are in scope | **Accepted** 2026-08-29 | [security sweep](../security-sweep.md), [SECURITY.md](../../.github/SECURITY.md) |
| [0007](0007-auth-state-outlives-the-process.md) | Authentication rate-limit state lives outside the process | **Accepted** 2026-08-29 | [security sweep](../security-sweep.md) S12 |
| [0008](0008-postgresql-only-runtime-engine.md) | PostgreSQL becomes the only runtime engine; SQLite becomes an import format | **Proposed** | — |
| [0009](0009-database-as-the-logic-layer.md) | The database is the logic layer | **Proposed**, depends on 0008 | — |
| [0010](0010-workload-standard.md) | Fork-owned workloads are stateless, idempotent, unprivileged and declared | **Proposed** | [constitution](../constitution.md) |
| [0011](0011-label-namespace.md) | Labels carry stable opaque identifiers; grocycode becomes an input symbology | **Proposed** | [06](../plans/06-location-barcodes.md) |
| [0012](0012-observations-are-proposals.md) | Observations write proposals, never bookings | **Proposed** | — |
| [0013](0013-nix-built-container-images.md) | Production images are built by Nix from a flake in this repository | **Proposed** | [20](../plans/20-container-infrastructure.md), [nix/](../../nix/README.md), [deploy/](../../deploy/README.md) |

**0008 was accepted 2026-08-31 and supersedes
[0001](0001-postgresql-alongside-sqlite.md).** 0009 remains a proposal under active
consideration — its dependency on 0008 is now satisfied, and it is still not in the
roadmap's wave order. They were written together as one concept and split into two
records precisely because the first is defensible without the second.

## Known unfiled decisions

Decisions that are live in the codebase and ADR-shaped, but not yet written up. Listed so
they are tracked somewhere rather than nowhere — the lesson the
[rigor review](../architecture-rigor-review.md) taught this corpus once already.

- **Request-scoped `define()` constants stay.** `VICTUAL_USER_ID`, `VICTUAL_LOCALE` and the
  rest are safe under php-fpm and rule out worker-mode runtimes.
  [Plan 10](../plans/10-cold-start-statelessness.md) calls this out explicitly as a
  decision worth recording rather than changing, which is exactly what an ADR is for.
- **`InTransaction` asks `PDO::inTransaction()` rather than counting depth.** Chosen during
  [13](../plans/13-write-path-transactions.md)'s implementation, against 13-Q2's recorded
  response, with the reasoning in a docblock and the deviation noted in 13's Executed
  section. It has already been re-derived once, in plan 10's sequencing.
- **Files are stored in the database rather than on a volume.** Currently a plan
  ([01](../plans/01-file-storage.md)) rather than a decision; it becomes an ADR when it is
  accepted rather than when it ships.
