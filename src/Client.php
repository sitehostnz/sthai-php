<?php

declare(strict_types=1);

namespace SthAI;

use SthAI\Exception\HttpException;
use SthAI\Exception\InvalidArgumentException;
use SthAI\Exception\ResponseParseException;
use SthAI\Model\InferenceModel;
use SthAI\Response\InferenceResponse;
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

    private bool $writeHistory;

    /** @var array<int, array<string, mixed>> */
    private array $chatHistory = [];

    private ?InferenceResponse $lastResponse = null;

    private TransportInterface $transport;

    /**
     * Create a client. apiKey defaults to the STHAI_KEY environment
     * variable. sessionPin (or autoSession=true to generate one) pins
     * requests to a server session; writeHistory controls whether chat()
     * records conversation turns.
     */
    public function __construct(
        ?string $apiKey = null,
        string $fqdn = 'ai.sitehost.nz',
        bool $secure = true,
        ?string $sessionPin = null,
        bool $autoSession = false,
        bool $writeHistory = true,
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
        $this->writeHistory = $writeHistory;
        $this->transport = $transport ?? new CurlTransport();
    }

    /**
     * Discard the stored chat history.
     */
    public function clearHistory(): void
    {
        $this->chatHistory = [];
    }

    /**
     * The full response from the most recent inference call.
     */
    public function lastResponse(): ?InferenceResponse
    {
        return $this->lastResponse;
    }

    /**
     * The reasoning from the most recent inference call, if any.
     */
    public function lastReasoning(): ?string
    {
        if ($this->lastResponse === null) {
            return null;
        }

        return $this->lastResponse->output()->reasoning;
    }

    /**
     * Send a chat message and return the full inference response.
     *
     * With writeHistory=true (the default), each successful call appends
     * the user and assistant turns to the stored history, and later calls
     * send that history. Pass useHistory=false for a standalone call that
     * neither sends nor updates it.
     *
     * @param string[] $imageUrls  image URLs (or data URIs, e.g. from Image::dataUriFromBytes())
     * @param string[] $imageFiles paths of local image files, inlined as data URIs
     */
    public function chat(
        string $prompt,
        string $model = InferenceModel::QWEN_3_6_27B,
        ?int $maxTokens = null,
        ?float $temperature = null,
        bool $useThinking = false,
        ?string $systemPrompt = null,
        array $imageUrls = [],
        array $imageFiles = [],
        bool $useHistory = true
    ): InferenceResponse {
        $userMessage = [
            'role' => 'user',
            'content' => self::promptContent($prompt, $imageUrls, $imageFiles),
        ];

        $messages = [];
        if ($systemPrompt !== null && $systemPrompt !== '') {
            // The system prompt is prepended per-call rather than stored in
            // history, so changing it between calls behaves predictably
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        if ($useHistory) {
            $messages = array_merge($messages, $this->chatHistory);
        }
        $messages[] = $userMessage;

        $decoded = $this->inferenceRequest([
            'messages' => $messages,
            'model' => $model,
            // max_tokens is deprecated upstream in favor of max_completion_tokens
            'max_completion_tokens' => $maxTokens,
            'temperature' => $temperature,
            'chat_template_kwargs' => ['enable_thinking' => $useThinking],
        ]);

        // A call that didn't see the history must not write to it either,
        // or the stored conversation would gain a turn with missing context
        if ($useHistory && $this->writeHistory && $decoded->choices !== []) {
            $this->chatHistory[] = $userMessage;
            $this->chatHistory[] = [
                'role' => 'assistant',
                'content' => $decoded->choices[0]->message->content,
            ];
        }

        return $decoded;
    }

    /**
     * POST an inference request and record the decoded last response.
     *
     * @param array<string, mixed> $body
     */
    private function inferenceRequest(array $body): InferenceResponse
    {
        $decoded = InferenceResponse::fromArray(
            $this->requestJson('POST', self::INFERENCE_ENDPOINT, $body)
        );
        $this->lastResponse = $decoded;

        return $decoded;
    }

    /**
     * A user-message content value: plain text, or text plus image parts.
     *
     * @param string[] $imageUrls
     * @param string[] $imageFiles
     *
     * @return string|array<int, array<string, mixed>>
     */
    private static function promptContent(string $prompt, array $imageUrls, array $imageFiles)
    {
        $imageParts = self::buildImageParts($imageUrls, $imageFiles);
        if ($imageParts === []) {
            return $prompt;
        }

        return array_merge([['type' => 'text', 'text' => $prompt]], $imageParts);
    }

    /**
     * Image content parts for the given URLs and local files.
     *
     * @param string[] $imageUrls
     * @param string[] $imageFiles
     *
     * @return array<int, array<string, mixed>>
     */
    private static function buildImageParts(array $imageUrls, array $imageFiles): array
    {
        $parts = [];
        foreach ($imageUrls as $url) {
            $parts[] = Image::urlPart($url);
        }
        foreach ($imageFiles as $path) {
            $parts[] = Image::filePart($path);
        }

        return $parts;
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
