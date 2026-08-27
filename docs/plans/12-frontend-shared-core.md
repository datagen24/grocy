# 12. Frontend shared core

**Goal:** Stop the copy-paste conventions drifting: one request core in `Grocy.Api` with
a default error path, factories for the list and form clone families, and the latent bugs
those copies have already accumulated.
**Depends on:** nothing. Must land before [05](05-store-shopping-lists.md),
[06](06-location-barcodes.md) and [08](08-nested-locations.md) add more list/form pairs to
copy from.
**Status:** draft for review.

## Today

The wiring is exemplary for a no-framework app. The layout auto-loads
`/viewjs/{view}.js`, every view/viewjs/route name lines up across all 73 views, inline
Blade scripts inject data and nothing else, and all API traffic goes through
`Grocy.Api.*` — there is not a single `$.ajax` or `fetch` bypass anywhere in the
codebase. That last property is what makes this plan cheap: there is exactly one place to
change.

What is wrong is underneath it.

**`Grocy.Api` repeats itself six times.** `Get`, `Post`, `Put`, `Delete`, `UploadFile`
and `DeleteFile` (`public/js/grocy.js:18-280`) each contain their own copy of the same
~30-line `onreadystatechange` handler, differing only in method and body. None of the six
sets a `timeout` or an `onerror` handler, so a dropped connection mid-save never resolves
either callback: the form stays disabled and the user is left with a spinner and no
information, forever.

**Silent failure is the default.** 148 error callbacks across 41 viewjs files do nothing
but `console.error(xhr)`. A failed delete, a failed save, a rejected edit — the UI simply
does not react. The fix is one line in one place, not 148 edits:
`Grocy.FrontendHelpers.ShowGenericError` already exists (`public/js/grocy.js:485`) and
already renders exactly the right thing, a toast with click-through technical details.

**Two clone families.** ~14 master-data list scripts and 22 `*form.js` scripts are
byte-identical modulo the entity name — roughly 2,300 lines. The delete-confirm
`bootbox.confirm` block appears in 23 files. `datetimepicker2.js` is a 344-line copy of
`datetimepicker.js` whose entire reason to exist is that two pickers cannot share a page.

**The copies have already drifted**, which is the actual argument for this plan rather
than tidiness:

- sibling lists disagree about the embedded-dialog reload convention;
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
| `public/viewjs/userform.js:157` | Sets `Grocy.DeleteUserePictureOnSave` (extra "e"); the submit handler at `:83` reads `Grocy.DeleteUserPictureOnSave`. Choosing a new picture does not cancel a pending delete-picture flag |
| `public/viewjs/tasks.js` | The user-filter handler builds an anchored regex in an if/else and never uses it; filtering silently falls back to substring search |
| `controllers/Api/StockApiController.php:344` | `ConsumeProduct` checks `array_key_exists('transaction_type', …)` then reads `$requestBody['transactiontype']`. Both spellings must be sent for the override to work, so effectively it never does |
| `public/viewjs/stockoverview.js` | Depends on `purchase.js` being pushed by its Blade view (above) |

The third is server-side, but it is the write path the frontend's consume dialog uses and
it is in the same "one spelling can never match" family, so it belongs with the rest.

## Proposed change

The order matters: **fix the bugs first, then refactor.** A refactor that also changes
behaviour cannot be verified by "the pages still work".

### Step 1 — the four latent bugs, as their own commit

Each is a one-line fix. Doing them first means the subsequent refactor is a pure no-op
and any behavioural difference found afterwards is a refactor bug.

For `transaction_type`/`transactiontype`, accepting both spellings is additive and breaks
nothing; accepting only the documented one is correct but is a (theoretical) API change.
See Q1.

### Step 2 — one private `request()` in `Grocy.Api`

```js
function request(method, url, body, success, error, opts) { … }
```

`Get`/`Post`/`Put`/`Delete`/`UploadFile`/`DeleteFile` become thin wrappers. The core
gains what none of the six has:

- a `timeout` (30 s proposed) and an `ontimeout` handler;
- an `onerror` handler, so a dropped connection reaches the error callback;
- `error` defaulting to `Grocy.FrontendHelpers.ShowGenericError` when the caller passes
  nothing.

That last line is the one that fixes 148 silent handlers, because after it the 148
`console.error` callbacks can simply be deleted rather than rewritten — omitting the
argument is then the correct spelling. Q2 covers the callers that legitimately expect
failures and must keep an explicit no-op.

The two hand-rolled `toastr.error(JSON.parse(…))` sites collapse into the same default.

### Step 3 — `GrocyEntityList` and `GrocyEntityForm`

```js
Grocy.EntityList("locations", { deleteConfirm: __t("…"), afterDelete: … })
Grocy.EntityForm("locations", "/api/objects/locations")
```

Each clone script becomes a call plus whatever is genuinely specific to it. The factories
own the DataTable wiring, the delete-confirm dialog, the Enter-to-submit handler, the
save/disable/re-enable cycle and the embedded-dialog reload — so `userobjectform.js` gets
its missing Enter handler back by construction, not by being noticed.

Convert **one** pair first (`locations` is the smallest and is also what
[06](06-location-barcodes.md) and [08](08-nested-locations.md) will extend), verify it,
then do the rest mechanically.

### Step 4 — one embedded-dialog reload convention

The two conventions currently in the tree do the same thing differently. Pick one, put it
in the factory, and delete the other. Q3 — this needs someone to look at both and decide,
not a plan to assert one.

### Step 5 — `purchase.js` and `datetimepicker2`

Extract the functions three other pages need out of `purchase.js` into a properly loaded
shared file (`public/js/grocy_stock_dialogs.js` or a `Grocy.Components` entry), so
nothing depends on a `@push` side effect. `purchase.js` keeps only what the purchase page
itself uses.

`datetimepicker2` disappears: parameterise the single component by element id/suffix so
two instances can coexist. This is 344 lines deleted and is the cleanest win in the plan,
but it is also the one most likely to have a subtle reason to exist — see Q4.

### Step 6 — the Blade minor items

List-page chrome repeats across ~14 templates while partials for it already exist and are
used elsewhere; apply the existing idiom. `mealplan` and `recipes` blades inject bare
globals instead of namespacing under `Grocy.*`. Two components are not registered under
`Grocy.Components`. All small, all mechanical, all safe to do alongside step 3 since the
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

The default error toast is a *user-visible* behaviour change with no wire-format
component: operations that used to fail silently now say so. That is the point, but it
will surface pre-existing failures nobody knew about, and the first week after it lands is
likely to produce bug reports that are really discoveries.

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
4. **`grep -c console.error public/viewjs/*.js` must reach 0** for the handlers that are
   pure logging, with the survivors being deliberate and documented (Q2).
5. **Two datetimepickers on one page.** The meal plan and stock entry forms are the
   places two pickers coexist; after step 5 both must set, clear and validate
   independently, including the "clear" and "now" shortcuts.
6. **Both engines, for step 1 only.** The consume fix is server-side and writes
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
`public/js/grocy.js`, `public/viewjs/*` and `views/*.blade.php`; only its step-1 consume
fix reaches into `controllers/Api/`, and even that does not collide with
[11](11-api-error-handling.md), which changes error paths rather than request parsing. It
can be done in parallel with 10, 11, 13 or 14.

**It blocks no feature plan outright** but it de-risks 05/06/08 by removing ~1,500 lines
those plans would otherwise have to keep consistent, and it de-risks
[04 seed datasets](04-seed-datasets.md) indirectly: a seeded test instance is much more
useful when a failed import says so instead of logging to a console nobody has open.

## Open questions

1. **`transaction_type` / `transactiontype`: accept both, or only the documented one?**
   Accepting both is additive and safe. Accepting only `transaction_type` is what the
   OpenAPI spec says and is cleaner, at the theoretical cost of a client that currently
   sends both. I lean to accepting both for one release with the undocumented spelling
   noted as deprecated, then removing it with the other breaking changes in
   [15](15-deliberate-cleanup.md) — but it may not be worth the ceremony for a spelling
   that is plainly a typo.
2. **Which callers must keep an explicit no-op error handler?** Making
   `ShowGenericError` the default is right for the 148, but some calls legitimately
   expect failure and must not toast: the `db-changed-time` poller
   (`grocy_dbchangedhandling.js`), the missing-localization logger, and barcode lookups
   where "not found" is an ordinary outcome. These need `function () { }` passed
   deliberately with a comment saying why. The question is whether that list is those
   three or longer — it needs the grep, not a guess.
3. **Which embedded-dialog reload convention wins?** Both are in the tree and both work.
   This wants five minutes of reading the two and picking, and it should be picked before
   the factory is written rather than after, because the factory bakes it in.
4. **Is there a real reason `datetimepicker2` exists as a copy?** The stated reason is
   "so two pickers can share a page", which parameterisation solves. If there is a second
   reason buried in it — different validation, a different date format, a different
   dependency on page state — a naive merge will break one of the two pages that use it.
   The honest check is a diff of the two files before committing to the merge.
5. **How far do the factories go?** A config-object factory that owns the whole page is
   the biggest win and the biggest blast radius; a set of shared mixins the scripts opt
   into is safer and collapses less. I lean to the factory for the pure clones and mixins
   for the ~5 list scripts that are only partly clones (`stockjournal`,
   `userpermissions`, `manageapikeys`, `products`, `recipes`), rather than forcing all 36
   scripts through one shape.
6. **Does this want a build step?** Everything above is plain browser JS with no
   bundling, matching the current architecture. Introducing a bundler would make the
   shared-module story conventional and would also be a new dependency, a new build
   artifact and a departure from a design that is otherwise working. I lean strongly to
   no — but it is worth stating as a decision rather than a default.

## Effort

Medium, dominated by conversion volume rather than difficulty. Steps 1 and 2 are a single
short session each and deliver most of the user-visible value — the bug fixes and the end
of silent failures. Step 3 is the bulk: one pair converted carefully, then ~35 mechanical
conversions with the baseline from verification check 1 as the acceptance list. Steps 4–6
are tidy-up that can ride along. Worth splitting: 1 and 2 are worth landing on their own
even if the factories wait.
