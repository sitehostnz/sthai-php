<?php

declare(strict_types=1);

namespace SthAI\Exception;

/**
 * The server returned a response the client could not use: a success body
 * that didn't decode into the expected shape, or one that decoded but was
 * missing data the caller needs (e.g. no embedding vector returned).
 */
class ResponseException extends \RuntimeException implements SthAIException
{
}
