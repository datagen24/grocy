<?php

namespace Victual\Middleware\Auth;

use DI\Container;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * One way of recognising who is making a request.
 *
 * An authenticator has exactly this job. It does not decide what happens when nobody is
 * recognised, it does not render a login page and it is not a middleware - which is the
 * point of it existing. Before plan 15-C1 the authentication middlewares built each other:
 * DefaultAuthMiddleware constructed a SessionAuthMiddleware and an ApiKeyAuthMiddleware
 * and called AuthenticateRequest() on them directly, so a class that is a middleware in
 * one configuration was a helper object in another, with half its state never set.
 *
 * That was not only untidy. ApiKeyAuthMiddleware read $this->RouteName, which
 * BaseAuthMiddleware::__invoke() sets - and on a cross-constructed instance __invoke()
 * never runs, so RouteName was null and the iCal sharing link's "secret" branch could
 * never be reached (sweep finding S17). A composed object that asks the request its own
 * questions cannot have that bug.
 */
abstract class Authenticator
{
	public function __construct(Container $container)
	{
		$this->AppContainer = $container;
	}

	protected Container $AppContainer;

	/**
	 * Recognises the request, or does not.
	 *
	 * @return mixed|null The user row this request is made by, or null when this
	 *                    authenticator does not recognise it
	 */
	abstract public function Authenticate(Request $request);
}
