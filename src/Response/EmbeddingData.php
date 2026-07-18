<?php

declare(strict_types=1);

namespace SthAI\Response;

/**
 * One embedding in an embedding response.
 */
final class EmbeddingData
{
    public int $index;

    /**
     * A list of floats for encoding_format "float" (the only format this
     * client requests); the server returns strings for other formats.
     *
     * @var array<int, float|int>|string
     */
    public $embedding;

    /**
     * @param array<int, float|int>|string $embedding
     */
    public function __construct(int $index, $embedding)
    {
        $this->index = $index;
        $this->embedding = $embedding;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $embedding = $data['embedding'] ?? [];

        return new self(
            (int) ($data['index'] ?? 0),
            is_string($embedding) ? $embedding : (is_array($embedding) ? $embedding : [])
        );
    }
}
