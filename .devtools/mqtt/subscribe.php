<?php

// A fresh subscriber, for checking what a broker actually holds.
//
//   php .devtools/mqtt/subscribe.php <host> <port> <topic filter> [seconds]
//
// Connects with a clean session and a client id nothing else uses, subscribes, and prints
// every message it receives with its retain flag, then exits. "Fresh" is the point:
// verification 1 of docs/plans/18-mqtt-state-publication.md is only meaningful against a
// subscriber that was not connected when the publish happened, since a broker delivers
// live messages with retain=false and replays retained ones with retain=true.
//
// Exit codes: 0 always - what it found is on stdout, and "found nothing" is a legitimate
// answer (it is what a successful --retract looks like).

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

if (PHP_SAPI !== 'cli')
{
	exit('This is a command line script');
}

require_once __DIR__ . '/../../packages/autoload.php';

$host = $argv[1] ?? '127.0.0.1';
$port = (int)($argv[2] ?? 1883);
$filter = $argv[3] ?? '#';
$seconds = (float)($argv[4] ?? 2);

$client = new MqttClient($host, $port, 'devtools-sub-' . bin2hex(random_bytes(6)), MqttClient::MQTT_3_1_1);
$client->connect((new ConnectionSettings())->setConnectTimeout(2)->setSocketTimeout(2), true);

$received = 0;

$client->subscribe($filter, function (string $topic, string $message, bool $retained) use (&$received)
{
	$received++;
	echo $topic . "\tretain=" . ($retained ? 'true' : 'false') . "\tbytes=" . strlen($message) . "\n";
	echo "\t" . $message . "\n";
}, MqttClient::QOS_AT_MOST_ONCE);

$until = microtime(true) + $seconds;
while (microtime(true) < $until)
{
	$client->loopOnce(microtime(true), true, 50000);
}

$client->disconnect();

echo '--- ' . $received . " message(s)\n";
exit(0);
