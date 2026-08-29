<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\User;
use Victual\Helpers\Grocycode;
use Victual\Helpers\WebhookRunner;
use Victual\Services\ChoresService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Serves the /api/chores endpoints: current chore overview, chore details,
 * execution tracking/undo, next assignment calculation, label printing and merging.
 */
class ChoresApiController extends BaseApiController
{
	/**
	 * POST /api/chores/executions/calculate-next-assignments - (re)calculates who is
	 * assigned to the next execution, either for the chore given via the numeric body
	 * field chore_id or for all chores when omitted.
	 * Returns 204 on success or a 400 error response.
	 */
	public function CalculateNextExecutionAssignments(Request $request, Response $response, array $args)
	{
		try
		{
			$requestBody = $this->GetParsedAndFilteredRequestBody($request);

			$choreId = null;
			if (array_key_exists('chore_id', $requestBody) && !empty($requestBody['chore_id']) && is_numeric($requestBody['chore_id']))
			{
				$choreId = $requestBody['chore_id'];
			}

			if ($choreId === null)
			{
				$chores = $this->DB->chores();
				foreach ($chores as $chore)
				{
					ChoresService::GetInstance()->CalculateNextExecutionAssignment($chore->id);
				}
			}
			else
			{
				ChoresService::GetInstance()->CalculateNextExecutionAssignment($choreId);
			}

			return $this->EmptyApiResponse($response);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * GET /api/chores/{choreId} - returns details of the given chore (200)
	 * or a 400 error response (e.g. when the chore does not exist).
	 */
	public function ChoreDetails(Request $request, Response $response, array $args)
	{
		try
		{
			return $this->ApiResponse($response, ChoresService::GetInstance()->GetChoreDetails($args['choreId']));
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * GET /api/chores - returns all chores with their current execution status,
	 * filterable via the generic query/limit/offset/order query parameters (200).
	 */
	public function Current(Request $request, Response $response, array $args)
	{
		return $this->FilteredApiResponse($response, ChoresService::GetInstance()->GetCurrent(), $request->getQueryParams());
	}

	/**
	 * POST /api/chores/{choreId}/execute - tracks an execution of the given chore.
	 * Optional body fields: tracked_time (ISO date or datetime, defaults to now),
	 * skipped (boolean, defaults to false), done_by (user id, defaults to the current user).
	 * Requires the CHORE_TRACK_EXECUTION permission; since the check runs inside the
	 * try block, a missing permission is reported as a 400 error response.
	 * Returns the created chores_log row (200) or a 400 error response.
	 */
	public function TrackChoreExecution(Request $request, Response $response, array $args)
	{
		$requestBody = $this->GetParsedAndFilteredRequestBody($request);

		try
		{
			User::CheckPermission($request, User::PERMISSION_CHORE_TRACK_EXECUTION);

			$trackedTime = date('Y-m-d H:i:s');
			if (array_key_exists('tracked_time', $requestBody) && (IsIsoDateTime($requestBody['tracked_time']) || IsIsoDate($requestBody['tracked_time'])))
			{
				$trackedTime = $requestBody['tracked_time'];
			}

			$skipped = false;
			if (array_key_exists('skipped', $requestBody) && filter_var($requestBody['skipped'], FILTER_VALIDATE_BOOLEAN) !== false)
			{
				$skipped = $requestBody['skipped'];
			}

			$doneBy = VICTUAL_USER_ID;
			if (array_key_exists('done_by', $requestBody) && !empty($requestBody['done_by']))
			{
				$doneBy = $requestBody['done_by'];
			}

			if ($doneBy != VICTUAL_USER_ID)
			{
				User::CheckPermission($request, User::PERMISSION_CHORE_TRACK_EXECUTION);
			}

			$choreExecutionId = ChoresService::GetInstance()->TrackChore($args['choreId'], $trackedTime, $doneBy, $skipped);
			return $this->ApiResponse($response, $this->DB->chores_log($choreExecutionId));
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * POST /api/chores/executions/{executionId}/undo - undoes a tracked chore execution.
	 * Requires the CHORE_UNDO_EXECUTION permission (reported as a 400 error response
	 * when missing, as the check runs inside the try block).
	 * Returns 204 on success or a 400 error response.
	 */
	public function UndoChoreExecution(Request $request, Response $response, array $args)
	{
		try
		{
			User::CheckPermission($request, User::PERMISSION_CHORE_UNDO_EXECUTION);

			$this->ApiResponse($response, ChoresService::GetInstance()->UndoChoreExecution($args['executionId']));
			return $this->EmptyApiResponse($response);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * GET /api/chores/{choreId}/printlabel - assembles the label printer webhook payload
	 * (chore name, Grocycode, details plus VICTUAL_LABEL_PRINTER_PARAMS), runs the webhook
	 * server-side when VICTUAL_LABEL_PRINTER_RUN_SERVER is enabled and returns the payload (200)
	 * or a 400 error response.
	 */
	public function ChorePrintLabel(Request $request, Response $response, array $args)
	{
		try
		{
			$choreDetails = (object)ChoresService::GetInstance()->GetChoreDetails($args['choreId']);

			$webhookData = array_merge([
				'chore' => $choreDetails->chore->name,
				'grocycode' => (string)(new Grocycode(Grocycode::CHORE, $args['choreId'])),
				'details' => $choreDetails,
			], VICTUAL_LABEL_PRINTER_PARAMS);

			if (VICTUAL_LABEL_PRINTER_RUN_SERVER)
			{
				(new WebhookRunner())->run(VICTUAL_LABEL_PRINTER_WEBHOOK, $webhookData, VICTUAL_LABEL_PRINTER_HOOK_JSON);
			}

			return $this->ApiResponse($response, $webhookData);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * POST /api/chores/{choreIdToKeep}/merge/{choreIdToRemove} - merges two chores.
	 * Requires the MASTER_DATA_EDIT permission (403 otherwise).
	 * Returns 204 on success or a 400 error response (e.g. non-integer route arguments).
	 */
	public function MergeChores(Request $request, Response $response, array $args)
	{
		User::CheckPermission($request, User::PERMISSION_MASTER_DATA_EDIT);

		try
		{
			if (filter_var($args['choreIdToKeep'], FILTER_VALIDATE_INT) === false || filter_var($args['choreIdToRemove'], FILTER_VALIDATE_INT) === false)
			{
				throw new \Exception('Provided {choreIdToKeep} or {choreIdToRemove} is not a valid integer');
			}

			$this->ApiResponse($response, ChoresService::GetInstance()->MergeChores($args['choreIdToKeep'], $args['choreIdToRemove']));
			return $this->EmptyApiResponse($response);
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}
}
