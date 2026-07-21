<?php

declare(strict_types=1);

namespace SthAI\Response;

use SthAI\Exception\ResponseException;

/**
 * The full response from an embedding request.
 */
final class EmbeddingResponse
{
    public string $id;

    /** @var EmbeddingData[] */
    public array $data;

    public UsageInfo $usageInfo;

    /** @var array<string, mixed> */
    private array $raw;

    /**
     * @param EmbeddingData[]      $data
     * @param array<string, mixed> $raw
     */
    public function __construct(string $id, array $data, UsageInfo $usageInfo, array $raw = [])
    {
        $this->id = $id;
        $this->data = $data;
        $this->usageInfo = $usageInfo;
        $this->raw = $raw;
    }

    /**
     * @param array<string, mixed> $payload the decoded response payload
     */
    public static function fromArray(array $payload): self
    {
        $data = [];
        foreach (is_array($payload['data'] ?? null) ? $payload['data'] : [] as $entry) {
            if (is_array($entry)) {
                $data[] = EmbeddingData::fromArray($entry);
            }
        }

        return new self(
            (string) ($payload['id'] ?? ''),
            $data,
            UsageInfo::fromArray(is_array($payload['usage'] ?? null) ? $payload['usage'] : []),
            $payload
        );
    }

    /**
     * Token usage summary; embeddings only consume input tokens.
     */
    public function usage(): Usage
    {
        return $this->usageInfo->summary();
    }

    /**
     * The embeddings in input order as float vectors. Throws
     * ResponseException when the response carries no embeddings, or for
     * non-float encoding formats (where the server returns strings; the
     * raw entries stay available on data).
     *
     * @return array<int, array<int, float|int>>
     */
    public function output(): array
    {
        if ($this->data === []) {
            throw new ResponseException('server returned no embedding data');
        }
        $sorted = $this->data;
        usort($sorted, static function (EmbeddingData $a, EmbeddingData $b): int {
            return $a->index <=> $b->index;
        });

        $embeddings = [];
        foreach ($sorted as $entry) {
            if (is_string($entry->embedding)) {
                throw new ResponseException('expected a float embedding, got an encoded string');
            }
            $embeddings[] = $entry->embedding;
        }

        return $embeddings;
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
