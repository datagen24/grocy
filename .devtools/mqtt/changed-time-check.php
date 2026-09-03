<?php

// Proves that publishing and draining never rewind the global change timestamp.
//
//   VICTUAL_DATAPATH=... php .devtools/mqtt/changed-time-check.php
//
// GET /api/system/db-changed-time is a timestamp, not a version, and the Home Assistant
// integration and the Swift client both decide whether to refetch by comparing it with the
// last one they saw. So a value that goes *backwards* is not a cosmetic wobble: a change
// committed between a snapshot and its restore is one the client never learns about, for as
// long as nothing else happens to write.
//
// The publication ledger and the outbox both used to take that snapshot - read the changed
// time, write their bookkeeping row, put the old value back - which is the idiom
// SessionService and ApiKeyService use for their last-used stamps. It is safe there and not
// here. A publish runs at the end of a request and a drain runs beside it, neither holding
// anything that stops another request committing in the window; the publication lock
// serializes publishers, not application writes. So both now run under
// DatabaseService::RunAsBookkeeping(), which suppresses change tracking instead of undoing
// it, and this probe is what says so:
//
//   1. NO WRITE AT ALL (PostgreSQL). The changed time lives in a table there, so "was it
//      written?" is answerable exactly rather than by comparing values: the row's xmin is
//      unchanged across a ledger and outbox write. A snapshot-and-restore implementation
//      fails this even though the value it restores is identical.
//   2. A CONCURRENT WRITER'S NEWER VALUE SURVIVES (both engines). The race itself,
//      reproduced against RunAsBookkeeping() - which is the helper both services delegate to,
//      and the only place the window can be opened from outside: a section that has already
//      written its bookkeeping row, another connection committing a newer changed time inside
//      it, and the newer value still standing when the section ends.
//   3. THE DIRTY FLAG IS NOT ERASED (both engines). SetDbChangedTime() also clears "this
//      request wrote data", so the old idiom made a bookkeeping write forget a real one that
//      preceded it. Suppression leaves it alone. This is the check that catches the idiom
//      coming back inside one service's own wrapper, where nothing outside can interleave with
//      it; on PostgreSQL the xmin check catches the same regression exactly.
//
// Exit codes: 0 when every assertion holds.

use Victual\Services\DatabaseService;
use Victual\Services\Mqtt\PublicationLedger;
use Victual\Services\Outbox\OutboxService;
use Victual\Services\StockService;

if (PHP_SAPI !== 'cli')
{
	exit('This is a command line script');
}

if (!defined('VICTUAL_DATAPATH'))
{
	define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH') ?: __DIR__ . '/../../data');
}

require_once __DIR__ . '/../../packages/autoload.php';

if (file_exists(VICTUAL_DATAPATH . '/config.php'))
{
	require_once VICTUAL_DATAPATH . '/config.php';
}

require_once __DIR__ . '/../../config-dist.php';

if (!defined('VICTUAL_USER_ID'))
{
	define('VICTUAL_USER_ID', 1);
}

$failures = [];

function Check(string $what, bool $ok, string $detail = '')
{
	global $failures;

	echo ($ok ? '  ok    ' : '  FAIL  ') . $what . ($detail === '' ? '' : '   ' . $detail) . PHP_EOL;

	if (!$ok)
	{
		$failures[] = $what;
	}
}

$database = DatabaseService::GetInstance();
$engine = $database->GetDialect()->GetName();

echo 'engine=' . $engine . PHP_EOL . PHP_EOL;

/**
 * The transaction id of the changed-time row, which changes on any UPDATE to it.
 *
 * PostgreSQL only, and it is the whole point of asking: comparing the *value* cannot tell a
 * write that put the same value back from no write at all, and it is the write that is the
 * defect.
 */
function ChangedTimeRowVersion(): ?string
{
	$value = DatabaseService::GetInstance()
		->ExecuteDbQuery('SELECT xmin::text FROM system_db_changed_time WHERE id = 1')
		->fetchColumn();

	return $value === false ? null : (string)$value;
}

/**
 * Moves the changed time to a distinctive future value from *outside* the application's
 * change tracking, standing in for another request that committed and flushed a newer one.
 *
 * A future value rather than "now" so the assertion is unambiguous on both engines: on
 * SQLite the changed time is the database file's modification time, which our own writes set
 * to the present, and a sentinel in the present would be indistinguishable from one of ours.
 * It is otherwise exactly what a concurrent writer leaves behind - a value nobody else may
 * overwrite with an older one.
 *
 * @return int The sentinel, as a Unix timestamp
 */
function CommitConcurrentChangedTime(): int
{
	$sentinel = time() + 300;

	if (DatabaseService::GetInstance()->GetDialect()->GetName() === 'pgsql')
	{
		// A second connection, so nothing about this goes through the service under test
		$dsn = 'pgsql:host=' . VICTUAL_DB_HOST . ';port=' . intval(VICTUAL_DB_PORT) . ';dbname=' . VICTUAL_DB_NAME;
		$other = new \PDO($dsn, VICTUAL_DB_USER, VICTUAL_DB_PASSWORD);
		$other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$other->exec("SET TIME ZONE " . $other->quote(date_default_timezone_get()));

		$statement = $other->prepare('UPDATE system_db_changed_time SET changed_time = ? WHERE id = 1');
		$statement->execute([date('Y-m-d H:i:s', $sentinel)]);

		return $sentinel;
	}

	// On SQLite the changed time is the file modification time, and another process writing
	// the database is what moves it. touch() is that, without a second process.
	touch(DatabaseService::GetInstance()->GetDialect()->GetDbFilePath(), $sentinel);
	clearstatcache(true, DatabaseService::GetInstance()->GetDialect()->GetDbFilePath());

	return $sentinel;
}

$ledger = new PublicationLedger();
$outbox = new OutboxService();

// ---------------------------------------------------------------------------------------
echo "1. A bookkeeping write is not a data change" . PHP_EOL;

$ledger->Record('probe/entity', 'hash-one');
$ledger->Record('probe/entity', 'hash-two');
$ledger->Forget('probe/entity');

Check('the ledger writes did not mark the request dirty', $database->HasDataChanged() === false);

$outbox->Enqueue(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED, ['payload_version' => OutboxService::PAYLOAD_VERSION]);

// Enqueue is a data change on purpose - it is part of the caller's transaction - so it is
// the acknowledgement side that has to be invisible
$queuedId = (int)DatabaseService::GetInstance()
	->ExecuteDbQuery('SELECT id FROM outbox ORDER BY id DESC LIMIT 1')
	->fetchColumn();

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "2. A real data change survives the bookkeeping that follows it" . PHP_EOL;

$stock = StockService::GetInstance();
$productId = (int)DatabaseService::GetInstance()
	->ExecuteDbQuery('SELECT id FROM products WHERE active = 1 ORDER BY id LIMIT 1')
	->fetchColumn();

if ($productId === 0)
{
	fwrite(STDERR, "No active product to book against - point VICTUAL_DATAPATH at a migrated database with data.\n");
	exit(1);
}

$stock->AddProduct($productId, 1, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 1.11);

Check('the booking marked the request dirty', $database->HasDataChanged() === true);

$outbox->MarkDelivered([$queuedId]);
$outbox->RecordFailure([$queuedId], 'probe');
$ledger->Record('probe/entity', 'hash-three');

// The old idiom cleared this: DatabaseService::SetDbChangedTime() clears the dirty flag as
// part of restoring the timestamp, so acknowledging a delivery made the request forget that
// it had also changed data - and the request-end publish then skipped a snapshot it owed.
Check('and acknowledging a delivery did not erase that', $database->HasDataChanged() === true);

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "3. A concurrent writer's newer changed time is not overwritten" . PHP_EOL;

// The race, reproduced. The bookkeeping section writes its row first - which is when the old
// idiom took its snapshot - and the concurrent commit lands before the section ends, which is
// when the old idiom put the snapshot back over it.
$sentinel = null;

// Read once first, which is also what flushes the booking above: PostgreSQL defers the
// changed-time UPDATE to the next read or to request end, and a deferred real change landing
// after the sentinel would move the time backwards for a reason that has nothing to do with
// what is being measured here.
$database->GetDbChangedTime();

$database->RunAsBookkeeping(function () use ($ledger, $outbox, $queuedId, &$sentinel)
{
	$ledger->Record('probe/race', 'hash-race');
	$outbox->MarkDelivered([$queuedId]);

	$sentinel = CommitConcurrentChangedTime();
});

$after = strtotime($database->GetDbChangedTime());

Check('the concurrent value is still there afterwards', $after >= $sentinel,
	'changed time ' . date('Y-m-d H:i:s', $after) . ', concurrent writer left ' . date('Y-m-d H:i:s', $sentinel));

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "4. On PostgreSQL, the changed time row is not written at all" . PHP_EOL;

if ($engine !== 'pgsql')
{
	echo '        (skipped: the changed time is the database file\'s modification time on SQLite,'
		. ' which the operating system advances with no way to ask whether we wrote it)' . PHP_EOL;
}
else
{
	// Read through the service first, so any deferred mark is flushed and what follows is
	// measuring the bookkeeping writes rather than a pending real one
	$database->GetDbChangedTime();

	$before = ChangedTimeRowVersion();

	$ledger->Record('probe/xmin', 'hash-xmin');
	$ledger->Forget('probe/xmin');
	$outbox->RecordFailure([$queuedId], 'probe again');
	$outbox->DeadLetter([$queuedId], 'probe again');

	$database->GetDbChangedTime();

	Check('the row version is unchanged, so nothing wrote it', ChangedTimeRowVersion() === $before,
		'xmin ' . $before . ' -> ' . ChangedTimeRowVersion());
}

echo PHP_EOL;

if (count($failures) > 0)
{
	fwrite(STDERR, count($failures) . " check(s) failed.\n");
	exit(1);
}

echo "All changed-time checks passed (engine: $engine).\n";
exit(0);
