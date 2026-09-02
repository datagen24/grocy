<?php

// A stand-in for InfluxDB, for the suite and for local probing.
//
//   php -S 127.0.0.1:<port> .devtools/mqtt/influx-standin.php
//
// It accepts POST /api/v2/write, appends the request to the file named by
// VICTUAL_STANDIN_LOG, and answers 204 the way the real server does.
//
// Two ways to make it fail, both so that a probe can exercise the failure path without
// needing an unroutable address and its multi-second timeout:
//
//   VICTUAL_STANDIN_REJECT=1        answer 500 to everything
//   VICTUAL_STANDIN_FAIL_AFTER=N    answer 204 to the first N writes and 500 after that,
//                                   which is how the drain loop's "stop at the first
//                                   failure" behaviour gets tested mid-backlog
//
// The count comes from the log file rather than from memory: the built-in server runs the
// router script afresh per request, so there is nowhere else to keep it.
//
// PHP's own built-in server rather than node, so the suite phase that uses this has no
// dependency beyond the PHP the rest of the suite already needs - a probe that only runs
// where somebody installed node is a probe CI skips.

$log = getenv('VICTUAL_STANDIN_LOG') ?: (sys_get_temp_dir() . '/victual-influx-standin.log');
$body = file_get_contents('php://input');

file_put_contents($log, '=== ' . ($_SERVER['REQUEST_METHOD'] ?? '?') . ' ' . ($_SERVER['REQUEST_URI'] ?? '?') . "\n"
	. 'authorization: ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? '') . "\n"
	. $body . "\n", FILE_APPEND);

$reject = getenv('VICTUAL_STANDIN_REJECT') === '1';

$failAfter = getenv('VICTUAL_STANDIN_FAIL_AFTER');
if (!$reject && $failAfter !== false && $failAfter !== '')
{
	// This request is already in the log, so the count includes it
	$reject = substr_count(file_get_contents($log), '=== POST') > (int)$failAfter;
}

if ($reject)
{
	http_response_code(500);
	echo 'rejected by the stand-in';

	return true;
}

http_response_code(204);

return true;
