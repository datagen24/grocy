<?php

namespace Grocy\Services;

/**
 * Manages API keys: creation, validation and lookup, including the special purpose
 * key used for the iCal calendar sharing URL.
 */
class ApiKeyService extends BaseService
{
	/**
	 * API key types: "default" for regular user-created keys, the special purpose
	 * type for the anonymously accessible calendar iCal export URL.
	 */
	const API_KEY_TYPE_DEFAULT = 'default';
	const API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL = 'special-purpose-calendar-ical';

	/**
	 * Creates a new random API key for the current user and returns it.
	 *
	 * Keys are practically non-expiring (expiry is set to the year 2999).
	 *
	 * @return string The newly generated API key
	 */
	public function CreateApiKey(string $keyType = self::API_KEY_TYPE_DEFAULT, ?string $description = null)
	{
		$newApiKey = $this->GenerateKey();

		$apiKeyRow = $this->DB->api_keys()->createRow([
			'api_key' => $newApiKey,
			'user_id' => GROCY_USER_ID,
			'expires' => '2999-12-31 23:59:59', // Default is that API keys never expire
			'key_type' => $keyType,
			'description' => $description
		]);
		$apiKeyRow->save();

		return $newApiKey;
	}

	/**
	 * Returns the row id of the given API key.
	 *
	 * @param string $apiKey
	 * @return int
	 */
	public function GetApiKeyId($apiKey)
	{
		$apiKey = $this->DB->api_keys()->where('api_key', $apiKey)->fetch();
		return $apiKey->id;
	}

	/**
	 * Returns any valid (unexpired) key of the given type, creating one when none
	 * exists; not allowed for key type "default" (returns null then).
	 *
	 * @param string $keyType One of the API_KEY_TYPE_* constants
	 * @return string|null
	 */
	public function GetOrCreateApiKey($keyType)
	{
		if ($keyType === self::API_KEY_TYPE_DEFAULT)
		{
			return null;
		}
		else
		{
			$apiKeyRow = $this->DB->api_keys()->where('key_type = :1 AND expires > :2', $keyType, date('Y-m-d H:i:s', time()))->fetch();

			if ($apiKeyRow !== null)
			{
				return $apiKeyRow->api_key;
			}
			else
			{
				return $this->CreateApiKey($keyType);
			}
		}
	}

	/**
	 * Returns the user row the given API key belongs to, or null for an unknown key.
	 *
	 * @param string $apiKey
	 * @return \LessQL\Row|null
	 */
	public function GetUserByApiKey($apiKey)
	{
		$apiKeyRow = $this->DB->api_keys()->where('api_key', $apiKey)->fetch();

		if ($apiKeyRow !== null)
		{
			return $this->DB->users($apiKeyRow->user_id);
		}

		return null;
	}

	/**
	 * Checks that the given key exists with the given type and is not expired, and
	 * updates its last_used timestamp on success (without advancing the db changed
	 * time, since that would make API clients refetch unchanged data).
	 *
	 * @param string|null $apiKey
	 * @param string $keyType One of the API_KEY_TYPE_* constants
	 * @return bool
	 */
	public function IsValidApiKey($apiKey, $keyType = self::API_KEY_TYPE_DEFAULT)
	{
		if ($apiKey === null || empty($apiKey))
		{
			return false;
		}
		else
		{
			$apiKeyRow = $this->DB->api_keys()->where('api_key = :1 AND expires > :2 AND key_type = :3', $apiKey, date('Y-m-d H:i:s', time()), $keyType)->fetch();

			if ($apiKeyRow !== null)
			{
				// This should not change the database file modification time as this is used
				// to determine if REALLY something has changed
				$dbModTime = DatabaseService::GetInstance()->GetDbChangedTime();
				$apiKeyRow->update([
					'last_used' => date('Y-m-d H:i:s', time())
				]);
				DatabaseService::GetInstance()->SetDbChangedTime($dbModTime);

				return true;
			}
			else
			{
				return false;
			}
		}
	}

	/**
	 * Deletes the given API key.
	 *
	 * @param string $apiKey
	 */
	public function RemoveApiKey($apiKey)
	{
		$this->DB->api_keys()->where('api_key', $apiKey)->delete();
	}

	/**
	 * Generates a random 50 character key.
	 *
	 * @return string
	 */
	private function GenerateKey()
	{
		return RandomString(50);
	}
}
