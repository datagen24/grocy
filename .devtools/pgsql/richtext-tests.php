<?php

// Does rich text that never met the API's purifier get cleaned up?
//
//   php richtext-tests.php [--source <sqlite file>]
//
// With --source it also runs the import case: the payloads are planted in that SQLite
// database and DatabaseImporter copies it into the engine this script is pointed at, which
// is the path bin/victual-db-import takes. Only meaningful against PostgreSQL, since that
// is the only target the importer has.
//
// Runs against whichever engine VICTUAL_DATAPATH's config.php selects, so the runner can
// point it at SQLite and then at PostgreSQL. See run-tests.sh.
//
// This phase exists because of review finding P1 on pull request #41, and the finding is
// worth restating because it is a gap in an argument rather than a bug in a line. Five
// columns are rendered as HTML rather than escaped, so the boundary for them is the
// HTMLPurifier that every API write goes through - and the frontend probe added in that
// same pull request asserts exactly that, by writing payloads through the API and reading
// them back. Both are true and neither covers a row that never went through the API:
//
//   - a database upgraded in place from upstream grocy, or from this fork before security
//     sweep finding S1 added the purifier, still holds whatever was typed then; and
//   - DatabaseImporter copies rows verbatim, which is what an importer should do.
//
// Those rows reach `{!! $recipe->description !!}` in views/recipes.blade.php, the .html()
// renders in shoppinglist.js, equipment.js, productcard.js and chorecard.js, and
// summernote's own editable div. So migration 0260 and the importer both run
// StoredHtmlPurifier, and this asserts that they work - by planting payloads the way the
// gap does, with a direct write, rather than through the API that would have cleaned them.
//
// Case 4 is the one that keeps the rest honest: the same payloads written to a column that
// is *not* HTML-rendered must survive untouched, because those columns are escaped at
// every sink and rewriting them would be data loss dressed up as a security fix.
//
// The database is mutated as each case needs and restored in a finally, for the reason
// schemagatetest.php gives: the runner builds this database from nothing, but a phase that
// leaves a database it damaged behind will eventually run against one that matters.

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

use Victual\Controllers\Api\BaseApiController;
use Victual\Services\Database\StoredHtmlPurifier;
use Victual\Services\DatabaseService;

$db = DatabaseService::GetInstance();
$pdo = $db->GetDbConnectionRaw();
$dialect = $db->GetDialect();
$engine = $dialect->GetName();

$failures = 0;

function Ok(string $label, string $detail): void
{
	printf("  ok     %-40s %s\n", $label, $detail);
}

function Fail(string $label, string $detail): void
{
	global $failures;

	$failures++;
	printf("  FAIL   %-40s %s\n", $label, $detail);
}

function Check(string $label, bool $condition, string $expected, string $actual): void
{
	$condition ? Ok($label, $actual) : Fail($label, 'expected ' . $expected . ', got ' . $actual);
}

/** What must never survive into a rendered column, whatever shape it arrived in. */
const DANGEROUS = [
	'/<script/i' => 'a <script> tag',
	'/<iframe/i' => 'an <iframe>',
	'/<svg/i' => 'an <svg> element',
	'/<object/i' => 'an <object>',
	'/\son[a-z]+\s*=/i' => 'an inline event handler',
	'/javascript\s*:/i' => 'a javascript: URI'
];

// Written straight to the column, which is the whole point: this is what a row that
// predates the purifier looks like. The last entry is legitimate summernote output and has
// to come back recognisable - a routine that emptied every column would otherwise pass.
const PAYLOADS = [
	'img-onerror' => '<img src=x onerror=window.__xss=1>',
	'script' => '<script>window.__xss=1</script>',
	'svg-onload' => '<svg onload=window.__xss=1></svg>',
	'a-javascript-uri' => '<a href="javascript:window.__xss=1">click</a>',
	'iframe' => '<iframe src="https://example.invalid/"></iframe>',
	'legitimate' => '<h1>Notes</h1><p><b>bold</b> and <span style="background-color: rgb(255, 255, 0);">mark</span></p><ul><li>one</li></ul>'
];

echo 'Stored rich text (' . $engine . ")\n\n";

$q = fn(string $name) => $dialect->QuoteIdentifier($name);

// --- Plant one payload-bearing row per HTML-rendered column ------------------------
//
// An UPDATE of an existing row rather than an INSERT: these tables have required columns
// and foreign keys that differ per entity, and the question here is about the column's
// contents, not about creating rows.

$planted = [];
$originals = [];

foreach (BaseApiController::HTML_RENDERED_COLUMNS as $table => $columns)
{
	$idRow = $pdo->query('SELECT ' . $q('id') . ' FROM ' . $q($table) . ' ORDER BY ' . $q('id') . ' LIMIT 1')->fetch(PDO::FETCH_NUM);

	if ($idRow === false)
	{
		// A table with no rows cannot carry the finding, but it also cannot demonstrate
		// the fix, and a phase that silently tested four of five columns is the kind of
		// thing this pull request exists to stop.
		Fail('a row exists to plant in: ' . $table, 'the table is empty, so this column was never tested');
		continue;
	}

	foreach ($columns as $column)
	{
		$originals[] = [$table, $column, $idRow[0],
			$pdo->query('SELECT ' . $q($column) . ' FROM ' . $q($table) . ' WHERE ' . $q('id') . ' = ' . (int)$idRow[0])->fetchColumn()];
		$planted[] = [$table, $column, (int)$idRow[0]];
	}
}

try
{
	// --- 1. Every payload is neutralised, and real formatting is not ------------------

	foreach (PAYLOADS as $name => $payload)
	{
		foreach ($planted as [$table, $column, $id])
		{
			$write = $pdo->prepare('UPDATE ' . $q($table) . ' SET ' . $q($column) . ' = ? WHERE ' . $q('id') . ' = ?');
			$write->execute([$payload, $id]);
		}

		StoredHtmlPurifier::Purify($pdo, $dialect);

		$offences = [];
		$lostFormatting = [];

		foreach ($planted as [$table, $column, $id])
		{
			$stored = (string)$pdo->query('SELECT ' . $q($column) . ' FROM ' . $q($table) . ' WHERE ' . $q('id') . ' = ' . $id)->fetchColumn();

			if ($name === 'legitimate')
			{
				// Any of the four is enough: HTMLPurifier reformats what it keeps (it
				// drops the spaces inside "rgb(255, 255, 0)"), so an exact comparison
				// would fail on tidying rather than on loss.
				$kept = array_filter(['<h1>', '<b>', '<ul>', 'background-color'], fn($marker) => str_contains($stored, $marker));

				if (count($kept) < 4)
				{
					$lostFormatting[] = $table . '.' . $column . ' kept ' . count($kept) . ' of 4 markers';
				}

				continue;
			}

			foreach (DANGEROUS as $pattern => $description)
			{
				if (preg_match($pattern, $stored))
				{
					$offences[] = $table . '.' . $column . ': ' . $description . ' survived as ' . var_export($stored, true);
				}
			}
		}

		if ($name === 'legitimate')
		{
			Check('legitimate formatting survives',
				empty($lostFormatting),
				'every marker kept in all ' . count($planted) . ' columns',
				empty($lostFormatting) ? 'all ' . count($planted) . ' columns intact' : implode('; ', $lostFormatting));

			continue;
		}

		Check('neutralised: ' . $name,
			empty($offences),
			'nothing dangerous in any of the ' . count($planted) . ' columns',
			empty($offences) ? 'clean in all ' . count($planted) . ' columns' : implode('; ', $offences));
	}

	// --- 2. A second run rewrites nothing ---------------------------------------------
	//
	// Migration 0260 runs once, but the importer's call runs on every import and
	// StoredHtmlPurifier is the kind of routine someone will later put behind a command.
	// Purifier output that is not a fixed point would make every run rewrite every row and
	// move the "database changed" timestamp clients poll.

	$secondPass = StoredHtmlPurifier::Purify($pdo, $dialect);

	Check('purifying twice changes nothing',
		empty($secondPass),
		'no rows rewritten on the second pass',
		empty($secondPass) ? 'no rows rewritten' : json_encode($secondPass));

	// --- 3. The routine reports what it did -------------------------------------------
	//
	// The importer prints this count to an operator who is deciding whether the import
	// worked, so a routine that silently returned an empty report would be worse than one
	// that did nothing.

	$write = $pdo->prepare('UPDATE ' . $q($planted[0][0]) . ' SET ' . $q($planted[0][1]) . ' = ? WHERE ' . $q('id') . ' = ?');
	$write->execute(['<img src=x onerror=window.__xss=1>', $planted[0][2]]);

	$report = StoredHtmlPurifier::Purify($pdo, $dialect);
	$key = $planted[0][0] . '.' . $planted[0][1];

	Check('the report names what changed',
		($report[$key] ?? 0) === 1,
		$key . ' => 1',
		json_encode($report));

	// --- 4. Columns that are not HTML-rendered are left alone --------------------------
	//
	// The other side of the boundary. Every other column is escaped at its sink, and its
	// stored value is the text somebody typed - "Fish & chips" stays "Fish & chips" rather
	// than becoming "Fish &amp; chips". Rewriting those would be data loss.

	$nameBefore = $pdo->query('SELECT ' . $q('name') . ' FROM ' . $q('products') . ' ORDER BY ' . $q('id') . ' LIMIT 1')->fetchColumn();
	$productId = (int)$pdo->query('SELECT ' . $q('id') . ' FROM ' . $q('products') . ' ORDER BY ' . $q('id') . ' LIMIT 1')->fetchColumn();
	$textPayload = 'Fish & chips <not a tag> "quoted"';

	$write = $pdo->prepare('UPDATE ' . $q('products') . ' SET ' . $q('name') . ' = ? WHERE ' . $q('id') . ' = ?');
	$write->execute([$textPayload, $productId]);

	try
	{
		StoredHtmlPurifier::Purify($pdo, $dialect);

		$after = (string)$pdo->query('SELECT ' . $q('name') . ' FROM ' . $q('products') . ' WHERE ' . $q('id') . ' = ' . $productId)->fetchColumn();

		Check('a text column is untouched',
			$after === $textPayload,
			var_export($textPayload, true),
			var_export($after, true));
	}
	finally
	{
		$write->execute([$nameBefore, $productId]);
	}
}
finally
{
	foreach ($originals as [$table, $column, $id, $value])
	{
		$restore = $pdo->prepare('UPDATE ' . $q($table) . ' SET ' . $q($column) . ' = ? WHERE ' . $q('id') . ' = ?');
		$restore->execute([$value, $id]);
	}
}

// --- 5. The import path ------------------------------------------------------------
//
// The other way a row arrives without having met the purifier, and the one the review
// finding named. Everything above proves StoredHtmlPurifier works; this proves the importer
// calls it, which is a different claim and the one an operator depends on.

$sourceArgument = array_search('--source', $argv, true);
$sourcePath = $sourceArgument === false ? null : ($argv[$sourceArgument + 1] ?? null);

if ($sourcePath !== null)
{
	$source = new PDO('sqlite:' . $sourcePath);
	$source->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	// Planted with a direct write, exactly as a database that predates the purifier holds
	// it. The importer copies rows verbatim, so this is what lands in the target.
	foreach (BaseApiController::HTML_RENDERED_COLUMNS as $table => $columns)
	{
		foreach ($columns as $column)
		{
			$source->exec('UPDATE "' . $table . '" SET "' . $column . '" = '
				. $source->quote('<img src=x onerror=window.__xss=1>'));
		}
	}

	(new Victual\Services\Database\DatabaseImporter($source, $pdo, $dialect, fn($m) => null))->Import(true);

	$survived = [];

	foreach (BaseApiController::HTML_RENDERED_COLUMNS as $table => $columns)
	{
		foreach ($columns as $column)
		{
			$rows = $pdo->query('SELECT ' . $q($column) . ' FROM ' . $q($table)
				. ' WHERE ' . $q($column) . ' IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);

			foreach ($rows as $stored)
			{
				foreach (DANGEROUS as $pattern => $description)
				{
					if (preg_match($pattern, (string)$stored))
					{
						$survived[] = $table . '.' . $column . ': ' . $description;
					}
				}
			}
		}
	}

	Check('an imported payload is neutralised',
		empty($survived),
		'nothing dangerous anywhere in the imported target',
		empty($survived) ? 'clean across all ' . count($planted) . ' imported columns' : implode('; ', array_unique($survived)));
}

echo "\n";

if ($failures === 0)
{
	echo "STORED RICH TEXT OK\n";
	exit(0);
}

echo $failures . " case(s) failed\n";
exit(1);
