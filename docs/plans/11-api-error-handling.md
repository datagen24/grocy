# 11. API error handling, auth surface and error logging

**Goal:** Make the whole API behave like its best controller — real status codes, one
error shape, permission failures that say 403 — and close the API-key and middleware
gaps found alongside them.
**Depends on:** nothing hard, but land
[14 contract and regression scaffolding](14-contract-and-regression-scaffolding.md)
first if both are being done, so the status-code changes here show up as a diff rather
than as an assertion.
**Status:** draft for review. Contains the only deliberate response-shape changes in the
hardening set.

## Today

The route table and `grocy.openapi.json` agree on ~74 routes with a single mismatch, the
`ExposedEntity` allow-lists are read from the spec at runtime so entity drift is
impossible by construction, and every controller returns the same
`{ "error_message": … }` body. The structure is sound. What is not uniform is *which
status code* that body arrives with, and it is not uniform in four separate ways.

**Permission checks land inside or outside a `try` at random.** `User::CheckPermission`
throws a Slim `HttpForbiddenException`. Where the call sits above the `try`
(`GenericEntityApiController::AddObject`, `UsersApiController::CreateUser`) the exception
escapes to `ExceptionController` and the client gets 403. Where it sits inside
(`UsersApiController::AddPermission` before its second catch was added, and others) the
generic `catch (\Exception)` swallows it and returns 400 with the forbidden message in
the body. Same failure, two status codes, decided by indentation.

**`UsersApiController::AddPermission` is the only controller that gets it right**, via a
`catch (\Slim\Exception\HttpSpecializedException)` ahead of the generic catch that
re-emits `$ex->getCode()`. That is the pattern; it exists in exactly one method.

**Query-parse failures are 500s.** `BaseApiController::QueryData` throws a bare
`\Exception('Invalid sort order …')` and `FilterData` throws `\Exception('Invalid query')`.
Neither is caught by the list endpoints, so a malformed `?order=` or `?query[]=` — client
error, entirely — reaches `ExceptionController` as an unclassified throwable and is
answered 500.

**Missing objects are 404 or 400 depending on the verb.** `GetObject` throws a Slim
`HttpNotFoundException`; `EditObject` and `DeleteObject` return
`GenericErrorResponse($response, 'Object not found', 400)`.

Alongside those, five smaller things on the same surface:

- **`ChoresApiController::CalculateNextExecutionAssignments` has no permission check at
  all.** Any authenticated key can recompute every chore's assignment.
- **Generic CRUD is mass-assignable.** `AddObject`/`EditObject` pass the purified request
  body straight to `createRow()`/`update()`, so `id` and the `row_created_timestamp`
  columns are writable by any client with edit permission.
- **`ExposedEntityEditRequiresAdmin` is an empty enum.** `IsEntityWithEditRequiresAdmin`
  is called in three places and can never return true. It is either a missing policy or
  dead code, and right now nobody can tell which.
- **The 401 path bypasses the JSON and CORS middleware.** Auth is added app-level
  (`app.php:110`), `JsonMiddleware`/`CorsMiddleware` are added per route group
  (`routes.php:268`), so a 401 from `BaseAuthMiddleware` is bodyless, has no
  `Content-Type` and no CORS headers. `OPTIONS` preflights hit auth first and are 401'd
  before `CorsMiddleware` ever runs, which means browser cross-origin API-key use cannot
  work at all despite CORS being implemented.
- **`CorsMiddleware` is unconditionally `Access-Control-Allow-Origin: *`.** Not
  configurable, not disableable.

**API keys.** `ApiKeyAuthMiddleware` accepts the key in the `GROCY-API-KEY` header, in a
query parameter of the same name ("not recommended", per its own comment — and it lands
in every access log and every `Referer`), and, on the iCal route only, in `?secret=`.
Keys are stored and compared in plaintext. `last_used` is `UPDATE`d on every
authenticated request.

**Errors are not logged anywhere.** Defect 9 gated `displayErrorDetails` on dev mode,
which stopped serving stack traces to clients. The second and third arguments of
`addErrorMiddleware` — `logErrors`, `logErrorDetails` — are still `false`, and no logger
is wired. In production a 500 currently leaves no trace at all. That was deliberately
deferred out of the defects pass; this is where it lands.

## Proposed change

### One error helper in `BaseApiController`

```php
protected function HandleApiCall(Response $response, callable $work): Response
```

Controllers wrap their body in it instead of writing their own `try`. Inside, in order:

| Caught | Answer |
|---|---|
| `HttpSpecializedException` (403, 404, 405, …) | `$ex->getCode()`, message from the exception |
| a new `EInvalidApiQuery` from `QueryData`/`FilterData` | 400 |
| a new `EObjectNotFound` from the CRUD paths | 404 |
| `\Exception` | 400, exactly as today |

The two new exception types are the only way to distinguish "the client asked for
something impossible" from "something broke", which is the distinction the current single
`\Exception` catch cannot make. Both extend `\Exception`, so any controller not yet
converted keeps behaving as it does today — the migration is per method, not big-bang.

**Permission checks move above the wrapper** everywhere, which is the mechanical half of
the fix and is what makes 403 mean 403. `CalculateNextExecutionAssignments` gains the
check it is missing (`PERMISSION_CHORES` — see Q2, the choice is not obvious).

The three named deviants from the review get the same treatment:
`RecipesApiController::AddNotFulfilledProductsToShoppingList` (no `try` at all, so every
failure is a 500) and `FilesApiController` (throws 404 for what are input errors) end up
on the shared helper like everything else.

### Middleware ordering

Move `JsonMiddleware` and `CorsMiddleware` from the route group to app level, added
*after* the auth middleware so they wrap it (Slim's `add()` is LIFO — outermost last).
Then a 401 carries `Content-Type: application/json`, the standard
`{ "error_message": "Unauthorized" }` body and CORS headers, and `OPTIONS` is
short-circuited by `CorsMiddleware` before authentication is attempted.

`CorsMiddleware` becomes config-driven: `GROCY_CORS_ALLOWED_ORIGINS`, empty by default,
meaning no CORS headers at all. Given the deployment target — a household instance behind
an ingress, with no browser-based third-party client — default-off is the honest default,
and `*` on an authenticated API was never a good one. Q3 covers whether the default
should instead preserve today's behaviour.

### API-key hygiene

- **Drop the query-parameter form** of `GROCY-API-KEY`. The iCal `?secret=` path is
  separate, is scoped to `API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL`, is the reason that
  affordance exists at all, and stays.
- **Hash stored keys** (Q4). This is doable without invalidating anything: a migration
  hashes each existing plaintext key in place, clients keep sending the same string, and
  lookup becomes hash-then-compare. What is lost is the manage-API-keys screen's ability
  to display an existing key — it can only show a key once, at creation.
- **Stop writing `last_used` on every request** — round to the day, or drop the column's
  update to "only when it changes date". A read-only GET currently issues a write, which
  is both a needless write and a needless invalidation.

### Mass assignment

Strip `id` and `row_created_timestamp` from the request body in `AddObject`/`EditObject`
before `createRow()`/`update()`. A blocklist of two columns is the small version; deriving
an allowlist per entity from the OpenAPI schemas is the thorough version and is Q5.

`ExposedEntityEditRequiresAdmin` gets populated or deleted — Q6. It cannot stay an empty
enum with three live call sites.

### Error logging

Wire a PSR-3 logger writing to `php://stderr` (the correct sink for a container; the
platform collects it) and set `addErrorMiddleware(GROCY_MODE === 'dev', true, true)`.
Log line carries method, path, status, exception class, message, file and line. It must
not carry the request body — bodies here contain product notes, user names and, on the
user endpoints, passwords.

### Schema

One migration, only if Q4 says yes to hashing: `api_keys.api_key` changes meaning from
plaintext to hash. Portable single file — it is an `UPDATE` over a text column with a
PHP-side hash, so it is a `NNNN.php` migration rather than SQL, and PHP migrations are
already engine-agnostic (they run through `ExecutePhpMigrationWhenNeeded` on both). No
DDL, so no per-engine pair. Note that it is irreversible by construction, which is the
point, and that it must run under the lock from [10](10-cold-start-statelessness.md) like
everything else.

### API

**This is the one plan in the hardening set that deliberately changes existing
responses.** The additive-API ground rule is about response *shape*; this changes status
codes, and that needs to be explicit rather than slipped in:

| Case | Today | After |
|---|---|---|
| Permission denied, check inside a `try` | 400 | 403 |
| Malformed `?query[]=` / `?order=` | 500 | 400 |
| `PUT`/`DELETE` on a missing object | 400 | 404 |
| Unauthenticated API call | bodyless 401 | 401 with JSON body |
| `OPTIONS` on an API route | 401 | 204 with CORS headers |
| Cross-origin `GET` | `Allow-Origin: *` | no CORS header unless configured |
| API key in a query parameter | accepted | 401 |

Response *bodies* are unchanged in shape everywhere: still `{ "error_message": … }`.
Success responses are untouched.

The Home Assistant integration and the iOS app are the two consumers to think about.
Neither should be affected by codes that only appear on failure paths — but "should" is
not evidence, and Q1 exists because of that.

The OpenAPI spec currently documents almost no error responses. Each converted endpoint
gets its real `4xx` responses added, which also makes the spec the place the contract test
in [14](14-contract-and-regression-scaffolding.md) reads from. The one known route/spec
mismatch — `/api/openapi/specification` missing from the spec — is fixed in 14 alongside
the parity check that would have caught it.

## Verification

1. **Status-code matrix, before and after, on a booted instance.** For every API route:
   call it unauthenticated, with a key lacking the permission, with a malformed
   `?order=zzz`, and against a non-existent id. Record method, path, case, status. The
   before-run is the baseline; the after-run must differ only in the rows of the table
   above. Doing this by hand across ~74 routes is the argument for landing
   [14](14-contract-and-regression-scaffolding.md) first and adding this as a case there.
2. **Success responses byte-identical.** Same harness, happy paths only, both engines:
   the diff must be empty. This is the check that the helper refactor did not change
   anything it was not supposed to.
3. **Two-user API-key test, repeated.** The defects pass established this for defect 4;
   re-run it after the key changes, including: key in header works, key in query
   parameter is now rejected, iCal `?secret=` still works, a key created before the
   hashing migration still authenticates afterwards.
4. **Browser preflight.** From a page on a different origin, with
   `GROCY_CORS_ALLOWED_ORIGINS` set to that origin: `OPTIONS` returns 204 with the
   headers, the subsequent `GET` with a key succeeds. With the setting empty: no CORS
   headers on either, which is the intended default.
5. **Permission check on the chores endpoint.** `POST
   /api/chores/executions/calculate-next-assignments` with a key whose user lacks the
   chosen permission must return 403, and the chore assignments in the database must be
   unchanged — checked by comparing `chores.next_execution_assigned_to_user_id` across
   all rows before and after.
6. **Mass assignment.** `POST /api/objects/locations` with `{"name":"x","id":9999}` must
   create a row whose id is the next sequence value, not 9999; and on PostgreSQL the
   identity counter must still be correct for the *next* insert afterwards — this is the
   same class of failure the fixing pass flagged in `DemoDataGeneratorService`.
7. **Logging.** Force a 500 (a temporarily broken query) in production mode and confirm:
   a line on stderr with the exception class and location, and a response body with no
   `error_details`.

## Sequencing

**After [14](14-contract-and-regression-scaffolding.md), before
[02 MCP](02-mcp-endpoint.md).** The dependency on 14 is soft but real: verification
checks 1 and 2 above are exactly what 14 builds, and doing this plan first means doing
that work twice or doing it by hand.

02 is the plan this most directly de-risks. Every MCP tool is a call onto this API (or
onto the same services behind it); an assistant that receives 400 for "you are not
allowed" and 500 for "you typed the filter wrong" cannot recover sensibly from either.
If [review-comments](review-comments.md) 02-Q6 lands on a separate MCP container calling
the REST API, this plan stops being a nicety and becomes the interface contract.

Against the other hardening plans: independent of [10](10-cold-start-statelessness.md)
and [12](12-frontend-shared-core.md). It overlaps [15](15-deliberate-cleanup.md) in the
auth middleware — do the middleware *ordering* change here (it is three lines in
`app.php` and `routes.php`) and leave the authenticator-class extraction to 15, rather
than entangling a small ordering fix with a refactor.

It blocks no feature plan. It should land before any new API surface is written, because
every new endpoint written before it is another one to convert afterwards.

## Open questions

1. **Ship the status-code changes outright, or behind a compatibility flag?** They are
   corrections, every one of them is on a failure path, and a flag means maintaining two
   behaviours forever. I lean to shipping them outright and putting them on the
   deliberate breaking-changes list in [15](15-deliberate-cleanup.md) — but that is a
   real call, and the answer depends on whether the Home Assistant integration
   distinguishes 400 from 403 anywhere. Worth ten minutes reading its error handling
   before deciding.
2. **Which permission for `CalculateNextExecutionAssignments`?** It is a write (it
   assigns chores to users) exposed as a `POST`, so `PERMISSION_CHORES` is the obvious
   reading. But it is also called by the chores overview page as a refresh, so gating it
   on the edit permission may lock out users who can see chores but not manage them. The
   answer depends on who is expected to be able to trigger a recalculation, which is a
   product question.
3. **CORS default: off, or preserve `*`?** Off is right for the stated deployment and
   makes the setting meaningful. Preserving `*` avoids breaking a browser-based client
   that might exist and that I do not know about. I lean off, on the grounds that
   `Allow-Origin: *` on an API-key-authenticated endpoint is not a feature anyone should
   be relying on.
4. **Hash API keys?** Yes on principle; the cost is that the manage-keys screen can only
   ever show a key at creation time, and anyone who wrote a key down nowhere and reads it
   back off that screen loses that. For a household instance that is a mild annoyance
   against a real improvement. The migration is one-way, which is the honest form of the
   change but also means "decide once".
5. **Mass-assignment: blocklist or spec-derived allowlist?** Blocklisting `id` and the
   timestamps is five minutes and covers the known problem. An allowlist derived from the
   OpenAPI schemas is correct-by-construction and would also catch the next column added
   with a meaning nobody wants clients writing — but the spec's entity schemas would have
   to be complete enough to trust, and that has never been tested.
6. **`ExposedEntityEditRequiresAdmin`: populate or delete?** If there is a set of entities
   where a non-admin should be able to read but not edit, name them and populate it. If
   there is not, delete the enum and its three call sites. Leaving an empty gate in place
   is the one option that is definitely wrong.
7. **What is the retention story for the error log?** stderr and let the platform handle
   it is the k3s answer and needs no code. But a household instance with no log
   aggregation gets errors that scroll away. A file with rotation is more work and
   reintroduces a writable path that [10](10-cold-start-statelessness.md) just removed.
   I lean stderr only.

## Effort

Medium, and it splits cleanly into three sessions that can land separately: the shared
helper plus the mechanical per-controller conversion (the bulk, and the boring part); the
middleware ordering, CORS setting and error logging (small, self-contained, could go
first); the API-key and mass-assignment work (small, but Q4 gates it). The verification
is the part that is easy to underestimate — checks 1 and 2 across ~74 routes on two
engines is not something to do by hand more than once.
