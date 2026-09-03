<?php

namespace Victual\Controllers;

use DI\Container;
use Victual\Controllers\Users\User;
use Victual\Services\ApplicationService;
use Victual\Services\DatabaseService;
use Victual\Services\LocalizationService;
use Victual\Services\UsersService;

/**
 * Base class for all non-API (view) controllers.
 *
 * Provides the shared DI container, the Blade view engine and the database
 * connection, plus the Render()/RenderPage() helpers that populate the
 * template variables common to every Victual page (localization, feature
 * flags, permissions, URL builder etc.).
 *
 * The connection is acquired by GetDb() when a controller asks for it, and deliberately
 * not in the constructor. `app.php` builds the error middleware by constructing
 * ExceptionController, which is a controller - so a constructor that connected made the
 * error handler impossible to *build* without a database, and an unreachable one killed
 * the application while it was still being assembled: an uncaught PDOException rendered
 * as a raw PHP fatal, before any middleware ran, answered 200 because nothing had set a
 * status yet. That is the same defect f4d1769b fixed one line above for middlewares (see
 * middleware/BaseMiddleware.php); a controller that needs the database asks for it when
 * it runs, not when it is constructed. See docs/plans/15-deliberate-cleanup.md, C15.
 */
class BaseController
{
	/**
	 * Wires up the view engine from the DI container. Opens nothing.
	 */
	public function __construct(Container $container)
	{
		$this->AppContainer = $container;
		$this->View = $container->get('view');
	}

	/** @var Container The application DI container */
	protected $AppContainer;

	/** @var \Victual\Helpers\SlimBladeView The shared Blade view engine */
	protected $View;

	/**
	 * The shared fluent database connection (SQLite or PostgreSQL, depending on
	 * configuration), opened on first use.
	 *
	 * Nothing is memoized here on purpose: DatabaseService already holds the one
	 * connection and the one LessQL wrapper for the process, so a copy in every
	 * controller would only be a second name for the same object - and a controller
	 * holding a connection it never used is exactly what this method exists to stop.
	 *
	 * @return \LessQL\Database
	 */
	protected function GetDb()
	{
		return DatabaseService::GetInstance()->GetDbConnection();
	}

	/**
	 * Renders the given view with the globally needed template variables set
	 * (version, translation closures, text direction, URL builder, feature flags
	 * and - when authenticated - the current user's permissions).
	 *
	 * @param \Psr\Http\Message\ResponseInterface $response
	 * @param string $viewName Name of the view file (without extension) below the views folder
	 * @param array $data Additional variables passed through to the view
	 * @return \Psr\Http\Message\ResponseInterface
	 */
	protected function Render($response, $viewName, $data = [])
	{
		$container = $this->AppContainer;

		$versionInfo = ApplicationService::GetInstance()->GetInstalledVersion();
		$this->View->set('version', $versionInfo->Version);

		$localizationService = LocalizationService::GetInstance();
		$this->View->set('__t', function (string $text, ...$placeholderValues) use ($localizationService)
		{
			return $localizationService->__t($text, $placeholderValues);
		});
		$this->View->set('__n', function ($number, $singularForm, $pluralForm, $isQu = false) use ($localizationService)
		{
			return $localizationService->__n($number, $singularForm, $pluralForm, $isQu);
		});
		$this->View->set('LocalizationStrings', $localizationService->GetPoAsJsonString());
		$this->View->set('LocalizationStringsQu', $localizationService->GetPoAsJsonStringQu());

		// TODO: Better handle this generically based on the current language (header in .po file?)
		$dir = 'ltr';
		if (VICTUAL_LOCALE == 'he_IL')
		{
			$dir = 'rtl';
		}
		$this->View->set('dir', $dir);

		$this->View->set('U', function ($relativePath, $isResource = false) use ($container)
		{
			return $container->get('UrlManager')->ConstructUrl($relativePath, $isResource);
		});

		$embedded = false;
		if (isset($_GET['embedded']))
		{
			$embedded = true;
		}
		$this->View->set('embedded', $embedded);

		$constants = get_defined_constants();
		foreach ($constants as $constant => $value)
		{
			if (!str_starts_with($constant, 'VICTUAL_FEATURE_FLAG_'))
			{
				unset($constants[$constant]);
			}
		}
		$this->View->set('featureFlags', $constants);

		if (VICTUAL_AUTHENTICATED)
		{
			$this->View->set('permissions', User::PermissionList());

			$decimalPlacesAmounts = UsersService::GetInstance()->GetUserSetting(VICTUAL_USER_ID, 'stock_decimal_places_amounts');
			if ($decimalPlacesAmounts <= 0)
			{
				$defaultMinAmount = 1;
			}
			else
			{
				$defaultMinAmount = '0.' . str_repeat('0', $decimalPlacesAmounts - 1) . '1';
			}
			$this->View->set('DEFAULT_MIN_AMOUNT', $defaultMinAmount);
		}

		$this->View->set('viewName', $viewName);

		return $this->View->Render($response, $viewName, $data);
	}

	/**
	 * Renders a full page: additionally provides the sidebar userentities and the
	 * current user's settings (null when not logged in), then delegates to Render().
	 *
	 * @param \Psr\Http\Message\ResponseInterface $response
	 * @param string $viewName Name of the view file (without extension) below the views folder
	 * @param array $data Additional variables passed through to the view
	 * @return \Psr\Http\Message\ResponseInterface
	 */
	protected function RenderPage($response, $viewName, $data = [])
	{
		$this->View->set('userentitiesForSidebar', $this->GetDb()->userentities()->where('show_in_sidebar_menu = 1')->orderBy('name'));
		try
		{
			$usersService = UsersService::GetInstance();
			if (defined('VICTUAL_USER_ID'))
			{
				$this->View->set('userSettings', $usersService->GetUserSettings(VICTUAL_USER_ID));
			}
			else
			{
				$this->View->set('userSettings', null);
			}
		}
		catch (\Exception $ex)
		{
			// Happens when database is not initialised or migrated...
		}

		return $this->Render($response, $viewName, $data);
	}
}
