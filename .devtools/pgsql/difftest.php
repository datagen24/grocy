<?php

// Differential test: put both engines into an IDENTICAL table state, then compare what
// their views return. Loading cleanly proves nothing about equivalence - this does.
//
// The seed is applied to SQLite only, so its triggers fire naturally, and the resulting
// table contents are then copied verbatim into PostgreSQL. That isolates what is being
// tested (view logic) from what is not yet ported (triggers), and the copy step is a
// prototype of the eventual SQLite -> PostgreSQL migration command.
//
// Usage: php difftest.php <seed.sql> <view> [<view> ...]

require_once (getenv('GROCY_ROOT') ?: '/app') . '/packages/autoload.php';

$seedFile = $argv[1];
$views = array_slice($argv, 2);

$sqlite = new PDO(getenv('DIFFTEST_SQLITE_DSN') ?: 'sqlite:/data/difftest.db');
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pg = new PDO(getenv('DIFFTEST_PGSQL_DSN') ?: 'pgsql:host=grocy-pg;port=5432;dbname=grocy_full', getenv('DIFFTEST_PGSQL_USER') ?: 'grocy', getenv('DIFFTEST_PGSQL_PASSWORD') ?: 'grocy');
$pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Set DIFFTEST_SKIP_COPY=1 to compare against a PostgreSQL database that was populated
// some other way - in particular one filled by bin/grocy-db-import, which verifies the
// real migration command rather than this script's own copier.
$skipCopy = (bool)getenv('DIFFTEST_SKIP_COPY');

// 1. Seed SQLite, letting its triggers do whatever they do
if (!$skipCopy)
{
	foreach (array_filter(array_map('trim', explode(";\n", file_get_contents($seedFile)))) as $statement)
	{
		if ($statement !== '')
		{
			$sqlite->exec($statement);
		}
	}

	// 2. Mirror every table into PostgreSQL
	$pgTables = $pg->query("SELECT table_name FROM information_schema.tables
		WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
		AND table_name <> 'user_settings_defaults' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);

	$sqliteTables = $sqlite->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
	$tables = array_values(array_intersect($pgTables, $sqliteTables));

	$pg->exec('TRUNCATE TABLE ' . implode(', ', array_map(fn($t) => '"' . $t . '"', $tables)) . ' RESTART IDENTITY CASCADE');

	$copied = 0;
	foreach ($tables as $table)
	{
		$rows = $sqlite->query('SELECT * FROM "' . $table . '"')->fetchAll(PDO::FETCH_ASSOC);
		if (empty($rows))
		{
			continue;
		}

		$pgColumns = $pg->query("SELECT column_name FROM information_schema.columns
			WHERE table_schema = 'public' AND table_name = " . $pg->quote($table))->fetchAll(PDO::FETCH_COLUMN);

		$columns = array_values(array_intersect(array_keys($rows[0]), $pgColumns));
		$sql = 'INSERT INTO "' . $table . '" (' . implode(', ', array_map(fn($c) => '"' . $c . '"', $columns)) . ')'
			. ' VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
		$statement = $pg->prepare($sql);

		foreach ($rows as $row)
		{
			$statement->execute(array_map(fn($c) => $row[$c], $columns));
			$copied++;
		}
	}

	(new Grocy\Services\Database\PostgresDialect())->ResyncGeneratedIdCounters($pg);
	echo "  copied $copied rows across " . count($tables) . " tables into PostgreSQL\n\n";
}

// 3. Compare the views
function normalise($v)
{
	if ($v === null) return null;
	if (is_bool($v)) return $v ? 1 : 0;
	if (is_numeric($v)) return round((float)$v, 6);
	return (string)$v;
}

$failures = 0;

foreach ($views as $view)
{
	try
	{
		$a = $sqlite->query("SELECT * FROM $view")->fetchAll(PDO::FETCH_ASSOC);
		$b = $pg->query("SELECT * FROM $view")->fetchAll(PDO::FETCH_ASSOC);
	}
	catch (Exception $ex)
	{
		echo "  FAIL $view -> " . $ex->getMessage() . "\n";
		$failures++;
		continue;
	}

	$ja = array_map(fn($r) => json_encode(array_map('normalise', $r)), $a);
	$jb = array_map(fn($r) => json_encode(array_map('normalise', $r)), $b);

	// Views have no inherent row order, so sort both sides by their normalised form and
	// keep the rows in step with it - the type comparison below is only meaningful when
	// it is looking at the same logical row on each side.
	array_multisort($ja, $a);
	array_multisort($jb, $b);

	// Compare what json_encode would actually emit, not just the numeric value. PDO hands
	// back NUMERIC as a string and BOOLEAN as a bool, so a view can produce numerically
	// equal output that serialises differently - "2.50" instead of 2.5, true instead of 1.
	// Note json_encode(6.0) emits 6, so int-vs-float is NOT a difference on the wire.
	$typeMismatches = [];

	if ($ja === $jb && !empty($a))
	{
		foreach (array_keys($a[0]) as $column)
		{
			foreach ($a as $i => $rowA)
			{
				if (!isset($b[$i]) || !array_key_exists($column, $b[$i])) continue;
				if ($rowA[$column] === null || $b[$i][$column] === null) continue;

				$encodedA = json_encode($rowA[$column]);
				$encodedB = json_encode($b[$i][$column]);

				if ($encodedA !== $encodedB)
				{
					$typeMismatches[$column] = gettype($rowA[$column]) . ' ' . $encodedA
						. '  vs  ' . gettype($b[$i][$column]) . ' ' . $encodedB;
					break;
				}
			}
		}
	}

	if ($ja === $jb && empty($typeMismatches))
	{
		echo "  ok   $view (" . count($a) . " rows identical)\n";
		continue;
	}

	if ($ja === $jb)
	{
		$failures++;
		echo "  TYPE $view -- values match but JSON types differ:\n";
		foreach ($typeMismatches as $column => $detail)
		{
			echo "         $column: SQLite $detail\n";
		}
		continue;
	}

	$failures++;
	echo "  DIFF $view -- SQLite " . count($a) . " rows, PostgreSQL " . count($b) . " rows\n";

	foreach (array_slice(array_diff($ja, $jb), 0, 6) as $only) echo "         only SQLite: $only\n";
	foreach (array_slice(array_diff($jb, $ja), 0, 6) as $only) echo "         only PgSQL:  $only\n";
}

echo "\n" . ($failures === 0 ? 'ALL VIEWS IDENTICAL' : "$failures view(s) differ") . "\n";
exit($failures === 0 ? 0 : 1);
