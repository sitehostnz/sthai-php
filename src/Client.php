<?php

declare(strict_types=1);

namespace SthAI;

use SthAI\Exception\HttpException;
use SthAI\Exception\InvalidArgumentException;
use SthAI\Exception\ResponseParseException;
use SthAI\Internal\Template;
use SthAI\Model\EmbeddingModel;
use SthAI\Model\EmbeddingParams;
use SthAI\Model\InferenceModel;
use SthAI\Response\EmbeddingResponse;
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
     * One-off inference: like chat(), but the stored chat history is
     * neither sent nor updated. The response remains available through
     * lastResponse() and lastReasoning().
     *
     * @param string[] $imageUrls  image URLs (or data URIs, e.g. from Image::dataUriFromBytes())
     * @param string[] $imageFiles paths of local image files, inlined as data URIs
     */
    public function response(
        string $prompt,
        string $model = InferenceModel::QWEN_3_6_27B,
        ?int $maxTokens = null,
        ?float $temperature = null,
        bool $useThinking = false,
        ?string $systemPrompt = null,
        array $imageUrls = [],
        array $imageFiles = []
    ): InferenceResponse {
        return $this->inferenceRequest($this->oneOffBody(
            $prompt,
            $model,
            $maxTokens,
            $temperature,
            $useThinking,
            $systemPrompt,
            $imageUrls,
            $imageFiles,
            null
        ));
    }

    /**
     * One-off inference with a structured response, returning the decoded
     * JSON as an associative array.
     *
     * Pass a JSON schema (as a PHP array) for the server to enforce during
     * generation via guided decoding; with the default null schema the
     * output is only constrained to valid JSON ("json_object" mode). The
     * client does not re-validate the result against the schema. The full
     * response remains available through lastResponse().
     *
     * Thinking combines with structured responses (reasoning stays
     * unconstrained) but consumes maxTokens, so budget generously. The
     * server occasionally skips the schema when thinking is enabled;
     * parsing then throws a ResponseParseException - retry, or disable
     * thinking.
     *
     * @param array<string, mixed>|null $schema     JSON schema for guided decoding; empty
     *                                              nested objects must be stdClass, not []
     * @param string                    $schemaName name sent alongside the schema
     * @param string[]                  $imageUrls
     * @param string[]                  $imageFiles
     *
     * @return array<mixed> the decoded response JSON
     */
    public function structuredResponse(
        string $prompt,
        ?array $schema = null,
        string $schemaName = 'response',
        string $model = InferenceModel::QWEN_3_6_27B,
        ?int $maxTokens = null,
        ?float $temperature = null,
        bool $useThinking = false,
        ?string $systemPrompt = null,
        array $imageUrls = [],
        array $imageFiles = []
    ): array {
        $responseFormat = $schema === null
            ? ['type' => 'json_object']
            : [
                'type' => 'json_schema',
                'json_schema' => ['name' => $schemaName, 'schema' => $schema],
            ];

        $decoded = $this->inferenceRequest($this->oneOffBody(
            $prompt,
            $model,
            $maxTokens,
            $temperature,
            $useThinking,
            $systemPrompt,
            $imageUrls,
            $imageFiles,
            $responseFormat
        ));

        return $this->parseStructured($decoded);
    }

    /**
     * The request body for a one-off (history-less) inference call.
     *
     * @param string[]                  $imageUrls
     * @param string[]                  $imageFiles
     * @param array<string, mixed>|null $responseFormat
     *
     * @return array<string, mixed>
     */
    private function oneOffBody(
        string $prompt,
        string $model,
        ?int $maxTokens,
        ?float $temperature,
        bool $useThinking,
        ?string $systemPrompt,
        array $imageUrls,
        array $imageFiles,
        ?array $responseFormat
    ): array {
        $messages = [];
        if ($systemPrompt !== null && $systemPrompt !== '') {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }
        $messages[] = [
            'role' => 'user',
            'content' => self::promptContent($prompt, $imageUrls, $imageFiles),
        ];

        return [
            'messages' => $messages,
            'model' => $model,
            'max_completion_tokens' => $maxTokens,
            'temperature' => $temperature,
            'chat_template_kwargs' => ['enable_thinking' => $useThinking],
            'response_format' => $responseFormat,
        ];
    }

    /**
     * Decode a structured response's text into an array.
     *
     * Guided decoding guarantees schema-valid syntax but not completeness
     * (token-limit cutoffs) nor, with reasoning models, that the schema was
     * applied at all - so parsing doubles as the check, throwing a
     * ResponseParseException naming the cause on failure.
     *
     * @return array<mixed>
     */
    private function parseStructured(InferenceResponse $response): array
    {
        $text = $response->output()->text;
        if ($text === null) {
            throw new ResponseParseException('response has no text content to parse');
        }

        $decoded = json_decode($text, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $choices = $response->choices;
            if ($choices !== [] && $choices[0]->finishReason === 'length') {
                throw new ResponseParseException(
                    'structured response was cut off by the token limit before '
                    . 'the JSON completed; raise maxTokens'
                );
            }
            // Guided decoding should make this impossible, but reasoning
            // models have been seen to intermittently emit non-JSON content
            throw new ResponseParseException(sprintf(
                'structured response is not valid JSON (%s); content began: %s',
                json_last_error_msg(),
                var_export(substr($text, 0, 120), true)
            ));
        }

        if (!is_array($decoded)) {
            // A schema with a scalar top level decodes to a scalar; the
            // client only supports object/array results
            throw new ResponseParseException(
                'structured response decoded to a scalar, not a JSON object or array'
            );
        }

        return $decoded;
    }

    /**
     * Embed a single input (text, images, or both) and return its vector.
     *
     * The instruction-trained model is steered by a default instruction
     * from EmbeddingParams: the model's document instruction, or its query
     * instruction when query=true (use this when embedding search
     * queries). Passing instruction overrides either.
     *
     * Each call produces exactly ONE vector - multimodal content rolls into
     * it; use batchEmbed() to embed many texts in one request. dimensions
     * truncates the vector server-side (Matryoshka); powers of two work
     * best, up to the model's native dimension.
     *
     * @param string[] $imageUrls  image URLs (or data URIs, e.g. from Image::dataUriFromBytes())
     * @param string[] $imageFiles paths of local image files, inlined as data URIs
     *
     * @return array<int, float|int> the embedding vector
     */
    public function embed(
        ?string $text = null,
        string $model = EmbeddingModel::QWEN_3_VL_8B,
        bool $query = false,
        ?string $instruction = null,
        array $imageUrls = [],
        array $imageFiles = [],
        ?int $dimensions = null
    ): array {
        $imageParts = self::buildImageParts($imageUrls, $imageFiles);
        $parts = [];
        // An empty string is treated as no text: embedding it would produce
        // a meaningless vector, so it falls through to the guard below
        $hasText = $text !== null && $text !== '';
        if ($hasText) {
            $parts[] = ['type' => 'text', 'text' => $text];
        }
        $parts = array_merge($parts, $imageParts);
        if ($parts === []) {
            throw new InvalidArgumentException('embed() requires text and/or images');
        }
        self::checkDimensions($model, $dimensions);
        $content = ($hasText && $imageParts === []) ? $text : $parts;

        if ($instruction === null) {
            $instruction = self::defaultInstruction($model, $query);
        }
        $messages = [];
        if ($instruction !== null) {
            $messages[] = ['role' => 'system', 'content' => $instruction];
        }
        $messages[] = ['role' => 'user', 'content' => $content];
        // The open assistant turn is intentional: with continue_final_message
        // the template is left unterminated, matching how the model was
        // trained to embed
        $messages[] = ['role' => 'assistant', 'content' => ''];

        $decoded = $this->embeddingRequest([
            'messages' => $messages,
            'model' => $model,
            'encoding_format' => 'float',
            'dimensions' => $dimensions,
            'continue_final_message' => true,
            // true (not the chat-form server default of false) so tokenization
            // matches batchEmbed's plain-input form, which defaults to true
            'add_special_tokens' => true,
        ]);
        $outputs = $decoded->output();
        if ($outputs === []) {
            throw new ResponseParseException('server returned no embedding data');
        }

        return self::floatEmbedding($outputs[0]);
    }

    /**
     * Embed a batch of texts in one request, returning one vector per text
     * in the same order. Text-only; use embed() for multimodal input.
     *
     * Only the plain-input request form batches, and it bypasses the
     * server-side chat template, so each text is rendered through a local
     * template first; with the built-in templates the results match calling
     * embed() per text. template and instruction default from
     * EmbeddingParams (the query instruction when query=true, as with
     * embed()). For models without known params, pass a template using
     * {instruction} and {text} placeholders - "{text}" alone for raw
     * untemplated input. dimensions truncates the vectors server-side.
     *
     * @param string[] $texts
     *
     * @return array<int, array<int, float|int>> one vector per text, in input order
     */
    public function batchEmbed(
        array $texts,
        string $model = EmbeddingModel::QWEN_3_VL_8B,
        bool $query = false,
        ?string $instruction = null,
        ?string $template = null,
        ?int $dimensions = null
    ): array {
        if ($texts === []) {
            throw new InvalidArgumentException('batchEmbed() requires at least one text');
        }
        foreach ($texts as $text) {
            if ($text === '') {
                throw new InvalidArgumentException('batchEmbed() texts must be non-empty strings');
            }
        }
        if ($template === null) {
            $params = EmbeddingParams::forModel($model);
            $template = $params !== null ? $params->getTemplate() : null;
            if ($template === null) {
                throw new InvalidArgumentException(sprintf(
                    "no known embedding template for model '%s'; pass template "
                    . '(use "{text}" for models that take raw untemplated input)',
                    $model
                ));
            }
        }
        if (!Template::hasPlaceholder($template, 'instruction') && ($instruction !== null || $query)) {
            trigger_error(
                'the template has no {instruction} placeholder, so the requested '
                . 'instruction steering will not be applied',
                E_USER_WARNING
            );
        }
        if ($instruction === null) {
            $instruction = self::defaultInstruction($model, $query);
            if ($instruction === null && Template::hasPlaceholder($template, 'instruction')) {
                throw new InvalidArgumentException(sprintf(
                    "no known embedding instruction for model '%s' but the "
                    . 'template expects one; pass instruction',
                    $model
                ));
            }
        }
        self::checkDimensions($model, $dimensions);

        $inputs = [];
        foreach ($texts as $text) {
            $inputs[] = Template::render($template, $instruction, $text);
        }

        $decoded = $this->embeddingRequest([
            'input' => $inputs,
            'model' => $model,
            'encoding_format' => 'float',
            'dimensions' => $dimensions,
        ]);

        $vectors = [];
        foreach ($decoded->output() as $embedding) {
            $vectors[] = self::floatEmbedding($embedding);
        }

        return $vectors;
    }

    /**
     * POST an embedding request and decode the response.
     *
     * @param array<string, mixed> $body
     */
    private function embeddingRequest(array $body): EmbeddingResponse
    {
        return EmbeddingResponse::fromArray(
            $this->requestJson('POST', self::EMBEDDING_ENDPOINT, $body)
        );
    }

    /**
     * The model's recommended embedding instruction, if known: its query
     * instruction when query is set, its document instruction otherwise.
     */
    private static function defaultInstruction(string $model, bool $query): ?string
    {
        $params = EmbeddingParams::forModel($model);
        if ($params === null) {
            return null;
        }

        return $params->getInstruction($query);
    }

    /**
     * Warn when a requested Matryoshka truncation exceeds or does not
     * divide evenly into the model's native output dimension (when both
     * are known).
     */
    private static function checkDimensions(string $model, ?int $dimensions): void
    {
        if ($dimensions === null) {
            return;
        }
        if ($dimensions < 1) {
            throw new InvalidArgumentException('dimensions must be a positive integer');
        }
        $params = EmbeddingParams::forModel($model);
        if ($params === null || $params->getDimensions() === null) {
            return;
        }
        $native = $params->getDimensions();
        if ($dimensions > $native) {
            trigger_error(
                sprintf("dimensions=%d exceeds the native %d dimensions of '%s'", $dimensions, $native, $model),
                E_USER_WARNING
            );
        } elseif ($native % $dimensions !== 0) {
            trigger_error(
                sprintf(
                    'dimensions=%d does not divide evenly into the native %d dimensions '
                    . "of '%s'; use a power-of-two divisor (e.g. %d, %d)",
                    $dimensions,
                    $native,
                    $model,
                    intdiv($native, 2),
                    intdiv($native, 4)
                ),
                E_USER_WARNING
            );
        }
    }

    /**
     * Ensure a decoded embedding is the float list the client requested
     * (the server returns strings for non-float encoding formats).
     *
     * @param array<int, float|int>|string $embedding
     *
     * @return array<int, float|int>
     */
    private static function floatEmbedding($embedding): array
    {
        if (is_string($embedding)) {
            throw new ResponseParseException('expected a float embedding, got an encoded string');
        }

        return $embedding;
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
