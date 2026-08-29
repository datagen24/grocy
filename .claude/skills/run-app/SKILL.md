---
name: run-app
description: Boot this app locally (PHP built-in server, SQLite demo mode) and drive it with Playwright for screenshots. Use when asked to run, start, boot, or screenshot the app, or to verify a change on a running instance.
---

# Run the app locally

Verified cold-start from a fresh Linux container (Claude Code web session,
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
('8.5.0'). On a container with PHP 8.4, temporarily lower it:

```bash
sed -i "s/const REQUIRED_PHP_VERSION = '8.5.0';/const REQUIRED_PHP_VERSION = '8.4.0';/" helpers/PrerequisiteChecker.php
```

**Revert before committing anything** — this is a local run hack, never
repo state:

```bash
git checkout helpers/PrerequisiteChecker.php
```

## 4. Data directory and boot

```bash
mkdir -p data && cp config-dist.php data/config.php
VICTUAL_MODE=demo VICTUAL_DATAPATH=$PWD/data php -S 127.0.0.1:8085 -t public > /tmp/php-server.log 2>&1 &
sleep 2 && curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8085/
```

Demo mode seeds a SQLite database (`data/victual_en.db`) with sample data and
auto-logs-in as "Demo User" — no credentials needed. First `GET /` runs
migrations and demo generation, then 302s to the entry page.

**If a page later 500s with `no such table: migrations`** (a race on the
very first boot), run migrations directly and retry:

```bash
VICTUAL_MODE=demo VICTUAL_DATAPATH=$PWD/data php bin/victual-migrate
```

Smoke check — expect 200 with a large HTML body:

```bash
curl -s -o /dev/null -w "%{http_code} %{size_download}\n" http://127.0.0.1:8085/stockoverview
```

## 5. Screenshots (Playwright)

Chromium is pre-installed at `/opt/pw-browsers/chromium`; do NOT run
`playwright install`. `playwright-core` is not in this repo's
`package.json` — install it in the scratchpad, not here:

```bash
cd "$SCRATCHPAD" && npm init -y >/dev/null && npm i playwright-core >/dev/null
```

```js
const { chromium } = require('playwright-core');
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
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
php -r '$d = new PDO("sqlite:data/victual_en.db");
  $d->exec("UPDATE recipes SET picture_file_name = NULL");
  $d->exec("UPDATE products SET picture_file_name = NULL");'
find data/storage -type f -size 0 -delete
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
