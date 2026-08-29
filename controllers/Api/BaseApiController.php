<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\BaseController;
use Victual\Services\DatabaseService;
use Victual\Services\Database\DatabaseDialect;
use LessQL\Result;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpException;

/**
 * Base class for all REST API controllers (everything below /api).
 *
 * Provides the JSON response helpers, the generic filtering/pagination/ordering
 * applied to list endpoints (query/limit/offset/order query parameters) and the
 * HTMLPurifier-based request body parsing/sanitization.
 */
class BaseApiController extends BaseController
{
	const PATTERN_FIELD = '[A-Za-z_][A-Za-z0-9_]+';
	const PATTERN_OPERATOR = '!?((>=)|(<=)|=|~|<|>|(§))';
	const PATTERN_VALUE = '[A-Za-z\p{L}\p{M}0-9*_.$#^| -\\\]+';

	protected $OpenApiSpec = null;

	/**
	 * Writes $data JSON-encoded to the response body; with $cache = true a 30 day Cache-Control header is added.
	 */
	protected function ApiResponse(Response $response, $data, $cache = false)
	{
		if ($cache)
		{
			$response = $response->withHeader('Cache-Control', 'max-age=2592000');
		}

		$response->getBody()->write(json_encode($data));
		return $response;
	}

	/**
	 * Returns a bodyless response with the given status code (default 204 No Content).
	 */
	protected function EmptyApiResponse(Response $response, $status = 204)
	{
		return $response->withStatus($status);
	}

	/**
	 * Returns a JSON error body of the shape { "error_message": string } with the given status code (default 400).
	 */
	protected function GenericErrorResponse(Response $response, $errorMessage, $status = 400)
	{
		$response = $response->withStatus($status);

		return $this->ApiResponse($response, [
			'error_message' => $errorMessage
		]);
	}

	/**
	 * Applies the generic list query parameters (see QueryData) to $data and returns the result JSON-encoded.
	 */
	public function FilteredApiResponse(Request $request, Response $response, Result $data, array $query)
	{
		$data = $this->QueryData($request, $data, $query);
		return $this->ApiResponse($response, $this->MaterialiseFiltered($request, $data, $query));
	}

	/**
	 * Runs a filtered/sorted query now rather than leaving it to be executed when the
	 * response is serialised, so that a database rejection of a *client supplied* term
	 * can be answered 400 instead of escaping as an unclassified 500.
	 *
	 * The rule is deliberately about provenance rather than about SQLSTATEs. "?order=nope"
	 * is rejected by SQLite as an unknown column and "?query[]=id~2" by PostgreSQL as an
	 * operator that does not exist for that type, and the list of codes an engine might
	 * choose for "your term made this statement invalid" is not something worth keeping in
	 * sync with two engines. What is knowable here is that the statement was fine until a
	 * query parameter was appended to it: if the caller supplied no "query[]" and no
	 * "order", a PDO failure is the server's and stays a 500.
	 *
	 * Returns the rows rather than the Result: LessQL's Result::jsonSerialize() is itself
	 * fetchAll(), so this changes nothing about the response body.
	 *
	 * @return \LessQL\Row[]
	 */
	protected function MaterialiseFiltered(Request $request, Result $data, array $query): array
	{
		try
		{
			return $data->fetchAll();
		}
		catch (\PDOException $ex)
		{
			if (!isset($query['query']) && !isset($query['order']))
			{
				throw $ex;
			}

			// Deliberately does not carry the driver's message. It names types, columns and
			// the engine, which is more than a caller needs to fix their request and more
			// than this API says about itself anywhere else.
			throw new HttpException(
				$request,
				'Invalid query: the database rejected the resulting statement - check that every field named in "query" and "order" exists on this entity and that the operator suits its type',
				400,
				$ex
			);
		}
	}

	/** @var array<string, array<string, string>> Column types per table, for this request only */
	private static $ColumnTypeCache = [];

	/**
	 * The column types of the table or view $data reads from, keyed by column name, or an
	 * empty array when they cannot be determined.
	 *
	 * Empty is not a failure and must not be treated as "no columns exist": a Result whose
	 * table the engine does not know about is a case for letting the query run and be
	 * caught by MaterialiseFiltered, not for rejecting every field the caller named.
	 *
	 * @return array<string, string>
	 */
	private function ColumnTypesOf(Result $data): array
	{
		$table = $data->getTable();

		if (!array_key_exists($table, self::$ColumnTypeCache))
		{
			$database = DatabaseService::GetInstance();

			try
			{
				self::$ColumnTypeCache[$table] = $database->GetDialect()->GetColumnTypes($database->GetDbConnectionRaw(), $table);
			}
			catch (\PDOException $ex)
			{
				// Introspection is an improvement on letting the engine complain, never a
				// new way to fail: a request that would have worked must not start
				// returning 500 because the catalogue lookup did. SQLite's PRAGMA compiles
				// the view it is asked about, for instance, so it can fail for reasons that
				// have nothing to do with the caller's filter. Fall back to no validation
				// and let MaterialiseFiltered classify whatever the query itself does.
				self::$ColumnTypeCache[$table] = [];
			}
		}

		return self::$ColumnTypeCache[$table];
	}

	/**
	 * Rejects a field a caller named in "query[]" or "order" that the entity does not have,
	 * with 400 rather than the 500 the engine's own complaint would otherwise become.
	 */
	private function AssertFieldExists(Request $request, array $columnTypes, string $field): void
	{
		if (!empty($columnTypes) && !array_key_exists($field, $columnTypes))
		{
			throw new HttpException($request, 'Invalid query: unknown field "' . $field . '"', 400);
		}
	}

	/**
	 * Applies the generic list query parameters to a LessQL result:
	 * query[] (filter conditions, see FilterData), limit/offset (pagination)
	 * and order ("field" or "field:asc|desc"; throws on any other sort order).
	 */
	protected function QueryData(Request $request, Result $data, array $query)
	{
		if (isset($query['query']))
		{
			$data = $this->FilterData($request, $data, $query['query']);
		}

		if (isset($query['limit']) || isset($query['offset']))
		{
			if (!isset($query['limit']))
			{
				$query['limit'] = -1;
			}

			$data = $data->limit(intval($query['limit']), intval($query['offset'] ?? 0));
		}

		if (isset($query['order']))
		{
			$parts = explode(':', $query['order']);
			$this->AssertFieldExists($request, $this->ColumnTypesOf($data), $parts[0]);

			if (count($parts) == 1)
			{
				$data = $data->orderBy($parts[0]);
			}
			else
			{
				if ($parts[1] != 'asc' && $parts[1] != 'desc')
				{
					throw new HttpException($request, 'Invalid sort order ' . $parts[1], 400);
				}

				$data = $data->orderBy($parts[0], $parts[1]);
			}
		}

		return $data;
	}

	/**
	 * Applies each query[] filter condition of the form "<field><operator><value>"
	 * (operators =, !=, ~, !~, <, >, <=, >= and § for regex matching; the value
	 * "null" additionally matches SQL NULL) as a WHERE clause to $data.
	 * Throws when a condition does not match the expected pattern.
	 */
	protected function FilterData(Request $request, Result $data, array $query): Result
	{
		$columnTypes = $this->ColumnTypesOf($data);

		foreach ($query as $q)
		{
			$matches = [];
			preg_match(
				'/(?P<field>' . self::PATTERN_FIELD . ')'
				. '(?P<op>' . self::PATTERN_OPERATOR . ')'
				. '(?P<value>' . self::PATTERN_VALUE . ')/u',
				$q,
				$matches
			);

			if (!array_key_exists('field', $matches) || !array_key_exists('op', $matches) || !array_key_exists('value', $matches))
			{
				throw new HttpException($request, 'Invalid query', 400);
			}

			$this->AssertFieldExists($request, $columnTypes, $matches['field']);

			// The substring and regex operators are the ones that need a string to work on.
			// Rejecting them here, on both engines, is what stops the two disagreeing: left
			// to the engines, SQLite coerces the value to text and matches while PostgreSQL
			// has no such operator for the type and raises. Neither answer is better than
			// the other, but one of them has to be given to both callers, and a filter that
			// silently means different things per engine is the worse outcome of the two.
			// See DatabaseDialect::IsTextMatchableType() for why timestamps are in here too.
			if (in_array($matches['op'], ['~', '!~', '§'], true)
				&& !empty($columnTypes)
				&& !DatabaseDialect::IsTextMatchableType($columnTypes[$matches['field']]))
			{
				throw new HttpException(
					$request,
					'Invalid query: the "' . $matches['op'] . '" operator needs a text field, and "'
						. $matches['field'] . '" is ' . $columnTypes[$matches['field']],
					400
				);
			}

			$sqlOrNull = '';
			if (strtolower($matches['value']) == 'null')
			{
				$sqlOrNull = ' OR ' . $matches['field'] . ' IS NULL';
			}

			switch ($matches['op'])
			{
				case '=':
					$data = $data->where($matches['field'] . ' = ?' . $sqlOrNull, $matches['value']);
					break;
				case '!=':
					$data = $data->where($matches['field'] . ' != ?' . $sqlOrNull, $matches['value']);
					break;
				case '~':
					// Spelled differently per engine (SQLite's LIKE is case insensitive,
					// PostgreSQL's is not and needs ILIKE), but the API contract is the same
					$data = $data->where(DatabaseService::GetInstance()->GetDialect()->GetLikeCondition($matches['field'], false), '%' . $matches['value'] . '%');
					break;
				case '!~':
					$data = $data->where(DatabaseService::GetInstance()->GetDialect()->GetLikeCondition($matches['field'], true), '%' . $matches['value'] . '%');
					break;
				case '<':
					$data = $data->where($matches['field'] . ' < ?', $matches['value']);
					break;
				case '>':
					$data = $data->where($matches['field'] . ' > ?', $matches['value']);
					break;
				case '>=':
					$data = $data->where($matches['field'] . ' >= ?', $matches['value']);
					break;
				case '<=':
					$data = $data->where($matches['field'] . ' <= ?', $matches['value']);
					break;
				case '§':
					// Spelled differently per engine (SQLite has a REGEXP operator backed by a
					// user defined function, PostgreSQL has "~"), but the API contract is the same
					$data = $data->where(DatabaseService::GetInstance()->GetDialect()->GetRegexpCondition($matches['field']), $matches['value']);
					break;
			}
		}

		return $data;
	}

	/**
	 * Lazily loads and returns victual.openapi.json as an object (also used for entity/file group validation).
	 */
	protected function GetOpenApispec()
	{
		if ($this->OpenApiSpec == null)
		{
			$this->OpenApiSpec = json_decode(file_get_contents(__DIR__ . '/../../victual.openapi.json'));
		}

		return $this->OpenApiSpec;
	}

	private static $htmlPurifierInstance = null;
	/**
	 * Returns the parsed JSON request body with all scalar string values run through HTMLPurifier.
	 * Throws a Slim HttpException (status 400) when the Content-Type is not application/json.
	 *
	 * @return array|null Null when the body could not be parsed as JSON
	 */
	protected function GetParsedAndFilteredRequestBody($request)
	{
		if ($request->getHeaderLine('Content-Type') != 'application/json')
		{
			throw new HttpException($request, 'Bad Content-Type', 400);
		}

		if (self::$htmlPurifierInstance == null)
		{
			$htmlPurifierConfig = \HTMLPurifier_Config::createDefault();
			$htmlPurifierConfig->set('Cache.SerializerPath', VICTUAL_DATAPATH . '/viewcache');
			$htmlPurifierConfig->set('HTML.Allowed', 'div,b,strong,i,em,u,a[href|title|target],iframe[src|width|height|frameborder],ul,ol,li,p[style],br,span[style],img[style|width|height|alt|src],table[border|width|style],tbody,tr,td,th,blockquote,*[style|class|id],h1,h2,h3,h4,h5,h6');
			$htmlPurifierConfig->set('Attr.EnableID', true);
			$htmlPurifierConfig->set('HTML.SafeIframe', true);
			$htmlPurifierConfig->set('CSS.AllowedProperties', 'font,font-size,font-weight,font-style,font-family,text-decoration,padding-left,color,background-color,text-align,width,height');
			$htmlPurifierConfig->set('URI.AllowedSchemes', ['data' => true, 'http' => true, 'https' => true]);
			$htmlPurifierConfig->set('URI.SafeIframeRegexp', '%^.*%'); // Allow any iframe source
			$htmlPurifierConfig->set('CSS.MaxImgLength', null);

			self::$htmlPurifierInstance = new \HTMLPurifier($htmlPurifierConfig);
		}

		$requestBody = $request->getParsedBody();
		foreach ($requestBody as $key => &$value)
		{
			// HTMLPurifier removes boolean values (true/false) and arrays, so explicitly keep them
			// Maybe also possible through HTMLPurifier config (http://htmlpurifier.org/live/configdoc/plain.html)
			if (!is_bool($value) && !is_array($value))
			{
				$value = self::$htmlPurifierInstance->purify($value);

				// Allow some special chars
				// Maybe also possible through HTMLPurifier config (http://htmlpurifier.org/live/configdoc/plain.html)
				$value = str_replace('&amp;', '&', $value);
				$value = str_replace('&gt;', '>', $value);
				$value = str_replace('&lt;', '<', $value);
			}
		}

		return $requestBody;
	}
}
