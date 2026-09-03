<?php

use Victual\Controllers\ExceptionController;
use Victual\Helpers\CachePaths;
use Victual\Helpers\SlimBladeView;
use Victual\Helpers\UrlManager;
use Victual\Middleware\LocaleMiddleware;
use Victual\Middleware\SchemaVersionMiddleware;
use Psr\Container\ContainerInterface as Container;
use Slim\Factory\AppFactory;

// Load composer dependencies
require_once __DIR__ . '/packages/autoload.php';

// Load config files
require_once VICTUAL_DATAPATH . '/config.php';
require_once __DIR__ . '/config-dist.php'; // For not in own config defined values we use the default ones

// Error reporting definitions
if (VICTUAL_MODE === 'dev')
{
	error_reporting(E_ALL);
}
else
{
	error_reporting(E_ALL ^ (E_NOTICE | E_WARNING | E_DEPRECATED));
}

// Definitions for dev/demo/prerelease mode
if ((VICTUAL_MODE === 'dev' || VICTUAL_MODE === 'demo' || VICTUAL_MODE === 'prerelease') && !defined('VICTUAL_USER_ID'))
{
	define('VICTUAL_USER_ID', 1);
}

// Definitions for disabled authentication mode
if (VICTUAL_DISABLE_AUTH === true)
{
	if (!defined('VICTUAL_USER_ID'))
	{
		define('VICTUAL_USER_ID', 1);
	}
}

// The prerequisites which depend on the configured database engine. public/index.php
// checks everything else before this file is even loaded; the driver is only known here,
// once the configuration has been read.
try
{
	(new Victual\Helpers\PrerequisiteChecker())->checkDatabaseRequirements(VICTUAL_DB_DRIVER);
}
catch (\Victual\Helpers\ERequirementNotMet $ex)
{
	exit('Unable to run Victual: ' . $ex->getMessage());
}

// Check if any invalid entries in config.php have been made
try
{
	(new Victual\Helpers\ConfigurationValidator())->validateConfig();
}
catch (\Victual\Helpers\EInvalidConfig $ex)
{
	exit('Invalid setting in config.php: ' . $ex->getMessage());
}

// Create the view cache folder if it doesn't exist. A baked, read-only cache directory
// already exists and is not writable, so a failure here is not one: whatever is missing
// surfaces where it is used, with a message about that file rather than about mkdir.
if (!file_exists(VICTUAL_VIEWCACHE_PATH))
{
	@mkdir(VICTUAL_VIEWCACHE_PATH, 0755, true);
}

// Setup base application
AppFactory::setContainer(new DI\Container());
$app = AppFactory::create();

$container = $app->getContainer();
$container->set('view', function (Container $container)
{
	return new SlimBladeView(__DIR__ . '/views', VICTUAL_VIEWCACHE_PATH);
});

$container->set('UrlManager', function (Container $container)
{
	return new UrlManager(VICTUAL_BASE_URL);
});

$container->set('ApiKeyHeaderName', function (Container $container)
{
	return 'VICTUAL-API-KEY';
});

// Load routes from separate file
require_once __DIR__ . '/routes.php';

// Set base path if defined
if (!empty(VICTUAL_BASE_PATH))
{
	$app->setBasePath(VICTUAL_BASE_PATH);
}

$app->add(new LocaleMiddleware($container, $app->getResponseFactory()));
$authMiddlewareClass = VICTUAL_AUTH_CLASS;
$app->add(new $authMiddlewareClass($container, $app->getResponseFactory()));

// Add default middleware
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
// Outside routing and authentication on purpose: a database whose schema does not match
// this code cannot be trusted to resolve a route or identify a user, and the answer is
// the same for every route anyway
$app->add(new SchemaVersionMiddleware($container, $app->getResponseFactory()));
// Error details (including stack traces) are only displayed in dev mode
// (arguments are displayErrorDetails, logErrors, logErrorDetails)
$errorMiddleware = $app->addErrorMiddleware(VICTUAL_MODE === 'dev', false, false);
$errorMiddleware->setDefaultErrorHandler(new ExceptionController($container, $app->getResponseFactory()));

$app->getRouteCollector()->setCacheFile(CachePaths::RouteCacheFile());

// No response output before here. Guarded because ob_clean() with no buffer open is not a
// no-op but an E_NOTICE, and output_buffering is off by default on the CLI SAPI - so on
// the built-in server in dev mode, where notices are displayed, this line was itself the
// first thing written to every response. Anything written before Slim emits is what makes
// a response's status unsettable, which is the failure this whole path exists to avoid:
// the body said 503 and the status line said 200.
if (ob_get_level() > 0)
{
	ob_clean();
}

$app->run();
