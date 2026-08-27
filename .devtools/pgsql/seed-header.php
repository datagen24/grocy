<?php

// Prints the view list a seed declares, so the runner does not have to parse SQL with
// grep. A seed's header looks like:
//
//   -- @views products_view stock_current_overview
//
// Multiple @views lines are allowed and are concatenated, which keeps a long list
// readable instead of forcing one very wide line.
//
// Exits non-zero when a seed declares no views at all. A seed that exercises nothing is
// almost certainly a mistake, and silently running zero comparisons for it would let
// that mistake pass as a green run.

$path = $argv[1] ?? null;

if ($path === null || !is_readable($path))
{
	fwrite(STDERR, 'seed not found or unreadable: ' . var_export($path, true) . PHP_EOL);
	exit(1);
}

$views = [];

foreach (file($path) as $line)
{
	$line = trim($line);

	// The header stops at the first line that is not a comment; everything after that
	// is SQL and an "@views" in it would be part of a string or a stray note.
	if ($line !== '' && !str_starts_with($line, '--'))
	{
		break;
	}

	if (preg_match('/^--\s*@views\s+(.+)$/', $line, $matches))
	{
		foreach (preg_split('/\s+/', trim($matches[1])) as $view)
		{
			if ($view !== '')
			{
				$views[] = $view;
			}
		}
	}
}

if (empty($views))
{
	fwrite(STDERR, 'no "-- @views" header in ' . $path . PHP_EOL);
	exit(1);
}

echo implode(' ', array_unique($views)) . PHP_EOL;
