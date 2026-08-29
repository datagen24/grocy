# 17. Ecosystem clients

**Goal:** Know, before each plan lands, which third-party clients it breaks — and hold a
standing decision for each about whether this fork forks it, replaces it, or lets it go.
**Depends on:** [14](14-contract-and-regression-scaffolding.md) supplies the mechanism.
[11](11-api-error-handling.md) and [16](16-project-rename.md) are the two plans that break
clients hardest, and both are early. [10](10-cold-start-statelessness.md) has a conflict
with the Home Assistant integration that is not an API-compatibility problem at all.
**Status:** draft for review — **and already overtaken on [16](16-project-rename.md)**,
which landed on 2026-08-29, the day this was written, ahead of the roadmap's own
"17 before 11, 16 and 10" rule. Two of the breaks below are therefore past tense: the API
key header and the `/system/info` version field are renamed in the tree today. Coupling 0
records what that costs and what the options are; the three open questions at the end are
all still unanswered, and Q1 is now being asked after the event rather than before it. The
rule still holds for [11](11-api-error-handling.md) and
[10](10-cold-start-statelessness.md), which are both still ahead.

## Why this is a plan and not a wiki page

Every other plan is held to "the API is additive — existing endpoints keep their response
shape". That rule protects *this fork's own web frontend* and nothing else, because the
frontend is the only client in the repository. Two clients live outside it, and three of
the roadmap's plans reach them without breaking a single response shape:

- [11](11-api-error-handling.md) changes status codes on ~74 routes and drops
  `error_details` from nine list operations. Response *shapes* survive; error *contracts*
  do not.
- [16](16-project-rename.md) renamed the project, and did it *before* this document was
  read. It moved the API key header from `GROCY-API-KEY` to `VICTUAL-API-KEY` and the
  `/system/info` field from `grocy_version` to `victual_version`. Neither changes a
  response *shape* in the sense the additive rule polices; the first stops both clients
  authenticating at all. Coupling 0.
- [10](10-cold-start-statelessness.md) makes the pod scale to zero. The Home Assistant
  integration polls every thirty seconds and would keep it awake forever. Nothing about
  that is visible in an API contract.

So the additive rule is necessary and not sufficient.

## Scope

Two clients are tracked: **Grocy-SwiftUI** (iOS) and **custom-components/grocy** (Home
Assistant).

**grocy-py** is not a tracked client — it is a decision, and only because the Home
Assistant integration needs a Python client. It gets a section below and question 2, and
depending on that answer it may leave this document entirely.

Out, and recorded so the boundary is deliberate rather than an oversight:

- **[Grocy Android](https://github.com/patzly/grocy-android)** (GPL-3.0, v3.8.3 Jan 2026,
  the largest client by installs). Excluded on capability, not merit: this is an Apple
  household with no Android development experience and no Android hardware beyond some
  rooted Facebook Portals. A fork nobody can build is worse than no fork. It stays in the
  compatibility matrix as a client the fork will break and will not fix.
- **[grocy-desktop](https://github.com/grocy/grocy-desktop)** (MIT, v2.15.0 Mar 2026), a
  Windows wrapper bundling PHP and nginx around the server. The deployment target here is
  scale-to-zero pods on k3s; the wrapper solves a problem this fork does not have.

## Where the two tracked clients actually stand

| Client | Language | License | Last commit | Health | Posture |
|---|---|---|---|---|---|
| [Grocy-SwiftUI](https://github.com/supergeorg/Grocy-SwiftUI) | Swift | **GPL-3.0** | 2026-08-29 | active, single maintainer | fork |
| [custom-components/grocy](https://github.com/custom-components/grocy) | Python | Apache-2.0 | 2025-07-23 | stale, dead dependency, poll design incompatible with the deployment | fork, and rework rather than rebase |

Measured against this fork's `victual.openapi.json` (73 paths): Grocy-SwiftUI uses 48
endpoints, 47 of which exist here. The Home Assistant integration uses a narrow slice —
stock, volatile stock, chores, tasks, batteries, meal plan, shopping list — plus
`/api/files/{picture_type}/{filename}` for product and recipe pictures, all present.

Coverage is not the risk, and it never was. What a path list does not show is the
couplings — and as of 2026-08-29 the first of them has stopped being a risk and become a
fact. Neither client authenticates against the fork any more.

## Coupling 0 — the rename already broke both clients

This section is written after the event, which is the thing it is chiefly evidence of.
[16](16-project-rename.md) landed on 2026-08-29 and its "What the survey missed" section
records two renames whose justification is, verbatim, "the justification is Tier 1's,
since no client exists". That premise is true of *deployed instances of this fork* — there
are none, which is what Tier 1 is about — and false of *clients*, of which this document
names two. The roadmap's own sequencing rule ("17 before 11, 16 and 10") exists precisely
to put this document in front of that decision, and it did not happen.

Two things changed, and they are not the same size:

**The API key header, `GROCY-API-KEY` → `VICTUAL-API-KEY`** (`app.php:96`). This is the
transport, not a field. `ApiKeyAuthMiddleware` looks for exactly one header name, resolved
from the `ApiKeyHeaderName` container binding, plus a query parameter of the same name.
Both tracked clients send `GROCY-API-KEY` on every request, because it is the only API
authentication grocy has. Against the current tree every one of those requests is
unauthenticated: not a warning banner, not a degraded feature — Grocy-SwiftUI cannot log
in and the Home Assistant integration's config flow cannot complete. This is the hardest
break either client has ever been handed by this fork, and it was taken in a commit whose
reasoning says no client exists.

The mitigation, if one is wanted, is unusually cheap and that is worth writing down before
the decision is made rather than after: the header name is a single string in a DI binding
and the middleware already reads it from there, so accepting a legacy name alongside the
canonical one is a contained change in one file, not a compatibility layer. The cost is
that it keeps upstream's name alive in the auth path indefinitely, which is the sort of
thing that is easy to add and never removed. See Q4.

**`grocy_version` → `victual_version` in `GET /api/system/info`** (`ApplicationService.php:89`).
16 correctly calls this out as the only response *field* in the whole API surface carrying
the name, and correctly calls it a breaking API change. Coupling 2 below was written about
the version *value*; this is the *key*, and it is the harder of the two, because a client
that gates on a value it cannot find is in a different situation from one that finds an
unfamiliar value. Grocy-SwiftUI reads `grocy_version.Version`; that key is now absent.
Whether that surfaces as a decode failure on `SystemInfo` or as a silently-nil version
depends on how the Swift model declares it, and this plan should not guess — Verification 5
below is the check that answers it, and it is now a check on something that has already
happened rather than a rehearsal for something that has not.

The Home Assistant integration reads `/system/info` through `pygrocy2` for its version
sensor and is subject to the same key rename, though it is moot while the header break
stops it reaching the endpoint at all.

**What this costs, and what it does not.** Nothing is deployed and no household member has
lost anything, which is exactly what 16's Tier 1 reasoning gets right. What was lost is the
*ordering*: the decision about how much upstream compatibility to keep in the auth path was
taken implicitly, by a rename sweep, instead of explicitly here with its cost written down.
That decision is now Q4's, taken after the fact. The three older questions below are still
unanswered.

**What it says about the mechanism.** "How this plan stays current" proposes client
endpoint manifests asserted against 14's snapshot, and notes that a manifest would fail CI
with the client named. A path manifest would not have caught either of these: `/system/info`
is still there and still a `GET`, and the API key header does not appear in a path list at
all. So the manifest is necessary and not sufficient, in the same way the additive rule is.
Whatever piece 2 builds needs to cover the request headers a client sends and the response
*keys* it reads, not only the routes it calls.

The couplings below are the ones still ahead.

## Coupling 1 — the Home Assistant integration defeats scale-to-zero

The largest of the findings still ahead, and the only one that is not about the API at all.
Coupling 0 is worse today, but it is a decision to take rather than a design to change.

`GrocyCoordinator._async_update_data` iterates every enabled entity and awaits one HTTP
round trip per entity, **sequentially**, on a `SCAN_INTERVAL` of thirty seconds. With the
integration's thirteen entity types enabled that is thirteen serialized requests every
thirty seconds, each one a blocking `requests` call hopped onto the executor pool because
the underlying library is synchronous.

[10](10-cold-start-statelessness.md) exists to produce a pod that scales to zero. A client
that touches the API twice a minute, forever, means the pod never idles and never scales
down. The two plans are in direct conflict, and the conflict does not appear in any
response shape, status code or schema — it is a design assumption of the client that the
server is always on and cheap to ask.

Grocy already has the endpoint that fixes this: `/system/db-changed-time`. A correctly
built integration polls that one cheap route and fetches entity data only when the
timestamp moves. That is one request per interval instead of thirteen, and it lets the
interval stretch to minutes without the entities going stale in practice.

This matters to question 2 more than anything else in this document. Rebasing upstream's
integration inherits the poll design; reworking it is where the actual value is.

## Coupling 2 — `/system/info` and the version *value*

This is about the string, not the key; the key rename is Coupling 0 and has already
happened.

Grocy-SwiftUI exact-matches `grocy_version.Version` against `["4.4.0", "4.5.0", "4.6.0"]`.
Outside that set it warns — "the server version is currently unsupported by the app, you
can use it anyways, but there can be problems" — and continues. It is a banner, not a
refusal.

`version.json` still reads `4.6.0`, the last value in that list. [16](16-project-rename.md)
landed without touching it, so the version *string* is unchanged and this gate is exactly
where it was; what moved is the field it is read from. That is luck rather than design, and
it was already fragile before the rename: the iOS app's most recent commit is a fix for
upstream 4.7.0, which this fork is not.

So Q1 — what version string the rename ships — is still genuinely open, and is now asked
about a rename that has already shipped without deciding it. The ecosystem cost of the
answer is unchanged and still small: one warning banner, in an app this fork is taking over
anyway, and one that is moot until the client can read the field again at all. See Q1.

## Coupling 3 — the error contract

[11](11-api-error-handling.md) carries its own breaking-changes list. What reaches clients
is the status codes: permission denied 400→403, malformed filter 500→400, `PUT`/`DELETE`
on a missing object 400→404, and the removal of `error_details` (`stack_trace`, `file`,
`line`) from nine list operations that currently document `Error500`.

Both tracked clients branch on status. Grocy-SwiftUI surfaces failures to a user;
the Home Assistant integration wraps every failure as `UpdateFailed` and marks the whole
coordinator unavailable, so a single 403 on one entity takes out all of them — which is a
defect in that integration worth fixing in the fork regardless of plan 11.

Error *message* text is not a tracked coupling. No client in scope matches on it, so
[14](14-contract-and-regression-scaffolding.md)'s snapshot has no reason to freeze message
strings, and plan 11 stays free to reword them.

## Coupling 4 — hierarchies presented to a client that assumes flatness

[07](07-nested-products.md) and [08](08-nested-locations.md) add depth to
`objects/product_groups` and `objects/locations`. Both are additive on the wire — a new
nullable parent column — and both change what a flat list *means*.

Grocy-SwiftUI fetches both and renders them as flat pickers. After 07/08 it shows every
node in the tree as a sibling, with no path and no indication that "Cheese" under "Dairy"
is not a peer of "Dairy". Nothing errors; the app quietly becomes wrong to look at, which
is the outcome an additive-API rule is least good at preventing. This is the first real
feature work the iOS fork owes, and it is not small — pickers become hierarchical
navigation.

The Home Assistant integration does not model groups or locations and is unaffected.

The same shape recurs for [06](06-location-barcodes.md)'s `l` Grocycode type: the iOS
app's scanner resolves `grcy:p:42` and will not recognise `grcy:l:{uuid}`.

## Lower-exposure plans

| Plan | Client exposure |
|---|---|
| [01](01-file-storage.md) files in the database | Plan 01 states "No change. Same three routes, same headers, same 404 behaviour", which is the right commitment: Home Assistant builds picture URLs by hand from `/api/files/{picture_type}/{filename}` with base64 filenames, and Grocy-SwiftUI uses `/files/{group}/{fileName}`. Both are URL constructors, so the *route* is the contract, not the storage behind it. The one client-visible risk is the one plan 01 already lists — `mime_content_type($path)` and `finfo_buffer($bytes)` disagreeing and shifting `Content-Type` on an existing endpoint. An image client renders that difference; a schema snapshot does not see it. |
| [03](03-category-min-stock.md) | Home Assistant's missing-products sensor reads `stock/volatile`. The plan keeps group shortfalls out of `stock_missing_products`, so the sensor is unchanged — which is also why the feature is invisible to it until the integration is taught about it. |
| [05](05-store-shopping-lists.md) | Additive fields on `objects/shopping_list`; both clients ignore unknown fields. Note Grocy-SwiftUI reads `objects/shopping_locations` by that name, so 15-Q5's declined rename stays declined. |
| [02](02-mcp-endpoint.md), [12](12-frontend-shared-core.md), [13](13-write-path-transactions.md) | None. |

## Posture per client

### custom-components/grocy — fork, and rework rather than rebase

This one needs work regardless of anything this fork does. Its last commit is 2025-07-23.
It pins `pygrocy2==2.4.1`, a library its own author archived on 2026-02-09 in favour of
`grocy-py`. It polls thirteen endpoints serially every thirty seconds. It collapses any
single-entity failure into whole-integration unavailability. Apache-2.0, so forking is
clean.

The temptation is to fork, swap the dead dependency, and stop. That produces a working
integration and inherits every design decision above, including the one that stops
[10](10-cold-start-statelessness.md) from delivering what it is for.

Posture: fork, and treat the upstream code as a reference implementation of the entity
model rather than a base to rebase on. The entity descriptions, config flow and service
definitions are worth keeping; the data layer is not. Ship as a HACS custom repository.
Not blocked by any plan — this can start now.

### grocy-py — a dependency question, not a project to track

The only reason a Python client entered this list is that the Home Assistant integration
needs one. So the question is what that integration talks to, and there are two answers.

**Depend on grocy-py.** It is actively maintained, MIT, committed to semantic versioning
since 1.0.0, `>=3.12`, pydantic v2 and uv-managed — the last of which matches the standard
every Python tool built for these systems is held to anyway. Its 1.1.0 release exists
because Home Assistant's `cv.date` validator produces a `datetime.date`, so the maintainer
is already tracking downstream integration needs. Depending on it means free maintenance
and no client code to own.

**Reimplement inside the integration.** Three arguments, in ascending order of weight:

1. **Surface.** grocy-py covers roughly seventy endpoints across fifteen managers. The
   integration needs seven read paths and a handful of service calls. Reimplementing the
   slice is a few hundred lines, not seventy endpoints — the surface-area argument for
   depending on a library mostly evaporates once the slice is measured.
2. **Async.** grocy-py is synchronous `requests` by design, which is why the current
   integration has fifteen `async_add_executor_job` wrappers. An integration written
   against `aiohttp` and Home Assistant's shared session drops the executor hop entirely
   and can issue its reads concurrently instead of in a for loop. That is not a style
   preference; it is the difference between one round trip and thirteen serial ones per
   interval. No amount of maintaining grocy-py gets there, because making it async would
   be a rewrite of its public surface.
3. **Decay.** The free maintenance grocy-py provides is maintenance against *upstream
   grocy's* API. This fork is diverging from that API on purpose. The value of the free
   maintenance therefore falls at exactly the rate the fork succeeds, while the cost of
   carrying a dependency whose models no longer describe the server stays flat.

The honest case against reimplementation is that it is code owned forever with no
upstream, written by a household of one, and that the first year of a hand-rolled HTTP
client is mostly rediscovering timeouts, retries, connection reuse and error mapping that
a maintained library already got right. That case is real. It is weaker here than usual
because the client is narrow, the server is on the same cluster, and
[11](11-api-error-handling.md) is about to make the error mapping worth writing fresh
against rather than inheriting.

Recommendation: reimplement, async-native, inside the forked integration, and do not track
grocy-py at all. See Q2 — this is the one open question in this plan whose answer changes
what gets built rather than how it is labelled.

### Grocy-SwiftUI — fork, and accept GPL-3.0

GPL-3.0, one maintainer, actively developed. Forking is permitted and the fork stays
GPL-3.0 — which is fine, because it is a separate repository talking HTTP to the server.
There is no license interaction with this fork's MIT/BSD-3 tree.

Two things are already broken in it, independent of any divergence, and both are worth
knowing before deciding how much to trust the codebase:

1. All six `by-barcode` endpoint constants contain U+200B zero-width spaces, evidently
   copy-pasted out of rendered API documentation — `"​/stock​/products​/by-barcode​/{barcode}"`.
   Any request built from them URL-encodes to `%E2%80%8B` and 404s. They are currently
   unreferenced, so this is latent rather than live: it detonates the moment barcode
   scanning is wired through them.
2. `/system/log-missing/localization` does not exist. The route is
   `/system/log-missing-localization`. It 404s against upstream grocy too.

Neither is caught by anything, which tells us what the test coverage is worth.

Posture: fork. **The order of work changed when [16](16-project-rename.md) landed**: the
first commit is now the API key header, because without it the app cannot authenticate and
nothing else in the list is reachable to test. Then the `victual_version` key, then the
version gate's value, then the two latent 404s above, then hierarchical pickers when
[07](07-nested-products.md)/[08](08-nested-locations.md) land. Track upstream and rebase; a
single-maintainer app is easier to follow than to diverge from. See Q3 on distribution,
which is the real cost here and is not a code problem, and Q4 on whether the server meets
the client half way on the header so this fork is not the only way to reach the server.

## How this plan stays current

A document that lists what breaks is worthless the day someone changes an endpoint without
opening it. The mechanism, not the list, is the deliverable:

1. **The client surface becomes a fixture, not prose.** Each tracked client's endpoint and
   field usage is extracted into a checked-in manifest and asserted against
   [14](14-contract-and-regression-scaffolding.md)'s snapshot. A plan that changes a route
   or a status code on a path some client consumes fails CI with the client named. The
   failure has to arrive before the merge, not from a household member saying the app
   stopped working.
2. **Each plan carries a client-impact line.** One row, even when it reads "none". Absent
   is not the same as none.
3. **This document is reviewed at [16](16-project-rename.md)** — the rename is the largest
   client-facing event on the roadmap and the one with the least code in it. *This did not
   happen*: 16 landed first, on the same day this was written. Item 2 above is what would
   have caught it — a client-impact line on 16 could not have been written as "none" — and
   item 1 would not have, since neither break shows up in a path manifest. That is the
   argument for making item 2 a checklist row in the pull request template rather than a
   convention, and for widening item 1 from paths to request headers and response keys.
4. **Fork repositories live under the same owner**, each with an `UPSTREAM.md` recording
   the upstream commit last rebased onto and the divergence held. No fork is created
   before there is a change to put in it.

## Open questions

All four are unanswered. Q1 is now asked after the rename it was written to precede, and
Q4 exists because that rename took a decision this document was supposed to hold.

1. **What version string does the rename ship?** ***Asked after the fact.***
   [16](16-project-rename.md) landed on 2026-08-29 without changing `version.json`, so the
   fork still reports `4.6.0` — from `victual_version` rather than `grocy_version`, but the
   same string. The question is therefore live and unchanged, only later than it should
   be; nothing has foreclosed any of the answers. With the iOS app the only version gate
   left, and a soft one, this is no longer constrained by the ecosystem. I lean to
   resetting the version at the rename rather than continuing upstream's `4.x.y` line: the
   fork stops being 4.x in any meaningful sense around [07](07-nested-products.md), and a
   client that believes it is talking to grocy 4.8 fails in more confusing ways than one
   that knows it is talking to something else. The forked iOS app's supported-versions list
   is updated in the same change, so the warning banner never appears. The case against is
   that it is churn with no functional benefit, and that keeping `4.x.y` costs nothing
   until something actually reads the string for compatibility rather than display.

   > **Response:**

2. **Does the Home Assistant fork depend on grocy-py, or reimplement its client?** The
   argument is worked above; I recommend reimplementing async-native against `aiohttp`,
   polling `/system/db-changed-time` and fetching entity data only when it moves. The
   decisive reason is [10](10-cold-start-statelessness.md): thirteen serial requests every
   thirty seconds is not a client that lets a pod scale to zero, and no dependency choice
   fixes that — but the async rewrite is also the thing that makes depending on a sync
   library pointless, so the two decisions are one decision. If the answer is to depend on
   grocy-py after all, then the `pygrocy2`→`grocy-py` migration is work upstream needs,
   is not fork-specific, and should be offered back as a PR rather than kept private.

   > **Response:**

3. **How does a forked Grocy-SwiftUI get onto devices?** This is the real cost of that
   fork and none of it is code. TestFlight needs a paid Apple developer account and a build
   pipeline; sideloading through AltStore or a personal signing certificate means a
   seven-day resign cycle per device. A third option is not forking the app at all and
   letting [02](02-mcp-endpoint.md) carry the mobile use case — an assistant that answers
   "what is expiring this week" covers a real share of what the app is opened for, without
   an app. I lean to forking and sideloading initially, deferring the developer account
   until the fork does something the stock app cannot.

   > **Response:**

4. **Does the server keep accepting `GROCY-API-KEY`, and for how long?** New, and forced
   by Coupling 0 rather than chosen. Three answers, and the roadmap needs one of them
   written down rather than arrived at by default:

   - **No shim.** The forked clients set the new header and no unmodified client ever
     reaches this server. Cleanest, and consistent with the hard-fork posture — but it
     means the *only* way to talk to this fork from iOS or Home Assistant is through
     software this household also maintains, and it forecloses anyone else's client
     before there is anyone else. It also makes the Q3 distribution problem load-bearing:
     if the forked iOS app cannot be got onto a device, there is no iOS access at all.
   - **Accept both, indefinitely.** One extra string in the `ApiKeyHeaderName` binding and
     one extra lookup in `ApiKeyAuthMiddleware`. Cheap to write, and the sort of
     compatibility shim that is never removed — upstream's name stays in the auth path
     forever, which is precisely the outcome [16](16-project-rename.md)'s Tier 0/Tier 1
     split exists to make deliberate rather than accidental.
   - **Accept both, with an expiry.** Same change plus a deprecation window and a log line
     on the legacy header, removed at a stated point — most plausibly when the forked
     clients ship, since after that nothing this household runs needs it. This is the
     answer I lean to, because it makes the shim's removal a scheduled item rather than a
     someday.

   Whichever is chosen, note that the same question does *not* arise for
   `grocy_version` → `victual_version`: a client reading a missing key needs its own fix
   regardless, and answering `/system/info` with both keys would be adding a field to a
   response purely to keep an old name alive, which the additive rule permits and the
   hard-fork posture argues against.

   > **Response:**

## Verification

Verification here is a booted instance and a real client, per the standard the rest of the
roadmap is held to. Lint is not verification, and neither is reading a client's source.

1. **Baseline, before anything changes** — ***and it has to be taken on a pre-rename
   checkout now.*** Point both tracked clients at a booted fork instance on both engines
   and record what works: Grocy-SwiftUI logging in and reaching stock, Home Assistant's
   config flow completing and its sensors populating. This is the before-picture that
   every later claim of "still works" is measured against, and against the current head it
   is not obtainable — both clients fail at authentication per Coupling 0. Take it at
   `93605da`, the last commit before [16](16-project-rename.md) merged, which is the state
   this document was written about. Losing the ability to take a baseline on `master` is
   the concrete cost of the ordering breach, and it is small only because the checkout is
   still there.
2. **The manifest matches reality.** The extracted endpoint manifests are diffed against
   the fork's `victual.openapi.json` and every mismatch is explained — the two found while
   writing this plan are client-side bugs, and any future mismatch must be classified as
   client bug or fork divergence, not left in the diff.
3. **Request count per poll interval is measured, not assumed.** Instrument the server and
   count requests from the Home Assistant integration over ten minutes, before and after
   the rework. The target is one `/system/db-changed-time` request per interval with no
   entity fetches while nothing changes, and the number that matters is how long the pod
   stays idle — [10](10-cold-start-statelessness.md)'s scale-to-zero is the actual
   acceptance criterion, not the request count itself.
4. **[11](11-api-error-handling.md)'s breaking-changes list is replayed against clients.**
   For each moved status code, exercise the path from a client that reaches it and record
   the client-visible behaviour before and after. The permission-denied 400→403 change on
   `POST /chores/{id}/execute` is reachable from both clients and is the case to check
   first, along with confirming that one failing entity no longer marks the whole Home
   Assistant coordinator unavailable.
5. **[16](16-project-rename.md)'s renames are tested against a stock client — the version
   *key* now, the version *string* before it is chosen.** Two checks, in that order, both
   with the stock app rather than the forked one, because the stock app is the honest test
   of what an unmodified client sees:

   a. *After the fact, and overdue.* On the current head, with the legacy
      `GROCY-API-KEY` header temporarily accepted so the app can get far enough to ask,
      confirm what Grocy-SwiftUI does when `/system/info` answers `victual_version` and
      not `grocy_version` — a decode failure on `SystemInfo`, or a nil version and a
      warning banner. Coupling 0 declines to guess, and this is the check that stops it
      being a guess. Repeat for the Home Assistant integration's version sensor.

   b. *Before it is chosen, as originally written.* Set the candidate version value in
      `version.json` on a disposable instance and confirm what the client does with it.
      This one is still genuinely ahead of the decision: Q1 is unanswered and
      `version.json` still reads `4.6.0`.

6. **Q4's answer is tested as a pair.** Whichever shim answer Q4 lands on, exercise a stock
   client against a booted instance with the legacy header and with the new one, and
   confirm both the accepted and the rejected case behave as the answer says — including,
   if the answer has an expiry, that the deprecation log line fires. A shim nobody has
   watched reject a request is a shim nobody knows the shape of.
7. **After [07](07-nested-products.md)/[08](08-nested-locations.md), look at the pickers.**
   Screenshot Grocy-SwiftUI's product-group and location pickers against a seeded tree
   three levels deep. The failure mode is visual and correct-looking, so it has to be
   looked at rather than asserted.
