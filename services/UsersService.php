<?php

namespace Grocy\Services;

use LessQL\Result;

/**
 * User account management and per-user settings. Settings are cached per request; the
 * cache is shared with the SQL-side victual_user_setting() helper on SQLite, which resolves
 * settings through GetUserSetting().
 */
class UsersService extends BaseService
{
	/**
	 * Creates a user (password hashed with Argon2id) and grants the permission set
	 * configured as VICTUAL_DEFAULT_PERMISSIONS.
	 *
	 * @return \LessQL\Row The new users row
	 */
	public function CreateUser(string $username, ?string $firstName, ?string $lastName, string $password, ?string $pictureFileName = null)
	{
		$newUserRow = $this->DB->users()->createRow([
			'username' => $username,
			'first_name' => $firstName,
			'last_name' => $lastName,
			'password' => password_hash($password, PASSWORD_ARGON2ID),
			'picture_file_name' => $pictureFileName
		]);
		$newUserRow = $newUserRow->save();
		$permList = [];

		foreach ($this->DB->permission_hierarchy()->where('name', VICTUAL_DEFAULT_PERMISSIONS)->fetchAll() as $perm)
		{
			$permList[] = [
				'user_id' => $newUserRow->id,
				'permission_id' => $perm->id
			];
		}

		$this->DB->user_permissions()->insert($permList);

		return $newUserRow;
	}

	/**
	 * Deletes the users row (only that row - related rows like sessions or
	 * permissions are not cleaned up here).
	 */
	public function DeleteUser($userId)
	{
		$row = $this->DB->users($userId);
		$row->delete();
	}

	/**
	 * Updates a user's profile; the password is only changed (re-hashed with Argon2id)
	 * when a non-empty one is given.
	 *
	 * @throws \Exception When the user does not exist
	 */
	public function EditUser(int $userId, string $username, string $firstName, string $lastName, ?string $password, ?string $pictureFileName = null)
	{
		if (!$this->UserExists($userId))
		{
			throw new \Exception('User does not exist');
		}

		$user = $this->DB->users($userId);

		if ($password == null || empty($password))
		{
			$user->update([
				'username' => $username,
				'first_name' => $firstName,
				'last_name' => $lastName,
				'picture_file_name' => $pictureFileName
			]);
		}
		else
		{
			$user->update([
				'username' => $username,
				'first_name' => $firstName,
				'last_name' => $lastName,
				'password' => password_hash($password, PASSWORD_ARGON2ID),
				'picture_file_name' => $pictureFileName
			]);
		}
	}

	/** @var array<int|string, array<string, mixed>> Per-request settings cache: [user id => [key => value]] */
	private static $UserSettingsCache = [];

	/**
	 * One setting value for the user, falling back to the $VICTUAL_DEFAULT_USER_SETTINGS
	 * default and finally null. Cached per request.
	 *
	 * @param int $userId
	 * @param string $settingKey
	 * @return mixed
	 */
	public function GetUserSetting($userId, $settingKey)
	{
		if (!array_key_exists($userId, self::$UserSettingsCache))
		{
			self::$UserSettingsCache[$userId] = [];
		}

		if (array_key_exists($settingKey, self::$UserSettingsCache[$userId]))
		{
			return self::$UserSettingsCache[$userId][$settingKey];
		}

		$value = null;
		$settingRow = $this->DB->user_settings()->where('user_id = :1 AND key = :2', $userId, $settingKey)->fetch();
		if ($settingRow !== null)
		{
			$value = $settingRow->value;
		}
		else
		{
			// Use the configured default values for a missing setting, otherwise return NULL
			global $VICTUAL_DEFAULT_USER_SETTINGS;
			if (array_key_exists($settingKey, $VICTUAL_DEFAULT_USER_SETTINGS))
			{
				$value = $VICTUAL_DEFAULT_USER_SETTINGS[$settingKey];
			}
		}

		self::$UserSettingsCache[$userId][$settingKey] = $value;
		return $value;
	}

	/**
	 * All settings of the user as [key => value], with $VICTUAL_DEFAULT_USER_SETTINGS
	 * filling in every key the user has not overridden.
	 *
	 * @param int $userId
	 * @return array<string, mixed>
	 */
	public function GetUserSettings($userId)
	{
		$settings = [];
		$settingRows = $this->DB->user_settings()->where('user_id = :1', $userId)->fetchAll();
		foreach ($settingRows as $settingRow)
		{
			$settings[$settingRow->key] = $settingRow->value;
		}

		// Use the configured default values for all missing settings
		global $VICTUAL_DEFAULT_USER_SETTINGS;
		return array_merge($VICTUAL_DEFAULT_USER_SETTINGS, $settings);
	}

	/**
	 * All users via the users_dto view, i.e. without password hashes and with
	 * display_name precomputed.
	 */
	public function GetUsersAsDto(): Result
	{
		return $this->DB->users_dto();
	}

	/**
	 * Inserts or updates one setting for the user and refreshes the request cache.
	 */
	public function SetUserSetting($userId, $settingKey, $settingValue)
	{
		if (!array_key_exists($userId, self::$UserSettingsCache))
		{
			self::$UserSettingsCache[$userId] = [];
		}
		self::$UserSettingsCache[$userId][$settingKey] = $settingValue;

		$settingRow = $this->DB->user_settings()->where('user_id = :1 AND key = :2', $userId, $settingKey)->fetch();
		if ($settingRow !== null)
		{
			$settingRow->update([
				'value' => $settingValue,
				'row_updated_timestamp' => date('Y-m-d H:i:s')
			]);
		}
		else
		{
			$settingRow = $this->DB->user_settings()->createRow([
				'user_id' => $userId,
				'key' => $settingKey,
				'value' => $settingValue
			]);
			$settingRow->save();
		}
	}

	/**
	 * Removes one stored setting for the user (reverting it to the configured default)
	 * and evicts it from the request cache.
	 */
	public function DeleteUserSetting($userId, $settingKey)
	{
		if (!array_key_exists($userId, self::$UserSettingsCache))
		{
			self::$UserSettingsCache[$userId] = [];
		}
		unset(self::$UserSettingsCache[$userId][$settingKey]);

		$this->DB->user_settings()->where('user_id = :1 AND key = :2', $userId, $settingKey)->delete();
	}

	private function UserExists($userId)
	{
		$userRow = $this->DB->users()->where('id = :1', $userId)->fetch();
		return $userRow !== null;
	}
}
