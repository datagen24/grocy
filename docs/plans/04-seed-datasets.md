# 04. Seed product datasets

**Goal:** Optional baseline master data — common household staples, quantity units,
product groups, and barcodes — so a new install is not an empty database.
**Upstream:** [grocy/grocy#2679](https://github.com/grocy/grocy/issues/2679)
**Status:** draft for review.

## Today

A fresh install has nothing but the defaults created during migration. Populating master
data means entering products by hand or scripting against the API.

There is no import mechanism of any kind — no CSV, no JSON, nothing in the API controllers.
The one precedent is `DemoDataGeneratorService`, which builds a full sample database in PHP
for demo mode. It is a reasonable model for *how* to write rows (it goes through LessQL and
lets triggers fire, which is what keeps derived tables consistent) but not for *what*,
since its content is illustrative rather than useful.

## Proposed change

Two separable things, and conflating them is the main risk in this plan:

**A. An import mechanism.** A command that takes a dataset file and creates master data,
skipping anything that already exists. Useful on its own — it is also how you would restore
master data, share a setup, or bootstrap a second instance.

**B. Actual dataset content.** The staples themselves. This is curation work, not
engineering, and it is where the effort really goes.

### Format

JSON, one file per dataset, covering the master data entities only — `quantity_units`,
`quantity_unit_conversions`, `product_groups`, `locations`, `shopping_locations`,
`products`, `product_barcodes`. Deliberately not stock, chores, recipes or users.

Entities reference each other by **name**, not id, so a dataset is portable between
installs and merges into an existing one. The importer resolves names to ids as it goes.

### Import path

`bin/grocy-seed-import <dataset.json> [--dry-run]`, mirroring `bin/grocy-db-import`:

- idempotent — an existing name is skipped, not duplicated or updated
- `--dry-run` prints what would be created
- goes through LessQL so triggers fire and derived tables stay correct
- reports what it created and what it skipped

Writing directly through the service layer rather than over HTTP avoids needing an API key
and keeps it usable during first setup.

### Datasets shipped

Start with one small, obviously useful set rather than an ambitious library:
`datasets/household-staples-en.json` — a few dozen products across a handful of groups,
sensible quantity units, no barcodes.

### API

None. This is a CLI concern. Adding an import endpoint later is possible but is a new API
surface with real authorisation questions, and is not needed for the goal.

## The barcode problem

The issue asks for "common household staple barcodes", and that is the part to be careful
about. Barcodes are regional — the same product has different EANs in different countries,
and a shipped barcode list is wrong for most users. Worse, a *wrong* barcode is more
harmful than no barcode, because scanning silently matches the wrong product.

Grocy already resolves barcodes at scan time through
`STOCK_BARCODE_LOOKUP_PLUGIN` (Open Food Facts by default), which is regionally correct and
always current. My recommendation is to ship **no** barcodes and let lookup populate them
naturally — see Q2.

## Open questions

1. **Is this actually valuable to you**, or is it a "good for the fork's users" item? You
   already have a populated instance. If it is the latter, it is the easiest thing on the
   roadmap to defer, and A without B is most of the durable value.
2. **Ship barcodes or not?** I recommend not, for the reasons above. If yes, they need a
   region marker and a clear statement that they are best-effort.
3. **Should a dataset be able to update existing rows**, or only create? Create-only is
   safe and predictable. Update-capable makes datasets a sync mechanism, which is a much
   bigger idea with conflict questions attached.
4. **Where do datasets live?** In the repo (reviewable, versioned, but every user carries
   every dataset) or fetched on demand (flexible, but adds a network dependency and a
   trust question about what is being fetched).
5. **Localisation.** Product names are language specific. One dataset per locale, or
   English only to start? English only, unless you want otherwise.

## Effort

Medium, and unusually lopsided: the importer is a day, the curation is open-ended. Suggest
scoping to the importer plus one deliberately small dataset, and treating additional
datasets as ongoing content rather than part of the change.
