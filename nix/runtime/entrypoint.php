#!/usr/bin/env php
<?php

/*
 * Container entrypoint. Prepares the writable data directory, then replaces itself with
 * the real command.
 *
 * This script is scaffolding with a stated expiry date. It exists because the
 * application, today, insists on two things an immutable image cannot give it:
 *
 *   1. helpers/PrerequisiteChecker.php refuses to start unless config.php exists inside
 *      VICTUAL_DATAPATH — not next to the code, not on a read-only mount of its own,
 *      but inside the one directory the application also writes to.
 *   2. app.php creates VICTUAL_DATAPATH/viewcache and, on every cold start, empties it
 *      and answers the request with a 302, because the version/base-URL marker file it
 *      looks for never survives a restart.
 *
 * docs/plans/10-cold-start-statelessness.md removes both — a separate
 * VICTUAL_VIEWCACHE_PATH baked at build time, and the deletion of the hash redirect.
 * When it lands, this file and the emptyDir it prepares are deleted together and the
 * images run straight from php-fpm with no entrypoint at all. Until then, the honest
 * shape of the deployment is "read-only root filesystem plus one ephemeral scratch
 * directory", and this is the four lines that make that true.
 *
 * It is PHP rather than shell on purpose: the images contain no shell, and adding one
 * so that six lines of setup can run would put a shell in the address space of every
 * production container for the rest of the deployment's life. PHP is already there.
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

// The data directory is an emptyDir/tmpfs: fresh on every start, so this runs every
// time and is not an "if it does not exist" convenience.
foreach ([$dataPath, $dataPath . '/viewcache', $dataPath . '/settingoverrides'] as $directory)
{
	if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory))
	{
		fail('could not create ' . $directory);
	}
}

// PrerequisiteChecker wants a config.php in the data directory. The image ships a
// near-empty one whose only job is to satisfy that check; every actual setting arrives
// as a VICTUAL_* environment variable, which config-dist.php's Setting() already
// prefers over its own defaults. A deployment that would rather mount a real config.php
// over the seeded path can do so — this only writes when nothing is there.
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

// Hand over. pcntl_exec replaces this process, so the command becomes PID 1 and
// receives SIGTERM directly from the runtime — no init shim, no signal forwarding, no
// process that outlives the thing it started.
$command = $argv[1];
$arguments = array_slice($argv, 2);

if (!function_exists('pcntl_exec'))
{
	fail('ext/pcntl is missing from this image; see nix/php.nix');
}

pcntl_exec($command, $arguments, getenv());

// pcntl_exec only returns on failure.
fail('could not exec ' . $command);
