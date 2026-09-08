<?php

declare(strict_types=1);

namespace App\Cleaning;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Structural pipeline validation failure — always a 400 (malformed
 * request), distinct from a 422 that means "well-formed but the data
 * engine couldn't apply it to the actual data". Extends
 * BadRequestHttpException so App\EventListener\ApiExceptionListener
 * converts it to a JSON 400 automatically, same as any other HTTP
 * exception in this codebase.
 */
final class PipelineValidationException extends BadRequestHttpException
{
}
