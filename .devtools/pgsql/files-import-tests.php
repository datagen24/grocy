<?php

// Is bin/victual-files-import's idempotency actually content based?
//
//   php files-import-tests.php
//
// PostgreSQL only, like the table it is about. ConfigurationValidator refuses
// FILE_STORAGE = "database" on any other driver, so there is no second engine to compare
// against and this phase asks one engine a question instead - the same shape as the
// rollback phase, for the same reason.
//
// The question is narrow, and it was a review finding on plan 01. The command used to
// decide "already imported" by comparing filesize() against the stored size_bytes, which
// is not an identity. Two cases below are exactly the ones a length comparison gets
// wrong, and they are different from each other:
//
//   - the file on disk changes while keeping its length, so the database holds an old
//     version of a file that is still there; and
//   - the row's content is replaced while size_bytes stays right, which is what a write
//     killed halfway can leave behind - the shape the command's own header calls out.
//
// In both the length matches and the content does not, so a length check reports success
// over stale bytes. That is the one answer this command must never give, because what
// happens next is that an operator deletes the volume the files came from. Hence also the
// --verify cases: the operator's evidence is an exit code, so the exit code is asserted
// rather than the output alone.
//
// The command is driven as a subprocess rather than called into, so what is under test is
// the file that ships, its exit codes included.

define('VICTUAL_ROOT_PATH', getenv('VICTUAL_ROOT') ?: dirname(__DIR__, 2));

if (!defined('VICTUAL_DATAPATH'))
{
	define('VICTUAL_DATAPATH', getenv('VICTUAL_DATAPATH') ?: VICTUAL_ROOT_PATH . '/data');
}

require_once VICTUAL_ROOT_PATH . '/packages/autoload.php';

if (file_exists(VICTUAL_DATAPATH . '/config.php'))
{
	require_once VICTUAL_DATAPATH . '/config.php';
}

require_once VICTUAL_ROOT_PATH . '/config-dist.php';

if (!defined('VICTUAL_USER_ID'))
{
	define('VICTUAL_USER_ID', 1);
}

use Victual\Services\DatabaseService;

/**
 * The storage tree the cases start from: five files across four groups, including a
 * cached downscale so that a derivative row is in the picture too.
 *
 * The contents are deliberately unremarkable. This phase is about bytes being equal or
 * not; what they depict is the header matrix's question, not this one's.
 */
const SEED_FILES = [
	'productpictures/apple.jpg' => "\xff\xd8\xff\xe0apple-original",
	'productpictures/apple__downscaledto64x64.jpg' => "\xff\xd8\xff\xe0apple-thumb",
	'recipepictures/soup.png' => "\x89PNG\r\n\x1a\nsoup",
	'userfiles/notes.txt' => 'the quick brown fox',
	'equipmentmanuals/mixer.txt' => 'plug it in, press the button',
];

$storagePath = VICTUAL_DATAPATH . '/storage';
$pdo = DatabaseService::GetInstance()->GetDbConnectionRaw();
$failures = 0;

echo '== bin/victual-files-import' . PHP_EOL;

SeedStorage($storagePath);

// --- A first import, from an empty table ------------------------------------------

[$status, $output] = RunImporter();
Check('the first run exits 0', $status === 0, $output);
Check('the first run imports all five files', str_contains($output, 'Imported 5, replaced 0, verified 0, failed 0.'), $output);

$mismatches = StoredDigestsMatchDisk($pdo, $storagePath);
Check('every file is in the table with the right bytes', $mismatches === [], 'mismatches: ' . implode(', ', $mismatches));

// --- A second import, which is the re-runnable Job -------------------------------

[$status, $output] = RunImporter();
Check('a rerun exits 0', $status === 0, $output);
Check('a rerun verifies rather than rewrites', str_contains($output, 'Imported 0, replaced 0, verified 5, failed 0.'), $output);
Check('a rerun says the content was verified, not just that a row exists',
	str_contains($output, 'already imported, content verified'), $output);

[$status, $output] = RunImporter(['--verify']);
Check('--verify on a clean state exits 0', $status === 0, $output);
Check('--verify on a clean state verifies all five', str_contains($output, 'Verified 5, differing 0, missing 0, failed 0.'), $output);

// --- The finding: a source file that changed without changing length ---------------
//
// Nineteen bytes before and nineteen after, so size_bytes still agrees and only the
// digest can tell the two apart.

$changedPath = $storagePath . '/userfiles/notes.txt';
file_put_contents($changedPath, 'the quick brown cat');
Check('the rewritten file is the same length as the one imported',
	filesize($changedPath) === strlen(SEED_FILES['userfiles/notes.txt']), 'lengths differ, the case proves nothing');

[$status, $output] = RunImporter(['--verify']);
Check('--verify notices a same-length change on disk', $status === 1, $output);
Check('--verify names the file that differs', str_contains($output, 'DIFFERS  userfiles/notes.txt'), $output);
Check('--verify counts it as differing rather than missing',
	str_contains($output, 'Verified 4, differing 1, missing 0, failed 0.'), $output);
Check('--verify wrote nothing', StoredDigest($pdo, 'userfiles', 'notes.txt') === hash('sha256', SEED_FILES['userfiles/notes.txt']), 'the row moved under --verify');

[$status, $output] = RunImporter();
Check('the import re-imports it', $status === 0 && str_contains($output, 'Imported 0, replaced 1, verified 4, failed 0.'), $output);
Check('the import says it differed rather than reporting a bare replacement',
	str_contains($output, 'replaced notes.txt (differs, re-imported'), $output);
Check('the stored bytes are now the new ones', StoredDigest($pdo, 'userfiles', 'notes.txt') === hash_file('sha256', $changedPath), 'the row still holds the old file');

// --- The other half: a row that lies about itself ----------------------------------
//
// content is replaced and size_bytes is left alone, which is the shape a write killed
// between the bytes and the commit can leave. Written through decode(?, 'hex') so the
// case does not depend on how PDO chooses to bind a bytea parameter.

$corrupted = str_repeat('x', strlen(SEED_FILES['recipepictures/soup.png']));
$pdo->prepare("UPDATE files SET content = decode(?, 'hex') WHERE file_group = ? AND name = ?")
	->execute([bin2hex($corrupted), 'recipepictures', 'soup.png']);

Check('the corrupted row still reports the right size',
	(int)ColumnValue($pdo, 'SELECT size_bytes FROM files WHERE file_group = ? AND name = ?', ['recipepictures', 'soup.png'])
		=== strlen(SEED_FILES['recipepictures/soup.png']), 'size_bytes moved, so the case proves nothing');

[$status, $output] = RunImporter(['--verify']);
Check('--verify notices content that does not match its own size_bytes', $status === 1, $output);
Check('--verify names the corrupted file', str_contains($output, 'DIFFERS  recipepictures/soup.png'), $output);

[$status, $output] = RunImporter();
Check('the import replaces the corrupted row', $status === 0 && str_contains($output, 'replaced soup.png (differs, re-imported'), $output);
Check('the corrupted row now holds the file', StoredDigest($pdo, 'recipepictures', 'soup.png')
	=== hash('sha256', SEED_FILES['recipepictures/soup.png']), 'the row still holds the corrupted bytes');

// --- A row that is not there at all -------------------------------------------------

$pdo->prepare('DELETE FROM files WHERE file_group = ? AND name = ?')->execute(['productpictures', 'apple__downscaledto64x64.jpg']);

[$status, $output] = RunImporter(['--verify']);
Check('--verify notices a missing row', $status === 1, $output);
Check('--verify separates missing from differing',
	str_contains($output, 'MISSING  productpictures/apple__downscaledto64x64.jpg')
	&& str_contains($output, 'Verified 4, differing 0, missing 1, failed 0.'), $output);

[$status, $output] = RunImporter();
Check('the import puts it back', $status === 0 && str_contains($output, 'Imported 1, replaced 0, verified 4, failed 0.'), $output);

[$status, $output] = RunImporter(['--verify']);
Check('--verify is clean again, which is the operator go-ahead', $status === 0, $output);

$mismatches = StoredDigestsMatchDisk($pdo, $storagePath);
Check('and every stored digest matches its file', $mismatches === [], 'mismatches: ' . implode(', ', $mismatches));

// --- The flag itself ----------------------------------------------------------------
//
// A mistyped --verify used to be an argument the command ignored, which would have made
// it write when the operator asked it to look. Asserted because the whole point of the
// flag is that it does not write.

[$status, $output] = RunImporter(['--verfiy']);
Check('a mistyped flag is refused rather than ignored', $status === 1 && str_contains($output, 'Unknown argument'), $output);

echo PHP_EOL;

if ($failures === 0)
{
	echo 'FILE IMPORT OK' . PHP_EOL;
	exit(0);
}

echo 'FILE IMPORT FAILED - ' . $failures . ' case(s)' . PHP_EOL;
exit(1);

/** Records one assertion, printing what failed rather than only that something did. */
function Check(string $what, bool $ok, string $detail = ''): void
{
	global $failures;

	if ($ok)
	{
		echo '  ok    ' . $what . PHP_EOL;

		return;
	}

	$failures++;
	echo '  FAIL  ' . $what . PHP_EOL;

	if ($detail !== '')
	{
		foreach (explode(PHP_EOL, $detail) as $line)
		{
			echo '          ' . $line . PHP_EOL;
		}
	}
}

/** Writes SEED_FILES under the storage path, replacing whatever was there. */
function SeedStorage(string $storagePath): void
{
	RemoveDirectory($storagePath);

	foreach (SEED_FILES as $relative => $content)
	{
		$path = $storagePath . '/' . $relative;

		if (!is_dir(dirname($path)))
		{
			mkdir(dirname($path), 0777, true);
		}

		file_put_contents($path, $content);
	}
}

function RemoveDirectory(string $path): void
{
	if (!is_dir($path))
	{
		return;
	}

	foreach (array_diff(scandir($path), ['.', '..']) as $entry)
	{
		$child = $path . '/' . $entry;
		is_dir($child) ? RemoveDirectory($child) : unlink($child);
	}

	rmdir($path);
}

/**
 * Runs the importer and returns its exit code and combined output.
 *
 * stderr is merged into stdout because the reports go to one and the counts to the other,
 * and the cases assert on both. The environment is inherited, which is how the child
 * finds VICTUAL_DATAPATH and the connection settings this process was given.
 *
 * @param string[] $arguments
 * @return array{0: int, 1: string}
 */
function RunImporter(array $arguments = []): array
{
	$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(VICTUAL_ROOT_PATH . '/bin/victual-files-import');

	foreach ($arguments as $argument)
	{
		$command .= ' ' . escapeshellarg($argument);
	}

	$output = [];
	$status = 0;
	exec($command . ' 2>&1', $output, $status);

	return [$status, implode(PHP_EOL, $output)];
}

/** The SHA-256 of a stored file's bytes, hashed by the server, or null when there is no row. */
function StoredDigest(PDO $pdo, string $group, string $name): ?string
{
	$digest = ColumnValue($pdo, "SELECT encode(sha256(content), 'hex') FROM files WHERE file_group = ? AND name = ?", [$group, $name]);

	return $digest === false ? null : (string)$digest;
}

/** @param array<int, string> $parameters */
function ColumnValue(PDO $pdo, string $sql, array $parameters)
{
	$statement = $pdo->prepare($sql);
	$statement->execute($parameters);

	return $statement->fetchColumn();
}

/**
 * The seed files whose stored bytes do not match the file on disk.
 *
 * Independent of the command's own report on purpose: a summary line saying five files
 * verified is the command grading its own homework, and this is the database being asked.
 *
 * @return string[] The relative paths that differ, empty when everything matches
 */
function StoredDigestsMatchDisk(PDO $pdo, string $storagePath): array
{
	$mismatches = [];

	foreach (array_keys(SEED_FILES) as $relative)
	{
		[$group, $name] = explode('/', $relative, 2);
		$path = $storagePath . '/' . $relative;

		if (StoredDigest($pdo, $group, $name) !== hash_file('sha256', $path))
		{
			$mismatches[] = $relative;
		}
	}

	return $mismatches;
}
