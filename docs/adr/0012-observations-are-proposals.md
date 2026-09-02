# ADR-0012: Observations write proposals, never bookings

- **Status: Proposed.** Written to be argued with.
- **Decider:** datagen24 (maintainer). Acceptance is its own pull request — see the
  lifecycle rule in [the index](README.md).
- **Recorded:** 2026-08-31.
- **Relationship:** decides what this fork's API offers to observation-producing
  clients. The clients themselves — the examples below include a vision pipeline and
  weight sensors — are illustrative operator-side systems and **do not live in this
  repository**; what lives here is the entity, the endpoints, and the invariant. The
  proposals schema is one of the flat contracts
  [ADR-0010](0010-workload-standard.md) requires consumers to share.
- **Would affect:** [01](../plans/01-file-storage.md),
  [18](../plans/18-mqtt-state-publication.md), [19](../plans/19-rbac.md),
  [14](../plans/14-contract-and-regression-scaffolding.md),
  [02](../plans/02-mcp-endpoint.md).

## Context

The stock ledger is exact, trusted history: every booking in `stock_log` is a fact a
household member made happen and can undo, and
[13](../plans/13-write-path-transactions.md) centralised the write paths that keep it
that way. The API accordingly offers exactly one kind of write: a booking, asserted as
true.

Automated observers do not produce that kind of statement. A camera pipeline diffing
what it can see produces "entry X appears to be gone, confidence 0.7, and here is the
frame"; a shelf-weight sensor produces "this location lost 340g." Wiring such a client
to the booking API forces a choice between two bad options: the client asserts
certainty it does not have, silently polluting the ledger with guesses that read exactly
like human actions — or the client stays read-only and the observations are wasted. The
failure mode of the first option is insidious precisely because the ledger's value is
that nobody doubts it.

There is also a threat-model angle. Under
[ADR-0006](0006-authenticated-issues-in-scope.md), the sensor boxes an operator wires up
are the least defensible credentialed things on the network; a compromised observer
holding a booking-capable key can rewrite inventory history. The narrowest useful write
grant for an observer is "may suggest."

## Decision (proposed)

**Probabilistic observations never write the ledger. They write proposals.**

1. **A `proposals` entity**: source, kind (a proposed booking — consume, purchase,
   transfer, inventory-correction), the proposed payload, confidence, an optional
   evidence reference, a **unique source-event id**, status
   (pending / confirmed / rejected), and who resolved it.
2. **Creating a proposal is its own narrow grant.** An observer's API key resolves to a
   user whose permissions allow creating proposals and nothing else — the shape
   [19](../plans/19-rbac.md) exists to express. A compromised observer can spam the
   review queue; it cannot touch history.
3. **Confirmation executes the booking** through the existing service write paths — the
   confirmed proposal is the audit trail from observation to booking, and the booking
   itself is indistinguishable from any other because it *is* any other.
   Rejection records why, which is training data for whatever proposed it.
4. **The unique source-event id makes creation idempotent** — a redelivered sensor event
   cannot double-propose — which is [ADR-0010](0010-workload-standard.md)'s idempotency
   rule applied at the API boundary rather than inside a consumer.
5. **Deterministic sources are out of scope.** A scale that weighs an open jar against a
   known tare is not guessing; neither is a human with a scanner. Those clients use the
   booking API as they do today. The line is confidence: a client that would have to
   invent a confidence value belongs on the booking API; one that genuinely has one
   belongs here.

## Where the boundary of this repository is

This record deliberately ships the *narrow* half. In scope: the entity, its endpoints
under the wire-contract discipline, the permission, and surfacing pending proposals —
in the UI, and as a count in [18](../plans/18-mqtt-state-publication.md)'s published
snapshot so an always-on consumer can nag. Out of scope, permanently: the observers
themselves, their sensors, their inference, their scheduling. The fork's promise to that
ecosystem is a stable, idempotent, least-privilege way to say "I think something
happened" — nothing more.

## Consequences

**A review queue is a chore.** A household that ignores it accumulates stale pending
proposals, and the feature degrades to noise. This is the real risk — not technical —
and it is why proposals surface through channels the household already looks at rather
than a page nobody visits.

**New wire surface.** The entity and endpoints land under
[14](../plans/14-contract-and-regression-scaffolding.md)'s snapshot discipline, and are
additive.

**Evidence wants [01](../plans/01-file-storage.md).** An evidence reference without file
storage is a URL to somewhere with its own retention; with 01 it is a stored artifact
that lives exactly as long as the proposal. V1 can ship with evidence optional.

**Two more dual-engine tables** while
[ADR-0008](0008-postgresql-only-runtime-engine.md) is Proposed — same small-but-real tax
noted in [ADR-0011](0011-label-namespace.md).

**Auto-confirmation is explicitly deferred.** A threshold above which a proposal
self-confirms is a policy decision with the exact failure mode this record exists to
prevent, and it should be earned by months of precision data, not designed in advance.
V1 has no auto-confirm path.

## Acceptance prerequisites

- **The absent-versus-redacted-versus-unknown contract for proposal payloads is stated**
  — a proposed booking is partial by nature (a mass delta proposes an amount but maybe
  not a price), and [19](../plans/19-rbac.md)'s wire-contract questions about absent
  keys apply here from day one.
- **The confirm permission is decided** — open question 2 carries the lean — because it
  is the difference between a review queue and a second admin surface.

## Open questions

1. **Expiry.** Do pending proposals age out? *Lean: no automatic expiry — a stale
   pending proposal is information about the household's engagement, and silently
   deleting it hides exactly that. Staleness is displayed, not enforced.*
2. **Who may confirm?** *Lean: any user holding the permission for the underlying
   booking type — confirming a consume requires what consuming requires. No separate
   "reviewer" role; [19](../plans/19-rbac.md) already prices this correctly.*
3. **Confirm-with-edit.** May the confirmer adjust the payload (the amount, the
   product) before it books? *Lean: yes — the observation was approximately right and
   the human is the precision step; the proposal keeps both the proposed and the booked
   payload so the delta is visible to whoever tunes the observer.*
4. **Is inventory-correction a proposal kind in v1**, given corrections are the
   heaviest-handed booking? *Lean: yes but last — it is the kind a reconciling observer
   most wants and the kind a wrong observer does the most damage with; shipping
   consume/purchase/transfer first lets the trust be earned on smaller stakes.*

## Research

- Ledger and write-path facts: `stock_log`,
  [13](../plans/13-write-path-transactions.md)'s Executed section, working copy of
  2026-08-31.
- The narrow-grant argument is [ADR-0006](0006-authenticated-issues-in-scope.md)'s
  threat model applied to operator-side observers; the idempotency rule is
  [ADR-0010](0010-workload-standard.md)'s.
