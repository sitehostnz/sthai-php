<?php

declare(strict_types=1);

namespace SthAI\Model;

/**
 * Client-side defaults for a known embedding model: the local chat template
 * used by batched requests, the model's native output dimension, and its
 * recommended task instructions.
 */
final class EmbeddingParams
{
    /**
     * Chat template applied locally for batched embedding requests, since
     * only the plain-input request form batches and it bypasses the
     * server-side template. It matches what the model's own chat template
     * renders for a single-turn request; the open assistant turn is
     * intentional.
     */
    public const QWEN_3_VL_EMBEDDING_TEMPLATE =
        "<|im_start|>system\n{instruction}<|im_end|>\n" .
        "<|im_start|>user\n{text}<|im_end|>\n" .
        '<|im_start|>assistant' . "\n";

    // Recommended task instructions for the embedding model
    public const DOCUMENT_INSTRUCTION = "Represent the user's input.";
    public const QUERY_INSTRUCTION =
        'Given a web search query, retrieve relevant passages that answer the query';

    private ?string $template;

    private ?int $dimensions;

    private ?string $documentInstruction;

    private ?string $queryInstruction;

    private function __construct(
        ?string $template,
        ?int $dimensions,
        ?string $documentInstruction,
        ?string $queryInstruction
    ) {
        $this->template = $template;
        $this->dimensions = $dimensions;
        $this->documentInstruction = $documentInstruction;
        $this->queryInstruction = $queryInstruction;
    }

    /**
     * The known parameters for a model, or null for unknown models.
     */
    public static function forModel(string $model): ?self
    {
        if ($model === EmbeddingModel::QWEN_3_VL_8B) {
            return new self(
                self::QWEN_3_VL_EMBEDDING_TEMPLATE,
                4096,
                self::DOCUMENT_INSTRUCTION,
                self::QUERY_INSTRUCTION
            );
        }

        return null;
    }

    public function getTemplate(): ?string
    {
        return $this->template;
    }

    /**
     * The model's native output dimension; a requested Matryoshka
     * truncation should divide evenly into this.
     */
    public function getDimensions(): ?int
    {
        return $this->dimensions;
    }

    public function getDocumentInstruction(): ?string
    {
        return $this->documentInstruction;
    }

    public function getQueryInstruction(): ?string
    {
        return $this->queryInstruction;
    }

    /**
     * The recommended instruction: the query instruction when embedding
     * search queries, the document instruction otherwise.
     */
    public function getInstruction(bool $query): ?string
    {
        return $query ? $this->queryInstruction : $this->documentInstruction;
    }
}
