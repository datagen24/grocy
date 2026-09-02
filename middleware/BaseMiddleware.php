<?php

namespace Victual\Middleware;

use DI\Container;
use Psr\Http\Message\ResponseFactoryInterface;

/**
 * Base class for all middlewares; provides access to the DI container and the PSR-17
 * response factory.
 *
 * Deliberately nothing else. It used to fetch ApplicationService here too, which no
 * middleware ever read - and constructing a service opens the database connection, so
 * merely registering the routes (routes.php attaches CorsMiddleware and JsonMiddleware
 * to the API groups) needed a database. bin/victual-warm-cache registers the routes to
 * write the route cache at image build time, where there is no database and must not
 * be one; a middleware that needs a service asks for it when it runs, not when it is
 * constructed.
 */
class BaseMiddleware
{
	public function __construct(Container $container, ResponseFactoryInterface $responseFactory)
	{
		$this->AppContainer = $container;
		$this->ResponseFactory = $responseFactory;
	}

	protected Container $AppContainer;
	protected ResponseFactoryInterface $ResponseFactory;
}
