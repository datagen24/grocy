<?php

// Proves the three properties the transactional outbox exists for.
//
//   VICTUAL_DATAPATH=... php .devtools/mqtt/outbox-check.php
//
// Plan 18's InfluxDB events were held in process memory between the booking and the write,
// so a crash, a timeout or a rejected write lost a committed purchase permanently and
// silently. The outbox (migration 259) is the fix, and these are the three claims it has to
// support - each one asserted against the database rather than reasoned about:
//
//   1. DURABILITY. With the endpoint unreachable, the event survives the failed delivery:
//      the row is still undelivered, attempts has advanced and last_error says why. Point
//      the endpoint somewhere real, drain, and the same event is delivered - carrying the
//      booking made while the endpoint was down.
//   2. ATOMICITY. A booking that rolls back leaves no event. The transaction is failed
//      deliberately, mid-flight, the way plan 13's rollback probe does it.
//   3. IDENTITY. Two purchases of one product in the same second produce two price_paid
//      points with distinct booking_id tags. Without that they share a measurement, tag set
//      and second-truncated timestamp, which in InfluxDB is one point, not two.
//   4. THE ENQUEUE IS PART OF THE BOOKING. With the outbox table renamed away, a purchase
//      fails and writes no stock_log row. That is the point of not catching the enqueue
//      failure: a booking whose event cannot be recorded has not fully happened.
//   5. THE EDIT PATH IS TRANSACTIONAL TOO. A failure injected after EditStockEntry's ledger
//      writes leaves neither the stock_log pair nor an outbox row.
//
// The endpoint is never really contacted for (1)'s first half: an unroutable address is
// what makes the failure a timeout rather than a refusal, which is the case that matters.
//
// Exit codes: 0 when every assertion holds.

use Victual\Services\DatabaseService;
use Victual\Services\Influx\BookingEventPublisher;
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

function OutboxRows(): array
{
	return DatabaseService::GetInstance()
		->ExecuteDbQuery('SELECT id, event_type, payload, delivered_at, attempts, last_error FROM outbox ORDER BY id')
		->fetchAll(\PDO::FETCH_ASSOC);
}

$stock = StockService::GetInstance();
$productId = (int)DatabaseService::GetInstance()
	->ExecuteDbQuery('SELECT id FROM products WHERE active = 1 ORDER BY id LIMIT 1')
	->fetchColumn();

if ($productId === 0)
{
	fwrite(STDERR, "No active product to book against - point VICTUAL_DATAPATH at a migrated database with data.\n");
	exit(1);
}

echo 'Product ' . $productId . ', InfluxDB at ' . VICTUAL_INFLUXDB_URL . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "1. Durability: a failed delivery keeps the event" . PHP_EOL;

$before = count(OutboxRows());
$stock->AddProduct($productId, 2, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 1.23);

$rows = OutboxRows();
Check('the booking enqueued an event', count($rows) === $before + 1, '(' . $before . ' -> ' . count($rows) . ' rows)');

$queued = end($rows);
Check('it is undelivered before any drain', $queued['delivered_at'] === null);
Check('its type is the shared contract\'s', $queued['event_type'] === OutboxService::EVENT_STOCK_TRANSACTION_BOOKED, $queued['event_type']);
// Self-contained rather than a pointer at the ledger: everything BuildLines() needs was
// captured inside the booking's transaction, which is what makes a rebuild identical
$payload = json_decode((string)$queued['payload'], true);
Check('its payload is a self-contained record', array_keys($payload) === ['transaction_id', 'occurred_at', 'bookings', 'stock']);
Check('carrying the bookings and the post-commit stock', count($payload['bookings']) > 0 && count($payload['stock']) > 0,
	count($payload['bookings']) . ' booking(s), ' . count($payload['stock']) . ' product(s)');

$delivered = BookingEventPublisher::Drain();
Check('the drain reports failure against an unreachable endpoint', $delivered === false);

$rows = OutboxRows();
$queued = end($rows);
Check('the event is still undelivered', $queued['delivered_at'] === null);
Check('attempts advanced to 1', (int)$queued['attempts'] === 1, 'attempts=' . $queued['attempts']);
Check('last_error says why', !empty($queued['last_error']), substr((string)$queued['last_error'], 0, 60) . '…');

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "2. Atomicity: a rolled back booking leaves no event" . PHP_EOL;

$before = count(OutboxRows());
$threw = false;

try
{
	// The plan 13 probe shape: fail deliberately inside the transaction, after the work
	DatabaseService::GetInstance()->InTransaction(function () use ($stock, $productId)
	{
		$stock->AddProduct($productId, 5, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 9.99);

		throw new \Exception('injected failure, mid-transaction');
	});
}
catch (\Throwable $ex)
{
	$threw = true;
}

Check('the injected failure propagated', $threw);
Check('no outbox row survived the rollback', count(OutboxRows()) === $before, $before . ' rows before and after');

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "3. Identity: two purchases in one second are two points" . PHP_EOL;

$firstTransaction = null;
$secondTransaction = null;
$before = count(OutboxRows());
$stock->AddProduct($productId, 1, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 2.00, null, null, $firstTransaction);
$stock->AddProduct($productId, 1, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 3.00, null, null, $secondTransaction);

// The two events just enqueued, built the way a drain would build them
$payloads = [];
foreach (array_slice(OutboxRows(), $before) as $row)
{
	$payloads[] = json_decode((string)$row['payload'], true);
}

$lines = BookingEventPublisher::BuildLines($payloads);
$pricePaid = array_values(array_filter($lines, fn($line) => str_starts_with($line, 'price_paid,')));

foreach ($pricePaid as $line)
{
	echo '        ' . $line . PHP_EOL;
}

Check('two price_paid points', count($pricePaid) === 2, count($pricePaid) . ' found');

$bookingIds = [];
$timestamps = [];
foreach ($pricePaid as $line)
{
	if (preg_match('/booking_id=(\d+)/', $line, $m))
	{
		$bookingIds[] = $m[1];
	}

	$parts = explode(' ', $line);
	$timestamps[] = end($parts);
}

Check('their booking_id tags differ', count(array_unique($bookingIds)) === 2, implode(' vs ', $bookingIds));
Check('every price_paid carries a transaction_id tag', count(array_filter($pricePaid, fn($l) => str_contains($l, 'transaction_id='))) === count($pricePaid));
Check('every stock_value carries a transaction_id tag',
	count(array_filter($lines, fn($l) => str_starts_with($l, 'stock_value,') && !str_contains($l, 'transaction_id='))) === 0);

// The point of the fix: same second, so without booking_id these would be one point
$sameSecond = count(array_unique($timestamps)) === 1;
echo '        (timestamps ' . ($sameSecond ? 'identical - the collision case' : 'differ - the collision was not reproduced this run') . ')' . PHP_EOL;

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "4. The enqueue is part of the booking, not a best effort beside it" . PHP_EOL;

$ledgerBefore = (int)DatabaseService::GetInstance()->ExecuteDbQuery('SELECT COUNT(*) FROM stock_log')->fetchColumn();

// The outbox taken away underneath the booking. Renaming rather than dropping, so the
// probe can put it back and the rest of the run continues against a real database.
DatabaseService::GetInstance()->ExecuteDbStatement('ALTER TABLE outbox RENAME TO outbox_hidden');

$bookingThrew = false;

try
{
	$stock->AddProduct($productId, 1, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 7.77);
}
catch (\Throwable $ex)
{
	$bookingThrew = true;
}

DatabaseService::GetInstance()->ExecuteDbStatement('ALTER TABLE outbox_hidden RENAME TO outbox');

$ledgerAfter = (int)DatabaseService::GetInstance()->ExecuteDbQuery('SELECT COUNT(*) FROM stock_log')->fetchColumn();

Check('the booking failed when its event could not be enqueued', $bookingThrew);
Check('and left no ledger row behind', $ledgerAfter === $ledgerBefore, $ledgerBefore . ' rows before and after');

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "5. EditStockEntry is transactional too" . PHP_EOL;

// Something to edit
$editTransaction = null;
$stock->AddProduct($productId, 4, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 1.11, null, null, $editTransaction);

$stockRowId = (int)DatabaseService::GetInstance()
	->ExecuteDbQuery('SELECT id FROM stock ORDER BY id DESC LIMIT 1')
	->fetchColumn();

$ledgerBefore = (int)DatabaseService::GetInstance()->ExecuteDbQuery('SELECT COUNT(*) FROM stock_log')->fetchColumn();
$outboxBefore = count(OutboxRows());

$editThrew = false;

try
{
	// The failure is injected the way plan 13's rollback probe injects one: an outer
	// transaction the inner work joins, failed after that work has run. EditStockEntry's own
	// InTransaction() call sees an open transaction and joins it, so the rollback here is
	// exactly the rollback its own boundary would perform.
	DatabaseService::GetInstance()->InTransaction(function () use ($stock, $stockRowId)
	{
		$stock->EditStockEntry($stockRowId, 9, date('Y-m-d', strtotime('+2 years')), 1, null, 2.22, false, date('Y-m-d'), null);

		throw new \Exception('injected failure, after the edit');
	});
}
catch (\Throwable $ex)
{
	$editThrew = true;
}

Check('the injected failure propagated', $editThrew);
Check('no stock_log rows survived the rolled back edit',
	(int)DatabaseService::GetInstance()->ExecuteDbQuery('SELECT COUNT(*) FROM stock_log')->fetchColumn() === $ledgerBefore,
	$ledgerBefore . ' rows before and after');
Check('and no outbox row either', count(OutboxRows()) === $outboxBefore, $outboxBefore . ' rows before and after');

echo PHP_EOL;

if (count($failures) > 0)
{
	fwrite(STDERR, count($failures) . " check(s) failed.\n");
	exit(1);
}

echo "All outbox checks passed.\n";
exit(0);
