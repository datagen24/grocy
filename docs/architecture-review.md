# Architecture review — 2026-08-27

Full-codebase review for architectural stability and uniformity, run area by area
(backend PHP, services + database layer, API surface, frontend). Judged against this
fork's actual bar: household scale, correctness and consistency over enterprise
ceremony, with the deployment target being immutable, scale-to-zero pods on k3s.

A companion documentation pass added PHPDoc/JSDoc coverage across controllers,
services, middleware, helpers, and the browser-side JS; see the commit history on this
branch. Comments on the roadmap plans live in [docs/plans/review-comments.md](plans/review-comments.md).

## Executive summary

The codebase is **architecturally stable and unusually uniform** for a
convention-driven app of this vintage: one controller shape, one middleware base, one
view/viewjs naming convention honored by all 73 views, a service layer with a single
access idiom, and an API whose route table and OpenAPI spec agree almost perfectly.
The fork's dual-engine database work is careful where it is deliberate (typed baseline
schema, CASE-wrapped booleans, a NOCASE collation, differential view/trigger tests).

The review found no structural rot. What it found instead is:

1. **A handful of real defects** — several of them fork-relevant, two of them
   one-line fixes (see "Defects to fix first").
2. **The single biggest architectural gap: raw SQLite date SQL in five page
   controllers** that bypasses the dialect layer and breaks those pages on
   PostgreSQL — the fork's own headline feature.
3. **Cold-start behavior hostile to scale-to-zero** — the first request on a fresh
   container is hijacked into a viewcache reset + redirect + inline web-triggered
   migration.
4. **Uniformity achieved by copy-paste** — the frontend's ~30 list/form scripts and
   the API's per-endpoint try/catch blocks are clone families; they are consistent
   today but drift is already measurable.

## Defects to fix first

Concrete bugs found while reviewing, ordered by impact. None are large.

| # | Defect | Where | Fix |
|---|---|---|---|
| 1 | `GET /api/batteries/{id}` always fails: committed debug `throw new \Exception('df')` | `controllers/Api/BatteriesApiController.php:18` | Delete the line |
| 2 | `/api/system/config` leaks `DB_PASSWORD`, `DB_USER`, `DB_HOST`, `LDAP_BIND_PW` to any authenticated API key — the DB leak is fork-introduced (the new `DB_*` settings joined an endpoint that filters by blocklist) | `controllers/Api/SystemApiController.php:13-38` | Switch to an allowlist of frontend-needed settings |
| 3 | Five page routes crash on PostgreSQL: raw `DATE('now','localtime', ...)` / `STRFTIME` in journal/report/mealplan queries | `StockController.php:67,72`, `ChoresController.php:74,79`, `BatteriesController.php:87,92`, `StockReportsController.php:24`, `RecipesController.php:30,62` | Compute cutoff dates in PHP and bind as parameters (portable, also removes string interpolation) |
| 4 | Any authenticated user can delete any API key by ID (sequential IDs, no ownership check, `api_keys` not in `ExposedEntityNoDelete`) | `controllers/Api/GenericEntityApiController.php:96-99` | Restrict to own keys unless admin |
| 5 | Session/API keys generated with non-crypto `rand()` | `helpers/extensions.php` (`RandomString`), used by `SessionService.php:79`, `ApiKeyService.php:152` | Use `random_int()` / `random_bytes()` |
| 6 | `WebhookRunner` catches nonexistent `Grocy\Helpers\RequestException`, so a printer webhook failure 500s the user action instead of being handled | `helpers/WebhookRunner.php` | Import `GuzzleHttp\Exception\RequestException` |
| 7 | `/recipes` 500s on a fresh install: unguarded `$selectedRecipe->id` when no recipes exist | `controllers/RecipesController.php:113` | Hoist the existing `if ($selectedRecipe)` guard |
| 8 | LDAP filter injection: raw POST username interpolated into the search filter | `middleware/Auth/LdapAuthMiddleware.php:35` | `ldap_escape(..., LDAP_ESCAPE_FILTER)` |
| 9 | Stack traces served in production: `addErrorMiddleware(true, ...)` hardcoded, so every 500 includes `error_details.stack_trace` | `app.php:115` | Gate on `GROCY_MODE === 'dev'` |
| 10 | `FilesService.php:54` tests `$bestFitHeight !== null` twice (second was surely `$bestFitWidth`); `:70` catches an unimported `ImageResizeException` that can never match | `services/FilesService.php` | Fix the operand; import the Gumlet exception |
| 11 | `LogMissingLocalization` returns `null` (→ 500) outside dev mode | `controllers/Api/SystemApiController.php:76-92` | Return `EmptyApiResponse` unconditionally |

## Statelessness / k3s readiness

Sessions and API keys are DB-backed — the hard part is already right. What still
touches local mutable state:

- **`data/viewcache` + version-hash marker (the big one).** On any container whose
  viewcache lacks the current version hash, `app.php:52-77` empties the cache, resets
  opcache, and 302-redirects the request — whatever it was — to `/`, which then runs
  schema migrations inline (`SystemController::Root`). On an ephemeral filesystem this
  happens on **every cold start**: API clients get a 302 instead of data, and
  concurrent first requests race through `EmptyFolder()` + migration with no locking.
  The Slim route cache (`app.php:118`) is written to the same data dir.
  **Direction:** bake compiled Blade views and the route cache into the image (or an
  emptyDir keyed by image version), run migrations from an initContainer/CLI
  entrypoint (`DatabaseMigrationService` works outside a request), drop the redirect.
  This is the prerequisite for scale-to-zero being pleasant, and pairs with plan 01
  (file storage in the database), which removes `data/storage`.
- **`data/storage`** — uploaded files; removed by plan 01.
- **`data/config.php` + `data/settingoverrides/`** — read-only at runtime; a stub
  config plus `GROCY_*` env vars is already viable, ConfigMap-compatible.
- **SQLite artifacts** (`data/grocy.db`, file-mtime change tracking) — moot under
  PostgreSQL; the Postgres dialect already replaced mtime tracking with a table.
- **Request-scoped `define()` constants** (`GROCY_USER_ID`, `GROCY_LOCALE`, …) — safe
  under php-fpm, but permanently rule out worker-mode runtimes (FrankenPHP, Swoole).
  Not worth changing unless that becomes a goal.
- **PrerequisiteChecker requires `pdo_sqlite` ≥ 3.40 and opens `sqlite::memory:` on
  every request even on pgsql-only deployments** — skip when `GROCY_DB_DRIVER=pgsql`
  to slim the image and the request path.

## Backend (controllers, middleware, helpers)

**Verdict: uniform to a fault; the defects above are the exceptions, not the rule.**

- Layering is the conventional grocy split: controllers read views/tables directly
  for page data, services own writes and domain logic. It is applied consistently and
  is fine at this scale. The one outlier worth moving: `StockReportsController`
  embeds three hand-written multi-join SELECTs (also the SQLite-date offender) —
  a `StockReportsService` would put all raw SQL behind the dialect boundary.
- Services are singletons via `GetInstance()` while a PHP-DI container is wired and
  used for controller construction — two DI idioms coexisting. Harmless, but pick one
  direction for new code (the plans' new services should take constructor injection
  from the container).
- Auth middleware chain: composition works but is fragile — middlewares instantiate
  *other* middlewares and call `AuthenticateRequest` cross-instance, visibility
  drifts per subclass (protected in `DefaultAuthMiddleware`, public in three others),
  and `ProcessLogin` is an abstract static that three of five subclasses stub with
  `throw`. Extracting credential-checking into plain authenticator classes would
  remove the awkwardness; do it opportunistically, e.g. when plan 02 touches auth.
- Session cookie: set via bare `setcookie()` outside PSR-7, no `HttpOnly`/`SameSite`/
  `Secure`, never expires. Worth hardening in one small change alongside defect 5.
- Request-data access has three coexisting styles (PSR-7 `getQueryParams()`,
  slim/http decorated `getQueryParam()`, raw superglobals in three places).
  Standardize opportunistically.
- Minor debt: unused `EquipmentController::$UserfieldsService`; unreachable duplicate
  block in `ExceptionController` (also: it trusts `$exception->getCode()` as an HTTP
  status — clamp it); config comment for locale priority contradicts
  `LocaleMiddleware`'s actual order; `composer.json` pins PHP `8.5.*` while the real
  code floor is 8.4.

## API surface

**Verdict: structurally sound and spec-honest; per-endpoint error discipline is the
weak spot.**

- Route table vs `grocy.openapi.json`: of ~74 API routes, the only mismatch is the
  self-referential `/api/openapi/specification` missing from the spec. The
  `ExposedEntity` allow-lists are read from the spec at runtime, so entity drift is
  impossible by construction — a genuinely good mechanism, and the right foundation
  for the "additive API" ground rule.
- Error handling is where uniformity breaks: permission failures return 403 or 400
  depending on whether `CheckPermission` sits inside or outside a `try`;
  `UsersApiController` alone re-emits real status codes via a second catch; malformed
  `?query[]=`/`?order=` params 500 with stack traces; missing objects are 404 from
  `GetObject` but 400 from `EditObject`/`DeleteObject`. **Direction:** one shared
  helper in `BaseApiController` (catch `HttpSpecializedException` before the generic
  catch, map query-parse errors to 400, missing resource to 404) and hoist permission
  checks above try blocks — a mechanical, low-risk cleanup that would make the whole
  API behave like its best controller.
- The 401 path bypasses the JSON and CORS middleware (auth is app-level, they are
  route-level): unauthenticated API calls get a bodyless 401 without CORS headers,
  and `OPTIONS` preflights sit behind auth, so browser cross-origin API-key use
  cannot work at all despite `CorsMiddleware` existing. Short-circuit `OPTIONS` and
  emit the standard error JSON on 401 — or, given the deployment, consider making
  CORS config-driven and default-off.
- The deeper "additive API" risk: nearly every response is a raw LessQL row/view
  serialized as-is, so the DB schema *is* the wire contract, with no tripwire when a
  migration changes it. The differential tests already check JSON value types across
  engines; extending that idea one layer up — a snapshot test of JSON key sets and
  scalar types per endpoint against the OpenAPI schemas — is the single best
  investment before building the MCP endpoint (plan 02) on top of this API.
- Smaller items: `ChoresApiController::CalculateNextExecutionAssignments` has no
  permission check; generic CRUD allows mass assignment of `id`/timestamps and the
  `ExposedEntityEditRequiresAdmin` gate is an empty enum (dead code — populate or
  remove); API keys are accepted via query parameter (logs) and stored/compared in
  plaintext; keys' `last_used` is UPDATEd on every request.

## Frontend (views, viewjs, shared JS)

**Verdict: stable, convention-complete, no dead files — but the conventions are
enforced by copying, and the copies are drifting.**

- The wiring is exemplary for a no-framework app: layout auto-loads
  `/viewjs/{view}.js`, all API traffic goes through `Grocy.Api.*` (zero `$.ajax`/
  `fetch` bypasses), inline Blade scripts are data-injection only, and every
  view/viewjs/route name lines up.
- **Silent failures are the default error path:** 148 error callbacks in 41 files do
  nothing but `console.error(xhr)` — a failed delete gives the user nothing. The fix
  is central, not 148-fold: make `Grocy.Api`'s error parameter default to
  `Grocy.FrontendHelpers.ShowGenericError`.
- **Clone families:** ~14 master-data list scripts and ~15 entity-form scripts are
  byte-identical modulo entity name (~2,300 lines total); the delete-confirm dialog
  appears 31 times; `Grocy.Api` itself repeats its 30-line XHR handler six times (and
  has no timeout/`onerror` handling — a dropped connection during save leaves the UI
  busy-locked forever); `datetimepicker2` is a full 344-line clone of
  `datetimepicker` existing only so two pickers can share a page. Drift is already
  observable: sibling lists disagree about the embedded-dialog reload convention,
  `userobjectform.js` lost the Enter-to-submit handler its siblings have,
  `stockjournal.js`/`userpermissions.js` hand-roll `toastr.error(JSON.parse(...))`.
  **Direction:** three small shared helpers — `GrocyEntityList(entity, opts)`,
  `GrocyEntityForm(entity, url)`, and a single private `request()` core inside
  `Grocy.Api` — would collapse ~1,500 lines and end the drift. Do it before plans
  05/06/08 add more list/form pairs to copy from.
- `purchase.js` doubles as an unguarded shared library on three other pages (pulled
  in via `@push` in their Blade views); extract the shared dialog logic instead.
- Minor: list-page Blade chrome repeats across ~14 templates (partials exist and are
  used elsewhere — apply the idiom); `mealplan`/`recipes` blades inject bare globals
  instead of namespacing under `Grocy.*`; two components aren't registered under
  `Grocy.Components`.

## Services and database layer

_(section pending — being rewritten from the dedicated services/DB review)_

## Uniformity: the short list of deviants

Files that break their area's dominant pattern (details in sections above):
`StockReportsController` (raw SQL in controller), `ExceptionController` (API base
class for HTML errors, manual construction), `UsersApiController` (the only
correct-status API controller — make its pattern the base), `RecipesApiController::
AddNotFulfilledProductsToShoppingList` (no try/catch), `FilesApiController` (throws
404 for input errors), `CalendarApiController::Ical` (non-JSON, fine),
`userobjectform.js`, `userpermissions.js`, `stockjournal.js`,
`quantityunitconversionsresolved.js`, `productgroups.js` (frontend drift markers),
`purchase.js` (dual-role script), `LoginController` (superglobals + static dispatch),
`DemoDataGeneratorService`.

## Suggested order of remedial work

1. **Defects table** — one sitting; items 1–3 before anything else (3 blocks the
   PostgreSQL story; 1 and 2 are one-liners with real impact).
2. **Cold-start rework** (viewcache/route-cache into the image, migrations to an
   init step, advisory lock) — this is the scale-to-zero enabler and should precede
   serious k3s work.
3. **API error-handling unification** in `BaseApiController` + the 401/OPTIONS
   middleware ordering fix.
4. **Frontend shared helpers** (`request()` core with default error toast, entity
   list/form factories) — before plans 05/06/08 mint new copies.
5. **Transactions around stock/chore write paths** — before plan 02 writes.
6. **Response-contract snapshot test** — before plan 02 ships, so the "additive API"
   rule is enforced by a failing test instead of vigilance.

Items 3–6 are each a focused session; none block the roadmap plans, but 4–6
specifically de-risk them.
