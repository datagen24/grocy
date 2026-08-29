# Coverage

What the differential test suite actually reaches.

```sh
SUITE_COVERAGE=1 .devtools/pgsql/run-tests.sh
```

The run prints a per-class summary at the end and nothing else changes. Add
`SUITE_COVERAGE_CLOVER=clover.xml` to also write Clover XML, which is what CI keeps as an
artifact.

## What the number means

It is line coverage of `services/`, `controllers/`, `helpers/`, `middleware/`, `plugins/`
and the three top-level PHP files, by a suite that is not a unit test suite. Two phases
drive SQL straight at each engine and never enter PHP application code at all; the third
goes through `StockService`. So most controllers are at zero by design, and the total is
low for a reason that is not a quality judgement.

Read it as a map, not a score. `StockService` sitting around a third means the stock write
paths are exercised; that figure falling means a phase stopped reaching something it used
to, which is the failure this exists to make visible. Nothing is gated on the number — a
threshold nobody chose is a threshold that gets lowered until it stops failing, and then
deleted. `report.php` takes `--min=NN` if a future change wants one deliberately.

## How it is wired

The suite is a couple of dozen short-lived PHP processes: `difftest.php` once per seed,
`trigdifftest.php`, `rollback-tests.php` once per engine, `bin/grocy-migrate` and
`bin/grocy-db-import` several times each. Rather than editing each call site — which means
remembering to edit the next one too — `run-tests.sh` writes a throwaway `php.ini`
fragment setting `auto_prepend_file` and puts it on `PHP_INI_SCAN_DIR`. Every PHP process
the run spawns then loads `prepend.php` first.

- **`prepend.php`** starts a line-coverage driver and registers a shutdown handler that
  writes one `.cov` file named for the process. It returns immediately when
  `GROCY_COVERAGE_DIR` is unset, so an ordinary run is untouched: no driver, no autoloader,
  no handler.
- **`report.php`** merges every `.cov` in the directory — no single process can know it is
  the last one — and prints the summary. It is run with `GROCY_COVERAGE_DIR` unset so it
  does not measure itself into the directory it is reading.

The driver is [pcov](https://github.com/krakjoe/pcov): line coverage only, which is all
this needs, and fast enough that the suite's runtime does not visibly change. Xdebug
satisfies the same check if it is what you have. The `Dockerfile` installs pcov and CI asks
`shivammathur/setup-php` for it; on a host PHP, `pecl install pcov` and enable it.

The merging and reporting is `phpunit/php-code-coverage`, a `require-dev` dependency. The
library works standalone — PHPUnit is not installed and is not needed.
