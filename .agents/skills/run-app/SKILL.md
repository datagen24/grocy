---
name: run-app
description: Boot this app locally (PHP built-in server, SQLite demo mode) and drive it with Playwright for screenshots. Use when asked to run, start, boot, or screenshot the app, or to verify a change on a running instance.
---

# Run the app locally

Verified cold-start from a fresh Linux container (Codex web session,
2026-08). Total time ~2 minutes. All commands from the repo root.

## 1. PHP dependencies

```bash
composer install --no-interaction --ignore-platform-req=php
```

`--ignore-platform-req=php` because containers commonly ship PHP 8.4 while
`composer.json` pins 8.5.*. The dependencies themselves work fine on 8.4.

## 2. Frontend packages

```bash
yarn install --frozen-lockfile
```

The views load CSS/JS from `/packages/...`, and `.yarnrc` already sets
`--modules-folder public/packages`, so yarn installs straight there — there
is no `node_modules` and no symlink to make. If a stale `public/packages`
symlink exists from an earlier session, yarn fails with
`EEXIST: file already exists, mkdir '.../public/packages'`; `rm -f
public/packages` and re-run. Without the packages the app boots unstyled.

## 3. PHP version gate (only if `php -v` < 8.5)

`helpers/PrerequisiteChecker.php` hard-fails below `REQUIRED_PHP_VERSION`
('8.5.0'). On a container with PHP 8.4, temporarily lower it — saving the
file's exact prior state first, so the restore cannot discard uncommitted
local edits the way a `git checkout` would:

```bash
cp helpers/PrerequisiteChecker.php /tmp/PrerequisiteChecker.php.orig
sed -i "s/const REQUIRED_PHP_VERSION = '8.5.0';/const REQUIRED_PHP_VERSION = '8.4.0';/" helpers/PrerequisiteChecker.php
```

**Restore before committing anything** — this is a local run hack, never
repo state, and the restore puts back whatever was there before, local
edits included:

```bash
mv /tmp/PrerequisiteChecker.php.orig helpers/PrerequisiteChecker.php
```

## 4. Data directory and boot

Use a throwaway data directory — never `./data`, which may hold a real
local `config.php` and database that an unconditional copy would destroy:

```bash
export VDATA=$(mktemp -d)
cp config-dist.php "$VDATA/config.php"
VICTUAL_MODE=demo VICTUAL_DATAPATH="$VDATA" php bin/victual-migrate
VICTUAL_MODE=demo VICTUAL_DATAPATH="$VDATA" php -S 127.0.0.1:8085 -t public > /tmp/php-server.log 2>&1 &
sleep 2 && curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8085/
```

Migrate first: a request no longer migrates the database unless
`MIGRATE_ON_ROOT_REQUEST` is on, and an application whose schema is behind
its code answers **503** with a message saying exactly this (plan 10). The
alternative is `VICTUAL_MIGRATE_ON_ROOT_REQUEST=true` in the environment of
both commands, which restores the old "just hit the page" behaviour.

Demo mode seeds a SQLite database (`$VDATA/victual_en.db`) with sample data and
auto-logs-in as "Demo User" — no credentials needed. The first `GET /` generates
the demo data, then 302s to the entry page.

Smoke check — expect 200 with a large HTML body:

```bash
curl -s -o /dev/null -w "%{http_code} %{size_download}\n" http://127.0.0.1:8085/stockoverview
```

## 5. Screenshots (Playwright)

`playwright-core` is not in this repo's `package.json` — install it in a
throwaway directory, not here, and point it at whatever Chromium the
machine actually has (`playwright-core` bundles no browser):

```bash
WORK=$(mktemp -d) && cd "$WORK" && npm init -y >/dev/null && npm i playwright-core >/dev/null
export CHROME_BIN=$(command -v chromium || command -v chromium-browser || echo /opt/pw-browsers/chromium)
```

The `/opt/pw-browsers/chromium` fallback is where Anthropic agent
containers pre-install it (there, do NOT run `playwright install`). On any
other machine, set `CHROME_BIN` to a real Chrome/Chromium binary or this
section does not apply.

```js
const { chromium } = require('playwright-core');
(async () => {
  const browser = await chromium.launch({ executablePath: process.env.CHROME_BIN, args: ['--no-sandbox'] });
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
  await page.goto('http://127.0.0.1:8085/stockoverview', { waitUntil: 'networkidle' });
  await page.screenshot({ path: 'fullpage.png' });
  await page.locator('#mainNav').screenshot({ path: 'navbar.png' });  // navbar only
  await browser.close();
})();
```

Look at the screenshot after taking it — a blank frame means the boot or
step 2 failed.

**Demo pictures do not load in this environment.** Demo generation fetches
product and recipe photos from `releases.grocy.info`, which the agent proxy
denies (403 on CONNECT), and `DownloadFileIfNotAlreadyExists` writes a
0-byte file instead of failing — so recipe thumbnails render as broken-image
icons on `/mealplan`. For presentable screenshots, clear the references
first:

```bash
php -r '$d = new PDO("sqlite:" . getenv("VDATA") . "/victual_en.db");
  $d->exec("UPDATE recipes SET picture_file_name = NULL");
  $d->exec("UPDATE products SET picture_file_name = NULL");'
find "$VDATA/storage" -type f -size 0 -delete
```

Check for the problem rather than assuming: after loading a page,
`document.images` filtered on `complete && naturalWidth === 0` lists what
failed. One hit is expected and harmless — `#productcard-product-picture`
is a `d-none` template element.

## Variants

- **Dev mode instead of demo data**: `VICTUAL_MODE=dev` — empty database,
  auth also bypassed (user id 1).
- **PostgreSQL**: `docker-compose.yml` has a PostgreSQL service and the
  `DB_*` settings in `data/config.php` switch the driver; SQLite demo mode
  is the fastest path for UI checks and needs no services.
