<?php

declare(strict_types=1);

namespace SthAI\Exception;

/**
 * Marker interface implemented by every exception this library throws,
 * so callers can catch (SthAIException $e) to handle them all.
 */
interface SthAIException extends \Throwable
{
}
