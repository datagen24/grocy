<?php

namespace Victual\Helpers;

use Victual\Services\Storage\FileSizeLimit;

/**
 * Thrown when a VICTUAL_* configuration constant has an invalid value.
 */
class EInvalidConfig extends \Exception
{
}

/**
 * Validates the VICTUAL_* configuration constants (mode, database driver, file storage,
 * locale, currency, entry page, week start days, auto night mode range, and the MQTT
 * and InfluxDB outbound targets) on application startup.
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
		self::checkFileStorage();
		self::checkDefaultLocale();
		self::checkCurrencyFormat();
		self::checkFirstDayOfWeek();
		self::checkEntryPage();
		self::checkMealplanFirstDayOfWeek();
		self::checkAutoNightModeRange();
		self::checkMqttSettings();
		self::checkInfluxDbSettings();
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

	/**
	 * FILE_STORAGE, the two combinations it may not be in, and the upload size limit.
	 *
	 * Both are refused here rather than at the first upload, which is the point: a
	 * household that flips the setting finds out at startup, when it can still change its
	 * mind, instead of when someone tries to attach a picture.
	 *
	 * "database" needs PostgreSQL because the backend is BYTEA, bound as a LOB through raw
	 * PDO; there is deliberately no SQLite BLOB counterpart (plan 01, and ADR-0008 makes
	 * PostgreSQL the only runtime engine anyway).
	 *
	 * It is refused in demo/prerelease mode because FilesystemStorage gives each demo
	 * instance its own storage sub folder, and the files table's UNIQUE(file_group, name)
	 * has no column for that suffix - two demo instances sharing a database would collide
	 * on it. The demo path already provisions a database per instance, so scoping it out
	 * costs nothing (plan 01 Q4).
	 */
	private function checkFileStorage()
	{
		$allowedStorages = ['filesystem', 'database'];
		if (!in_array(VICTUAL_FILE_STORAGE, $allowedStorages))
		{
			throw new EInvalidConfig('Invalid file storage "' . VICTUAL_FILE_STORAGE . '" set, only ' . implode(', ', $allowedStorages) . ' allowed');
		}

		if (VICTUAL_FILE_STORAGE === 'database')
		{
			if (strtolower(VICTUAL_DB_DRIVER) !== 'pgsql')
			{
				throw new EInvalidConfig('FILE_STORAGE "database" requires DB_DRIVER "pgsql", but DB_DRIVER is "' . VICTUAL_DB_DRIVER . '" - either switch the database driver or set FILE_STORAGE back to "filesystem"');
			}

			if (VICTUAL_MODE === 'demo' || VICTUAL_MODE === 'prerelease')
			{
				throw new EInvalidConfig('FILE_STORAGE "database" is not supported in "' . VICTUAL_MODE . '" mode, because demo instances share a storage location by file name suffix and the files table has no column for it - use FILE_STORAGE "filesystem" here');
			}
		}

		if (!is_numeric(VICTUAL_FILE_STORAGE_MAX_SIZE_MB) || VICTUAL_FILE_STORAGE_MAX_SIZE_MB <= 0)
		{
			throw new EInvalidConfig('FILE_STORAGE_MAX_SIZE_MB must be a positive number of megabytes, "' . VICTUAL_FILE_STORAGE_MAX_SIZE_MB . '" given');
		}

		// Resolving the limit here rather than at the first upload is what makes it a
		// startup fact: a FILE_STORAGE_MAX_SIZE_MB larger than what PHP will accept logs
		// its clamp while someone is still looking at the boot output, instead of the
		// first time a household member is refused a picture. Startup keeps running
		// either way - a clamp is information, not a failure (plan 01 Q2).
		FileSizeLimit::EffectiveMaxBytes();
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

	/**
	 * MQTT is off by default, so the only way to get this wrong is to turn it on. Both
	 * checks catch a misconfiguration that would otherwise be silent: a publish path
	 * swallows every failure by design (a broker must never break a write), so an empty
	 * host would show up as nothing being published and no error anywhere.
	 */
	private function checkMqttSettings()
	{
		if (!VICTUAL_MQTT_ENABLED)
		{
			return;
		}

		if (empty(trim(VICTUAL_MQTT_HOST)))
		{
			throw new EInvalidConfig('MQTT_HOST needs to be set when MQTT_ENABLED is true');
		}

		$allowedDiscoveryModes = ['device', 'entity'];
		if (!in_array(VICTUAL_MQTT_DISCOVERY_MODE, $allowedDiscoveryModes))
		{
			throw new EInvalidConfig('Invalid MQTT_DISCOVERY_MODE "' . VICTUAL_MQTT_DISCOVERY_MODE . '" set, only ' . implode(', ', $allowedDiscoveryModes) . ' allowed');
		}

		// The library rejects anything below 1 second outright, and this timeout is what
		// bounds how long an unreachable broker delays a committed write
		if (!is_numeric(VICTUAL_MQTT_CONNECT_TIMEOUT_SECONDS) || (int)VICTUAL_MQTT_CONNECT_TIMEOUT_SECONDS < 1)
		{
			throw new EInvalidConfig('MQTT_CONNECT_TIMEOUT_SECONDS needs to be a whole number of seconds, at least 1');
		}
	}

	/**
	 * Same shape as checkMqttSettings(), same reason: the write path swallows its own
	 * failures so that a metrics server can never break a committed booking, which means a
	 * misconfiguration would otherwise be entirely silent.
	 */
	private function checkInfluxDbSettings()
	{
		if (!VICTUAL_INFLUXDB_ENABLED)
		{
			return;
		}

		if (empty(trim(VICTUAL_INFLUXDB_URL)))
		{
			throw new EInvalidConfig('INFLUXDB_URL needs to be set when INFLUXDB_ENABLED is true');
		}

		if (empty(trim(VICTUAL_INFLUXDB_BUCKET)) || empty(trim(VICTUAL_INFLUXDB_ORG)))
		{
			throw new EInvalidConfig('INFLUXDB_ORG and INFLUXDB_BUCKET need to be set when INFLUXDB_ENABLED is true');
		}

		if (!is_numeric(VICTUAL_INFLUXDB_TIMEOUT_SECONDS) || (int)VICTUAL_INFLUXDB_TIMEOUT_SECONDS < 1)
		{
			throw new EInvalidConfig('INFLUXDB_TIMEOUT_SECONDS needs to be a whole number of seconds, at least 1');
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
