<?php

declare(strict_types=1);

namespace SthAI\Response;

/**
 * One choice in an inference response.
 */
final class ResponseChoice
{
    public int $index;

    public ResponseMessage $message;

    public ?string $finishReason;

    public function __construct(int $index, ResponseMessage $message, ?string $finishReason = 'stop')
    {
        $this->index = $index;
        $this->message = $message;
        $this->finishReason = $finishReason;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['index'] ?? 0),
            ResponseMessage::fromArray(is_array($data['message'] ?? null) ? $data['message'] : []),
            isset($data['finish_reason']) ? (string) $data['finish_reason'] : null
        );
    }
}
