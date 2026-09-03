<?php

// Checks that the MQTT client id keeps its randomness whatever the configured prefix is.
//
//   php .devtools/mqtt/client-id-check.php [prefix]
//
// A broker disconnects an existing session when a second connection presents the same
// client id, so two overlapping requests sharing an id knock each other off the broker.
// The suffix is what prevents that, and the failure mode this guards is quiet: trimming the
// joined string to 23 characters ate the suffix as the prefix grew, so the collision only
// appeared for installations that configured a long MQTT_CLIENT_ID and never for the
// default.
//
// Run with no argument it checks a spread of prefix lengths in subprocesses (the prefix is
// a constant, and a constant can only be defined once per process). With an argument it
// checks that one prefix and prints the id.
//
// Exit codes: 0 when every id is at most 23 characters and ends in a 12 hex digit suffix.

if (PHP_SAPI !== 'cli')
{
	exit('This is a command line script');
}

const MAX_LENGTH = 23;
const SUFFIX_HEX = 12;

$argument = $argv[1] ?? null;

if ($argument !== null)
{
	if (!defined('VICTUAL_DATAPATH'))
	{
		define('VICTUAL_DATAPATH', sys_get_temp_dir());
	}

	require_once __DIR__ . '/../../packages/autoload.php';

	// The real setting, so the real method under test reads the real constant
	define('VICTUAL_MQTT_CLIENT_ID', $argument);

	// No setAccessible() call: it has had no effect since PHP 8.1, and on 8.5 - which is what
	// composer.json pins and the dev image runs - it emits a deprecation notice that lands on
	// stdout and becomes the "client id" the parent process reads back, failing every case
	// with a diagnostic instead of an answer.
	$method = new ReflectionMethod(\Victual\Services\Mqtt\MqttPublisher::class, 'BuildClientId');

	echo $method->invoke(new \Victual\Services\Mqtt\MqttPublisher()) . PHP_EOL;
	exit(0);
}

$cases = [
	'the default' => 'victual',
	'one character' => 'x',
	'ten characters' => 'abcdefghij',
	'eleven characters' => 'abcdefghijk',
	'exactly 23' => str_repeat('b', 23),
	'30 characters' => str_repeat('a', 30),
	'only illegal characters' => '!!!!',
	'illegal characters mixed in' => 'my broker/name!!'
];

$failures = 0;

foreach ($cases as $label => $prefix)
{
	// A subprocess each, because VICTUAL_MQTT_CLIENT_ID is a constant
	$id = trim(shell_exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($prefix)));

	$lengthOk = strlen($id) <= MAX_LENGTH;
	$suffixOk = preg_match('/-[0-9a-f]{' . SUFFIX_HEX . '}$/', $id) === 1;
	$ok = $lengthOk && $suffixOk;

	printf("  %-6s %-28s prefix=%2d  id=%-24s len=%2d\n", $ok ? 'ok' : 'FAIL', $label, strlen($prefix), $id, strlen($id));

	if (!$ok)
	{
		$failures++;

		if (!$lengthOk)
		{
			echo '         longer than ' . MAX_LENGTH . " characters\n";
		}

		if (!$suffixOk)
		{
			echo '         does not end in a ' . SUFFIX_HEX . " hex digit random suffix\n";
		}
	}
}

// Two ids from the same prefix must differ, which is the property the suffix exists for
$first = trim(shell_exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg(str_repeat('a', 30))));
$second = trim(shell_exec(escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg(str_repeat('a', 30))));

$distinct = $first !== $second;
printf("  %-6s %-28s %s vs %s\n", $distinct ? 'ok' : 'FAIL', 'two ids from one long prefix', $first, $second);

if (!$distinct)
{
	$failures++;
}

echo PHP_EOL;

if ($failures > 0)
{
	fwrite(STDERR, $failures . " check(s) failed.\n");
	exit(1);
}

echo "All client id checks passed.\n";
exit(0);
