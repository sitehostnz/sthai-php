<?php

declare(strict_types=1);

namespace SthAI;

use SthAI\Exception\HttpException;
use SthAI\Exception\InvalidArgumentException;
use SthAI\Exception\ResponseParseException;
use SthAI\Response\ModelCard;
use SthAI\Transport\CurlTransport;
use SthAI\Transport\HttpResponse;
use SthAI\Transport\TransportInterface;

/**
 * The main client class for interacting with the SthAI API.
 *
 * All optional constructor and method parameters are ordinary PHP
 * parameters, so PHP 8.0+ callers can use named arguments; on PHP 7.4
 * they are positional.
 */
final class Client
{
    public const INFERENCE_ENDPOINT = '/v1/chat/completions';
    public const EMBEDDING_ENDPOINT = '/v1/embeddings';
    public const RERANKING_ENDPOINT = '/v1/rerank';
    public const MODELS_ENDPOINT = '/v1/models';
    public const HEALTH_ENDPOINT = '/health';

    public const SESSION_PIN_HEADER = 'X-Session-Id';

    private string $fqdn;

    private bool $secure;

    private string $apiKey;

    private ?string $sessionPin;

    private TransportInterface $transport;

    /**
     * Create a client. apiKey defaults to the STHAI_KEY environment
     * variable. sessionPin (or autoSession=true to generate one) pins
     * requests to a server session.
     */
    public function __construct(
        ?string $apiKey = null,
        string $fqdn = 'ai.sitehost.nz',
        bool $secure = true,
        ?string $sessionPin = null,
        bool $autoSession = false,
        ?TransportInterface $transport = null
    ) {
        if ($apiKey === null) {
            $envKey = getenv('STHAI_KEY');
            $apiKey = $envKey === false ? '' : $envKey;
        }
        if ($apiKey === '') {
            throw new InvalidArgumentException('apiKey is required (or set the STHAI_KEY environment variable)');
        }

        $this->apiKey = $apiKey;
        $this->fqdn = $fqdn;
        $this->secure = $secure;
        $this->sessionPin = $sessionPin;
        if ($this->sessionPin === null && $autoSession) {
            $this->sessionPin = bin2hex(random_bytes(24));
        }
        $this->transport = $transport ?? new CurlTransport();
    }

    /**
     * Check the server's health status.
     */
    public function healthy(): bool
    {
        return $this->request('GET', self::HEALTH_ENDPOINT)->isOk();
    }

    /**
     * List the models available on the server.
     *
     * @return ModelCard[]
     */
    public function models(): array
    {
        $decoded = $this->requestJson('GET', self::MODELS_ENDPOINT);

        $cards = [];
        foreach (is_array($decoded['data'] ?? null) ? $decoded['data'] : [] as $entry) {
            if (is_array($entry)) {
                $cards[] = ModelCard::fromArray($entry);
            }
        }

        return $cards;
    }

    /**
     * The server's base URL.
     */
    private function serverUrl(): string
    {
        return ($this->secure ? 'https' : 'http') . '://' . $this->fqdn;
    }

    /**
     * The absolute URL for an endpoint path.
     */
    private function endpointUrl(string $endpoint): string
    {
        if (strncmp($endpoint, '/', 1) !== 0) {
            $endpoint = '/' . $endpoint;
        }

        return $this->serverUrl() . $endpoint;
    }

    /**
     * The auth (and session pin) headers sent with every request.
     *
     * @return array<string, string>
     */
    private function defaultHeaders(): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->apiKey];
        if ($this->sessionPin !== null) {
            $headers[self::SESSION_PIN_HEADER] = $this->sessionPin;
        }

        return $headers;
    }

    /**
     * Send a request with default headers; a JSON body is encoded with
     * top-level nulls stripped (null stands in for "not set", so the
     * server applies its own defaults).
     *
     * @param non-empty-string          $method
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $endpoint, ?array $body = null): HttpResponse
    {
        $headers = $this->defaultHeaders();
        $encoded = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $encoded = json_encode(
                self::withoutNulls($body),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }

        return $this->transport->send($method, $this->endpointUrl($endpoint), $headers, $encoded);
    }

    /**
     * Send a request, throw on HTTP errors, and decode the JSON response.
     *
     * @param non-empty-string          $method
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $endpoint, ?array $body = null): array
    {
        $response = $this->request($method, $endpoint, $body);
        if (!$response->isOk()) {
            throw new HttpException($response->getStatusCode(), $method, $endpoint, $response->getBody());
        }

        $decoded = json_decode($response->getBody(), true);
        if (!is_array($decoded)) {
            throw new ResponseParseException(
                sprintf('server returned invalid JSON for %s %s', $method, $endpoint)
            );
        }

        return $decoded;
    }

    /**
     * Strip top-level nulls only: nested values such as
     * ['enable_thinking' => false] or an empty-string assistant turn must
     * survive, and no request field is ever legitimately null on the wire.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $body): array
    {
        return array_filter($body, static function ($value): bool {
            return $value !== null;
        });
    }
}
