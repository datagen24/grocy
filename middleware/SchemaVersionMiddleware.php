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
 * what is missing and how to fix it (plan 10, question 6).
 *
 * **The comparison is of sets, not of maxima.** Q6's response called for one
 * SELECT MAX(migration); a review of this plan's pull request showed that is not a schema
 * version. Migrations reach master in the order their pull requests merge, so a database
 * can hold 0257 and 0259 without ever having run 0258 - and its maximum is then 259, the
 * same as a database that ran everything. The gate would report a schema that is missing
 * a table as current, and would go on doing so forever, because nothing about the
 * maximum ever changes again. Comparing every required migration against every applied
 * one is the same one query, cannot be fooled by merge order, and names the holes it
 * finds so an operator can act on them.
 *
 * The check is unconditional. Only *auto-migration* is opt-in (MIGRATE_ON_ROOT_REQUEST);
 * knowing whether the schema matches is not something a deployment should be able to
 * switch off, because the answer is what makes every other failure legible. It costs one
 * memoized SELECT per request.
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

		// Only "the migrations table does not exist" is an answer about the schema; every
		// other database failure means this check could not be made at all, and the
		// service says so by throwing rather than by reporting an empty database. The two
		// need different responses, because "run the migrations" is actively misleading
		// advice to somebody whose database is unreachable.
		try
		{
			$service = DatabaseMigrationService::GetInstance();
			$missing = $service->GetMissingMigrationNumbers($dialect);
			$unknown = $service->GetUnknownMigrationNumbers($dialect);
			$applied = $service->GetAppliedMigrationNumber();
		}
		catch (\PDOException $ex)
		{
			return $this->DatabaseUnavailable($dialect, $ex);
		}

		if (empty($missing) && empty($unknown))
		{
			return $handler->handle($request);
		}

		return $this->PlainText(503, $this->DescribeMismatch($dialect, $applied, $missing, $unknown));
	}

	/**
	 * The plain text body of the 503: what is wrong, in which direction, which migrations
	 * it is about, and what to do about it. A failure that has to be looked up somewhere
	 * else is a failure that gets guessed at instead.
	 *
	 * @param int[] $missing Required by this engine, not recorded in the database
	 * @param int[] $unknown Recorded in the database, unknown to this engine
	 */
	private function DescribeMismatch(DatabaseDialect $dialect, int $applied, array $missing, array $unknown): string
	{
		$message = 'Victual cannot serve requests: the database schema does not match this code.' . PHP_EOL . PHP_EOL;
		$message .= '  Database: highest applied migration ' . $applied
			. ($applied === 0 ? ' (nothing migrated yet)' : '') . PHP_EOL;
		$message .= '  Code:     latest migration ' . DatabaseMigrationService::GetLatestMigrationNumber($dialect)
			. ' (' . $dialect->GetName() . ')' . PHP_EOL . PHP_EOL;

		if (!empty($missing))
		{
			// Listed rather than counted, and listed even when the highest numbers agree:
			// a hole below the maximum is the case a maximum cannot see, and the numbers
			// are what an operator needs to tell "nothing has run here" from "one
			// migration was skipped".
			$message .= 'Missing from the database: ' . $this->SummariseNumbers($missing) . PHP_EOL . PHP_EOL;
			$message .= 'Run' . PHP_EOL . PHP_EOL;
			$message .= '    php bin/victual-migrate' . PHP_EOL . PHP_EOL;
			$message .= 'against it - as a deployment step, before this application serves traffic.' . PHP_EOL;
			$message .= 'Setting MIGRATE_ON_ROOT_REQUEST to true instead lets a request to / migrate' . PHP_EOL;
			$message .= 'the database, for an installation with nowhere to run the command from.' . PHP_EOL;
		}

		if (!empty($unknown))
		{
			if (!empty($missing))
			{
				$message .= PHP_EOL;
			}

			$message .= 'Applied but unknown to this code: ' . $this->SummariseNumbers($unknown) . PHP_EOL . PHP_EOL;
			$message .= 'The database is ahead of the code: it was migrated by a newer version of' . PHP_EOL;
			$message .= 'Victual than the one running here. Neither bin/victual-migrate nor' . PHP_EOL;
			$message .= 'MIGRATE_ON_ROOT_REQUEST can help - migrations only go forwards. Deploy the' . PHP_EOL;
			$message .= 'newer version again, or restore the database from before it was migrated.' . PHP_EOL;
		}

		return $message;
	}

	/**
	 * The 503 for a database that could not be asked the question at all.
	 *
	 * Deliberately not the mismatch message above, and deliberately not a 500 through the
	 * error middleware either. Not the mismatch message, because telling somebody whose
	 * database is down to run migrations at it sends them in the wrong direction - which
	 * is the defect this response exists to correct. Not the error page, because the
	 * error page renders a template and asks ApplicationService for system information,
	 * which reaches the same unreachable database; a middleware that already answers in
	 * plain text before routing can say what happened without depending on anything that
	 * is failing.
	 *
	 * The driver's own message goes to the log rather than the body, except in dev mode,
	 * for the same reason the error middleware only shows details there: this response is
	 * emitted before authentication, and a connection failure names the host, port and
	 * role. The SQLSTATE is in the body because it identifies the condition without
	 * describing the deployment.
	 */
	private function DatabaseUnavailable(DatabaseDialect $dialect, \PDOException $ex): Response
	{
		error_log('Victual: the database could not be asked for its schema version: ' . $ex->getMessage());

		$sqlState = $ex->errorInfo[0] ?? $ex->getCode();

		$message = 'Victual cannot serve requests: the database could not be queried.' . PHP_EOL . PHP_EOL;
		$message .= '  Driver:   ' . $dialect->GetName() . PHP_EOL;
		$message .= '  SQLSTATE: ' . (is_string($sqlState) && $sqlState !== '' ? $sqlState : 'none reported') . PHP_EOL . PHP_EOL;
		$message .= 'This is not a migration problem: the schema version could not be read at all.' . PHP_EOL;
		$message .= 'Check that the database is running, reachable, and readable by the configured' . PHP_EOL;
		$message .= 'user. The server log holds the driver\'s own message.' . PHP_EOL;

		if (VICTUAL_MODE === 'dev')
		{
			$message .= PHP_EOL . $ex->getMessage() . PHP_EOL;
		}

		return $this->PlainText(503, $message);
	}

	/**
	 * A run of consecutive numbers written as a range, so that "every migration there is"
	 * reads as "1-256" rather than as 256 numbers nobody will count.
	 *
	 * @param int[] $numbers Ascending
	 */
	private function SummariseNumbers(array $numbers): string
	{
		$parts = [];
		$start = null;
		$previous = null;

		foreach ($numbers as $number)
		{
			if ($start === null)
			{
				$start = $previous = $number;
				continue;
			}

			if ($number === $previous + 1)
			{
				$previous = $number;
				continue;
			}

			$parts[] = $start === $previous ? (string)$start : $start . '-' . $previous;
			$start = $previous = $number;
		}

		if ($start !== null)
		{
			$parts[] = $start === $previous ? (string)$start : $start . '-' . $previous;
		}

		return implode(', ', $parts);
	}

	private function PlainText(int $status, string $body): Response
	{
		$response = $this->ResponseFactory->createResponse($status)
			->withHeader('Content-Type', 'text/plain; charset=utf-8');
		$response->getBody()->write($body);

		return $response;
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
