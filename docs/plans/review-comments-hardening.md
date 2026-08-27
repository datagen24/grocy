# Hardening plan review — comments on plans 10–15

Companion to [review-comments.md](review-comments.md) (plans 01–08). Same format:
comments keyed to each plan's numbered **Open questions**, anything else is an
additional observation. Context: household scale, hard fork, k3s immutable
scale-to-zero target, and the verification-by-execution standard the defects pass
established (booted instances and result-set diffs, not lint).

## General

These plans are faithful to the review and in several places better than it — plan 10
caught the HTMLPurifier serializer path the review missed, plan 13 caught that the
review's "four entrypoints" undercounted by three, and plan 14's mutation-shaped
verification ("break it deliberately, confirm the suite notices") is the right bar for
test scaffolding. Two cross-plan notes:

- **Order of operations.** 10 says "first among the hardening plans"; 14 says "highest
  leverage, land before 11". Both are right if 14 is split as its own effort section
  proposes: **14 piece 1 (the runnable diff suite) first** — half a day if the seeds
  are recoverable — then 10, then 11, with 12 and 13 parallel to any of them, and 15
  last. Piece 1 of 14 is what makes 10's concurrency checks and 13's ledger snapshots
  commands instead of afternoons.
- **The `InTransaction` / `WithMigrationLock` split** (13-Q6 vs 10) is principled, not
  confusing: engine-specific behavior lives on the dialect, engine-neutral composition
  on `DatabaseService`. Keep both where their plans put them and cross-reference in
  the docblocks; do not merge them.

## 10. Cold start and statelessness

The plan's best insight is that compiled Blade output is a pure function of the source
tree — that's what makes the whole bake-at-build approach possible, and the
HTMLPurifier catch is what makes it honest.

1. **Cache-hash URL terms:** run exactly the empirical check proposed — compile under
   one base path, serve under another, diff the HTML. Expect "not load-bearing" (the
   `$U`-closure evidence is strong). If it somehow fails, the fallback is warming in
   the initContainer per deployment instead of the image — the plan survives, one
   layer moves.
2. **Read-only cache:** agree with the lean — read-only, with a warmer that fails the
   build unless every file under `views/` compiled. One addition the plan implies but
   should state: the warmer must also pre-generate the **HTMLPurifier definition
   cache** (one dummy purify call at build time does it — definitions depend only on
   the library version and config). Otherwise the first JSON write request hits a
   read-only serializer path and verification check 4 fails on the first POST, not on
   a missed template.
3. **SQLite lock file:** sibling file (`<db>.migrate.lock`), not `flock` on the
   database file itself — SQLite holds its own locks on that file and "safe but
   surprising" is the wrong property for locking code. The k3s target is PostgreSQL
   anyway; the SQLite path is a dev convenience and plain is better than clever.
4. **Web-triggered fallback:** agree — keep it behind `MIGRATE_ON_ROOT_REQUEST`,
   default off. Refinement: the Q6 fail-fast message should *name* the setting and
   `bin/grocy-migrate`, so the failure is its own documentation.
5. **Separate commands:** agree, keep `grocy-warm-cache` and `grocy-migrate` separate.
   They run at different lifecycle moments (image build vs deployment); a combined
   `grocy-init` blurs exactly the distinction this plan exists to draw.
6. **Boot against a stale schema:** fail fast, unconditionally — do not tie the
   *check* to the Q4 setting (only auto-migration is opt-in). One
   `SELECT MAX(migration)` per request is one indexed row on a tiny table; at
   household scale that is noise, and memoizing per request is enough. Two additions:
   the check should also fail on a database *ahead* of the code (a rollback scenario
   that would otherwise break unpredictably), and if the per-request cost ever
   bothers anyone, APCu is the answer then — not now.

## 11. API error handling, auth surface and error logging

The `HandleApiCall` shape is right, and the two new exception types are the minimum
machinery that makes 400-vs-500 expressible. The migration being per-method rather
than big-bang is what makes this landable.

1. **Ship outright, no flag.** All changed codes are on failure paths, they are
   corrections, and a compatibility flag means testing both behaviors forever. Do the
   ten-minute read of the Home Assistant integration's error handling first as the
   plan says — but as confirmation, not as a decision gate. Changelog entry, on 15's
   breaking list for visibility, done.
2. **`CalculateNextExecutionAssignments`:** the tension the plan found (write
   endpoint, but the overview page calls it as a refresh) is best resolved by
   removing it, not choosing a side: recompute server-side during the overview
   *render* (the page controller can do what the JS currently asks the API to do),
   and gate the API route on `PERMISSION_CHORES`. Then viewers still see fresh data,
   and only chore managers can force a recompute through the API. If that is more
   surgery than wanted, gate on `PERMISSION_CHORES` alone and accept that read-only
   users see assignments as of the last tracked execution — recomputation happens on
   every track anyway, so the staleness window is small.
3. **CORS default off.** Agree without reservation. `Allow-Origin: *` on an
   authenticated API was never a feature; nothing browser-cross-origin exists; the
   ingress can add headers in an emergency.
4. **Hash API keys — yes, and use SHA-256, not `password_hash`.** This deserves a
   line in the plan because the obvious tool is the wrong one: keys must be looked up
   *by value*, which salted bcrypt cannot do without a full-table scan, and these are
   50-character random strings (~250 bits) — brute force is not the threat model, a
   leaked `api_keys` table is. Unsalted SHA-256 gives O(1) lookup and is exactly
   right for high-entropy secrets. Keep a `key_hint` (last four characters) column so
   the manage screen can still identify keys after creation.
5. **Blocklist now.** `id` + `row_created_timestamp` covers the known problem in five
   minutes. The spec-derived allowlist depends on the entity schemas being complete,
   which the plan itself notes has never been tested — 14's snapshot-vs-schema leg is
   what will *make* them trustworthy. Revisit the allowlist after 14 has run for a
   while; do not build it on an unvalidated spec.
6. **Populate `ExposedEntityEditRequiresAdmin`** with `userfields` and `userentities`
   at minimum — definition-level entities that reshape the data model, which is a
   different act from editing master data. If on reflection nobody should be
   admin-gated, delete the enum and its three call sites the same day; the empty gate
   is the only wrong option, exactly as the plan says.
7. **stderr only.** Correct for k3s and correct for the household case too — `kubectl
   logs` / `docker logs` *is* the log file. A rotating file reintroduces the writable
   path 10 just removed; decline it.

## 12. Frontend shared core

"Fix the bugs first, then refactor" is the right spine, and defaulting the error
callback so the 148 sites are *deleted* rather than rewritten is the elegant move.

1. **`transaction_type`: accept only the documented spelling — skip the deprecation
   ceremony.** Push-back on the plan's lean. The current code requires *both*
   spellings simultaneously for the override to function, so no client sending only
   the undocumented one has ever worked, and a client sending both is a client that
   read this exact broken source. There is no one to deprecate for; a
   one-release-accept-both window is process without a beneficiary. Fix to the spec,
   one changelog line.
2. **No-op survivors:** the three named (db-changed poller, missing-localization
   logger, barcode lookup) are right; the grep will likely add the test pages
   (`barcodescannertesting`, `quantityunitpluraltesting`), background statistics
   refreshers, and the product-card price-history fetch where empty history is
   normal. The criterion worth writing into the factory's docs: *failure is an
   expected domain outcome or a background poll* → explicit `function() {}` with a
   comment; anything user-initiated toasts.
3. **Reload convention: form-posts-`Reload` wins.** The form knows whether data
   actually changed; a list reloading on every `CloseLastModal` refreshes on cancel
   for nothing. The majority of the tree already leans this way. Factory lists handle
   the `Reload` message; the `CloseLastModal`-triggered reloads get deleted.
4. **`datetimepicker2`:** the diff the question asks for has effectively been run —
   the frontend review normalized-diffed the pair and found naming-only differences,
   and the component documentation pass confirmed it ("independent second-instance
   twin"). Proceed with parameterization, merge the Blade component pair too, and
   verification check 5 (two pickers on one page) is the safety net.
5. **Factory scope:** agree with the lean — factory for the pure clones, mixins for
   the five partial clones — with one addition: leave `mealplan.js` and `recipes.js`
   entirely alone. They are the two most divergent files in the tree; forcing them
   through either shape is where a mechanical conversion becomes a rewrite.
6. **No build step.** Strong agree, stated as a decision: the no-bundler convention
   is load-bearing for this fork's maintainability, and a second `<script>` in the
   layout is the whole cost of sharing code. Revisit only if a real module problem
   appears, which nothing in this plan creates.

## 13. Write-path transactions

The reentrancy analysis is the plan's core and it is correct — note that the helper is
not merely convenient but *required* by the existing call graph: `ConsumeRecipe`
already wraps, and once `ConsumeProduct` wraps too, that pair nests today, before any
undo recursion enters the picture.

1. **Webhook: (a), collect and fire after commit.** Agree. Implementation note worth
   a comment in the code: build the payloads *eagerly during the loop* (from values in
   hand, not by re-reading rows after commit) and only the firing moves after commit —
   a label describing an entry should describe it as it was booked.
2. **Depth counting, not savepoints.** Agree. Nothing wants partial rollback, and an
   undo that half-succeeds is the disease being cured. If a future feature genuinely
   needs savepoints, add them then — the helper's signature doesn't change.
3. **Transaction starts after validation.** Take the consistent-and-short option:
   validation throws before `beginTransaction`, keeping the SQLite write-lock window
   minimal. The plan calls it marginal, and it is — but "the transaction contains
   only writes" is the version of consistent that also reads best.
4. **Include all seven.** `AddProduct`, `InventoryProduct`, `OpenProduct` come in
   scope. "Every stock write path is transactional" is a property worth stating
   without exceptions — and `InventoryProduct` is precisely the entrypoint plan 06's
   future camera-ingest work would hit hardest. The webhook answer from Q1 applies
   uniformly, so the blast radius Q4 worries about is one decision applied seven
   times, not three new decisions.
5. **Importer check: everything, measured.** Agree with the lean. It runs once per
   deployment lifetime against a ~30 MB database; stream rows with difftest's
   normalization and just compare all of it. If measurement on the real database says
   minutes rather than seconds, that is still fine for a once-ever command.
6. **`InTransaction` on `DatabaseService`.** As noted in General: the split against
   10's dialect-level `WithMigrationLock` is principled (engine-neutral vs
   engine-specific). Cross-referencing docblocks solve the discoverability worry;
   colocation would solve nothing.

## 14. Contract and regression scaffolding

The three-way comparison table (snapshot vs previous / vs spec / engine vs engine) is
the clearest statement of what this buys, and verification check 6 — clean checkout,
one command — is the correct acceptance criterion for the whole plan.

1. **Thin bash orchestrator, PHP comparator.** Agree — it matches what exists, and
   seed-header parsing belongs in PHP, not grep.
2. **Committed `.sql` seeds.** Agree, and agree with the reasoning against building on
   04's unbuilt importer. Regenerating seeds *from* 04 later is a nice option
   precisely because committed SQL makes the current fixtures legible in the
   meantime.
3. **GitHub Actions, with a PostgreSQL service container — firmer than the plan's
   hedge.** The fork's actual workflow is already PR-driven (everything in this
   effort has merged through PRs); a suite that runs on every PR without local
   discipline is worth more to a solo maintainer than to a team, because there is no
   second person to catch the skipped local run. Keep `make check` (or the bash
   runner directly) as the local entry point; the workflow file just calls it. The
   contract snapshot (piece 2) stays local until it has proven stable, exactly as the
   plan says.
4. **Type-level exemptions: confirm reachability, then refuse to build the
   mechanism.** If `products_view`'s `qu_factor_*` string/number difference is not
   reachable through any route — which the plan suspects — then no exemption
   mechanism is needed. And if a type difference *does* surface on a reachable route
   later, the fork's own porting rules say that is a port bug: fix the view (a
   `CAST`), don't exempt it. An exemption mechanism is where wire-format bugs go to
   become permanent.
5. **Cover all ~74 routes from the start.** The 40-odd generic `/api/objects/…`
   routes are one loop over the entity enum; full coverage is nearly free, and a
   recorded gap is still a gap. Staging by "hand-built responses first" is fine as an
   implementation order within one sitting, not as a scope decision.
6. **Committed golden files.** Agree — the diff appearing in the PR *is* the feature.
   Naming the regeneration command in the failure message is the right mitigation for
   blind regeneration; there is no better one.
7. **Timebox seed recovery to an hour.** The seeds encode which views matter, so
   recovery is worth attempting first — but the eight trigger-tests plus the
   README's fifteen documented hazards are enough of a map that rewriting from them
   is bounded work, not doubled effort. Do not let archaeology block piece 1.

## 15. Deliberate cleanup batch

Batching the breaking items is the right frame, and C10's "named so it is not
rediscovered, deferred so 13 stays verifiable" is exactly how to record accepted debt.

1. **LDAP: delete outright — and put the guard in `ConfigurationValidator`, not in a
   stub.** Push-back on the lean. A stub file is a tombstone that only handles the
   one class it replaces; a config-validation check ("`AUTH_CLASS` does not resolve
   to a class — valid values are: …, LDAP support was removed in this fork") is ten
   lines that turns *every* future bad auth class into a clear startup failure,
   forever, with no dead file in `middleware/Auth/`. It also sidesteps the
   release-cadence problem the plan admits the stub depends on.
2. **`SameSite=Lax`, `Secure` conditional on HTTPS.** Agree. One extra data point:
   embedded install mode is the desktop packaging serving on localhost same-origin,
   so even `Strict` would likely survive it — but `Lax` costs nothing and tolerates
   grocycode deep links and bookmarks arriving cross-context. `HttpOnly`
   unconditional, as planned.
3. **Cookie expiry mirrors the server-side session record.** Session cookie (no
   `Expires`) when "stay logged in" is unticked; when ticked, set the cookie expiry
   to the server-side session lifetime — and if that lifetime is currently infinite,
   give it a bound (90 days, configurable) as part of this change. The server stays
   the authority; the cookie stops being a forever-token if stolen. "Not wrong, just
   unexamined" is the plan's phrase, and examination says: bound it.
4. **PHP floor: 8.4 — push-back on the plan's lean toward 8.5.** The deciding
   evidence arrived during the defects verification: the pin had to be temporarily
   relaxed to boot on a real 8.4 box, and verification-on-real-hardware is now part
   of this fork's methodology. The code floor *is* 8.4 (the `\PDO\Pgsql`/`\PDO\Sqlite`
   subclasses); declaring 8.5 buys nothing today and taxes exactly the workflow that
   just proved its worth. Pin 8.4 in both places, keep shipping 8.5 in the image, and
   raise the pin the day the code actually uses an 8.5 feature — that commit is the
   honest place for the bump.
5. **The rename: no — not even the compatibility-view middle path, yet.** The middle
   path is clever but pays "two names in the schema forever" to resolve a naming
   niggle, and every future migration, view, seed and plan then chooses which name to
   use. Ship 05 with `shopping_locations`; record the rename as *declined unless a
   breaking batch happens for other reasons*, in which case it rides along via the
   compatibility view. A rename that never becomes worth doing was correctly never
   done.
6. **`database_version` alongside, deprecate `sqlite_version`.** Agree with the lean:
   additive now, removal rides the breaking batch. Have the new key report engine
   name and version from the live connection (`PDO::ATTR_SERVER_VERSION`), which
   also answers C3 in the same change.
7. **Ship the halves separately — and note the breaking batch just shrank.** With Q4
   resolved to 8.4 (non-breaking), Q5 declined, and Q1 a config-validation change,
   the "breaking" batch is down to B1 (LDAP config removal) and B2 (cookie flags) —
   both small. That is no longer a batch that needs a ceremonial release; do C1–C9
   opportunistically as planned, and land B1+B2 together as one ordinary, clearly
   changelogged change whenever 11's auth-adjacent work has the files open.

## Recommended landing order, all six plans in view

1. **14 piece 1** — the runnable diff suite (half a day; unblocks every other
   verification section).
2. **10** — cold start (the deployment-goal enabler; its concurrency checks now have
   a home).
3. **11** — error handling (14's sweep presents it as a diff), with 15-C1's
   authenticator refactor immediately after if plan 02 is on the horizon.
4. **12 and 13** — in parallel with any of the above; 12 before feature plans
   05/06/08, 13 before 02's write tools.
5. **14 piece 2** (contract snapshot) once 11 has stabilized the failure paths it
   would snapshot; **piece 3** (CI) as soon as piece 1 exists.
6. **15** — non-breaking items opportunistically throughout; B1+B2 as one small
   deliberate change.
