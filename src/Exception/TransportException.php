<?php

declare(strict_types=1);

namespace SthAI\Exception;

/**
 * The HTTP request could not be completed at the transport level
 * (connection failure, timeout, TLS error, and so on).
 */
class TransportException extends \RuntimeException implements SthAIException
{
}
