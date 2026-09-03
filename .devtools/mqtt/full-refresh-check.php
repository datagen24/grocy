<?php

// Proves that a full refresh resends every per-product topic, and that an incremental
// publish still does not.
//
//   VICTUAL_DATAPATH=... VICTUAL_BROKER_STANDIN_PORT=... VICTUAL_BROKER_STANDIN_LOG=... \
//     php .devtools/mqtt/full-refresh-check.php
//
// The publication ledger records what this application last *sent*. A full refresh -
// bin/victual-publish-state, the boot publish - exists to answer a different question: what
// does the broker still *retain*? Everything is published at QoS 0, so a message can simply
// be lost, and a broker can be restarted without persistence, replaced, or have its retained
// messages cleared by hand. In all of those the ledger still says "sent".
//
// So a full refresh that consulted the ledger skipped every unchanged product, and those
// entities stayed missing from Home Assistant until that particular product's payload
// changed - which for a product nobody buys is never. The ambient topics never had this
// problem because they are resent every time. Two consecutive full refreshes emitted two
// product state topics and then zero.
//
// What is asserted, against a recording stand-in broker rather than against the ledger,
// because the ledger is the thing under test:
//
//   1. A FULL REFRESH RESENDS EVERYTHING, TWICE RUNNING. Same topic count both times, and
//      every product's discovery and state topic present in both.
//   2. AN INCREMENTAL PUBLISH STILL DIFFS. A second PublishState() with nothing changed
//      emits no product topic at all - the fix must not turn every write into a full resend.
//   3. A CHANGE STILL REACHES THE INCREMENTAL PATH. After a booking, that product's topics
//      are back in an incremental publish.
//
// Exit codes: 0 when every assertion holds.

use Victual\Services\DatabaseService;
use Victual\Services\Mqtt\MqttStatePublicationService;
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

// Defined before config-dist.php, which only fills in what is not already defined. Set here
// rather than written into the data directory's config.php so this probe carries its own
// broker settings and the runner does not have to know about them.
$brokerPort = (int)(getenv('VICTUAL_BROKER_STANDIN_PORT') ?: 8391);
$brokerLog = getenv('VICTUAL_BROKER_STANDIN_LOG') ?: (sys_get_temp_dir() . '/victual-broker-standin.log');

define('VICTUAL_MQTT_ENABLED', true);
define('VICTUAL_MQTT_HOST', '127.0.0.1');
define('VICTUAL_MQTT_PORT', $brokerPort);

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
 * The topics one publish put on the wire, read from the stand-in broker's log.
 *
 * The log is truncated before each publish, so what comes back belongs to that publish only.
 *
 * @return string[]
 */
function PublishAndCollect(callable $publish): array
{
	global $brokerLog;

	file_put_contents($brokerLog, '');

	$ok = $publish();

	if (!$ok)
	{
		fwrite(STDERR, "The publish reported failure - is the stand-in broker running?\n");
		exit(1);
	}

	// PublishBatch() returning means the packets were written to the socket, not that the
	// stand-in has finished recording them. Reading the log straight away is a race whose
	// failure mode is a *short* topic list - which is precisely the finding this probe is
	// looking for, so it would read as a defect rather than as a timing problem. The
	// stand-in writes "=== end" when the connection closes; that is the handshake.
	$waited = 0;

	while ($waited < 100 && !str_contains((string)@file_get_contents($brokerLog), '=== end'))
	{
		usleep(50000);
		$waited++;
	}

	if ($waited >= 100)
	{
		fwrite(STDERR, "The stand-in broker never finished the connection - no \"=== end\" in $brokerLog.\n");
		exit(1);
	}

	$topics = [];

	foreach (file($brokerLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line)
	{
		if (str_starts_with($line, '==='))
		{
			continue;
		}

		$topics[] = explode("\t", $line)[0];
	}

	return $topics;
}

/**
 * The per-product topics out of a batch: the two shapes DiscoveryPayloadBuilder emits,
 * matched on their own structure rather than on a substring that an ambient topic could
 * also contain.
 *
 *   <topic prefix>/state/product/<id>
 *   <discovery prefix>/sensor/<node>/product_<id>/config
 *
 * @param string[] $topics
 * @return string[]
 */
function ProductTopics(array $topics): array
{
	return array_values(array_filter($topics, fn($topic) => preg_match('#/state/product/\d+$#', $topic) === 1
		|| preg_match('#/product_\d+/config$#', $topic) === 1));
}

/**
 * The product id a per-product topic belongs to.
 */
function ProductIdOf(string $topic): int
{
	$matches = [];

	if (preg_match('#/state/product/(\d+)$#', $topic, $matches) === 1
		|| preg_match('#/product_(\d+)/config$#', $topic, $matches) === 1)
	{
		return (int)$matches[1];
	}

	return 0;
}

$database = DatabaseService::GetInstance();
$engine = $database->GetDialect()->GetName();

echo 'engine=' . $engine . ', broker stand-in on 127.0.0.1:' . $brokerPort . PHP_EOL . PHP_EOL;

// Two products opted in, inserted here rather than taken from a fixture so the probe says
// what it depends on. Any active product will do; what matters is that there is more than
// one, so "resent everything" is distinguishable from "resent something".
$productIds = array_map('intval', $database
	->ExecuteDbQuery('SELECT id FROM products WHERE active = 1 ORDER BY id LIMIT 2')
	->fetchAll(\PDO::FETCH_COLUMN));

if (count($productIds) < 2)
{
	fwrite(STDERR, "Need two active products - point VICTUAL_DATAPATH at a migrated database with data.\n");
	exit(1);
}

foreach ($productIds as $productId)
{
	$existing = $database->ExecuteDbQuery('SELECT COUNT(*) FROM mqtt_product_entities WHERE product_id = ?', [$productId])->fetchColumn();

	if ((int)$existing === 0)
	{
		$database->ExecuteDbStatement('INSERT INTO mqtt_product_entities (product_id) VALUES (?)', [$productId]);
	}
}

echo 'opted-in products: ' . implode(', ', $productIds) . PHP_EOL . PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "1. Two consecutive full refreshes send the same thing" . PHP_EOL;

$first = PublishAndCollect(fn() => MqttStatePublicationService::PublishDiscoveryAndState());
$second = PublishAndCollect(fn() => MqttStatePublicationService::PublishDiscoveryAndState());

$firstProducts = ProductTopics($first);
$secondProducts = ProductTopics($second);

echo '        first: ' . count($first) . ' topic(s), ' . count($firstProducts) . ' of them per-product' . PHP_EOL;
echo '        again: ' . count($second) . ' topic(s), ' . count($secondProducts) . ' of them per-product' . PHP_EOL;

Check('the first full refresh sends per-product topics', count($firstProducts) > 0,
	count($firstProducts) . ' found');
// Two topics per product - the discovery config and the state - so two products is four
Check('one discovery and one state topic per opted-in product',
	count($firstProducts) === count($productIds) * 2,
	count($firstProducts) . ' for ' . count($productIds) . ' product(s)');
Check('the second full refresh sends exactly the same per-product topics',
	count($secondProducts) === count($firstProducts),
	count($firstProducts) . ' then ' . count($secondProducts));
Check('and the same topics, not merely as many',
	array_diff($firstProducts, $secondProducts) === [] && array_diff($secondProducts, $firstProducts) === []);
Check('the whole batch is the same size both times', count($first) === count($second),
	count($first) . ' then ' . count($second));

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "2. An incremental publish still skips what has not changed" . PHP_EOL;

// The full refresh above has just recorded every hash, so nothing has changed since
$incremental = PublishAndCollect(fn() => MqttStatePublicationService::PublishState());
$incrementalProducts = ProductTopics($incremental);

echo '        incremental: ' . count($incremental) . ' topic(s), ' . count($incrementalProducts) . ' of them per-product' . PHP_EOL;

Check('it sends no per-product topic at all', count($incrementalProducts) === 0,
	count($incrementalProducts) . ' found');
Check('but still sends the ambient state topics', count($incremental) > 0,
	count($incremental) . ' found');
Check('and fewer topics than a full refresh', count($incremental) < count($first),
	count($incremental) . ' vs ' . count($first));

echo PHP_EOL;

// ---------------------------------------------------------------------------------------
echo "3. A real change still reaches the incremental path" . PHP_EOL;

$stock = StockService::GetInstance();
$stock->AddProduct($productIds[0], 3, date('Y-m-d', strtotime('+1 year')), StockService::TRANSACTION_TYPE_PURCHASE, date('Y-m-d'), 1.23);

$afterChange = ProductTopics(PublishAndCollect(fn() => MqttStatePublicationService::PublishState()));

echo '        after a booking: ' . count($afterChange) . ' per-product topic(s)' . PHP_EOL;

Check('the changed product is republished', count($afterChange) > 0, count($afterChange) . ' found');
Check('and only that product', count($afterChange) === 2, count($afterChange) . ' found, expected 2');
Check('naming the product that changed and no other',
	array_values(array_unique(array_map('ProductIdOf', $afterChange))) === [$productIds[0]],
	implode(' ', $afterChange));

echo PHP_EOL;

if (count($failures) > 0)
{
	fwrite(STDERR, count($failures) . " check(s) failed.\n");
	exit(1);
}

echo "All full refresh checks passed (engine: $engine).\n";
exit(0);
