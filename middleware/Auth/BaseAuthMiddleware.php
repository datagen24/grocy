<?php

namespace Grocy\Middleware\Auth;

use Grocy\Middleware\BaseMiddleware;
use Grocy\Services\DatabaseService;
use Grocy\Services\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Routing\RouteContext;

/**
 * Base class for all authentication middlewares (the concrete class is selected
 * via the GROCY_AUTH_CLASS setting). Handles the common flow: public routes
 * (root/login), authentication-less modes (dev/demo/prerelease, embedded
 * install, DISABLE_AUTH) and, otherwise, delegating to AuthenticateRequest().
 * On success the GROCY_AUTHENTICATED / GROCY_USER_* constants are defined;
 * on failure API routes get a 401 response and other routes a redirect to /login.
 */
abstract class BaseAuthMiddleware extends BaseMiddleware
{
	/** @var string|null Name of the currently matched route */
	protected ?string $RouteName = null;

	/** @var bool True when the request path starts with /api/ */
	protected bool $IsApiRoute = false;

	/**
	 * Authenticates the request as described in the class docblock and
	 * either passes it on to the next handler or short-circuits with a
	 * 401 / login redirect response.
	 */
	public function __invoke(Request $request, RequestHandler $handler): Response
	{
		$routeContext = RouteContext::fromRequest($request);
		$route = $routeContext->getRoute();
		$this->RouteName = $route->getName();
		$this->IsApiRoute = string_starts_with($request->getUri()->getPath(), '/api/');

		if ($this->RouteName === 'root' || $this->RouteName === 'login')
		{
			// Root and Login routes are public/unauthenticated

			define('GROCY_AUTHENTICATED', false);
			return $handler->handle($request);
		}

		if (GROCY_MODE === 'dev' || GROCY_MODE === 'demo' || GROCY_MODE === 'prerelease' || GROCY_IS_EMBEDDED_INSTALL || GROCY_DISABLE_AUTH)
		{
			// These modes use default user context (without authentication) only

			$sessionService = SessionService::GetInstance();
			$user = $sessionService->GetDefaultUser();

			define('GROCY_AUTHENTICATED', true);
			define('GROCY_USER_USERNAME', $user->username);
			define('GROCY_USER_PICTURE_FILE_NAME', $user->picture_file_name);
			self::SyncDatabaseUserContext();

			return $handler->handle($request);
		}
		else
		{
			// Normal authentication flow (up to specific middleware implementation)

			$user = $this->AuthenticateRequest($request);

			if ($user === null)
			{
				define('GROCY_AUTHENTICATED', false);
				$response = $this->ResponseFactory->createResponse();

				if ($this->IsApiRoute)
				{
					return $response->withStatus(401);
				}
				else
				{
					return $response->withStatus(302)->withHeader('Location', $this->AppContainer->get('UrlManager')->ConstructUrl('/login'));
				}
			}
			else
			{
				define('GROCY_AUTHENTICATED', true);
				define('GROCY_USER_ID', $user->id);
				define('GROCY_USER_USERNAME', $user->username);
				define('GROCY_USER_PICTURE_FILE_NAME', $user->picture_file_name);
				self::SyncDatabaseUserContext();

				return $response = $handler->handle($request);
			}
		}
	}

	/**
	 * Passes the acting user down to the database connection. Engines which resolve user
	 * settings in SQL rather than via a PHP callback (PostgreSQL) need this to make
	 * grocy_user_setting() work; on SQLite it does nothing.
	 */
	protected static function SyncDatabaseUserContext()
	{
		if (defined('GROCY_USER_ID'))
		{
			DatabaseService::GetInstance()->SetCurrentUserId(GROCY_USER_ID);
		}
	}

	/**
	 * Sets the session cookie with the given session key on the client.
	 *
	 * @param string $sessionKey The session key as returned by SessionService::CreateSession()
	 */
	protected static function SetSessionCookie(string $sessionKey)
	{
		// Cookie never expires, session validity is up to SessionService
		setcookie(SessionService::SESSION_COOKIE_NAME, $sessionKey, PHP_INT_SIZE == 4 ? PHP_INT_MAX : PHP_INT_MAX >> 32);
	}

	/**
	 * Processes a login form submission and, on success, creates a session and sets the session cookie.
	 *
	 * @param array $postParams The POST parameters of the login form (username, password, stay_logged_in)
	 * @return bool True/False if the provided credentials were valid
	 * @throws \Exception Throws an \Exception if an error happened during credentials processing or if this authentication middlware doesn't provide credentials processing (e.g. handles this externally)
	 */
	abstract public static function ProcessLogin(array $postParams);

	/**
	 * Authenticates the given request (implementation-specific).
	 *
	 * @param Request $request
	 * @return mixed|null the user row or null if the request is not authenticated
	 * @throws \Exception Throws an \Exception if authentaction config is invalid
	 */
	abstract protected function AuthenticateRequest(Request $request);
}
