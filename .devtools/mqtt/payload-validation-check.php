<?php

// Proves that a malformed event payload is set aside rather than written as zeros.
//
//   VICTUAL_DATAPATH=... php .devtools/mqtt/payload-validation-check.php
//
// The outbox already had a dead-letter state and a version check, and that turned out to be
// the easy half. The hard half is a payload whose *version is right* and whose bookings and
// stock arrays are *present* but whose contents are missing or the wrong type. That used to
// count as readable, and BuildLines() then cast whatever was there: a missing product_id
// became product 0, a missing amount or value became 0.0, a missing timestamp became
// ToNanoseconds('') - a point that looks like data, written into a series nothing will ever
// correct, on a row then marked delivered. Silently, because nothing failed.
//
// So this asserts the three properties that make that impossible:
//
//   1. NOTHING CORRUPT IS BUILT. Every malformed payload produces zero line-protocol lines,
//      and in particular no line carrying product_id=0 or a zero timestamp.
//   2. EVERY BAD ROW IS DEAD-LETTERED, INDIVIDUALLY, WITH THE FIELD THAT FAILED. Not
//      delivered, not retried forever, and last_error names the element and the key rather
//      than saying "unreadable".
//   3. ONE BAD ROW DOES NOT STOP THE BATCH. A valid event queued behind eight malformed ones
//      is still deliverable afterwards.
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
		->ExecuteDbQuery('SELECT id, payload, delivered_at, dead_lettered_at, attempts, last_error FROM outbox ORDER BY id')
		->fetchAll(\PDO::FETCH_ASSOC);
}

$engine = DatabaseService::GetInstance()->GetDialect()->GetName();
echo 'engine=' . $engine . PHP_EOL . PHP_EOL;

$stock = StockService::GetInstance();
$productId = (int)DatabaseService::GetInstance()
	->ExecuteDbQuery('SELECT id FROM products WHERE active = 1 ORDER BY id LIMIT 1')
	->fetchColumn();

if ($productId === 0)
{
	fwrite(STDERR, "No active product to book against - point VICTUAL_DATAPATH at a migrated database with data.\n");
	exit(1);
}

// A well formed version 2 event, which each case below then breaks in exactly one place.
// Written out rather than captured from a booking so that the difference between the valid
// payload and each broken one is visible in this file.
function ValidPayload(): array
{
	return [
		'payload_version' => OutboxService::PAYLOAD_VERSION,
		'event_id' => '3f2a6c1e-9d4b-4c8a-8f1e-2b6d5a0c7e93',
		'transaction_id' => 'probe-transaction',
		'occurred_at' => '2026-09-02 10:00:00',
		'bookings' => [[
			'booking_id' => 4242,
			'product_id' => 7,
			'amount' => 2.0,
			'price' => 1.5,
			'transaction_type' => 'purchase',
			'undone' => 0,
			'row_created_timestamp' => '2026-09-02 10:00:00'
		]],
		'stock' => [[
			'product_id' => 7,
			'amount' => 2.0,
			'value' => 3.0
		]]
	];
}

/**
 * Applies one mutation to a copy of the valid payload.
 */
function Broken(callable $break): array
{
	$payload = ValidPayload();
	$break($payload);

	return $payload;
}

// Each case is one nested defect, and each is a real shape rather than an invented one: an
// older writer that omitted a key, a driver handing back something unexpected, a truncated
// row. The expected fragment is what last_error has to contain, so that a person reading the
// column learns which field failed rather than that something did.
$cases = [
	'a booking with no product_id' => [
		Broken(function (array &$p) { unset($p['bookings'][0]['product_id']); }),
		'bookings[0].product_id'
	],
	'a booking with no amount' => [
		Broken(function (array &$p) { unset($p['bookings'][0]['amount']); }),
		'bookings[0].amount'
	],
	'a booking whose amount is not a number' => [
		Broken(function (array &$p) { $p['bookings'][0]['amount'] = 'lots'; }),
		'bookings[0].amount'
	],
	'a booking whose price is a string' => [
		Broken(function (array &$p) { $p['bookings'][0]['price'] = 'free'; }),
		'bookings[0].price'
	],
	'a booking with an empty timestamp' => [
		Broken(function (array &$p) { $p['bookings'][0]['row_created_timestamp'] = ''; }),
		'bookings[0].row_created_timestamp'
	],
	'a booking whose timestamp is a phrase' => [
		Broken(function (array &$p) { $p['bookings'][0]['row_created_timestamp'] = 'now'; }),
		'bookings[0].row_created_timestamp'
	],
	'a booking with no transaction_type' => [
		Broken(function (array &$p) { unset($p['bookings'][0]['transaction_type']); }),
		'bookings[0].transaction_type'
	],
	'a stock entry with no value' => [
		Broken(function (array &$p) { unset($p['stock'][0]['value']); }),
		'stock[0].value'
	],
	'a stock entry with no product_id' => [
		Broken(function (array &$p) { unset($p['stock'][0]['product_id']); }),
		'stock[0].product_id'
	],
	'a stock entry that is not an object' => [
		Broken(function (array &$p) { $p['stock'][0] = 'nothing'; }),
		'stock[0]'
	],
	'an event_id that is not a UUID' => [
		Broken(function (array &$p) { $p['event_id'] = 'not-a-uuid'; }),
		'event_id'
	],
	'an event_id that is missing' => [
		Broken(function (array &$p) { unset($p['event_id']); }),
		'event_id'
	],
	'an occurred_at that is not a timestamp' => [
		Broken(function (array &$p) { $p['occurred_at'] = 'yesterday'; }),
		'occurred_at'
	],
	'a transaction_id that is blank' => [
		Broken(function (array &$p) { $p['transaction_id'] = '   '; }),
		'transaction_id'
	],
	'bookings that is not an array' => [
		Broken(function (array &$p) { $p['bookings'] = 'one'; }),
		'bookings'
	]
];

// ---------------------------------------------------------------------------------------
echo "1. The valid payload is readable, and produces the points it should" . PHP_EOL;

Check('the reference payload is readable', BookingEventPublisher::DescribeUnreadable(ValidPayload()) === null,
	(string)BookingEventPublisher::DescribeUnreadable(ValidPayload()));

$referenceLines = BookingEventPublisher::BuildLines([ValidPayload()]);
Check('and builds two points', count($referenceLines) === 2, count($referenceLines) . ' line(s)');

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "2. Nothing corrupt is built from a malformed payload" . PHP_EOL;

foreach ($cases as $what => [$payload, $expectedFragment])
{
	$reason = BookingEventPublisher::DescribeUnreadable($payload);

	Check($what . ' is refused', $reason !== null, (string)$reason);
	Check('  and says which field failed', $reason !== null && str_contains($reason, $expectedFragment),
		'expected "' . $expectedFragment . '"');

	$lines = BookingEventPublisher::BuildLines([$payload]);
	Check('  and builds no line at all', count($lines) === 0, implode(' | ', $lines));
}

// The specific corruption the old shape produced: absent values cast to zero. Asserted over
// every case at once, because it is the *output* that mattered, not which key was missing.
$allLines = BookingEventPublisher::BuildLines(array_column($cases, 0));
Check('no point was built for any malformed payload', count($allLines) === 0, count($allLines) . ' line(s)');
Check('so no point carries product_id=0', count(array_filter($allLines, fn($l) => str_contains($l, 'product_id=0'))) === 0);

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "3. Each bad row is dead-lettered individually, and the valid one behind them survives" . PHP_EOL;

$outbox = new OutboxService();

// Everything queued so far out of the way, so the batch below is exactly what is asserted.
// Rows already set aside are left alone rather than acknowledged - on the PostgreSQL leg
// this database is imported from the one the SQLite leg has just finished with, so it
// arrives carrying that run's dead-lettered rows, and marking those delivered would make
// them look like the failure this probe is looking for.
$outbox->MarkDelivered(array_column(
	array_filter(OutboxRows(), fn($row) => $row['dead_lettered_at'] === null),
	'id'
));

// Everything from here on is this run's, which is how the imported rows stay out of the
// counts below
$firstId = ((int)DatabaseService::GetInstance()
	->ExecuteDbQuery('SELECT COALESCE(MAX(id), 0) FROM outbox')
	->fetchColumn()) + 1;

foreach ($cases as [$payload, $expectedFragment])
{
	$outbox->Enqueue(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED, $payload);
}

// A real booking behind them, which must still be deliverable afterwards
$stock->AddProduct($productId, 1, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 4.44);

$waitingBefore = $outbox->CountUndelivered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED);
Check('every case plus one valid event is waiting', $waitingBefore === count($cases) + 1,
	$waitingBefore . ' waiting, expected ' . (count($cases) + 1));

// The stand-in rejects, so the valid row stays queued - which is exactly what proves it was
// neither dead-lettered nor swept up with the batch
BookingEventPublisher::Drain();

$deadLettered = 0;
$valid = null;

foreach (OutboxRows() as $row)
{
	if ((int)$row['id'] < $firstId)
	{
		continue;
	}

	if ($row['dead_lettered_at'] !== null)
	{
		$deadLettered++;

		Check('  dead-lettered row ' . $row['id'] . ' was not also marked delivered', $row['delivered_at'] === null);
		Check('  dead-lettered row ' . $row['id'] . ' records why', !empty($row['last_error']),
			substr((string)$row['last_error'], 0, 70));
		Check('  dead-lettered row ' . $row['id'] . ' had its attempt counted', (int)$row['attempts'] > 0);
	}
	elseif ($row['delivered_at'] === null)
	{
		// Neither delivered nor set aside: the only row left in that state is the real
		// booking, which the rejecting stand-in leaves queued
		$valid = $row;
	}
}

Check('every malformed row is dead-lettered', $deadLettered === count($cases),
	$deadLettered . ' of ' . count($cases));
Check('the valid row behind them is still waiting', $valid !== null);
Check('so it is the only one left to deliver',
	$outbox->CountUndelivered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED) === 1,
	$outbox->CountUndelivered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED) . ' waiting');
Check('and the dead-lettered ones are counted as such',
	$outbox->CountDeadLettered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED) >= count($cases),
	$outbox->CountDeadLettered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED) . ' set aside in total');

echo PHP_EOL;

if (count($failures) > 0)
{
	fwrite(STDERR, count($failures) . " check(s) failed.\n");
	exit(1);
}

echo "All payload validation checks passed (engine: $engine).\n";
exit(0);
