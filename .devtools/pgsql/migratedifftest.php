<?php

// Differential test for the migration path itself.
//
// The other two phases both start from a PostgreSQL database that was populated by
// copying an already-migrated SQLite one, so neither has ever looked at what
// bin/grocy-migrate produces on its own. That blind spot hid a real defect: the
// PostgreSQL baseline is schema only, while a third of the migrations it stands in for
// also insert rows, so a freshly migrated PostgreSQL database had no admin user, no
// permission hierarchy and no quantity units - and reported success.
//
// So this compares two databases that have had nothing done to them but migrate.
// db/pgsql/README.md claims the baseline is "equivalent to the state SQLite reaches
// after migrations 0001-0255"; this is that sentence written as a test.
//
// Usage: php migratedifftest.php
//
//   MIGRATEDIFF_SQLITE_PATH   a freshly migrated SQLite database
//   MIGRATEDIFF_PGSQL_DSN     a freshly migrated PostgreSQL database
//   MIGRATEDIFF_PGSQL_USER, MIGRATEDIFF_PGSQL_PASSWORD

require_once (getenv('GROCY_ROOT') ?: '/app') . '/packages/autoload.php';

use Grocy\Services\Database\DatabaseImporter;
use Grocy\Services\Database\ValueComparison;

$sqlitePath = getenv('MIGRATEDIFF_SQLITE_PATH');
$pgsqlDsn = getenv('MIGRATEDIFF_PGSQL_DSN');

if (empty($sqlitePath) || empty($pgsqlDsn))
{
	exit('MIGRATEDIFF_SQLITE_PATH and MIGRATEDIFF_PGSQL_DSN must both be set' . PHP_EOL);
}

if (!is_readable($sqlitePath))
{
	exit('  SQLite database not found or unreadable: ' . $sqlitePath . PHP_EOL);
}

$sqlite = new PDO('sqlite:' . $sqlitePath);
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Not NULL_EMPTY_STRING, which the application sets on its own connections: the internal
// meal plan section really is named with an empty string, and folding that to NULL here
// would make the two sides differ over something neither engine did.
$sqlite->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);

$pg = new PDO($pgsqlDsn, getenv('MIGRATEDIFF_PGSQL_USER') ?: null, getenv('MIGRATEDIFF_PGSQL_PASSWORD') ?: null);
$pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pg->setAttribute(PDO::ATTR_ORACLE_NULLS, PDO::NULL_NATURAL);

echo '== freshly migrated databases' . PHP_EOL;

$problems = CompareTableSets($sqlite, $pg) + CompareRows($sqlite, $pg);

echo PHP_EOL . 'Excluded from comparison: the migrations table (per engine by design), '
	. 'row_created_timestamp everywhere (clock) and users.password (hashed with a fresh '
	. 'salt per installation).' . PHP_EOL;

echo PHP_EOL . ($problems === 0 ? 'MIGRATED STATE IDENTICAL' : "$problems problem(s)") . PHP_EOL;
exit($problems === 0 ? 0 : 1);

/**
 * Reports tables that only one engine has. A missing table is the loudest possible
 * version of the defect this phase exists for, and it would otherwise be invisible:
 * comparing only the tables both sides have makes a table nobody created look like
 * agreement.
 */
function CompareTableSets(PDO $sqlite, PDO $pg): int
{
	$problems = 0;

	// The two the PostgreSQL schema owns outright: user_settings_defaults resolves
	// settings in SQL where SQLite uses a PHP callback, and system_db_changed_time
	// replaces SQLite's file mtime. Same list DatabaseImporter works from.
	foreach (array_diff(PgTables($pg), SqliteTables($sqlite), DatabaseImporter::TARGET_ONLY_TABLES) as $table)
	{
		echo "  DIFF  table $table exists on PostgreSQL only\n";
		$problems++;
	}

	foreach (array_diff(SqliteTables($sqlite), PgTables($pg)) as $table)
	{
		echo "  DIFF  table $table exists on SQLite only\n";
		$problems++;
	}

	return $problems;
}

/**
 * Compares the contents of every table both engines have, column by column.
 */
function CompareRows(PDO $sqlite, PDO $pg): int
{
	$problems = 0;

	foreach (array_intersect(PgTables($pg), SqliteTables($sqlite)) as $table)
	{
		// Per engine by design - PostgreSQL replaces migrations 0001-0255 with the
		// baseline, and 0256.sqlite.sql applies to one side only, so two fully migrated
		// databases hold different rows here and always will.
		if ($table === 'migrations')
		{
			continue;
		}

		$columns = array_values(array_diff(
			array_intersect(
				array_map(fn($c) => $c['name'], $sqlite->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC)),
				$pg->query("SELECT column_name FROM information_schema.columns
					WHERE table_schema = current_schema() AND table_name = " . $pg->quote($table))->fetchAll(PDO::FETCH_COLUMN)
			),
			IgnoredColumns($table)
		));

		if (empty($columns))
		{
			continue;
		}

		$list = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));

		$a = array_map([ValueComparison::class, 'NormaliseRow'], $sqlite->query('SELECT ' . $list . ' FROM "' . $table . '"')->fetchAll(PDO::FETCH_ASSOC));
		$b = array_map([ValueComparison::class, 'NormaliseRow'], $pg->query('SELECT ' . $list . ' FROM "' . $table . '"')->fetchAll(PDO::FETCH_ASSOC));

		sort($a);
		sort($b);

		if ($a === $b)
		{
			continue;
		}

		$problems++;
		echo "  DIFF  table $table -- SQLite " . count($a) . " rows, PostgreSQL " . count($b) . " rows\n";

		foreach (array_slice(array_diff($a, $b), 0, 4) as $only) echo "        only SQLite: $only\n";
		foreach (array_slice(array_diff($b, $a), 0, 4) as $only) echo "        only PgSQL:  $only\n";
	}

	return $problems;
}

/**
 * Columns excluded from the comparison, and why. Both entries are non-deterministic
 * rather than inconvenient.
 *
 * @return string[]
 */
function IgnoredColumns(string $table): array
{
	// Set from the clock, so it legitimately differs between the two migration runs
	$ignored = ['row_created_timestamp'];

	// The admin account's password is hashed per installation, salt and all, rather than
	// shipped as a fixed digest - so the same password produces a different string on
	// every run, on one engine as much as on two.
	if ($table === 'users')
	{
		$ignored[] = 'password';
	}

	return $ignored;
}

/**
 * @return string[]
 */
function PgTables(PDO $pg): array
{
	return $pg->query("SELECT table_name FROM information_schema.tables
		WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
		ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * @return string[]
 */
function SqliteTables(PDO $sqlite): array
{
	return $sqlite->query("SELECT name FROM sqlite_master
		WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
}
