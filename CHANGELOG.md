# Changelog

All notable changes to this project will be documented in this file.

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

[1.0.0]: https://github.com/sitehostnz/sthai-php/compare/v0.1.0...v1.0.0
[0.1.0]: https://github.com/sitehostnz/sthai-php/releases/tag/v0.1.0
