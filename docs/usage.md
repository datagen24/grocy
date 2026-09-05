# Installation and usage

Setup, configuration, and user-facing features. For project goals and development status,
see the [root README](../README.md). Commands below run from the repository root.

## How to install

Install from a checkout on a web server or use the production container images.

### From a checkout

From the repository root:

- Clone this repository and install Composer and Yarn dependencies
- Copy `config-dist.php` to `data/config.php` and edit to your needs
- Ensure that the `data` directory is writable
- Run `php bin/victual-migrate` to bring the schema up to date
- The webserver root should point to the `public` directory
- Include `try_files $uri /index.php$is_args$query_string;` in your location block if you
  use nginx, or disable URL rewriting (see `DISABLE_URL_REWRITING` in `data/config.php`)
- &rarr; Default login is user `admin` with password `admin`. **Change it immediately**
  (user menu, top right)

### Container images

Production images are built by Nix from [`flake.nix`](../flake.nix) and [`nix/`](../nix/README.md),
one image per workload, from `scratch` — no base image, no shell, no package manager, all
three running as uid 65532:

- `.#image-app` — php-fpm on loopback:9000
- `.#image-web` — nginx on :8080, holding `public/` and the yarn-built assets and no PHP
- `.#image-migrate` — `bin/victual-migrate`, run as a Job or an initContainer

`nix run .#load` builds and loads all three; on macOS that needs a Linux builder, which
[`nix/build-in-podman.sh`](../nix/build-in-podman.sh) provides.
[`deploy/podman/victual.yaml`](../deploy/podman/victual.yaml) is the pod — migrate
initContainer, php-fpm, nginx — and [deploy/README.md](../deploy/README.md) has the
bootstrap, including the ConfigMap and Secret shapes it expects.

The root [`Dockerfile`](../Dockerfile) builds the development and CI image. Production
builds use Nix under [ADR-0013](adr/0013-nix-built-container-images.md).

### PostgreSQL

`DB_DRIVER` is `pgsql` and there is no other value:
[ADR-0008](adr/0008-postgresql-only-runtime-engine.md) made PostgreSQL the sole runtime
engine, and its retirement has landed. Set the `DB_*` connection settings in
`data/config.php` (or as `VICTUAL_DB_*` environment variables). A fresh, empty database is a
valid target — the schema is created on first migration from a squashed baseline rather than
by replaying grocy's SQLite migration history.

An installation whose `config.php` still says `sqlite` is refused at startup, with the
command that moves it across in the message.

### Moving an existing SQLite installation across

```
php bin/victual-migrate            # create the schema in the empty PostgreSQL database
php bin/victual-db-import /path/to/victual.db --force
```

`bin/victual-db-import` preserves row ids exactly. It accepts a grocy or Victual SQLite
database whose schema is between migrations **0255 and 0265** inclusive and refuses anything
outside that span, naming both numbers. 0255 is where upstream grocy 4.x stops, so a database
from a grocy installation qualifies once that installation has been started once on the
version that wrote it; 0265 is the last migration the SQLite line will ever have.

Two things the import does that a migration cannot, because the target is migrated before the
rows arrive: it runs the HTML purifier over the five rich-text columns, and it replaces any
plaintext API key with its hash. Calendar sharing keys stay readable, as they are in an
in-place upgrade.

See [db/pgsql/README.md](../db/pgsql/README.md) for the porting rules, the seventeen
documented porting hazards and the two accepted behavioural differences between the
engines.

### File storage

`FILE_STORAGE` decides where uploaded files — product pictures, recipe pictures, userfiles,
userentity pictures, manuals — are kept. The default, `filesystem`, puts them below
`<data path>/storage`. Setting it to `database` stores them as `BYTEA` rows instead, so the
application directory needs no persistent volume at all and one `pg_dump` captures a file
and the row pointing at it together. It requires `DB_DRIVER=pgsql` and is refused in
demo/prerelease mode; both are checked at startup.

Files already on disk are not read after the switch — run `php bin/victual-files-import`
once to move them in, and `php bin/victual-files-import --verify` before removing the old
storage directory. `FILE_STORAGE_MAX_SIZE_MB` bounds an upload for both backends.

### Home Assistant

Victual can push the household's ambient state to an MQTT broker as retained topics, with
Home Assistant discovery payloads alongside them, so nothing has to poll: a consumer holds
the last snapshot across its own restarts and arbitrarily long server absences. Off by
default — set `MQTT_ENABLED` and the `MQTT_*` connection settings in `data/config.php`; the
block there documents each one.

Run `bin/victual-publish-state` once after every deployment (a postStart hook, or a Job
alongside the `bin/victual-migrate` initContainer). It publishes the discovery payloads and
the full snapshot, which is what makes a migration, an import or someone in `psql` get
picked up rather than silently diverging from what the broker still holds.
`bin/victual-publish-state --retract` clears every retained topic again.

Seven summary sensors are published by default. A product also gets its own sensor when it
is opted in — `POST /api/objects/mqtt_product_entities` with `{"product_id": <id>}`, and
`DELETE` to remove it, which retracts that product's topics.

Optionally, `INFLUXDB_ENABLED` and the `INFLUXDB_*` settings write price and stock-value
*events* to InfluxDB on the same after-commit path. That channel, not MQTT, is where
spending history lives: anything holding broker credentials reads the MQTT topics without
authenticating to Victual, so no price, cost or value field is published there.

Both publishes happen after the request is finished, bounded by
`MQTT_CONNECT_TIMEOUT_SECONDS` and `INFLUXDB_TIMEOUT_SECONDS`, and a failure of either is
logged and never reaches the write that triggered it. The delay is off the response under
php-fpm and on it under mod_php — so on mod_php an unreachable broker and an unreachable
InfluxDB cost the caller the sum of those two timeouts.

### Platform support

- PHP 8.5 — what `composer.json` declares; the real language floor is 8.4 and reconciling
  the two is [plan 15](plans/15-deliberate-cleanup.md)
  - Required extensions: `fileinfo`, `gd`, `ctype`, `intl`, `zlib`, `mbstring`
  - Plus `pdo_pgsql`. `pdo_sqlite` is needed only by `bin/victual-db-import` and by the
    differential suite, which is why the Nix migrate image carries it and the serving
    images do not
- Recent Firefox, Chrome or Edge

## How to update

This fork tracks no release schedule. Pull, then check `config-dist.php` for new
configuration options — values not set in `data/config.php` fall back to the defaults
there. Run `php bin/victual-migrate` afterwards; a deployment does that in its init step
instead.

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

## Using Victual

Everything in this section describes behaviour shared with upstream grocy unless noted.

### REST API

See the integrated Swagger UI instance at `/api`.

The frontend uses the REST API for writes, but several pages read the database directly.
Some reports therefore have no equivalent API response yet. [Plan 14](plans/14-contract-and-regression-scaffolding.md)
tracks those gaps. Existing response contracts are governed by
[ADR-0005](adr/0005-wire-contract-is-the-invariant.md).

The [parity suite](../.devtools/parity/README.md) compares Victual with grocy 4.6.0 over
HTTP. Its accepted-difference records identify intentional contract changes and their
supporting decisions.

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
[plan 09](plans/09-barcode-lookup-sources.md) is about.

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

`php bin/victual-migrate` brings the schema up to date and
exits; on PostgreSQL it serialises concurrent runs on a session-level advisory lock, so
two pods starting together are safe. An application that finds the schema out of date
refuses to serve rather than guessing.

For installations without a deployment init step, `MIGRATE_ON_ROOT_REQUEST` enables
migration through the root route. It is off by default; use the CLI for deployments.

The advisory lock lives on the connection that took it, so `bin/victual-migrate` has to
reach PostgreSQL over a direct connection or a session-mode pool entry — behind a
transaction-mode pooler the unlock can land on a different backend and leak permanently.

Migrations are supposed to work between releases, not between every commit.

### Disable certain features

Feature flags per major feature set hide and disable the related UI — see
`config-dist.php`. Useful if you do not use Chores, for instance.

### Adding your own CSS or JS

- If `data/custom_js.html` exists, its contents are added just before `</body>` on every page
- If `data/custom_css.html` exists, its contents are added just before `</head>` on every page

### Demo mode

When `MODE` is set to `dev`, `demo` or `prerelease`, the application runs in demo mode:
**authentication is disabled** and demo data is generated on a request to the root (`/`)
route (pass `?nodemodata` to skip that). It runs against the configured PostgreSQL database
like everything else; it used to be skipped on any driver but SQLite, which meant a
PostgreSQL demo instance was an empty one.

### Configuration outside `config.php`

Every `Setting()` in `config-dist.php` can be set without editing a file, in this order of
precedence: a `<SettingName>.txt` file in `<data path>/settingoverrides`, then a
`VICTUAL_<SettingName>` environment variable, then the default. `VICTUAL_DATAPATH` moves
the data directory itself.

This is how the container images are configured — see the ConfigMap and Secret in
[deploy/README.md](../deploy/README.md), which set `VICTUAL_DB_*`, `VICTUAL_FILE_STORAGE` and
the rest rather than shipping a `config.php`.

### Embedded mode

When the file `embedded.txt` exists it must contain a valid, writable path, used as the
data directory instead of `data`; authentication is disabled.

