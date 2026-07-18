<?php

declare(strict_types=1);

namespace SthAI\Response;

/**
 * The full response from a chat completion request. Only the fields the
 * client surfaces are hydrated; the complete decoded payload remains
 * available through toArray().
 */
final class InferenceResponse
{
    public string $id;

    public string $model;

    /** @var ResponseChoice[] */
    public array $choices;

    public UsageInfo $usageInfo;

    /** @var array<string, mixed> */
    private array $raw;

    /**
     * @param ResponseChoice[]     $choices
     * @param array<string, mixed> $raw
     */
    public function __construct(
        string $id,
        string $model,
        array $choices,
        UsageInfo $usageInfo,
        array $raw = []
    ) {
        $this->id = $id;
        $this->model = $model;
        $this->choices = $choices;
        $this->usageInfo = $usageInfo;
        $this->raw = $raw;
    }

    /**
     * @param array<string, mixed> $data the decoded response payload
     */
    public static function fromArray(array $data): self
    {
        $choices = [];
        foreach (is_array($data['choices'] ?? null) ? $data['choices'] : [] as $choice) {
            if (is_array($choice)) {
                $choices[] = ResponseChoice::fromArray($choice);
            }
        }

        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['model'] ?? ''),
            $choices,
            UsageInfo::fromArray(is_array($data['usage'] ?? null) ? $data['usage'] : []),
            $data
        );
    }

    /**
     * Token usage summary: input, output, and cached prompt tokens.
     */
    public function usage(): Usage
    {
        return $this->usageInfo->summary();
    }

    /**
     * The first choice's response text and reasoning, if any.
     */
    public function output(): InferenceOutput
    {
        if ($this->choices === []) {
            return new InferenceOutput();
        }
        $message = $this->choices[0]->message;

        return new InferenceOutput($message->content, $message->reasoning);
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
