# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-07-18

Initial release: a PHP port of [sthai-py](https://github.com/ftsartek/sthai-py) matching its functionality and wire format.

### Added

- `SthAI\Client` for the SiteHost AI Platform with no runtime dependencies beyond `ext-curl` and `ext-json` (PHP 7.4+)
- `chat()` with client-side conversation history, thinking mode, per-call system prompts and image input
- `response()` for one-off inference and `structuredResponse()` for schema-enforced JSON output (guided decoding)
- `embed()` for single text/multimodal embeddings and `batchEmbed()` with local chat-template rendering
- `rerank()` returning relevance-sorted results with input indexes
- `models()`, `healthy()`, session pinning (explicit or auto-generated) and `SthAI\Image` data-URI helpers
- Offline test suite replaying fixtures captured from the live API, shared with sthai-py

[Unreleased]: https://github.com/ftsartek/sthai-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/ftsartek/sthai-php/releases/tag/v0.1.0
