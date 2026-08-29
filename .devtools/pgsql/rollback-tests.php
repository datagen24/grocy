<?php

// Does a failure partway through a stock operation leave the ledger half-written?
//
//   php rollback-tests.php
//
// Runs against whichever engine VICTUAL_DATAPATH's config.php selects, so the runner can
// point it at SQLite and then at PostgreSQL. See run-tests.sh.
//
// The other two tools in here compare the two engines against each other. This one asks a
// different question of one engine at a time: after an operation fails, is the database
// where it started? `stock` and `stock_log` have to describe the same history, and
// nothing enforces that except the write paths being transactional — so this is the only
// check that would notice if they stopped being.
//
// How the failure is provoked matters. It is a trigger that aborts the second write of an
// operation, not a debug `throw` added to the source, so the code under test is the code
// that ships. Each case then snapshots `stock` and `stock_log` in full, runs an operation
// that will fail on its second iteration, and compares the snapshots row for row.
//
// A case that does not throw is reported as a failure even if the ledger is unchanged: it
// means the provocation missed and the result proves nothing. That is the failure mode
// this file is most likely to develop as the code around it changes, so it is checked
// explicitly rather than assumed.

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

use Victual\Services\DatabaseService;
use Victual\Services\StockService;

const INJECTOR = 'probe_fail_after_first_write';

$db = DatabaseService::GetInstance();
$pdo = $db->GetDbConnectionRaw();
$engine = $db->GetDialect()->GetName();
$stock = StockService::GetInstance();

$failures = 0;

/**
 * Installs the trigger that aborts the second write of an operation.
 *
 * Two shapes, because the entrypoints write in two different ways. The booking paths
 * INSERT into stock_log, so rejecting the second insert of a transaction stops them after
 * one iteration. UndoBooking does not insert at all — it UPDATEs the original row's undone
 * flag and adjusts stock — so an insert trigger never fires for it and the undo cases need
 * their own.
 */
function InstallInjector(PDO $pdo, string $engine, string $kind): void
{
	RemoveInjector($pdo, $engine);

	$condition = $kind === 'insert'
		? '(SELECT COUNT(*) FROM stock_log WHERE transaction_id = NEW.transaction_id) >= 1'
		: 'NEW.undone = 1 AND OLD.undone = 0 AND (SELECT COUNT(*) FROM stock_log WHERE undone = 1) >= 1';

	$event = $kind === 'insert' ? 'INSERT' : 'UPDATE';
	$message = 'injected failure after the first write';

	if ($engine === 'sqlite')
	{
		$pdo->exec('CREATE TRIGGER ' . INJECTOR . ' BEFORE ' . $event . ' ON stock_log
			WHEN ' . $condition . '
			BEGIN SELECT RAISE(ABORT, \'' . $message . '\'); END');

		return;
	}

	// PostgreSQL has no RAISE outside a function, so the same condition becomes a
	// one-line plpgsql body. undone is SMALLINT on both engines, so the comparison above
	// is portable as written.
	$pdo->exec('CREATE OR REPLACE FUNCTION ' . INJECTOR . '() RETURNS trigger AS $$
		BEGIN
			IF ' . $condition . ' THEN
				RAISE EXCEPTION \'' . $message . '\';
			END IF;

			RETURN NEW;
		END;
		$$ LANGUAGE plpgsql');

	$pdo->exec('CREATE TRIGGER ' . INJECTOR . ' BEFORE ' . $event . ' ON stock_log
		FOR EACH ROW EXECUTE FUNCTION ' . INJECTOR . '()');
}

function RemoveInjector(PDO $pdo, string $engine): void
{
	if ($engine === 'sqlite')
	{
		$pdo->exec('DROP TRIGGER IF EXISTS ' . INJECTOR);

		return;
	}

	$pdo->exec('DROP TRIGGER IF EXISTS ' . INJECTOR . ' ON stock_log');
	$pdo->exec('DROP FUNCTION IF EXISTS ' . INJECTOR . '()');
}

/** The whole ledger, ordered deterministically, as one comparable string. */
function Snapshot(PDO $pdo): string
{
	$out = '';

	foreach (['stock', 'stock_log'] as $table)
	{
		$rows = $pdo->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

		foreach ($rows as $row)
		{
			// Set from the clock, so it would differ between two runs for reasons that
			// have nothing to do with rollback.
			unset($row['row_created_timestamp']);
			$out .= $table . ':' . json_encode($row) . "\n";
		}
	}

	return $out;
}

/**
 * One case: set the product up, snapshot, provoke a failure partway, snapshot again.
 *
 * @param callable $setup Puts the product into the state the case needs
 * @param callable $act   The operation expected to fail on its second iteration
 */
function Probe(string $label, callable $setup, callable $act, string $kind = 'insert'): void
{
	global $pdo, $engine, $failures;

	$setup();

	InstallInjector($pdo, $engine, $kind);
	$before = Snapshot($pdo);

	$threw = false;

	try
	{
		$act();
	}
	catch (\Throwable $ex)
	{
		$threw = true;
	}

	// A transaction the operation left open would make the snapshot below meaningless,
	// and would poison every case after this one.
	if ($pdo->inTransaction())
	{
		$pdo->rollBack();
		printf("  FAIL   %-18s left a transaction open\n", $label);
		$failures++;

		RemoveInjector($pdo, $engine);

		return;
	}

	RemoveInjector($pdo, $engine);

	$after = Snapshot($pdo);
	$clean = ($before === $after);

	if (!$threw)
	{
		printf("  FAIL   %-18s did not fail — the provocation missed, so this proves nothing\n", $label);
		$failures++;

		return;
	}

	if (!$clean)
	{
		printf("  DIRTY  %-18s ledger changed — a partial write was left behind\n", $label);
		$failures++;

		return;
	}

	printf("  ok     %-18s failed and left the ledger unchanged\n", $label);
}

/** Two stock entries, so any loop over entries has a second iteration to fail on. */
function GiveTwoEntries(StockService $stock, int $productId): void
{
	$t = null;
	$stock->AddProduct($productId, 5, '2027-01-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-08-01', 1.00, 1, 1, $t);
	$stock->AddProduct($productId, 5, '2027-06-01', StockService::TRANSACTION_TYPE_PURCHASE, '2026-08-02', 1.10, 1, 1, $t);
}

echo 'Rollback after an injected failure (' . $engine . ")\n\n";

Probe('ConsumeProduct',
	function () use ($stock) { GiveTwoEntries($stock, 3); },
	function () use ($stock) { $t = null; $stock->ConsumeProduct(3, 8, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $t); });

Probe('TransferProduct',
	function () use ($stock) { GiveTwoEntries($stock, 4); },
	function () use ($stock) { $t = null; $stock->TransferProduct(4, 8, 1, 3, 'default', $t); });

Probe('InventoryProduct',
	function () use ($stock) { GiveTwoEntries($stock, 5); },
	function () use ($stock) { $stock->InventoryProduct(5, 2, '2027-01-01', 1); });

Probe('OpenProduct',
	function () use ($stock) { GiveTwoEntries($stock, 6); },
	function () use ($stock) { $t = null; $stock->OpenProduct(6, 8, 'default', $t); });

// A product of its own: the products above carry leftovers from their own case, and how
// many entries this consume spans decides whether the undo loop has a second iteration.
$pdo->exec('INSERT INTO products (id, name, location_id, qu_id_purchase, qu_id_stock, min_stock_amount, default_best_before_days)
	VALUES (800, \'Rollback Test Product\', 1, 2, 2, 0, 0)');

Probe('UndoTransaction',
	function () use ($stock) {
		GiveTwoEntries($stock, 800);
		$t = null;
		$stock->ConsumeProduct(800, 8, false, StockService::TRANSACTION_TYPE_CONSUME, 'default', null, null, $t);
		$GLOBALS['undoTransactionId'] = $t;
	},
	function () use ($stock) { $stock->UndoTransaction($GLOBALS['undoTransactionId']); },
	'update');

echo "\n";

if ($failures === 0)
{
	echo "EVERY FAILED OPERATION ROLLED BACK\n";
	exit(0);
}

echo $failures . " case(s) did not roll back cleanly\n";
exit(1);
