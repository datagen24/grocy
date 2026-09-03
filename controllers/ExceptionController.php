<?php

namespace Victual\Controllers;

use DI\Container;
use Victual\Controllers\Api\BaseApiController;
use Victual\Services\ApplicationService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Slim custom error handler for the whole application: renders uncaught
 * exceptions either as a JSON API error payload (for /api/* routes) or as an
 * HTML error page (404/403/500) for regular view routes.
 *
 * Unlike every other controller this one is constructed while `app.php` is still
 * assembling the application, before a single middleware has run - so nothing it does on
 * the way in may depend on the request, on authentication, or on the database being
 * reachable. BaseController::GetDb() is what keeps the last of those true; this class is
 * the reason that method exists.
 */
class ExceptionController extends BaseApiController
{
	/**
	 * @param ResponseFactoryInterface $responseFactory Factory used to create the fresh error response
	 */
	public function __construct(Container $container, ResponseFactoryInterface $responseFactory)
	{
		parent::__construct($container);
		$this->ResponseFactory = $responseFactory;
	}

	private $ResponseFactory;

	/**
	 * Handles the given exception (Slim error handler signature).
	 *
	 * For API routes a JSON body with error_message (plus stack trace details when
	 * $displayErrorDetails is true) is returned; the HTTP status comes from the
	 * exception for HttpExceptions, otherwise 500. For view routes the matching
	 * error page (404, 403 or 500) is rendered.
	 *
	 * @return \Psr\Http\Message\ResponseInterface
	 */
	public function __invoke(ServerRequestInterface $request, Throwable $exception, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails, ?LoggerInterface $logger = null)
	{
		if (!defined('VICTUAL_LOCALE'))
		{
			define('VICTUAL_LOCALE', VICTUAL_DEFAULT_LOCALE);
		}

		$response = $this->ResponseFactory->createResponse();
		$isApiRoute = string_starts_with($request->getUri()->getPath(), '/api/');

		if (!defined('VICTUAL_AUTHENTICATED'))
		{
			define('VICTUAL_AUTHENTICATED', false);
		}

		if ($isApiRoute)
		{
			$status = 500;
			if ($exception instanceof HttpException)
			{
				$status = $exception->getCode();
			}

			$data = [
				'error_message' => $exception->getMessage()
			];

			if ($displayErrorDetails)
			{
				$data['error_details'] = [
					'stack_trace' => $exception->getTraceAsString(),
					'file' => $exception->getFile(),
					'line' => $exception->getLine()
				];
			}

			return $this->ApiResponse($response->withStatus($status)->withHeader('Content-Type', 'application/json'), $data);
		}

		$status = $this->ErrorPageStatus($exception);

		// The error page is the one page that has to render when everything else has
		// stopped working, and it is also one of the most demanding: it reads the sidebar
		// entities and the system information from the database, and compiles a template.
		// When that fails there is nothing left to fall back to but plain text - and
		// failing here means the exception escapes the error handler itself, which PHP
		// renders as a raw fatal with whatever status nothing has set yet.
		try
		{
			if ($exception instanceof HttpNotFoundException)
			{
				if (!defined('VICTUAL_AUTHENTICATED'))
				{
					define('VICTUAL_AUTHENTICATED', false);
				}

				return $this->RenderPage($response->withStatus($status), 'errors/404', [
					'exception' => $exception
				]);
			}

			if ($exception instanceof HttpForbiddenException)
			{
				return $this->RenderPage($response->withStatus($status), 'errors/403', [
					'exception' => $exception
				]);
			}

			return $this->RenderPage($response->withStatus($status), 'errors/500', [
				'exception' => $exception,
				'systemInfo' => ApplicationService::GetInstance()->GetSystemInfo()
			]);
		}
		catch (Throwable $renderFailure)
		{
			return $this->UnrenderableError($status, $exception, $renderFailure, $displayErrorDetails);
		}
	}

	/**
	 * The status the rendered error page carries, by exception type.
	 */
	private function ErrorPageStatus(Throwable $exception): int
	{
		if ($exception instanceof HttpNotFoundException)
		{
			return 404;
		}

		if ($exception instanceof HttpForbiddenException)
		{
			return 403;
		}

		return 500;
	}

	/**
	 * The last resort: the original status, in plain text, when the error page itself
	 * could not be rendered.
	 *
	 * Deliberately a response rather than a rethrow. An exception thrown out of the error
	 * handler has nowhere left to go - Slim is already handling one - so it leaves through
	 * PHP as an uncaught fatal, with a stack trace in the body and, because nothing has set
	 * a status by then, frequently a 200. A client that is told "OK" and handed a fatal
	 * error page has been lied to about the one thing it can act on.
	 *
	 * Built from a fresh response, not from the one being rendered into: whatever the
	 * template managed to write before it failed is a fragment of a page, and half a page
	 * is worse than none.
	 *
	 * Both messages go to the log, and only to the body in dev mode, for the reason the 500
	 * page's own details are gated the same way - this is emitted after authentication has
	 * been abandoned, and a connection failure names the host, port and role.
	 */
	private function UnrenderableError(int $status, Throwable $exception, Throwable $renderFailure, bool $displayErrorDetails)
	{
		error_log('Victual: ' . $status . ' for ' . get_class($exception) . ': ' . $exception->getMessage());
		error_log('Victual: the error page could not be rendered: ' . $renderFailure->getMessage());

		$body = 'Victual cannot show its error page.' . PHP_EOL . PHP_EOL;
		$body .= 'The request failed, and the page that reports failures could not be built' . PHP_EOL;
		$body .= 'either - it reads the sidebar and the system information from the database,' . PHP_EOL;
		$body .= 'so an unreachable database takes both the request and its error page with it.' . PHP_EOL;
		$body .= 'The server log holds the original error and the rendering failure.' . PHP_EOL;

		if ($displayErrorDetails)
		{
			$body .= PHP_EOL . 'Original error:  ' . $exception->getMessage() . PHP_EOL;
			$body .= 'Rendering error: ' . $renderFailure->getMessage() . PHP_EOL;
		}

		$response = $this->ResponseFactory->createResponse($status)
			->withHeader('Content-Type', 'text/plain; charset=utf-8');
		$response->getBody()->write($body);

		return $response;
	}
}
