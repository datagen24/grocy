<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\BaseController;
use Victual\Services\DatabaseService;
use LessQL\Result;
use Psr\Http\Message\ResponseInterface as Response;
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
	public function FilteredApiResponse(Response $response, Result $data, array $query)
	{
		$data = $this->QueryData($data, $query);
		return $this->ApiResponse($response, $data);
	}

	/**
	 * Applies the generic list query parameters to a LessQL result:
	 * query[] (filter conditions, see FilterData), limit/offset (pagination)
	 * and order ("field" or "field:asc|desc"; throws on any other sort order).
	 */
	protected function QueryData(Result $data, array $query)
	{
		if (isset($query['query']))
		{
			$data = $this->FilterData($data, $query['query']);
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

			if (count($parts) == 1)
			{
				$data = $data->orderBy($parts[0]);
			}
			else
			{
				if ($parts[1] != 'asc' && $parts[1] != 'desc')
				{
					throw new \Exception('Invalid sort order ' . $parts[1]);
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
	protected function FilterData(Result $data, array $query): Result
	{
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
				throw new \Exception('Invalid query');
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
					$data = $data->where($matches['field'] . ' LIKE ?', '%' . $matches['value'] . '%');
					break;
				case '!~':
					$data = $data->where($matches['field'] . ' NOT LIKE ?', '%' . $matches['value'] . '%');
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
