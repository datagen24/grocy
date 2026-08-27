<?php

namespace Grocy\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Sets the Content-Type header of every response to application/json;
 * file download responses (with a Content-Disposition header) are left untouched.
 * Used for all API routes.
 */
class JsonMiddleware extends BaseMiddleware
{
	public function __invoke(Request $request, RequestHandler $handler): Response
	{
		$response = $handler->handle($request);

		if ($response->hasHeader('Content-Disposition'))
		{
			return $response;
		}
		else
		{
			$response = $response->withHeader('Content-Type', 'application/json');

			return $response;
		}
	}
}
