<?php

declare(strict_types=1);

namespace SthAI\Response;

/**
 * One model from the models list endpoint.
 */
final class ModelCard
{
    public string $id;

    public int $created;

    public string $ownedBy;

    public ?int $maxModelLen;

    /** @var array<string, mixed> */
    private array $raw;

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        string $id,
        int $created = 0,
        string $ownedBy = 'vllm',
        ?int $maxModelLen = null,
        array $raw = []
    ) {
        $this->id = $id;
        $this->created = $created;
        $this->ownedBy = $ownedBy;
        $this->maxModelLen = $maxModelLen;
        $this->raw = $raw;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (int) ($data['created'] ?? 0),
            (string) ($data['owned_by'] ?? 'vllm'),
            isset($data['max_model_len']) ? (int) $data['max_model_len'] : null,
            $data
        );
    }

    /**
     * The complete decoded model card as received on the wire.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }
}
