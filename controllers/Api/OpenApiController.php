<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Services\ApiKeyService;
use Victual\Services\ApplicationService;
use Victual\Services\UserfieldsService;
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
	 * their own keys. The optional integer query parameter "key" preselects a key.
	 */
	public function ApiKeysList(Request $request, Response $response, array $args)
	{
		$selectedKeyId = -1;
		if (isset($request->getQueryParams()['key']) && filter_var($request->getQueryParams()['key'], FILTER_VALIDATE_INT))
		{
			$selectedKeyId = $request->getQueryParams()['key'];
		}

		return $this->RenderApiKeysPage($response, $selectedKeyId);
	}

	/**
	 * Renders the manage-keys page, optionally highlighting one key and showing the
	 * plaintext of one that has just been created.
	 *
	 * @param int $selectedKeyId Row id to highlight, or -1
	 * @param string|null $newApiKey The plaintext of a key created by this request - the
	 *                               only moment it exists, since what is stored is a hash
	 */
	private function RenderApiKeysPage(Response $response, int $selectedKeyId, ?string $newApiKey = null)
	{
		$apiKeys = $this->DB->api_keys();
		if (!User::HasPermissions(User::PERMISSION_ADMIN))
		{
			$apiKeys = $apiKeys->where('user_id', VICTUAL_USER_ID);
		}

		return $this->RenderPage($response, 'manageapikeys', [
			'apiKeys' => $apiKeys,
			'users' => $this->DB->users(),
			'selectedKeyId' => $selectedKeyId,
			'newApiKey' => $newApiKey
		]);
	}

	/**
	 * POST /manageapikeys/new - creates a new API key (optional "description" form
	 * parameter) and renders the manage-keys page showing it, once.
	 */
	public function CreateNewApiKey(Request $request, Response $response, array $args)
	{
		$description = null;
		$postParams = $request->getParsedBody();

		if (is_array($postParams) && isset($postParams['description']))
		{
			$description = $postParams['description'];
		}

		$newApiKey = ApiKeyService::GetInstance()->CreateApiKey(ApiKeyService::API_KEY_TYPE_DEFAULT, $description);
		$newApiKeyId = ApiKeyService::GetInstance()->GetApiKeyId($newApiKey);

		// Rendered here rather than redirected to, because this response is the only place
		// the key can ever be shown: what is stored is a SHA-256 hash (plan 11, question
		// 4), so nothing can produce the plaintext again. The obvious alternative - putting
		// it in the redirect URL - is the query-string key path sweep finding S11 exists to
		// remove, in the one place it would be most durable: browser history.
		return $this->RenderApiKeysPage($response, (int)$newApiKeyId, $newApiKey);
	}

	/**
	 * GET /api/openapi/specification - returns victual.openapi.json enriched at runtime
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
