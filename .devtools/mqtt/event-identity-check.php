<?php

// Proves that one transaction produces one event, and that no two events share an identity.
//
//   VICTUAL_DATAPATH=... php .devtools/mqtt/event-identity-check.php
//
// Two defects live here, both silent, and both are about what "one event" means.
//
// The call graph nests. OpenProduct delegates to TransferProduct, ConsumeRecipe wraps
// ConsumeProduct and AddProduct, UndoTransaction loops over UndoBooking - and
// DatabaseService::InTransaction deliberately lets an inner call join the outer one. So an
// event captured at the end of each entrypoint was captured several times per transaction,
// each recording a state that was real only part way through the work: stock amounts
// mid-transfer, bookings not yet undone. Nothing failed; the series just described things
// that never independently committed.
//
// And a transaction id is reused. Undoing a transaction writes rows under the one it names,
// so identity built on (product, transaction, second) had an undo overwrite the purchase it
// reversed whenever the two landed in the same second.
//
// So: one event per transaction, captured at the outermost commit with the final state; and
// a per-event event_id on every point.
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

function Queued(): array
{
	return (new OutboxService())->GetUndelivered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED, 1000);
}

function DrainQueue(): void
{
	(new OutboxService())->MarkDelivered(array_column(Queued(), 'id'));
}

function CurrentAmount(int $productId): float
{
	foreach (DatabaseService::GetInstance()
		->ExecuteDbQuery('SELECT product_id, amount FROM stock_current')
		->fetchAll(\PDO::FETCH_ASSOC) as $row)
	{
		if ((int)$row['product_id'] === $productId)
		{
			return (float)$row['amount'];
		}
	}

	return 0.0;
}

$stock = StockService::GetInstance();
$engine = DatabaseService::GetInstance()->GetDialect()->GetName();

$productId = (int)DatabaseService::GetInstance()
	->ExecuteDbQuery('SELECT id FROM products WHERE active = 1 ORDER BY id LIMIT 1')
	->fetchColumn();

if ($productId === 0)
{
	fwrite(STDERR, "No active product to book against - point VICTUAL_DATAPATH at a migrated database with data.\n");
	exit(1);
}

echo 'engine=' . $engine . ', product ' . $productId . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------------------
echo '1. Every payload is versioned and carries its own event_id' . PHP_EOL;

DrainQueue();
$stock->AddProduct($productId, 3, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 1.25);

$queued = Queued();
Check('one event for one purchase', count($queued) === 1, count($queued) . ' queued');

$payload = $queued[0]['payload'] ?? [];
Check('it declares payload_version ' . OutboxService::PAYLOAD_VERSION,
	($payload['payload_version'] ?? null) === OutboxService::PAYLOAD_VERSION,
	var_export($payload['payload_version'] ?? null, true));
Check('it carries a UUID event_id',
	preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string)($payload['event_id'] ?? '')) === 1,
	(string)($payload['event_id'] ?? 'missing'));
Check('a readable payload is not unreadable', BookingEventPublisher::DescribeUnreadable($payload) === null);

$lines = BookingEventPublisher::BuildLines([$payload]);
Check('every point carries the event_id',
	count($lines) > 0 && count(array_filter($lines, fn($l) => str_contains($l, 'event_id=' . $payload['event_id']))) === count($lines),
	count($lines) . ' line(s)');

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo '2. A nested flow produces one event, with the final state' . PHP_EOL;

DrainQueue();

$locationId = (int)DatabaseService::GetInstance()
	->ExecuteDbQuery('SELECT id FROM locations ORDER BY id LIMIT 1')
	->fetchColumn();

$outerTransaction = null;

// Two entrypoints inside one transaction, which is what an outer service method looks like -
// ConsumeRecipe and OpenProduct both reach this shape through their own nesting
DatabaseService::GetInstance()->InTransaction(function () use ($stock, $productId, $locationId, &$outerTransaction)
{
	$stock->AddProduct($productId, 5, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 2.00, $locationId, null, $outerTransaction);
	$stock->ConsumeProduct($productId, 1, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $outerTransaction);
});

$queued = Queued();
Check('one event for the whole nested transaction', count($queued) === 1, count($queued) . ' queued');

$payload = $queued[0]['payload'] ?? [];
Check('the event covers both bookings', count($payload['bookings'] ?? []) === 2,
	count($payload['bookings'] ?? []) . ' booking(s)');

$finalAmount = CurrentAmount($productId);
$snapshotAmount = null;
foreach ($payload['stock'] ?? [] as $entry)
{
	if ((int)$entry['product_id'] === $productId)
	{
		$snapshotAmount = (float)$entry['amount'];
	}
}

Check('its snapshot is the committed state, not an intermediate one',
	$snapshotAmount !== null && abs($snapshotAmount - $finalAmount) < 0.000001,
	'ledger ' . $finalAmount . ', event ' . var_export($snapshotAmount, true));

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo '3. An undo is its own event, and cannot overwrite what it reverses' . PHP_EOL;

DrainQueue();

$purchaseTransaction = null;
$stock->AddProduct($productId, 2, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 4.00, null, null, $purchaseTransaction);
$stock->UndoTransaction($purchaseTransaction);

$queued = Queued();
Check('two events - the purchase and its undo', count($queued) === 2, count($queued) . ' queued');

$payloads = array_column($queued, 'payload');
$eventIds = array_values(array_unique(array_column($payloads, 'event_id')));

Check('sharing one transaction id', count(array_unique(array_column($payloads, 'transaction_id'))) === 1,
	implode(', ', array_unique(array_column($payloads, 'transaction_id'))));
Check('but with distinct event ids', count($eventIds) === 2, implode(' vs ', $eventIds));

$lines = BookingEventPublisher::BuildLines($payloads);
$stockValue = array_values(array_filter($lines, fn($l) => str_starts_with($l, 'stock_value,')));

foreach ($stockValue as $line)
{
	echo '        ' . $line . PHP_EOL;
}

// A point's identity is its measurement, tag set and timestamp. Two points sharing all
// three are one point in InfluxDB - which is what the undo used to do to the purchase.
$identities = [];
foreach ($lines as $line)
{
	$parts = explode(' ', $line);
	$identities[] = $parts[0] . ' ' . end($parts);
}

Check('no two points share an identity', count($identities) === count(array_unique($identities)),
	count($identities) . ' point(s), ' . count(array_unique($identities)) . ' distinct');

$seconds = array_unique(array_map(fn($l) => substr(strrchr($l, ' '), 1), $stockValue));
echo '        (timestamps ' . (count($seconds) === 1 ? 'identical - the collision case' : 'differ - the collision was not reproduced this run') . ')' . PHP_EOL;

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo '4. An unreadable payload is refused rather than guessed at' . PHP_EOL;

$version1 = ['transaction_id' => 'x'];
Check('a version 1 payload is unreadable', BookingEventPublisher::DescribeUnreadable($version1) !== null,
	(string)BookingEventPublisher::DescribeUnreadable($version1));
Check('a future version is unreadable',
	BookingEventPublisher::DescribeUnreadable(['payload_version' => 99, 'event_id' => 'e', 'transaction_id' => 't', 'occurred_at' => 'now', 'bookings' => [], 'stock' => []]) !== null);
Check('a payload missing its event_id is unreadable',
	BookingEventPublisher::DescribeUnreadable(['payload_version' => OutboxService::PAYLOAD_VERSION, 'transaction_id' => 't', 'occurred_at' => 'now', 'bookings' => [], 'stock' => []]) !== null);
Check('a payload missing its arrays is unreadable',
	BookingEventPublisher::DescribeUnreadable(['payload_version' => OutboxService::PAYLOAD_VERSION, 'event_id' => 'e', 'transaction_id' => 't', 'occurred_at' => 'now']) !== null);
Check('BuildLines produces nothing for one', count(BookingEventPublisher::BuildLines([$version1])) === 0);

echo PHP_EOL;

if (count($failures) > 0)
{
	fwrite(STDERR, count($failures) . " check(s) failed.\n");
	exit(1);
}

echo "All event identity checks passed (engine: $engine).\n";
exit(0);
