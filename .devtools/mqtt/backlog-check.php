<?php

// Proves that one `bin/victual-publish-state --drain` clears a whole backlog, and that a
// failure part way through is reported rather than swallowed.
//
//   VICTUAL_DATAPATH=... VICTUAL_STANDIN_CONTROL=... php .devtools/mqtt/backlog-check.php
//
// A drain takes a bounded batch so that one request never becomes an unbounded write. That
// is right for a request and wrong for the command an operator runs *because* a backlog
// exists, which is why --drain loops - and why it has to be tested with more events than
// one batch holds, or the loop is never entered at all.
//
// The scenario, end to end:
//
//   1. Queue 450 events while the stand-in rejects, so they accumulate the way they would
//      during a real outage.
//   2. Flip the stand-in to accepting through its control file - without restarting it,
//      because restarting would lose the request log this counts.
//   3. Run the real CLI. Assert three batches, exit 0, nothing left undelivered.
//   4. Queue another 450, set the stand-in to fail after two writes, run the CLI again, and
//      assert exit 1 with the remainder still queued.
//
// The CLI is invoked as a subprocess rather than reimplemented here: the exit code is half
// of what is being asserted, and a copy of the loop would pass while the real one was
// broken.
//
// VICTUAL_STANDIN_CONTROL must name the same control file the running stand-in was started
// with, and VICTUAL_STANDIN_LOG the same log. Without the control file this exits 0 with a
// note rather than failing, so a person running the probe by hand against a plain stand-in
// gets an explanation instead of a mystery.
//
// Exit codes: 0 when every assertion holds.

use Victual\Services\DatabaseService;
use Victual\Services\Influx\BookingEventPublisher;
use Victual\Services\Outbox\OutboxService;

if (PHP_SAPI !== 'cli')
{
	exit('This is a command line script');
}

const BACKLOG_SIZE = 450;

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

$control = getenv('VICTUAL_STANDIN_CONTROL');

if ($control === false || $control === '')
{
	echo "VICTUAL_STANDIN_CONTROL is not set, so the stand-in cannot be flipped mid-run; skipping.\n";
	exit(0);
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
	// "fail-after:N" counts the writes in the stand-in's log, which is the only place a
	// server that reloads its script per request can keep a count. So the log is reset with
	// the mode - otherwise N is measured from the start of the whole run and the very first
	// write of the next scenario is already past it.
	if (str_starts_with($mode, 'fail-after:'))
	{
		$log = getenv('VICTUAL_STANDIN_LOG');

		if ($log !== false && $log !== '')
		{
			file_put_contents($log, '');
		}
	}

	file_put_contents(getenv('VICTUAL_STANDIN_CONTROL'), $mode);
}

/**
 * Queues synthetic events directly, rather than booking 450 times.
 *
 * What is being tested is the drain loop, not the capture, and 450 real bookings would make
 * this the slowest thing in the suite by a wide margin. The payloads are the real shape,
 * version and all, so an unreadable one would still be caught.
 */
function QueueBacklog(int $count): void
{
	$outbox = new OutboxService();

	for ($i = 0; $i < $count; $i++)
	{
		$outbox->Enqueue(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED, [
			'payload_version' => OutboxService::PAYLOAD_VERSION,
			'event_id' => sprintf('%08x-0000-4000-8000-%012x', $i, $i),
			'transaction_id' => 'backlog-' . $i,
			'occurred_at' => date('Y-m-d H:i:s', strtotime('-' . $i . ' minutes')),
			'bookings' => [[
				'booking_id' => 900000 + $i,
				'product_id' => 1,
				'amount' => 1.0,
				'price' => 1.0 + $i,
				'transaction_type' => 'purchase',
				'undone' => 0,
				'row_created_timestamp' => date('Y-m-d H:i:s', strtotime('-' . $i . ' minutes'))
			]],
			'stock' => [['product_id' => 1, 'amount' => (float)$i, 'value' => (float)$i]]
		]);
	}
}

function Undelivered(): int
{
	return (new OutboxService())->CountUndelivered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED);
}

/**
 * Runs the real CLI and returns [exit code, output].
 */
function RunDrain(): array
{
	$command = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../../bin/victual-publish-state')
		. ' --drain 2>&1';

	$output = [];
	$status = 0;
	exec('VICTUAL_DATAPATH=' . escapeshellarg(VICTUAL_DATAPATH) . ' ' . $command, $output, $status);

	return [$status, implode("\n", $output)];
}

// This process must not drain on its way out - the CLI subprocess is what is being measured
BookingEventPublisher::SuppressRequestEndDrain();

$engine = DatabaseService::GetInstance()->GetDialect()->GetName();
echo 'engine=' . $engine . ', backlog of ' . BACKLOG_SIZE . ', batches of ' . OutboxService::DRAIN_BATCH_SIZE . PHP_EOL . PHP_EOL;

// Anything an earlier probe left behind would be counted as part of this backlog
(new OutboxService())->MarkDelivered(array_column(
	(new OutboxService())->GetUndelivered(OutboxService::EVENT_STOCK_TRANSACTION_BOOKED, 100000),
	'id'
));

// ---------------------------------------------------------------------------------------
echo 'A. One run clears a backlog larger than a batch' . PHP_EOL;

SetStandinMode('reject');
QueueBacklog(BACKLOG_SIZE);

Check('the backlog is queued', Undelivered() === BACKLOG_SIZE, Undelivered() . ' waiting');

SetStandinMode('accept');
[$status, $output] = RunDrain();

echo '        ' . trim(explode("\n", $output)[count(explode("\n", $output)) - 1]) . PHP_EOL;

$expectedBatches = (int)ceil(BACKLOG_SIZE / OutboxService::DRAIN_BATCH_SIZE);

Check('the CLI exits 0', $status === 0, 'exit ' . $status);
Check('reporting ' . $expectedBatches . ' batches', str_contains($output, 'in ' . $expectedBatches . ' batch(es)'));
Check('nothing is left undelivered', Undelivered() === 0, Undelivered() . ' waiting');

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo 'B. A failure part way through is reported, not swallowed' . PHP_EOL;

SetStandinMode('reject');
QueueBacklog(BACKLOG_SIZE);

Check('the second backlog is queued', Undelivered() === BACKLOG_SIZE, Undelivered() . ' waiting');

// Two batches through, then rejection
SetStandinMode('fail-after:2');

[$status, $output] = RunDrain();

echo '        ' . trim(explode("\n", $output)[count(explode("\n", $output)) - 1]) . PHP_EOL;

$remaining = Undelivered();

Check('the CLI exits 1', $status === 1, 'exit ' . $status);
Check('saying how far it got', str_contains($output, 'failed after'));
$expectedRemaining = BACKLOG_SIZE - (2 * OutboxService::DRAIN_BATCH_SIZE);
Check('with exactly the undelivered batches left', $remaining === $expectedRemaining,
	$remaining . ' of ' . BACKLOG_SIZE . ' left, expected ' . $expectedRemaining);

// Left in a state the next run can finish, which is the whole point of not acknowledging
SetStandinMode('accept');
[$status, $output] = RunDrain();

Check('a later run finishes the job', $status === 0 && Undelivered() === 0,
	'exit ' . $status . ', ' . Undelivered() . ' waiting');

echo PHP_EOL;

if (count($failures) > 0)
{
	fwrite(STDERR, count($failures) . " check(s) failed.\n");
	exit(1);
}

echo "All backlog checks passed (engine: $engine).\n";
exit(0);
