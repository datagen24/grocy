# 14. Contract and regression scaffolding

**Goal:** Turn the fork's two differential test scripts into a suite anyone can run in
one command, add a response-contract snapshot so the additive-API rule is enforced by a
failing test rather than by vigilance, and put both behind minimal CI.
**Depends on:** nothing. Everything else in the roadmap is easier once this exists.
**Status:** draft for review.

## Today

The fork has exactly one safety net and it is entirely manual.

**`difftest.php`** puts both engines into an identical table state and compares what
their views return. It is a good tool — it is what proves the PostgreSQL port rather than
asserting it. But its interface is:

    docker run --rm --network grocynet -v "$PWD":/app -v /path/to/scratch:/scratch \
      -v /path/to/scratch/data:/data \
      -e DIFFTEST_SQLITE_DSN=… -e DIFFTEST_PGSQL_DSN=… \
      grocy-fork-dev php /app/.devtools/pgsql/difftest.php seed.sql <view> [<view> …]

and `seed.sql` is not in the repository. Neither is the list of views. There are 45 views
in the baseline; which of them a given change should be tested against is knowledge that
lives in whoever ran it last. `DIFFTEST_SKIP_COPY=1` exists to run the comparison against
a database populated by `bin/grocy-db-import` — a genuinely valuable second mode that is
documented in a comment and nowhere else.

**`trigdifftest.php`** is in better shape: eight scripts are committed under
`.devtools/pgsql/trigger-tests/`, it has an `-- @expect-error` convention for asserting
that a trigger rejects something, and it compares every table rather than a named list.
It still needs the same eight-line docker invocation, a `grocy_en.db` pristine database
at a path only the operator knows, and a scratch directory.

**Nothing tests the HTTP surface at all.** No test exists — of any kind — that calls an
endpoint and looks at the response. This matters more here than in most codebases,
because of what the review identified as the deeper additive-API risk: nearly every
response is a raw LessQL row or view serialised as-is. **The database schema is the wire
contract**, and there is no tripwire between a migration and a client. Change a view's
column type and the JSON changes; nothing anywhere notices.

The one route/spec mismatch in the whole API — `/api/openapi/specification` missing from
`grocy.openapi.json` — is a small illustration: it is a mismatch that has survived because
nothing compares the two.

**Two facts that shape what the fixtures can be:**

- `DemoDataGeneratorService` is SQLite-only (defect 13 skipped it on other engines rather
  than porting it), so "generate a demo database" is not available as a way to populate a
  PostgreSQL test instance. The fixing pass also found that a *ported* generator would
  still be wrong: it inserts explicit `quantity_units` ids and never calls
  `ResyncGeneratedIdCounters`, so the identity sequence would sit behind the data.
- `difftest.php` has never been run against a recursive CTE. Plans
  [07](07-nested-products.md) and [08](08-nested-locations.md) both introduce one, so
  their fixtures will be new ground for the tool as well as for the schema.

## Proposed change

Three pieces, in dependency order. The first is worth doing even if the other two never
happen.

### 1. A runnable suite around the tools that exist

`.devtools/pgsql/run-tests.sh` (or a small PHP runner — Q1) that:

- stands up both engines, or connects to already-running ones, with the docker invocation
  and its seven environment variables in the script instead of in a README code block;
- runs every committed view seed against its declared view list;
- runs every script in `trigger-tests/`;
- exits non-zero if any comparison differs;
- prints one line per case, not per row, unless something differs.

Alongside it, `.devtools/pgsql/view-tests/` gains committed seed files, mirroring the
existing `trigger-tests/` convention: each seed declares (in a leading comment, parsed by
the runner) which views it exercises. The seeds that were used to verify the PostgreSQL
port exist somewhere in the history of that work; recovering them is cheaper than writing
new ones, and they already cover the interesting cases.

Committed fixture data, not a generated demo database — for the reasons above, and
because a fixture whose contents are visible in the diff is a fixture you can reason
about. What that fixture *is* — a `.sql` seed, a checked-in SQLite file, or the seed
importer from [04](04-seed-datasets.md) — is Q2.

### 2. Response-contract snapshot

A script that, against a booted instance seeded with the same fixture:

- calls every route in the table (~74) with a valid key;
- records, per route, the **JSON key set** and the **scalar type of each value** — not the
  values themselves, which are fixture-dependent and would make the snapshot brittle;
- compares that against the OpenAPI schemas, and against the previous snapshot;
- runs against both engines and requires them to agree.

Values are deliberately out of scope. The engines are already known to disagree on float
accumulation order and on `chores.start_date` rendering; those are documented accepted
differences in `db/pgsql/README.md` and a value-comparing snapshot would fight them
forever. Key sets and types are the actual contract, and are what a migration silently
changes. Q4 covers whether the two accepted differences need an explicit exemption even
at the type level.

The comparison is three-way and each leg catches something different:

| Comparison | Catches |
|---|---|
| snapshot vs previous snapshot | a migration that changed a response |
| snapshot vs OpenAPI schema | a response that never matched its documentation |
| engine vs engine | a port that diverged on the wire |

The `/api/openapi/specification` gap gets fixed here, along with a route-table-vs-spec
parity assertion so the next one cannot survive.

### 3. Minimal CI

`php -l` over every `.php` file, `node --check` over every `.js` file, and the suite from
piece 1. That is the whole of it. Lint is nearly worthless on its own — it is in here
because it is free and because it catches the one class of mistake (a syntax error in a
file nobody exercised) that costs a deploy. The differential suite is the part that
matters, and it needs a PostgreSQL service container, which is the only real question
about the CI shape (Q3).

Piece 2 in CI needs a booted instance, not just two databases, which is a step up in
complexity. It may be right to run it locally first and add it to CI once it has proven
stable.

### Schema

None. This plan adds no migration and touches no view. It does add fixture SQL, which is
not schema and does not ship in the image.

If Q2 lands on reusing [04](04-seed-datasets.md)'s importer for fixtures, that creates a
dependency in the other direction — 04 would need building first — which is an argument
against it for now.

### API

**No change to any endpoint**, with one exception: `grocy.openapi.json` gains the missing
`/api/openapi/specification` path, and gains documented error responses for whatever
[11](11-api-error-handling.md) has converted by then. Adding a path to the spec is
additive by definition and changes no response.

The plan's *purpose*, though, is API compatibility: after it exists, "existing endpoints
keep their response shape" — the second ground rule in this README — is a test that fails
rather than a rule that gets remembered.

## Verification

A test suite whose own correctness is unverified is worse than no suite, because it
produces confident green output. So the verification here is mutation-shaped: break
things deliberately and confirm the suite notices.

1. **The view suite catches a real regression.** Change one baseline view on the
   PostgreSQL side in a way that alters output — a `CAST(x AS INT)` where SQLite
   truncates and PostgreSQL rounds is hazard 7 in `db/pgsql/README.md` and is a realistic
   mistake — and confirm the runner exits non-zero and names the view. Revert.
2. **The trigger suite still passes unchanged.** All eight existing scripts must produce
   identical results through the new runner as through the current manual invocation. If
   the runner changes any of them, the runner is wrong.
3. **The runner fails loudly on a missing fixture.** `trigdifftest.php` already refuses to
   report "identical state" for a script it could not read; the new runner must have the
   same property for seeds and for a database it could not connect to. A suite that
   silently skips is the failure mode to design against.
4. **The contract snapshot catches a schema change.** Add a column to a view that is
   exposed through an endpoint, regenerate, and confirm the diff shows exactly that key
   appearing on exactly that route. Then change a column's type and confirm the type diff
   shows. Revert both.
5. **The contract snapshot survives the accepted differences.** Run it against both
   engines with the fixture and confirm a clean result — `products_average_price.price`
   and `chores.start_date` must not produce a diff. If they do, Q4's exemption mechanism
   is needed rather than optional.
6. **The whole suite runs from a clean checkout** on a machine that has not run it before,
   with only the documented prerequisites, in one command. This is the actual acceptance
   criterion; everything above is secondary to it. If it takes seven environment variables
   and a scratch directory that only one person knows the path to, it has not been built.
7. **Recursive CTE coverage.** Add a throwaway fixture with a three-level product tree and
   a recursive view over it, and confirm the runner compares it correctly on both engines.
   This is a check on the *tool*, not on the schema — [07](07-nested-products.md) and
   [08](08-nested-locations.md) depend on it working and it has never been exercised.

## Sequencing

**Highest leverage of the hardening plans, and the one with the most downstream
dependents.** Three separate things in the roadmap already assume it exists:

- [07](07-nested-products.md)'s "Verification" section says to extend `.devtools/pgsql/`
  with a three-level-tree fixture *before touching anything*. That is this plan.
- [review-comments](review-comments.md) makes the same point about
  [08](08-nested-locations.md), and recommends the fixture suite before the
  recursive-hierarchy work generally.
- [02 MCP](02-mcp-endpoint.md) is the reason the response-contract snapshot is worth
  building at all: it puts a second consumer on an API whose wire format is an accident of
  the schema.

**Before [11](11-api-error-handling.md)**, if both are being done. 11 deliberately changes
status codes on failure paths across ~74 routes, and its own verification section is two
full-surface sweeps. Building the sweep here and letting 11 present its changes as a diff
is strictly better than 11 asserting them by hand.

**After [10](10-cold-start-statelessness.md)** is mildly preferable but not required — a
suite that boots an instance is simpler to write once the first request is not a redirect.

**It blocks no feature plan today**, but it is the fixture home that
[07](07-nested-products.md) and [08](08-nested-locations.md) both plan against, and the
enforcement mechanism the README's additive-API ground rule currently lacks. The first
shipped dual-engine migration should still be a small one
([06](06-location-barcodes.md), per the review) — this suite is what will tell you whether
it worked.

## Open questions

1. **Bash runner or PHP runner?** Bash is the obvious shape for "start containers, loop
   over files, collect exit codes" and adds no dependency. PHP means the runner can share
   `difftest.php`'s normalisation logic and can parse the seed headers properly rather
   than with `grep`. I lean to a thin bash script that orchestrates and PHP that compares,
   which is roughly what exists already — the missing part is only the orchestration.
2. **What is the fixture?** Committed `.sql` seeds are the most legible and match the
   existing `trigger-tests/` convention. A checked-in SQLite file is faster to load and
   harder to review. [04](04-seed-datasets.md)'s name-keyed importer would be the most
   reusable but does not exist yet, and building this plan on an unbuilt one is backwards.
   I lean to committed `.sql` seeds now, with the option to regenerate them from 04's
   importer later. Note that the demo data generator is *not* an option on PostgreSQL.
3. **Does CI get a PostgreSQL service container, and where does CI run?** There is no
   `.github/workflows/` directory today — the fork has never had CI. A service container
   is standard and free on GitHub Actions. The question is really whether CI runs there at
   all, given this is a personal fork deployed to a private k3s cluster; a git hook or a
   local `make check` may be the honest answer, in which case "minimal CI" means "one
   command a human runs" rather than a workflow file.
4. **Do the two accepted engine differences need an exemption at the type level?**
   `products_average_price.price` differs in the last bit, which is a value difference, not
   a type one — so probably not. `chores.start_date` differs in *rendering* of the same
   string type — also probably not. But `qu_factor_*` in `products_view` is documented as
   SQLite returning the string `"1.0"` where PostgreSQL returns the number `1`, which **is**
   a type difference and would fail a type-level snapshot. That view is not in
   `ExposedEntity` and may not be reachable from any route — worth confirming before
   designing an exemption mechanism nothing needs.
5. **How much of the API does the contract snapshot cover?** All ~74 routes is the
   complete answer and is mostly mechanical, since 40-odd of them are the generic
   `/api/objects/{entity}` shape. The stock, recipes and chores endpoints are the ones
   with hand-built responses and the ones where a change is most likely — starting there
   and expanding is a reasonable staging, as long as the gap is recorded rather than
   forgotten.
6. **Are snapshots committed, or generated and compared in-place?** Committed golden files
   make the diff show up in code review, which is the whole point — a reviewer sees "this
   PR changes the shape of `/api/stock`" without running anything. They also add churn and
   invite blind regeneration. I lean to committed, with the regeneration command named in
   the failure message so at least the blind regeneration is deliberate.
7. **Where do the original difftest seeds live?** The PostgreSQL port was verified with
   seeds that are not in the repository. Recovering them from that work's history is much
   cheaper than rewriting, and they encode which views matter. If they are genuinely gone,
   the effort estimate below roughly doubles.

## Effort

Medium, with a wide range depending on Q7. Piece 1 is half a day if the seeds are
recoverable and two days if they must be written from scratch — and that half day is the
single best-value item in the whole hardening set, because it is what makes every
subsequent plan's verification section actually runnable.

Piece 2 is the larger piece and the more interesting one: a day for the harness, plus the
per-route work, plus the schema comparison. Piece 3 is an hour once piece 1 exists.

Worth splitting: land piece 1 on its own and use it. Piece 2 can wait until
[11](11-api-error-handling.md) or [02](02-mcp-endpoint.md) makes it urgent, but not later
than either of those.
