# Changelog

All notable changes to this project will be documented in this file.

## [1.2.0] - 2026-07-21

### Changed

- **Breaking:** `embed()` and `batchEmbed()` now return the full `EmbeddingResponse` instead of bare vectors. Get the vectors via `output()` (one per input, so `output()[0]` for `embed()`), and token usage via `usage()` - previously usage on embedding calls was unrecoverable.
- **Breaking:** `rerank()` now returns the full `RerankResponse` instead of a `RerankResult[]` array. Get the sorted results via `output()` (or the `$results` property), and token usage via `usage()`.
- **Breaking:** `EmbeddingResponse::output()` now returns validated float vectors and throws `SthAI\Exception\ResponseException` for non-float encoding formats or when the response carries no embeddings. The raw entries stay available on the `$data` property.

## [1.1.1] - 2026-07-20

### Added

- A fuller error hierarchy matching the Python client: `InputException` (renamed from `InvalidArgumentException`), `TransportException`, `ResponseException` (with `ResponseParseException` as a subclass), and `ApiStatusException` with `ClientException` (4xx) and `ApiException` (5xx). Catching `SthAI\Exception\SthAIException` still handles everything the client can throw.
- HTTP error responses now parse the server's `{"error": {"message": ..., "type": ...}}` body: `ClientException` and `ApiException` carry `getStatusCode()`, `getResponseBody()`, `getServerMessage()` and `getErrorType()`.

### Changed

- **Breaking:** `InvalidArgumentException` is renamed to `InputException` (still extends SPL `\InvalidArgumentException`).
- **Breaking:** `HttpException` is removed. HTTP 4xx/5xx responses now throw `ClientException`/`ApiException`, with a message matching the Python client (e.g. `404: model not found (invalid_request_error)`, falling back to `502: HTTP error` when the body has no parseable error envelope).
- **Breaking:** unusable success responses - no embedding data, an encoded string where a float vector was expected, or a body that isn't a JSON object - now throw `ResponseException` instead of `ResponseParseException`. Structured-output parsing failures still throw `ResponseParseException`, which is now a subclass of `ResponseException`.

## [1.1.0] - 2026-07-20

### Added

- Accessors for client state: `sessionPin()`/`setSessionPin()` and `newSession()` for reading, changing or regenerating the session pin after construction; `history()`/`setHistory()` for persisting and restoring conversations; and `writeHistory()`/`setWriteHistory()` for toggling history recording.
- `historyUsage()` totalling token usage across the calls that built the stored history.

## [1.0.0] - 2026-07-20

First stable release.

### Changed

- Renamed the package from `ftsartek/sthai` to `sitehostnz/sthai`; the repository now lives at [sitehostnz/sthai-php](https://github.com/sitehostnz/sthai-php).
- Added PHP 8.5 to the tested version matrix; the supported range is now PHP 7.4-8.5.

## [0.1.0] - 2026-07-18

Initial release: a PHP port of [sthai-py](https://github.com/sitehostnz/sthai-py) matching its functionality and wire format.

### Added

- `SthAI\Client` for the SiteHost AI Platform with no runtime dependencies beyond `ext-curl` and `ext-json` (PHP 7.4+)
- `chat()` with client-side conversation history, thinking mode, per-call system prompts and image input
- `response()` for one-off inference and `structuredResponse()` for schema-enforced JSON output (guided decoding)
- `embed()` for single text/multimodal embeddings and `batchEmbed()` with local chat-template rendering
- `rerank()` returning relevance-sorted results with input indexes
- `models()`, `healthy()`, session pinning (explicit or auto-generated) and `SthAI\Image` data-URI helpers
- Offline test suite replaying fixtures captured from the live API, shared with sthai-py

[1.2.0]: https://github.com/sitehostnz/sthai-php/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/sitehostnz/sthai-php/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/sitehostnz/sthai-php/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/sitehostnz/sthai-php/compare/v0.1.0...v1.0.0
[0.1.0]: https://github.com/sitehostnz/sthai-php/releases/tag/v0.1.0
