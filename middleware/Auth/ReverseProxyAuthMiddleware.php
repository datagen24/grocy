<?php

namespace Victual\Middleware\Auth;

use Victual\Services\DatabaseService;
use Victual\Services\UsersService;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Active when VICTUAL_AUTH_CLASS is set to Victual\Middleware\Auth\ReverseProxyAuthMiddleware:
 * authenticates against the username supplied by an upstream reverse proxy that
 * already performed authentication, either via the HTTP header named by
 * VICTUAL_REVERSE_PROXY_AUTH_HEADER (default "REMOTE_USER") or, when
 * VICTUAL_REVERSE_PROXY_AUTH_USE_ENV is enabled, the equally named entry in
 * $_SERVER (see config-dist.php). A matching local Victual user is created on
 * first sight of a username. API routes still fall back to regular API key
 * authentication first, for reverse proxy setups that bypass the proxy for them.
 */
class ReverseProxyAuthMiddleware extends BaseAuthMiddleware
{
	/**
	 * Authenticates via API key (API routes only, as a proxy-bypass fallback), or
	 * otherwise trusts the username supplied by the reverse proxy via header or
	 * environment variable, creating the local user if it does not exist yet.
	 *
	 * @return mixed The user row
	 * @throws \Exception When the configured header/env variable is missing, empty
	 *                    or ambiguous
	 */
	public function AuthenticateRequest(Request $request)
	{
		define('VICTUAL_EXTERNALLY_MANAGED_AUTHENTICATION', true);

		// Try to use regular API Key authentiaction (applies when the reverse proxy is configured to be bypassed for API routes)
		if ($this->IsApiRoute)
		{
			$auth = new ApiKeyAuthMiddleware($this->AppContainer, $this->ResponseFactory);
			$user = $auth->AuthenticateRequest($request);
			if ($user !== null)
			{
				return $user;
			}
		}

		if (VICTUAL_REVERSE_PROXY_AUTH_USE_ENV)
		{
			if (!isset($_SERVER[VICTUAL_REVERSE_PROXY_AUTH_HEADER]))
			{
				// Variable is not set
				throw new \Exception('ReverseProxyAuthMiddleware: ' . VICTUAL_REVERSE_PROXY_AUTH_HEADER . ' env variable is missing (could not be found in $_SERVER array)');
			}

			$username = $_SERVER[VICTUAL_REVERSE_PROXY_AUTH_HEADER];
			if (strlen($username) === 0)
			{
				// Variable is empty
				throw new \Exception('ReverseProxyAuthMiddleware: ' . VICTUAL_REVERSE_PROXY_AUTH_HEADER . ' env variable is invalid');
			}
		}
		else
		{
			$username = $request->getHeader(VICTUAL_REVERSE_PROXY_AUTH_HEADER);
			if (count($username) !== 1 || (count($username) === 1 && strlen($username[0]) === 0))
			{
				// Invalid configuration of Proxy
				throw new \Exception('ReverseProxyAuthMiddleware: ' . VICTUAL_REVERSE_PROXY_AUTH_HEADER . ' header is missing or invalid');
			}
			$username = $username[0];
		}

		$db = DatabaseService::GetInstance()->GetDbConnection();
		$user = $db->users()->where('username', $username)->fetch();
		if ($user == null)
		{
			$user = UsersService::GetInstance()->CreateUser($username, '', '', '');
		}

		return $user;
	}

	/**
	 * Not supported: authentication is fully delegated to the reverse proxy, so
	 * there is no Victual-handled login form to process.
	 *
	 * @param array $postParams The login form POST parameters (unused)
	 * @throws \Exception Always
	 */
	public static function ProcessLogin(array $postParams)
	{
		throw new \Exception('Not implemented');
	}
}
