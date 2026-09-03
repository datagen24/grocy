<?php

namespace Victual\Services\Storage;

/**
 * Thrown by a storage backend when a source exceeds the effective upload limit.
 *
 * Its own class rather than a plain \Exception because the files API has to answer it
 * with 413 rather than the 400 every other upload failure gets, and a message match
 * would be the wrong way to tell them apart. Sweep finding S10.
 */
class FileTooLargeException extends \Exception
{
}
