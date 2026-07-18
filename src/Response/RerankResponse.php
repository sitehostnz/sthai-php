<?php

declare(strict_types=1);

namespace SthAI\Response;

/**
 * The full response from a rerank request: a Cohere-compatible results
 * array sorted by relevance score descending.
 */
final class RerankResponse
{
    public string $id;

    public string $model;

    /** @var RerankResult[] */
    public array $results;

    /** Reranking costs input tokens only. */
    public int $promptTokens;

    /** @var array<string, mixed> */
    private array $raw;

    /**
     * @param RerankResult[]       $results
     * @param array<string, mixed> $raw
     */
    public function __construct(
        string $id,
        string $model,
        array $results,
        int $promptTokens = 0,
        array $raw = []
    ) {
        $this->id = $id;
        $this->model = $model;
        $this->results = $results;
        $this->promptTokens = $promptTokens;
        $this->raw = $raw;
    }

    /**
     * @param array<string, mixed> $data the decoded response payload
     */
    public static function fromArray(array $data): self
    {
        $results = [];
        foreach (is_array($data['results'] ?? null) ? $data['results'] : [] as $result) {
            if (is_array($result)) {
                $results[] = RerankResult::fromArray($result);
            }
        }
        $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];

        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['model'] ?? ''),
            $results,
            (int) ($usage['prompt_tokens'] ?? 0),
            $data
        );
    }

    /**
     * Token usage summary; reranking only consumes input tokens.
     */
    public function usage(): Usage
    {
        return new Usage($this->promptTokens);
    }

    /**
     * The rerank results, sorted by relevance score descending.
     *
     * @return RerankResult[]
     */
    public function output(): array
    {
        return $this->results;
    }

    /**
     * The complete decoded response payload as received on the wire.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
