<?php

namespace Victual\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Sets the Content-Type header of every API response that has not set one to
 * application/json; a response that chose its own type keeps it.
 *
 * This runs at application level, outside the authentication middleware, so that the
 * 401 an unauthenticated API request receives is typed like every other API response
 * rather than arriving bodyless and without a Content-Type (plan 11). It therefore has
 * to decide for itself which requests are API requests, which it does by path - the same
 * question BaseAuthMiddleware and ExceptionController answer the same way.
 */
class JsonMiddleware extends BaseMiddleware
{
	public function __invoke(Request $request, RequestHandler $handler): Response
	{
		$response = $handler->handle($request);

		if (!IsApiRoutePath($request->getUri()->getPath()))
		{
			return $response;
		}

		if ($response->hasHeader('Content-Type'))
		{
			// A handler that set its own type meant it. That covers the file download and
			// calendar responses this used to recognise by their Content-Disposition, and
			// one it did not: SchemaVersionMiddleware answers a schema mismatch with
			// deliberately plain text, and now sits inside this middleware rather than
			// outside it. Stamping application/json on that would be a lie about the one
			// response an operator reads when a deployment is misconfigured.
			return $response;
		}

		return $response->withHeader('Content-Type', 'application/json');
	}
}
