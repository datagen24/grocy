<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Helpers\Grocycode;
use Victual\Helpers\WebhookRunner;
use Victual\Services\RecipesService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the /api/recipes endpoints: stock fulfillment info, consuming a recipe,
 * adding missing ingredients to the shopping list, copying and label printing.
 */
class RecipesApiController extends BaseApiController
{
	/**
	 * POST /api/recipes/{recipeId}/add-not-fulfilled-products-to-shoppinglist - puts all
	 * ingredients not currently in stock onto the shopping list; the optional body field
	 * excludedProductIds (array of product ids) skips the given products.
	 * Requires the SHOPPINGLIST_ITEMS_ADD permission (403 otherwise). Returns 204;
	 * service errors are not caught here and surface via the Slim error handler.
	 */
	public function AddNotFulfilledProductsToShoppingList(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_SHOPPINGLIST_ITEMS_ADD);

		$requestBody = $this->GetParsedAndFilteredRequestBody($request);
		$excludedProductIds = null;

		if ($requestBody !== null && array_key_exists('excludedProductIds', $requestBody))
		{
			$excludedProductIds = $requestBody['excludedProductIds'];
		}

		RecipesService::GetInstance()->AddNotFulfilledProductsToShoppingList($args['recipeId'], $excludedProductIds);
		return $this->EmptyApiResponse($response);
	}

	/**
	 * POST /api/recipes/{recipeId}/consume - consumes all ingredients of the recipe
	 * from stock. Requires the STOCK_CONSUME permission (403 otherwise).
	 * Returns 204 on success or a 400 error response.
	 */
	public function ConsumeRecipe(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_STOCK_CONSUME);

		try
		{
			RecipesService::GetInstance()->ConsumeRecipe($args['recipeId']);
			return $this->EmptyApiResponse($response);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * GET /api/recipes/fulfillment and GET /api/recipes/{recipeId}/fulfillment - returns
	 * stock fulfillment information for all recipes (filterable via the generic
	 * query/limit/offset/order query parameters) or for a single recipe.
	 * Returns a 400 error response when the given recipe does not exist.
	 */
	public function GetRecipeFulfillment(Request $request, Response $response, array $args)
	{
		try
		{
			if (!isset($args['recipeId']))
			{
				return $this->FilteredApiResponse($request, $response, RecipesService::GetInstance()->GetRecipesResolved(), $request->getQueryParams());
			}

			$recipeResolved = FindObjectInArrayByPropertyValue(RecipesService::GetInstance()->GetRecipesResolved(), 'recipe_id', $args['recipeId']);

			if (!$recipeResolved)
			{
				throw new \Exception('Recipe does not exist');
			}
			else
			{
				return $this->ApiResponse($response, $recipeResolved);
			}
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * POST /api/recipes/{recipeId}/copy - copies the given recipe and returns
	 * { "created_object_id": int } (200) or a 400 error response.
	 */
	public function CopyRecipe(Request $request, Response $response, array $args)
	{
		try
		{
			return $this->ApiResponse($response, [
				'created_object_id' => RecipesService::GetInstance()->CopyRecipe($args['recipeId'])
			]);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * GET /api/recipes/{recipeId}/printlabel - assembles the label printer webhook payload
	 * (recipe name, Grocycode, recipe row plus VICTUAL_LABEL_PRINTER_PARAMS), runs the webhook
	 * server-side when VICTUAL_LABEL_PRINTER_RUN_SERVER is enabled and returns the payload (200)
	 * or a 400 error response.
	 */
	public function RecipePrintLabel(Request $request, Response $response, array $args)
	{
		try
		{
			$recipe = $this->GetDb()->recipes()->where('id', $args['recipeId'])->fetch();

			$webhookData = array_merge([
				'recipe' => $recipe->name,
				'grocycode' => (string)(new Grocycode(Grocycode::RECIPE, $args['recipeId'])),
				'details' => $recipe
			], VICTUAL_LABEL_PRINTER_PARAMS);

			if (VICTUAL_LABEL_PRINTER_RUN_SERVER)
			{
				(new WebhookRunner())->run(VICTUAL_LABEL_PRINTER_WEBHOOK, $webhookData, VICTUAL_LABEL_PRINTER_HOOK_JSON);
			}

			return $this->ApiResponse($response, $webhookData);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}
}
