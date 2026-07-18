<?php

declare(strict_types=1);

namespace SthAI\Tests;

use PHPUnit\Framework\TestCase;
use SthAI\Exception\HttpException;
use SthAI\Exception\InvalidArgumentException;
use SthAI\Exception\ResponseParseException;
use SthAI\Exception\SthAIException;
use SthAI\Exception\TransportException;
use SthAI\Transport\HttpResponse;

final class TransportTest extends TestCase
{
    public function testHttpResponseIsOkBelow400(): void
    {
        $this->assertTrue((new HttpResponse(200, ''))->isOk());
        $this->assertTrue((new HttpResponse(204, ''))->isOk());
        $this->assertTrue((new HttpResponse(399, ''))->isOk());
        $this->assertFalse((new HttpResponse(400, ''))->isOk());
        $this->assertFalse((new HttpResponse(500, ''))->isOk());
    }

    public function testHttpResponseExposesStatusAndBody(): void
    {
        $response = new HttpResponse(404, '{"error":"nope"}');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('{"error":"nope"}', $response->getBody());
    }

    public function testHttpExceptionCarriesStatusAndBody(): void
    {
        $exception = new HttpException(404, 'POST', '/v1/chat/completions', '{"detail":"missing"}');
        $this->assertSame('HTTP 404 for POST /v1/chat/completions', $exception->getMessage());
        $this->assertSame(404, $exception->getStatusCode());
        $this->assertSame('{"detail":"missing"}', $exception->getResponseBody());
    }

    public function testEveryExceptionImplementsTheMarkerInterface(): void
    {
        $this->assertInstanceOf(SthAIException::class, new InvalidArgumentException('bad'));
        $this->assertInstanceOf(SthAIException::class, new TransportException('down'));
        $this->assertInstanceOf(SthAIException::class, new HttpException(500, 'GET', '/health', ''));
        $this->assertInstanceOf(SthAIException::class, new ResponseParseException('cut off'));
    }

    public function testInvalidArgumentExceptionIsCatchableAsSpl(): void
    {
        $this->assertInstanceOf(\InvalidArgumentException::class, new InvalidArgumentException('bad'));
    }
}
