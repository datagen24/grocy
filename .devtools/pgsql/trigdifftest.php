<?php

// Differential test for triggers.
//
// Views are checked by comparing what they return. Triggers cannot be: what they do is
// change other rows. So this starts both engines from an identical table state, applies
// exactly the same statements to each, and then compares every table.
//
// Any difference means a trigger did not fire, fired when it should not have, or computed
// something different. Statements expected to be rejected by a trigger are handled too -
// see "-- @expect-error" below.
//
// Usage: php trigdifftest.php <script.sql> [<script.sql> ...]
//
// A script is plain SQL, one statement per ";" at end of line. A statement preceded by a
// line "-- @expect-error <substring>" must fail on BOTH engines, and both messages must
// contain <substring>; that is how RAISE(ABORT, ...) constraints are checked.

require_once (getenv('GROCY_ROOT') ?: '/app') . '/packages/autoload.php';

use Grocy\Services\Database\DatabaseImporter;
use Grocy\Services\Database\PostgresDialect;

$scripts = array_slice($argv, 1);

$sqlitePath = getenv('TRIGTEST_SQLITE_PATH') ?: '/data/trigtest.db';
$pristinePath = getenv('TRIGTEST_PRISTINE_PATH') ?: '/scratch/demodata/grocy_en.db';

$dialect = new PostgresDialect();
$failures = 0;

if (empty($scripts))
{
	exit('Usage: php trigdifftest.php <script.sql> [<script.sql> ...]' . PHP_EOL);
}

foreach ($scripts as $script)
{
	echo PHP_EOL . '== ' . basename($script) . PHP_EOL;

	// A script that cannot be read must not quietly pass. Reporting "identical state"
	// for a file that was never opened is worse than reporting nothing at all.
	if (!is_readable($script))
	{
		exit('  script not found or unreadable: ' . $script . PHP_EOL);
	}

	// Every script starts from the same pristine state on both sides
	copy($pristinePath, $sqlitePath);

	$sqlite = new PDO('sqlite:' . $sqlitePath);
	$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$pg = new PDO(
		getenv('TRIGTEST_PGSQL_DSN') ?: 'pgsql:host=grocy-pg;port=5432;dbname=grocy_trig',
		getenv('TRIGTEST_PGSQL_USER') ?: 'grocy',
		getenv('TRIGTEST_PGSQL_PASSWORD') ?: 'grocy'
	);
	$pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	// Load the SQLite state into PostgreSQL with triggers off, so both sides begin
	// from the same rows rather than from rows the target's triggers have re-derived
	$importer = new DatabaseImporter($sqlite, $pg, $dialect, fn($m) => null);
	$importer->Import(true);

	$statements = ParseScript(file_get_contents($script));

	if (empty($statements))
	{
		exit('  script contains no statements: ' . $script . PHP_EOL);
	}

	$scriptFailures = 0;

	foreach ($statements as [$sql, $expectError])
	{
		$resultA = RunStatement($sqlite, $sql);
		$resultB = RunStatement($pg, $sql);

		$label = trim(preg_replace('/\s+/', ' ', substr($sql, 0, 68)));

		if ($expectError !== null)
		{
			$aRejected = $resultA !== null;
			$bRejected = $resultB !== null;

			if (!$aRejected || !$bRejected)
			{
				echo "  FAIL  $label\n";
				echo "        expected both engines to reject it; SQLite "
					. ($aRejected ? 'rejected' : 'ACCEPTED') . ', PostgreSQL '
					. ($bRejected ? 'rejected' : 'ACCEPTED') . "\n";
				$scriptFailures++;
				continue;
			}

			$aMatches = stripos($resultA, $expectError) !== false;
			$bMatches = stripos($resultB, $expectError) !== false;

			if (!$aMatches || !$bMatches)
			{
				echo "  FAIL  $label\n";
				echo "        both rejected, but the message should contain \"$expectError\"\n";
				echo "        SQLite:     $resultA\n";
				echo "        PostgreSQL: $resultB\n";
				$scriptFailures++;
				continue;
			}

			echo "  ok    rejected by both: $label\n";
			continue;
		}

		if ($resultA !== null || $resultB !== null)
		{
			echo "  FAIL  $label\n";
			if ($resultA !== null) echo "        SQLite error:     $resultA\n";
			if ($resultB !== null) echo "        PostgreSQL error: $resultB\n";
			$scriptFailures++;
		}
	}

	$scriptFailures += CompareAllTables($sqlite, $pg);

	echo $scriptFailures === 0
		? "  -> identical state after " . count($statements) . " statements\n"
		: "  -> $scriptFailures problem(s)\n";

	$failures += $scriptFailures;
}

echo PHP_EOL . 'Excluded from comparison: row_created_timestamp everywhere (clock), '
	. 'chores.start_date and chores.rescheduled_date, and the dummy id on cache__ tables '
	. '(both accepted differences, see db/pgsql/README.md).' . PHP_EOL;

echo PHP_EOL . ($failures === 0 ? 'TRIGGER BEHAVIOUR IDENTICAL' : "$failures problem(s)") . PHP_EOL;
exit($failures === 0 ? 0 : 1);

/**
 * @return array<array{0:string,1:?string}> [sql, expectedErrorSubstring]
 */
function ParseScript(string $content): array
{
	$statements = [];
	$expect = null;
	$buffer = '';

	foreach (explode("\n", $content) as $line)
	{
		if (preg_match('/^\s*--\s*@expect-error\s+(.+?)\s*$/', $line, $m))
		{
			$expect = $m[1];
			continue;
		}

		if (preg_match('/^\s*--/', $line) && trim($buffer) === '')
		{
			continue;
		}

		$buffer .= $line . "\n";

		if (preg_match('/;\s*$/', $line))
		{
			if (trim($buffer) !== '')
			{
				$statements[] = [trim($buffer), $expect];
			}

			$buffer = '';
			$expect = null;
		}
	}

	if (trim($buffer) !== '')
	{
		$statements[] = [trim($buffer), $expect];
	}

	return $statements;
}

/**
 * @return string|null The error message, or null when the statement succeeded
 */
function RunStatement(PDO $db, string $sql): ?string
{
	try
	{
		$db->exec($sql);
		return null;
	}
	catch (PDOException $ex)
	{
		return trim(preg_replace('/\s+/', ' ', $ex->getMessage()));
	}
}

function CompareAllTables(PDO $sqlite, PDO $pg): int
{
	$pgTables = $pg->query("SELECT table_name FROM information_schema.tables
		WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
		ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);

	$sqliteTables = $sqlite->query("SELECT name FROM sqlite_master
		WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);

	$problems = 0;

	foreach (array_intersect($pgTables, $sqliteTables) as $table)
	{
		$columns = array_values(array_intersect(
			array_map(fn($c) => $c['name'], $sqlite->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC)),
			$pg->query("SELECT column_name FROM information_schema.columns
				WHERE table_schema = current_schema() AND table_name = " . $pg->quote($table))->fetchAll(PDO::FETCH_COLUMN)
		));

		$columns = array_values(array_diff($columns, IgnoredColumns($table)));

		if (empty($columns))
		{
			continue;
		}

		$list = implode(', ', array_map(fn($c) => '"' . $c . '"', $columns));

		$a = array_map('NormaliseRow', $sqlite->query('SELECT ' . $list . ' FROM "' . $table . '"')->fetchAll(PDO::FETCH_ASSOC));
		$b = array_map('NormaliseRow', $pg->query('SELECT ' . $list . ' FROM "' . $table . '"')->fetchAll(PDO::FETCH_ASSOC));

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
 * Columns excluded from the comparison, and why. Everything here is either
 * non-deterministic or a difference recorded in db/pgsql/README.md as accepted - never a
 * convenient way to make a real failure disappear. The run prints what it skipped.
 *
 * @return string[]
 */
function IgnoredColumns(string $table): array
{
	// Set from the clock, so it legitimately differs between the two runs
	$ignored = ['row_created_timestamp'];

	// Accepted difference: SQLite returns the stored string verbatim, so a date-only
	// value comes back without a time where PostgreSQL renders "00:00:00"
	if ($table === 'chores')
	{
		$ignored[] = 'start_date';
		$ignored[] = 'rescheduled_date';
	}

	// Accepted difference: SQLite's INSERT OR REPLACE deletes and reinserts, taking a new
	// id, where PostgreSQL's ON CONFLICT DO UPDATE keeps the existing one. These ids are
	// "dummy" columns that LessQL requires and nothing reads - no view selects them and no
	// cache table is an exposed entity.
	if (str_starts_with($table, 'cache__'))
	{
		$ignored[] = 'id';
	}

	return $ignored;
}

function NormaliseRow(array $row): string
{
	return json_encode(array_map(function ($v)
	{
		if ($v === null) return null;
		if (is_bool($v)) return $v ? 1 : 0;
		if (is_numeric($v)) return round((float)$v, 6);
		return (string)$v;
	}, $row));
}
