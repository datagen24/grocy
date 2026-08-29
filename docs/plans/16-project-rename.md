# 16. Project rename

**Goal:** Give the fork its own name — repo, branding, and eventually internal
identifiers — with the breaking parts batched deliberately rather than leaked.
**Depends on:** nothing for the outward rename; [15](15-deliberate-cleanup.md)'s
breaking batch (or any release that is already breaking) for the internal renames.
**Status:** direction settled (see Q1), execution staged. The outward rename can
happen any time; the internal tiers land per the touchpoint table below.

## Background

The fork has diverged past the point where "grocy fork" describes it: nested
product-group taxonomy, directed substitutions with conversion factors, additive
fulfillment fields, density conversions on the horizon, and an agent (Hermes)
maintaining the knowledge base via MCP. Breaking changes are now deliberate
policy rather than accidents. A fork that keeps the parent's name reads as
derivative, confuses search and issue triage, and risks trademark friction.
Precedent for clean renames: Jellyfin (Emby), Forgejo (Gitea), Valkey (Redis),
LibreOffice (OpenOffice).

Naming direction: the project is provisioning, not list-keeping — stores,
minimums, substitutions, resupply. The nautical/victualling frame fits both the
domain and the maintainer.

## Candidates

| Name | For | Against |
|---|---|---|
| **Victual** (chosen direction, core name) | Names the domain (the goods themselves); no spelling variance, unlike the -er forms; softer and more approachable; pairs naturally with *Victualer* as the actor name (below) | Dictionary word, so more collision-prone in package registries than the rarer -er form; pronunciation ("VIT-l") doesn't match spelling |
| **Victualer** (chosen direction, automation actor) | Names the system's role; admiralty pedigree (victualling bills, the Victualling Board) maps exactly to the problem domain; short forms come free (`vict`, `victd`, "victualling yard" for the knowledge base) | Spelling variance (victualer/victualler); same pronunciation gap |
| Provisioner | Plain-English, nautical resonance | Generic; heavy namespace collision in infra tooling (Terraform provisioners etc.) |
| Steward | Personal, crew-role framing | Generic; collisions likely |
| Larder | Evocative, short | Prior apps by this name; less distinctive |
| Mise | Chef-insider, very short | Opaque to non-cooks; collision-prone |
| Galley | Kitchen + ship | Heavily overloaded in software |

The pairing resolves each name's main objection with the other: Victual alone
"names the goods, not the actor" — so the actor name goes to the actual actor.
Automated processes write log entries as **the Victualer**; humans appear as
themselves. That split also does real work: it distinguishes machine writes from
human ones in the audit trail, and [02](02-mcp-endpoint.md)'s sidecar wants
exactly such an actor identity when MCP writes arrive. Meanwhile the
spelling-variance problem is contained to an in-app label where a canonical
spelling is simply picked, instead of afflicting the repo, domain, and package
names.

## Codebase touchpoints

Survey of everything carrying the old name, tiered by what renaming it breaks.
Counts from the tree as of this plan's writing.

### Tier 0 — never rename (wire formats and immutable history)

- **The grocycode magic `grcy:`** (`helpers/Grocycode.php`,
  [docs/grocycode.md](../grocycode.md)). It is encoded in physical printed
  labels on real shelves. Renaming the magic invalidates every label ever
  printed and every scanner integration. It is a wire format: keep `grcy`
  forever, documented as a historical artifact — at most accept a second magic
  alongside it someday. Do not let a rename sweep "fix" this.
- **Shipped migrations.** Migration files are immutable history; any that
  mention old identifiers keep mentioning them. Renames of database-level
  objects happen in *new* migrations only.

### Tier 1 — breaking for deployed instances (batch with a breaking release)

- **Env-var prefix `GROCY_*`** — every setting is overridable via the prefix
  (`helpers/extensions.php:244`), and `GROCY_DATAPATH` is load-bearing at boot
  (`app.php:14`). Renaming the prefix breaks every deployment's environment.
- **In-database identifiers**: `grocy_user_setting()`,
  `grocy_next_internal_recipe_id`, `grocy_sqlite_percent_w`,
  `grocy_mealplan_week_name` (`db/pgsql/baseline/`, referenced from views and
  migrations). Renaming requires a migration pair on live databases plus a
  baseline update.
- **`grocy.db` default filename** (`services/Database/SqliteDialect.php:154`) —
  existing volumes hold the file under that name; a rename needs a fallback
  probe or an explicit migration step.
- **DB defaults** `DB_NAME`/`DB_USER` = `grocy` (`config-dist.php:33-34`) and
  the compose file's matching PostgreSQL service values. Defaults only, but
  changing them strands anyone who relied on them.
- **`bin/grocy-migrate` / `bin/grocy-db-import`** — ops entry points that may
  live in cron jobs and runbooks; rename with compat symlinks or not at all
  until the batch.
- **Session cookie `grocy_session`**
  (`services/SessionService.php:11`) — renaming logs every user out once.
  Cheap, but it belongs in the batch's changelog, not a side effect.

### Tier 2 — internal, non-breaking but high-churn (schedule around open branches)

- **PHP namespace `Grocy\`** — 72 files plus composer autoload. Safe but a
  merge-conflict bomb for every open branch; do it at a quiet point between
  waves, as one mechanical commit with nothing else in it.
- **JS global `Grocy.` and the `public/js/grocy_*.js` files** — coordinate with
  [12](12-frontend-shared-core.md), which rewrites those files wholesale
  anyway. Renaming before or with 12 avoids touching them twice.
- **`grocy.openapi.json`** — filename and `"title": "Grocy REST API"`. The API
  paths themselves carry no name (all `/api/...`), so this is cosmetic — but
  the filename is referenced from code and docs.
- **Localization msgids** — UI source strings embed the name ("About Grocy",
  "Do you find Grocy useful?"). Changing a msgid orphans that string's
  translation in every locale. As a hard fork with no live Transifex feed this
  is tolerable, but it is a known cost, not free.

### Tier 3 — free, and some should change regardless of the rename

- **User-Agent on barcode API calls** (`services/StockService.php:849`) sends
  `Grocy/<version> (https://grocy.info)` — advertising *upstream's* URL on this
  fork's traffic. That misattributes traffic to upstream and should change as
  soon as there is a name to change it to; arguably before.
- **README, about page, logo** — the README already leads with "hard fork of
  grocy"; the about page and `grocy.info` links move to the attribution section
  (Q5) rather than vanishing.
- **The MCP sidecar repo** — the interface spec already anticipates this plan:
  the repo takes the working name `grocy-mcp` "with the real name settled
  before the first tagged release" ([mcp-interface-spec.md](../mcp-interface-spec.md),
  §Repo home). Settling Q1 unblocks naming it correctly from the start.
- **Dev tooling image `grocy-fork-dev`** (`db/pgsql/README.md`, Dockerfile /
  compose) — dev-only, rename any time.

## Open questions

1. **Final name.** Decision deliberately deferred at least one day past initial
   enthusiasm.

   > **Response:** **Victual** as the core/project name, **Victualer** as the
   > automation actor — automated processes write log entries as the Victualer.
   > The pairing beats either name alone (see Candidates). Recorded as the
   > direction, subject to the one-day cooling rule and to Q3's namespace
   > verification; if either sours it, the table stands ready.

2. **Spelling.** American *victualer* or British *victualler*? Whichever is
   chosen, register/redirect the other where possible (repo redirects, domain).

   > **Response:** Mostly dissolved by Q1 — *Victual* has one spelling, and it
   > is the name on the repo, domain, packages, and env prefix. For the actor
   > label, American *Victualer*, matching the maintainer's locale; claim both
   > spellings wherever a claim is cheap (domains, org names).

3. **Namespace claims.** GitHub org/repo, domain(s), package names. Verify
   clear before announcing; claim both spellings. Note *victual* is a
   dictionary word, so check registries (GitHub, npm, Docker Hub, domains)
   more carefully than the rarer *victualer* would need.

4. **Scope of the rename.** Repo and branding only, or also internal
   identifiers — DB name defaults, config env-var prefixes (`GROCY_*` → ?),
   Docker image name, user-agent strings on barcode API calls? Internal renames
   are the breaking part; decide whether they land with the rename or lag
   behind a compatibility window.

   > **Response:** Split per the touchpoint tiers above. The outward rename
   > (repo, branding, docs, Tier 3) is free and happens first. Tier 1 —
   > `GROCY_*` prefixes, DB defaults, database identifiers, bin scripts — rides
   > with a release that is already breaking, of which
   > [15](15-deliberate-cleanup.md) is the standing accumulator, rather than
   > making the rename its own migration event. Tier 2 lands opportunistically
   > (namespace between waves, JS global with plan 12). Tier 0 never lands.

5. **Upstream attribution.** grocy is the origin and its license terms follow
   the code. Decide the attribution wording in README/about and whether any
   upstream sync relationship survives the rename (likely already dead given
   hard-fork status — confirm and state it).

   > **Response:** Largely done already: `LICENSE.md` is structured for exactly
   > this (upstream code MIT © Bernd Bestel, fork changes BSD 3-Clause), and
   > the README leads with the hard-fork attribution. The rename keeps both,
   > moves the remaining `grocy.info` links out of the UI chrome into an
   > attribution block on the about page, and states plainly that no upstream
   > sync relationship exists. Confirmed: there is none to preserve.

6. **Migration story for existing instances.** Do current deployments (this
   household's) rename in place, or does the rename coincide with a version
   boundary that's already breaking anyway? Bundling it with an existing
   breaking release costs nothing extra.

   > **Response:** Bundled, per Q4. The household instance takes the Tier 1
   > changes as part of that breaking release's normal upgrade, with the
   > env-var and filename renames called out in its changelog. Nothing renames
   > in place ahead of that boundary.

7. **Timing.** Rename before or after the next implementation work lands?
   Renaming first means all new docs/plans carry the new name; renaming later
   means a sweep. Earlier is cheaper.

   > **Response:** Sooner. Every plan doc and Response block written from here
   > forward either carries the new name or gets swept later, and the MCP
   > sidecar repo is explicitly waiting on this decision for its real name.
   > Once Q1 survives its cooling day and Q3's checks pass, the outward rename
   > (Tier 3 + repo/branding) proceeds before the next implementation wave
   > starts; the breaking tiers keep their own schedule per Q4.

## Constraints

- License continuity from upstream grocy is non-negotiable.
- Existing instance must survive the rename with data intact (dual-engine).
- The rename itself must not silently change any API surface — if env-var
  prefixes or endpoints change, that is a called-out breaking change per
  ground rules, not a side effect.
- The grocycode wire format (`grcy:` magic) is out of scope permanently
  (Tier 0): printed labels outlive branding.
