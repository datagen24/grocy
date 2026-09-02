<?php

// Prints the assembled MQTT payload for the configured database, as JSON, without
// publishing anything.
//
//   VICTUAL_DATAPATH=... php .devtools/mqtt/assemble.php [--pretty]
//
// The point of it is verification 7 of docs/plans/18-mqtt-state-publication.md: run it
// against SQLite and against PostgreSQL over the same fixtures and diff. It reads the same
// views the UI does, so a divergence here is a divergence anywhere, and this is the cheapest
// place to notice one. engine-diff.sh drives both sides and does the comparison.
//
// The last_published sensor is pinned to a fixed instant rather than now(), because a
// timestamp that moves between two runs is the one field guaranteed to differ and says
// nothing about the engines.

use Victual\Services\Mqtt\StateSnapshotAssembler;

if (PHP_SAPI !== 'cli')
{
	exit('This is a command line script');
}

const PINNED_PUBLISHED_AT = '2026-01-01 00:00:00';

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

$assembler = new StateSnapshotAssembler();

// Encoded through the same helper the publisher uses, so what this diff compares is the
// bytes that would go on a topic rather than a nearby approximation of them
$ambient = [];
foreach ($assembler->Assemble(PINNED_PUBLISHED_AT) as $entity => $entityPayload)
{
	$ambient[$entity] = json_decode(StateSnapshotAssembler::EncodePayload($entityPayload), true);
}

$perProduct = [];
foreach ($assembler->AssemblePerProductEntities() as $objectId => $entityPayload)
{
	$perProduct[$objectId] = json_decode(StateSnapshotAssembler::EncodePayload($entityPayload), true);
}

$payload = [
	'ambient' => $ambient,
	'per_product' => $perProduct,
	'orphaned_flags' => $assembler->GetOrphanedFlagProductIds()
];

$flags = in_array('--pretty', array_slice($argv, 1), true) ? JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES : JSON_UNESCAPED_SLASHES;

echo json_encode($payload, $flags) . PHP_EOL;
exit(0);
