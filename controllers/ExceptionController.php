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
 */
class ExceptionController extends BaseApiController
{
	/**
	 * @param ResponseFactoryInterface $responseFactory Factory used to create the fresh error response
	 * @param LoggerInterface|null $logger Where uncaught exceptions are recorded. Slim's error
	 *                                     middleware only hands its own logger to its own handler,
	 *                                     so this one arrives here rather than through __invoke()
	 */
	public function __construct(Container $container, ResponseFactoryInterface $responseFactory, ?LoggerInterface $logger = null)
	{
		parent::__construct($container);
		$this->ResponseFactory = $responseFactory;
		$this->Logger = $logger;
	}

	private $ResponseFactory;
	private ?LoggerInterface $Logger;

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
		$isApiRoute = IsApiRoutePath($request->getUri()->getPath());

		$this->LogException($request, $exception, $logErrors, $logErrorDetails, $logger);

		if (!defined('VICTUAL_AUTHENTICATED'))
		{
			define('VICTUAL_AUTHENTICATED', false);
		}

		if ($isApiRoute)
		{
			$status = self::HttpStatusOf($exception);

			// The same rule GenericErrorResponse() applies to a caught exception, applied
			// to one that escaped: an uncaught PDOException is a server fault and stays a
			// 500, but the answer does not quote the statement that failed. See issue #48.
			$data = [
				'error_message' => self::WithoutDriverText($exception->getMessage())
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

		if ($exception instanceof HttpNotFoundException)
		{
			return $this->RenderPage($response->withStatus(404), 'errors/404', [
				'exception' => $exception
			]);
		}

		if ($exception instanceof HttpForbiddenException)
		{
			return $this->RenderPage($response->withStatus(403), 'errors/403', [
				'exception' => $exception
			]);
		}

		$status = self::HttpStatusOf($exception);

		if ($status < 500)
		{
			// A 4xx that is neither of the two above - a 405 on a route whose verb changed,
			// a 400 raised before a controller was reached. It used to render the "a server
			// error occured" page with a 500, which is wrong in both halves: it is the
			// caller's request that could not be handled, and telling them the server broke
			// invites a retry that will fail the same way. Plan 15-C4.
			return $this->RenderPage($response->withStatus($status), 'errors/4xx', [
				'exception' => $exception,
				'status' => $status
			]);
		}

		return $this->RenderPage($response->withStatus(500), 'errors/500', [
			'exception' => $exception,
			'systemInfo' => ApplicationService::GetInstance()->GetSystemInfo()
		]);
	}

	/**
	 * The HTTP status an exception asks for, clamped to a range that is one.
	 *
	 * `HttpException::getCode()` was trusted as a status with nothing checking it, so an
	 * exception constructed with a code that is not one - which any `\Exception` subclass
	 * may carry, since getCode() is free-form - reached the response as a status the PSR-7
	 * implementation then rejected, turning a handled error into an unhandled one. Plan
	 * 15-C4.
	 */
	private static function HttpStatusOf(Throwable $exception): int
	{
		if (!($exception instanceof HttpException))
		{
			return 500;
		}

		$status = (int)$exception->getCode();

		return ($status >= 400 && $status <= 599) ? $status : 500;
	}

	/**
	 * Records the exception for the operator.
	 *
	 * What it deliberately does not carry is the request body. Bodies on this API contain
	 * product notes, user names and, on the user endpoints, passwords, and a log is a
	 * place they would sit in plain text for as long as the platform keeps records.
	 *
	 * A client error is logged at warning and a server fault at error, so that the volume
	 * a malformed filter can generate does not drown the faults worth reading. File, line
	 * and stack trace are attached only when the error middleware was asked for details -
	 * the same flag that used to be the only reason anything was recorded at all.
	 */
	private function LogException(ServerRequestInterface $request, Throwable $exception, bool $logErrors, bool $logErrorDetails, ?LoggerInterface $logger): void
	{
		$logger = $logger ?? $this->Logger;

		if (!$logErrors || $logger === null)
		{
			return;
		}

		$status = self::HttpStatusOf($exception);

		$context = [
			'method' => $request->getMethod(),
			'path' => $request->getUri()->getPath(),
			'status' => $status,
			'exception' => get_class($exception)
		];

		if ($logErrorDetails)
		{
			$context['file'] = $exception->getFile();
			$context['line'] = $exception->getLine();
			$context['stack_trace'] = $exception->getTraceAsString();
		}

		$message = self::WithoutDriverText($exception->getMessage());

		if ($status >= 500)
		{
			$logger->error($message, $context);
		}
		else
		{
			$logger->warning($message, $context);
		}
	}
}
