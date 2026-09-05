# ADR-0007: Authentication rate-limit state lives outside the process

- **Status:** **Accepted** 2026-08-29, recorded in the sweep's S12 and in
  [11](../plans/11-api-error-handling.md)'s sequencing. **Built 2026-09-04**, in a table
  (`login_attempts`, migration 0262, a per-engine pair) — `services/LoginThrottleService.php`
  and `middleware/Auth/PasswordLogin.php`. This record is what decided the shape, and it is
  cited in both.
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
  S12 stayed open until the throttle existed, and this record is what stopped it being
  implemented the fast, wrong way. **It worked**: the throttle landed in wave 2 as a table,
  and the schema change this section names as the cost is migration 0262. Redis was
  available and was not chosen, for the reason the Consequences list already implies — a
  table costs one write per *failed* attempt and no second service, and a household
  instance has few of either.
- **This record's argument caught a second thing, in a shape it did not anticipate.** The
  throttle shipped with a per-client-address counter beside the per-username one, and the
  maintainer's review of the implementing pull request pointed out that behind a reverse
  proxy `REMOTE_ADDR` is the proxy for every request — so that counter was not per-address
  at all. It was a whole-instance lockout wearing a per-address name: ten mistakes by
  anyone would hold everyone for the window. That is this record's own objection with the
  axis changed. The counter that resets itself on a schedule an attacker can predict and
  the counter that counts something other than what its name says are the same mistake,
  which is *believing a limit binds what it appears to bind*. The per-address counter was
  removed rather than tuned, and rate limiting a misbehaving address is the proxy's job,
  because the proxy is the only party that knows which address it is.
- **The same reasoning generalised on the way through, and the fix is recorded here because
  the ADR is where a reader looks.** S12's second half — force a password change while the
  seeded `admin`/`admin` hash is in use — has the same shape and a different answer. The
  question "is this account still on the default password?" can only be asked where a
  plaintext password exists, which is the login path, so the *answer* is stored (the
  `users.must_change_password` column, migration 0265) rather than recomputed. It was a
  *user setting* first, and review of PR #68 named the flaw in one line: a setting is a bag
  its owner can empty, so `DELETE /api/user/settings/must_change_password` lifted the
  restriction without changing any password. Where state outlives the process is only half
  the question; the other half is who can reach it. Recomputing it would mean an
  Argon2id verification on every request, which is expensive by design. That is the mirror
  of this record rather than an exception to it: state that must outlive the process goes in
  the database either way, and what changes is whether the process could cheaply derive it
  again.
