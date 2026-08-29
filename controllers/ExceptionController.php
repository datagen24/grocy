<?php

namespace Grocy\Controllers;

use DI\Container;
use Grocy\Controllers\Api\BaseApiController;
use Grocy\Services\ApplicationService;
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

		if ($exception instanceof HttpNotFoundException)
		{
			if (!defined('VICTUAL_AUTHENTICATED'))
			{
				define('VICTUAL_AUTHENTICATED', false);
			}

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

		return $this->RenderPage($response->withStatus(500), 'errors/500', [
			'exception' => $exception,
			'systemInfo' => ApplicationService::GetInstance()->GetSystemInfo()
		]);
	}
}
