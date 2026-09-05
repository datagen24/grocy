<?php

namespace Victual\Controllers;

use Victual\Middleware\Auth\SessionCookie;
use Victual\Services\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Slim route controller for session handling: login form display,
 * credential processing (via the configured auth middleware class) and logout.
 */
class LoginController extends BaseController
{
	/**
	 * Serves the login form view (route GET /login).
	 */
	public function LoginPage(Request $request, Response $response, array $args)
	{
		return $this->RenderPage($response, 'login');
	}

	/**
	 * Destroys the current session and redirects to the root page (route GET /logout).
	 *
	 * The cookie is expired as well as the session row deleted. Deleting only the row
	 * left the browser holding a string that looks like a credential and is refused -
	 * harmless to the server, and a confusing thing to leave behind on a shared machine
	 * (sweep finding S19). The cookie parameter is read from the request rather than from
	 * $_COOKIE, and its absence is no longer a warning: logging out twice is a thing
	 * people do.
	 */
	public function Logout(Request $request, Response $response, array $args)
	{
		$sessionKey = $request->getCookieParams()[SessionService::SESSION_COOKIE_NAME] ?? null;

		if ($sessionKey !== null)
		{
			SessionService::GetInstance()->RemoveSession($sessionKey);
		}

		SessionCookie::Clear();

		return $response->withRedirect($this->AppContainer->get('UrlManager')->ConstructUrl('/'));
	}

	/**
	 * Processes a submitted login form (route POST /login) through the configured
	 * VICTUAL_AUTH_CLASS; a base64 encoded password (password_base64) is decoded first.
	 * Redirects to the root page on success, back to /login?invalid=true on failure.
	 */
	public function ProcessLogin(Request $request, Response $response, array $args)
	{
		$authMiddlewareClass = VICTUAL_AUTH_CLASS;

		$postParams = $request->getParsedBody();
		if (isset($postParams['password_base64']))
		{
			$postParams['password'] = base64_decode($postParams['password_base64']);
		}
		unset($postParams['password_base64']);

		if ($authMiddlewareClass::ProcessLogin($postParams))
		{
			return $response->withRedirect($this->AppContainer->get('UrlManager')->ConstructUrl('/'));
		}
		else
		{
			return $response->withRedirect($this->AppContainer->get('UrlManager')->ConstructUrl('/login?invalid=true'));
		}
	}
}
