# 17. Ecosystem clients

**Goal:** Know, before each plan lands, which third-party clients it breaks — and hold a
standing decision for each about whether this fork forks it, tracks it, or lets it go.
**Depends on:** [14](14-contract-and-regression-scaffolding.md) supplies the mechanism.
[11](11-api-error-handling.md) and [16](16-project-rename.md) are the two plans that
break clients hardest, and both are early.
**Status:** draft for review.

## Why this is a plan and not a wiki page

Every other plan is held to "the API is additive — existing endpoints keep their response
shape". That rule protects *this fork's own web frontend* and nothing else, because the
frontend is the only client in the repository. Four clients live outside it, and two of
the roadmap's plans break them without breaking a single response shape:

- [11](11-api-error-handling.md) changes status codes on ~74 routes and drops
  `error_details` from nine list operations. Response *shapes* survive; error *contracts*
  do not.
- [16](16-project-rename.md) renames the project. `/system/info` reports
  `grocy_version.Version`, and three of the four clients gate on that string before they
  will talk to a server at all.

So the additive rule is necessary and not sufficient. This plan names the clients, records
what each actually consumes, and sets the posture for each one.

## Scope

In: **Grocy-SwiftUI**, **custom-components/grocy** (Home Assistant), **grocy-py**,
**Barcode Buddy**.

Out, and recorded so the boundary is deliberate rather than an oversight:

- **[Grocy Android](https://github.com/patzly/grocy-android)** (GPL-3.0, v3.8.3 Jan 2026,
  the largest client by installs). Excluded on capability, not merit: this is an Apple
  household with no Android development experience and no Android hardware beyond some
  rooted Facebook Portals. A fork nobody can build is worse than no fork. It stays in the
  compatibility matrix as a client the fork will break and will not fix.
- **[grocy-desktop](https://github.com/grocy/grocy-desktop)** (MIT, v2.15.0 Mar 2026), a
  Windows wrapper bundling PHP and nginx around the server. The deployment target here is
  scale-to-zero pods on k3s; the wrapper solves a problem this fork does not have.
- **pygrocy** and **pygrocy2**, both archived. They appear below only as the thing Home
  Assistant has to be moved *off*.

## The ecosystem as it actually stands

Two of the four are healthy, two are not, and the unhealthy ones are unhealthy for
reasons that have nothing to do with this fork.

### The Python client lineage

This matters because it is the one place the ecosystem has already reorganised itself:

```
pygrocy      SebRut       MIT   archived 2024-04-23  ("de-facto unmaintained")
  └─ pygrocy2   iamkarlson   MIT   archived 2026-02-09  ("Migrated to grocy-py")
       └─ grocy-py   iamkarlson   MIT   v1.1.0, 2026-08-28   ← current
```

Same maintainer across the last two links; the rename was to escape a PyPI name that
blocked semantic versioning. **Home Assistant's integration still pins
`pygrocy2==2.4.1`** — a library its own author archived seven months ago, in a component
whose last commit is 2025-07-23. That integration is thirteen months stale and standing on
a dead dependency *today*, on stock upstream grocy, before this fork touches anything.

### Status table

| Client | Language | License | Last commit | Health | Posture |
|---|---|---|---|---|---|
| [grocy-py](https://github.com/iamkarlson/grocy-py) | Python ≥3.12 | MIT | 2026-08-28 | active, semver from 1.0.0, uv-managed, changelogged | **upstream first, do not fork** |
| [Grocy-SwiftUI](https://github.com/supergeorg/Grocy-SwiftUI) | Swift | **GPL-3.0** | 2026-08-29 | active, single maintainer | **fork** |
| [custom-components/grocy](https://github.com/custom-components/grocy) | Python | Apache-2.0 | 2025-07-23 | stale, dead dependency | **fork** |
| [Barcode Buddy](https://github.com/Forceu/barcodebuddy) | PHP | **AGPL-3.0+** | 2025-01-10 | stale | **do not fork — absorb the behaviour** |

## What each client actually consumes

Measured against this fork's `grocy.openapi.json` (73 paths), not against upstream's docs.

| Client | Endpoints used | Present in this fork |
|---|---|---|
| Grocy-SwiftUI | 48 | 47 |
| grocy-py | ~70 across 15 managers | all (param names differ only) |
| custom-components/grocy | via grocy-py's ancestor; adds `/api/files/{type}/{name}` for pictures | all |
| Barcode Buddy | 8 | 8 |

Coverage is not the risk. Every one of these clients works against the fork today. The
risk is in three couplings that a path list does not show.

### Coupling 1 — `/system/info` is the most load-bearing field in the ecosystem

Three of the four clients refuse or degrade based on `grocy_version.Version`, and each
reads it differently:

| Client | Gate | Behaviour on a value it does not like |
|---|---|---|
| Grocy-SwiftUI | exact match against `["4.4.0", "4.5.0", "4.6.0"]` | warns, continues — "there can be problems" |
| Barcode Buddy | numeric floor, `MIN_GROCY_VERSION = "4.0.3"`, split on `.` and compared field by field | **hard refusal**, "please upgrade your Grocy instance" |
| grocy-py | parses into a `SystemInfo` model, no gate | fine |

`version.json` currently reads `4.6.0`, which is why everything works: the fork sits
exactly on the last value in Grocy-SwiftUI's list. That is luck, not design, and it is
already fragile — Grocy-SwiftUI's most recent commit is a fix for upstream 4.7.0, which
this fork is not.

[16](16-project-rename.md) is the plan that touches this, and it is scheduled *before
first deployment*. Its question 6 already asks whether the rename coincides with a version
change. The ecosystem answer is that any non-`4.x.y` version string — a reset to `1.0.0`,
a suffix like `4.6.0-victual`, anything Barcode Buddy's `explode(".")` reads as smaller
than `4.0.3` — hard-blocks Barcode Buddy and un-supports Grocy-SwiftUI on the same day.
See Q1.

### Coupling 2 — the error contract, which no client reads as a contract

[11](11-api-error-handling.md) is deliberate about status codes and carries its own
breaking-changes list. Two consequences reach clients:

- **Status codes move** — permission denied 400→403, malformed filter 500→400,
  `PUT`/`DELETE` on a missing object 400→404. Clients that branch on a status code branch
  differently afterwards.
- **`error_message` text.** Barcode Buddy suppresses one specific failure by
  regex-matching the message body: `/No product with barcode .+ found/`. A reworded
  message does not raise an error there — it stops *suppressing* an expected one, and the
  user sees noise for the normal case of scanning something unknown. This is the failure
  mode this plan exists to catch: no shape changed, no status code changed, and a client
  broke.

[14](14-contract-and-regression-scaffolding.md) piece 2 snapshots response contracts. That
snapshot is the right artefact to diff clients against, and this plan's ask of 14 is that
the snapshot include the `error_message` string, not only the schema. See Q2.

### Coupling 3 — hierarchies presented to clients that assume flatness

[07](07-nested-products.md) and [08](08-nested-locations.md) add depth to
`objects/product_groups` and `objects/locations`. Both are additive on the wire — a new
nullable parent column — and both change what a flat list *means*:

- Grocy-SwiftUI fetches `objects/product_groups` and `objects/locations` and renders them
  as flat pickers. After 07/08 it shows every node in the tree as a sibling, with no path
  and no indication that "Cheese" under "Dairy" is not a peer of "Dairy".
- Home Assistant's integration exposes products and does not model groups; low exposure.
- Barcode Buddy reads `objects/products` and `objects/product_barcodes`; low exposure.

Nothing errors. The iOS app just quietly becomes wrong to look at, which is the outcome an
additive-API rule is least good at preventing. This is the first thing the SwiftUI fork
has to fix, and it is not a small change — pickers become hierarchical navigation.

The same shape recurs for [06](06-location-barcodes.md)'s `l` Grocycode type: every client
with a scanner resolves `grcy:p:42` and will not recognise `grcy:l:{uuid}`. Grocy-SwiftUI
and Barcode Buddy both have scanners.

### Lower-exposure plans

| Plan | Client exposure |
|---|---|
| [01](01-file-storage.md) files in the database | Plan 01 states "No change. Same three routes, same headers, same 404 behaviour", which is the right commitment: Home Assistant builds picture URLs by hand from `/api/files/{picture_type}/{filename}` with base64 filenames, and Grocy-SwiftUI uses `/files/{group}/{fileName}`. Both are URL constructors, so the *route* is the contract, not the storage behind it. The one client-visible risk is the one plan 01 already lists — `mime_content_type($path)` and `finfo_buffer($bytes)` disagreeing and shifting `Content-Type` on an existing endpoint. An image client renders that difference; a schema snapshot does not see it. |
| [03](03-category-min-stock.md) | Home Assistant's "missing products" sensor reads `stock/volatile`. The plan keeps group shortfalls out of `stock_missing_products`, so the sensor is unchanged — which is also why the feature is invisible to it. |
| [05](05-store-shopping-lists.md) | Additive fields on `objects/shopping_list`; all four ignore unknown fields. Note Grocy-SwiftUI reads `objects/shopping_locations` by that name, so 15-Q5's declined rename stays declined. |
| [02](02-mcp-endpoint.md), [10](10-cold-start-statelessness.md), [12](12-frontend-shared-core.md), [13](13-write-path-transactions.md) | None. |

## Posture per client

### grocy-py — upstream first, do not fork

The strongest argument for forking everything is uniform control. It does not apply here.
This library is actively maintained by a responsive author, committed to semantic
versioning since 1.0.0, MIT, `>=3.12`, pydantic v2, and uv-managed — which is the same
constraint every Python tool built for these systems is held to anyway. Its 1.1.0 release
exists because Home Assistant's `cv.date` validator produces a `datetime.date`; the
maintainer is already tracking downstream integration needs.

Forking it buys nothing this fork needs and costs the upstream release cadence. The
fork-only surface — nested product groups, location Grocycodes, substitution relations,
`need_fulfilled_with_substitutions` — is reachable through the generic
`objects/{entity_type}` manager without any library change at all.

Posture: contribute upstream. Fork only if a needed change is refused, or if the fork's
API diverges far enough that the library's models stop describing it. Track the PyPI
release feed; the changelog is good enough to read rather than diff.

### custom-components/grocy — fork, and the reason is not this fork

This one needs work regardless of anything Victual does: thirteen months stale, pinned to
an archived library, no release since its dependency died. Apache-2.0, so forking is
clean.

The first commit of the fork is the dependency swap from `pygrocy2==2.4.1` to `grocy-py`,
which is the migration the upstream integration has not done. Everything fork-specific
comes after that and is small — this integration reads a narrow surface (stock, volatile
stock, chores, tasks, batteries, meal plan, shopping list) and none of the roadmap's
feature plans reshape it.

Posture: fork now. Ship as a HACS custom repository. This is the lowest-risk, highest-value
fork of the four and it is not blocked by any plan.

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

Neither is caught by anything, which tells us what the test coverage is worth. Fixing both
is the fork's first commit; the version gate is the second.

Posture: fork. Order of work — version gate, then the two latent 404s, then hierarchical
pickers when [07](07-nested-products.md)/[08](08-nested-locations.md) land. Track
upstream and rebase; a single-maintainer app is easier to follow than to diverge from.
See Q3 on distribution, which is the real cost here and is not a code problem.

### Barcode Buddy — do not fork; absorb the behaviour

**AGPL-3.0+.** That is a firewall, not a footnote. This fork's own additions are
BSD-3-Clause; taking Barcode Buddy source into the tree would relicense the fork's
additions to AGPL-3.0 and put a network-copyleft obligation on a self-hosted deployment.
Nothing here is worth that.

Forking it as a separate service is legally clean but strategically wrong: it is a
PHP application requiring Redis, sockets and a websocket server, nineteen months without a
commit, and it exists to solve the two problems [06](06-location-barcodes.md) and
[09](09-barcode-lookup-sources.md) are already scoped to solve — barcode ingest and
looking up unknown barcodes against external sources. Two implementations of the same
feature, one of them in a language runtime the deployment does not otherwise need.

Posture: **do not fork, do not vendor, do not read the source for implementation ideas.**
Treat it as a design reference at the behavioural level only — what it does, from its
documentation and its observable API use — and build the equivalent inside 06 and 09.
Keep it in the compatibility matrix for as long as it is actually running here, because
plan 11's message rewording and plan 16's version string both break it. See Q4.

## How this plan stays current

A document that lists what breaks is worthless the day someone changes an endpoint without
opening it. The mechanism, not the list, is the deliverable:

1. **The client surface becomes a fixture, not prose.** Each tracked client's endpoint and
   field usage is extracted into a checked-in manifest and asserted against
   [14](14-contract-and-regression-scaffolding.md)'s snapshot. A plan that changes a route,
   a status code or an `error_message` on a path some client consumes fails CI with the
   client named. That is the whole point — the failure has to arrive before the merge, not
   from a household member saying the app stopped working.
2. **Each plan carries a client-impact line.** One row, even when it reads "none". Absent
   is not the same as none.
3. **This table is reviewed at [16](16-project-rename.md)** — the rename is the single
   largest client-facing event on the roadmap and the one with the least code in it.
4. **Fork repositories live under the same owner**, each with an `UPSTREAM.md` recording
   the upstream commit last rebased onto and the divergence held. No fork is created before
   there is a change to put in it.

## Open questions

1. **What version string does the rename ship?** The ecosystem cost is asymmetric and
   ought to decide this rather than aesthetics. Staying on the `4.x.y` line — continuing
   upstream's numbering as `4.7.0`, `4.8.0` — keeps Barcode Buddy working and keeps
   Grocy-SwiftUI in "warns but works" territory rather than off. Resetting to `1.0.0`
   hard-blocks Barcode Buddy immediately and is honest about the divergence. I lean to
   keeping `4.x.y` through the rename and treating a version reset as a separate, later,
   deliberately breaking event once the forked clients are the only clients. The case
   against: it advertises a compatibility with upstream 4.x that the fork will stop having
   around plan 07, and a client that thinks it is talking to grocy 4.8 will fail in more
   confusing ways than one that is cleanly refused.

   > **Response:**

2. **Should [14](14-contract-and-regression-scaffolding.md)'s snapshot cover
   `error_message` text?** It is the coupling most likely to break silently and the one
   the current plan does not cover. The argument against is that freezing error strings
   makes them un-improvable and locks in upstream's wording — including wording plan 11 is
   explicitly rewriting. A middle position is to snapshot only the messages a tracked
   client is known to match on, which today is exactly one regex in Barcode Buddy, and
   which shrinks to zero if Q4 resolves to drop it.

   > **Response:**

3. **How does a forked Grocy-SwiftUI get onto devices?** This is the real cost of that
   fork and none of it is code. TestFlight needs a paid Apple developer account and a
   build pipeline; sideloading through AltStore or a personal signing certificate means
   a seven-day resign cycle per device. A third option is not forking the app at all and
   letting [02](02-mcp-endpoint.md) carry the mobile use case — an assistant that answers
   "what is expiring this week" covers a real share of what the app is opened for, without
   an app. I lean to forking and sideloading initially, deferring the developer account
   until the fork does something the stock app cannot.

   > **Response:**

4. **Is Barcode Buddy actually running here?** The whole posture above assumes it is in
   use. If it is not deployed, it leaves the compatibility matrix entirely and Q2 collapses
   with it, and 06/09 lose a design reference but no obligation.

   > **Response:**

5. **Does the Home Assistant fork get offered back?** The dependency migration from
   `pygrocy2` to `grocy-py` is work upstream needs and has not done, is not fork-specific,
   and would be a clean PR. Offering it costs a review cycle and some patience with a
   repository that has been quiet for thirteen months; keeping it means maintaining a
   permanent fork of something that did not have to be forked. I lean to offering the
   migration upstream and keeping the fork for anything genuinely Victual-specific.

   > **Response:**

## Verification

Verification here is a booted instance and a real client, per the standard the rest of the
roadmap is held to. Lint is not verification, and neither is reading a client's source.

1. **Baseline, before anything changes.** Point each tracked client at a booted fork
   instance on both engines and record what works: Grocy-SwiftUI logging in and reaching
   stock, Home Assistant's config flow completing and its sensors populating, grocy-py's
   test suite against the instance, Barcode Buddy's version check passing. This is the
   before-picture that every later claim of "still works" is measured against.
2. **The manifest matches reality.** The extracted endpoint manifests are diffed against
   the fork's `grocy.openapi.json` and every mismatch is explained — the two found while
   writing this plan are client-side bugs, and any future mismatch must be classified as
   client bug or fork divergence, not left in the diff.
3. **[11](11-api-error-handling.md)'s breaking-changes list is replayed against clients.**
   For each moved status code, exercise the path from at least one client that reaches it
   and record the client-visible behaviour before and after. The permission-denied 400→403
   change on `POST /chores/{id}/execute` is reachable from both Grocy-SwiftUI and Barcode
   Buddy and is the case to check first.
4. **[16](16-project-rename.md)'s version string is tested before it is chosen.** Set the
   candidate value in `version.json` on a disposable instance and confirm what each gating
   client does with it. Barcode Buddy's refusal is a string comparison and can be predicted
   on paper; predict it, then run it, and if the prediction was wrong the gate is not
   understood well enough to change the value.
5. **After [07](07-nested-products.md)/[08](08-nested-locations.md), look at the pickers.**
   Screenshot Grocy-SwiftUI's product-group and location pickers against a seeded tree
   three levels deep. The failure mode is visual and correct-looking, so it has to be
   looked at rather than asserted.
