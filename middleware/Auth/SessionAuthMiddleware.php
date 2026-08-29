<?php

namespace Grocy\Middleware\Auth;

use Grocy\Services\SessionService;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Authenticates via the Grocy session cookie against the sessions managed
 * by SessionService. Not selected directly via VICTUAL_AUTH_CLASS, but used
 * as a building block by DefaultAuthMiddleware (and thus LdapAuthMiddleware).
 */
class SessionAuthMiddleware extends BaseAuthMiddleware
{
	/**
	 * Returns the user of the valid session cookie, or null when no valid session cookie is present.
	 */
	public function AuthenticateRequest(Request $request)
	{
		$sessionService = SessionService::GetInstance();

		if (isset($_COOKIE[SessionService::SESSION_COOKIE_NAME]) && $sessionService->IsValidSession($_COOKIE[SessionService::SESSION_COOKIE_NAME]))
		{
			return $sessionService->GetUserBySessionKey($_COOKIE[SessionService::SESSION_COOKIE_NAME]);
		}
		else
		{
			return null;
		}
	}

	/**
	 * Not supported - sessions are only created by other middlewares' login processing.
	 *
	 * @throws \Exception Always
	 */
	public static function ProcessLogin(array $postParams)
	{
		throw new \Exception('Not implemented');
	}
}
