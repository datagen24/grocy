<?php

// Does the boot check notice a schema that is wrong, and only when it is wrong?
//
//   php schemagatetest.php
//
// Runs against whichever engine VICTUAL_DATAPATH's config.php selects, so the runner can
// point it at SQLite and then at PostgreSQL. See run-tests.sh.
//
// Two failures found in review of plan 10's pull request are what this phase exists to
// keep closed, and neither is visible to any other phase: the other five compare engines
// or exercise write paths, and none of them ever asks the application what it thinks the
// schema version is.
//
// The first is that a maximum is not a schema version. Migrations reach master in the
// order their pull requests merge, so a database can hold 0257 and 0259 and never have
// run 0258 — and MAX(migration) then reports the same 259 a fully migrated database
// reports. A gate built on the maximum calls that schema current forever. Case 2 below
// digs exactly that hole and asserts both halves: that the set-based check finds it, and
// that the maximum-based check would not have.
//
// The second is that not every database failure means "nothing has been migrated". A
// catch wide enough to cover an unreachable server, a role that may not read the table or
// a malformed query, answering zero for all of them, tells an operator whose database is
// down to run migrations at it. Cases 3 to 5 pin the line: a missing migrations table is
// the one condition that reads as an empty database, and it has to be recognised on both
// engines — which is what makes this a differential question rather than a unit test.
// SQLite reports nearly everything as HY000 and PostgreSQL gives each condition its own
// SQLSTATE, so the two implementations of DatabaseDialect::IsMissingTableError() have
// nothing in common except the answer they have to agree on.
//
// The database is mutated as each case needs and restored immediately afterwards, in a
// finally: the runner builds this database from nothing, but a phase that leaves a
// database it damaged behind is a phase that will eventually be run against something
// that matters.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));

if (!defined('VICTUAL_DATAPATH'))
{
	define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH') ?: VICTUAL_ROOT_PATH . '/data');
}

require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';

if (file_exists(VICTUAL_DATAPATH . '/config.php'))
{
	require_once VICTUAL_DATAPATH . '/config.php';
}

require_once VICTUAL_ROOT_PATH . '/config-dist.php';

if (!defined('VICTUAL_USER_ID'))
{
	define('VICTUAL_USER_ID', 1);
}

use Victual\Services\DatabaseMigrationService;
use Victual\Services\DatabaseService;

$db = DatabaseService::GetInstance();
$pdo = $db->GetDbConnectionRaw();
$dialect = $db->GetDialect();
$engine = $dialect->GetName();
$service = DatabaseMigrationService::GetInstance();

$required = DatabaseMigrationService::GetRequiredMigrationNumbers($dialect);
$failures = 0;

/**
 * Drops the service's per-request memo of the applied migrations.
 *
 * Reached by reflection on purpose. The memo is dropped in production by the one thing
 * that can invalidate it — a migration run — and adding a public way to clear it so that
 * this file can ask the same question six times would be test scaffolding in shipped
 * code. A devtool reaching into a private static is the smaller cost.
 */
function ForgetMemo(): void
{
	$memo = new ReflectionProperty(DatabaseMigrationService::class, 'AppliedMigrationNumbers');
	$memo->setValue(null, null);
}

function Ok(string $label, string $detail): void
{
	printf("  ok     %-34s %s\n", $label, $detail);
}

function Fail(string $label, string $detail): void
{
	global $failures;

	$failures++;
	printf("  FAIL   %-34s %s\n", $label, $detail);
}

function Check(string $label, bool $condition, string $expected, string $actual): void
{
	$condition ? Ok($label, $actual) : Fail($label, 'expected ' . $expected . ', got ' . $actual);
}

/** The error the given statement fails with, or null when it does not fail. */
function ErrorFrom(PDO $pdo, string $sql): ?PDOException
{
	try
	{
		$pdo->query($sql);

		return null;
	}
	catch (PDOException $ex)
	{
		return $ex;
	}
}

echo 'The schema version gate (' . $engine . ")\n\n";

// --- 1. A migrated database is current --------------------------------------------

ForgetMemo();
$missing = $service->GetMissingMigrationNumbers($dialect);
$unknown = $service->GetUnknownMigrationNumbers($dialect);

Check('a migrated database is current',
	empty($missing) && empty($unknown),
	'nothing missing and nothing unknown',
	'missing [' . implode(',', $missing) . '], unknown [' . implode(',', $unknown) . ']');

// --- 2. A hole below the maximum --------------------------------------------------
//
// The second highest required migration, so that deleting it leaves MAX(migration)
// exactly where it was. That is the shape the review described: #36 applies 0257 and
// 0259, #34 then introduces 0258, and the maximum never notices.

$hole = $required[count($required) - 2];

try
{
	$pdo->exec('DELETE FROM migrations WHERE migration = ' . $hole);

	ForgetMemo();
	$missing = $service->GetMissingMigrationNumbers($dialect);
	$applied = $service->GetAppliedMigrationNumber();
	$latest = DatabaseMigrationService::GetLatestMigrationNumber($dialect);

	Check('an interior migration is missed',
		$missing === [$hole],
		'missing [' . $hole . ']',
		'missing [' . implode(',', $missing) . ']');

	// Not a redundant assertion of the line above: this is the half that says the case
	// is worth having. If the maximum ever stops matching here, the hole moved rather
	// than the check improving, and case 2 has quietly stopped testing anything.
	Check('...which a maximum cannot see',
		$applied === $latest,
		'the old MAX-based check to report the schema current (' . $latest . ')',
		'MAX(migration) = ' . $applied . ', code at ' . $latest);
}
finally
{
	$pdo->exec('INSERT INTO migrations (migration) VALUES (' . $hole . ')');
	ForgetMemo();
}

// --- 3. No migrations table at all ------------------------------------------------

$pdo->exec('ALTER TABLE migrations RENAME TO migrations_schemagate_probe');

try
{
	ForgetMemo();
	$applied = $service->GetAppliedMigrationNumbers();
	$missing = $service->GetMissingMigrationNumbers($dialect);

	Check('no migrations table reads as empty',
		$applied === [] && $missing === $required,
		'nothing applied and every migration missing',
		count($applied) . ' applied, ' . count($missing) . ' of ' . count($required) . ' missing');
}
catch (PDOException $ex)
{
	Fail('no migrations table reads as empty', 'the check threw: ' . $ex->getMessage());
}
finally
{
	$pdo->exec('ALTER TABLE migrations_schemagate_probe RENAME TO migrations');
	ForgetMemo();
}

// --- 4. Every other failure propagates --------------------------------------------
//
// The migrations table is there and unreadable in a different way, which is the cheapest
// stand-in for the failures that actually matter — an unreachable server, a role without
// SELECT, a statement timeout. All of them have to reach the caller rather than be
// answered with "nothing has been migrated yet".

$pdo->exec('ALTER TABLE migrations RENAME COLUMN migration TO migration_schemagate_probe');

try
{
	ForgetMemo();

	try
	{
		$applied = $service->GetAppliedMigrationNumbers();
		Fail('an unrelated failure propagates', 'the check answered [' . implode(',', $applied) . '] instead of throwing');
	}
	catch (PDOException $ex)
	{
		Ok('an unrelated failure propagates', 'SQLSTATE ' . ($ex->errorInfo[0] ?? $ex->getCode()));
	}

	// And it must not have been remembered. A memoized failure would answer every later
	// request in this process with a lie that outlives the fault.
	ForgetMemo();
}
finally
{
	$pdo->exec('ALTER TABLE migrations RENAME COLUMN migration_schemagate_probe TO migration');
	ForgetMemo();
}

// --- 5. The detection itself ------------------------------------------------------
//
// Asked of the dialect directly, because this is where the two engines differ and where
// a wrong answer would be silent: on SQLite a missing table, a missing column and a
// syntax error share one SQLSTATE.

$missingTable = ErrorFrom($pdo, 'SELECT 1 FROM schemagate_absent_table');
$missingColumn = ErrorFrom($pdo, 'SELECT schemagate_absent_column FROM migrations');
$syntax = ErrorFrom($pdo, 'SELEC 1');

if ($missingTable === null || $missingColumn === null || $syntax === null)
{
	Fail('the missing-table error is recognised', 'one of the three probe statements did not fail at all');
}
else
{
	Check('a missing table is recognised',
		$dialect->IsMissingTableError($missingTable),
		'true',
		var_export($dialect->IsMissingTableError($missingTable), true) . ' (SQLSTATE ' . ($missingTable->errorInfo[0] ?? '?') . ')');

	Check('a missing column is not',
		!$dialect->IsMissingTableError($missingColumn),
		'false',
		var_export($dialect->IsMissingTableError($missingColumn), true) . ' (SQLSTATE ' . ($missingColumn->errorInfo[0] ?? '?') . ')');

	Check('a syntax error is not',
		!$dialect->IsMissingTableError($syntax),
		'false',
		var_export($dialect->IsMissingTableError($syntax), true) . ' (SQLSTATE ' . ($syntax->errorInfo[0] ?? '?') . ')');
}

// --- The database is where it started ---------------------------------------------

ForgetMemo();
$missing = $service->GetMissingMigrationNumbers($dialect);
$unknown = $service->GetUnknownMigrationNumbers($dialect);

Check('the database was left as found',
	empty($missing) && empty($unknown),
	'nothing missing and nothing unknown',
	'missing [' . implode(',', $missing) . '], unknown [' . implode(',', $unknown) . ']');

echo "\n";

if ($failures === 0)
{
	echo "SCHEMA GATE OK\n";
	exit(0);
}

echo $failures . " case(s) failed\n";
exit(1);
