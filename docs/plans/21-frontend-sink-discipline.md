# 21. Frontend sink discipline

**Goal:** Make the frontend XSS gate real. The 23 CodeQL alerts of 2026-09-04 were closed
by hand; the job that was supposed to have caught them does not exist, the harness it
would run cannot boot against this tree, and the one sink that is a decision rather than a
defect is still open. Close all four.
**Depends on:** nothing. Step 1 unblocks [12](12-frontend-shared-core.md)'s whole harness,
so it comes first regardless of what else is scheduled.
**Status:** draft for review. Step 0 has already landed (`bdfa00c`, 2026-09-04).

## Today

### What the alerts were

GitHub had 23 open CodeQL alerts on `master`, all JavaScript, all High. Triaged one by
one against the tree rather than by title:

| Class | Alerts | Verdict |
|---|---|---|
| `.html()` / markup concatenation with browser-local input | #32, #10 | **exploitable**, proved with a payload |
| `.html()` of a summernote field | #17 | **real, and a decision** — see step 4 |
| DOM string reaching `$()` | #31, #30, #29, #28, #5, #4, #3 | not exploitable — jQuery 3.6 parses a string as HTML only when it starts with `<` and ends with `>`, and every one of these starts with `#` |
| DOM value interpolated into a query string | #37, #36, #35, #20, #19, #18, #14 | not exploitable — fixed path prefix, so no scheme is reachable |
| `replace("'", …)` escaping only the first quote | #25, #24, #23 | real bug, Sizzle selector rather than HTML |
| `stripHtml` single-pass tag strip | #22 | real weakness, but a DataTables search/sort normaliser and not a sanitiser |

**Step 0 (landed, `bdfa00c`)** fixed all 23 rather than dismissing the 20: the two live
sinks properly, the rest as one-line hardenings that also make the sinks honest.
`victual.js:616` rendered a chosen file name with `.html()` — a file named
`<img src=x onerror=…>.txt` executed on selection, in every form with a picture field.
`barcodescannertesting.js:90` concatenated the scanned barcode into `<option>` markup.
Both were verified by reverting the fix and driving a demo instance with Playwright:
`window.__pwned` was `true` before and `false` after.

### The gate does not exist

Three documents in this repository state that the S29 payload probe runs on every pull
request:

- [plans README row 12](README.md#hardening) — "the probe now runs on every pull request as
  the `frontend-security` job"
- [12-frontend-shared-core.md](12-frontend-shared-core.md) — "the probe now runs on every
  pull request rather than once"
- [.devtools/frontend/README.md](../../.devtools/frontend/README.md) — "**it is the one
  this repository runs on every pull request** — the `frontend-security` job in
  `.github/workflows/tests.yml`"

`.github/workflows/tests.yml` has three jobs: `lint`, `suite`, `images`. `psalm.yml` has
one: `php-security`. Neither file mentions `s29-payload.js`, and `grep -r s29-payload
.github/` returns nothing. The probe exists and is good; nothing runs it.

This is the reason the 23 alerts are the record they are. S29's second pass closed a
stored-XSS class in September 2026 and declared a standing guard against its return; two
sinks of exactly that shape were then merged and sat open for five days until CodeQL — not
this repository's own gate — reported them.

It is also a second instance of the failure the plans README already named once, in its
own words: *"a deferral that contradicts a stated gate has to change the gate's wording at
the same time, or the wording quietly becomes a claim nobody checks."* Here the wording was
never true to begin with. Step 2 is the fix; **Q1** is whether the corpus needs something
stronger than another promise.

### The harness cannot boot

Independent of CI, `.devtools/frontend/`'s documented recipe does not work against the
current tree. Every probe in it needs a demo instance, and demo mode 503s after its first
request:

```
Victual cannot serve requests: the database schema does not match this code.
  Applied but unknown to this code: -1
```

`DemoDataGeneratorService` records "the demo data already ran" as a row with the magic
migration number `-1` (`services/DemoDataGeneratorService.php:35`).
`DatabaseMigrationService::GetUnknownMigrationNumbers()` returns every applied number this
engine has no file for, `-1` included, and `SchemaVersionMiddleware:77` refuses to serve
when that set is non-empty. So the first `GET /` seeds the demo data and thereby breaks
every request after it.

The dates say this was not noticed rather than accepted: the harness landed 2026-09-02
(`1403cd0`), the middleware started consulting the unknown set on 2026-09-03 (`b46781e`,
"gate on the whole migration set"). Nothing ran the harness in between, which is the same
finding as the section above from the other end.

It also means step 2 cannot be done first. A `frontend-security` job added today would
fail on its first run for a reason that has nothing to do with the frontend.

## Proposed change

Five steps. 1 and 2 are the ones that matter; 3, 4 and 5 are the work the gate then holds.

### Step 1 — the demo marker is not a migration

`GetUnknownMigrationNumbers()` filters to `>= 0`, so the demo marker stops reading as a
rolled-back deployment. One line, plus the comment saying why negative numbers are
bookkeeping rather than schema versions.

`GetMissingMigrationNumbers()` is unaffected (`-1` is never in the required set), and
`.devtools/pgsql/schemagatetest.php` calls the same method at lines 123 and 260 — it must
keep asserting that a genuinely rolled-back database is refused. Both directions get a
test, because the fix is in exactly the code whose job is to be strict. See **Q2** on
whether the marker belongs in the `migrations` table at all.

### Step 2 — the job, for real

Add `frontend-security` to `.github/workflows/tests.yml`: boot a demo instance the way
`.agents/skills/run-app/SKILL.md` does, `npm install` in `.devtools/frontend`, run
`s29-payload.js`, fail the job on a non-zero exit. `PLAYWRIGHT_BROWSERS_PATH` and
`PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD` per that skill.

The job has to be shown capable of failing before it is believed, by the probe's own rule:
run it once against a tree with step 0 reverted and confirm it reports the two sinks (after
step 3 gives it probes for them), then restore. A gate whose failure mode has never been
observed is the thing this plan exists to stop shipping.

`routes-smoke.js` is cheap and covers every non-API view route; adding it to the same job
is nearly free. See **Q3**.

### Step 3 — probes for the shape that got through

`s29-payload.js` seeds records through the API and asserts no page executes their names. It
is well built for the sinks S29 was about, and structurally blind to both sinks step 0
fixed, because neither involves a stored record:

- **A file name.** `<input type="file">` → `.custom-file-label`. The payload never touches
  the server; Playwright's `setInputFiles` with a file named `<img src=x onerror=…>.txt`
  reaches it in one call.
- **Local keyboard/scanner input.** `/barcodescannertesting`, where a typed barcode is
  echoed into an `<option>`.

Add both as a `local-input` probe family alongside the seeded one, under the probe's
existing rule that a sink which never appeared and an action that threw both count as
failures. That rule is the reason this suite is worth extending rather than replacing.

**Q4** asks how far the family should go — every `.custom-file-input` on the six forms that
have one, or one representative.

### Step 4 — rich text, sanitised in the browser at render time

Alert #17, `shoppinglist.js:560`, is the one that is not a defect: `#description` is a
summernote field and `.html()` is what renders it. **Decision taken: sanitise in the
browser with DOMPurify.** Two things about the shape of that, both established against the
running app rather than assumed:

**Sanitising on save does not close it.** `PUT /api/objects/shopping_lists/{id}`
(`routes.php:168`) writes `description` directly. A browser-side sanitiser on the save path
keeps the database clean against paste accidents and is worth having for that, but any
account that can edit a list can also skip the page and post the payload. It is hygiene,
not the control.

**Sanitising on render does close it, and has to happen before summernote.** With
`<img src=x onerror=…>` stored in `shopping_lists.description`, the payload fires **on page
load**, in summernote's own contenteditable, before anyone opens the print dialog — so
patching line 560 alone removes nothing. Purifying at render, in the victim's browser, is
effective precisely because that is where the payload would otherwise run.

So:

1. Add DOMPurify as a frontend package (`.yarnrc` already installs into `public/packages`).
2. Purify every `wysiwyg-editor` textarea's value **before** `victual_summernote.js`
   initialises it, and purify at each `.html()` render of such a value — `shoppinglist.js:560`
   today. One helper next to `Victual.FrontendHelpers.EscapeHtml`, which is where a reader
   will look for it.
3. Purify on save as well, for the hygiene reason above, and say in the comment that it is
   hygiene so nobody later mistakes it for the boundary.
4. Configure the allowlist against what the toolbar can actually produce — the toolbar is
   fixed in `victual_summernote.js` (fontsize, bold/underline, colour, lists, tables, link,
   picture, video) and `codeview` lets a user type anything else. **Q5**.

This decides where HTML is trusted in this application, so per [AGENTS.md](../../AGENTS.md)
it leaves an ADR behind — *Rich text is sanitised in the browser at render time* — recording
the client-side choice, that the API is deliberately not a sanitising boundary, and what
that means for [ADR-0006](../adr/0006-authenticated-issues-in-scope.md)'s threat model. The
ADR is accepted in its own PR carrying bookkeeping only, per the same file.

### Step 5 — the last of the selector class, and a convention

CodeQL flagged seven `$(domDerivedString)` sites and step 0 fixed them. Twelve more of the
identical shape are in the tree, unflagged, all reading `data-next-input-selector`:

| File | Lines |
|---|---|
| `components/shoppinglocationpicker.js` | 62, 75 |
| `components/productpicker.js` | 140, 161 |
| `components/locationpicker.js` | 61, 74 |
| `components/recipepicker.js` | 62, 75 |
| `components/datetimepicker.js` | 191, 395 |
| `components/userpicker.js` | 65, 81 |

Every value is a Blade literal and none is exploitable, for the same jQuery 3.6 reason as
the flagged seven. They are here because a security change that leaves the identical sink
twelve lines away is a change a reviewer cannot trust, and because CodeQL will eventually
raise them and this plan will be read again from scratch.

`$(document).find(sel)` throughout, matching step 0. Then one line in
[AGENTS.md](../../AGENTS.md)'s security posture: **a string that came out of the DOM reaches
jQuery through `.find()`, never through `$()`** — short enough to be checked in review,
which is the only kind of convention that survives.

## Open questions

1. **Is a fourth promise the right fix for a gate that was promised three times?** Step 2
   adds the job and the three documents become true. Nothing stops the next divergence. The
   alternative is mechanical: a `lint`-job assertion that each workflow job name named in
   the corpus exists in `.github/workflows/`, so the claim and the tree cannot separate
   silently. That is a small script and a real widening of scope. Worth it, or is the job
   plus this record enough?

2. **Should the demo marker live in the `migrations` table at all?** Step 1 filters it out
   at the read. A `demo_data_generated` row in a settings table, or a dedicated column,
   would mean nothing has to remember the exception — at the cost of a migration and a
   compatibility path for databases carrying the `-1` row today. Filter now and move it
   later, or move it once?

3. **How much runs in `frontend-security`?** `s29-payload.js` alone is the security gate.
   `routes-smoke.js` needs the same booted instance and would catch a console error on any
   view route. `baseline.js` is a much longer run and belongs on demand. Probe only, or
   probe plus smoke?

4. **How wide is the `local-input` family?** One `.custom-file-input` probe proves the
   sink; six prove every form. The sink is one shared handler in `victual.js`, so one is
   arguably honest and six is arguably theatre — but the six differ in what sits next to
   the input, which is what `.next()` resolves.

5. **What does the DOMPurify allowlist permit?** Two defensible answers. Match the toolbar
   exactly, which is tightest and silently drops anything an existing description already
   contains from `codeview`. Or take DOMPurify's default HTML profile, which is safe and
   keeps existing content intact. This household's data can be inspected before deciding —
   `SELECT description FROM shopping_lists` and the equivalent for recipes and products is
   the whole population.

6. **Does anything else render a `wysiwyg-editor` value?** Step 4 covers summernote's own
   init and `shoppinglist.js:560`. `productform.js:444` pastes a source product's
   description into the editor on copy. A grep for the class plus the fields that use it
   settles this before the step, not during it.

## Verification

1. **Step 1** — a demo instance serves every page after seeding: `bin/victual-migrate`, boot,
   `GET /`, then 200 on `/stockoverview`, `/shoppinglist`, `/recipes`. `schemagatetest.php`
   still refuses a database carrying a genuine unknown migration.
2. **Step 2** — the job is green on a clean tree, and **red** on a tree with step 0's
   `victual.js` and `barcodescannertesting.js` fixes reverted. Both runs recorded in the
   Executed section with their run URLs.
3. **Step 3** — each new probe reports `xss=1` against the reverted tree and clean against
   the fixed one. A probe that cannot be made to fail is not evidence and does not ship.
4. **Step 4** — `<img src=x onerror=window.__pwned=1>` stored in `shopping_lists.description`
   by `PUT /api/objects/shopping_lists/1`, then: the shopping list page loads with
   `__pwned` undefined, the print dialog renders with `__pwned` undefined, and a description
   using every toolbar feature survives a save/reload round trip visually unchanged.
5. **Step 5** — the twelve sites converted, and every picker still moves focus to its next
   input: purchase, inventory, stockentryform and mealplan driven end to end.
   `.devtools/frontend/two-pickers.js` covers the datetimepicker pair already.
6. **Whole plan** — CodeQL reports zero open alerts on `master`, and the `frontend-security`
   job has failed at least once, on purpose, on a branch.
