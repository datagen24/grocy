<?php

namespace Victual\Middleware\Auth;

use Victual\Middleware\BaseMiddleware;
use Victual\Services\DatabaseService;
use Victual\Services\SessionService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Routing\RouteContext;

/**
 * Base class for all authentication middlewares (the concrete class is selected
 * via the VICTUAL_AUTH_CLASS setting). Handles the common flow: public routes
 * (root/login), authentication-less modes (dev/demo/prerelease, embedded
 * install, DISABLE_AUTH) and, otherwise, delegating to AuthenticateRequest().
 * On success the VICTUAL_AUTHENTICATED / VICTUAL_USER_* constants are defined;
 * on failure API routes get a 401 response and other routes a redirect to /login.
 *
 * What a subclass supplies is which Authenticator objects recognise a request, and in
 * what order. It does not supply another middleware: the middlewares used to construct
 * each other and call AuthenticateRequest() across instances, which left half the
 * constructed object's state unset and made one branch of the API key path unreachable
 * (sweep finding S17). Plan 15-C1 is why that is gone.
 */
abstract class BaseAuthMiddleware extends BaseMiddleware
{
	/** @var bool True when the request addresses the JSON API rather than a rendered page */
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
		$routeName = $route === null ? null : $route->getName();
		$this->IsApiRoute = IsApiRoutePath($request->getUri()->getPath());

		if ($routeName === 'root' || $routeName === 'login')
		{
			// Root and Login routes are public/unauthenticated

			define('VICTUAL_AUTHENTICATED', false);
			return $handler->handle($request);
		}

		if (VICTUAL_MODE === 'dev' || VICTUAL_MODE === 'demo' || VICTUAL_MODE === 'prerelease' || VICTUAL_IS_EMBEDDED_INSTALL || VICTUAL_DISABLE_AUTH)
		{
			// These modes use default user context (without authentication) only

			$sessionService = SessionService::GetInstance();
			$user = $sessionService->GetDefaultUser();

			define('VICTUAL_AUTHENTICATED', true);
			define('VICTUAL_USER_USERNAME', $user->username);
			define('VICTUAL_USER_PICTURE_FILE_NAME', $user->picture_file_name);
			self::SyncDatabaseUserContext();

			return $handler->handle($request);
		}
		else
		{
			// Normal authentication flow (up to specific middleware implementation)

			$user = $this->AuthenticateRequest($request);

			if ($user === null)
			{
				define('VICTUAL_AUTHENTICATED', false);
				$response = $this->ResponseFactory->createResponse();

				if ($this->IsApiRoute)
				{
					// The body is written here rather than left to the caller because
					// nothing downstream of this point runs: this is a short circuit, and
					// a bodyless 401 was what a client had to guess at. JsonMiddleware,
					// which now wraps this middleware, supplies the Content-Type.
					$response->getBody()->write(json_encode(['error_message' => 'Unauthorized']));

					return $response->withStatus(401);
				}
				else
				{
					return $response->withStatus(302)->withHeader('Location', $this->AppContainer->get('UrlManager')->ConstructUrl('/login'));
				}
			}
			else
			{
				define('VICTUAL_AUTHENTICATED', true);
				define('VICTUAL_USER_ID', $user->id);
				define('VICTUAL_USER_USERNAME', $user->username);
				define('VICTUAL_USER_PICTURE_FILE_NAME', $user->picture_file_name);
				self::SyncDatabaseUserContext();

				return $handler->handle($request);
			}
		}
	}

	/**
	 * Passes the acting user down to the database connection. Engines which resolve user
	 * settings in SQL rather than via a PHP callback (PostgreSQL) need this to make
	 * victual_user_setting() work; on SQLite it does nothing.
	 */
	protected static function SyncDatabaseUserContext()
	{
		if (defined('VICTUAL_USER_ID'))
		{
			DatabaseService::GetInstance()->SetCurrentUserId(VICTUAL_USER_ID);
		}
	}

	/**
	 * Processes a login form submission and, on success, creates a session and sets the
	 * session cookie.
	 *
	 * The default is that there is nothing to process: an authentication backend that
	 * delegates to a reverse proxy has no credentials of its own to check, and a login
	 * form submitted to it is answered "invalid" rather than with an exception. It used
	 * to be an abstract static that three of five subclasses satisfied by throwing, so a
	 * login form posted on such an installation was a 500 (plan 15-C1).
	 *
	 * @param array $postParams The POST parameters of the login form (username, password, stay_logged_in)
	 * @return bool True/False if the provided credentials were valid
	 */
	public static function ProcessLogin(array $postParams)
	{
		return false;
	}

	/**
	 * Authenticates the given request (implementation-specific).
	 *
	 * @param Request $request
	 * @return mixed|null the user row or null if the request is not authenticated
	 * @throws \Exception Throws an \Exception if authentaction config is invalid
	 */
	abstract protected function AuthenticateRequest(Request $request);
}
