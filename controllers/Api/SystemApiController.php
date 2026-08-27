<?php

namespace Grocy\Controllers\Api;

use Grocy\Services\ApplicationService;
use Grocy\Services\DatabaseService;
use Grocy\Services\LocalizationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the /api/system endpoints: instance info, server time, database change
 * detection, exposed configuration and localization strings.
 */
class SystemApiController extends BaseApiController
{
	/**
	 * GET /api/system/config - returns all GROCY_* constants as a key/value map with
	 * the GROCY_ prefix stripped; internal constants (GROCY_AUTHENTICATED, GROCY_DATAPATH,
	 * GROCY_IS_EMBEDDED_INSTALL, GROCY_USER_ID) are excluded. 400 error response on failure.
	 */
	public function GetConfig(Request $request, Response $response, array $args)
	{
		try
		{
			$constants = get_defined_constants();

			// Some GROCY_* constants are not really config settings and therefore should not be exposed
			unset($constants['GROCY_AUTHENTICATED'], $constants['GROCY_DATAPATH'], $constants['GROCY_IS_EMBEDDED_INSTALL'], $constants['GROCY_USER_ID']);

			$returnArray = [];

			foreach ($constants as $constant => $value)
			{
				if (substr($constant, 0, 6) === 'GROCY_')
				{
					$returnArray[substr($constant, 6)] = $value;
				}
			}

			return $this->ApiResponse($response, $returnArray);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * GET /api/system/db-changed-time - returns { "changed_time": string }, the time
	 * the database was last modified (used by clients for change polling).
	 */
	public function GetDbChangedTime(Request $request, Response $response, array $args)
	{
		return $this->ApiResponse($response, [
			'changed_time' => DatabaseService::GetInstance()->GetDbChangedTime()
		]);
	}

	/**
	 * GET /api/system/info - returns information about the installed grocy version and environment.
	 */
	public function GetSystemInfo(Request $request, Response $response, array $args)
	{
		return $this->ApiResponse($response, ApplicationService::GetInstance()->GetSystemInfo());
	}

	/**
	 * GET /api/system/time - returns the current server time; the optional integer
	 * query parameter "offset" (seconds) is applied to it. Returns a 400 error
	 * response when offset is not a valid integer.
	 */
	public function GetSystemTime(Request $request, Response $response, array $args)
	{
		try
		{
			$offset = 0;
			$params = $request->getQueryParams();
			if (isset($params['offset']))
			{
				if (filter_var($params['offset'], FILTER_VALIDATE_INT) === false)
				{
					throw new \Exception('Query parameter "offset" is not a valid integer');
				}

				$offset = $params['offset'];
			}

			return $this->ApiResponse($response, ApplicationService::GetInstance()->GetSystemTime($offset));
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * POST /api/system/log-missing-localization - adds the body field "text" to the
	 * localization POT file when it is not translated yet. Only active when
	 * GROCY_MODE is "dev" (204 on success, 400 on error); in any other mode the
	 * method returns nothing at all.
	 */
	public function LogMissingLocalization(Request $request, Response $response, array $args)
	{
		if (GROCY_MODE === 'dev')
		{
			try
			{
				$requestBody = $this->GetParsedAndFilteredRequestBody($request);

				LocalizationService::GetInstance()->CheckAndAddMissingTranslationToPot($requestBody['text']);
				return $this->EmptyApiResponse($response);
			}
			catch (\Exception $ex)
			{
				return $this->GenericErrorResponse($response, $ex->getMessage());
			}
		}
	}

	/**
	 * GET /api/system/localization-strings - returns the localization strings of the
	 * current language as JSON, with a 30 day Cache-Control header.
	 */
	public function GetLocalizationStrings(Request $request, Response $response, array $args)
	{
		return $this->ApiResponse($response, json_decode(LocalizationService::GetInstance()->GetPoAsJsonString()), true);
	}
}
