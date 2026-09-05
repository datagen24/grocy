#!/usr/bin/env bash
#
# Regenerates the committed SQLite import fixtures.
#
#   .devtools/pgsql/fixtures/import/make-fixtures.sh
#
# ADR-0008 kept SQLite as an import format and nothing else, which leaves
# bin/victual-db-import reading a format no engine in this repository produces any more.
# These fixtures are what keeps it honest: one database at each end of the supported span
# (DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN and SUPPORTED_SOURCE_MIGRATION_MAX),
# committed, with import-tests.php asserting what importing each of them produces on
# PostgreSQL.
#
# Two rather than one per version, which is ADR-0008 question 2 answered by building them:
# the importer's failure modes are schema-shaped rather than version-shaped - a table the
# source does not have, a column the target gained, a row transformation the target's own
# migration ran before the rows arrived - and every one of those is at its most extreme
# between the two ends. A fixture per intermediate version would re-ask the same questions
# with smaller deltas.
#
# How the older one is built, since it cannot simply be migrated: the migration runner
# resolves migrations/ relative to its own file, so there is no "migrate as far as N"
# switch to pass it. The tree is therefore hard-linked into a scratch directory - no data
# is copied, and unlinking a hard link cannot touch the original - the migrations above N
# are unlinked there, and the runner is invoked against that copy. It is the real runner
# producing the real schema, which a hand-written .sql fixture would only resemble.
#
# The fixtures are real .db files rather than SQL dumps on purpose. What the importer knows
# about SQLite is type affinity (porting hazards 1-14): what a value comes back as depends
# on how the column was declared and on what was stored in it, and a dump reconstituted
# through a different writer is one step removed from the thing under test.

set -euo pipefail

SUITE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
VICTUAL_ROOT="$(cd "$SUITE_DIR/../.." && pwd)"
OUT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

fail() { printf '%s\n' "$*" >&2; exit 1; }

command -v php >/dev/null || fail 'php not found on PATH'
[ -f "$VICTUAL_ROOT/packages/autoload.php" ] || fail 'packages/ is missing - run composer install first'

# Both ends of the span, read from the code that enforces it rather than restated here.
MIN=$(php -r "require '$VICTUAL_ROOT/packages/autoload.php'; echo Victual\\Services\\Database\\DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MIN;")
MAX=$(php -r "require '$VICTUAL_ROOT/packages/autoload.php'; echo Victual\\Services\\Database\\DatabaseImporter::SUPPORTED_SOURCE_MIGRATION_MAX;")

SCRATCH="$(mktemp -d)"
trap 'rm -rf "$SCRATCH"' EXIT

# The suite's own gate, so a fixture is never built by a runtime that would refuse to
# construct a SQLite dialect at all. See DatabaseDialect::SQLITE_TOOLING_ENV.
export DIFFTEST_SQLITE_RUNTIME=1

# Hard-linked, not copied: the tree carries a vendor directory that is most of it, and
# nothing here writes through a link. .git is left out because nothing in a migration run
# reads it and a hard-linked object store is a confusing thing to leave in a temp dir.
link_tree() {
	local dest="$1"

	mkdir -p "$dest"

	for entry in "$VICTUAL_ROOT"/* "$VICTUAL_ROOT"/.[!.]*; do
		[ -e "$entry" ] || continue
		case "$(basename "$entry")" in
			.git) continue ;;
		esac
		cp -al "$entry" "$dest/" 2>/dev/null || cp -a "$entry" "$dest/"
	done
}

build_fixture() {
	local upto="$1"
	local out="$2"

	local root="$SCRATCH/root-$upto"
	local datapath="$SCRATCH/data-$upto"

	rm -rf "$root" "$datapath"
	link_tree "$root"
	mkdir -p "$datapath"

	# Everything above the requested number goes, on both engines and in both spellings.
	# The always-run fixups (8888, 9999) stay: they are not schema versions and every real
	# database has met them.
	local file number
	for file in "$root"/migrations/*.sql "$root"/migrations/*.php; do
		[ -e "$file" ] || continue
		number="$(basename "$file" | sed -n 's/^\([0-9]\{1,\}\)\..*$/\1/p')"
		[ -n "$number" ] || continue
		if [ "$number" -gt "$upto" ] && [ "$number" -lt 8888 ]; then
			rm -f "$file"
		fi
	done

	cat > "$datapath/config.php" <<-'PHPCONFIG'
		<?php
		Setting('DB_DRIVER', 'sqlite');
	PHPCONFIG

	VICTUAL_ROOT="$root" VICTUAL_DATAPATH="$datapath" php "$root/bin/victual-migrate" --quiet \
		|| fail "could not migrate a SQLite database up to $upto"

	# The suite's shared fixture first, so an imported database has the same reference rows
	# every other phase reasons about, then the per-version seed with what only this end of
	# the span can carry.
	php "$SUITE_DIR/apply-sql.php" "sqlite:$datapath/victual.db" "$SUITE_DIR/fixtures/00_base.sql" \
		|| fail "could not apply the base fixture for $upto"

	php "$SUITE_DIR/apply-sql.php" "sqlite:$datapath/victual.db" "$OUT_DIR/seed-$upto.sql" \
		|| fail "could not apply the seed for $upto"

	# VACUUM so what is committed is the pages the data needs rather than the pages the
	# migration run happened to touch.
	php -r '$d = new PDO("sqlite:" . $argv[1]); $d->exec("VACUUM");' "$datapath/victual.db" \
		|| fail "could not vacuum the fixture for $upto"

	mv "$datapath/victual.db" "$out"

	printf '%s -> %s (%s bytes)\n' "$upto" "$out" "$(stat -c %s "$out")"
}

build_fixture "$MIN" "$OUT_DIR/victual-$MIN.db"
build_fixture "$MAX" "$OUT_DIR/victual-$MAX.db"

printf '\nRegenerated. Commit the .db files and run: .devtools/pgsql/run-tests.sh import\n'
