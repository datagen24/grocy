<?php

namespace Victual\Controllers\Api;

/**
 * Thrown when the object a request names does not exist.
 *
 * The distinction this draws is the one the API could not make before: "not found" and
 * "your request was wrong" both arrived as a 400, and which of the two a caller got for a
 * missing object depended on the verb - GET answered 404, PUT and DELETE answered 400.
 *
 * It extends \Exception, so a method that has not been converted to HandleApiCall() yet
 * keeps answering exactly as it does today. See docs/plans/11-api-error-handling.md.
 */
class EObjectNotFound extends \Exception
{
}
