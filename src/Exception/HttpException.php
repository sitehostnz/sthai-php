<?php

declare(strict_types=1);

namespace SthAI\Exception;

/**
 * The server answered with an HTTP error status (>= 400).
 */
class HttpException extends \RuntimeException implements SthAIException
{
    private int $statusCode;

    private string $responseBody;

    public function __construct(int $statusCode, string $method, string $endpoint, string $responseBody)
    {
        parent::__construct(sprintf('HTTP %d for %s %s', $statusCode, $method, $endpoint));
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }
}
