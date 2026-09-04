<?php

namespace Victual\Middleware\Auth;

use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * The default authentication middleware (VICTUAL_AUTH_CLASS default value):
 * authenticates against the users created in Victual itself. Accepts a session
 * cookie for all routes and additionally an API key for API routes; logins
 * are verified against the password hash in the users table (rehashing to
 * Argon2id when necessary).
 */
class DefaultAuthMiddleware extends BaseAuthMiddleware
{
	/**
	 * Authenticates via session cookie, or via API key as fallback on API routes.
	 *
	 * @return mixed|null The user row or null if the request is not authenticated
	 */
	protected function AuthenticateRequest(Request $request)
	{
		$user = (new SessionAuthenticator($this->AppContainer))->Authenticate($request);

		if ($user !== null)
		{
			return $user;
		}

		if ($this->IsApiRoute)
		{
			// An API key is a credential for the API and nothing else: it cannot open a
			// rendered page, which is why this branch is the only one that consults it
			return (new ApiKeyAuthenticator($this->AppContainer))->Authenticate($request);
		}

		return null;
	}

	/**
	 * Verifies username/password against the users table; on success creates
	 * a session, sets the session cookie and rehashes the password if needed.
	 *
	 * @param array $postParams The login form POST parameters (username, password, stay_logged_in)
	 * @return bool True when the credentials were valid
	 */
	public static function ProcessLogin(array $postParams)
	{
		return PasswordLogin::Process($postParams);
	}
}
