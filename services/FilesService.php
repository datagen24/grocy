<?php

namespace Victual\Services;

use Gumlet\ImageResize;
use Gumlet\ImageResizeException;

/**
 * Manages uploaded files (product pictures, equipment manuals, ...) below
 * <data path>/storage, organized in per purpose group folders, including cached
 * downscaled versions of images. In demo/prerelease mode the storage path gets a per
 * demo instance suffix, mirroring the separate demo databases.
 */
class FilesService extends BaseService
{
	/**
	 * Value of the "force_serve_as" request option: serve the file inline as a
	 * picture instead of as a download.
	 */
	const FILE_SERVE_TYPE_PICTURE = 'picture';

	public function __construct()
	{
		parent::__construct();

		$this->StoragePath = VICTUAL_DATAPATH . '/storage';
		if (!file_exists($this->StoragePath))
		{
			mkdir($this->StoragePath);
		}

		if (VICTUAL_MODE === 'demo' || VICTUAL_MODE === 'prerelease')
		{
			$dbSuffix = VICTUAL_DEFAULT_LOCALE;
			if (defined('VICTUAL_DEMO_DB_SUFFIX'))
			{
				$dbSuffix = VICTUAL_DEMO_DB_SUFFIX;
			}

			$this->StoragePath = $this->StoragePath . '/' . $dbSuffix;
			if (!file_exists($this->StoragePath))
			{
				mkdir($this->StoragePath);
			}
		}
	}

	private $StoragePath;

	/**
	 * Returns the path of a downscaled copy of the given image, created on first use
	 * and cached next to the original as "<name>__downscaledto<h>x<w>.<ext>".
	 * Falls back to the original file when resizing fails.
	 *
	 * @param string $group Group folder name, e.g. "productpictures"
	 * @param string $fileName
	 * @param int|null $bestFitHeight Maximum height in pixels, or null for no height limit
	 * @param int|null $bestFitWidth Maximum width in pixels, or null for no width limit
	 * @return string Absolute path of the file to serve
	 */
	public function DownscaleImage($group, $fileName, $bestFitHeight = null, $bestFitWidth = null)
	{
		$filePath = $this->GetFilePath($group, $fileName);
		$fileNameWithoutExtension = pathinfo($filePath, PATHINFO_FILENAME);
		$fileExtension = pathinfo($filePath, PATHINFO_EXTENSION);

		$fileNameDownscaled = $fileNameWithoutExtension . '__downscaledto' . ($bestFitHeight ? $bestFitHeight : 'auto') . 'x' . ($bestFitWidth ? $bestFitWidth : 'auto') . '.' . $fileExtension;
		$filePathDownscaled = $this->GetFilePath($group, $fileNameDownscaled);

		try
		{
			if (!file_exists($filePathDownscaled))
			{
				$image = new ImageResize($filePath);

				if ($bestFitHeight !== null && $bestFitWidth !== null)
				{
					$image->resizeToBestFit($bestFitWidth, $bestFitHeight);
				}
				elseif ($bestFitHeight !== null)
				{
					$image->resizeToHeight($bestFitHeight);
				}
				elseif ($bestFitWidth !== null)
				{
					$image->resizeToWidth($bestFitWidth);
				}

				$image->save($filePathDownscaled);
			}
		}
		catch (ImageResizeException $ex)
		{
			return $filePath;
		}

		return $filePathDownscaled;
	}

	/**
	 * Deletes the given file; for images, also all of its cached "__downscaledto" copies.
	 *
	 * @param string $group Group folder name
	 * @param string $fileName
	 */
	public function DeleteFile($group, $fileName)
	{
		$filePath = $this->GetFilePath($group, $fileName);

		if (file_exists($filePath))
		{
			$fileNameWithoutExtension = pathinfo($filePath, PATHINFO_FILENAME);

			// Then the file is an image
			if (getimagesize($filePath) !== false)
			{
				// Also delete all corresponding "__downscaledto" files when deleting an image
				$groupFolderPath = $this->StoragePath . '/' . $group;
				$files = scandir($groupFolderPath);
				foreach ($files as $file)
				{
					if (string_starts_with($file, $fileNameWithoutExtension . '__downscaledto'))
					{
						unlink($this->GetFilePath($group, $file));
					}
				}
			}

			unlink($filePath);
		}
	}

	/**
	 * Returns the absolute path for a file in the given group folder, creating the
	 * folder when needed (the file itself may or may not exist).
	 *
	 * @param string $group Group folder name
	 * @param string $fileName
	 * @return string
	 */
	public function GetFilePath($group, $fileName)
	{
		$groupFolderPath = $this->StoragePath . '/' . $group;

		if (!file_exists($groupFolderPath))
		{
			mkdir($groupFolderPath);
		}

		return $groupFolderPath . '/' . $fileName;
	}
}
