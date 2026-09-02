#!/usr/bin/env bash
#
# Verification 7 of docs/plans/18-mqtt-state-publication.md: does the snapshot assembler
# agree on SQLite and PostgreSQL?
#
#   .devtools/mqtt/engine-diff.sh
#
# It reads the same views the UI does, so a divergence in the payload is a divergence
# anywhere - and this is the cheapest place to notice one, because it is one JSON document
# rather than a page.
#
# What it does:
#
#   1. migrates a fresh SQLite database with bin/victual-migrate
#   2. seeds it with the fixture below, so both engines have identical data - including the
#      per-product opt-in flags, which is the part the ambient snapshot alone would not
#      exercise
#   3. migrates a fresh PostgreSQL database and copies the SQLite one into it with
#      bin/victual-db-import, the real migration command rather than a copier written for
#      this script
#   4. runs .devtools/mqtt/assemble.php against each and diffs the JSON
#
# The two payloads have to be byte-identical, not merely equivalent. PDO hands back strings
# from one engine where the other gives numbers, so the assembler has to have normalised
# every value it emits; a diff here is that normalisation missing, which would reach a
# subscriber as a state that changes type when the engine does.
#
# Connection settings come from the environment, with the same names the differential suite
# uses:
#
#   PGHOST, PGPORT, PGUSER, PGPASSWORD   PostgreSQL connection (libpq's own names)
#   MQTTDIFF_PGSQL_DB                    database to build     (default victual_mqttdiff)
#   SUITE_SCRATCH                        where the throwaway SQLite database goes
#
# Exit codes: 0 when the payloads match, 1 when they differ or a step fails.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRATCH="${SUITE_SCRATCH:-/tmp/victual-mqttdiff}"
PGDB="${MQTTDIFF_PGSQL_DB:-victual_mqttdiff}"

export PGHOST="${PGHOST:-127.0.0.1}"
export PGPORT="${PGPORT:-5432}"
export PGUSER="${PGUSER:-victual}"
export PGPASSWORD="${PGPASSWORD:-victual}"

SQLITE_DATA="$SCRATCH/mqtt-sqlite"
PGSQL_DATA="$SCRATCH/mqtt-pgsql"

rm -rf "$SQLITE_DATA" "$PGSQL_DATA"
mkdir -p "$SQLITE_DATA" "$PGSQL_DATA"

echo "== SQLite: migrate =="
VICTUAL_DATAPATH="$SQLITE_DATA" php "$ROOT/bin/victual-migrate" --quiet

echo "== SQLite: seed =="
php -r '
$db = new PDO("sqlite:" . $argv[1] . "/victual.db");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sql = file_get_contents($argv[2]);
foreach (array_filter(array_map("trim", explode(";\n", $sql))) as $statement)
{
	if ($statement !== "") { $db->exec($statement); }
}
' "$SQLITE_DATA" "$ROOT/.devtools/mqtt/fixture.sql"

echo "== PostgreSQL: recreate $PGDB =="
psql -v ON_ERROR_STOP=1 -d postgres -c "DROP DATABASE IF EXISTS $PGDB" > /dev/null
psql -v ON_ERROR_STOP=1 -d postgres -c "CREATE DATABASE $PGDB" > /dev/null

cat > "$PGSQL_DATA/config.php" <<PHPCONF
<?php
Setting('DB_DRIVER', 'pgsql');
Setting('DB_HOST', '$PGHOST');
Setting('DB_PORT', $PGPORT);
Setting('DB_NAME', '$PGDB');
Setting('DB_USER', '$PGUSER');
Setting('DB_PASSWORD', '$PGPASSWORD');
PHPCONF

echo "== PostgreSQL: migrate and import =="
VICTUAL_DATAPATH="$PGSQL_DATA" php "$ROOT/bin/victual-migrate" --quiet
VICTUAL_DATAPATH="$PGSQL_DATA" php "$ROOT/bin/victual-db-import" "$SQLITE_DATA/victual.db" --force > /dev/null

echo "== Assemble on both =="
VICTUAL_DATAPATH="$SQLITE_DATA" php "$ROOT/.devtools/mqtt/assemble.php" --pretty > "$SCRATCH/payload-sqlite.json"
VICTUAL_DATAPATH="$PGSQL_DATA" php "$ROOT/.devtools/mqtt/assemble.php" --pretty > "$SCRATCH/payload-pgsql.json"

if diff -u "$SCRATCH/payload-sqlite.json" "$SCRATCH/payload-pgsql.json"
then
	echo "MQTT PAYLOAD IDENTICAL ON BOTH ENGINES"
	echo "  $(wc -c < "$SCRATCH/payload-sqlite.json") bytes, $(grep -c . "$SCRATCH/payload-sqlite.json") lines"
	exit 0
fi

echo "MQTT PAYLOAD DIFFERS BETWEEN ENGINES (above: SQLite left, PostgreSQL right)"
exit 1
