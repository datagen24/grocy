<?php

namespace Grocy\Helpers;

class EInvalidConfig extends \Exception
{
}

class ConfigurationValidator
{
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
		if (!in_array(GROCY_MODE, $allowedModes))
		{
			throw new EInvalidConfig('Invalid mode "' . GROCY_MODE . '" set, only ' . implode(', ', $allowedModes) . ' allowed');
		}
	}

	private function checkDatabaseDriver()
	{
		$allowedDrivers = ['sqlite', 'pgsql'];
		$driver = strtolower(GROCY_DB_DRIVER);

		if (!in_array($driver, $allowedDrivers))
		{
			throw new EInvalidConfig('Invalid database driver "' . GROCY_DB_DRIVER . '" set, only ' . implode(', ', $allowedDrivers) . ' allowed');
		}

		if (!in_array($driver, \PDO::getAvailableDrivers()))
		{
			throw new EInvalidConfig('The PDO driver for "' . $driver . '" is not installed in this PHP environment');
		}

		if ($driver === 'pgsql')
		{
			if (empty(GROCY_DB_NAME) || empty(GROCY_DB_HOST) || empty(GROCY_DB_USER))
			{
				throw new EInvalidConfig('DB_HOST, DB_NAME and DB_USER need to be set when DB_DRIVER is "pgsql"');
			}

			$allowedSslModes = ['', 'disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];
			if (!in_array(GROCY_DB_SSLMODE, $allowedSslModes))
			{
				throw new EInvalidConfig('Invalid DB_SSLMODE "' . GROCY_DB_SSLMODE . '" set, only ' . implode(', ', array_filter($allowedSslModes)) . ' allowed');
			}
		}
	}

	private function checkDefaultLocale()
	{
		if (!file_exists(__DIR__ . '/../localization/' . GROCY_DEFAULT_LOCALE))
		{
			throw new EInvalidConfig('Invalid locale "' . GROCY_DEFAULT_LOCALE . '" set, locale needs to exist in folder localization');
		}
	}

	private function checkFirstDayOfWeek()
	{
		if (!(GROCY_CALENDAR_FIRST_DAY_OF_WEEK == '' ||
			(is_numeric(GROCY_CALENDAR_FIRST_DAY_OF_WEEK) && GROCY_CALENDAR_FIRST_DAY_OF_WEEK >= 0 && GROCY_CALENDAR_FIRST_DAY_OF_WEEK <= 6)))
		{
			throw new EInvalidConfig('Invalid value for CALENDAR_FIRST_DAY_OF_WEEK');
		}
	}

	private function checkCurrencyFormat()
	{
		if (!(preg_match('/^([A-z]){3}$/', GROCY_CURRENCY)))
		{
			throw new EInvalidConfig('CURRENCY is not in ISO 4217 format (three letter code)');
		}
	}

	private function checkEntryPage()
	{
		$allowedPages = ['stock', 'shoppinglist', 'recipes', 'chores', 'tasks', 'batteries', 'equipment', 'calendar', 'mealplan'];
		if (!in_array(GROCY_ENTRY_PAGE, $allowedPages))
		{
			throw new EInvalidConfig('Invalid entry page "' . GROCY_ENTRY_PAGE . '" set, only ' . implode(', ', $allowedPages) . ' allowed');
		}
	}

	private function checkMealplanFirstDayOfWeek()
	{
		if (!(GROCY_MEAL_PLAN_FIRST_DAY_OF_WEEK == '' ||
			(is_numeric(GROCY_MEAL_PLAN_FIRST_DAY_OF_WEEK) && GROCY_MEAL_PLAN_FIRST_DAY_OF_WEEK >= -1 && GROCY_MEAL_PLAN_FIRST_DAY_OF_WEEK <= 6)))
		{
			throw new EInvalidConfig('Invalid value for MEAL_PLAN_FIRST_DAY_OF_WEEK');
		}
	}

	private function checkAutoNightModeRange()
	{
		global $GROCY_DEFAULT_USER_SETTINGS;
		if (!(preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/', $GROCY_DEFAULT_USER_SETTINGS['auto_night_mode_time_range_from'])))
		{
			throw new EInvalidConfig('auto_night_mode_time_range_from is not in HH:mm format (' . $GROCY_DEFAULT_USER_SETTINGS['auto_night_mode_time_range_from'] . ')');
		}
		if (!(preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/', $GROCY_DEFAULT_USER_SETTINGS['auto_night_mode_time_range_to'])))
		{
			throw new EInvalidConfig('auto_night_mode_time_range_to is not in HH:mm format (' . $GROCY_DEFAULT_USER_SETTINGS['auto_night_mode_time_range_to'] . ')');
		}
	}
}
