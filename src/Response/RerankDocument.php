<?php

declare(strict_types=1);

namespace SthAI\Response;

/**
 * The document echoed back with a rerank result: plain text, or content
 * parts for multimodal input.
 */
final class RerankDocument
{
    public ?string $text;

    /** @var array<int, array<string, mixed>>|null */
    public ?array $multiModal;

    /**
     * @param array<int, array<string, mixed>>|null $multiModal
     */
    public function __construct(?string $text = null, ?array $multiModal = null)
    {
        $this->text = $text;
        $this->multiModal = $multiModal;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['text']) ? (string) $data['text'] : null,
            is_array($data['multi_modal'] ?? null) ? $data['multi_modal'] : null
        );
    }
}
