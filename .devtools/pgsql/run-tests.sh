#!/usr/bin/env bash
#
# The differential test suite: does this fork behave identically on SQLite and
# PostgreSQL?
#
#   .devtools/pgsql/run-tests.sh [views|triggers|rollback]
#
# Three kinds of check, for three reasons. Views are compared by what they return, because
# that is all a view is. Triggers cannot be compared that way — what a trigger does is
# change other rows — so those scripts are applied to both engines and every table is
# compared afterwards.
#
# The third asks something the first two cannot. Both of them drive SQL straight at each
# engine and never enter the application, so neither would notice if a write path stopped
# being transactional. The rollback tests go through StockService, fail an operation
# halfway, and check the ledger is where it started — on each engine in turn rather than
# against the other.
#
# This script is deliberately thin: it builds the databases, loops, and collects exit
# codes. Everything that has to decide whether two result sets are the same is PHP, in
# difftest.php and trigdifftest.php, which share their normalisation rules with the
# application through Grocy\Services\Database\ValueComparison.
#
# Connection settings come from the environment. The two PHP tools were written with
# disjoint variable namespaces (DIFFTEST_* and TRIGTEST_*) and this is where they are
# reconciled onto one set, so that running the suite is a command rather than a recipe.
#
#   PGHOST, PGPORT, PGUSER, PGPASSWORD   PostgreSQL connection (libpq's own names)
#   SUITE_PGSQL_VIEW_DB                  database for the view tests   (default grocy_full)
#   SUITE_PGSQL_TRIGGER_DB               database for the trigger tests (default grocy_trig)
#   SUITE_PGSQL_ROLLBACK_DB              database for the rollback tests (default grocy_rollback)
#   SUITE_SCRATCH                        where the throwaway databases go
#   SUITE_COVERAGE                       set to 1 to measure line coverage of the run
#   SUITE_COVERAGE_DIR                   where the coverage data goes (default under SUITE_SCRATCH)
#   SUITE_COVERAGE_CLOVER                also write a Clover XML report to this path
#
# The coverage variables are documented in full in .devtools/coverage/README.md.
#
# Under docker compose all of these are already set; see docker-compose.yml.

set -euo pipefail

GROCY_ROOT="${GROCY_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
export GROCY_ROOT

SUITE_DIR="$GROCY_ROOT/.devtools/pgsql"
SUITE_SCRATCH="${SUITE_SCRATCH:-${TMPDIR:-/tmp}/grocy-suite}"

PGHOST="${PGHOST:-127.0.0.1}"
PGPORT="${PGPORT:-5432}"
PGUSER="${PGUSER:-grocy}"
PGPASSWORD="${PGPASSWORD:-grocy}"
export PGHOST PGPORT PGUSER PGPASSWORD

VIEW_DB="${SUITE_PGSQL_VIEW_DB:-grocy_full}"
TRIGGER_DB="${SUITE_PGSQL_TRIGGER_DB:-grocy_trig}"
ROLLBACK_DB="${SUITE_PGSQL_ROLLBACK_DB:-grocy_rollback}"

WHICH="${1:-all}"

say() { printf '%s\n' "$*"; }

fail() { printf '%s\n' "$*" >&2; exit 1; }

command -v php >/dev/null || fail 'php not found on PATH'
[ -f "$GROCY_ROOT/packages/autoload.php" ] || fail 'packages/ is missing — run composer install first'

mkdir -p "$SUITE_SCRATCH"

# --- Coverage ---------------------------------------------------------------------
#
# The suite is a dozen short-lived PHP processes, so it is hooked at the interpreter
# rather than at each call site: an extra ini directory sets auto_prepend_file, and
# .devtools/coverage/prepend.php starts a driver in every process that then runs. Nothing
# below this point knows coverage exists, which is the point — a phase added later is
# measured without being told to be.
#
# The leading colon in PHP_INI_SCAN_DIR means "the usual directory, and then this one", so
# the platform's own extension ini files still load.

COVERAGE_DIR=""

if [ "${SUITE_COVERAGE:-0}" = "1" ]; then
	COVERAGE_DIR="${SUITE_COVERAGE_DIR:-$SUITE_SCRATCH/coverage}"

	php -r 'exit(extension_loaded("pcov") || extension_loaded("xdebug") ? 0 : 1);' \
		|| fail 'SUITE_COVERAGE=1 but neither pcov nor xdebug is loaded'

	# Cleared, not appended to: merging this run's data with the last one would report
	# lines as covered that this run never reached.
	rm -rf "$COVERAGE_DIR"
	mkdir -p "$COVERAGE_DIR"

	COVERAGE_INI_DIR="$SUITE_SCRATCH/coverage-ini"
	rm -rf "$COVERAGE_INI_DIR"
	mkdir -p "$COVERAGE_INI_DIR"

	cat > "$COVERAGE_INI_DIR/99-grocy-coverage.ini" <<-INI
		auto_prepend_file=$GROCY_ROOT/.devtools/coverage/prepend.php
		pcov.directory=$GROCY_ROOT
		pcov.enabled=1
	INI

	export PHP_INI_SCAN_DIR=":$COVERAGE_INI_DIR"
	export GROCY_COVERAGE_DIR="$COVERAGE_DIR"

	say "measuring coverage into $COVERAGE_DIR"
fi

# --- The pristine SQLite database -------------------------------------------------
#
# Built here rather than expected at an operator-known path, so that the suite has no
# prerequisite a clean checkout cannot satisfy. bin/grocy-migrate creates the schema;
# fixtures/00_base.sql adds the rows the tests refer to.

PRISTINE="$SUITE_SCRATCH/pristine.db"

build_pristine() {
	local datapath="$SUITE_SCRATCH/pristine-data"

	rm -rf "$datapath"
	mkdir -p "$datapath"

	GROCY_DATAPATH="$datapath" php "$GROCY_ROOT/bin/grocy-migrate" --quiet \
		|| fail 'could not migrate the pristine SQLite database'

	php "$SUITE_DIR/apply-sql.php" "sqlite:$datapath/grocy.db" "$SUITE_DIR/fixtures/00_base.sql" \
		|| fail 'could not apply the base fixture to the pristine database'

	mv "$datapath/grocy.db" "$PRISTINE"
	rm -rf "$datapath"
}

# --- The PostgreSQL side ----------------------------------------------------------
#
# Each target database is dropped and rebuilt from the migrations, so a run never
# inherits state from the last one. A suite that can pass because of what a previous
# run left behind is not measuring anything.

build_pgsql() {
	local dbname="$1"
	local datapath="$SUITE_SCRATCH/pg-data-$dbname"

	dropdb --if-exists "$dbname" || fail "could not drop $dbname"
	createdb "$dbname" || fail "could not create $dbname"

	rm -rf "$datapath"
	mkdir -p "$datapath"

	# The connection settings are read from the environment rather than interpolated
	# into the file. A password with a quote in it would otherwise produce a config.php
	# that is either broken or executing something it should not be, and the database
	# name is the only value this function actually chooses.
	cat > "$datapath/config.php" <<-'PHPCONFIG'
		<?php
		Setting('DB_DRIVER', 'pgsql');
		Setting('DB_HOST', getenv('PGHOST'));
		Setting('DB_PORT', intval(getenv('PGPORT')));
		Setting('DB_NAME', getenv('DIFFTEST_DB_NAME'));
		Setting('DB_USER', getenv('PGUSER'));
		Setting('DB_PASSWORD', getenv('PGPASSWORD'));
	PHPCONFIG

	GROCY_DATAPATH="$datapath" DIFFTEST_DB_NAME="$dbname" php "$GROCY_ROOT/bin/grocy-migrate" --quiet \
		|| fail "could not migrate $dbname"

	rm -rf "$datapath"
}

failures=0

# --- View tests -------------------------------------------------------------------
#
# Each seed declares the views it exercises in a leading "-- @views" comment, parsed in
# PHP rather than with grep so that the header is read the same way every time.

run_view_tests() {
	local seeds=("$SUITE_DIR"/view-tests/*.sql)

	if [ ! -e "${seeds[0]}" ]; then
		say 'no view seeds found'
		return 0
	fi

	build_pgsql "$VIEW_DB"

	export DIFFTEST_PGSQL_DSN="pgsql:host=$PGHOST;port=$PGPORT;dbname=$VIEW_DB"
	export DIFFTEST_PGSQL_USER="$PGUSER"
	export DIFFTEST_PGSQL_PASSWORD="$PGPASSWORD"

	for seed in "${seeds[@]}"; do
		local views
		views="$(php "$SUITE_DIR/seed-header.php" "$seed")" \
			|| fail "$(basename "$seed") has no @views header"

		# Every seed starts from the same pristine state, so a seed cannot pass
		# because of what the seed before it inserted.
		local sqlite_db="$SUITE_SCRATCH/difftest.db"
		cp "$PRISTINE" "$sqlite_db"
		export DIFFTEST_SQLITE_DSN="sqlite:$sqlite_db"

		say ""
		say "== $(basename "$seed")"

		# shellcheck disable=SC2086 -- the view list is deliberately word-split
		if ! php "$SUITE_DIR/difftest.php" "$seed" $views; then
			failures=$((failures + 1))
		fi
	done
}

# --- Rollback tests ---------------------------------------------------------------
#
# Unlike the two phases above, this one runs against one engine at a time: the question
# is whether a failed operation leaves that engine's ledger intact, which has no
# cross-engine comparison in it.

run_rollback_tests() {
	local datapath="$SUITE_SCRATCH/rollback-sqlite"
	local sqlite_db="$SUITE_SCRATCH/rollback-source.db"

	# SQLite first, from a fresh database with the base fixture, exactly as the pristine
	# database is built.
	rm -rf "$datapath"
	mkdir -p "$datapath"

	GROCY_DATAPATH="$datapath" php "$GROCY_ROOT/bin/grocy-migrate" --quiet \
		|| fail 'could not migrate the rollback test database'
	php "$SUITE_DIR/apply-sql.php" "sqlite:$datapath/grocy.db" "$SUITE_DIR/fixtures/00_base.sql" \
		|| fail 'could not apply the base fixture for the rollback tests'

	# Kept aside before the tests run, so PostgreSQL starts from the same rows rather
	# than from whatever the SQLite cases left behind.
	cp "$datapath/grocy.db" "$sqlite_db"

	say ""
	if ! GROCY_DATAPATH="$datapath" php "$SUITE_DIR/rollback-tests.php"; then
		failures=$((failures + 1))
	fi

	rm -rf "$datapath"

	# Then PostgreSQL, which is the half that was never covered before: the injector has
	# to be written twice because RAISE(ABORT) has no PostgreSQL equivalent outside a
	# function, and a rollback is exactly the sort of thing two engines can differ on.
	build_pgsql "$ROLLBACK_DB"

	local pgdatapath="$SUITE_SCRATCH/rollback-pgsql"
	rm -rf "$pgdatapath"
	mkdir -p "$pgdatapath"

	cat > "$pgdatapath/config.php" <<-'PHPCONFIG'
		<?php
		Setting('DB_DRIVER', 'pgsql');
		Setting('DB_HOST', getenv('PGHOST'));
		Setting('DB_PORT', intval(getenv('PGPORT')));
		Setting('DB_NAME', getenv('DIFFTEST_DB_NAME'));
		Setting('DB_USER', getenv('PGUSER'));
		Setting('DB_PASSWORD', getenv('PGPASSWORD'));
	PHPCONFIG

	# The PostgreSQL side is populated by importing the SQLite database just used, rather
	# than by applying the fixture again. That is the supported way a PostgreSQL
	# deployment gets its data, so it is the state worth testing against — and it avoids
	# depending on inserts behaving identically on both engines, which is a separate
	# question from whether a rollback works. (They do not, currently: applying the
	# fixture straight to PostgreSQL fails, because products_ins leaves
	# cache__quantity_unit_conversions_resolved empty there while SQLite's populates it.
	# Unrelated to this phase and recorded on its own.)
	DIFFTEST_DB_NAME="$ROLLBACK_DB" GROCY_DATAPATH="$pgdatapath" \
		php "$GROCY_ROOT/bin/grocy-db-import" "$sqlite_db" > /dev/null \
		|| fail 'could not import the rollback fixture into PostgreSQL'

	say ""
	if ! GROCY_DATAPATH="$pgdatapath" DIFFTEST_DB_NAME="$ROLLBACK_DB" php "$SUITE_DIR/rollback-tests.php"; then
		failures=$((failures + 1))
	fi

	rm -rf "$pgdatapath"
	rm -f "$sqlite_db"
}

# --- Trigger tests ----------------------------------------------------------------

run_trigger_tests() {
	local scripts=("$SUITE_DIR"/trigger-tests/*.sql)

	if [ ! -e "${scripts[0]}" ]; then
		say 'no trigger scripts found'
		return 0
	fi

	build_pgsql "$TRIGGER_DB"

	export TRIGTEST_SQLITE_PATH="$SUITE_SCRATCH/trigtest.db"
	export TRIGTEST_PRISTINE_PATH="$PRISTINE"
	export TRIGTEST_PGSQL_DSN="pgsql:host=$PGHOST;port=$PGPORT;dbname=$TRIGGER_DB"
	export TRIGTEST_PGSQL_USER="$PGUSER"
	export TRIGTEST_PGSQL_PASSWORD="$PGPASSWORD"

	if ! php "$SUITE_DIR/trigdifftest.php" "${scripts[@]}"; then
		failures=$((failures + 1))
	fi
}

# Before anything is built: a migration numbering mistake means the two engines are not
# running the same set of changes, which would make every comparison below meaningless
# rather than merely wrong.
say "checking migration numbering"
php "$SUITE_DIR/check-migrations.php" || fail 'migration numbering check failed'

say ""
say "building the pristine SQLite database"
build_pristine

case "$WHICH" in
	views) run_view_tests ;;
	triggers) run_trigger_tests ;;
	rollback) run_rollback_tests ;;
	all) run_view_tests; run_trigger_tests; run_rollback_tests ;;
	*) fail "unknown target: $WHICH (expected views, triggers, rollback or all)" ;;
esac

if [ -n "$COVERAGE_DIR" ]; then
	say ""
	say "== coverage"

	# Reported whether or not the suite passed: when a phase fails, what it did and did
	# not reach is part of reading the failure.
	report_args=("$COVERAGE_DIR")

	if [ -n "${SUITE_COVERAGE_CLOVER:-}" ]; then
		report_args+=("--clover=$SUITE_COVERAGE_CLOVER")
	fi

	# The report is itself a PHP process, and hooking it would have it measure its own
	# run and write a further .cov into the directory it is reading. Unsetting the
	# variable is enough — prepend.php returns immediately without it — and is what has
	# to be done rather than clearing PHP_INI_SCAN_DIR, which would also drop the
	# platform's own ini directory and with it every extension the report needs.
	env -u GROCY_COVERAGE_DIR php "$GROCY_ROOT/.devtools/coverage/report.php" "${report_args[@]}" \
		|| failures=$((failures + 1))
fi

say ""
if [ "$failures" -eq 0 ]; then
	say "SUITE PASSED"
	exit 0
fi

say "SUITE FAILED — $failures case(s) differ"
exit 1
