<?php

namespace Victual\Middleware;

use Victual\Services\Database\DatabaseDialect;
use Victual\Services\DatabaseMigrationService;
use Victual\Services\DatabaseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Refuses to serve when the database schema and the code disagree.
 *
 * Migrations no longer happen inside a request by default, which leaves an obvious
 * question: what does the application do when it boots against a database that has not
 * been migrated yet, or against one migrated by a newer version than the deployed code?
 * Silently serving is the worst of the options - it breaks later, somewhere else, in a
 * way whose cause is nowhere near its symptom. So it fails fast, with a message naming
 * both numbers and how to fix it (plan 10, question 6).
 *
 * The check is unconditional. Only *auto-migration* is opt-in (MIGRATE_ON_ROOT_REQUEST);
 * knowing whether the schema matches is not something a deployment should be able to
 * switch off, because the answer is what makes every other failure legible. It costs one
 * memoized SELECT MAX(migration) per request.
 *
 * A database that is *ahead* of the code fails too. That is the rollback case - a deploy
 * reverted to an older image against a database a newer one already migrated - and it is
 * precisely as unserveable as being behind, while looking much more like everything is
 * fine.
 *
 * 503 rather than 500 because the condition is transient and operational: nothing is
 * broken, something has not been run yet. It is the one status decision made here before
 * [11](../docs/plans/11-api-error-handling.md) exists, and 11 should inherit it rather
 * than revisit it.
 */
class SchemaVersionMiddleware extends BaseMiddleware
{
	public function __invoke(Request $request, RequestHandler $handler): Response
	{
		// The root route is where MIGRATE_ON_ROOT_REQUEST does its migrating, so with
		// that setting on the check cannot run in front of it: the migrations table is
		// legitimately absent right up until the request that creates it. Every other
		// route is checked, which is what makes the fallback usable rather than a hole -
		// the API still refuses to answer from an unmigrated database, it just says to
		// open the page (or run the CLI) instead of failing further in.
		//
		// Matched on the path rather than the route name because this runs in front of
		// the routing middleware, deliberately: an unmigrated database should not be
		// asked to resolve a route or authenticate anybody first.
		if (VICTUAL_MIGRATE_ON_ROOT_REQUEST && $this->TargetsTheRootRoute($request))
		{
			return $handler->handle($request);
		}

		$dialect = DatabaseService::GetInstance()->GetDialect();
		$expected = DatabaseMigrationService::GetLatestMigrationNumber($dialect);
		$applied = DatabaseMigrationService::GetInstance()->GetAppliedMigrationNumber();

		if ($applied === $expected)
		{
			return $handler->handle($request);
		}

		$response = $this->ResponseFactory->createResponse(503)
			->withHeader('Content-Type', 'text/plain; charset=utf-8');
		$response->getBody()->write($this->DescribeMismatch($dialect, $applied, $expected));

		return $response;
	}

	/**
	 * The plain text body of the 503: what is wrong, in which direction, and what to do
	 * about it. A failure that has to be looked up somewhere else is a failure that gets
	 * guessed at instead.
	 */
	private function DescribeMismatch(DatabaseDialect $dialect, int $applied, int $expected): string
	{
		$message = 'Victual cannot serve requests: the database schema does not match this code.' . PHP_EOL . PHP_EOL;
		$message .= '  Database: migration ' . $applied
			. ($applied === 0 ? ' (nothing migrated yet)' : '') . PHP_EOL;
		$message .= '  Code:     migration ' . $expected . ' (' . $dialect->GetName() . ')' . PHP_EOL . PHP_EOL;

		if ($applied < $expected)
		{
			$message .= 'The database is behind the code. Run' . PHP_EOL . PHP_EOL;
			$message .= '    php bin/victual-migrate' . PHP_EOL . PHP_EOL;
			$message .= 'against it - as a deployment step, before this application serves traffic.' . PHP_EOL;
			$message .= 'Setting MIGRATE_ON_ROOT_REQUEST to true instead lets a request to / migrate' . PHP_EOL;
			$message .= 'the database, for an installation with nowhere to run the command from.' . PHP_EOL;

			return $message;
		}

		$message .= 'The database is ahead of the code: it was migrated by a newer version of' . PHP_EOL;
		$message .= 'Victual than the one running here. Neither bin/victual-migrate nor' . PHP_EOL;
		$message .= 'MIGRATE_ON_ROOT_REQUEST can help - migrations only go forwards. Deploy the' . PHP_EOL;
		$message .= 'newer version again, or restore the database from before it was migrated.' . PHP_EOL;

		return $message;
	}

	/**
	 * Whether the request is for "/" itself, base path allowed for.
	 */
	private function TargetsTheRootRoute(Request $request): bool
	{
		$path = $request->getUri()->getPath();

		if (!empty(VICTUAL_BASE_PATH) && str_starts_with($path, VICTUAL_BASE_PATH))
		{
			$path = substr($path, strlen(VICTUAL_BASE_PATH));
		}

		return $path === '' || $path === '/';
	}
}
