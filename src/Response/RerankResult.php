<?php

declare(strict_types=1);

namespace SthAI\Response;

/**
 * One scored document from a rerank request. index refers to the
 * document's position in the request's documents list.
 */
final class RerankResult
{
    public int $index;

    public RerankDocument $document;

    public float $relevanceScore;

    public function __construct(int $index, RerankDocument $document, float $relevanceScore)
    {
        $this->index = $index;
        $this->document = $document;
        $this->relevanceScore = $relevanceScore;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['index'] ?? 0),
            RerankDocument::fromArray(is_array($data['document'] ?? null) ? $data['document'] : []),
            (float) ($data['relevance_score'] ?? 0.0)
        );
    }
}
