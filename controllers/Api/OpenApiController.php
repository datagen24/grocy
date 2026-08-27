<?php

namespace Grocy\Controllers\Api;

use Grocy\Controllers\Users\User;
use Grocy\Services\ApiKeyService;
use Grocy\Services\ApplicationService;
use Grocy\Services\UserfieldsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the OpenAPI documentation endpoints (/api and /api/openapi/specification)
 * and the API key management pages (/manageapikeys) - a mix of API and view routes.
 */
class OpenApiController extends BaseApiController
{
	/**
	 * GET /manageapikeys - renders the API key management page; non-admins only see
	 * their own keys. The optional integer query parameter "key" preselects a key
	 * (used to highlight a freshly created one).
	 */
	public function ApiKeysList(Request $request, Response $response, array $args)
	{
		$selectedKeyId = -1;
		if (isset($request->getQueryParams()['key']) && filter_var($request->getQueryParams()['key'], FILTER_VALIDATE_INT))
		{
			$selectedKeyId = $request->getQueryParams()['key'];
		}

		$apiKeys = $this->DB->api_keys();
		if (!User::HasPermissions(User::PERMISSION_ADMIN))
		{
			$apiKeys = $apiKeys->where('user_id', GROCY_USER_ID);
		}

		return $this->RenderPage($response, 'manageapikeys', [
			'apiKeys' => $apiKeys,
			'users' => $this->DB->users(),
			'selectedKeyId' => $selectedKeyId
		]);
	}

	/**
	 * GET /manageapikeys/new - creates a new API key (optional "description" query
	 * parameter) and redirects to /manageapikeys?key={newKeyId}.
	 */
	public function CreateNewApiKey(Request $request, Response $response, array $args)
	{
		$description = null;
		if (isset($request->getQueryParams()['description']))
		{
			$description = $request->getQueryParams()['description'];
		}

		$newApiKey = ApiKeyService::GetInstance()->CreateApiKey(ApiKeyService::API_KEY_TYPE_DEFAULT, $description);
		$newApiKeyId = ApiKeyService::GetInstance()->GetApiKeyId($newApiKey);
		return $response->withRedirect($this->AppContainer->get('UrlManager')->ConstructUrl("/manageapikeys?key=$newApiKeyId"));
	}

	/**
	 * GET /api/openapi/specification - returns grocy.openapi.json enriched at runtime
	 * with the installed version, the instance server URL and derived ExposedEntity_*
	 * enum variants (including user entities and minus not editable/deletable/listable
	 * entities) used by the Swagger UI.
	 */
	public function DocumentationSpec(Request $request, Response $response, array $args)
	{
		$spec = $this->GetOpenApispec();

		$applicationService = ApplicationService::GetInstance();
		$versionInfo = $applicationService->GetInstalledVersion();
		$spec->info->version = $versionInfo->Version;
		$spec->info->description = str_replace('PlaceHolderManageApiKeysUrl', $this->AppContainer->get('UrlManager')->ConstructUrl('/manageapikeys'), $spec->info->description);
		$spec->servers[0]->url = $this->AppContainer->get('UrlManager')->ConstructUrl('/api');

		$spec->components->schemas->ExposedEntity_IncludingUserEntities = clone $spec->components->schemas->StringEnumTemplate;
		;
		foreach (UserfieldsService::GetInstance()->GetEntities() as $userEntity)
		{
			array_push($spec->components->schemas->ExposedEntity_IncludingUserEntities->enum, $userEntity);
		}
		sort($spec->components->schemas->ExposedEntity_IncludingUserEntities->enum);

		$spec->components->schemas->ExposedEntity_NotIncludingNotEditable = clone $spec->components->schemas->StringEnumTemplate;
		foreach ($spec->components->schemas->ExposedEntity->enum as $value)
		{
			if (!in_array($value, $spec->components->schemas->ExposedEntityNoEdit->enum))
			{
				array_push($spec->components->schemas->ExposedEntity_NotIncludingNotEditable->enum, $value);
			}
		}
		sort($spec->components->schemas->ExposedEntity_NotIncludingNotEditable->enum);

		$spec->components->schemas->ExposedEntity_IncludingUserEntities_NotIncludingNotEditable = clone $spec->components->schemas->StringEnumTemplate;
		foreach ($spec->components->schemas->ExposedEntity_IncludingUserEntities->enum as $value)
		{
			if (!in_array($value, $spec->components->schemas->ExposedEntityNoEdit->enum))
			{
				array_push($spec->components->schemas->ExposedEntity_IncludingUserEntities_NotIncludingNotEditable->enum, $value);
			}
		}
		array_push($spec->components->schemas->ExposedEntity_IncludingUserEntities_NotIncludingNotEditable->enum, 'stock'); // TODO: Don't hardcode this here - stock entries are normally not editable, but the corresponding Userfields are
		sort($spec->components->schemas->ExposedEntity_IncludingUserEntities_NotIncludingNotEditable->enum);

		$spec->components->schemas->ExposedEntity_NotIncludingNotDeletable = clone $spec->components->schemas->StringEnumTemplate;
		foreach ($spec->components->schemas->ExposedEntity->enum as $value)
		{
			if (!in_array($value, $spec->components->schemas->ExposedEntityNoDelete->enum))
			{
				array_push($spec->components->schemas->ExposedEntity_NotIncludingNotDeletable->enum, $value);
			}
		}
		sort($spec->components->schemas->ExposedEntity_NotIncludingNotDeletable->enum);

		$spec->components->schemas->ExposedEntity_NotIncludingNotListable = clone $spec->components->schemas->StringEnumTemplate;
		foreach ($spec->components->schemas->ExposedEntity->enum as $value)
		{
			if (!in_array($value, $spec->components->schemas->ExposedEntityNoListing->enum))
			{
				array_push($spec->components->schemas->ExposedEntity_NotIncludingNotListable->enum, $value);
			}
		}
		sort($spec->components->schemas->ExposedEntity_NotIncludingNotListable->enum);

		return $this->ApiResponse($response, $spec);
	}

	/**
	 * GET /api - renders the interactive API documentation UI (openapiui view).
	 */
	public function DocumentationUi(Request $request, Response $response, array $args)
	{
		return $this->Render($response, 'openapiui');
	}
}
