# ADR-0007: Authentication rate-limit state lives outside the process

- **Status:** **Accepted** 2026-08-29, recorded in the sweep's S12 and in
  [11](../plans/11-api-error-handling.md)'s sequencing. The throttle itself is not built
  yet; the constraint on how it may be built is decided.
- **Decider:** datagen24 (maintainer), retrospectively — see the lifecycle rule in [the index](README.md).
- **Recorded:** 2026-08-30, retrospectively.
- **Referenced by:** [security sweep](../security-sweep.md) S12,
  [11](../plans/11-api-error-handling.md), [10](../plans/10-cold-start-statelessness.md).

## Context

`DefaultAuthMiddleware::ProcessLogin` runs `password_verify` on every attempt with no
counter and no delay, and `migrations/0027.php` seeds `admin`/`admin`. A throttle is
needed. The obvious implementation — a counter in process memory, or APCu — is wrong here
for a reason that has nothing to do with security craft and everything to do with the
deployment target.

**On a scale-to-zero pod, in-process state is state only until the next idle window.** An
attacker resets the counter by waiting the pod out, and per
[17](../plans/17-ecosystem-clients.md)'s Q2 those windows are long and ordinary. A
throttle that resets itself on a schedule the attacker can predict is not a throttle.

## Decision

Login throttle state goes in Redis, which is always-on in the cluster, or in a table.
**Never in process memory or APCu.**

The general form, which is the part worth citing: **anything that keeps state between
requests is a cold-start problem, not just a correctness one.** Plan 10 owns that rule;
this ADR is its first concrete instance.

## Consequences

- The throttle acquires a dependency — Redis or a schema change — which is why it did not
  ship with the wave 0.5 hotfix alongside the other High findings.
- APCu remains available for things that are genuinely caches, where a cold start costs a
  recomputation rather than a security property. Plan 10 Q6 names it as the answer for the
  per-request migration-number check *if that ever becomes measurable*, and that is
  consistent with this record: a memoized `SELECT MAX(migration)` losing its memo on a
  restart costs one query, while a lost throttle counter costs the throttle.
- It is a constraint on [11](../plans/11-api-error-handling.md) rather than on the sweep:
  S12 stays open until the throttle exists, and this record is what stops it being
  implemented the fast, wrong way.
