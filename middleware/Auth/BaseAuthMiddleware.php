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

				return $response = $handler->handle($request);
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
	 * Sets the session cookie with the given session key on the client.
	 *
	 * The session key is the credential itself, so the cookie carries HttpOnly (nothing
	 * reads it from JavaScript), SameSite=Lax and, over HTTPS, Secure. Its own lifetime
	 * mirrors the session row: a browser session cookie for a normal login, and the
	 * stay-logged-in lifetime when that box was ticked. Sweep finding S3 / plan 15-B2.
	 *
	 * @param string $sessionKey The session key as returned by SessionService::CreateSession()
	 * @param bool $stayLoggedInPermanently Whether the login asked to be remembered
	 */
	protected static function SetSessionCookie(string $sessionKey, bool $stayLoggedInPermanently = false)
	{
		setcookie(SessionService::SESSION_COOKIE_NAME, $sessionKey, [
			// 0 is a browser session cookie. The server remains the authority on validity
			// either way - see SessionService::CreateSession().
			'expires' => $stayLoggedInPermanently ? time() + SessionService::GetStayLoggedInLifetimeSeconds() : 0,
			'path' => rtrim(VICTUAL_BASE_PATH, '/') . '/',
			'secure' => self::IsHttpsRequest(),
			'httponly' => true,
			'samesite' => 'Lax'
		]);
	}

	/**
	 * Whether the current request reached us over HTTPS, honoring X-Forwarded-Proto for the
	 * reverse proxy deployments this fork targets. Same determination UrlManager makes.
	 */
	protected static function IsHttpsRequest(): bool
	{
		if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && str_contains($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https'))
		{
			return true;
		}

		return !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off';
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
