<?php

// Checks that the migrations directory says what it means.
//
//   php check-migrations.php
//
// The runtime loader (DatabaseMigrationService::GetMigrationFiles) already refuses to
// start on a migration whose name does not parse or whose suffix is not a real engine.
// That catches the file nobody would notice. This catches the subtler thing: a migration
// number whose *meaning* differs between engines.
//
// The number recorded in the migrations table is the migration's identity, and the
// dialect suffix only selects which file supplies it. So "0260" has to mean one logical
// change. Nothing at runtime enforces that — two databases can each record 260, have run
// different files, and end up with different schemas without a word of complaint. This
// script is that enforcement, and it runs before the suite because a numbering mistake
// invalidates everything the suite would go on to say.
//
// Migrations up to the baseline (0001-0255) are SQLite-only history that PostgreSQL
// replaces wholesale, so they are exempt by definition.

require_once (getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2)) . '/packages/autoload.php';

use Victual\Services\Database\DatabaseDialect;
use Victual\Services\DatabaseMigrationService;

const MARKER_ENGINE_EXCLUSIVE = '@engine-exclusive';
const MARKER_OVERRIDES_GENERIC = '@overrides-generic';

$migrationsPath = (getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2)) . '/migrations';

/** @var array<int, array{generic: ?string, specific: array<string, string>}> */
$byNumber = [];

foreach (new FilesystemIterator($migrationsPath) as $file)
{
	$name = $file->getBasename();
	$matches = [];

	if (preg_match('/^(\d+)\.(sql|php)$/', $name, $matches))
	{
		$byNumber[intval($matches[1])]['generic'] = $name;
	}
	elseif (preg_match('/^(\d+)\.([a-z]+)\.(sql|php)$/', $name, $matches))
	{
		$byNumber[intval($matches[1])]['specific'][$matches[2]] = $name;
	}
}

ksort($byNumber);

$problems = [];
$checked = 0;

function FileContains(string $path, string $marker): bool
{
	return str_contains(file_get_contents($path), $marker);
}

foreach ($byNumber as $number => $files)
{
	// The always-run fixups are not schema versions and have no per engine story.
	if ($number === DatabaseMigrationService::DOALWAYS_MIGRATION_ID
		|| $number === DatabaseMigrationService::EMERGENCY_MIGRATION_ID)
	{
		continue;
	}

	if ($number <= DatabaseMigrationService::BASELINE_MIGRATION_ID)
	{
		continue;
	}

	$checked++;

	$generic = $files['generic'] ?? null;
	$specific = $files['specific'] ?? [];
	$drivers = array_keys($specific);
	$missing = array_diff(DatabaseDialect::SUPPORTED_DRIVERS, $drivers);

	if ($generic !== null && !empty($specific))
	{
		// Legal — an engine specific file wins over a generic one with the same number —
		// but only ever on purpose. Left implicit it means one engine silently skips the
		// portable migration, which is how two databases at the same number end up with
		// different schemas.
		foreach ($specific as $driver => $name)
		{
			if (!FileContains($migrationsPath . '/' . $name, MARKER_OVERRIDES_GENERIC))
			{
				$problems[] = "$number: \"$name\" shadows the portable \"$generic\", so $driver "
					. 'never runs the portable file. If that is intended, say so with a "'
					. MARKER_OVERRIDES_GENERIC . '" comment in it; otherwise write a complete per '
					. 'engine set and drop the generic file.';
			}
		}

		continue;
	}

	if ($generic !== null)
	{
		// Portable, applies everywhere. Nothing to check.
		continue;
	}

	if (empty($missing))
	{
		// A complete per engine set: every supported driver has its own file.
		continue;
	}

	// One or more engines have no file for this number at all. That is allowed, but it
	// is the case that makes the two engines sit at different migration numbers, so it
	// has to be a decision somebody wrote down rather than a file somebody forgot.
	foreach ($specific as $driver => $name)
	{
		if (!FileContains($migrationsPath . '/' . $name, MARKER_ENGINE_EXCLUSIVE))
		{
			$problems[] = "$number: \"$name\" exists for $driver but not for "
				. implode(', ', $missing) . '. If the other engine genuinely needs no change, mark '
				. 'the file with a "' . MARKER_ENGINE_EXCLUSIVE . '" comment saying why; otherwise '
				. 'the counterpart is missing.';
		}
	}
}

echo 'Checked ' . $checked . ' migration number(s) above the baseline ('
	. DatabaseMigrationService::BASELINE_MIGRATION_ID . ").\n";

if (empty($problems))
{
	echo "MIGRATION NUMBERING OK\n";
	exit(0);
}

foreach ($problems as $problem)
{
	echo '  ' . $problem . "\n";
}

echo "\n" . count($problems) . " migration numbering problem(s)\n";
exit(1);
