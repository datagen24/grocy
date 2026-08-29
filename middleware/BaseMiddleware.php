<?php

namespace Victual\Middleware;

use DI\Container;
use Victual\Services\ApplicationService;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * Base class for all middlewares; provides access to the DI container,
 * the PSR-17 response factory and the ApplicationService.
 */
class BaseMiddleware
{
	public function __construct(Container $container, ResponseFactoryInterface $responseFactory)
	{
		$this->AppContainer = $container;
		$this->ResponseFactory = $responseFactory;
		$this->ApplicationService = ApplicationService::GetInstance();
	}

	protected Container $AppContainer;
	protected ResponseFactoryInterface $ResponseFactory;
	protected ApplicationService $ApplicationService;
}
