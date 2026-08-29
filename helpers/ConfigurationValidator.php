<?php

namespace Victual\Helpers;

/**
 * Thrown when a VICTUAL_* configuration constant has an invalid value.
 */
class EInvalidConfig extends \Exception
{
}

/**
 * Validates the VICTUAL_* configuration constants (mode, database driver,
 * locale, currency, entry page, week start days, auto night mode range)
 * on application startup.
 */
class ConfigurationValidator
{
	/**
	 * Runs all configuration checks.
	 *
	 * @throws EInvalidConfig On the first invalid configuration value found
	 */
	public function validateConfig()
	{
		self::checkMode();
		self::checkDatabaseDriver();
		self::checkDefaultLocale();
		self::checkCurrencyFormat();
		self::checkFirstDayOfWeek();
		self::checkEntryPage();
		self::checkMealplanFirstDayOfWeek();
		self::checkAutoNightModeRange();
	}

	private function checkMode()
	{
		$allowedModes = ['production', 'dev', 'demo', 'prerelease'];
		if (!in_array(VICTUAL_MODE, $allowedModes))
		{
			throw new EInvalidConfig('Invalid mode "' . VICTUAL_MODE . '" set, only ' . implode(', ', $allowedModes) . ' allowed');
		}
	}

	private function checkDatabaseDriver()
	{
		$allowedDrivers = ['sqlite', 'pgsql'];
		$driver = strtolower(VICTUAL_DB_DRIVER);

		if (!in_array($driver, $allowedDrivers))
		{
			throw new EInvalidConfig('Invalid database driver "' . VICTUAL_DB_DRIVER . '" set, only ' . implode(', ', $allowedDrivers) . ' allowed');
		}

		if (!in_array($driver, \PDO::getAvailableDrivers()))
		{
			throw new EInvalidConfig('The PDO driver for "' . $driver . '" is not installed in this PHP environment');
		}

		if ($driver === 'pgsql')
		{
			if (empty(VICTUAL_DB_NAME) || empty(VICTUAL_DB_HOST) || empty(VICTUAL_DB_USER))
			{
				throw new EInvalidConfig('DB_HOST, DB_NAME and DB_USER need to be set when DB_DRIVER is "pgsql"');
			}

			$allowedSslModes = ['', 'disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
			if (!in_array(VICTUAL_DB_SSLMODE, $allowedSslModes))
			{
				throw new EInvalidConfig('Invalid DB_SSLMODE "' . VICTUAL_DB_SSLMODE . '" set, only ' . implode(', ', array_filter($allowedSslModes)) . ' allowed');
			}
		}
	}

	private function checkDefaultLocale()
	{
		if (!file_exists(__DIR__ . '/../localization/' . VICTUAL_DEFAULT_LOCALE))
		{
			throw new EInvalidConfig('Invalid locale "' . VICTUAL_DEFAULT_LOCALE . '" set, locale needs to exist in folder localization');
		}
	}

	private function checkFirstDayOfWeek()
	{
		if (!(VICTUAL_CALENDAR_FIRST_DAY_OF_WEEK == '' ||
			(is_numeric(VICTUAL_CALENDAR_FIRST_DAY_OF_WEEK) && VICTUAL_CALENDAR_FIRST_DAY_OF_WEEK >= 0 && VICTUAL_CALENDAR_FIRST_DAY_OF_WEEK <= 6)))
		{
			throw new EInvalidConfig('Invalid value for CALENDAR_FIRST_DAY_OF_WEEK');
		}
	}

	private function checkCurrencyFormat()
	{
		if (!(preg_match('/^([A-z]){3}$/', VICTUAL_CURRENCY)))
		{
			throw new EInvalidConfig('CURRENCY is not in ISO 4217 format (three letter code)');
		}
	}

	private function checkEntryPage()
	{
		$allowedPages = ['stock', 'shoppinglist', 'recipes', 'chores', 'tasks', 'batteries', 'equipment', 'calendar', 'mealplan'];
		if (!in_array(VICTUAL_ENTRY_PAGE, $allowedPages))
		{
			throw new EInvalidConfig('Invalid entry page "' . VICTUAL_ENTRY_PAGE . '" set, only ' . implode(', ', $allowedPages) . ' allowed');
		}
	}

	private function checkMealplanFirstDayOfWeek()
	{
		if (!(VICTUAL_MEAL_PLAN_FIRST_DAY_OF_WEEK == '' ||
			(is_numeric(VICTUAL_MEAL_PLAN_FIRST_DAY_OF_WEEK) && VICTUAL_MEAL_PLAN_FIRST_DAY_OF_WEEK >= -1 && VICTUAL_MEAL_PLAN_FIRST_DAY_OF_WEEK <= 6)))
		{
			throw new EInvalidConfig('Invalid value for MEAL_PLAN_FIRST_DAY_OF_WEEK');
		}
	}

	private function checkAutoNightModeRange()
	{
		global $VICTUAL_DEFAULT_USER_SETTINGS;
		if (!(preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/', $VICTUAL_DEFAULT_USER_SETTINGS['auto_night_mode_time_range_from'])))
		{
			throw new EInvalidConfig('auto_night_mode_time_range_from is not in HH:mm format (' . $VICTUAL_DEFAULT_USER_SETTINGS['auto_night_mode_time_range_from'] . ')');
		}
		if (!(preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/', $VICTUAL_DEFAULT_USER_SETTINGS['auto_night_mode_time_range_to'])))
		{
			throw new EInvalidConfig('auto_night_mode_time_range_to is not in HH:mm format (' . $VICTUAL_DEFAULT_USER_SETTINGS['auto_night_mode_time_range_to'] . ')');
		}
	}
}
