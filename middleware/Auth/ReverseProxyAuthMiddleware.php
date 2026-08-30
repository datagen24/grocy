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
			// The header is client-settable, so it is only worth anything if the request
			// demonstrably came from the proxy that sets it. Sweep finding S4.
			$this->CheckRequestCameFromTrustedProxy();

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
	 * Refuses the request unless it arrived from an address in
	 * VICTUAL_REVERSE_PROXY_AUTH_TRUSTED_PROXIES.
	 *
	 * Without this the username header is simply believed, so anyone who can reach the
	 * backend directly - on the k3s target, any pod in the namespace - authenticates as
	 * whoever they say they are, and is created as a user if they do not exist. An unset
	 * list refuses everything rather than trusting everything: a header-mode deployment
	 * that has not named its proxy is not one whose header means anything.
	 *
	 * Deliberately not applied to VICTUAL_REVERSE_PROXY_AUTH_USE_ENV mode. There the
	 * username comes from $_SERVER, which the web server populates and a client header
	 * cannot reach (PHP exposes those as HTTP_*), so it is not forgeable the same way -
	 * and that mode covers setups like Apache doing its own authentication, where
	 * REMOTE_ADDR is the end user rather than a proxy and requiring a proxy list would
	 * break a correct configuration. USE_ENV is the mode to prefer.
	 *
	 * @throws \Exception When the list is unset or the request did not come from it
	 */
	protected function CheckRequestCameFromTrustedProxy(): void
	{
		$trustedProxies = trim((string)VICTUAL_REVERSE_PROXY_AUTH_TRUSTED_PROXIES);

		if ($trustedProxies === '')
		{
			throw new \Exception('ReverseProxyAuthMiddleware: REVERSE_PROXY_AUTH_TRUSTED_PROXIES is not configured, so the ' . VICTUAL_REVERSE_PROXY_AUTH_HEADER . ' header cannot be trusted. Set it to the address or CIDR range of your reverse proxy, or use REVERSE_PROXY_AUTH_USE_ENV instead.');
		}

		$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';

		if (!IsIpInCidrList($remoteAddress, $trustedProxies))
		{
			throw new \Exception('ReverseProxyAuthMiddleware: request did not come from a trusted proxy');
		}
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
