<?php

// Proves that a redelivered outbox event produces exactly the same InfluxDB points.
//
//   VICTUAL_DATAPATH=... php .devtools/mqtt/idempotency-check.php
//
// This guards the property the whole outbox rests on. A point in InfluxDB is identified by
// its measurement, tag set and timestamp, so writing the same identity again overwrites
// rather than appends - which is what lets delivery be at-least-once. The moment any line
// is derived at delivery time instead of at capture time, that stops being true, and both
// ways it stopped were real defects:
//
//   1. stock_value used the current clock, so a retry after a POST that succeeded but was
//      never acknowledged wrote a *second* point one second later.
//   2. stock_value re-read stock_current, so a backlog drained after an outage gave every
//      queued transaction the latest snapshot instead of each one's own post-commit value.
//
// So two assertions, and neither can pass by accident:
//
//   A. REBUILD. Build the lines for one event, wait past a second boundary, build them
//      again, and require the two to be byte-identical. The wait is what makes it a test:
//      without it the clock would agree by luck.
//   B. PER-TRANSACTION SNAPSHOT. Queue two transactions on one product with different
//      resulting amounts, then build both events in one batch and require each stock_value
//      point to carry its own post-commit amount rather than the later one.
//
// Nothing here talks to InfluxDB - it is about what the lines say, not about delivering
// them. outbox-check.php covers delivery.
//
// Exit codes: 0 when both hold.

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

/**
 * The product's current stock amount, read the way CaptureEvent() reads it - the whole view,
 * filtered in PHP. A bound "WHERE product_id = ?" does not match here: PDO binds the value
 * as a string by default and stock_current's product_id carries no column affinity to
 * coerce it back, so the comparison quietly returns nothing.
 */
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

function UndeliveredPayloads(): array
{
	return array_column(
		(new OutboxService())->GetUndelivered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED),
		'payload'
	);
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

$engine = DatabaseService::GetInstance()->GetDialect()->GetName();

// ---------------------------------------------------------------------------------------
echo 'engine=' . $engine . PHP_EOL . PHP_EOL;
echo 'A. Rebuilding an event across a second boundary' . PHP_EOL;

$stock->AddProduct($productId, 2, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 1.50);

$payloads = UndeliveredPayloads();
Check('the booking enqueued an event', count($payloads) > 0, count($payloads) . ' undelivered');

$first = BookingEventPublisher::BuildLines($payloads);

// Past the next whole second, so a delivery-time clock could not possibly agree with itself
$startedAt = time();
while (time() === $startedAt)
{
	usleep(50000);
}

// And change the stock underneath it, so a delivery-time re-read could not agree either
$stock->AddProduct($productId, 7, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 9.99);

$second = BookingEventPublisher::BuildLines($payloads);

foreach (array_slice($first, 0, 4) as $line)
{
	echo '        ' . $line . PHP_EOL;
}

Check('the rebuild is byte-identical', $first === $second,
	count($first) . ' line(s), rebuilt ' . (time() - $startedAt) . 's later with the stock changed in between');

if ($first !== $second)
{
	foreach (array_diff($second, $first) as $line)
	{
		echo '        second run only: ' . $line . PHP_EOL;
	}
}

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo 'B. Two queued transactions on one product keep their own snapshots' . PHP_EOL;

// Everything queued so far is in the way of a clean read
(new OutboxService())->MarkDelivered(array_column(
	(new OutboxService())->GetUndelivered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED),
	'id'
));

$firstTransaction = null;
$secondTransaction = null;

$stock->AddProduct($productId, 3, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 1.00, null, null, $firstTransaction);
$amountAfterFirst = CurrentAmount($productId);

$stock->AddProduct($productId, 4, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 1.00, null, null, $secondTransaction);
$amountAfterSecond = CurrentAmount($productId);

Check('the two transactions left different amounts', $amountAfterFirst !== $amountAfterSecond,
	$amountAfterFirst . ' then ' . $amountAfterSecond);

// Both drained in one batch, which is the case that was wrong
$lines = BookingEventPublisher::BuildLines(UndeliveredPayloads());
$stockValue = array_values(array_filter($lines, fn($line) => str_starts_with($line, 'stock_value,')));

foreach ($stockValue as $line)
{
	echo '        ' . $line . PHP_EOL;
}

$amounts = [];
foreach ($stockValue as $line)
{
	if (preg_match('/transaction_id=([^ ,]+).*amount=([0-9.]+)/', $line, $m))
	{
		$amounts[$m[1]] = (float)$m[2];
	}
}

Check('the first transaction kept its own post-commit amount',
	isset($amounts[$firstTransaction]) && abs($amounts[$firstTransaction] - $amountAfterFirst) < 0.000001,
	'expected ' . $amountAfterFirst . ', got ' . ($amounts[$firstTransaction] ?? 'nothing'));

Check('the second transaction kept its own',
	isset($amounts[$secondTransaction]) && abs($amounts[$secondTransaction] - $amountAfterSecond) < 0.000001,
	'expected ' . $amountAfterSecond . ', got ' . ($amounts[$secondTransaction] ?? 'nothing'));

Check('they are not the same number', count(array_unique(array_values($amounts))) === 2,
	implode(' vs ', array_values($amounts)));

echo PHP_EOL;

if (count($failures) > 0)
{
	fwrite(STDERR, count($failures) . " check(s) failed.\n");
	exit(1);
}

echo "All idempotency checks passed (engine: $engine).\n";
exit(0);
