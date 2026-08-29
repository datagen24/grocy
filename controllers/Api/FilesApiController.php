<?php

namespace Victual\Controllers\Api;

use Victual\Controllers\Users\PermissionMissingException;
use Victual\Controllers\Users\User;
use Victual\Services\FilesService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Stream;

/**
 * Serves the /api/files endpoints for uploading, serving and deleting files
 * (e.g. product or recipe pictures). The {group} route argument must be one of
 * the FileGroups defined in the OpenAPI spec and the {fileName} route argument
 * is expected to be BASE64 encoded.
 *
 * Writing is gated by the permission that governs the records the group belongs to,
 * and both the extension and, for images, the content are checked before a file is
 * kept. Serving answers with a type from a fixed list or hands the file over as a
 * download, never with a sniffed type inline. Sweep finding S2.
 */
class FilesApiController extends BaseApiController
{
	/**
	 * Extensions that may be stored as an image, and are served inline when their
	 * content really is one.
	 */
	const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

	/**
	 * Which permission lets a caller upload to or delete from a group. Holding any one
	 * of the listed permissions is enough. These mirror the permission that governs the
	 * record the file hangs off: equipment manuals follow equipment, recipe pictures
	 * follow recipes, and userfield files follow the userfield write path, which
	 * GenericEntityApiController::SetUserfields gates on MASTER_DATA_EDIT.
	 */
	const GROUP_WRITE_PERMISSIONS = [
		'productpictures' => [User::PERMISSION_MASTER_DATA_EDIT],
		'recipepictures' => [User::PERMISSION_RECIPES],
		'equipmentmanuals' => [User::PERMISSION_EQUIPMENT],
		'userfiles' => [User::PERMISSION_MASTER_DATA_EDIT],
		// The route carries no user id, so this is "may edit some user" rather than
		// "may edit this user". Binding a picture to its owner needs the id in the
		// request and belongs with the user permission work in plan 15 / sweep S6.
		'userpictures' => [User::PERMISSION_USERS_EDIT, User::PERMISSION_USERS_EDIT_SELF]
	];

	/**
	 * What may be stored in each group. An upload of anything else is refused rather
	 * than stored and dealt with at serving time: a file the browser will not be asked
	 * to render is still a file every other user can be handed a link to.
	 */
	const GROUP_ALLOWED_EXTENSIONS = [
		'productpictures' => self::IMAGE_EXTENSIONS,
		'recipepictures' => self::IMAGE_EXTENSIONS,
		'userpictures' => self::IMAGE_EXTENSIONS,
		'equipmentmanuals' => ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'],
		// Userfields of type file take whatever a household attaches to a record. The
		// list is deliberately about document formats, and deliberately without the
		// ones a browser executes in this origin - svg, html, xhtml, xml, js.
		'userfiles' => [
			'pdf', 'txt', 'md', 'csv', 'rtf',
			'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
			'odt', 'ods', 'odp', 'zip',
			'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic'
		]
	];

	/**
	 * Content types that are served inline. Everything else is a download.
	 *
	 * Deliberately without image/svg+xml, which is a script document. PDF is here
	 * because the equipment manual is shown in an <embed> and a PDF cannot reach the
	 * page that embeds it; it is served with this exact type and X-Content-Type-Options,
	 * so it is never treated as anything else.
	 */
	const INLINE_SERVED_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];

	/**
	 * Throws unless the caller may write to the given group.
	 *
	 * @throws PermissionMissingException
	 */
	protected function CheckGroupWritePermission(Request $request, string $group): void
	{
		$permissions = self::GROUP_WRITE_PERMISSIONS[$group] ?? null;
		if ($permissions === null)
		{
			// A group in the OpenAPI enum with no entry here is a mistake, not a free pass
			throw new \Exception('No write permission is defined for this file group');
		}

		foreach ($permissions as $permission)
		{
			if (User::HasPermissions($permission))
			{
				return;
			}
		}

		throw new PermissionMissingException($request, $permissions[0]);
	}

	/**
	 * Throws unless the given file name carries an extension the group accepts.
	 */
	protected function CheckFileExtension(string $group, string $fileName): void
	{
		$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

		if (!in_array($extension, self::GROUP_ALLOWED_EXTENSIONS[$group] ?? []))
		{
			throw new \Exception('File type not allowed for this file group');
		}
	}

	/**
	 * DELETE /api/files/{group}/{fileName} - deletes the given file.
	 * Returns 204 on success or a 400 error response (invalid file group or filename).
	 */
	public function DeleteFile(Request $request, Response $response, array $args)
	{
		try
		{
			if (!in_array($args['group'], $this->GetOpenApispec()->components->schemas->FileGroups->enum))
			{
				throw new \Exception('Invalid file group');
			}

			$this->CheckGroupWritePermission($request, $args['group']);

			if (IsValidFileName(base64_decode($args['fileName'])))
			{
				$fileName = base64_decode($args['fileName']);
			}
			else
			{
				throw new \Exception('Invalid filename');
			}

			FilesService::GetInstance()->DeleteFile($args['group'], $fileName);

			return $this->EmptyApiResponse($response);
		}
		catch (PermissionMissingException $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), $ex->getCode());
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * GET /api/files/{group}/{fileName} - streams the given file with a type from
	 * INLINE_SERVED_TYPES when its content is one of those, and as a download otherwise,
	 * with a 30 day Cache-Control header. {fileName} may also be two BASE64 encoded names
	 * joined by "_" (actual file name + download file name). Query parameters
	 * force_serve_as=picture with optional best_fit_height/best_fit_width serve a
	 * downscaled image variant. Any failure (including an invalid group or filename)
	 * results in a 404 HttpNotFoundException.
	 */
	public function ServeFile(Request $request, Response $response, array $args)
	{
		try
		{
			if (!in_array($args['group'], $this->GetOpenApispec()->components->schemas->FileGroups->enum))
			{
				throw new \Exception('Invalid file group');
			}

			if (str_contains($args['fileName'], '_'))
			{
				$fileInfo = explode('_', $args['fileName']);
				$fileName = $this->CheckFileName($fileInfo[0]);
				$fileNameOutput = $this->CheckFileName($fileInfo[1]);
				$filePath = $this->GetFilePath($args['group'], $fileName, $request->getQueryParams());
			}
			else
			{
				$fileName = $this->CheckFileName($args['fileName']);
				$fileNameOutput = $fileName;
				$filePath = $this->GetFilePath($args['group'], $fileName, $request->getQueryParams());
			}

			if (file_exists($filePath))
			{
				$mimeType = mime_content_type($filePath);

				if (in_array($mimeType, self::INLINE_SERVED_TYPES))
				{
					$disposition = 'inline';
				}
				else
				{
					// Whatever this turned out to be, it is handed over as bytes to save,
					// not as a document to render in this origin
					$disposition = 'attachment';
					$mimeType = 'application/octet-stream';
				}

				$response = $response->withHeader('Cache-Control', 'max-age=2592000');
				$response = $response->withHeader('Content-Type', $mimeType);
				// Keeps the browser on the type above rather than sniffing the body
				$response = $response->withHeader('X-Content-Type-Options', 'nosniff');
				// RFC 5987 encoded, so a quote in the name cannot end the parameter
				$response = $response->withHeader('Content-Disposition', $disposition . '; filename*=UTF-8\'\'' . rawurlencode($fileNameOutput));
				return $response->withBody(new Stream(fopen($filePath, 'rb')));
			}
			else
			{
				throw new HttpNotFoundException($request, 'File not found');
			}
		}
		catch (\Exception $ex)
		{
			throw new HttpNotFoundException($request, $ex->getMessage(), $ex);
		}
	}

	/**
	 * PUT /api/files/{group}/{fileName} - stores the raw request body as a new file,
	 * written to disk in 1 MB chunks. Fails when the file already exists (exclusive
	 * "xb" mode), when the extension is not one the group accepts, or when a file
	 * stored under an image extension is not an image. Returns 204 on success or a
	 * 400 error response.
	 */
	public function UploadFile(Request $request, Response $response, array $args)
	{
		try
		{
			if (!in_array($args['group'], $this->GetOpenApispec()->components->schemas->FileGroups->enum))
			{
				throw new \Exception('Invalid file group');
			}

			$this->CheckGroupWritePermission($request, $args['group']);

			$fileName = $this->CheckFileName($args['fileName']);
			$this->CheckFileExtension($args['group'], $fileName);

			$filePath = FilesService::GetInstance()->GetFilePath($args['group'], $fileName);
			$fileHandle = fopen($filePath, 'xb');
			if ($fileHandle === false)
			{
				throw new \Exception("Error while creating file $fileName");
			}

			// Save the file to disk in chunks of 1 MB
			$requestBody = $request->getBody();
			while ($data = $requestBody->read(1048576))
			{
				if (fwrite($fileHandle, $data) === false)
				{
					throw new \Exception("Error while writing file $fileName");
				}
			}

			if (fclose($fileHandle) === false)
			{
				throw new \Exception("Error while closing file $fileName");
			}

			// A name ending in .png that holds a script is the whole of the problem, so
			// what was stored has to be what the extension said it was
			if (in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS) && getimagesize($filePath) === false)
			{
				unlink($filePath);
				throw new \Exception('File is not a valid image');
			}

			return $this->EmptyApiResponse($response);
		}
		catch (PermissionMissingException $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage(), $ex->getCode());
		}
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * BASE64 decodes $fileName and returns it; throws when the decoded name is not a valid filename.
	 */
	protected function CheckFileName(string $fileName)
	{
		if (IsValidFileName(base64_decode($fileName)))
		{
			$fileName = base64_decode($fileName);
		}
		else
		{
			throw new \Exception('Invalid filename');
		}

		return $fileName;
	}

	/**
	 * Resolves the on-disk path for the given file; when the query parameters request
	 * force_serve_as=picture, a downscaled variant honoring best_fit_height/best_fit_width
	 * is created and its path returned instead.
	 */
	protected function GetFilePath(string $group, string $fileName, array $queryParams = [])
	{
		$forceServeAs = null;
		if (isset($queryParams['force_serve_as']) && !empty($queryParams['force_serve_as']))
		{
			$forceServeAs = $queryParams['force_serve_as'];
		}

		if ($forceServeAs == FilesService::FILE_SERVE_TYPE_PICTURE)
		{
			$bestFitHeight = null;
			if (isset($queryParams['best_fit_height']) && !empty($queryParams['best_fit_height']) && is_numeric($queryParams['best_fit_height']))
			{
				$bestFitHeight = $queryParams['best_fit_height'];
			}

			$bestFitWidth = null;
			if (isset($queryParams['best_fit_width']) && !empty($queryParams['best_fit_width']) && is_numeric($queryParams['best_fit_width']))
			{
				$bestFitWidth = $queryParams['best_fit_width'];
			}

			$filePath = FilesService::GetInstance()->DownscaleImage($group, $fileName, $bestFitHeight, $bestFitWidth);
		}
		else
		{
			$filePath = FilesService::GetInstance()->GetFilePath($group, $fileName);
		}

		return $filePath;
	}
}
