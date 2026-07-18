<?php

declare(strict_types=1);

namespace SthAI\Exception;

/**
 * A response body could not be decoded or, for structured responses,
 * the generated JSON was cut off or absent.
 */
class ResponseParseException extends \RuntimeException implements SthAIException
{
}
