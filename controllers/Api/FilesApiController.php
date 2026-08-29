<?php

namespace Victual\Controllers\Api;

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
 */
class FilesApiController extends BaseApiController
{
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
		catch (\Exception $ex)
		{
			return $this->GenericErrorResponse($response, $ex->getMessage());
		}
	}

	/**
	 * GET /api/files/{group}/{fileName} - streams the given file inline with its detected
	 * MIME type and a 30 day Cache-Control header. {fileName} may also be two BASE64
	 * encoded names joined by "_" (actual file name + download file name). Query
	 * parameters force_serve_as=picture with optional best_fit_height/best_fit_width
	 * serve a downscaled image variant. Any failure (including an invalid group or
	 * filename) results in a 404 HttpNotFoundException.
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
				$response = $response->withHeader('Cache-Control', 'max-age=2592000');
				$response = $response->withHeader('Content-Type', mime_content_type($filePath));
				$response = $response->withHeader('Content-Disposition', 'inline; filename="' . $fileNameOutput . '"');
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
	 * "xb" mode). Returns 204 on success or a 400 error response.
	 */
	public function UploadFile(Request $request, Response $response, array $args)
	{
		try
		{
			if (!in_array($args['group'], $this->GetOpenApispec()->components->schemas->FileGroups->enum))
			{
				throw new \Exception('Invalid file group');
			}

			$fileName = $this->CheckFileName($args['fileName']);

			$fileHandle = fopen(FilesService::GetInstance()->GetFilePath($args['group'], $fileName), 'xb');
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

			return $this->EmptyApiResponse($response);
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
