#!/usr/bin/env bash
#
# The differential test suite: does this fork behave identically on SQLite and
# PostgreSQL?
#
#   .devtools/pgsql/run-tests.sh [migrate|views|triggers|rollback|filter|files]
#
# Six kinds of check, for six reasons. Views are compared by what they return, because
# that is all a view is. Triggers cannot be compared that way — what a trigger does is
# change other rows — so those scripts are applied to both engines and every table is
# compared afterwards.
#
# Before either of those means anything, though, the two engines have to start from the
# same place. The migration check compares two databases that have been migrated and
# nothing else, which is precisely what the other three cannot see: every one of them
# populates PostgreSQL by copying an already-migrated SQLite database, so all of them
# start from a state that was constructed rather than migrated. That blind spot hid a
# real defect — the PostgreSQL baseline being schema only, so a fresh database had no
# admin user and no quantity units — so this phase runs first.
#
# The fourth asks something none of the others can. The first three drive SQL straight at
# each engine and never enter the application, so none would notice if a write path
# stopped being transactional. The rollback tests go through StockService, fail an
# operation halfway, and check the ledger is where it started — on each engine in turn
# rather than against the other.
#
# The fifth closes the gap the other four leave between them: application code that
# builds SQL differently per engine. The rollback phase enters the application but asks
# one engine at a time; the first three compare engines but never enter the application.
# Hazard 16 lived in exactly that hole — the "~" filter operator meant "case insensitive"
# on SQLite and "case sensitive" on PostgreSQL for as long as the controller spelled LIKE
# itself, with an identical response shape either way. The filter phase asks both engines
# the same question through the dialects and compares the rows.
#
# The sixth is not a comparison at all, and is here because there is nowhere better. The
# files table exists on PostgreSQL only — the configuration that reads it cannot exist on
# SQLite — so the one command that writes to it, bin/victual-files-import, has no engine
# to be compared against and had no coverage anywhere. What it needs asserting is that it
# can tell an imported file from a stale one, since an operator deletes the source volume
# on its say-so. Like the rollback phase it asks one engine a question.
#
# This script is deliberately thin: it builds the databases, loops, and collects exit
# codes. Everything that has to decide whether two result sets are the same is PHP, in
# difftest.php, trigdifftest.php and migratedifftest.php, which share their normalisation
# rules with the application through Victual\Services\Database\ValueComparison.
#
# Connection settings come from the environment. The two PHP tools were written with
# disjoint variable namespaces (DIFFTEST_* and TRIGTEST_*) and this is where they are
# reconciled onto one set, so that running the suite is a command rather than a recipe.
#
#   PGHOST, PGPORT, PGUSER, PGPASSWORD   PostgreSQL connection (libpq's own names)
#   SUITE_PGSQL_VIEW_DB                  database for the view tests    (default victual_full)
#   SUITE_PGSQL_TRIGGER_DB               database for the trigger tests (default victual_trig)
#   SUITE_PGSQL_MIGRATE_DB               database for the migration test (default victual_migrate)
#   SUITE_PGSQL_ROLLBACK_DB              database for the rollback tests (default victual_rollback)
#   SUITE_PGSQL_FILTER_DB                database for the filter tests  (default victual_filter)
#   SUITE_PGSQL_FILES_DB                 database for the file import tests (default victual_files)
#   SUITE_SCRATCH                        where the throwaway databases go
#   SUITE_COVERAGE                       set to 1 to measure line coverage of the run
#   SUITE_COVERAGE_DIR                   where the coverage data goes (default under SUITE_SCRATCH)
#   SUITE_COVERAGE_CLOVER                also write a Clover XML report to this path
#
# The coverage variables are documented in full in .devtools/coverage/README.md.
#
# Under docker compose all of these are already set; see docker-compose.yml.

set -euo pipefail

VICTUAL_ROOT="${VICTUAL_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
export VICTUAL_ROOT

SUITE_DIR="$VICTUAL_ROOT/.devtools/pgsql"
SUITE_SCRATCH="${SUITE_SCRATCH:-${TMPDIR:-/tmp}/victual-suite}"

PGHOST="${PGHOST:-127.0.0.1}"
PGPORT="${PGPORT:-5432}"
PGUSER="${PGUSER:-victual}"
PGPASSWORD="${PGPASSWORD:-victual}"
export PGHOST PGPORT PGUSER PGPASSWORD

VIEW_DB="${SUITE_PGSQL_VIEW_DB:-victual_full}"
TRIGGER_DB="${SUITE_PGSQL_TRIGGER_DB:-victual_trig}"
MIGRATE_DB="${SUITE_PGSQL_MIGRATE_DB:-victual_migrate}"
ROLLBACK_DB="${SUITE_PGSQL_ROLLBACK_DB:-victual_rollback}"
FILTER_DB="${SUITE_PGSQL_FILTER_DB:-victual_filter}"
FILES_DB="${SUITE_PGSQL_FILES_DB:-victual_files}"

WHICH="${1:-all}"

say() { printf '%s\n' "$*"; }

fail() { printf '%s\n' "$*" >&2; exit 1; }

command -v php >/dev/null || fail 'php not found on PATH'
[ -f "$VICTUAL_ROOT/packages/autoload.php" ] || fail 'packages/ is missing — run composer install first'

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

	cat > "$COVERAGE_INI_DIR/99-victual-coverage.ini" <<-INI
		auto_prepend_file=$VICTUAL_ROOT/.devtools/coverage/prepend.php
		pcov.directory=$VICTUAL_ROOT
		pcov.enabled=1
	INI

	export PHP_INI_SCAN_DIR=":$COVERAGE_INI_DIR"
	export VICTUAL_COVERAGE_DIR="$COVERAGE_DIR"

	say "measuring coverage into $COVERAGE_DIR"
fi

# --- The pristine SQLite database -------------------------------------------------
#
# Built here rather than expected at an operator-known path, so that the suite has no
# prerequisite a clean checkout cannot satisfy. bin/victual-migrate creates the schema;
# fixtures/00_base.sql adds the rows the tests refer to.

PRISTINE="$SUITE_SCRATCH/pristine.db"

# The same database one step earlier: migrated, and nothing else. That is what the
# migration phase compares against, and it has to be taken before the fixture goes in.
MIGRATED_ONLY="$SUITE_SCRATCH/migrated-only.db"

build_pristine() {
	local datapath="$SUITE_SCRATCH/pristine-data"

	rm -rf "$datapath"
	mkdir -p "$datapath"

	VICTUAL_DATAPATH="$datapath" php "$VICTUAL_ROOT/bin/victual-migrate" --quiet \
		|| fail 'could not migrate the pristine SQLite database'

	cp "$datapath/victual.db" "$MIGRATED_ONLY"

	php "$SUITE_DIR/apply-sql.php" "sqlite:$datapath/victual.db" "$SUITE_DIR/fixtures/00_base.sql" \
		|| fail 'could not apply the base fixture to the pristine database'

	mv "$datapath/victual.db" "$PRISTINE"
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

	VICTUAL_DATAPATH="$datapath" DIFFTEST_DB_NAME="$dbname" php "$VICTUAL_ROOT/bin/victual-migrate" --quiet \
		|| fail "could not migrate $dbname"

	rm -rf "$datapath"
}

failures=0

# --- Migration tests --------------------------------------------------------------
#
# Both databases are built by bin/victual-migrate and then left alone. Nothing is seeded
# into either side, because the question is what migrating alone produces — the state
# every other phase, and every real installation, starts from.

run_migration_tests() {
	build_pgsql "$MIGRATE_DB"

	export MIGRATEDIFF_SQLITE_PATH="$MIGRATED_ONLY"
	export MIGRATEDIFF_PGSQL_DSN="pgsql:host=$PGHOST;port=$PGPORT;dbname=$MIGRATE_DB"
	export MIGRATEDIFF_PGSQL_USER="$PGUSER"
	export MIGRATEDIFF_PGSQL_PASSWORD="$PGPASSWORD"

	say ""
	if ! php "$SUITE_DIR/migratedifftest.php"; then
		failures=$((failures + 1))
	fi
}

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
# Unlike the three phases above, this one runs against one engine at a time: the question
# is whether a failed operation leaves that engine's ledger intact, which has no
# cross-engine comparison in it.

run_rollback_tests() {
	local datapath="$SUITE_SCRATCH/rollback-sqlite"
	local sqlite_db="$SUITE_SCRATCH/rollback-source.db"

	# SQLite first, from a fresh database with the base fixture, exactly as the pristine
	# database is built.
	rm -rf "$datapath"
	mkdir -p "$datapath"

	VICTUAL_DATAPATH="$datapath" php "$VICTUAL_ROOT/bin/victual-migrate" --quiet \
		|| fail 'could not migrate the rollback test database'
	php "$SUITE_DIR/apply-sql.php" "sqlite:$datapath/victual.db" "$SUITE_DIR/fixtures/00_base.sql" \
		|| fail 'could not apply the base fixture for the rollback tests'

	# Kept aside before the tests run, so PostgreSQL starts from the same rows rather
	# than from whatever the SQLite cases left behind.
	cp "$datapath/victual.db" "$sqlite_db"

	say ""
	if ! VICTUAL_DATAPATH="$datapath" php "$SUITE_DIR/rollback-tests.php"; then
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
	# than by applying the fixture again. That is the supported way an existing
	# installation's data reaches PostgreSQL, so it is the state worth testing against —
	# and it keeps this phase's subject to rollback alone rather than also to whether
	# inserts behave identically on both engines, which the other three phases answer.
	#
	# An earlier version of this comment said applying the fixture straight to PostgreSQL
	# fails, and blamed products_ins for leaving cache__quantity_unit_conversions_resolved
	# empty. Both halves were wrong. The trigger was a faithful port; what was missing was
	# the seed data the PostgreSQL baseline never inserted, so quantity_units was empty and
	# the view the trigger copies from had nothing in it for any product. With that fixed
	# the fixture does apply directly. The import stays because it is the more
	# representative state, not because the alternative is broken.
	#
	# --force because build_pgsql above ran bin/victual-migrate, which seeds a fresh database
	# with the initial data of a new installation, and the import refuses a target that
	# holds rows unless told. Those particular rows are exactly what this import replaces —
	# it truncates before it copies — so overwriting them is the intent, not a risk.
	DIFFTEST_DB_NAME="$ROLLBACK_DB" VICTUAL_DATAPATH="$pgdatapath" \
		php "$VICTUAL_ROOT/bin/victual-db-import" "$sqlite_db" --force > /dev/null \
		|| fail 'could not import the rollback fixture into PostgreSQL'

	say ""
	if ! VICTUAL_DATAPATH="$pgdatapath" DIFFTEST_DB_NAME="$ROLLBACK_DB" php "$SUITE_DIR/rollback-tests.php"; then
		failures=$((failures + 1))
	fi

	rm -rf "$pgdatapath"
	rm -f "$sqlite_db"
}

# --- Filter operator tests --------------------------------------------------------
#
# The one phase that compares application behaviour rather than SQL. It asks each dialect
# for the condition it would emit for the API's "~" and "!~" operators, runs both against
# their own engine, and compares the rows - so it fails if the two ever stop meaning the
# same thing again, which is what hazard 16 was.
#
# Both databases are migrated ones rather than bare scratch databases, deliberately:
# PostgreSQL's ILIKE folds case according to the database's collation, so the answer
# depends on how the database was created, and the database this suite creates the way
# bin/victual-migrate creates one is the honest thing to measure.

run_filter_tests() {
	build_pgsql "$FILTER_DB"

	# A copy, not the pristine database itself: this phase creates and drops a scratch
	# table, and the pristine database is the template every other phase starts from.
	local sqlite_db="$SUITE_SCRATCH/filter-source.db"
	cp "$PRISTINE" "$sqlite_db" || fail 'could not copy the pristine database for the filter tests'

	export FILTERDIFF_SQLITE_PATH="$sqlite_db"
	export FILTERDIFF_PGSQL_DSN="pgsql:host=$PGHOST;port=$PGPORT;dbname=$FILTER_DB"
	export FILTERDIFF_PGSQL_USER="$PGUSER"
	export FILTERDIFF_PGSQL_PASSWORD="$PGPASSWORD"

	if ! php "$SUITE_DIR/filterdifftest.php"; then
		failures=$((failures + 1))
	fi

	rm -f "$sqlite_db"
}

# --- Schema gate tests ------------------------------------------------------------
#
# One engine at a time, like the rollback phase: the question is what the application
# believes about the database in front of it, which has no cross-engine comparison in it.
# The database is built by bin/victual-migrate and nothing else — the gate is about
# migration bookkeeping, so a fixture would only add rows it does not read.
#
# The script mutates the migrations table and puts it back; the databases here are
# throwaway either way, but SQLite's is a copy rather than the pristine database itself,
# because the pristine one is the template every other phase starts from.

run_schema_tests() {
	local datapath="$SUITE_SCRATCH/schema-sqlite"

	rm -rf "$datapath"
	mkdir -p "$datapath"

	VICTUAL_DATAPATH="$datapath" php "$VICTUAL_ROOT/bin/victual-migrate" --quiet \
		|| fail 'could not migrate the schema gate test database'

	say ""
	if ! VICTUAL_DATAPATH="$datapath" php "$SUITE_DIR/schemagatetest.php"; then
		failures=$((failures + 1))
	fi

	rm -rf "$datapath"

	build_pgsql "$SCHEMA_DB"

	local pgdatapath="$SUITE_SCRATCH/schema-pgsql"
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

	say ""
	if ! VICTUAL_DATAPATH="$pgdatapath" DIFFTEST_DB_NAME="$SCHEMA_DB" php "$SUITE_DIR/schemagatetest.php"; then
		failures=$((failures + 1))
	fi

	rm -rf "$pgdatapath"
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

# --- File import tests ------------------------------------------------------------
#
# PostgreSQL only, because the files table is: ConfigurationValidator refuses
# FILE_STORAGE = "database" on any other driver, so there is no SQLite side to compare
# with and nothing for the other phases to have caught. The phase needs a data directory
# of its own rather than a bare database, because the command under test reads
# <data path>/storage and the FILE_STORAGE setting lives in the same config.php — which
# is also why this is the one config.php in this file that sets more than the connection.

run_files_import_tests() {
	build_pgsql "$FILES_DB"

	local datapath="$SUITE_SCRATCH/files-import"
	rm -rf "$datapath"
	mkdir -p "$datapath"

	cat > "$datapath/config.php" <<-'PHPCONFIG'
		<?php
		Setting('DB_DRIVER', 'pgsql');
		Setting('DB_HOST', getenv('PGHOST'));
		Setting('DB_PORT', intval(getenv('PGPORT')));
		Setting('DB_NAME', getenv('DIFFTEST_DB_NAME'));
		Setting('DB_USER', getenv('PGUSER'));
		Setting('DB_PASSWORD', getenv('PGPASSWORD'));
		Setting('FILE_STORAGE', 'database');
	PHPCONFIG

	say ""

	# Both variables are exported into the child rather than set here, because the test
	# starts bin/victual-files-import as a further process and that one reads the same
	# config.php: an unexported value would reach the test and not the command it drives.
	if ! VICTUAL_DATAPATH="$datapath" DIFFTEST_DB_NAME="$FILES_DB" php "$SUITE_DIR/files-import-tests.php"; then
		failures=$((failures + 1))
	fi

	rm -rf "$datapath"
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
	migrate) run_migration_tests ;;
	views) run_view_tests ;;
	triggers) run_trigger_tests ;;
	rollback) run_rollback_tests ;;
	filter) run_filter_tests ;;
	schema) run_schema_tests ;;
	all) run_migration_tests; run_view_tests; run_trigger_tests; run_rollback_tests; run_filter_tests; run_schema_tests ;;
	*) fail "unknown target: $WHICH (expected migrate, views, triggers, rollback, filter, schema or all)" ;;
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
	env -u VICTUAL_COVERAGE_DIR php "$VICTUAL_ROOT/.devtools/coverage/report.php" "${report_args[@]}" \
		|| failures=$((failures + 1))
fi

say ""
if [ "$failures" -eq 0 ]; then
	say "SUITE PASSED"
	exit 0
fi

say "SUITE FAILED — $failures case(s) differ"
exit 1
