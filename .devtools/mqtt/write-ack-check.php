<?php

// Proves that an event is only acknowledged when the write endpoint acknowledged it.
//
//   VICTUAL_DATAPATH=... VICTUAL_STANDIN_LOG=... VICTUAL_STANDIN_CONTROL=... \
//     php .devtools/mqtt/write-ack-check.php
//
// The drain turns InfluxEventWriter::Write()'s bool into `delivered_at`, so anything that
// bool reports as true without an acknowledgement is a committed event discarded silently -
// the one failure the outbox exists to prevent, arriving at the last step and leaving no
// trace at all: no attempts, no last_error, and no retry ever.
//
// Guzzle's defaults were not that check. `http_errors` raises for 4xx and 5xx but not for
// 3xx, and `allow_redirects` is on - so a bare 302 came back as an ordinary response nobody
// looked at, and a 302 followed to a login page came back as an HTTP 200 written by
// something that is not InfluxDB. Both marked the row delivered.
//
// Three shapes, run through the real Drain() rather than through Write() alone, because what
// is being asserted is what the *outbox row* looks like afterwards:
//
//   1. A BARE 302 IS NOT A DELIVERY. The row stays undelivered, attempts advances, last_error
//      names the status - and /login is never requested, which is what proves the redirect
//      was refused rather than followed.
//   2. A 200 WITH A BODY IS NOT A DELIVERY EITHER. InfluxDB's write API answers 204 with no
//      body, so a 2xx carrying a page was answered by something in front of it.
//   3. A REAL 204 IS. The same event, same drain, delivered.
//
// Exit codes: 0 when every assertion holds.

use Victual\Services\DatabaseService;
use Victual\Services\Influx\BookingEventPublisher;
use Victual\Services\Influx\InfluxEventWriter;
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

$controlFile = getenv('VICTUAL_STANDIN_CONTROL');
$logFile = getenv('VICTUAL_STANDIN_LOG');

if ($controlFile === false || $controlFile === '' || $logFile === false || $logFile === '')
{
	fwrite(STDERR, "VICTUAL_STANDIN_CONTROL and VICTUAL_STANDIN_LOG must both be set - this probe drives the stand-in's mode.\n");
	exit(1);
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

function SetStandinMode(string $mode): void
{
	global $controlFile, $logFile;

	file_put_contents($controlFile, $mode);

	// Truncated with the mode, so "which requests did this case make" is answerable
	file_put_contents($logFile, '');
}

function StandinLog(): string
{
	global $logFile;

	return (string)@file_get_contents($logFile);
}

/**
 * The outbox row with the given id.
 */
function OutboxRow(int $id): ?array
{
	$row = DatabaseService::GetInstance()
		->ExecuteDbQuery('SELECT id, delivered_at, dead_lettered_at, attempts, last_error FROM outbox WHERE id = ?', [$id])
		->fetch(\PDO::FETCH_ASSOC);

	return $row === false ? null : $row;
}

$engine = DatabaseService::GetInstance()->GetDialect()->GetName();
echo 'engine=' . $engine . ', InfluxDB stand-in at ' . VICTUAL_INFLUXDB_URL . PHP_EOL . PHP_EOL;

$outbox = new OutboxService();
$stock = StockService::GetInstance();

$productId = (int)DatabaseService::GetInstance()
	->ExecuteDbQuery('SELECT id FROM products WHERE active = 1 ORDER BY id LIMIT 1')
	->fetchColumn();

if ($productId === 0)
{
	fwrite(STDERR, "No active product to book against - point VICTUAL_DATAPATH at a migrated database with data.\n");
	exit(1);
}

// Anything already queued out of the way, so each case below drains exactly one event
$outbox->MarkDelivered(array_column(
	array_filter(
		DatabaseService::GetInstance()->ExecuteDbQuery('SELECT id, dead_lettered_at FROM outbox')->fetchAll(\PDO::FETCH_ASSOC),
		fn($row) => $row['dead_lettered_at'] === null
	),
	'id'
));

/**
 * Books one purchase and returns the outbox row id it queued.
 */
function QueueOneEvent(): int
{
	global $stock, $productId;

	$stock->AddProduct($productId, 1, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 2.50);

	return (int)DatabaseService::GetInstance()
		->ExecuteDbQuery('SELECT id FROM outbox ORDER BY id DESC LIMIT 1')
		->fetchColumn();
}

// ---------------------------------------------------------------------------------------
echo "1. A 302 is not an acknowledgement, and is not followed" . PHP_EOL;

$id = QueueOneEvent();
SetStandinMode('redirect');

$delivered = BookingEventPublisher::Drain();
$row = OutboxRow($id);
$log = StandinLog();

Check('the drain reports failure', $delivered === false);
Check('the row is not marked delivered', $row !== null && $row['delivered_at'] === null,
	'delivered_at=' . var_export($row['delivered_at'] ?? null, true));
Check('and not dead-lettered either - it is retryable', $row !== null && $row['dead_lettered_at'] === null);
Check('attempts advanced', $row !== null && (int)$row['attempts'] > 0, 'attempts=' . ($row['attempts'] ?? '?'));
Check('last_error names the status', $row !== null && str_contains((string)$row['last_error'], '302'),
	substr((string)($row['last_error'] ?? ''), 0, 100));
Check('the write endpoint was asked', str_contains($log, '/api/v2/write'));
Check('and /login was never requested, so the redirect was refused', !str_contains($log, '/login'));

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "2. A 200 carrying a page is not an acknowledgement" . PHP_EOL;

SetStandinMode('ok-with-body');

$delivered = BookingEventPublisher::Drain();
$row = OutboxRow($id);

Check('the drain reports failure', $delivered === false);
Check('the row is still not delivered', $row !== null && $row['delivered_at'] === null);
Check('attempts advanced again', $row !== null && (int)$row['attempts'] > 1, 'attempts=' . ($row['attempts'] ?? '?'));
Check('last_error says a body came back where none should', $row !== null && str_contains((string)$row['last_error'], 'body'),
	substr((string)($row['last_error'] ?? ''), 0, 120));

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "3. A 204 from the write endpoint is" . PHP_EOL;

SetStandinMode('accept');

$delivered = BookingEventPublisher::Drain();
$row = OutboxRow($id);

Check('the drain reports success', $delivered === true);
Check('the row is delivered', $row !== null && $row['delivered_at'] !== null,
	'delivered_at=' . var_export($row['delivered_at'] ?? null, true));
Check('the write reached /api/v2/write', str_contains(StandinLog(), '/api/v2/write'));

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "4. Write() itself agrees, without the outbox in the way" . PHP_EOL;

$line = InfluxEventWriter::BuildLine('probe', ['probe' => 'write-ack'], ['value' => 1.0], 1788343200000000000);

foreach (['redirect' => '302', 'ok-with-body' => 'body', 'reject' => '500'] as $mode => $fragment)
{
	SetStandinMode($mode);

	$writer = new InfluxEventWriter();
	$ok = $writer->Write([$line]);

	Check($mode . ' is refused', $ok === false);
	Check('  and says why', str_contains((string)$writer->GetLastError(), $fragment),
		substr((string)$writer->GetLastError(), 0, 110));
}

SetStandinMode('accept');
$writer = new InfluxEventWriter();
Check('accept is taken', $writer->Write([$line]) === true);
Check('  with no error recorded', $writer->GetLastError() === null, var_export($writer->GetLastError(), true));

echo PHP_EOL;

// The suite's other probes expect the stand-in rejecting, which is how it was found
SetStandinMode('reject');

if (count($failures) > 0)
{
	fwrite(STDERR, count($failures) . " check(s) failed.\n");
	exit(1);
}

echo "All write acknowledgement checks passed (engine: $engine).\n";
exit(0);
