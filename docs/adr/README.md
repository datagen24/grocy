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
| [0008](0008-postgresql-only-runtime-engine.md) | PostgreSQL becomes the only runtime engine; SQLite becomes an import format | **Accepted** 2026-08-31, **supersedes [0001](0001-postgresql-alongside-sqlite.md)** | — |
| [0009](0009-database-as-the-logic-layer.md) | The database is the logic layer | **Proposed**, depends on 0008 | — |
| [0010](0010-workload-standard.md) | Fork-owned workloads are stateless, idempotent, unprivileged and declared | **Proposed** | [constitution](../constitution.md) |
| [0011](0011-label-namespace.md) | Labels carry stable opaque identifiers; grocycode becomes an input symbology | **Proposed** | [06](../plans/06-location-barcodes.md) |
| [0012](0012-observations-are-proposals.md) | Observations write proposals, never bookings | **Proposed** | — |
| [0013](0013-nix-built-container-images.md) | Production images are built by Nix from a flake in this repository | **Accepted** 2026-09-04, **supersedes the `Dockerfile`'s `production` target** | [20](../plans/20-container-infrastructure.md), [nix/](../../nix/README.md), [deploy/](../../deploy/README.md) |

**0008 was accepted 2026-08-31 and supersedes
[0001](0001-postgresql-alongside-sqlite.md).** 0009 remains a proposal under active
consideration — its dependency on 0008 is now satisfied, and it is still not in the
roadmap's wave order. They were written together as one concept and split into two
records precisely because the first is defensible without the second.

*That acceptance took eight days to reach this table: the record's own status line said
Accepted from 2026-08-31 and 0001's row named 0008 as its successor, while 0008's row
here still read Proposed until 2026-09-04. The lifecycle rule above lists exactly four
things the accepting pull request changes and the index row is the second of them, so this
was the one piece of bookkeeping that rule already names — missed anyway, and only caught
by reading the index against the records.*

## Where the proposals stand

Reviewed 2026-09-04. This section is a **status of the gates, not a decision** — accepting
or rejecting any of these is its own pull request, per the lifecycle rule above, and
nothing here counts as one.

- **[0009](0009-database-as-the-logic-layer.md) — the database is the logic layer.** Its
  first gate (0008 accepted) is met. Its second is not, and is now *cheap* rather than
  blocked: it says deploy [18](../plans/18-mqtt-state-publication.md) and measure whether
  the pod actually sleeps — 18 landed 2026-09-02, so the measurement is available for the
  taking and has not been taken. The record is explicit that if the pod does not sleep,
  this collapses to 0008's tax argument and should be **rejected rather than accepted
  smaller**. The third gate, the seven-part Anonymizer spike behind the redaction
  mechanism, is untouched. **Not ready; the next move is a measurement, not an argument.**
- **[0010](0010-workload-standard.md) — the workload standard.** No acceptance
  prerequisites, and two of its three open questions have been answered by events rather
  than by argument: the deploy tree does live in this repository ([20](../plans/20-container-infrastructure.md)
  shipped `deploy/podman/`, question 1's lean), and the main image's root-user retrofit
  landed with [10](../plans/10-cold-start-statelessness.md) (question 3). Question 2,
  enforcement, is the one that matters and the tree now argues its lean for it — the
  cheap greps work, as `.devtools/check-cited-jobs.php` and
  `.devtools/pgsql/check-runtime-sql.php` both demonstrate. **The most acceptable of the
  five, and the one whose acceptance would cost the least.**
- **[0011](0011-label-namespace.md) — the label namespace.** Two gates, both answerable at
  a desk: choose the uid format (question 1 carries a specific lean) and reconcile plan
  [06](../plans/06-location-barcodes.md), which is either absorbed or narrowed. Neither
  needs code. **Ready to decide whenever 06 comes up the queue; nothing is waiting on it.**
- **[0012](0012-observations-are-proposals.md) — observations are proposals.** Two gates,
  both design statements: the absent-versus-redacted-versus-unknown contract for proposal
  payloads, and the confirm permission (question 2 carries the lean). The first is
  entangled with [19](../plans/19-rbac.md)'s wire-contract questions, and 19 is itself
  blocked on its own Q8. **Decidable, but reads better after 19 unblocks.**
- **[0013](0013-nix-built-container-images.md) — Nix-built production images.**
  **Accepted 2026-09-04**, with all five gates met as written rather than amended; it is
  listed here only so a reader of this section is not left thinking it is still open.
  [Plan 20](../plans/20-container-infrastructure.md) piece 1 met two of them, and fixing
  [#49](https://github.com/datagen24/victual/issues/49) met the other three. An amendment
  that would have made it acceptable a day earlier was available and declined; the record
  says why that mattered, which is that fixing the blocker found two more defects.

  **It was never rejected.** A commit on 2026-09-03 (`1c97766f`) marked it Rejected and
  deleted the flake; the next commit put both back. That was an agent hallucinating a
  decision the maintainer never made — not a rejection later reconsidered. 0013 carries the
  correction, because the git history alone reads as a maintainer who changed their mind,
  and this corpus is the wrong place for false provenance about who decided what.

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
