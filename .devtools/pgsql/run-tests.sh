#!/usr/bin/env bash
#
# The differential test suite: does this fork behave identically on SQLite and
# PostgreSQL?
#
#   .devtools/pgsql/run-tests.sh [views|triggers]
#
# Two kinds of check, for two reasons. Views are compared by what they return, because
# that is all a view is. Triggers cannot be compared that way — what a trigger does is
# change other rows — so those scripts are applied to both engines and every table is
# compared afterwards.
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
#   SUITE_SCRATCH                        where the throwaway databases go
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

WHICH="${1:-all}"

say() { printf '%s\n' "$*"; }

fail() { printf '%s\n' "$*" >&2; exit 1; }

command -v php >/dev/null || fail 'php not found on PATH'
[ -f "$GROCY_ROOT/packages/autoload.php" ] || fail 'packages/ is missing — run composer install first'

mkdir -p "$SUITE_SCRATCH"

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
	all) run_view_tests; run_trigger_tests ;;
	*) fail "unknown target: $WHICH (expected views, triggers or all)" ;;
esac

say ""
if [ "$failures" -eq 0 ]; then
	say "SUITE PASSED"
	exit 0
fi

say "SUITE FAILED — $failures case(s) differ"
exit 1
