<?php

namespace Grocy\Controllers;

use Grocy\Services\ApplicationService;
use Grocy\Services\DatabaseMigrationService;
use Grocy\Services\DatabaseService;
use Grocy\Services\DemoDataGeneratorService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Slim route controller for application level pages: the root entry point
 * (which also runs database schema migrations), the about page, the PWA
 * manifest and the barcode scanner testing page.
 */
class SystemController extends BaseController
{
	/**
	 * Serves the about view with version, system info and changelog (route GET /about).
	 */
	public function About(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'about', [
			'systemInfo' => ApplicationService::GetInstance()->GetSystemInfo(),
			'versionInfo' => ApplicationService::GetInstance()->GetInstalledVersion(),
			'changelog' => ApplicationService::GetInstance()->GetChangelog()
		]);
	}

	/**
	 * Serves the barcode scanner testing view (route GET /barcodescannertesting).
	 */
	public function BarcodeScannerTesting(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'barcodescannertesting');
	}

	/**
	 * Handles the application root (route GET /): runs pending database schema
	 * migrations, populates demo data in dev/demo/prerelease mode (on SQLite only,
	 * as the demo data generator uses SQLite specific SQL) and redirects to the
	 * configured entry page.
	 */
	public function Root(Request $request, Response $response, array $args)
	{
		// Schema migration is done here
		$databaseMigrationService = DatabaseMigrationService::GetInstance();
		$databaseMigrationService->MigrateDatabase();

		if (GROCY_MODE === 'dev' || GROCY_MODE === 'demo' || GROCY_MODE === 'prerelease')
		{
			// The demo data generator uses SQLite specific SQL, so it can only run there -
			// on any other driver demo data is skipped and the app just continues
			$databaseDialectName = DatabaseService::GetInstance()->GetDialect()->GetName();
			if ($databaseDialectName === 'sqlite')
			{
				$demoDataGeneratorService = DemoDataGeneratorService::GetInstance();
				$demoDataGeneratorService->PopulateDemoData(isset($request->getQueryParams()['nodemodata']));
			}
			else
			{
				file_put_contents('php://stderr', 'Demo data generation is SQLite only and was skipped for the ' . $databaseDialectName . " driver\n");
			}
		}

		return $response->withRedirect($this->AppContainer->get('UrlManager')->ConstructUrl($this->GetEntryPageRelative()));
	}

	/**
	 * Serves the dynamic PWA web app manifest as JSON (route GET /manifest).
	 *
	 * Query parameter data is a base64 encoded '#'-separated pair of
	 * app name suffix and start URL.
	 */
	public function Manifest(Request $request, Response $response, array $args)
	{
		$data = explode('#', base64_decode($request->getQueryParams()['data']));

		$manifest = [
			'name' => 'Grocy ' . $data[0],
			'short_name' => 'Grocy ' . $data[0],
			'icons' => [[
				'src' => './img/icon-1024.png',
				'sizes'=> '1024x1024',
				'type' => 'image/png'
			]],
			'start_url' => $data[1],
			'background_color' => '#333131',
			'theme_color' => '#333131',
			'display' => 'standalone'
		];

		$response->getBody()->write(json_encode($manifest));
		return $response->withHeader('Content-Type', 'application/json');
	}

	/**
	 * Resolves the relative URL of the configured entry page (GROCY_ENTRY_PAGE),
	 * falling back to /about when the corresponding feature is disabled.
	 *
	 * @return string Relative URL, e.g. '/stockoverview'
	 */
	private function GetEntryPageRelative()
	{
		if (defined('GROCY_ENTRY_PAGE'))
		{
			$entryPage = constant('GROCY_ENTRY_PAGE');
		}
		else
		{
			$entryPage = 'stock';
		}

		// Stock
		if ($entryPage === 'stock' && constant('GROCY_FEATURE_FLAG_STOCK'))
		{
			return '/stockoverview';
		}

		// Shoppinglist
		if ($entryPage === 'shoppinglist' && constant('GROCY_FEATURE_FLAG_SHOPPINGLIST'))
		{
			return '/shoppinglist';
		}

		// Recipes
		if ($entryPage === 'recipes' && constant('GROCY_FEATURE_FLAG_RECIPES'))
		{
			return '/recipes';
		}

		// Chores
		if ($entryPage === 'chores' && constant('GROCY_FEATURE_FLAG_CHORES'))
		{
			return '/choresoverview';
		}

		// Tasks
		if ($entryPage === 'tasks' && constant('GROCY_FEATURE_FLAG_TASKS'))
		{
			return '/tasks';
		}

		// Batteries
		if ($entryPage === 'batteries' && constant('GROCY_FEATURE_FLAG_BATTERIES'))
		{
			return '/batteriesoverview';
		}

		if ($entryPage === 'equipment' && constant('GROCY_FEATURE_FLAG_EQUIPMENT'))
		{
			return '/equipment';
		}

		// Calendar
		if ($entryPage === 'calendar' && constant('GROCY_FEATURE_FLAG_CALENDAR'))
		{
			return '/calendar';
		}

		// Meal Plan
		if ($entryPage === 'mealplan' && constant('GROCY_FEATURE_FLAG_RECIPES_MEALPLAN'))
		{
			return '/mealplan';
		}

		return '/about';
	}
}
