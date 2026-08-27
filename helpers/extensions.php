<?php

/**
 * Global helper functions (loaded unconditionally, no namespace):
 * array/object search utilities, type conversion and validation helpers,
 * and the Setting()/DefaultUserSetting() functions used by config-dist.php
 * and data/config.php to define GROCY_* configuration constants.
 */

/**
 * Returns the first object in $array whose $propertyName equals (==) $propertyValue.
 *
 * @return object|null The matching object or null when none matches
 */
function FindObjectInArrayByPropertyValue($array, $propertyName, $propertyValue)
{
	foreach ($array as $object)
	{
		if ($object->{$propertyName} == $propertyValue)
		{
			return $object;
		}
	}

	return null;
}

/**
 * Returns all objects in $array whose $propertyName compares to $propertyValue.
 *
 * @param string $operator Comparison operator: '==', '>' or '<' (anything else matches nothing)
 * @return array The matching objects (empty array when none matches)
 */
function FindAllObjectsInArrayByPropertyValue($array, $propertyName, $propertyValue, $operator = '==')
{
	$returnArray = [];
	foreach ($array as $object)
	{
		switch ($operator)
		{
			case '==':
				if ($object->{$propertyName} == $propertyValue)
				{
					$returnArray[] = $object;
				}
				break;
			case '>':
				if ($object->{$propertyName} > $propertyValue)
				{
					$returnArray[] = $object;
				}
				break;
			case '<':

				if ($object->{$propertyName} < $propertyValue)
				{
					$returnArray[] = $object;
				}
				break;
		}
	}

	return $returnArray;
}

/**
 * Returns all scalar items in $array which compare to $value.
 *
 * @param string $operator Comparison operator: '==', '>' or '<' (anything else matches nothing)
 * @return array The matching items (empty array when none matches)
 */
function FindAllItemsInArrayByValue($array, $value, $operator = '==')
{
	$returnArray = [];
	foreach ($array as $item)
	{
		switch ($operator)
		{
			case '==':

				if ($item == $value)
				{
					$returnArray[] = $item;
				}
				break;
			case '>':

				if ($item > $value)
				{
					$returnArray[] = $item;
				}
				break;
			case '<':

				if ($item < $value)
				{
					$returnArray[] = $item;
				}
				break;
		}
	}

	return $returnArray;
}

/**
 * Sums the given property (cast to float) over all objects in $array.
 *
 * @return float
 */
function SumArrayValue($array, $propertyName)
{
	$sum = 0;
	foreach ($array as $object)
	{
		$sum += floatval($object->{$propertyName});
	}

	return $sum;
}

/**
 * Returns the constants of the given class via reflection.
 *
 * @param string $className Fully qualified class name
 * @param string|null $prefix When given, only constants whose name starts with this prefix are returned
 * @return array Constant name => value
 */
function GetClassConstants($className, $prefix = null)
{
	$r = new ReflectionClass($className);
	$constants = $r->getConstants();

	if ($prefix === null)
	{
		return $constants;
	}
	else
	{
		$matchingKeys = preg_grep('!^' . $prefix . '!', array_keys($constants));
		return array_intersect_key($constants, array_flip($matchingKeys));
	}
}

/**
 * Generates a random string of the given length from $allowedChars
 * (default: alphanumeric); cryptographically secure (uses random_int()),
 * so the result is suitable for session keys and API keys.
 *
 * @return string
 */
function RandomString($length, $allowedChars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ')
{
	$randomString = '';
	for ($i = 0; $i < $length; $i++)
	{
		$randomString .= $allowedChars[random_int(0, strlen($allowedChars) - 1)];
	}

	return $randomString;
}

/**
 * Returns true when $array is associative (has non-sequential/string keys),
 * false for a plain indexed array.
 *
 * @return bool
 */
function IsAssociativeArray(array $array)
{
	$keys = array_keys($array);
	return array_keys($keys) !== $keys;
}

/**
 * Returns true when $dateString is a valid date in ISO format (Y-m-d).
 *
 * @return bool
 */
function IsIsoDate($dateString)
{
	$d = DateTime::createFromFormat('Y-m-d', $dateString);
	return $d && $d->format('Y-m-d') === $dateString;
}

/**
 * Returns true when $dateTimeString is a valid date/time in ISO format (Y-m-d H:i:s).
 *
 * @return bool
 */
function IsIsoDateTime($dateTimeString)
{
	$d = DateTime::createFromFormat('Y-m-d H:i:s', $dateTimeString);
	return $d && $d->format('Y-m-d H:i:s') === $dateTimeString;
}

/**
 * Converts a boolean to the string 'true' or 'false'.
 *
 * @return string
 */
function BoolToString(bool $bool)
{
	return $bool ? 'true' : 'false';
}

/**
 * Converts a boolean to 1 or 0.
 *
 * @return int
 */
function BoolToInt(bool $bool)
{
	return $bool ? 1 : 0;
}

/**
 * Normalizes a setting value coming from an external source (environment
 * variable or setting override file): trims trailing line breaks and converts
 * the strings 'true'/'false' (case insensitive) to real booleans.
 *
 * @return bool|string
 */
function ExternalSettingValue(string $value)
{
	$tvalue = rtrim($value, "\r\n");
	$lvalue = strtolower($tvalue);

	if ($lvalue === 'true')
	{
		return true;
	}
	elseif ($lvalue === 'false')
	{
		return false;
	}

	return $tvalue;
}

/**
 * Defines the configuration constant GROCY_$name with $value as default,
 * unless it is already defined or overridden by (in order of precedence)
 * a $name.txt file in GROCY_DATAPATH/settingoverrides or an environment
 * variable named GROCY_$name.
 *
 * @param string $name Setting name without the GROCY_ prefix
 * @param mixed $value Default value
 */
function Setting(string $name, $value)
{
	if (!defined('GROCY_' . $name))
	{
		// The content of a $name.txt file in /data/settingoverrides can overwrite the given setting (for embedded mode)
		$settingOverrideFile = GROCY_DATAPATH . '/settingoverrides/' . $name . '.txt';

		if (file_exists($settingOverrideFile))
		{
			define('GROCY_' . $name, ExternalSettingValue(file_get_contents($settingOverrideFile)));
		}
		elseif (getenv('GROCY_' . $name) !== false)
		{
			// An environment variable with the same name and prefix GROCY_ overwrites the given setting
			define('GROCY_' . $name, ExternalSettingValue(getenv('GROCY_' . $name)));
		}
		else
		{
			define('GROCY_' . $name, $value);
		}
	}
}

global $GROCY_DEFAULT_USER_SETTINGS;
$GROCY_DEFAULT_USER_SETTINGS = [];
/**
 * Registers the default value for a per-user setting (collected in the global
 * $GROCY_DEFAULT_USER_SETTINGS array); the first registration of a name wins.
 *
 * @param string $name User setting name
 * @param mixed $value Default value
 */
function DefaultUserSetting(string $name, $value)
{
	global $GROCY_DEFAULT_USER_SETTINGS;

	if (!array_key_exists($name, $GROCY_DEFAULT_USER_SETTINGS))
	{
		$GROCY_DEFAULT_USER_SETTINGS[$name] = $value;
	}
}

/**
 * Returns a display name for the given user row: "first last", one of the two
 * if only one is set, or the username when neither name is set.
 *
 * @param object $user A user row with first_name, last_name and username properties
 * @return string
 */
function GetUserDisplayName($user)
{
	$displayName = '';

	if (empty($user->first_name) && !empty($user->last_name))
	{
		$displayName = $user->last_name;
	}
	elseif (empty($user->last_name) && !empty($user->first_name))
	{
		$displayName = $user->first_name;
	}
	elseif (!empty($user->last_name) && !empty($user->first_name))
	{
		$displayName = $user->first_name . ' ' . $user->last_name;
	}
	else
	{
		$displayName = $user->username;
	}

	return $displayName;
}

/**
 * Returns true when $fileName is a plain "name.extension" file name without
 * path separators or other forbidden characters (/ ? * ; : { } \).
 *
 * @return bool
 */
function IsValidFileName($fileName)
{
	if (preg_match('=^[^/?*;:{}\\\\]+\.[^/?*;:{}\\\\]+$=', $fileName))
	{
		return true;
	}

	return false;
}

/**
 * Returns true when $text is valid JSON.
 *
 * @return bool
 */
function IsJsonString($text)
{
	json_decode($text);
	return (json_last_error() == JSON_ERROR_NONE);
}

/**
 * Returns true when $haystack starts with $needle.
 *
 * @return bool
 */
function string_starts_with($haystack, $needle)
{
	return (substr($haystack, 0, strlen($needle)) === $needle);
}

/**
 * Returns true when $haystack ends with $needle (an empty $needle always matches).
 *
 * @return bool
 */
function string_ends_with($haystack, $needle)
{
	$length = strlen($needle);

	if ($length == 0)
	{
		return true;
	}

	return (substr($haystack, -$length) === $needle);
}

global $GROCY_REQUIRED_FRONTEND_PACKAGES;
$GROCY_REQUIRED_FRONTEND_PACKAGES = [];
/**
 * Marks the given frontend packages (npm package names) as required for the
 * current page, so that their CSS/JS assets get included (collected in the
 * global $GROCY_REQUIRED_FRONTEND_PACKAGES array, deduplicated).
 */
function require_frontend_packages(array $packages)
{
	global $GROCY_REQUIRED_FRONTEND_PACKAGES;

	$GROCY_REQUIRED_FRONTEND_PACKAGES = array_unique(array_merge($GROCY_REQUIRED_FRONTEND_PACKAGES, $packages));
}

/**
 * Recursively deletes all files and subfolders inside $folderPath
 * (the folder itself is kept).
 */
function EmptyFolder($folderPath)
{
	foreach (glob("{$folderPath}/*") as $item)
	{
		if (is_dir($item))
		{
			EmptyFolder($item);
			rmdir($item);
		}
		else
		{
			unlink($item);
		}
	}
}
