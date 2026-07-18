<?php

declare(strict_types=1);

namespace SthAI\Response;

/**
 * The raw wire "usage" object on inference and embedding responses;
 * summary() condenses it into the generic Usage summary.
 */
final class UsageInfo
{
    public int $promptTokens;

    public int $totalTokens;

    public ?int $completionTokens;

    public int $cachedTokens;

    public function __construct(
        int $promptTokens = 0,
        int $totalTokens = 0,
        ?int $completionTokens = 0,
        int $cachedTokens = 0
    ) {
        $this->promptTokens = $promptTokens;
        $this->totalTokens = $totalTokens;
        $this->completionTokens = $completionTokens;
        $this->cachedTokens = $cachedTokens;
    }

    /**
     * @param array<string, mixed> $data the decoded wire "usage" object
     */
    public static function fromArray(array $data): self
    {
        $details = $data['prompt_tokens_details'] ?? null;

        return new self(
            (int) ($data['prompt_tokens'] ?? 0),
            (int) ($data['total_tokens'] ?? 0),
            isset($data['completion_tokens']) ? (int) $data['completion_tokens'] : null,
            is_array($details) ? (int) ($details['cached_tokens'] ?? 0) : 0
        );
    }

    /**
     * Condense into the generic input/output/cached token summary.
     */
    public function summary(): Usage
    {
        return new Usage($this->promptTokens, $this->completionTokens ?? 0, $this->cachedTokens);
    }
}
