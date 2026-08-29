-----

<div align="center">
<img alt="Victual logo" height="50" src="public/img/logo.svg" />
<h2>ERP beyond your fridge</h2>
<h3>Victual is a web-based self-hosted groceries & household management solution for your home</h3>
<em><h4>A hard fork of <a href="https://github.com/grocy/grocy">grocy</a> by <a href="https://berrnd.de">Bernd Bestel</a>, maintained for one household</h4></em>
</div>

-----

## What this is

[grocy](https://github.com/grocy/grocy) is Bernd Bestel's household management
application, and essentially all of what is described below is his work. This is a hard
fork of it, run on k3s for a single household, that has deliberately drifted from upstream
and will keep drifting.

It is not a community distribution, a rewrite, or an attempt to replace upstream. If you
want grocy, [use grocy](https://grocy.info) — it is excellent and actively maintained.

## What this fork changes

| | Status |
|---|---|
| **PostgreSQL support** alongside SQLite — typed baseline schema, ported views and triggers, differential tests proving the two engines agree | **done**, see [db/pgsql/README.md](db/pgsql/README.md) |
| **File storage in the database**, so the container needs no persistent volume | [plan 01](docs/plans/01-file-storage.md) |
| **MCP endpoint**, so an assistant can answer "what is expiring this week" | [plan 02](docs/plans/02-mcp-endpoint.md) |
| **Deeply nested locations and products** — hierarchies more than one level deep | [plans 07](docs/plans/07-nested-products.md), [08](docs/plans/08-nested-locations.md) |
| **Location barcodes**, aimed at camera-based inventory rather than phone scanning | [plan 06](docs/plans/06-location-barcodes.md) |
| Store-specific shopping lists, category-level minimum stock, seed datasets, US barcode lookup | [plans 03–05, 09](docs/plans/README.md) |
| **Hardening** — cold-start and statelessness for scale-to-zero pods, API error handling, write-path transactions, contract tests | [plans 10–15](docs/plans/README.md) |

The deployment target is immutable, scale-to-zero pods on k3s, which is what motivates
most of the divergence: PostgreSQL instead of a SQLite file, files in the database instead
of on a volume, migrations in an init step instead of on the first web request.

**Start here:** [docs/plans/README.md](docs/plans/README.md) for the roadmap and the ground
rules every change is held to, and
[docs/architecture-review.md](docs/architecture-review.md) for an honest assessment of the
codebase as it stands.

## Questions / Help / Bug Reports / Feature Requests

- **Using grocy** — [r/grocy on Reddit](https://www.reddit.com/r/grocy) and
  [grocy.info](https://grocy.info). Upstream's resources cover everything this fork shares
  with grocy, which is most of it
- **A bug in grocy itself** — [upstream's issue
  tracker](https://github.com/grocy/grocy/issues/new/choose). Reproduce it on the
  [upstream demo](https://demo-prerelease.grocy.info) first
- **A bug in something this fork added** — this repository's issue tracker. PostgreSQL and
  anything from [docs/plans/](docs/plans/README.md) is fork-only and will never reproduce
  upstream
- **A security issue** — see [SECURITY.md](.github/SECURITY.md). Unlike upstream, issues
  requiring authentication are in scope here, because this fork's threat model includes a
  multi-user household

Contributions are welcome, AI-assisted ones included — see
[CONTRIBUTING.md](.github/CONTRIBUTING.md).

## Give it a try

There is no demo of this fork. Upstream's demos show the shared feature set:

- Latest stable &rarr; [https://demo.grocy.info](https://demo.grocy.info)
- Development version &rarr; [https://demo-prerelease.grocy.info](https://demo-prerelease.grocy.info)

## How to install

Victual is technically a pretty simple PHP application:

- Clone this repository and install Composer and Yarn dependencies
- Copy `config-dist.php` to `data/config.php` and edit to your needs
- Ensure that the `data` directory is writable
- The webserver root should point to the `public` directory
- Include `try_files $uri /index.php$is_args$query_string;` in your location block if you
  use nginx, or disable URL rewriting (see `DISABLE_URL_REWRITING` in `data/config.php`)
- &rarr; Default login is user `admin` with password `admin`. **Change it immediately**
  (user menu, top right)

### PostgreSQL

Set `DB_DRIVER` to `pgsql` in `data/config.php` along with the `DB_*` connection settings.
A fresh, empty database is a valid target — the schema is created on first migration from
a squashed baseline rather than by replaying grocy's SQLite migration history.

To move an existing SQLite installation across, use `bin/victual-db-import`, which preserves
row ids exactly. See [db/pgsql/README.md](db/pgsql/README.md) for the porting rules, the
fifteen documented type-coercion hazards, and the two accepted behavioural differences
between the engines.

### Platform support

- PHP 8.5 — the declared floor; the real language floor is 8.4 and reconciling the two is
  [plan 15](docs/plans/15-deliberate-cleanup.md)
  - Required extensions: `fileinfo`, `gd`, `ctype`, `intl`, `zlib`, `mbstring`
  - Plus `pdo_sqlite` (SQLite 3.40+) or `pdo_pgsql`. Both are currently required
    regardless of driver; making that conditional is
    [plan 10](docs/plans/10-cold-start-statelessness.md)
- Recent Firefox, Chrome or Edge

### Docker

Upstream images (e.g. [linuxserver/grocy](https://hub.docker.com/r/linuxserver/grocy)) run
upstream grocy, not this fork, and predate everything in the table above.

## How to update

This fork tracks no release schedule. Pull, then check `config-dist.php` for new
configuration options — values not set in `data/config.php` fall back to the defaults
there.

`update.sh` is upstream's release-based updater and is not the path used here.

## Localization

Victual is fully localizable; the default language is English, integrated into the code.
Translations come from upstream's [Transifex
project](https://explore.transifex.com/grocy/grocy/) — strings this fork adds are not
there.

The default language can be set in `data/config.php`, e.g.
`Setting('DEFAULT_LOCALE', 'de');`, and there is a per-user setting on the user settings
page.

_RTL languages are not yet supported._

## Things worth to know

Everything in this section describes behaviour shared with upstream grocy unless noted.

### REST API

See the integrated Swagger UI instance at `/api`.

The web frontend uses exactly this API for pretty much everything, so everything you can
do there is also possible via the API.

Note for this fork: nearly every response is a database row serialised as-is, which makes
the schema the wire contract. Existing endpoints keep their response shape, and anything
that would change one is called out explicitly — see the ground rules in
[docs/plans/README.md](docs/plans/README.md).

### Barcode readers & camera scanning

Some fields (with a barcode icon) also allow to select a value by scanning a barcode. It
works best when your barcode reader prefixes every barcode with a letter which is normally
not part of an item name (`$` works well) and sends a `TAB` after a scan.

It is also possible to use your device camera via the camera button on the right side of
the corresponding input field (powered by [ZXing](https://github.com/zxing-js/library),
entirely client-side). Due to browser security restrictions this only works when serving
over `https://`.

A USB barcode laser scanner works considerably better — faster, under any lighting, from
any angle.

### Barcode lookup via external services

Products can be added directly by looking them up against external services by barcode,
via the product picker workflow "External barcode lookup". A plugin for [Open Food
Facts](https://world.openfoodfacts.org/) is included and used by default (see
`STOCK_BARCODE_LOOKUP_PLUGIN`). See `plugins/DemoBarcodeLookupPlugin.php` for a commented
example if you want to build your own.

US product coverage is poor in Open Food Facts, which is what
[plan 09](docs/plans/09-barcode-lookup-sources.md) is about.

### Input shorthands for date fields

All date and time fields use ISO-8601 regardless of localization. The following shorthands
are available:

- `MMDD` &rarr; that day this year if it is still ahead, otherwise next year
  - `0517` becomes `2026-05-17`
- `YYYYMMDD` &rarr; proper ISO-8601 notation
  - `20260417` becomes `2026-04-17`
- `YYYYMMe` or `YYYYMM+` &rarr; end of that month
  - `202607e` becomes `2026-07-31`
- `[+/-]n[d/m/y]` &rarr; relative to today, adding (**+**) or subtracting (**-**) that
  **n**umber of **d**ays/**m**onths/**y**ears
  - `+1m` becomes the same day next month
- `x` &rarr; `2999-12-31`, the alias for "never overdue"
- Down/up arrows change the date by 1 day, right/left by 1 week; with Shift, by 1 month
  and 1 year respectively

### Keyboard shorthands for buttons

Wherever a button contains a bold highlighted letter, that is its shortcut key. Button
"**P** Add as new product" can be pressed with the `P` key.

### Installable web app (PWA)

The web frontend is responsive and an installable web app
([PWA](https://en.wikipedia.org/wiki/Progressive_web_app), with no offline capability),
which gives a fairly native mobile experience without additional tools.

### Database migrations

Schema migration currently runs when visiting the root (`/`) route, and is triggered
automatically when the version changes.

**This is changing in this fork.** Running migrations inside a web request does not
survive a scale-to-zero deployment: on an ephemeral filesystem it happens on every cold
start, and two pods starting together race with no lock.
[Plan 10](docs/plans/10-cold-start-statelessness.md) moves migration to a CLI entry point
under a cross-process lock, suitable for an initContainer.

Migrations are supposed to work between releases, not between every commit.

### Disable certain features

Feature flags per major feature set hide and disable the related UI — see
`config-dist.php`. Useful if you do not use Chores, for instance.

### Adding your own CSS or JS

- If `data/custom_js.html` exists, its contents are added just before `</body>` on every page
- If `data/custom_css.html` exists, its contents are added just before `</head>` on every page

### Demo mode

When `MODE` is set to `dev`, `demo` or `prerelease`, the application runs in demo mode:
**authentication is disabled** and demo data is generated during schema migration (pass
`?nodemodata` to skip that). Demo data generation is SQLite-only in this fork and is
skipped on PostgreSQL.

### Embedded mode

When the file `embedded.txt` exists it must contain a valid, writable path, used as the
data directory instead of `data`; authentication is disabled. Settings can be overridden
by text files in `data/settingoverrides`, named `<SettingName>.txt`.

## Roadmap

Upstream has none by policy. This fork does: [docs/plans/](docs/plans/README.md), one
document per work package, each with numbered open questions so individual decisions can
be argued with rather than accepted wholesale.

## Screenshots

### Stock overview

![Stock overview](https://github.com/grocy/grocy/raw/master/.github/publication_assets/stock.png "Stock overview")

### Shopping List

![Shopping List](https://github.com/grocy/grocy/raw/master/.github/publication_assets/shoppinglist.png "Shopping List")

### Meal Plan

![Meal Plan](https://github.com/grocy/grocy/raw/master/.github/publication_assets/mealplan.png "Meal Plan")

### Chores overview

![Chores overview](https://github.com/grocy/grocy/raw/master/.github/publication_assets/chores.png "Chores overview")

## Motivation

Upstream's, in Bernd Bestel's words:

> A household needs to be managed. Before Grocy I did this (for almost 10 years) using my
> first self written software (a C# Windows forms application) and with a bunch of Excel
> sheets. The software was a pain to use at the end and Excel is Excel. So I searched for
> and tried different things for a (very) long time, nothing 100 % fitted, so this is my
> aim for a "complete household management"-thing. ERP your fridge!

This fork's motivation is narrower: run that on k3s properly, on PostgreSQL, with
hierarchies deep enough for a real pantry.

If grocy is useful to you, [say thanks to Bernd](https://grocy.info/#say-thanks) — this
fork adds nothing to it that is worth paying for.

## License

Two licenses apply, depending on which part of the code is in question:

- **Code originating from upstream grocy** — MIT, © Bernd Bestel
- **Changes and additions made in this fork** — BSD 3-Clause, © Steven Peterson

Both texts, and how they interact, are in [LICENSE.md](LICENSE.md).
