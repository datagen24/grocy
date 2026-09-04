<?php

namespace Victual\Controllers\Api;

/**
 * Thrown when a request asks for something impossible rather than when something broke:
 * a filter term the entity cannot answer, a sort field it does not have, a body that
 * cannot mean what it says.
 *
 * It exists so that HandleApiCall() can tell those apart from a genuine failure, which
 * one `catch (\Exception)` per method structurally cannot. It extends \Exception, so a
 * method that has not been converted to the helper yet keeps answering exactly as it does
 * today - which is what makes the migration per method rather than big bang. See
 * docs/plans/11-api-error-handling.md.
 */
class EInvalidApiQuery extends \Exception
{
}
