<?php

// A recording stand-in for an MQTT broker, for the suite and for local probing.
//
//   php .devtools/mqtt/broker-standin.php <port> <log file>
//
// It speaks the small part of MQTT 3.1.1 that MqttPublisher uses - CONNECT, PUBLISH at QoS 0
// with the retain flag, DISCONNECT - and appends one line per published topic to the log:
//
//   === connect
//   <topic>\t<payload length>
//   === end
//
// The trailing "=== end" is written when the client disconnects and is load-bearing rather
// than decorative: a reader that simply looks at the log after PublishBatch() returns is
// racing this process, which may still be draining buffered PUBLISH packets. That race does
// not fail loudly - it produces a short topic list, which reads as "the publisher did not
// send those" and is exactly the conclusion the probe exists to draw. So a reader waits for
// this marker instead.
//
// It is not a broker. It retains nothing, forwards nothing, and answers no subscription. The
// only question it exists to answer is "which topics did this publish actually put on the
// wire", which is the question the ledger cannot be asked because the ledger is the thing
// under test.
//
// Written in plain PHP against a stream socket for the same reason influx-standin.php is a
// PHP built-in server rather than node: a probe that only runs where somebody installed a
// broker is a probe CI skips, and the defects here fail silently, so a skipped probe is
// worse than no probe.
//
// Exits on SIGTERM, which is how the suite stops it.

if (PHP_SAPI !== 'cli')
{
	exit('This is a command line script');
}

$port = (int)($argv[1] ?? 0);
$logFile = (string)($argv[2] ?? '');

if ($port === 0 || $logFile === '')
{
	fwrite(STDERR, "usage: broker-standin.php <port> <log file>\n");
	exit(1);
}

// Packet types, in the high nibble of the fixed header's first byte
const PACKET_CONNECT = 1;
const PACKET_PUBLISH = 3;
const PACKET_DISCONNECT = 14;

$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errorNumber, $errorMessage);

if ($server === false)
{
	fwrite(STDERR, 'could not listen on 127.0.0.1:' . $port . ': ' . $errorMessage . "\n");
	exit(1);
}

/**
 * Reads exactly $length bytes, or returns null when the peer went away first.
 *
 * A stream read can come back short, and treating a short read as the whole packet is how a
 * parser like this silently starts recording the wrong topic.
 *
 * @param resource $connection
 */
function ReadExactly($connection, int $length): ?string
{
	$buffer = '';

	while (strlen($buffer) < $length)
	{
		$chunk = fread($connection, $length - strlen($buffer));

		if ($chunk === false || $chunk === '')
		{
			return null;
		}

		$buffer .= $chunk;
	}

	return $buffer;
}

/**
 * MQTT's variable length integer: seven bits per byte, the top bit meaning "one more".
 *
 * @param resource $connection
 */
function ReadRemainingLength($connection): ?int
{
	$value = 0;
	$multiplier = 1;

	for ($i = 0; $i < 4; $i++)
	{
		$byte = ReadExactly($connection, 1);

		if ($byte === null)
		{
			return null;
		}

		$digit = ord($byte);
		$value += ($digit & 127) * $multiplier;

		if (($digit & 128) === 0)
		{
			return $value;
		}

		$multiplier *= 128;
	}

	return null;
}

while (true)
{
	$connection = @stream_socket_accept($server, -1);

	if ($connection === false)
	{
		continue;
	}

	while (true)
	{
		$header = ReadExactly($connection, 1);

		if ($header === null)
		{
			break;
		}

		$type = ord($header) >> 4;
		$flags = ord($header) & 15;
		$remaining = ReadRemainingLength($connection);

		if ($remaining === null)
		{
			break;
		}

		$rest = $remaining === 0 ? '' : ReadExactly($connection, $remaining);

		if ($rest === null)
		{
			break;
		}

		if ($type === PACKET_CONNECT)
		{
			// CONNACK: session present 0, return code 0 (accepted). The whole packet is four
			// bytes and none of them depend on what the CONNECT said, which is why this can
			// skip parsing the credentials, the will and the keep-alive entirely.
			file_put_contents($logFile, "=== connect\n", FILE_APPEND);
			fwrite($connection, "\x20\x02\x00\x00");

			continue;
		}

		if ($type === PACKET_PUBLISH)
		{
			// QoS is bits 1-2 of the flags; anything above 0 would need an acknowledgement
			// packet back, and MqttPublisher deliberately publishes at QoS 0 - so a non-zero
			// one here means the publisher changed and this stand-in has to grow with it
			// rather than quietly hanging.
			$qos = ($flags >> 1) & 3;

			if ($qos !== 0)
			{
				file_put_contents($logFile, "=== unsupported qos $qos\n", FILE_APPEND);

				break;
			}

			$topicLength = (ord($rest[0]) << 8) | ord($rest[1]);
			$topic = substr($rest, 2, $topicLength);
			$payload = substr($rest, 2 + $topicLength);

			file_put_contents($logFile, $topic . "\t" . strlen($payload) . "\n", FILE_APPEND);

			continue;
		}

		if ($type === PACKET_DISCONNECT)
		{
			break;
		}

		// Anything else - a PINGREQ, a SUBSCRIBE - is not part of what MqttPublisher does,
		// and recording it is more useful than guessing at a reply
		file_put_contents($logFile, '=== unhandled packet type ' . $type . "\n", FILE_APPEND);
	}

	// Written last, and only once every packet of this connection has been recorded: it is
	// what tells a reader the batch is complete rather than merely current
	file_put_contents($logFile, "=== end\n", FILE_APPEND);

	fclose($connection);
}
