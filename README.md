# sthai-php

[![Tests](https://github.com/sitehostnz/sthai-php/actions/workflows/tests.yml/badge.svg)](https://github.com/sitehostnz/sthai-php/actions/workflows/tests.yml)

A PHP client for the [SiteHost AI Platform](https://kb.sitehost.nz/ai-platform): inference, embeddings and reranking, with no runtime dependencies beyond `ext-curl` and `ext-json`. A port of the Python client, [sthai-py](https://github.com/sitehostnz/sthai-py), matching its functionality and wire format.

## The SiteHost AI Platform

The [SiteHost AI Platform](https://kb.sitehost.nz/ai-platform) serves capable open-weight models at their full context windows - not heavily quantised cut-downs - with full control over system prompts and outputs. Everything runs on SiteHost's own hardware in their own New Zealand data centres, so your data never leaves the country, and request bodies are never stored: only usage metrics are kept for billing and performance monitoring.

Models are stable targets, too: each served model has a minimum one-year retention window and at least three months' deprecation notice, with guidance on any adjustments needed.

The platform currently serves three models, one per capability (see the [models page](https://kb.sitehost.nz/ai-platform/models) for the source of truth):

| Model | Purpose | Context window |
|-------|---------|----------------|
| `Qwen/Qwen3.8-27B` | Inference (chat, multimodal, thinking) | 262K |
| `Qwen/Qwen3-VL-Embedding-8B` | Embeddings (multimodal, Matryoshka, 4096 dims) | 32K |
| `Qwen/Qwen3-VL-Reranker-8B` | Reranking (multimodal, instruction-trained) | 32K |

Qwen 3.6 27B (`Qwen/Qwen3.6-27B`) is deprecated, and Qwen 3.8 27B replaces it as the default inference model. The platform still accepts `Qwen/Qwen3.6-27B` as an alias, but `models()` no longer lists it. `InferenceModel::QWEN_3_6_27B` stays available until the alias is retired - see the [models page](https://kb.sitehost.nz/ai-platform/models) for the timeline.

## Installation

Requires PHP 7.4 or newer with `ext-curl` and `ext-json`. Install via [Composer](https://getcomposer.org/):

```bash
composer require sitehostnz/sthai
```

On PHP 8.0+ every optional parameter can be passed as a named argument, which the examples below use. On PHP 7.4 pass them positionally.

## Getting started

Create an API key in the SiteHost Control Panel (see [API keys](https://kb.sitehost.nz/ai-platform/api-keys)). The client reads it from the `STHAI_KEY` environment variable, or you can pass it explicitly:

```php
use SthAI\Client;

$client = new Client();  // or new Client('your-api-key')
$response = $client->chat("What's the tallest mountain in New Zealand?");
echo $response->output()->text;
```

## Usage

### Chat and history

`chat()` keeps a conversation going: each successful call appends the user and assistant turns to the client's history, and later calls send it back. The system prompt is applied per call rather than stored.

```php
$client->chat("I'm planning a tramping trip to Fiordland.");
$client->chat('What should I pack?');  // the model sees the earlier turn

$client->chat('Standalone question.', useHistory: false);  // neither sends nor records
$client->clearHistory();

$client->chat('Be brief: why is the sky blue?', systemPrompt: 'You are terse.');
```

The stored history can be read with `history()` and restored with `setHistory()`, so a conversation can be persisted and picked up later. `setWriteHistory(false)` stops `chat()` recording new turns (the stored history is kept and still sent); `writeHistory()` reads the current setting.

`historyUsage()` totals token usage across the calls that built the stored history. Each call resends the conversation so far, so input tokens count what the server processed (as billed), not unique tokens. `clearHistory()` and `setHistory()` reset the tally along with the turns it covered.

Thinking models can reason before answering; the reasoning rides along on the response:

```php
$response = $client->chat('What is 17 * 23?', useThinking: true, maxTokens: 2000);
echo $response->output()->reasoning;  // or $client->lastReasoning()
echo $response->output()->text;
```

### Images

Chat and embedding inputs can include images, given as URLs or local files (PNG, JPEG, GIF or WEBP - files are inlined as data URIs):

```php
$client->chat("What's in this image?", imageFiles: ['photo.png']);
$client->chat('Compare these.', imageUrls: [
    'https://example.com/a.jpg',
    'https://example.com/b.jpg',
]);
```

Raw image bytes can be converted with `SthAI\Image::dataUriFromBytes($bytes)` and passed via `imageUrls` (a data URI is a URL).

### One-off and structured responses

`response()` mirrors `chat()` without the back-and-forth: the stored history is neither sent nor updated. `structuredResponse()` adds structured output - pass a JSON schema as a PHP array and the server enforces it during generation, with the decoded array returned:

```php
$city = $client->structuredResponse(
    'Give me basic facts about Wellington.',
    schema: [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'country' => ['type' => 'string'],
            'population' => ['type' => 'integer'],
        ],
        'required' => ['name', 'country', 'population'],
    ],
    schemaName: 'CityInfo',
);
echo $city['population'];

// With no schema, the output is only constrained to valid JSON
$data = $client->structuredResponse('List three NZ birds as JSON.');
```

The client does not re-validate the result against the schema. If the output is cut off by the token limit, or (rarely, with thinking enabled) the server skips the schema, parsing throws a `SthAI\Exception\ResponseParseException` naming the cause. A schema needing an empty JSON object value must use `new \stdClass()` rather than `[]` (which encodes as an empty array).

### Embeddings

`embed()` turns one input - text, images, or both - into a single vector, the sole entry in the response's `output()`. The embedding model is instruction-trained: document embedding is the default, and `query: true` switches to the query instruction for search-style lookups. `dimensions` truncates the vector server-side (Matryoshka - powers of two work best):

```php
$vector = $client->embed("The Beehive is New Zealand's parliament building.")->output()[0];
$queryVector = $client->embed('Where does NZ parliament sit?', query: true)->output()[0];
$small = $client->embed('Compact vector, please.', dimensions: 512)->output()[0];
```

`batchEmbed()` embeds many texts in one request; the response's `output()` is one vector per text in order:

```php
$vectors = $client->batchEmbed([
    'Wellington is the capital of New Zealand.',
    'Auckland is the largest city in New Zealand.',
])->output();
```

### Reranking

`rerank()` scores each document against a query; `output()` on the response is the results sorted by relevance, with each result's `index` mapping back to your input list:

```php
$response = $client->rerank(
    'What is the capital of New Zealand?',
    [
        'The capital of New Zealand is Wellington.',
        'Auckland has the largest population in New Zealand.',
        "The All Blacks are New Zealand's national rugby team.",
    ],
    topN: 2,
);
foreach ($response->output() as $result) {
    echo $result->relevanceScore, ' ', $result->document->text, PHP_EOL;
}
```

Pass `instruction:` to steer relevance for a specific task; the model applies a sensible default otherwise.

### Response helpers

Every inference, embedding and rerank call returns the full response object, and every response object has `usage()` (input/output/cached token counts) and `output()` (the useful payload), with `toArray()` exposing the complete decoded payload:

```php
$response = $client->chat('Hello!');
echo $response->usage()->inputTokens, ' ', $response->usage()->outputTokens;

$embedded = $client->embed('Hello!');
echo $embedded->usage()->inputTokens, ' ', count($embedded->output()[0]);
```

The one exception is `structuredResponse()`, which returns the decoded array directly; the full response from the most recent inference call remains available via `lastResponse()`:

```php
$city = $client->structuredResponse('Describe Wellington.', $citySchema);
echo $client->lastResponse()->usage()->inputTokens;
```

### Sessions

Pinning requests to a server session keeps routing consistent and helps caching. Pass `sessionPin:` with your own identifier, or let the client generate one:

```php
$client = new Client(autoSession: true);
echo $client->sessionPin();        // the pin in use, e.g. to persist it

$client->setSessionPin('my-pin');  // pin to a known session (null unpins)
$pin = $client->newSession();      // switch to a freshly generated pin
```

### Models and health

```php
$client->healthy();  // true if the server is up
foreach ($client->models() as $card) {
    echo $card->id, PHP_EOL;
}
```

### Errors

Everything the library throws implements `SthAI\Exception\SthAIException`, so catching it is enough to handle anything the client can throw:

```php
use SthAI\Client;
use SthAI\Exception\ApiException;
use SthAI\Exception\ClientException;
use SthAI\Exception\InputException;
use SthAI\Exception\ResponseException;
use SthAI\Exception\TransportException;

$client = new Client();
try {
    $response = $client->chat('hello', model: 'no-such-model');
} catch (ClientException $e) {
    echo $e->getStatusCode(), ' ', $e->getErrorType(), ' ', $e->getMessage();
    // 404 invalid_request_error 404: model not found (invalid_request_error)
} catch (ApiException $e) {
    // 5xx: the server had a problem
} catch (TransportException $e) {
    // the request never completed: connection failure, timeout, TLS error
} catch (InputException $e) {
    // bad arguments to a client method
} catch (ResponseException $e) {
    // the server returned something the client couldn't use
}
```

`ClientException` (4xx) and `ApiException` (5xx) both extend `ApiStatusException`, which carries `getStatusCode()`, `getResponseBody()`, `getServerMessage()` and `getErrorType()` (parsed from the server's error body when present). `ResponseParseException`, thrown by `structuredResponse()`'s parsing, extends `ResponseException`.

## Development

The test suite runs entirely offline against fixtures captured from the live API (shared with sthai-py):

```bash
git clone https://github.com/sitehostnz/sthai-php.git
cd sthai-php
composer install
composer test      # phpunit
composer stan      # phpstan
composer cs        # php-cs-fixer (dry run)
```

## Licence

[MIT](LICENSE)
