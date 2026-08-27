<?php

namespace Grocy\Controllers\Api;

use Grocy\Controllers\Users\User;
use Grocy\Services\StockService;
use Grocy\Services\UserfieldsService;
use Grocy\Services\UsersService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the generic CRUD endpoints /api/objects/{entity}[/{objectId}] and the
 * Userfields endpoints /api/userfields/{entity}/{objectId} for all entities
 * exposed through the OpenAPI spec (ExposedEntity* enums also control which
 * entities can be listed, edited, deleted or require admin rights).
 */
class GenericEntityApiController extends BaseApiController
{
	/**
	 * POST /api/objects/{entity} - creates a new object from the JSON request body.
	 * Requires an entity-dependent permission (shopping list, recipes, meal plan,
	 * equipment or MASTER_DATA_EDIT as fallback; some entities additionally ADMIN),
	 * answered with 403 when missing. As a side effect, creating a product may add
	 * below-min-stock products to the shopping list (per user setting).
	 * Returns { "created_object_id": int|string } (200) or a 400 error response
	 * (unknown/not exposed/not editable entity or invalid body).
	 */
	public function AddObject(Request $request, Response $response, array $args)
	{
		if ($args['entity'] == 'shopping_list' || $args['entity'] == 'shopping_lists')
		{
			User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_ADD);
		}
		elseif ($args['entity'] == 'recipes' || $args['entity'] == 'recipes_pos' || $args['entity'] == 'recipes_nestings')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES);
		}
		elseif ($args['entity'] == 'meal_plan')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES_MEALPLAN);
		}
		elseif ($args['entity'] == 'equipment')
		{
			User::CheckPermission($request, User::PERMISSION_EQUIPMENT);
		}
		else
		{
			User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		}

		if ($this->IsValidExposedEntity($args['entity']) && !$this->IsEntityWithNoEdit($args['entity']))
		{
			if ($this->IsEntityWithEditRequiresAdmin($args['entity']))
			{
				User::CheckPermission($request, User::PERMISSION_ADMIN);
			}

			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			try
			{
				if ($requestBody === null)
				{
					throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
				}

				$newRow = $this->DB->{$args['entity']}()->createRow($requestBody);
				$newRow->save();
				$newObjectId = $this->DB->lastInsertId();

				// TODO: This should be better done somehow in StockService
				if ($args['entity'] == 'products' && boolval(UsersService::GetInstance()->GetUserSetting(GROCY_USER_ID, 'shopping_list_auto_add_below_min_stock_amount')))
				{
					StockService::GetInstance()->AddMissingProductsToShoppingList(UsersService::GetInstance()->GetUserSetting(GROCY_USER_ID, 'shopping_list_auto_add_below_min_stock_amount_list_id'));
				}

				return $this->ApiResponse($response, [
					'created_object_id' => $newObjectId
				]);
			}
			catch (\Exception $ex)
			{
				return $this->GenericErrorResponse($response, $ex->getMessage());
			}
		}
		else
		{
			return $this->GenericErrorResponse($response, 'Entity does not exist or is not exposed');
		}
	}

	/**
	 * DELETE /api/objects/{entity}/{objectId} - deletes the given object.
	 * Requires an entity-dependent permission (as in AddObject; api_keys are always
	 * deletable), answered with 403 when missing.
	 * Returns 204 on success or a 400 error response (invalid/undeletable entity
	 * or object not found).
	 */
	public function DeleteObject(Request $request, Response $response, array $args)
	{
		if ($args['entity'] == 'shopping_list' || $args['entity'] == 'shopping_lists')
		{
			User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_DELETE);
		}
		elseif ($args['entity'] == 'recipes' || $args['entity'] == 'recipes_pos' || $args['entity'] == 'recipes_nestings')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES);
		}
		elseif ($args['entity'] == 'meal_plan')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES_MEALPLAN);
		}
		elseif ($args['entity'] == 'equipment')
		{
			User::CheckPermission($request, User::PERMISSION_EQUIPMENT);
		}
		elseif ($args['entity'] == 'api_keys')
		{
			// Always allowed
		}
		else
		{
			User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		}

		if ($this->IsValidExposedEntity($args['entity']) && !$this->IsEntityWithNoDelete($args['entity']))
		{
			if ($this->IsEntityWithEditRequiresAdmin($args['entity']))
			{
				User::CheckPermission($request, User::PERMISSION_ADMIN);
			}

			$row = $this->DB->{$args['entity']}($args['objectId']);
			if ($row == null)
			{
				return $this->GenericErrorResponse($response, 'Object not found', 400);
			}

			$row->delete();

			return $this->EmptyApiResponse($response);
		}
		else
		{
			return $this->GenericErrorResponse($response, 'Invalid entity');
		}
	}

	/**
	 * PUT /api/objects/{entity}/{objectId} - updates the given object with the JSON
	 * request body. Requires an entity-dependent permission (as in AddObject),
	 * answered with 403 when missing. As a side effect, editing a product may add
	 * below-min-stock products to the shopping list (per user setting).
	 * Returns 204 on success or a 400 error response (invalid/not editable entity,
	 * invalid body or object not found).
	 */
	public function EditObject(Request $request, Response $response, array $args)
	{
		if ($args['entity'] == 'shopping_list' || $args['entity'] == 'shopping_lists')
		{
			User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_ADD);
		}
		elseif ($args['entity'] == 'recipes' || $args['entity'] == 'recipes_pos' || $args['entity'] == 'recipes_nestings')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES);
		}
		elseif ($args['entity'] == 'meal_plan')
		{
			User::CheckPermission($request, User::PERMISSION_RECIPES_MEALPLAN);
		}
		elseif ($args['entity'] == 'equipment')
		{
			User::CheckPermission($request, User::PERMISSION_EQUIPMENT);
		}
		else
		{
			User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);
		}

		if ($this->IsValidExposedEntity($args['entity']) && !$this->IsEntityWithNoEdit($args['entity']))
		{
			if ($this->IsEntityWithEditRequiresAdmin($args['entity']))
			{
				User::CheckPermission($request, User::PERMISSION_ADMIN);
			}

			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			try
			{
				if ($requestBody === null)
				{
					throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
				}

				$row = $this->DB->{$args['entity']}($args['objectId']);
				if ($row == null)
				{
					return $this->GenericErrorResponse($response, 'Object not found', 400);
				}

				$row->update($requestBody);

				// TODO: This should be better done somehow in StockService
				if ($args['entity'] == 'products' && boolval(UsersService::GetInstance()->GetUserSetting(GROCY_USER_ID, 'shopping_list_auto_add_below_min_stock_amount')))
				{
					StockService::GetInstance()->AddMissingProductsToShoppingList(UsersService::GetInstance()->GetUserSetting(GROCY_USER_ID, 'shopping_list_auto_add_below_min_stock_amount_list_id'));
				}

				return $this->EmptyApiResponse($response);
			}
			catch (\Exception $ex)
			{
				return $this->GenericErrorResponse($response, $ex->getMessage());
			}
		}
		else
		{
			return $this->GenericErrorResponse($response, 'Entity does not exist or is not exposed');
		}
	}

	/**
	 * GET /api/objects/{entity}/{objectId} - returns a single object including its
	 * Userfield values under the "userfields" key (null when none exist).
	 * Returns 400 for an unknown/not listable entity and 404 when the object does not exist.
	 */
	public function GetObject(Request $request, Response $response, array $args)
	{
		if (!$this->IsValidExposedEntity($args['entity']) || $this->IsEntityWithNoListing($args['entity']))
		{
			return $this->GenericErrorResponse($response, 'Entity does not exist or is not exposed');
		}

		$object = $this->DB->{$args['entity']}($args['objectId']);
		if ($object == null)
		{
			return $this->GenericErrorResponse($response, 'Object not found', 404);
		}

		// TODO: Handle this somehow more generically
		$referencingId = $args['objectId'];
		if ($args['entity'] == 'stock')
		{
			$referencingId = $object->stock_id;
		}
		$userfields = UserfieldsService::GetInstance()->GetValues($args['entity'], $referencingId);
		if (count($userfields) === 0)
		{
			$userfields = null;
		}
		$object['userfields'] = $userfields;

		return $this->ApiResponse($response, $object);
	}

	/**
	 * GET /api/objects/{entity} - lists all objects of the given entity, filterable via
	 * the generic query/limit/offset/order query parameters; when Userfields exist for
	 * the entity, each object gets a "userfields" key/value map attached.
	 * Returns 400 for an unknown or not listable entity.
	 */
	public function GetObjects(Request $request, Response $response, array $args)
	{
		if (!$this->IsValidExposedEntity($args['entity']) || $this->IsEntityWithNoListing($args['entity']))
		{
			return $this->GenericErrorResponse($response, 'Entity does not exist or is not exposed');
		}

		$objects = $this->QueryData($this->DB->{$args['entity']}(), $request->getQueryParams());

		$userfields = UserfieldsService::GetInstance()->GetFields($args['entity']);
		if (count($userfields) > 0)
		{
			$allUserfieldValues = UserfieldsService::GetInstance()->GetAllValues($args['entity']);

			foreach ($objects as $object)
			{
				$userfieldKeyValuePairs = null;
				foreach ($userfields as $userfield)
				{
					// TODO: Handle this somehow more generically
					$userfieldReference = 'id';
					if ($args['entity'] == 'stock')
					{
						$userfieldReference = 'stock_id';
					}

					$value = FindObjectInArrayByPropertyValue(FindAllObjectsInArrayByPropertyValue($allUserfieldValues, 'object_id', $object->{$userfieldReference}), 'name', $userfield->name);
					if ($value)
					{
						$userfieldKeyValuePairs[$userfield->name] = $value->value;
					}
					else
					{
						$userfieldKeyValuePairs[$userfield->name] = null;
					}
				}

				$object->userfields = $userfieldKeyValuePairs;
			}
		}

		return $this->ApiResponse($response, $objects);
	}

	/**
	 * GET /api/userfields/{entity}/{objectId} - returns the Userfield values of the
	 * given object as a key/value map (200) or a 400 error response.
	 */
	public function GetUserfields(Request $request, Response $response, array $args)
	{
		try
		{
			return $this->ApiResponse($response, UserfieldsService::GetInstance()->GetValues($args['entity'], $args['objectId']));
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * PUT /api/userfields/{entity}/{objectId} - sets the Userfield values of the given
	 * object from the JSON request body (a key/value map).
	 * Requires the MASTER_DATA_EDIT permission (403 otherwise).
	 * Returns 204 on success or a 400 error response.
	 */
	public function SetUserfields(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		try
		{
			if ($requestBody === null)
			{
				throw new \Exception('Request body could not be parsed (probably invalid JSON format or missing/wrong Content-Type header)');
			}

			UserfieldsService::GetInstance()->SetValues($args['entity'], $args['objectId'], $requestBody);
			return $this->EmptyApiResponse($response);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * Whether editing the given entity additionally requires the ADMIN permission (per OpenAPI spec enum).
	 */
	private function IsEntityWithEditRequiresAdmin($entity)
	{
		return in_array($entity, $this->GetOpenApispec()->components->schemas->ExposedEntityEditRequiresAdmin->enum);
	}

	/**
	 * Whether the given entity is excluded from listing/reading (per OpenAPI spec enum).
	 */
	private function IsEntityWithNoListing($entity)
	{
		return in_array($entity, $this->GetOpenApispec()->components->schemas->ExposedEntityNoListing->enum);
	}

	/**
	 * Whether the given entity cannot be created/edited through this API (per OpenAPI spec enum).
	 */
	private function IsEntityWithNoEdit($entity)
	{
		return in_array($entity, $this->GetOpenApispec()->components->schemas->ExposedEntityNoEdit->enum);
	}

	/**
	 * Whether the given entity cannot be deleted through this API (per OpenAPI spec enum).
	 */
	private function IsEntityWithNoDelete($entity)
	{
		return in_array($entity, $this->GetOpenApispec()->components->schemas->ExposedEntityNoDelete->enum);
	}

	/**
	 * Whether the given entity is exposed through this API at all (per OpenAPI spec enum).
	 */
	private function IsValidExposedEntity($entity)
	{
		return in_array($entity, $this->GetOpenApispec()->components->schemas->ExposedEntity->enum);
	}
}
