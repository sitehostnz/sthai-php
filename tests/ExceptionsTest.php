<?php

declare(strict_types=1);

namespace SthAI\Tests;

use SthAI\Exception\ApiException;
use SthAI\Exception\ClientException;
use SthAI\Exception\ResponseException;
use SthAI\Tests\Support\ClientTestCase;

/**
 * The exception surface: status mapping, envelope parsing, and fallbacks.
 */
final class ExceptionsTest extends ClientTestCase
{
    public function test4xxParsesErrorEnvelope(): void
    {
        $fixture = $this->transport->register('error_bad_model');

        try {
            $this->client()->chat('hello', 'does-not-exist');
            $this->fail('expected ClientException');
        } catch (ClientException $exception) {
            $this->assertSame($fixture['status_code'], $exception->getStatusCode());
            $this->assertSame(404, $exception->getStatusCode());
            $this->assertSame('model not found', $exception->getServerMessage());
            $this->assertSame('invalid_request_error', $exception->getErrorType());
            $this->assertSame('404: model not found (invalid_request_error)', $exception->getMessage());
            $this->assertNotSame('', $exception->getResponseBody());
        }
    }

    public function test5xxRaisesApiException(): void
    {
        $this->transport->respond('POST', '/v1/chat/completions', [
            'error' => ['message' => 'model not found', 'type' => 'invalid_request_error'],
        ], 503);

        try {
            $this->client()->chat('hello');
            $this->fail('expected ApiException');
        } catch (ApiException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
            $this->assertSame('model not found', $exception->getServerMessage());
        }
    }

    public function testUnparseableErrorBodyFallsBack(): void
    {
        $this->transport->respond('POST', '/v1/chat/completions', '<html>bad gateway</html>', 502);

        try {
            $this->client()->chat('hello');
            $this->fail('expected ApiException');
        } catch (ApiException $exception) {
            $this->assertSame(502, $exception->getStatusCode());
            $this->assertNull($exception->getServerMessage());
            $this->assertNull($exception->getErrorType());
            $this->assertSame('502: HTTP error', $exception->getMessage());
            $this->assertSame('<html>bad gateway</html>', $exception->getResponseBody());
        }
    }

    public function testEnvelopeWithoutTypeOmitsSuffix(): void
    {
        $this->transport->respond('POST', '/v1/chat/completions', [
            'error' => ['message' => 'slow down'],
        ], 429);

        try {
            $this->client()->chat('hello');
            $this->fail('expected ClientException');
        } catch (ClientException $exception) {
            $this->assertSame('429: slow down', $exception->getMessage());
            $this->assertNull($exception->getErrorType());
        }
    }

    public function testMalformedSuccessBodyRaisesResponseException(): void
    {
        // Valid JSON but a bare scalar top level: requestJson()'s array
        // check fails before any response hydration
        $this->transport->respond('GET', '/v1/models', '"not-a-model-list"');

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('invalid JSON');
        $this->client()->models();
    }
}
