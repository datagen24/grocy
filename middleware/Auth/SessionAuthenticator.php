<?php

namespace Victual\Middleware\Auth;

use Victual\Services\SessionService;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Recognises a request by the Victual session cookie, against the sessions
 * SessionService manages. The cookie value is the credential itself, which is why
 * SessionCookie sets it HttpOnly.
 */
class SessionAuthenticator extends Authenticator
{
	/**
	 * @return mixed|null The user owning the valid session, or null when the request
	 *                    carries no session cookie or an expired one
	 */
	public function Authenticate(Request $request)
	{
		$sessionKey = $request->getCookieParams()[SessionService::SESSION_COOKIE_NAME] ?? null;

		if ($sessionKey === null)
		{
			return null;
		}

		$sessionService = SessionService::GetInstance();

		if (!$sessionService->IsValidSession($sessionKey))
		{
			return null;
		}

		return $sessionService->GetUserBySessionKey($sessionKey);
	}
}
