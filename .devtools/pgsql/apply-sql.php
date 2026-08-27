<?php

// Applies a .sql file to a PDO DSN, one statement per ";" at end of line.
//
//   php apply-sql.php <dsn> <file.sql>
//
// Split out of the runner because bash has no business parsing SQL, and split out of
// difftest.php because building the pristine database happens once per run rather than
// once per seed. The splitting rule is the same one difftest.php and trigdifftest.php
// already use, so a fixture that works in one works in all of them.

$dsn = $argv[1] ?? null;
$path = $argv[2] ?? null;

if ($dsn === null || $path === null)
{
	fwrite(STDERR, 'Usage: php apply-sql.php <dsn> <file.sql>' . PHP_EOL);
	exit(1);
}

if (!is_readable($path))
{
	fwrite(STDERR, 'file not found or unreadable: ' . $path . PHP_EOL);
	exit(1);
}

$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$statements = array_filter(array_map('trim', explode(";\n", file_get_contents($path))));

foreach ($statements as $statement)
{
	if ($statement === '')
	{
		continue;
	}

	try
	{
		$pdo->exec($statement);
	}
	catch (PDOException $ex)
	{
		fwrite(STDERR, 'failed applying ' . basename($path) . ': ' . $ex->getMessage() . PHP_EOL);
		fwrite(STDERR, '  ' . trim(preg_replace('/\s+/', ' ', substr($statement, 0, 120))) . PHP_EOL);
		exit(1);
	}
}
