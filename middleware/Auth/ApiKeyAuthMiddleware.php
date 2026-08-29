<?php

namespace Grocy\Middleware\Auth;

use DI\Container;
use Grocy\Services\ApiKeyService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Authenticates API requests via an API key against the keys managed by
 * ApiKeyService. Not selected directly via VICTUAL_AUTH_CLASS, but used as a
 * building block by DefaultAuthMiddleware and ReverseProxyAuthMiddleware.
 * The key is expected in the configured header (default VICTUAL-API-KEY), as
 * a query parameter of the same name, or - for the calendar iCal route -
 * as a special purpose key in the "secret" query parameter.
 */
class ApiKeyAuthMiddleware extends BaseAuthMiddleware
{
	/**
	 * Resolves the configured API key header name (service "ApiKeyHeaderName") in
	 * addition to the base middleware construction.
	 */
	public function __construct(Container $container, ResponseFactoryInterface $responseFactory)
	{
		parent::__construct($container, $responseFactory);
		$this->ApiKeyHeaderName = $this->AppContainer->get('ApiKeyHeaderName');
	}

	/** @var string Name of the header (and equally named query parameter) carrying the API key */
	protected readonly string $ApiKeyHeaderName;

	/**
	 * Looks up the API key, in order: the configured header, then the equally
	 * named query parameter, then - only on the "calendar-ical" route - the
	 * "secret" query parameter checked against special-purpose calendar iCal
	 * keys (API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL). This lets the calendar
	 * feed be added to external calendar apps that can't set custom headers.
	 *
	 * @return mixed|null The user row owning the key, or null if no valid key was found
	 */
	public function AuthenticateRequest(Request $request)
	{
		$validApiKey = false;
		$usedApiKey = null;
		$apiKeyService = new ApiKeyService();

		// First check the key in the configured header
		if ($request->hasHeader($this->ApiKeyHeaderName) && $apiKeyService->IsValidApiKey($request->getHeaderLine($this->ApiKeyHeaderName)))
		{
			$validApiKey = true;
			$usedApiKey = $request->getHeaderLine($this->ApiKeyHeaderName);
		}

		// Not recommended, but it's also possible to provide the API key via a query parameter (same name as the configured header)
		if (!$validApiKey && !empty($request->getQueryParam($this->ApiKeyHeaderName)) && $apiKeyService->IsValidApiKey($request->getQueryParam($this->ApiKeyHeaderName)))
		{
			$validApiKey = true;
			$usedApiKey = $request->getQueryParam($this->ApiKeyHeaderName);
		}

		// Handling of special purpose API keys
		if (!$validApiKey)
		{
			if ($this->RouteName === 'calendar-ical')
			{
				if ($request->getQueryParam('secret') !== null && $apiKeyService->IsValidApiKey($request->getQueryParam('secret'), ApiKeyService::API_KEY_TYPE_SPECIAL_PURPOSE_CALENDAR_ICAL))
				{
					$validApiKey = true;
					$usedApiKey = $request->getQueryParam('secret');
				}
			}
		}

		if ($validApiKey)
		{
			return $apiKeyService->GetUserByApiKey($usedApiKey);
		}
		else
		{
			return null;
		}
	}

	/**
	 * Not supported: API key authentication has no login form to process.
	 *
	 * @param array $postParams The login form POST parameters (unused)
	 * @throws \Exception Always
	 */
	public static function ProcessLogin(array $postParams)
	{
		throw new \Exception('Not implemented');
	}
}
