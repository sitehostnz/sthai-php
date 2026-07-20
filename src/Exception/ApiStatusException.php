<?php

declare(strict_types=1);

namespace SthAI\Exception;

/**
 * Base class for HTTP error-status responses (>= 400); catch this to
 * handle ClientException and ApiException together. Carries the status
 * code, the raw response body, and - when the server sent a structured
 * error body - the parsed server message and error type.
 */
class ApiStatusException extends \RuntimeException implements SthAIException
{
    private int $statusCode;

    private string $responseBody;

    private ?string $serverMessage;

    private ?string $errorType;

    public function __construct(
        int $statusCode,
        string $responseBody,
        ?string $serverMessage = null,
        ?string $errorType = null
    ) {
        parent::__construct(self::formatMessage($statusCode, $serverMessage, $errorType));
        $this->statusCode = $statusCode;
        $this->responseBody = $responseBody;
        $this->serverMessage = $serverMessage;
        $this->errorType = $errorType;
    }

    /**
     * Build a ClientException (4xx) or ApiException (5xx) from an HTTP
     * error status and body, parsing the OpenAI-style error envelope
     * {"error": {"message": ..., "type": ...}} from the body when present.
     */
    public static function fromResponse(int $statusCode, string $responseBody): self
    {
        [$serverMessage, $errorType] = self::parseErrorEnvelope($responseBody);

        if ($statusCode < 500) {
            return new ClientException($statusCode, $responseBody, $serverMessage, $errorType);
        }

        return new ApiException($statusCode, $responseBody, $serverMessage, $errorType);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }

    public function getServerMessage(): ?string
    {
        return $this->serverMessage;
    }

    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    /**
     * The server message and error type from the body, or [null, null].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function parseErrorEnvelope(string $responseBody): array
    {
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded) || !isset($decoded['error']) || !is_array($decoded['error'])) {
            return [null, null];
        }

        $message = $decoded['error']['message'] ?? null;
        if (!is_string($message)) {
            return [null, null];
        }

        $type = $decoded['error']['type'] ?? null;

        return [$message, is_string($type) ? $type : null];
    }

    private static function formatMessage(int $statusCode, ?string $serverMessage, ?string $errorType): string
    {
        if ($serverMessage !== null && $serverMessage !== '') {
            $suffix = ($errorType !== null && $errorType !== '') ? sprintf(' (%s)', $errorType) : '';

            return sprintf('%d: %s%s', $statusCode, $serverMessage, $suffix);
        }

        return sprintf('%d: HTTP error', $statusCode);
    }
}
