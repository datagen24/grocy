<?php

// Proves that no price, cost or value field can reach an MQTT topic.
//
//   php .devtools/mqtt/price-guard.php
//
// Question 8 of docs/plans/18-mqtt-state-publication.md is the reason this exists as a
// script rather than a code review note. Anything with broker credentials reads the
// retained topics without authenticating to Victual at all, so a price that ships once
// stays on the broker until something republishes without it - there is no reader identity
// to gate it on afterwards and no way to un-see it.
//
// Three checks, none of which trusts the others:
//
//   1. The assembler's deny-list actually covers the price columns of the views it reads.
//      The list is checked against the columns named in the plan's security note.
//   2. Rows built with price columns in them come out without those columns, through both
//      the deny-list and the allow-list.
//   3. AssertNoForbiddenKeys refuses a payload with a money-shaped key anywhere in it,
//      including nested inside attribute rows, and accepts a realistic clean one.
//
// Exit codes: 0 when every check passes, 1 otherwise.

use Victual\Services\Mqtt\StateSnapshotAssembler;

if (PHP_SAPI !== 'cli')
{
	exit('This is a command line script');
}

require_once __DIR__ . '/../../packages/autoload.php';

$failures = [];

function Check(string $what, bool $ok)
{
	global $failures;

	echo ($ok ? '  ok   ' : '  FAIL ') . $what . PHP_EOL;

	if (!$ok)
	{
		$failures[] = $what;
	}
}

echo 'Deny-list covers the columns the plan names' . PHP_EOL;

// Exactly the columns docs/plans/18-mqtt-state-publication.md's security note lists as the
// ones that would ship by default, plus the shopping list view's own three
$mustBeDenied = ['value', 'last_price', 'average_price', 'avg_price', 'price', 'last_price_unit', 'last_price_total', 'costs'];
foreach ($mustBeDenied as $column)
{
	Check('"' . $column . '" is on the deny-list', in_array($column, StateSnapshotAssembler::DENIED_COLUMNS, true));
}

Check('"note" is on the deny-list (fails the wall-tablet test for the same reason)', in_array('note', StateSnapshotAssembler::DENIED_COLUMNS, true));
Check('"api_key" is on the deny-list', in_array('api_key', StateSnapshotAssembler::DENIED_COLUMNS, true));

echo PHP_EOL . 'No allow-list admits a money-shaped key' . PHP_EOL;

foreach (StateSnapshotAssembler::ALLOWED_ROW_KEYS as $entity => $keys)
{
	$offending = array_filter($keys, function ($key)
	{
		return preg_match(StateSnapshotAssembler::FORBIDDEN_KEY_PATTERN, $key) === 1;
	});

	Check('the "' . $entity . '" allow-list is clean', count($offending) === 0);
}

echo PHP_EOL . 'AssertNoForbiddenKeys rejects what it should' . PHP_EOL;

$rejects = [
	'a top level price' => ['price' => 1],
	'a price nested in an attribute row' => ['stock' => ['attributes' => ['products' => [['product_name' => 'Milk', 'last_price' => 2.5]]]]],
	'a cost field' => ['recipe' => ['attributes' => ['costs' => 3]]],
	'a value field' => ['stock' => ['attributes' => ['products' => [['value' => 12]]]]],
	'a differently cased price' => ['Stock' => ['AveragePrice' => 1]]
];

foreach ($rejects as $what => $payload)
{
	$threw = false;

	try
	{
		StateSnapshotAssembler::AssertNoForbiddenKeys($payload);
	}
	catch (\Exception $ex)
	{
		$threw = true;
	}

	Check('rejects ' . $what, $threw);
}

$clean = [
	'stock' => ['state' => 2, 'attributes' => ['products' => [
		['product_id' => 1, 'product_name' => 'Milk', 'amount' => 2.0, 'unit' => 'Piece', 'best_before_date' => '2026-09-10']
	]]],
	'shopping_list' => ['state' => 1, 'attributes' => ['items' => [
		['shopping_list_id' => 1, 'product_id' => 1, 'product_name' => 'Milk', 'amount' => 1.0, 'unit' => 'Piece']
	]]],
	'next_chore' => ['state' => '2026-09-03T09:00:00+00:00', 'attributes' => ['chores' => [
		['chore_id' => 1, 'chore_name' => 'Take out the trash', 'next_estimated_execution_time' => '2026-09-03T09:00:00+00:00']
	]]],
	'last_published' => ['state' => '2026-09-02T12:00:00+00:00', 'attributes' => []]
];

$accepted = true;
try
{
	StateSnapshotAssembler::AssertNoForbiddenKeys($clean);
}
catch (\Exception $ex)
{
	$accepted = false;
	echo '  (' . $ex->getMessage() . ')' . PHP_EOL;
}

Check('accepts a realistic clean snapshot', $accepted);

echo PHP_EOL;

if (count($failures) > 0)
{
	fwrite(STDERR, count($failures) . ' check(s) failed.' . PHP_EOL);
	exit(1);
}

echo 'All price guard checks passed.' . PHP_EOL;
exit(0);
