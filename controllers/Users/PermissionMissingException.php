<?php

namespace Grocy\Controllers\Users;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpForbiddenException;
use Throwable;

/**
 * HTTP 403 exception thrown (e.g. by User::CheckPermission()) when the current
 * user lacks a required permission; the message names the missing permission.
 */
class PermissionMissingException extends HttpForbiddenException
{
	/**
	 * @param string $permission Name of the missing permission (one of the User::PERMISSION_* constants)
	 */
	public function __construct(ServerRequestInterface $request, string $permission, ?Throwable $previous = null)
	{
		parent::__construct($request, 'Permission missing: ' . $permission, $previous);
	}
}
