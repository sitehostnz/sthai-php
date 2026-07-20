<?php

declare(strict_types=1);

namespace SthAI\Exception;

/**
 * A caller-supplied argument failed one of the client's input guards.
 */
class InputException extends \InvalidArgumentException implements SthAIException
{
}
