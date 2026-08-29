<?php

namespace Victual\Middleware\Auth;

use Victual\Services\DatabaseService;
use Victual\Services\SessionService;
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
		if ($this->IsApiRoute)
		{
			// Session cookie or API Key is ok
			$auth = new SessionAuthMiddleware($this->AppContainer, $this->ResponseFactory);
			$user = $auth->AuthenticateRequest($request);
			if ($user !== null)
			{
				return $user;
			}

			$auth = new ApiKeyAuthMiddleware($this->AppContainer, $this->ResponseFactory);
			$user = $auth->AuthenticateRequest($request);
			return $user;
		}
		else
		{
			// Only session cookie is ok
			$auth = new SessionAuthMiddleware($this->AppContainer, $this->ResponseFactory);
			$user = $auth->AuthenticateRequest($request);
			return $user;
		}
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
		if (empty($postParams['username']) || empty($postParams['password']))
		{
			return false;
		}

		$db = DatabaseService::GetInstance()->GetDbConnection();

		$user = $db->users()->where('username', $postParams['username'])->fetch();
		$inputPassword = $postParams['password'];
		$stayLoggedInPermanently = $postParams['stay_logged_in'] == 'on';

		if ($user !== null && password_verify($inputPassword, $user->password))
		{
			$sessionKey = SessionService::GetInstance()->CreateSession($user->id, $stayLoggedInPermanently);
			self::SetSessionCookie($sessionKey);

			if (password_needs_rehash($user->password, PASSWORD_ARGON2ID))
			{
				$user->update([
					'password' => password_hash($inputPassword, PASSWORD_ARGON2ID)
				]);
			}

			return true;
		}
		else
		{
			return false;
		}
	}
}
