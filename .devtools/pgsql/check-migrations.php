<?php

// Checks that the migrations directory says what it means.
//
//   php check-migrations.php [--allow-reserved-holes]
//
// --allow-reserved-holes waives exactly one failure — a missing number that
// migrations/RESERVATIONS.md says belongs to a branch which has not merged yet — so that the
// rest of the suite can still be run on a branch that is knowingly not mergeable. It is for
// a person at a terminal; CI and run-tests.sh do not pass it.
//
// The runtime loader (DatabaseMigrationService::GetMigrationFiles) already refuses to
// start on a migration whose name does not parse or whose suffix is not a real engine.
// That catches the file nobody would notice. This catches the subtler things: a migration
// number whose *meaning* differs between engines, and a number that is missing entirely.
//
// The number recorded in the migrations table is the migration's identity, and the
// dialect suffix only selects which file supplies it. So "0260" has to mean one logical
// change. Nothing at runtime enforces that — two databases can each record 260, have run
// different files, and end up with different schemas without a word of complaint. This
// script is that enforcement, and it runs before the suite because a numbering mistake
// invalidates everything the suite would go on to say.
//
// The second check is about a *hole* rather than a disagreement, and it exists because
// parallel plan branches each need a number before any of them merges. A tree carrying
// 0257 and 0259 but not 0258 migrates to a database recording MAX(migration) = 259, which
// satisfies every gate built on the maximum — GetLatestMigrationNumber(), DatabaseImporter's
// two-sided comparison, plan 10's boot check — while 0258 never ran. The runner itself is
// not fooled (it asks per number whether a row exists), so a 0258 merged later is applied;
// what is fooled is whatever decides a database is up to date, and that is what decides
// whether a deployment serves. So the sequence above the baseline has to be contiguous in a
// mergeable tree, and migrations/RESERVATIONS.md is the record of who owns which number and
// therefore of which branch has to merge first.
//
// Migrations up to the baseline (0001-0255) are SQLite-only history that PostgreSQL
// replaces wholesale, so they are exempt by definition.

require_once (getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2)) . '/packages/autoload.php';

use Victual\Services\Database\DatabaseDialect;
use Victual\Services\DatabaseMigrationService;

const MARKER_ENGINE_EXCLUSIVE = '@engine-exclusive';
const MARKER_OVERRIDES_GENERIC = '@overrides-generic';

$migrationsPath = (getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2)) . '/migrations';
$reservationsFile = $migrationsPath . '/RESERVATIONS.md';

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
$warnings = [];
$checked = 0;

// Downgrades exactly one class of failure — a hole whose number RESERVATIONS.md says belongs
// to a branch that has not merged — from fatal to a printed warning, so that the rest of the
// suite can be run on a branch which is knowingly not yet mergeable. Nothing else is
// downgraded, and CI does not pass it: a tree that cannot merge should not go green.
$allowReservedHoles = in_array('--allow-reserved-holes', array_slice($argv, 1), true);

function FileContains(string $path, string $marker): bool
{
	return str_contains(file_get_contents($path), $marker);
}

/**
 * The "Claimed numbers" table of migrations/RESERVATIONS.md, as number => owner.
 *
 * Parsed rather than duplicated here, because a list that exists twice is a list that will
 * eventually disagree with itself — the same reason this script exists at all. The table is
 * the record a person reads and this is the enforcement of it; there is no second copy.
 *
 * @return array<int, string>
 */
function ReadReservations(string $path): array
{
	$reserved = [];

	if (!file_exists($path))
	{
		return $reserved;
	}

	foreach (file($path) as $line)
	{
		$matches = [];

		// | 0258 | plan 01 — the files table (PR #34) | claimed, not in this tree |
		if (preg_match('/^\|\s*(\d{4})\s*\|([^|]*)\|/', $line, $matches) === 1)
		{
			// Markdown links flattened to their text: the owner is quoted back in an error
			// message a person reads at a terminal, not rendered
			$reserved[intval($matches[1])] = trim(preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $matches[2]));
		}
	}

	return $reserved;
}

$reserved = ReadReservations($reservationsFile);

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

// --- The sequence has no holes, and the record says who owns each number -----------------
//
// Everything above the baseline that is not one of the always-run fixups. Those two are
// 8888 and 9999, are never recorded in the migrations table, and would otherwise make every
// tree look like it had a five-thousand-number hole.
$present = array_values(array_filter(
	array_keys($byNumber),
	fn($number) => $number > DatabaseMigrationService::BASELINE_MIGRATION_ID
		&& $number !== DatabaseMigrationService::DOALWAYS_MIGRATION_ID
		&& $number !== DatabaseMigrationService::EMERGENCY_MIGRATION_ID
));

if (!file_exists($reservationsFile))
{
	$problems[] = 'migrations/RESERVATIONS.md is missing. It is the record of which plan owns '
		. 'which migration number, and the check below cannot say whether a hole in the '
		. 'sequence is an unmerged branch or a mistake without it.';
}

if (!empty($present))
{
	$highest = str_pad((string)max($present), 4, '0', STR_PAD_LEFT);
	$highestNumber = max($present);

	for ($number = DatabaseMigrationService::BASELINE_MIGRATION_ID + 1; $number < $highestNumber; $number++)
	{
		if (in_array($number, $present, true))
		{
			continue;
		}

		$padded = str_pad((string)$number, 4, '0', STR_PAD_LEFT);

		if (isset($reserved[$number]))
		{
			$message = "$padded: no file, but migrations/RESERVATIONS.md claims it for "
				. $reserved[$number] . ", and $highest is here. A database migrated against this "
				. "tree records $highest while never having run $padded, and every check built on "
				. 'the highest recorded number then reads it as up to date. This tree is not '
				. "mergeable until the branch owning $padded merges first — see "
				. 'migrations/RESERVATIONS.md for the order.';

			if ($allowReservedHoles)
			{
				$warnings[] = $message;
			}
			else
			{
				$problems[] = $message;
			}

			continue;
		}

		$problems[] = "$padded: no file and nothing in migrations/RESERVATIONS.md claims it, "
			. "while $highest is here. Either a migration was lost, a number was skipped, or the "
			. 'reservation was never written down. All three end with a database that records a '
			. 'number it did not reach.';
	}

	foreach ($present as $number)
	{
		if (!isset($reserved[$number]))
		{
			$problems[] = str_pad((string)$number, 4, '0', STR_PAD_LEFT)
				. ': on disk but not claimed in migrations/RESERVATIONS.md. Add the row, so the '
				. 'next branch picking a number can see this one is taken.';
		}
	}
}

echo 'Checked ' . $checked . ' migration number(s) above the baseline ('
	. DatabaseMigrationService::BASELINE_MIGRATION_ID . '), '
	. count($reserved) . " claimed in migrations/RESERVATIONS.md.\n";

foreach ($warnings as $warning)
{
	echo "  WARNING (--allow-reserved-holes): $warning\n";
}

if (empty($problems))
{
	echo (empty($warnings) ? "MIGRATION NUMBERING OK\n"
		: "MIGRATION NUMBERING OK, EXCEPT " . count($warnings) . " WAIVED HOLE(S)\n");
	exit(0);
}

foreach ($problems as $problem)
{
	echo '  ' . $problem . "\n";
}

echo "\n" . count($problems) . " migration numbering problem(s)\n";
exit(1);
