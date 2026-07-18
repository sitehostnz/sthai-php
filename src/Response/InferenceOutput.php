<?php

declare(strict_types=1);

namespace SthAI\Response;

/**
 * The text (and any reasoning) produced by a chat completion.
 */
final class InferenceOutput
{
    public ?string $text;

    public ?string $reasoning;

    public function __construct(?string $text = null, ?string $reasoning = null)
    {
        $this->text = $text;
        $this->reasoning = $reasoning;
    }
}
