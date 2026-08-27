<?php

namespace Grocy\Controllers\Api;

use Grocy\Controllers\Users\User;
use Grocy\Services\UsersService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the /api/users endpoints (user management and permission assignment)
 * and the /api/user endpoints (currently authenticated user and its settings).
 */
class UsersApiController extends BaseApiController
{
	/**
	 * POST /api/users/{userId}/permissions - assigns the permission given by the body
	 * field permission_id to the user. Requires the ADMIN permission (returned as a
	 * JSON error response with the exception's status code, i.e. 403).
	 * Returns 204 on success or a 400 error response.
	 */
	public function AddPermission(Request $request, Response $response, array $args)
	{
		try
		{
			User::CheckPermission($request, User::PERMISSION_ADMIN);
			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			$this->DB->user_permissions()->createRow([
				'user_id' => $args['userId'],
				'permission_id' => $requestBody['permission_id']
			])->save();
			return $this->EmptyApiResponse($response);
		}
		catch (\Slim\Exception\HttpSpecializedException $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), $ex->getCode());
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * POST /api/users - creates a new user from the body fields username, first_name,
	 * last_name, password (alternatively password_base64, which is decoded into
	 * password) and picture_file_name. Requires the USERS_CREATE permission (403
	 * otherwise). Returns 204 on success or a 400 error response.
	 */
	public function CreateUser(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_CREATE);
		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		try
		{
			if ($requestBody === null)
			{
				throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
			}

			if (isset($requestBody['password_base64']))
			{
				$requestBody['password'] = base64_decode($requestBody['password_base64']);
			}
			unset($requestBody['password_base64']);

			UsersService::GetInstance()->CreateUser($requestBody['username'], $requestBody['first_name'], $requestBody['last_name'], $requestBody['password'], $requestBody['picture_file_name']);
			return $this->EmptyApiResponse($response);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * DELETE /api/users/{userId} - deletes the given user.
	 * Requires the USERS_EDIT permission (403 otherwise).
	 * Returns 204 on success or a 400 error response.
	 */
	public function DeleteUser(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_EDIT);
		try
		{
			UsersService::GetInstance()->DeleteUser($args['userId']);
			return $this->EmptyApiResponse($response);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * PUT /api/users/{userId} - updates the given user with the body fields username,
	 * first_name, last_name, password (alternatively password_base64) and
	 * picture_file_name. Requires USERS_EDIT_SELF when editing the own account,
	 * USERS_EDIT otherwise (403 when missing).
	 * Returns 204 on success or a 400 error response.
	 */
	public function EditUser(Request $request, Response $response, array $args)
	{
		if ($args['userId'] == GROCY_USER_ID)
		{
			User::CheckPermission($request, User::PERMISSION_USERS_EDIT_SELF);
		}
		else
		{
			User::CheckPermission($request, User::PERMISSION_USERS_EDIT);
		}

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		try
		{
			if (isset($requestBody['password_base64']))
			{
				$requestBody['password'] = base64_decode($requestBody['password_base64']);
			}
			unset($requestBody['password_base64']);

			UsersService::GetInstance()->EditUser($args['userId'], $requestBody['username'], $requestBody['first_name'], $requestBody['last_name'], $requestBody['password'], $requestBody['picture_file_name']);
			return $this->EmptyApiResponse($response);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * GET /api/user/settings/{settingKey} - returns { "value": mixed } for the given
	 * setting of the current user (200) or a 400 error response.
	 */
	public function GetUserSetting(Request $request, Response $response, array $args)
	{
		try
		{
			$value = UsersService::GetInstance()->GetUserSetting(GROCY_USER_ID, $args['settingKey']);
			return $this->ApiResponse($response, ['value' => $value]);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * GET /api/user/settings - returns all settings of the current user as a key/value
	 * map (200) or a 400 error response.
	 */
	public function GetUserSettings(Request $request, Response $response, array $args)
	{
		try
		{
			return $this->ApiResponse($response, UsersService::GetInstance()->GetUserSettings(GROCY_USER_ID));
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * GET /api/users - returns all users as DTOs (without password hashes), filterable
	 * via the generic query/limit/offset/order query parameters.
	 * Requires the USERS_READ permission (403 otherwise); 400 error response on failure.
	 */
	public function GetUsers(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_USERS_READ);
		try
		{
			return $this->FilteredApiResponse($response, UsersService::GetInstance()->GetUsersAsDto(), $request->getQueryParams());
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * GET /api/user - returns the currently authenticated user as a single-element
	 * DTO list (200) or a 400 error response.
	 */
	public function CurrentUser(Request $request, Response $response, array $args)
	{
		try
		{
			return $this->ApiResponse($response, UsersService::GetInstance()->GetUsersAsDto()->where('id', GROCY_USER_ID));
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * GET /api/users/{userId}/permissions - returns the user_permissions rows assigned
	 * to the given user. Requires the ADMIN permission (returned as a JSON error
	 * response with the exception's status code, i.e. 403); 400 on other errors.
	 */
	public function ListPermissions(Request $request, Response $response, array $args)
	{
		try
		{
			User::CheckPermission($request, User::PERMISSION_ADMIN);

			return $this->ApiResponse(
				$response,
				$this->DB->user_permissions()->where('user_id', $args['userId'])
			);
		}
		catch (\Slim\Exception\HttpSpecializedException $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), $ex->getCode());
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * PUT /api/users/{userId}/permissions - replaces all permission assignments of the
	 * given user with the body field permissions (array of permission ids); in demo or
	 * prerelease mode the user is always given the ADMIN permission instead.
	 * Requires the ADMIN permission (returned as a JSON error response with the
	 * exception's status code, i.e. 403). Returns 204 on success or a 400 error response.
	 */
	public function SetPermissions(Request $request, Response $response, array $args)
	{
		try
		{
			User::CheckPermission($request, User::PERMISSION_ADMIN);

			$requestBody = $request->getParsedBody();
			$db = $this->DB;
			$db->user_permissions()
				->where('user_id', $args['userId'])
				->delete();

			$perms = [];
			if (GROCY_MODE === 'demo' || GROCY_MODE === 'prerelease')
			{
				// For demo mode always all users have and keep the ADMIN permission
				$perms[] = [
					'user_id' => $args['userId'],
					'permission_id' => 1
				];
			}
			else
			{
				foreach ($requestBody['permissions'] as $perm_id)
				{
					$perms[] = [
						'user_id' => $args['userId'],
						'permission_id' => $perm_id
					];
				}
			}
			$db->insert('user_permissions', $perms, 'batch');

			return $this->EmptyApiResponse($response);
		}
		catch (\Slim\Exception\HttpSpecializedException $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), $ex->getCode());
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * PUT /api/user/settings/{settingKey} - stores the body field "value" as the given
	 * setting of the current user. Returns 204 on success or a 400 error response.
	 */
	public function SetUserSetting(Request $request, Response $response, array $args)
	{
		try
		{
			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			$value = UsersService::GetInstance()->SetUserSetting(GROCY_USER_ID, $args['settingKey'], $requestBody['value']);
			return $this->EmptyApiResponse($response);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * DELETE /api/user/settings/{settingKey} - deletes the given setting of the current
	 * user. Returns 204 on success or a 400 error response.
	 */
	public function DeleteUserSetting(Request $request, Response $response, array $args)
	{
		try
		{
			$value = UsersService::GetInstance()->DeleteUserSetting(GROCY_USER_ID, $args['settingKey']);
			return $this->EmptyApiResponse($response);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}
}
