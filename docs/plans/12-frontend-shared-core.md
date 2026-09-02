# 12. Frontend shared core

**Goal:** Stop the copy-paste conventions drifting: one request core in `Victual.Api` with
a default error path, factories for the list and form clone families, and the latent bugs
those copies have already accumulated.
**Depends on:** nothing. Must land before [05](05-store-shopping-lists.md),
[06](06-location-barcodes.md) and [08](08-nested-locations.md) add more list/form pairs to
copy from.
**Status:** **steps 1 and 2 landed**, with verification 1's baseline; steps 3 to 6
outstanding — including step 3a, sweep finding **S29**, a High stored-XSS class across
~45 sites assigned here on 2026-08-30, which is why the remaining steps must not slip.
See [Executed](#executed--steps-1-and-2-and-the-baseline) below.

## Today

The wiring is exemplary for a no-framework app. The layout auto-loads
`/viewjs/{view}.js`, every view/viewjs/route name lines up (72 top-level scripts in
`public/viewjs`, 73 top-level Blade views — 96 counting the subdirectories), inline
Blade scripts inject data and nothing else, and every call to Victual's own API goes
through `Victual.Api.*`. The one bypass in the tree is `public/js/victual.js:562`, which uses
`$.ajax` to fire *outbound webhooks* — a different thing to a different host, with its
own `.fail()` handler that already calls `ShowGenericError`. It is not a candidate for
the shared core and does not need converting. That leaves exactly one place to change for
Victual's own traffic, which is what makes this plan cheap.

What is wrong is underneath it.

**`Victual.Api` repeats itself six times.** `Get`, `Post`, `Put`, `Delete`, `UploadFile`
and `DeleteFile` (`public/js/victual.js:18-280`) each contain their own copy of the same
~30-line `onreadystatechange` handler, differing only in method and body. None of the six
sets a `timeout` or an `onerror` handler, so a dropped connection mid-save never resolves
either callback: the form stays disabled and the user is left with a spinner and no
information, forever.

**Silent failure is the default.** 148 error callbacks across 41 viewjs files do nothing
but `console.error(xhr)`, plus 9 more across 5 files in `public/viewjs/components/`,
which the counts below and the conversion order should not forget. A failed delete, a
failed save, a rejected edit — the UI simply does not react.
`Victual.FrontendHelpers.ShowGenericError` already exists (`public/js/victual.js:485`) and
already renders exactly the right thing, a toast with click-through technical details.
The catch is that all 157 handlers are passed *explicitly*, so no default in the request
core can reach them: each one has to be deleted where it stands, which is why this is 41
files of work and not one line.

**Two clone families.** ~14 master-data list scripts and 22 `*form.js` scripts are
byte-identical modulo the entity name — roughly 2,300 lines. The delete-confirm
`bootbox.confirm` block appears in 23 files. `datetimepicker2.js` is a 373-line copy of
the 376-line `datetimepicker.js` whose entire reason to exist is that two pickers cannot
share a page.

**The copies have already drifted**, which is the actual argument for this plan rather
than tidiness:

- sibling lists disagree about the embedded-dialog reload convention — `productgroups.js`
  reloads the whole page on `CloseLastModal` where its siblings listen for `Reload`;
- `userobjectform.js` lost the Enter-to-submit handler its siblings all have;
- `stockjournal.js` and `userpermissions.js` hand-roll
  `toastr.error(JSON.parse(xhr.response).error_message)` instead of using the helper;
- `productgroups.js` and `quantityunitconversionsresolved.js` are flagged as drift
  markers in the review's deviants list.

**`purchase.js` is secretly a shared library.** `views/stockentries.blade.php`,
`views/stockoverview.blade.php` and `views/shoppinglist.blade.php` all `@push` it in
order to get functions defined there — `stockoverview.js` calls `UndoStockTransaction()`
and works only because of that push. Nothing declares this; delete the push and three
pages break at runtime with no static signal.

**Four latent bugs** found by the documentation pass and deliberately left alone:

| Where | What |
|---|---|
| `public/viewjs/userform.js:157` | Sets `Victual.DeleteUserePictureOnSave` (extra "e"); the submit handler at `:83` reads `Victual.DeleteUserPictureOnSave`. Choosing a new picture does not cancel a pending delete-picture flag |
| `public/viewjs/tasks.js` | The user-filter handler builds an anchored regex in an if/else and never uses it; filtering silently falls back to substring search |
| `controllers/Api/StockApiController.php:344` | `ConsumeProduct` checks `array_key_exists('transaction_type', …)` then reads `$requestBody['transactiontype']`. Both spellings must be sent for the override to work, so effectively it never does |
| `public/viewjs/stockoverview.js` | Depends on `purchase.js` being pushed by its Blade view (above) |

The third is server-side, but it is the write path the frontend's consume dialog uses and
it is in the same "one spelling can never match" family, so it belongs with the rest.

## What this is an on-ramp to

Worth stating before the steps, because it costs nothing to aim at and is expensive to
retrofit: **the endpoint of this work is a frontend that is a real client of the API**,
not merely one whose JavaScript has stopped drifting.

That matters now rather than someday, because [17](17-ecosystem-clients.md)'s answers
committed this household to two more first-party clients — a Home Assistant integration
and a Swift module with per-platform UI targets. Three clients against one API is what
the architecture already is; the browser is simply the one that has been allowed to skip
the API and read the database directly.

How directly is measured in [14](14-contract-and-regression-scaffolding.md)'s section 2b:
173 direct `$this->DB->` call sites across the view controllers, and eight pages whose
data has no API path in the shape they render. Most reads *are* reachable — the gap is
narrower than the call-site count suggests — but reachable via several calls plus a
client-side join is a different claim from the README's "the web frontend uses exactly
this API for pretty much everything", which is true of writes and approximately true of
reads.

None of that is this plan's work — 14 owns the surface and the contract. What this plan
owes it is a `request()` core and list/form factories that a page can be built on
*without* server-injected data, so that when a page's read does arrive as an endpoint,
converting it is a change to one call rather than an argument with the template. Steps 1
and 2 do that already. The thing to avoid is the version of this plan that tidies the
JavaScript while deepening its reliance on Blade-injected globals.

There is no tier-separation project on this roadmap and there does not need to be. It is
what falls out once the API stops treating the browser as special.

## This plan now carries a live security finding

[Sweep S29](../security-sweep.md), raised while fixing S1 on the wave 0.5 hotfix branch and
assigned here: `bootbox` renders its message with `.html()` and `toastr` ships
`escapeHtml: false`, so every "are you sure you want to delete X" and every success toast is
an HTML sink — and roughly 45 of them interpolate a name straight from a text column that can
contain markup. Demonstrated rather than theoretical: a product named with an entity-encoded
`<img onerror>` executes on view.

That changes this plan's status. It was drift cleanup with no security content and no plan
blocked on it; it is now the fix for a **High** finding, and the "12 before 05/06/08"
ordering carries a second reason — every list/form pair added before this lands is another
copy of the vulnerable dialog. Step 3a below is the work; it is why this plan should be
scheduled sooner rather than when it is convenient.

## Proposed change

The order matters: **fix the bugs first, then refactor.** A refactor that also changes
behaviour cannot be verified by "the pages still work".

### Step 1 — the four latent bugs, as their own commit

Each is a one-line fix. Doing them first means the subsequent refactor is a pure no-op
and any behavioural difference found afterwards is a refactor bug.

For `transaction_type`/`transactiontype`, accepting both spellings is additive and breaks
nothing; accepting only the documented one is correct but is a (theoretical) API change.
See Q1.

### Step 2 — one private `request()` in `Victual.Api`

```js
function request(method, url, body, success, error, opts) { … }
```

`Get`/`Post`/`Put`/`Delete`/`UploadFile`/`DeleteFile` become thin wrappers. The core
gains what none of the six has:

- a `timeout` (30 s proposed) and an `ontimeout` handler;
- an `onerror` handler, so a dropped connection reaches the error callback;
- `error` defaulting to `Victual.FrontendHelpers.ShowGenericError` when the caller passes
  nothing.

**Be honest about what that default does on the day it lands: nothing.** All 157
`console.error` handlers are passed explicitly, so the default never fires until they are
deleted — and deleting them means editing the same 41 files that step 3 rewrites
wholesale. Doing the deletions here would mean touching every file twice and would put a
behaviour change in the middle of what is meant to be a pure no-op refactor.

So the split is: **step 2's PR is the four bug fixes plus the `request()` core**, and its
real user-visible value is the `timeout`/`onerror` pair — the form that stays disabled
forever on a dropped connection starts re-enabling and reporting, which is a genuine
class of failure and cannot be fixed anywhere else. The default error callback ships in
the same PR as inert scaffolding that the next step switches on.

**The 157 handler deletions ride with step 3**, per file, as each script is converted:
deleting the callback is one more line of the same mechanical edit, the toast starts
working for that page the moment its file is converted, and the surfacing of previously
silent failures arrives gradually rather than all at once. Q2 covers the callers that
legitimately expect failures and must keep an explicit no-op — those are the ones *not*
deleted during the conversion.

The two hand-rolled `toastr.error(JSON.parse(…))` sites collapse into the same default,
on the same schedule.

### Step 3 — `VictualEntityList` and `VictualEntityForm`

```js
Victual.EntityList("locations", { deleteConfirm: __t("…"), afterDelete: … })
Victual.EntityForm("locations", "/api/objects/locations")
```

Each clone script becomes a call plus whatever is genuinely specific to it. The factories
own the DataTable wiring, the delete-confirm dialog, the Enter-to-submit handler, the
save/disable/re-enable cycle and the embedded-dialog reload — so `userobjectform.js` gets
its missing Enter handler back by construction, not by being noticed.

This step also carries the `console.error` deletions described above: converting a file
means removing its explicit error callbacks unless Q2's list says that one is deliberate.
The 5 files under `public/viewjs/components/` are not clone scripts and get no factory,
but their 9 handlers are on the same list and are deleted in a pass of their own.

Convert **one** pair first (`locations` is the smallest and is also what
[06](06-location-barcodes.md) and [08](08-nested-locations.md) will extend), verify it,
then do the rest mechanically.

### Step 3a — the factories escape by default, and the stragglers are swept

Same step, stated separately because it is the security half and has its own acceptance
test.

**Structural, not per-site.** `Victual.EntityList`'s delete confirmation takes the entity
name as *data* and escapes it on the way into the message; no caller can pass markup
through it. That is worth more than 31 correct call sites, because it is the version that
stays correct when someone adds the thirty-second. The same applies to whatever the factory
does with success toasts.

**What the factories do not cover** has to be swept by hand in the same step, because
leaving half a class fixed is how S1's claim went wrong once already:

- the ~20 `toastr.success/info` calls that interpolate a name — these are in page scripts
  (`consume.js`, `purchase.js`, `transfer.js`, `inventory.js`, `stockoverview.js`,
  `stockentries.js`, `choresoverview.js`, `batteriesoverview.js`, `tasks.js`, `recipes.js`,
  `choretracking.js`, `batterytracking.js`, `shoppinglistitemform.js`), not in clone
  scripts, so no factory reaches them;
- `components/productamountpicker.js`, which builds `<option>` markup from
  `quantity_units.name`/`name_plural` by concatenation;
- `manageapikeys.js` and `shoppinglist.js`, whose confirmations read from a `data-`
  attribute or an `<option>`'s text rather than through a factory.

**Two traps worth writing down before someone finds them the hard way:**

- **`toastr.options.escapeHtml = true` is not the one-line fix it appears to be.** Ten
  messages carry deliberate markup — including the Undo button in the consume toast, which
  the flag turns into visible tag text. The toasts need escaping at the interpolation, not
  at the sink.
- **Escaping a value into a `data-` attribute does not keep it escaped.** `.attr()` returns
  the decoded string, so a value escaped when the attribute was written arrives raw at the
  click handler that reads it back. This is exactly how `mealplan.js`'s single existing
  `.escapeHTML()` call was defeated. Escape at the point of use, every time, or hold the
  value as data and never round-trip it through the DOM.

The one file already fixed is `mealplan.js`, done on the hotfix branch because the review
there declined to let a live XSS wait for a plan boundary. Its fix is the pattern for the
rest.

### Step 4 — one embedded-dialog reload convention

The factory handles the form-posts-`Reload` message for lists, and `productgroups.js`'s
full-page reload on `CloseLastModal` — the one list that does that — is converted to it.
`CloseLastModal` itself stays exactly as it is: it is the app's global close-the-dialog
message, not a competing reload convention, and the forms whose parents do targeted
refreshes on it keep posting it. Q3 has the detail and the reason not to unify further.

### Step 5 — `purchase.js` and `datetimepicker2`

Extract the functions three other pages need out of `purchase.js` into a properly loaded
shared file (`public/js/victual_stock_dialogs.js` or a `Victual.Components` entry), so
nothing depends on a `@push` side effect. `purchase.js` keeps only what the purchase page
itself uses.

`datetimepicker2` disappears: parameterise the single component by element id/suffix so
two instances can coexist. This is 373 lines deleted and is the cleanest win in the plan,
but it is also the one most likely to have a subtle reason to exist — see Q4.

### Step 6 — the Blade minor items

List-page chrome repeats across ~14 templates while partials for it already exist and are
used elsewhere; apply the existing idiom. `mealplan` and `recipes` blades inject bare
globals instead of namespacing under `Victual.*`. Two components are not registered under
`Victual.Components`. All small, all mechanical, all safe to do alongside step 3 since the
same files are open.

### Schema

None.

### API

**No API change** — this is browser-side, with one exception. Step 1's
`transaction_type`/`transactiontype` fix touches `StockApiController::ConsumeProduct`.
Accepting both spellings is strictly additive: a body that works today keeps working, and
a body sending only the documented `transaction_type` starts working. Accepting only the
documented spelling would break any client that stumbled into sending both — theoretically
none, since the current behaviour is not documented anywhere and reads as a typo. Q1.

   > **Response:** See the Q1 response below — only the documented spelling.

**Client impact: one, and Q1 chose the answer that has it.** Accepting only the documented
`transaction_type` means a client sending `transactiontype` — the current, undocumented,
typo'd spelling — stops having its value honoured and silently gets the default. No status
code changes and no field moves, so nothing fails loudly. Neither client
[17](17-ecosystem-clients.md) tracks sends either spelling, which is what makes the
stricter answer affordable; it is recorded because "no API change" was this section's
first sentence and it is not quite true.

The default error toast is a *user-visible* behaviour change with no wire-format
component: operations that used to fail silently now say so. That is the point, but it
will surface pre-existing failures nobody knew about. Because the handler deletions
happen per file during step 3 rather than in one switch-flip, those discoveries arrive
page by page — which is the easier version to triage, since at any moment it is clear
which conversion introduced the noise.

## Verification

Everything here is browser behaviour, so verification is a booted instance and a browser
— but not an unstructured click-around.

1. **Baseline the pages before touching anything.** For each of the ~14 list pages and 22
   form pages: load, create, edit, delete, and confirm the row count changes. Record what
   each does today, including the two that behave differently on embedded-dialog reload.
   This list is the acceptance criteria for step 3; without it "the pages still work" is
   an opinion.
2. **The four bug fixes, individually.** User form: set a picture, click delete-picture,
   then choose a new file, save — the new picture must survive. Tasks: filter by a user
   whose name is a substring of another user's and confirm the filter is now anchored.
   Consume: `POST /api/stock/products/{id}/consume` with only `transaction_type` set and
   confirm the resulting `stock_log.transaction_type` row matches. Stock overview: remove
   the `purchase.js` push from `stockoverview.blade.php` and confirm the page still works
   after step 5.
3. **Error surfacing, forced.** Stop the database (or point a request at a route that
   returns 500) and exercise a delete, a save and a list load on three different pages.
   Each must produce the toast with working click-through details. Then break the network
   mid-save (throttle to offline in devtools after clicking save) and confirm the form
   re-enables rather than staying locked — this is the `onerror`/`timeout` gap and it
   cannot be tested any other way.
4. **`grep -rc console.error public/viewjs/` must reach 0** across both the 41
   top-level files and the 5 under `components/`, for the handlers that are pure
   logging, with the survivors being deliberate and documented (Q2). This is step 3's
   exit criterion, not step 2's — after step 2 the count is unchanged by design.
5. **S29 is closed, proved with a payload rather than by reading the diff.** Give one
   record of each affected kind — a product, a location, a chore, a quantity unit, a
   shopping list, an API key — a name of `&lt;img src=x onerror=window.__xss=1&gt;`, which
   the sanitiser stores as a live tag because those are text columns. Then, on every page
   that lists or acts on them: open the delete confirmation, trigger the success toast, and
   confirm `window.__xss` is still undefined and the name renders as visible text. Do it
   once on the unfixed head first, so the check is known to be capable of failing — on
   `mealplan.js` that check reported `window.__xss === 2` before its fix and unset after,
   and anything weaker than that is not evidence.

   The `grep` half is worth having too, and is not sufficient on its own: no `.html(`,
   `.append(`, `bootbox.` or `toastr.` call in `public/viewjs` may interpolate a bare
   `.name`, `.username` or `.description` that is not one of the five HTML columns. A
   reviewer can check that; only the payload check proves it.
6. **Two datetimepickers on one page.** The meal plan and stock entry forms are the
   places two pickers coexist; after step 5 both must set, clear and validate
   independently, including the "clear" and "now" shortcuts.
7. **Both engines, for step 1 only.** The consume fix is server-side and writes
   `stock_log`; run `trigdifftest.php`'s `01_purchase_consume_undo.sql` before and after
   to confirm nothing about the write path changed on either engine. Steps 2–6 are
   engine-independent and do not need this.

## Sequencing

**Before [05](05-store-shopping-lists.md), [06](06-location-barcodes.md) and
[08](08-nested-locations.md)** — this is the ordering the review is emphatic about and it
is the whole argument for the plan's priority. Each of those three adds at least one
list/form pair. Written before this plan they are copies of the old pattern that then
have to be converted; written after, they are two calls to a factory. 06 in particular
adds a print action to the locations list and form, which is exactly the pair proposed
here as the first conversion.

**Independent of everything else in the hardening set.** It touches
`public/js/victual.js`, `public/viewjs/*` and `views/*.blade.php`; only its step-1 consume
fix reaches into `controllers/Api/`, and even that does not collide with
[11](11-api-error-handling.md), which changes error paths rather than request parsing. It
can be done in parallel with 10, 11, 13 or 14.

**It blocks no feature plan outright** but it de-risks 05/06/08 by removing ~1,500 lines
those plans would otherwise have to keep consistent, and it de-risks
[04 seed datasets](04-seed-datasets.md) indirectly: a seeded test instance is much more
useful when a failed import says so instead of logging to a console nobody has open.

**S29 sharpens the ordering rather than changing it.** "12 before 05/06/08" was an argument
about duplication; it is now also an argument about exposure, since each list/form pair
those plans add before this lands is another copy of a vulnerable delete dialog. The
finding does not make 12 block anything it did not block before — it makes the cost of
deferring 12 a security cost rather than a tidiness one, and it means the plan should not
be the one that slips when wave 1 gets busy.

## Open questions

1. **`transaction_type` / `transactiontype`: accept both, or only the documented one?**
   Accepting both is additive and safe. Accepting only `transaction_type` is what the
   OpenAPI spec says and is cleaner, at the theoretical cost of a client that currently
   sends both. I lean to accepting both for one release with the undocumented spelling
   noted as deprecated, then removing it with the other breaking changes in
   [15](15-deliberate-cleanup.md) — but it may not be worth the ceremony for a spelling
   that is plainly a typo.

   > **Response:** Only the documented spelling — skip the deprecation ceremony. The
   > current code requires *both* spellings simultaneously, so no client sending
   > only the undocumented one has ever worked, and a client sending both is a
   > client that read this exact broken source. There is no one to deprecate for;
   > fix to the spec, one changelog line.
2. **Which callers must keep an explicit no-op error handler?** Making
   `ShowGenericError` the default is right for the 148, but some calls legitimately
   expect failure and must not toast: the `db-changed-time` poller
   (`victual_dbchangedhandling.js`), the missing-localization logger, and barcode lookups
   where "not found" is an ordinary outcome. These need `function () { }` passed
   deliberately with a comment saying why. The question is whether that list is those
   three or longer — it needs the grep, not a guess.

   > **Response:** The three named are right; the grep will likely add the test
   > pages (`barcodescannertesting`, `quantityunitpluraltesting`), background
   > statistics refreshers, and the product-card price-history fetch where empty
   > history is normal. Criterion worth writing into the factory docs: *failure is
   > an expected domain outcome or a background poll* → explicit `function() {}`
   > with a comment; anything user-initiated toasts.
   >
   > One case the guess misses and the grep must not: `public/js/victual.js:317` and
   > `:351` post to `system/log-missing-localization` with **no error argument at
   > all** — not a `console.error`, nothing — so the moment a default exists those
   > two start toasting on any failure. Worse, both calls are made from inside
   > `__t()`/`__n()`, and `ShowGenericError` renders a toast whose own text goes
   > through `__t()`; a failing localization log could therefore re-enter
   > localization and, on a dev instance with a broken API, recurse. So
   > `log-missing-localization` goes on an explicit *silent* list — passed
   > `function () { }` deliberately, with the recursion as the stated reason, not
   > merely "failure is expected here". Anything else in the tree that calls
   > `Victual.Api.*` from inside a rendering or translation helper wants the same
   > treatment, and the grep should look for that shape specifically.
3. **What does the factory do about `CloseLastModal`?** The two "conventions" are not
   two ways of doing one thing. `CloseLastModal` is a *global* mechanism: the Escape
   key and any `.close-last-modal-button` post it (`public/js/victual.js:819-830`), and
   the listener at `:841` hides the topmost visible modal. It is the app's close-the-
   dialog message and it cannot be replaced by `Reload`. Layered on top of it, about
   eleven forms post it deliberately after a successful save — `productgroupform`,
   `shoppinglistform`, `shoppinglistitemform`, `recipeposform`, `stockentryform`,
   `productbarcodeform`, `quantityunitconversionform`, and the `consume`, `inventory`,
   `purchase` and `transfer` dialogs — because their parents do a targeted refresh
   when the dialog closes. Exactly one list reloads the whole page on the message:
   `public/viewjs/productgroups.js:76`. The question is what the factory bakes in, and
   which of these it is allowed to convert.

   > **Response:** Leave `CloseLastModal` alone as the close mechanism and convert
   > exactly one pair: `productgroupform` / `productgroups` moves to the
   > form-posts-`Reload` shape that `locationform`, `batteryform`,
   > `taskcategoryform`, `quantityunitform` and the rest already use, and
   > `productgroups.js`'s `window.location.reload()` listener goes away. That is the
   > single genuine drift marker — a list doing a full page reload on a message that
   > also fires on Escape and on cancel.
   >
   > Every other form that posts `CloseLastModal` keeps doing so, because its parent
   > is not doing a page reload: `purchase`, `consume`, `transfer` and
   > `shoppinglistitemform` all rely on parent-side targeted refreshes, and pushing
   > them through `Reload` would replace a redrawn row with a full page load. That is
   > a regression dressed as consistency. The factory therefore handles the `Reload`
   > message for lists and leaves the `CloseLastModal` handlers where a parent
   > deliberately listens for one.
4. **Is there a real reason `datetimepicker2` exists as a copy?** The stated reason is
   "so two pickers can share a page", which parameterisation solves. If there is a second
   reason buried in it — different validation, a different date format, a different
   dependency on page state — a naive merge will break one of the two pages that use it.
   The honest check is a diff of the two files before committing to the merge.

   > **Response:** That diff has effectively been run: the frontend review
   > normalized-diffed the pair and found naming-only differences, and the component
   > documentation pass confirmed it. Proceed with parameterization, merge the Blade
   > component pair too; verification check 5 is the safety net.
5. **How far do the factories go?** A config-object factory that owns the whole page is
   the biggest win and the biggest blast radius; a set of shared mixins the scripts opt
   into is safer and collapses less. I lean to the factory for the pure clones and mixins
   for the ~5 list scripts that are only partly clones (`stockjournal`,
   `userpermissions`, `manageapikeys`, `products`, `recipes`), rather than forcing all 36
   scripts through one shape.

   > **Response:** Agreed — factory for the pure clones, mixins for the partial
   > clones — with two corrections to the list in the question. First, `recipes`
   > comes *off* the mixin list: `mealplan.js` and `recipes.js` are the two most
   > divergent files in the tree and are left entirely alone, so `recipes` cannot
   > also be a mixin adopter. That leaves `stockjournal`, `userpermissions`,
   > `manageapikeys` and `products` as the partial clones.
   >
   > Second, the files the question left unbucketed: `equipment.js` (192 lines),
   > `tasks.js` (278) and `stockjournal.js` are mixin adopters — list-shaped with
   > enough page-specific behaviour that the full factory would fight them.
   >
   > Third, added 2026-08-30: [19](19-rbac.md)'s `/role/{id}` joins the partial-clone
   > bucket by construction — it is `userpermissions.js`'s checkbox tree bound to
   > `role_permissions` — and 19 also edits `userpermissions.js` itself. So that list
   > grows by one rather than the factory list growing by two, and 19's `/roles` list is
   > the only clean factory call it brings.
   >
   > `shoppinglist.js` (708 lines) joins `mealplan.js` and `recipes.js` in the
   > leave-alone bucket; at that size it is its own application, not a list script
   > with extras.
6. **Does this want a build step?** Everything above is plain browser JS with no
   bundling, matching the current architecture. Introducing a bundler would make the
   shared-module story conventional and would also be a new dependency, a new build
   artifact and a departure from a design that is otherwise working. I lean strongly to
   no — but it is worth stating as a decision rather than a default.

   > **Response:** No bundler, stated as a decision: the no-build convention is
   > load-bearing for this fork's maintainability, and a second `<script>` in the
   > layout is the whole cost of sharing code. Revisit only if a real module problem
   > appears, which nothing in this plan creates.

## Effort

Medium, dominated by conversion volume rather than difficulty. Steps 1 and 2 are a single
short session each and deliver the four bug fixes plus network-failure handling — a
dropped connection now re-enables the form and reports, where today it hangs forever.
They do *not* deliver the end of silent failures: that is step 3's, one file at a time,
because the 157 explicit handlers can only be removed by the same edits that convert the
files. Step 3 is the bulk: one pair converted carefully, then ~35 mechanical conversions
with the baseline from verification check 1 as the acceptance list. Steps 4–6 are tidy-up
that can ride along. Worth splitting: 1 and 2 are worth landing on their own even if the
factories wait.

**S29 adds to step 3 rather than to the total.** The factories absorb the ~24 confirmation
dialogs at no extra cost, since those are being rewritten anyway. What it does add is the
~20 toast sites in page scripts that no factory touches, plus `productamountpicker.js` and
two irregular confirmations — each an individual judgement about whether that variable is
display-only or also feeds a URL or an API parameter, so an afternoon of care rather than
a `sed`. The payload verification is its own sitting.

**One consequence for the split:** if steps 1 and 2 land alone and the factories wait,
S29 waits with them. If that gap is going to be long, the ~20 straggler sites are worth
pulling forward on their own — they need no factory and would close most of the exposure
while step 3 is still being scheduled.

## Executed — steps 1 and 2, and the baseline

Landed 2026-09-02 on `worktree-agent-af99b0184155cc937`, against the working copy at
`c998aaf`, in the order the plan argues for: the bugs first, then the request core, then
the record of what the pages did before either. **Steps 3 to 6 are outstanding** — the
factories, the reload convention, the `purchase.js` and `datetimepicker2` extractions and
the Blade tidy-up are all untouched, and so are the 157 `console.error` handlers.

**Step 1 — the four latent bugs** (`98a4c93`).

- `userform.js` set `Victual.DeleteUserePictureOnSave` where the submit handler reads
  `Victual.DeleteUserPictureOnSave`. Choosing a new picture after clicking "delete current
  picture" therefore left the deletion flag standing, which nulled `picture_file_name` and
  skipped the upload — the new picture was silently discarded. One letter.
- `tasks.js` built the anchored, escaped regex in its else-branch and threw it away, so the
  filter fell back to a substring search. Now assigned.
- `StockApiController::ConsumeProduct` checked `transaction_type` and read
  `transactiontype`. Fixed to the documented spelling only, per Q1 — no deprecation window,
  because a client sending only the undocumented spelling has never worked and a client
  sending both is a client that read this exact broken source. The doc comment describing
  the old behaviour went with it. The typo is not a family: `AddProduct` already used the
  documented spelling, and `OpenProduct`, `InventoryProduct` and `TransferProduct` accept no
  transaction type at all. Neither does any caller in the tree send one — the consume dialog
  (`consume.js`), `stockoverview.js`, `stockentries.js` and `mealplan.js` all omit the field
  entirely — so nothing in `public/` or `bin/` needed changing to match.
- The `stockoverview.js` ↔ `purchase.js` `@push` dependency is **recorded, not fixed**, as
  the plan intends: the three Blade views that push `purchase.js` now say why. Reading them
  turned up something step 5 will want: of the three, only `stockoverview.js` references a
  `purchase.js` symbol (`UndoStockTransaction()`). `stockentries.js` defines its own
  `UndoStockBookingEntry` and `shoppinglist.js` loads the purchase form in an iframe; neither
  names anything `purchase.js` defines, so two of the three pushes look removable outright.

**Step 2 — one `request()` in `Victual.Api`** (`05a6d6e`). The six copies collapse into one
private `request(method, url, body, success, error, opts)`. Diffing them first found exactly
two deliberate differences, both preserved: `DeleteFile` takes `(fileName, group)` — the
mirror image of `UploadFile`'s `(file, group, fileName)` — and sends **no** body where
`Delete` sends `"{}"`. The `onreadystatechange` handlers were byte identical.

The core adds a 30 s `timeout` (`Victual.Api.TimeoutMilliseconds`, a setting rather than a
constant so it can be shortened from a console or a probe), `ontimeout`, `onerror` and
`onabort`, and a `settled` guard — a dropped connection fires `readystatechange` *and*
`onerror`, so without the guard one failure would call the error callback twice. A request
that never produces an HTTP response now reaches the error callback with an xhr-like
descriptor carrying `status: 0`, a `statusText` of `timeout`/`error`/`abort`, and a
`response`/`responseText` holding a readable `error_message` — because callers log the whole
object and two of them `JSON.parse(xhr.response).error_message`, and a real `XMLHttpRequest`
renders as `{}` through `JSON.stringify`.

**Divergences from the plan, both in step 2.**

*The default is `Victual.Api.DefaultErrorHandler`, which delegates to `ShowGenericError`
rather than being it.* `ShowGenericError(message, exception)` takes two arguments and an
error callback is called with one, so assigning it directly would put the XMLHttpRequest
where the message text belongs and run it through `__t()`. The adapter is what "defaults to
`ShowGenericError`" has to mean.

*"Be honest about what that default does on the day it lands: nothing" is not quite true,
and the plan's own Q2 response is why it noticed.* Q2 caught the two
`log-missing-localization` posts that pass no error argument at all. A count of every
`Victual.Api.*` call in the tree — 258 of them — finds **22 more** that pass none:

| What | Where | Count |
|---|---|---|
| grocycode label-print handlers | `stockoverview`, `stockjournal`, `stockentries`, `productform`, `recipes`, `recipeform`, `choreform`, `choresoverview`, `batteryform`, `batteriesoverview`, and `stockentryform` after a save | 11 |
| consume/open submit: product re-fetch and the booking POST inside it | `consume.js` | 4 |
| chore execute, its re-fetch, and reschedule | `choresoverview.js` | 4 |
| purchase submit product re-fetch, and the barcode-defaults lookup | `purchase.js` | 2 |
| recipe create | `recipeform.js` | 1 |

Every one of them is user-initiated, which is precisely the case Q2's criterion says should
toast, so they are left to the new default and the arrival of the toast on those paths is
this PR's, not step 3's. The 157 explicit `console.error` handlers are untouched and still
count 157; those remain step 3's, per file.

**Q2's silent list.** The two `system/log-missing-localization` posts in `__t()` and `__n()`
now pass an explicit `function () { }` with the recursion as the stated reason. Grepping the
tree for the *shape* Q2 asks about — a `Victual.Api.*` call made from inside a rendering or
translation helper — finds no others: `victual_dbchangedhandling.js`, the product card's
price-history fetch and the barcode lookups all already pass explicit handlers and are
step 3's to classify, and `Victual.FrontendHelpers.SaveUserSetting` passes one too.

**The baseline** (`cf1179d`), verification check 1, in `.devtools/frontend/`. It walks 13
master data lists through a real create/edit/delete round trip, load-probes the 10 pages that
are not round-tripped and all 22 `*form.js` pages, and records row-count deltas, the reload
convention, the delete style and every console message. It lives outside the repo root
`package.json`, which is a yarn manifest installing into `public/packages`, and pins its own
Playwright. Four things it wrote down that the plan above did not know:

- **`productgroups` is the only list that reloads on dialog *dismiss*.** Saving reloads the
  parent under both conventions, so only pressing Escape distinguishes them. That is Q3's
  drift marker, confirmed from the page rather than the source, and the probe that finds it
  is the acceptance test for step 4.
- **`productgroupform` on its own page never finishes.** It only posts `CloseLastModal`, and
  has no `embedded` branch at all, so `/productgroup/{id}` saves successfully and then sits
  there with every input still disabled by `BeginUiBusy`. Step 4 converts this pair anyway;
  the standalone page is the part to check afterwards.
- **`userobjectform` is the one form page with no Enter-to-submit handler bound**, confirming
  the plan's claim by observation.
- **The `userobjects` list throws on load** — `Cannot read properties of undefined (reading
  'aDataSort')` — for a user entity with no userfields. Pre-existing, unrelated to this plan,
  and the only console error in the whole recorded run.

Two more found while reading, neither fixed here because neither is on step 1's list:
`quantityunitform.js:147`/`:217` and `recipeform.js:148` click `#save-quantityunit-button`
and `#save-recipe-button`, which do not exist — both forms carry *class*-named save buttons
(`.save-quantityunit-button`, `.save-recipe`) — so Enter-to-submit is dead on both, as is the
plural-testing return path. Step 3's factory should fix them by construction.

### Verification

Against a demo-mode SQLite instance and a PostgreSQL 16 instance, both booted from this
working copy on 2026-09-02. Reproduce with `.agents/skills/run-app/SKILL.md` plus the
harness README.

- **Check 1, baseline.** `node .devtools/frontend/baseline.js --url http://127.0.0.1:8200`.
  13 lists round-tripped, 32 further pages probed, no harness errors. Recorded as
  `.devtools/frontend/baseline-2026-09-02.{json,md}`.
- **Check 2, each bug on its own.** User form: with the fix the delete-picture flag clears
  when a new file is chosen and `Victual.DeleteUserePictureOnSave` no longer exists; without
  it the flag stays `true`. Tasks: with two probe tasks assigned to "Demo User" and "Demo
  User 2", filtering by "Demo User" showed 5 rows across both users before the fix and 4 rows
  from "Demo User" alone after it. Consume, on **both engines**: `transaction_type` alone now
  reaches `stock_log.transaction_type`, `transactiontype` alone is ignored, and sending
  neither still books `consume` — confirmed by reading the rows back from SQLite and from
  `psql`. Stock overview loads clean with `UndoStockTransaction` defined, which is the no-op
  check it is meant to be while the push is still there.
- **Check 3, forced failures.** With Playwright intercepting `POST /api/objects/locations`:
  `route.abort('connectionreset')` re-enabled the form and produced the error toast; a route
  that never answers left the form disabled and the cursor busy for the shortened 2 s
  timeout and then re-enabled it and toasted, which is the failure mode that had no exit
  before. A forced 500 on `DELETE /api/objects/locations/{id}` reached the locations list's
  explicit `console.error` handler unchanged and produced **no** toast, which is the default
  staying inert where a handler exists.
- **Check 4 is step 3's, and stays where it was:** `grep -rc console.error public/viewjs/`
  is byte-identical before and after step 2, 157 occurrences.
- **Check 6, both engines.** `.devtools/pgsql/run-tests.sh triggers` before and after the
  consume fix: identical output, "TRIGGER BEHAVIOUR IDENTICAL", suite passed. `php -l` clean
  on `StockApiController.php`.
- **The refactor is a no-op.** Re-running the baseline harness against the step-2 tree
  reproduced every recorded field except the absolute row counts, which move because the
  probes themselves add and remove rows.

### Outstanding

Steps 3 to 6 in full, and with them the plan's checks 4 and 5. The baseline is the
acceptance list for step 3; the dialog-dismiss probe is the acceptance test for step 4; the
`purchase.js` push comments in the three Blade views are step 5's starting marker.
