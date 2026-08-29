<?php

namespace Victual\Controllers\Users;

use Victual\Services\DatabaseService;
use LessQL\Result;

/**
 * Permission helper for the currently authenticated user (VICTUAL_USER_ID):
 * defines all known permission names as PERMISSION_* constants and offers
 * static checks against the resolved permissions in the database.
 */
class User
{
	const PERMISSION_ADMIN = 'ADMIN';
	const PERMISSION_BATTERIES = 'BATTERIES';
	const PERMISSION_BATTERIES_TRACK_CHARGE_CYCLE = 'BATTERIES_TRACK_CHARGE_CYCLE';
	const PERMISSION_BATTERIES_UNDO_CHARGE_CYCLE = 'BATTERIES_UNDO_CHARGE_CYCLE';
	const PERMISSION_CALENDAR = 'CALENDAR';
	const PERMISSION_CHORES = 'CHORES';
	const PERMISSION_CHORE_TRACK_EXECUTION = 'CHORE_TRACK_EXECUTION';
	const PERMISSION_CHORE_UNDO_EXECUTION = 'CHORE_UNDO_EXECUTION';
	const PERMISSION_EQUIPMENT = 'EQUIPMENT';
	const PERMISSION_MASTER_DATA_EDIT = 'MASTER_DATA_EDIT';
	const PERMISSION_RECIPES = 'RECIPES';
	const PERMISSION_RECIPES_MEALPLAN = 'RECIPES_MEALPLAN';
	const PERMISSION_SHOPPINGLIST = 'SHOPPINGLIST';
	const PERMISSION_SHOPPINGLIST_ITEMS_ADD = 'SHOPPINGLIST_ITEMS_ADD';
	const PERMISSION_SHOPPINGLIST_ITEMS_DELETE = 'SHOPPINGLIST_ITEMS_DELETE';
	const PERMISSION_STOCK = 'STOCK';
	const PERMISSION_STOCK_CONSUME = 'STOCK_CONSUME';
	const PERMISSION_STOCK_EDIT = 'STOCK_EDIT';
	const PERMISSION_STOCK_INVENTORY = 'STOCK_INVENTORY';
	const PERMISSION_STOCK_OPEN = 'STOCK_OPEN';
	const PERMISSION_STOCK_PURCHASE = 'STOCK_PURCHASE';
	const PERMISSION_STOCK_TRANSFER = 'STOCK_TRANSFER';
	const PERMISSION_TASKS = 'TASKS';
	const PERMISSION_TASKS_MARK_COMPLETED = 'TASKS_MARK_COMPLETED';
	const PERMISSION_TASKS_UNDO_EXECUTION = 'TASKS_UNDO_EXECUTION';
	const PERMISSION_USERS = 'USERS';
	const PERMISSION_USERS_CREATE = 'USERS_CREATE';
	const PERMISSION_USERS_EDIT = 'USERS_EDIT';
	const PERMISSION_USERS_EDIT_SELF = 'USERS_EDIT_SELF';
	const PERMISSION_USERS_READ = 'USERS_READ';

	/**
	 * Grabs the shared database connection.
	 */
	public function __construct()
	{
		$this->DB = DatabaseService::GetInstance()->GetDbConnection();
	}

	/** @var \LessQL\Database Fluent database connection */
	protected $DB;

	/**
	 * Static convenience wrapper around GetPermissionList().
	 *
	 * @return Result The current user's rows from the uihelper_user_permissions view
	 */
	public static function PermissionList()
	{
		$user = new self();
		return $user->GetPermissionList();
	}

	/**
	 * Asserts that the current user has the given permission.
	 *
	 * @param \Psr\Http\Message\ServerRequestInterface $request The current request (needed to construct the exception)
	 * @param string $permission One of the PERMISSION_* constants
	 * @throws PermissionMissingException When the permission is not granted
	 */
	public static function CheckPermission($request, string $permission): void
	{
		$user = new self();
		if (!$user->HasPermission($permission))
		{
			throw new PermissionMissingException($request, $permission);
		}
	}

	/**
	 * Returns the current user's permission rows from the uihelper_user_permissions
	 * view (used e.g. to expose permissions to the templates).
	 *
	 * @return Result
	 */
	public function GetPermissionList()
	{
		return $this->DB->uihelper_user_permissions()->where('user_id', VICTUAL_USER_ID);
	}

	/**
	 * Whether the current user has the given permission (resolved, i.e. including
	 * permissions inherited via ADMIN/parent permissions).
	 *
	 * @param string $permission One of the PERMISSION_* constants
	 */
	public function HasPermission(string $permission): bool
	{
		return $this->GetPermissions()->where('permission_name', $permission)->fetch() !== null;
	}

	/**
	 * Whether the current user has ALL of the given permissions.
	 *
	 * @param string ...$permissions PERMISSION_* constants
	 * @return bool
	 */
	public static function HasPermissions(string ...$permissions)
	{
		$user = new self();

		foreach ($permissions as $permission)
		{
			if (!$user->HasPermission($permission))
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Returns the current user's resolved permissions (user_permissions_resolved view).
	 */
	protected function GetPermissions(): Result
	{
		return $this->DB->user_permissions_resolved()->where('user_id', VICTUAL_USER_ID);
	}
}
