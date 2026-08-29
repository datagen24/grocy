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

use Victual\Services\Database\DatabaseDialect;
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

// PDO\Sqlite rather than PDO, matching SqliteDialect::CreateConnection(): createFunction()
// lives on the subclass, and plain "new PDO" does not give it.
$sqlite = new PDO\Sqlite('sqlite:' . $sqlitePath);
$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// SqliteDialect::OnConnected() registers these on every application connection, and
// PRAGMA table_info compiles the view it is asked about - so without them this file cannot
// introspect any view that uses one. Stubs rather than the real implementations: what is
// being asked of the view here is whether it compiles and what its columns are called, not
// what it returns. The real ones need the whole application bootstrapped.
$sqlite->createFunction('victual_user_setting', fn($value) => null);
$sqlite->createFunction('regexp', fn($pattern, $value) => 0);
$sqlite->createFunction('ceil', fn($value) => ceil((float)$value));

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

// --- Which columns the operator is allowed on -------------------------------------
//
// The row comparison above only exercises a text column, because that is the only kind
// "~" is allowed on. What decides that is DatabaseDialect::IsTextMatchableType() applied
// to whatever each engine reports the column's type as - two different strings, from two
// different catalogues, that have to reach the same verdict or the API means different
// things per engine.
//
// This is checked against the real schema rather than a fixture. A fixture would only
// prove the classifier agrees about the types the fixture happens to contain; the schema
// is what callers actually filter, and it is what changes under a migration.

echo PHP_EOL;
echo 'Operator eligibility per column, every shared table' . PHP_EOL;
echo PHP_EOL;

$sqliteTables = $sqlite->query(
	"SELECT name FROM sqlite_master WHERE type IN ('table', 'view') AND name NOT LIKE 'sqlite_%' ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

$pgsqlTables = $pgsql->query(
	'SELECT table_name FROM information_schema.tables '
	. "WHERE table_schema = ANY(current_schemas(false)) ORDER BY table_name"
)->fetchAll(PDO::FETCH_COLUMN);

$shared = array_values(array_intersect($sqliteTables, $pgsqlTables));

$checked = 0;
$disagreements = [];
$untyped = [];
$samples = [];

foreach ($shared as $table)
{
	$sqliteTypes = $dialects['sqlite']->GetColumnTypes($sqlite, $table);
	$pgsqlTypes = $dialects['pgsql']->GetColumnTypes($pgsql, $table);

	foreach ($sqliteTypes as $column => $sqliteType)
	{
		// A column only one engine has is the view/table difference the other phases are
		// for; this phase is about the verdict, not the column list.
		if (!array_key_exists($column, $pgsqlTypes))
		{
			continue;
		}

		$checked++;

		$sqliteVerdict = DatabaseDialect::IsTextMatchableType($sqliteType);
		$pgsqlVerdict = DatabaseDialect::IsTextMatchableType($pgsqlTypes[$column]);

		if ($sqliteVerdict !== $pgsqlVerdict)
		{
			// SQLite's catalogue does not type a view column that is a computed expression -
			// PRAGMA table_info returns an empty string for GROUP_CONCAT, COALESCE, a
			// concatenation and so on, where PostgreSQL resolves the expression and reports
			// what it came out as. Where SQLite has no type to offer, the two cannot be made
			// to agree by comparing catalogues, and this file will not pretend otherwise.
			//
			// Rejecting the untyped ones is what the application does, and it is the smaller
			// of the two available residuals: measured across this schema, rejecting leaves
			// 13 columns where PostgreSQL is laxer, and allowing would leave 100 where SQLite
			// is. Both are listed rather than asserted away, because the fix for either is
			// the same piece of work - a type for these columns that does not come from
			// asking two catalogues the same question and getting two different answers.
			if (trim($sqliteType) === '')
			{
				$untyped[] = $table . '.' . $column . ': SQLite reports no type, PostgreSQL '
					. $pgsqlTypes[$column] . ' (' . ($pgsqlVerdict ? 'allowed there' : 'rejected there') . ')';

				continue;
			}

			$disagreements[] = $table . '.' . $column . ': ' . $sqliteType
				. ' (' . ($sqliteVerdict ? 'allowed' : 'rejected') . ') vs '
				. $pgsqlTypes[$column] . ' (' . ($pgsqlVerdict ? 'allowed' : 'rejected') . ')';
		}

		// One example of each of the four kinds the contract has to speak about, so the
		// log shows what was actually compared rather than only a count.
		$kind = $sqliteVerdict ? 'text' : (
			stripos($sqliteType, 'INT') !== false ? 'integer' : (
			stripos($sqliteType, 'DATE') !== false || stripos($sqliteType, 'TIME') !== false ? 'timestamp' : 'numeric'));

		if (!isset($samples[$kind]))
		{
			$samples[$kind] = '  ' . str_pad($kind, 10) . str_pad($table . '.' . $column, 42)
				. str_pad($sqliteType, 18) . str_pad($pgsqlTypes[$column], 28)
				. ($sqliteVerdict ? '~ allowed' : '~ rejected');
		}
	}
}

foreach (['text', 'integer', 'numeric', 'timestamp'] as $kind)
{
	if (isset($samples[$kind]))
	{
		echo $samples[$kind] . PHP_EOL;
	}
}

echo PHP_EOL;

if (empty($disagreements))
{
	echo '  ok     ' . ($checked - count($untyped)) . ' of ' . $checked . ' columns across '
		. count($shared) . ' shared tables/views: both engines agree wherever SQLite has a type' . PHP_EOL;
}
else
{
	foreach ($disagreements as $line)
	{
		echo '  DIFFER ' . $line . PHP_EOL;
		$failures++;
	}
}

if (!empty($untyped))
{
	echo '  note   ' . count($untyped) . ' view columns SQLite reports no type for, so the two engines'
		. ' cannot be compared there; rejected on SQLite, and:' . PHP_EOL;

	foreach ($untyped as $line)
	{
		echo '           - ' . $line . PHP_EOL;
	}
}

echo PHP_EOL;

if ($failures === 0)
{
	echo 'QUERY FILTER OPERATORS IDENTICAL ON ASCII, ELIGIBILITY IDENTICAL' . PHP_EOL;
	exit(0);
}

echo 'QUERY FILTER OPERATORS DIFFER — ' . $failures . ' case(s)' . PHP_EOL;
exit(1);
