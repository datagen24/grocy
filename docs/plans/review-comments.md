# Roadmap plan review — comments

Review pass over plans 01–08 (09 lives in its own PR and is only referenced here).
Comments are keyed to each plan's numbered **Open questions**; anything outside those
numbers is an additional observation. Context assumed throughout: household scale,
hard fork, deployed on k3s with the stated goal of fully immutable, scale-to-zero pods.

## General

The plans are unusually honest about where the risk lives (curation in 04, the audit in
07, spec churn in 02), and the README's suggested order is right — in particular doing
08 before 07, and running 09's twenty-barcode experiment before committing to anything
barcode-adjacent. Two cross-cutting notes:

- **Test scaffolding before 07/08.** The difftest/trigdifftest tooling is the fork's only
  safety net and it is manual. Before starting the recursive-hierarchy work, it is worth
  the half day to make a runnable fixture suite (even a bash script that stands up both
  engines and runs a list of seed+view assertions) so 07's "verification first" section
  has something to hang off. 07 already says this; it applies to 08 too.
- **`product_groups` is quietly becoming a hub.** 03 gives it a minimum, 05 gives it a
  per-store position. That is fine — it is the natural place — but it means the group
  picker moves from "optional taxonomy" to "load-bearing master data", and the seed
  dataset in 04 should reflect that by shipping a sane default group set.

## 01. File storage in the database

Agree with the design, including PostgreSQL-only and rejecting the config combination at
startup. This is the plan that actually delivers the stateless pod, and the two-backup-
streams argument is the right justification.

1. **Import strategy:** (a), explicit import — agree, and the k3s context strengthens it:
   run `bin/grocy-files-import` as a one-off Job against the PVC before flipping the
   Deployment to the volume-less spec. A read-through fallback means the PVC can never be
   deleted with confidence, which is the whole point. Make the importer idempotent
   (skip rows that already exist with identical size) so a crashed Job can simply re-run.
2. **Size cap:** yes, and validate it against PHP's own `upload_max_filesize` /
   `post_max_size` at startup — a `FILE_STORAGE_MAX_SIZE_MB` larger than what PHP accepts
   is a lie, and one smaller is the real bound. Report the effective value.
3. **Reverse migration:** agree, don't build it. `COPY (SELECT content ...) TO` plus a
   shell loop covers the contingency.

Additional:
- `Create` vs `Write` semantics: implement `Write` as `INSERT ... ON CONFLICT (file_group,
  name) DO UPDATE` so overwrite is atomic; `Create` as a plain insert letting the unique
  constraint signal "exists". Cheaper and more correct than exists-then-write.
- The `files` table will be the first engine-exclusive table. Check that
  `.devtools/pgsql/difftest.php` and `DatabaseImporter` tolerate a table that exists on
  one engine only, and note the exception in `db/pgsql/README.md` so the "every migration
  works on both engines" rule has its documented exemption.
- Thumbnails written on first request are a runtime DB write from a GET path. Harmless
  here, but worth a one-line comment in the code, since "GETs don't write" is otherwise a
  nice invariant.

## 02. MCP endpoint

The plan's instinct to distrust remembered spec detail is correct. Two structural
comments before the numbered answers:

- **Q6 deserves to be answered first, and I would answer it "separate container".**
  The MCP protocol layer (streamable HTTP, JSON-RPC framing, session handling,
  initialize/capabilities lifecycle) has mature official SDKs in TypeScript and Python
  and none in PHP — building and tracking it by hand in Slim is the single largest and
  least durable part of the integrated design. A sidecar/standalone MCP server that
  calls the existing REST API with an `API_KEY_TYPE_MCP` key gets: independent deploys
  as the spec moves, an SDK doing the transport, and — in the k3s playground framing —
  a second small scale-to-zero service, which is exactly the pattern being practiced.
  "Authenticates against Grocy's own user system" is still satisfied: the key resolves
  to a user and every REST call is permission-checked as that user. What is lost is
  in-process permission granularity, which Q3's new permission covers adequately.
- If it does stay integrated, the `/api/mcp` mount trick is elegant and correct.

1. **Auth:** bearer API key for v1, and let the ingress do the rest. You already run
   k3s ingress; if external exposure is ever wanted, an OAuth proxy at the ingress
   (which grocy's `ReverseProxyAuthMiddleware` already knows how to trust) is the
   household-scale answer to "the spec wants OAuth 2.1" without implementing an
   authorization server in grocy. Test with the actual client first, as the plan says.
2. **Read-only v1:** agree, strongly.
3. **Separate write permission:** yes — `MCP_WRITE` (or a per-key "read only" flag on
   the key itself, which is arguably simpler than a permission: revocation and scoping
   then live in one place, the key management screen).
4. **Exposure:** local/tailnet only until there is a concrete reason. External flips
   Q1's answer to "real OAuth in front", per above.
5. **Granularity:** narrow tools, roughly the table in the plan (6–10). One addition:
   keep responses deliberately small — return name/amount/due-date fields, not raw view
   rows. Token-economy is a real constraint for the consuming model, and raw
   `uihelper_*` rows are wide.
6. Answered above — recommend separable.

## 03. Category level minimum stock

The plan correctly spots the one real hazard (`/stock/volatile` response shape).

1. **Shortfall resolution:** agree with option 1 (show, don't auto-add) for v1, and with
   keeping group shortfalls in a **new** view (`product_groups_missing` or similar)
   rather than a third branch of `stock_missing_products` — that removes the
   `/stock/volatile` question entirely instead of resolving it carefully. The note-only
   row (option 3) is a good follow-up once the feature has proven itself.
2. **Independent minimums:** agree.
3. **Sub-product counting:** aggregate per product, not via `stock_current`'s aggregated
   rows. Concrete trap: if the group sum is built from rows that already aggregate
   children into parents *and* the children are themselves in the same group, the stock
   counts twice. Sum each product's own (non-aggregated) stock across the group's
   members; that stays correct when 07 makes the tree deep.
4. **Inactive products:** agree, exclude.

## 04. Seed product datasets

The A/B split is the right analysis. One reframe: **A is worth building for this fork's
own operational life regardless of B.** A name-keyed, idempotent master-data importer is
also how you seed disposable test instances — which the difftest workflow and every
future plan's manual testing will want. That is a better justification than hypothetical
fork users.

1. **Value:** per above — build A when the first plan needs a seeded test instance
   (probably 06 or 08); let B wait indefinitely.
2. **Barcodes:** ship none. Agree completely, for the stated reasons.
3. **Create-only:** agree. Update-capable turns a seed file into a sync protocol; decline.
4. **Location:** in the repo. A network fetch for master data is a trust and
   availability cost with no household-scale benefit.
5. **Localisation:** English only.

Additional: give the JSON format a `schema_version` field and validate the whole file
before writing anything, so `--dry-run` and "half-imported dataset" both stay honest.

## 05. Store specific shopping lists

1. **Ordering key:** product groups — agree. Home-storage location is the wrong axis for
   shop layout, and 08's tree should not be bent into that role.
2. **Is ordering worth it:** ship A + C first exactly as the effort section proposes, and
   let real shopping trips decide whether B happens. Filtering may well be 80% of it.
3. **Items from other stores:** show everything, use store only for sort — agree.
   Hiding rows from a shopping list is how things fail to get bought.
4. **Rename to `stores`:** not now. Park it on an explicit "breaking changes, batched"
   list for the fork rather than in any feature plan.
5. **Recipe default list:** per recipe — agree.

Additional: for the B ordering table, `(shopping_location_id, product_group_id,
sort_number)` with a unique on the pair; the drag-to-order UI already exists in spirit on
meal plan sections, so copy that pattern rather than inventing one.

## 06. Location barcodes

The camera-first framing is the most valuable part of this plan — it is what makes the
UUID and QR decisions fall out correctly.

1. **Stable codes:** yes, `locations.code_uuid`. Decide the encoding explicitly:
   `grcy:l:{uuid}` (uuid *as* the id, needs an indexed lookup and a Grocycode parser that
   accepts non-numeric ids) is cleaner than appending the uuid as extra data while the id
   stays authoritative. Labels should carry only the stable identifier.
2. **Machine reporting:** agree — out of scope here, own plan once the camera exists.
   When it comes, the observation-then-accept shape (a staging record a human confirms)
   is the one that cannot silently corrupt stock; a camera writing through the inventory
   endpoint directly is the trapdoor to avoid.
3. **QR:** yes, and expect a new dependency — the bundled barcode generation is
   1D/DataMatrix oriented, and a GD/SVG-capable QR library (e.g. chillerlan/php-qrcode)
   is the usual PHP answer. Verify before assuming the bundled library does it.
4. **Other entities:** just locations now. `quantity_units` and `product_groups` never
   get physical labels; add `shopping_locations` only if a use appears.
5. **Path on label:** human-readable text line shows the path once 08 lands; the encoded
   payload stays the bare uuid. Never encode display strings into the machine side.

## 07. Deeply nested products

The table of one-level assumptions is the plan's core asset, and "fixtures before any
change" is the right discipline. Endorse doing 08 first.

1. **Roll-up depth:** whole subtree — agree; a partial tree is worse than no tree.
2. **Mixed nodes:** must be allowed (depth > 2 requires it). The fixtures should include
   a middle node that itself holds stock, because that is where aggregation is most
   likely to double-count.
3. **Depth cap:** yes, cap ~5, and prefer a plain portable check if the config knob is
   awkward to reach from a trigger — a hard-coded sane cap beats a configurable one that
   complicates the dual-engine trigger pair. Cycle check is the correctness item; copy
   the recipe guards as planned.
4. **Substitution:** whole subtree, ordered by depth (nearest first). Direct-children-
   only would be the one roll-up that stops early, violating your own Q1 answer.
5. **Real use case:** worth actually writing down your concrete tree before starting.
   This is the largest item on the roadmap, and its cost is fixed while its value is
   proportional to how deep your real data goes. If the honest answer is "three levels,
   for a handful of staples", that still justifies it — but decide on data, not
   symmetry with 08.

Additional: middle-node semantics leak into the API. `stock_current` gaining aggregated
rows for intermediate parents changes what `/stock` returns for consumers like the Home
Assistant integration — technically additive (new rows, same shape), but worth a line in
the plan's API section and a deliberate decision, not a side effect.

## 08. Deeply nested locations

Keeping `stock_current_locations` meaning "exactly this location" and making roll-up a
separate join is the key call, and it is right — it keeps the change additive.

1. **Name uniqueness:** `UNIQUE(parent_location_id, name)` — agree. On PostgreSQL use
   `NULLS NOT DISTINCT` (15+, which this fork can require); on SQLite the equivalent is a
   unique **expression** index on `(IFNULL(parent_location_id, -1), name)`. That makes
   this the first migration pair where the two engines need genuinely different DDL for
   the same rule — a good, small test of the per-engine migration convention.
2. **Deleting a parent:** block — agree. Reparenting silently rewrites history; cascade
   deletes stock's location. Blocking with a clear message is honest.
3. **`is_freezer` inheritance:** don't inherit, v1. Due-date handling is
   stock-correctness territory, exactly as the plan says — so keep the flag literal, and
   get 90% of the friendliness by defaulting the checkbox from the parent when creating
   a child location. Inheritance can be revisited if the explicit flag proves annoying.
4. **Roll-up in UI:** filter yes, location-content report no — agree with the plan's own
   lean.
5. **Depth cap:** share one constant/config with 07; something like 6 clears
   floor/room/cabinet/shelf with headroom.

## 09. Barcode lookup sources (by reference)

Not on this branch, so no line comments — but the README's suggestion to run its Q1
experiment (twenty real pantry barcodes against each candidate source) *before* anything
else on the roadmap is the single best sequencing decision in the document. It costs
thirty minutes and de-risks both 09 and 04.
