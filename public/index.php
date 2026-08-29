<?php

// Definitions for embedded mode

if (file_exists(__DIR__ . '/../embedded.txt'))
{
	define('VICTUAL_IS_EMBEDDED_INSTALL', true);
	define('VICTUAL_DATAPATH', file_get_contents(__DIR__ . '/../embedded.txt'));
	define('VICTUAL_USER_ID', 1);
}
else
{
	define('VICTUAL_IS_EMBEDDED_INSTALL', false);

	$datapath = 'data';

	if (getenv('VICTUAL_DATAPATH') !== false)
	{
		$datapath = getenv('VICTUAL_DATAPATH');
	}
	elseif (array_key_exists('VICTUAL_DATAPATH', $_SERVER))
	{
		$datapath = $_SERVER['VICTUAL_DATAPATH'];
	}

	if ($datapath[0] != '/')
	{
		$datapath = __DIR__ . '/../' . $datapath;
	}

	define('VICTUAL_DATAPATH', $datapath);
}

require_once __DIR__ . '/../helpers/PrerequisiteChecker.php';

try
{
	(new Victual\Helpers\PrerequisiteChecker())->checkRequirements();
}
catch (Victual\Helpers\ERequirementNotMet $ex)
{
	exit('Unable to run Grocy: ' . $ex->getMessage());
}

require_once __DIR__ . '/../app.php';
