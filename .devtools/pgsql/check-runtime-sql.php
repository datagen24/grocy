<?php

// Reject SQLite-only functions embedded in SQL strings assembled by PHP.
//
//   php .devtools/pgsql/check-runtime-sql.php
//
// The database differential suite compares views, triggers and a handful of application
// paths. It cannot see a dialect-specific function that is only assembled when a controller
// or service handles a request. Such calls made the location content sheet fail with 500 and
// "clear done" on a shopping list fail with 400 on PostgreSQL (issue #44).
//
// Only PHP string tokens are inspected. Comments and executable PHP identifiers are outside
// the SQL surface this check guards and may name the functions when explaining the rule.

$root = dirname(__DIR__, 2);
$sourceDirectories = ['controllers', 'services', 'helpers'];
$forbidden = [
	'/\bIFNULL\s*\(/i' => 'IFNULL() is SQLite-only; use the portable COALESCE() function'
];

$problems = [];
$filesChecked = 0;
$stringsChecked = 0;

foreach ($sourceDirectories as $directory)
{
	$path = $root . '/' . $directory;

	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $file)
	{
		if (!$file->isFile() || strtolower($file->getExtension()) !== 'php')
		{
			continue;
		}

		$filesChecked++;
		$relativePath = str_replace($root . '/', '', $file->getPathname());

		foreach (token_get_all(file_get_contents($file->getPathname())) as $token)
		{
			if (!is_array($token)
				|| !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true))
			{
				continue;
			}

			$stringsChecked++;

			foreach ($forbidden as $pattern => $message)
			{
				if (preg_match_all($pattern, $token[1], $matches, PREG_OFFSET_CAPTURE) === 0)
				{
					continue;
				}

				foreach ($matches[0] as [$match, $offset])
				{
					$line = $token[2] + substr_count($token[1], "\n", 0, $offset);
					$problems[] = $relativePath . ':' . $line . '  ' . $message;
				}
			}
		}
	}
}

echo 'Runtime SQL portability: checked ' . $stringsChecked . ' strings in '
	. $filesChecked . " PHP files\n";

if (empty($problems))
{
	echo "\nno forbidden SQLite-only SQL found\n";
	exit(0);
}

fwrite(STDERR, "\nForbidden SQLite-only SQL found:\n\n");
foreach ($problems as $problem)
{
	fwrite(STDERR, '  ' . $problem . "\n");
}

fwrite(STDERR, "\nRuntime SQL has to be valid on PostgreSQL.\n");
exit(1);
