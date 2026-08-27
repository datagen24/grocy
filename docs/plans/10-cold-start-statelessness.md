# 10. Cold start and statelessness

**Goal:** A container that serves its first request correctly, with no writable state
outside the database, so scale-to-zero pods are a deployment choice rather than a
gamble.
**Depends on:** nothing. Pairs with [01 file storage](01-file-storage.md), which removes
the other half of the writable data directory.
**Status:** draft for review.

## Today

Three things happen before the router is even built, and all three are hostile to an
ephemeral filesystem.

**The version-hash redirect** (`app.php:52-77`). A hash of `version.json` plus
`GROCY_BASE_URL` plus `GROCY_BASE_PATH` names a marker file inside
`data/viewcache`. If the marker is absent the whole cache directory is emptied,
opcache is reset, and the request — whatever it was — is answered with a 302 to `/`.
On an ephemeral filesystem the marker is always absent, so this is not an upgrade
path, it is the cold-start path. An API client's first call after a scale-up gets a
redirect to an HTML page instead of data.

**Migrations run inside a request.** `/` is the only route that calls
`MigrateDatabase()` (`SystemController::Root`), which is why the redirect targets it.
`DatabaseMigrationService` has no concurrency guard anywhere: `EnsureMigrationsTable`,
`ApplyBaselineSchemaWhenNeeded` and each of `ExecuteSqlMigrationWhenNeeded` /
`ExecutePhpMigrationWhenNeeded` do check-then-apply. Two pods starting together roll
back cleanly on the loser's primary-key violation — no corruption — but the loser's user
sees a 500. `migrations/8888.php` is worse: it runs on *every* invocation by design, does
`count() == 0` then `INSERT id 1` on `locations`, and is not inside the per-migration
try/catch, so the race there is unguarded.

**Everything writable lives in one directory that is also the cache directory.**

| Path | Written by | Needs to survive a restart? |
|---|---|---|
| `data/viewcache/*.php` | Blade, on first render of each of the 73 views | No — derivable from the source tree |
| `data/viewcache/route_cache.php` | Slim (`app.php:120`) | No — derivable from `routes.php` |
| `data/viewcache/<hash>.txt` | `app.php:68` | No — exists only to drive the redirect |
| `data/viewcache/` HTMLPurifier serializer | `BaseApiController::GetParsedAndFilteredRequestBody` | No — but it is a runtime write on every JSON write request |
| `data/storage/` | `FilesService` | Yes — until [01](01-file-storage.md) lands |
| `data/config.php`, `data/settingoverrides/` | nobody | Read-only; ConfigMap-compatible already |
| `data/grocy.db` | SQLite | Moot under PostgreSQL |

The HTMLPurifier serializer path is the one the review did not list, and it is the
reason a "read-only except for the database" pod does not work today even after the
redirect is gone: every `POST`/`PUT` through the generic CRUD API touches it.

**One thing that is better than it looks.** The hash includes `GROCY_BASE_URL` and
`GROCY_BASE_PATH`, which implies compiled views embed the deployment URL. They do not.
No template under `views/` references either constant; URLs are built by the `$U`
closure injected at render time (`controllers/BaseController.php:78`). Compiled Blade
output is therefore a pure function of the source tree, which is what makes baking it
into the image possible at all. The two URL terms in the hash appear to be defensive
rather than load-bearing — see Q1.

**PrerequisiteChecker runs on every request** and requires `pdo_sqlite` plus SQLite
≥ 3.40, opening `sqlite::memory:` to read the version, regardless of `DB_DRIVER`. On a
PostgreSQL-only deployment that is a pointless connection per request and a pointless
extension in the image. `REQUIRED_PHP_VERSION` there is `8.5.0`, which is its own
problem — see [15](15-deliberate-cleanup.md).

## Proposed change

### Separate the cache directory from the data directory

New setting `GROCY_VIEWCACHE_PATH`, defaulting to `GROCY_DATAPATH . '/viewcache'` so
nothing changes for existing installs. Container images set it to a baked, image-local
path such as `/app/viewcache`. `SlimBladeView`, the Slim route collector and the
HTMLPurifier serializer all read that one setting instead of deriving their own path
from the data path.

### Bake the cache at image build time

`bin/grocy-warm-cache`: compiles every template under `views/` and writes the Slim
route cache, then exits. Run it as the last step of the image build. The result is
deterministic per source tree, so it is a layer, not state.

Blade compiles on demand when a compiled file is missing, so a template the warmer
missed degrades to today's behaviour rather than failing — but only if the directory is
writable. Whether to make it strictly read-only (and so turn a missed template into a
hard error, which is the honest failure mode for an immutable image) is Q2.

### Move migrations out of the request path

`bin/grocy-migrate`, a CLI entry point that bootstraps config the way `bin/grocy-db-import`
already does, takes the lock, runs `MigrateDatabase()`, and exits non-zero on failure.
That is an initContainer in k3s and a one-line `docker exec` elsewhere.
`DatabaseMigrationService` already works outside a request; nothing about it needs to
change except the lock.

`SystemController::Root` then stops calling `MigrateDatabase()`. Q4 covers whether a
web-triggered fallback should remain for people running this fork from a stock
container image with no init step.

### One cross-process lock around the whole run

```
pgsql:  SELECT pg_advisory_lock(<constant>)   ... run ...   SELECT pg_advisory_unlock(...)
sqlite: flock() on <db path>.migrate.lock, LOCK_EX, blocking
```

A new `DatabaseDialect` method (`WithMigrationLock(callable)`) is the natural home:
it is genuinely per-engine, unlike the date-offset primitive the review proposed and the
defects pass rejected. The lock wraps the *entire* `MigrateDatabase()` call, baseline
included, so the always-run `8888.php` is covered by the same guard as everything else.

The advisory lock is session-scoped and held on the connection, so it must be taken on
`GetDbConnectionRaw()` and released in a `finally`; a crashed process releases it when
its connection closes, which is the behaviour we want.

`8888.php` should additionally be made idempotent on its own terms — the location-1
insert becomes conditional in SQL rather than in PHP — so that it is safe even if
someone runs migrations outside the CLI entry point.

### Remove the version-hash redirect

With the cache baked and migrations moved, `app.php:52-77` deletes entirely: no
`EmptyFolder`, no marker file, no `opcache_reset` (meaningless in an immutable image
that is replaced rather than updated in place), no 302. Upgrades in a mutable
deployment are then covered by "run `bin/grocy-warm-cache` after updating", which
`update.sh` can do.

### Skip the SQLite checks on a PostgreSQL-only deployment

`PrerequisiteChecker` takes the configured driver into account: `pdo_sqlite` and the
SQLite version check apply when `DB_DRIVER` is `sqlite`, `pdo_pgsql` when it is `pgsql`.
That removes a per-request in-memory connection and lets the image drop the extension.
`ApplicationService::GetSystemInfo()` opens the same throwaway connection for the About
dialog; that one is cosmetic and is handled in [15](15-deliberate-cleanup.md).

### Explicitly not in scope

The request-scoped `define()` constants (`GROCY_USER_ID`, `GROCY_LOCALE`, …) stay. They
are safe under php-fpm and only rule out worker-mode runtimes, which are not a goal.
Recording that as a decision is worth more than changing it.

### Schema

None. This plan changes when migrations run and how they are serialised, not what they
do. No new migration file, so no dual-engine migration shape to state.

### API

No endpoint gains, loses or changes a field. Two behavioural changes are worth stating
plainly because they are visible to clients:

- **A cold-start request is no longer answered with a 302 to `/`.** This is a fix, but
  any client that learned to follow that redirect will simply stop seeing it.
- **`GET /` no longer migrates the schema.** Anyone pulling this fork into a container
  that has no init step, and relying on "hit the page once after an update", loses that
  behaviour unless Q4 says otherwise. This is the one item in this plan that can break
  an existing deployment, and it should be in the changelog rather than discovered.

## Verification

Lint proves nothing here; every check below wants a booted instance.

1. **Fresh container, first request is an API call.** Empty data directory, empty
   PostgreSQL database, migrations run by the init step. `curl -H 'GROCY-API-KEY: …'
   /api/stock` as the very first request must return 200 with a JSON body — today it
   returns 302 with an HTML target. Repeat on SQLite.
2. **Concurrent cold start.** Two `bin/grocy-migrate` processes against the same empty
   PostgreSQL database, started together; and the same on SQLite. Both must exit 0, the
   `migrations` table must contain each number exactly once, and `locations` must contain
   exactly one row with id 1. Run it ten times — the current race is timing-dependent and
   a single green run means nothing.
3. **Racing pods against an already-migrated database.** Five concurrent
   `bin/grocy-migrate` runs must be no-ops and must not deadlock (this is the always-run
   `8888.php` path, which is the one that runs on every start forever).
4. **Read-only root filesystem.** Boot with the image's filesystem mounted read-only
   except for the database, browse every top-level page, and perform one create, one
   edit and one delete through the API. Any write attempt to the baked cache directory
   is a failure to fix, not to work around — this is what catches a template the warmer
   missed and the HTMLPurifier serializer path.
5. **Schema equivalence unchanged.** `difftest.php` over the full view list and the
   existing `trigdifftest.php` scripts must produce the same output before and after.
   Nothing here should touch schema, so any diff is a bug in the lock wrapping.
6. **Prerequisite skip is real.** On a `pgsql` deployment, remove `pdo_sqlite` from the
   image and confirm the app boots and serves pages; confirm a `sqlite` deployment still
   fails loudly with the existing message when the extension is missing.

## Sequencing

**First among the hardening plans, and independent of the feature roadmap.** Nothing in
01–09 blocks on it and it blocks nothing, but it is the prerequisite for k3s work being
pleasant rather than superstitious, and it should land before any effort goes into
scale-to-zero tuning.

It pairs with [01 file storage](01-file-storage.md): 01 removes `data/storage`, this
removes everything else writable, and only both together give a pod with no volume. Do
this one first — 01's importer is easier to reason about when the cold-start path is no
longer rewriting requests.

Against the other hardening plans it is independent: it touches `app.php`, the migration
service and `PrerequisiteChecker`, none of which [11](11-api-error-handling.md),
[12](12-frontend-shared-core.md), [13](13-write-path-transactions.md) or
[14](14-contract-and-regression-scaffolding.md) go near. It can be done in parallel with
any of them.

## Open questions

1. **Are `GROCY_BASE_URL` / `GROCY_BASE_PATH` still load-bearing in the cache hash?**
   Nothing under `views/` reads either constant and `$U` resolves at render time, which
   says no. But the hash was written for a reason, and the honest check is to compile the
   cache under one base path, serve under another, and diff the rendered HTML — not to
   reason about it. If they are genuinely irrelevant, the cache is a build artifact and
   this whole plan gets simpler.
2. **Should the baked cache directory be read-only, or writable as a fallback?**
   Read-only turns a missed template into a 500 at exactly the moment someone opens that
   page, which is a bad time to find out. Writable hides the problem and quietly
   reintroduces a mutable path. I lean read-only *plus* a warmer that fails the build if
   it did not compile every file under `views/`, which moves the discovery to build time
   where it belongs.
3. **Where does the SQLite lock file live?** `flock` needs a real file. Next to the
   database is the obvious answer and inherits its permissions, but it puts a second file
   in a directory that some deployments treat as "the database, nothing else". The
   alternative is locking the database file itself, which is safe with `flock` but
   surprising to read.
4. **Keep a web-triggered migration fallback?** Removing it entirely is cleanest and
   matches the deployment target. Keeping it behind a setting
   (`MIGRATE_ON_ROOT_REQUEST`, default off) costs almost nothing and keeps the fork
   usable in a stock container image with no init step. I lean to keeping the setting,
   defaulting off, precisely so the default is the immutable one.
5. **Does the migration CLI also warm the cache?** They are different lifecycles — one is
   per image build, the other is per deployment — but a single `bin/grocy-init` that does
   both is one less thing to forget. I lean to keeping them separate commands and letting
   the image build and the initContainer each call the one they need.
6. **What happens when the app boots against a database that is behind the code?**
   Today it silently migrates. With migrations moved out, the options are: fail fast with
   a clear message, serve anyway and break unpredictably, or check the `migrations` table
   on boot and refuse. Failing fast is the only honest one, but it needs a cheap check —
   one `SELECT MAX(migration)` per request is probably acceptable, and probably wants to
   be behind the same setting as Q4.

## Effort

Medium. The individual pieces are all small — the cache warmer is an afternoon, the lock
is a dialect method, deleting the redirect is a deletion — but the verification is the
real cost: several of the checks above want two engines and genuinely concurrent
processes, which is fixture work that does not exist yet. Doing
[14](14-contract-and-regression-scaffolding.md) first would give this plan somewhere to
put its cold-start tests, but it is not a hard dependency and this one is more urgent.
