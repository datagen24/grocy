# ADR-0010: Fork-owned workloads are stateless, idempotent, unprivileged and declared

- **Status: Proposed.** Written to be argued with.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-08-31.
- **Relationship:** binds the workload family the roadmap is about to create — the MCP
  sidecar ([02](../plans/02-mcp-endpoint.md) / the
  [interface spec](../mcp-interface-spec.md)), the MQTT publisher
  ([18](../plans/18-mqtt-state-publication.md)), the print drainer
  ([ADR-0011](0011-label-namespace.md)) — and, retroactively, the main application image.
  The [constitution](../constitution.md)'s workload-standard section is the statement of
  intent this record makes binding.
- **Would affect:** [10](../plans/10-cold-start-statelessness.md),
  [02](../plans/02-mcp-endpoint.md), [18](../plans/18-mqtt-state-publication.md).

## Context

The deployment is one container today and will not stay that way: the MCP interface spec
chose a sidecar, plan 18 needs a publisher, and ADR-0011 replaces a webhook consumer with
a queue drainer. The maintainer's operating philosophy — one tool, one job — welcomes
that multiplication, on the condition that failed elsewhere: sprawl without management.
The failure modes have names, and the fork has already ruled on two of them piecemeal.
State between requests is a cold-start problem
([ADR-0007](0007-auth-state-outlives-the-process.md); plan 10 owns the rule). Side
effects on write paths fire after commit and must tolerate redelivery
([13](../plans/13-write-path-transactions.md), [18](../plans/18-mqtt-state-publication.md)'s
retained-snapshot design). What has never been ruled on is privilege and declaration —
and the tree shows it: `Dockerfile` builds `FROM php:8.5-cli-bookworm` with **no `USER`
directive**, so the container holding the database owner's credentials runs as root, and
the only deployment artifacts in the repository are that Dockerfile and a compose file.

## Decision (proposed)

Every workload this repository ships is, as a condition of shipping:

1. **Stateless.** Its durable state lives in PostgreSQL, Redis, or the broker — the
   stores [ADR-0007](0007-auth-state-outlives-the-process.md) permits. Killing the
   process at any moment loses nothing. Process memory and APCu hold only pure caches
   whose loss costs a recomputation.
2. **Idempotent.** Every consumer is an at-least-once consumer; every side effect is
   idempotent or deduplicated by an explicit key. Queues are drained
   (`SELECT … FOR UPDATE SKIP LOCKED` or equivalent), never fired-and-forgotten.
3. **Unprivileged.** Non-root, read-only root filesystem, no capabilities it did not
   ask for — and its own identity: its own credential, its own database role, least
   privilege for the one job it does.
4. **Declared.** It exists in the repository's deploy tree with health probes and
   resource limits, or it does not exist.

And one rule about the family rather than its members: **consumers may multiply;
contracts may not.** One outbox schema discriminated by event type, one proposals schema
([ADR-0012](0012-observations-are-proposals.md)) — new consumers attach to existing
contracts rather than minting their own.

The standard binds what this repository ships. Workloads an operator runs *against* the
fork — integration pipelines, bridges, anything consuming the API or the published state
from outside — are their operator's to govern; this record is guidance there, not law.

## Consequences

**The main application image is non-compliant on the day this is accepted**, on
properties 3 and 4 at least. That is deliberate: accepting the standard does not require
the retrofit to be done, it requires the gap to be a tracked work item rather than an
unstated fact. The retrofit is not free — a non-root PHP image has to reconcile every
path the application writes (the data directory, the Blade view cache, plan 10's
HTMLPurifier serializer path, which that plan already calls its most annoying
writable-path problem) with a read-only root filesystem.

**New workloads are born compliant or not born.** For a Go or Node binary on a distroless
base this costs nearly nothing, which is the point of deciding it before the family
exists rather than after.

**Idempotency becomes reviewable.** "What is this consumer's dedup key" and "what happens
when this delivery arrives twice" become questions a PR review is entitled to ask about
any consumer, the way "which engines does this migration run on" is askable today under
[ADR-0004](0004-engine-specific-migrations.md).

**A deploy tree has to exist**, and it becomes part of the reviewed surface. Manifests
are code.

## Open questions

1. **Where does the deploy tree live?** In-repository manifests for fork-shipped
   workloads, or the operator's infrastructure repository? *Lean: the fork ships its
   workloads' manifests (that is what "declared" means here) and stays silent on
   cluster-specific concerns — ingress, storage classes, secrets management — which
   belong to the operator. The boundary is "what the workload needs" versus "where it
   runs."*
2. **Enforcement.** A standard nobody checks is the plans-README gate problem all over
   again. *Lean: start with the cheap greps — CI fails a Dockerfile with no `USER`, a
   manifest with no probes or limits — and adopt a real linter only when the cheap
   version misses something that mattered.*
3. **When does the main image's retrofit land?** *Lean: the standard binds at the image's
   next substantive change rather than demanding a dedicated retrofit release — but the
   root-user finding is the highest-leverage item on the list and should not wait long
   behind that formula.*

## Research

- Tree facts (`Dockerfile`, absence of a deploy tree) measured on the working copy of
  2026-08-31.
- The at-least-once and after-commit disciplines this record generalizes are established
  in [13](../plans/13-write-path-transactions.md) and
  [18](../plans/18-mqtt-state-publication.md); the state rule in
  [ADR-0007](0007-auth-state-outlives-the-process.md) and
  [10](../plans/10-cold-start-statelessness.md).
