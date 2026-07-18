<?php

declare(strict_types=1);

namespace SthAI\Response;

/**
 * Generic token usage summary for any request type.
 */
final class Usage
{
    public int $inputTokens;

    public int $outputTokens;

    public int $cachedTokens;

    public function __construct(int $inputTokens = 0, int $outputTokens = 0, int $cachedTokens = 0)
    {
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;
        $this->cachedTokens = $cachedTokens;
    }
}
