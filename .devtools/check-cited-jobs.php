<?php

// Every CI job the documentation names has to exist.
//
//   php .devtools/check-cited-jobs.php
//
// This check exists because of a specific failure, not as general tidiness. Between
// 2026-09-03 and 2026-09-04 four documents - the plans README, plan 12,
// .devtools/frontend/README.md and the security sweep - stated that the S29 payload probe
// ran on every pull request as the `frontend-security` job. No such job was ever added.
// The probe was real and good and nothing ran it, so two live XSS sinks of exactly the
// class it guards reached master and stayed open until an external scanner reported them.
//
// The corpus is written to be believed. A gate described in prose and absent from the tree
// is worse than no gate at all, because it stops anyone looking. Prose cannot be trusted to
// stay true on its own, so the claim is made checkable: the documentation already spells a
// citation one way - `name` job - and this reads them all back against
// .github/workflows/.
//
// It deliberately does not check the other direction. A job with no mention in the docs is
// fine; a mention with no job is a lie.

// \s covers the newline on purpose: prose is hard-wrapped and a citation straddles one
// routinely, which is why the documents are read whole rather than line by line below.
const CITATION = '/`([a-z][a-z0-9-]*)`\s+job\b/';

$root = dirname(__DIR__);
$workflowDir = $root . '/.github/workflows';

// --- What jobs actually exist -----------------------------------------------------
//
// Read as text rather than parsed as YAML: the lint job this runs in has PHP and nothing
// else, and ext-yaml is not among the extensions the workflow installs. A job key is a
// two-space-indented mapping key directly under the top-level `jobs:`, which is exactly
// what these files contain and is checked here rather than assumed - a workflow whose
// `jobs:` block was never found is reported instead of silently contributing no names.

$defined = [];
$scanned = [];

foreach (glob($workflowDir . '/*.{yml,yaml}', GLOB_BRACE) as $file)
{
	$inJobs = false;
	$found = 0;

	foreach (file($file, FILE_IGNORE_NEW_LINES) as $line)
	{
		if (preg_match('/^jobs:\s*$/', $line))
		{
			$inJobs = true;
			continue;
		}

		// Any other column-0 key ends the jobs block.
		if ($inJobs && preg_match('/^[^\s#]/', $line))
		{
			$inJobs = false;
		}

		if ($inJobs && preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $line, $m))
		{
			$defined[$m[1]] = basename($file);
			$found++;
		}
	}

	$scanned[basename($file)] = $found;
}

foreach ($scanned as $file => $found)
{
	if ($found === 0)
	{
		fwrite(STDERR, "  no jobs found in " . $file . " - this check cannot read it\n");
		exit(1);
	}
}

// --- What the documentation says exists -------------------------------------------

$citations = [];

$documents = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
		function ($current)
		{
			// Vendored trees carry their own prose about their own CI.
			$skip = ['.git', 'node_modules', 'packages', 'public/packages', 'data', 'viewcache'];
			$relative = str_replace(dirname(__DIR__) . '/', '', $current->getPathname());

			return !in_array($relative, $skip, true);
		}
	)
);

foreach ($documents as $file)
{
	if (!$file->isFile() || strtolower($file->getExtension()) !== 'md')
	{
		continue;
	}

	$relative = str_replace($root . '/', '', $file->getPathname());
	$contents = file_get_contents($file->getPathname());

	// The whole file rather than line by line: prose is hard-wrapped, so a citation
	// routinely straddles a line break, and reading lines in isolation would miss exactly
	// the citations a paragraph happened to wrap. The offset is turned back into a line
	// number for the report.
	if (preg_match_all(CITATION, $contents, $matches, PREG_OFFSET_CAPTURE))
	{
		foreach ($matches[1] as [$name, $offset])
		{
			$citations[] = [$name, $relative, substr_count($contents, "\n", 0, $offset) + 1];
		}
	}
}

// --- The answer -------------------------------------------------------------------

$missing = array_values(array_filter($citations, fn ($citation) => !isset($defined[$citation[0]])));

echo 'Jobs defined: ' . implode(', ', array_keys($defined)) . "\n";
echo 'Job citations in the documentation: ' . count($citations) . "\n";

if (empty($citations))
{
	// Not success. Either every citation was removed, or the convention this reads moved
	// and the check has quietly stopped checking anything.
	fwrite(STDERR, "\nNo job citations found at all. Either the documentation stopped naming\n"
		. "jobs, or the `name` job convention this check reads has changed - and a check\n"
		. "that matches nothing cannot fail, which is the state it exists to prevent.\n");
	exit(1);
}

if (empty($missing))
{
	echo "\nevery cited job exists\n";
	exit(0);
}

fwrite(STDERR, "\nThe documentation names " . count($missing) . " job(s) that do not exist:\n\n");

foreach ($missing as [$name, $file, $line])
{
	fwrite(STDERR, '  ' . $file . ':' . $line . '  names `' . $name . "`\n");
}

fwrite(STDERR, "\nAdd the job, or fix the sentence. A gate that exists only in prose stops\n"
	. "people looking for one and guards nothing.\n");
exit(1);
