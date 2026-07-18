<?php

declare(strict_types=1);

namespace SthAI\Transport;

/**
 * A minimal HTTP response: the status code and the raw body.
 */
final class HttpResponse
{
    private int $statusCode;

    private string $body;

    public function __construct(int $statusCode, string $body)
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isOk(): bool
    {
        return $this->statusCode < 400;
    }
}
