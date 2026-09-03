#!/usr/bin/env php
<?php

/*
 * Container entrypoint. Seeds the data directory, then replaces itself with the real
 * command.
 *
 * It used to do more. Before plan 10 landed it also created the view cache directory,
 * because the application emptied and rebuilt that directory on every cold start and
 * answered the request with a 302 while it did. That is gone: the cache is baked into the
 * application's store path at build time (nix/app.nix) and mounted read-only, and
 * VICTUAL_VIEWCACHE_PATH points at it.
 *
 * What is left is one thing the application still insists on:
 * `PrerequisiteChecker::checkForConfigFile()` refuses to start unless config.php exists
 * inside VICTUAL_DATAPATH, and that directory is an emptyDir on every start. The image
 * ships a near-empty config.php at /etc/victual/config.php (nix/config-seed.nix) and this
 * copies it in. Every actual setting arrives as a VICTUAL_* environment variable, which
 * config-dist.php's Setting() already prefers over its own defaults.
 *
 * It is PHP rather than shell on purpose: the images contain no shell, and adding one so
 * that a copy can happen would put a shell in the address space of every production
 * container for the rest of the deployment's life. PHP is already there.
 *
 * This file goes away entirely when the application stops requiring config.php to exist —
 * the check predates environment configuration and now has nothing left to check.
 *
 * Usage (from the image's Entrypoint, never by hand):
 *   php entrypoint.php <command> [args...]
 */

const SEEDED_CONFIG = '/etc/victual/config.php';

function fail(string $message): never
{
	fwrite(STDERR, 'victual-entrypoint: ' . $message . PHP_EOL);
	exit(1);
}

$argv = $_SERVER['argv'];

if (count($argv) < 2)
{
	fail('no command given — the image Entrypoint must pass one');
}

$dataPath = getenv('VICTUAL_DATAPATH');

if ($dataPath === false || $dataPath === '')
{
	fail('VICTUAL_DATAPATH is not set');
}

if (!is_dir($dataPath) && !@mkdir($dataPath, 0o700, true) && !is_dir($dataPath))
{
	fail('could not create ' . $dataPath);
}

// Only when nothing is there, so that a deployment mounting a real config.php over this
// path keeps it.
if (!file_exists($dataPath . '/config.php'))
{
	if (!is_readable(SEEDED_CONFIG))
	{
		fail('no config.php in ' . $dataPath . ' and none to seed from at ' . SEEDED_CONFIG);
	}

	if (@copy(SEEDED_CONFIG, $dataPath . '/config.php') === false)
	{
		fail('could not seed config.php into ' . $dataPath);
	}
}

// Hand over. pcntl_exec replaces this process, so the command becomes PID 1 and receives
// SIGTERM directly from the runtime — no init shim, no signal forwarding, and no process
// that outlives the thing it started.
$command = $argv[1];
$arguments = array_slice($argv, 2);

if (!function_exists('pcntl_exec'))
{
	fail('ext/pcntl is missing from this image; see nix/php.nix');
}

pcntl_exec($command, $arguments, getenv());

// pcntl_exec only returns on failure.
fail('could not exec ' . $command);
