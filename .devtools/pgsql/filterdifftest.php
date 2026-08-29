<?php

// Differential test for the API's "~" and "!~" query operators.
//
//   php filterdifftest.php
//
//     FILTERDIFF_SQLITE_PATH   a migrated SQLite database
//     FILTERDIFF_PGSQL_DSN     a migrated PostgreSQL database
//     FILTERDIFF_PGSQL_USER, FILTERDIFF_PGSQL_PASSWORD
//
// The suite's other phases drive SQL at each engine directly. This one is the first that
// asks whether a piece of *application* behaviour means the same thing on both, which is
// the gap that let hazard 16 exist: BaseApiController spelled LIKE itself, SQLite's LIKE
// ignores ASCII case and PostgreSQL's does not, and the same API request therefore
// returned different rows per engine - with no error, and with a byte-identical response
// shape, so nothing a schema or snapshot comparison can see.
//
// So the condition under test is not written out here. It is fetched from the dialects
// themselves, via the same GetLikeCondition() the controller calls, and this file fails
// if either dialect stops agreeing with the other about what the operator means.
//
// Two things are asserted, and the split matters:
//
//   ASCII      identical row sets. This is the documented contract and there is no
//              latitude in it.
//
//   non-ASCII  PostgreSQL may fold more than SQLite, never less. SQLite's LIKE folds
//              ASCII A-Z only; PostgreSQL's ILIKE folds per the database collation, so on
//              a UTF-8 database "Æ" matches "æ" where SQLite would not. That is a real
//              difference and it is deliberately not asserted away, because which
//              characters fold is a property of the database's collation rather than of
//              this code - asserting an exact row set here would make the suite fail on a
//              C-locale database for a reason that is not a defect. What is invariant, and
//              is what gets checked, is the direction: ILIKE is at least as permissive as
//              LIKE. The actual sets and the collation are printed either way, so a change
//              in the residual is visible in the log rather than silent.

require_once (getenv('VICTUAL_ROOT') ?: '/app') . '/packages/autoload.php';

use Victual\Services\Database\PostgresDialect;
use Victual\Services\Database\SqliteDialect;

$sqlitePath = getenv('FILTERDIFF_SQLITE_PATH');
$pgsqlDsn = getenv('FILTERDIFF_PGSQL_DSN');

if (empty($sqlitePath) || empty($pgsqlDsn))
{
	exit('FILTERDIFF_SQLITE_PATH and FILTERDIFF_PGSQL_DSN must both be set' . PHP_EOL);
}

if (!is_readable($sqlitePath))
{
	exit('  SQLite database not found or unreadable: ' . $sqlitePath . PHP_EOL);
}

$sqlite = new PDO('sqlite:' . $sqlitePath);
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pgsql = new PDO($pgsqlDsn, getenv('FILTERDIFF_PGSQL_USER') ?: null, getenv('FILTERDIFF_PGSQL_PASSWORD') ?: null);
$pgsql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// A scratch table rather than one of the real ones: the fixture needs specific strings,
// and a phase that mutates a table another phase compares would be a bad neighbour. It is
// dropped again at the end.
const TABLE = 'filterdiff_names';

// The NULL row is here for the negated operator. NOT LIKE against NULL is NULL and so
// excludes the row on both engines - true, easy to assume, and exactly the kind of
// three-valued-logic detail that would be a silent per-engine difference if it were not.
$fixture = [
	[1, 'Milk'],
	[2, 'milk chocolate'],
	[3, 'Butter'],
	[4, null],
	[5, 'ÆBLE'],
	[6, 'æble'],
];

foreach ([[$sqlite, 'sqlite'], [$pgsql, 'pgsql']] as [$pdo, $name])
{
	$pdo->exec('DROP TABLE IF EXISTS ' . TABLE);
	$pdo->exec('CREATE TABLE ' . TABLE . ' (id INTEGER, name TEXT)');

	$insert = $pdo->prepare('INSERT INTO ' . TABLE . ' (id, name) VALUES (?, ?)');
	foreach ($fixture as [$id, $value])
	{
		$insert->execute([$id, $value]);
	}
}

$dialects = ['sqlite' => new SqliteDialect(), 'pgsql' => new PostgresDialect()];

/**
 * Runs one operator against one engine and returns the matching ids, using the condition
 * the dialect itself produces.
 */
function matches(PDO $pdo, $dialect, string $pattern, bool $negated): array
{
	$sql = 'SELECT id FROM ' . TABLE . ' WHERE ' . $dialect->GetLikeCondition('name', $negated) . ' ORDER BY id';
	$statement = $pdo->prepare($sql);
	$statement->execute(['%' . $pattern . '%']);

	return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

$cases = [
	['pattern' => 'milk', 'negated' => false, 'ascii' => true, 'label' => '~  milk   (mixed case, ASCII)'],
	['pattern' => 'MILK', 'negated' => false, 'ascii' => true, 'label' => '~  MILK   (upper pattern, ASCII)'],
	['pattern' => 'milk', 'negated' => true, 'ascii' => true, 'label' => '!~ milk   (negated, NULL must be excluded)'],
	['pattern' => 'æ', 'negated' => false, 'ascii' => false, 'label' => '~  æ      (non-ASCII fold)'],
	['pattern' => 'æ', 'negated' => true, 'ascii' => false, 'label' => '!~ æ      (non-ASCII fold, negated)'],
];

$collation = $pgsql->query('SELECT datcollate FROM pg_database WHERE datname = current_database()')->fetchColumn();

echo PHP_EOL;
echo 'Query filter operators, both engines (PostgreSQL collation: ' . $collation . ')' . PHP_EOL;
echo PHP_EOL;

$failures = 0;

foreach ($cases as $case)
{
	$sqliteIds = matches($sqlite, $dialects['sqlite'], $case['pattern'], $case['negated']);
	$pgsqlIds = matches($pgsql, $dialects['pgsql'], $case['pattern'], $case['negated']);

	$show = '[' . implode(',', $sqliteIds) . '] vs [' . implode(',', $pgsqlIds) . ']';

	if ($case['ascii'])
	{
		if ($sqliteIds === $pgsqlIds)
		{
			echo '  ok     ' . $case['label'] . '  identical ' . $show . PHP_EOL;
		}
		else
		{
			echo '  DIFFER ' . $case['label'] . '  ' . $show . PHP_EOL;
			$failures++;
		}

		continue;
	}

	// Beyond ASCII the check is directional. A positive match may return more on
	// PostgreSQL; a negated one, which excludes what the positive one matched, may
	// therefore return fewer. Either way neither engine may return something the other
	// cannot account for.
	$extra = $case['negated']
		? array_diff($pgsqlIds, $sqliteIds)
		: array_diff($sqliteIds, $pgsqlIds);

	if (empty($extra))
	{
		$note = $sqliteIds === $pgsqlIds ? 'identical' : 'differs, as documented';
		echo '  ok     ' . $case['label'] . '  ' . $note . ' ' . $show . PHP_EOL;
	}
	else
	{
		echo '  DIFFER ' . $case['label'] . '  wrong direction ' . $show . PHP_EOL;
		$failures++;
	}
}

foreach ([$sqlite, $pgsql] as $pdo)
{
	$pdo->exec('DROP TABLE IF EXISTS ' . TABLE);
}

echo PHP_EOL;

if ($failures === 0)
{
	echo 'QUERY FILTER OPERATORS IDENTICAL ON ASCII' . PHP_EOL;
	exit(0);
}

echo 'QUERY FILTER OPERATORS DIFFER — ' . $failures . ' case(s)' . PHP_EOL;
exit(1);
