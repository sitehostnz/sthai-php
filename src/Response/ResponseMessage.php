<?php

declare(strict_types=1);

namespace SthAI\Response;

/**
 * The assistant message inside a response choice. Tool calls are kept as
 * the raw decoded array; the platform's models are used without tools in
 * this client version.
 */
final class ResponseMessage
{
    public string $role;

    public ?string $content;

    /** Reasoning output from thinking models (vLLM-specific). */
    public ?string $reasoning;

    /** @var array<int, array<string, mixed>> */
    public array $toolCalls;

    /**
     * @param array<int, array<string, mixed>> $toolCalls
     */
    public function __construct(
        string $role,
        ?string $content = null,
        ?string $reasoning = null,
        array $toolCalls = []
    ) {
        $this->role = $role;
        $this->content = $content;
        $this->reasoning = $reasoning;
        $this->toolCalls = $toolCalls;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['role'] ?? ''),
            isset($data['content']) ? (string) $data['content'] : null,
            isset($data['reasoning']) ? (string) $data['reasoning'] : null,
            is_array($data['tool_calls'] ?? null) ? $data['tool_calls'] : []
        );
    }
}
