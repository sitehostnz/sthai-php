<?php

declare(strict_types=1);

namespace SthAI\Tests;

use PHPUnit\Framework\TestCase;
use SthAI\Exception\ApiException;
use SthAI\Exception\ApiStatusException;
use SthAI\Exception\ClientException;
use SthAI\Exception\InputException;
use SthAI\Exception\ResponseException;
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

    public function testEveryExceptionImplementsTheMarkerInterface(): void
    {
        $this->assertInstanceOf(SthAIException::class, new InputException('bad'));
        $this->assertInstanceOf(SthAIException::class, new TransportException('down'));
        $this->assertInstanceOf(SthAIException::class, new ClientException(400, ''));
        $this->assertInstanceOf(SthAIException::class, new ApiException(500, ''));
        $this->assertInstanceOf(SthAIException::class, new ResponseException('bad body'));
        $this->assertInstanceOf(SthAIException::class, new ResponseParseException('cut off'));
    }

    public function testExceptionHierarchy(): void
    {
        // catch (ApiStatusException) handles 4xx and 5xx together; parse
        // failures are catchable as the broader ResponseException
        $this->assertInstanceOf(ApiStatusException::class, new ClientException(400, ''));
        $this->assertInstanceOf(ApiStatusException::class, new ApiException(500, ''));
        $this->assertInstanceOf(ResponseException::class, new ResponseParseException('cut off'));
    }

    public function testInputExceptionIsCatchableAsSpl(): void
    {
        $this->assertInstanceOf(\InvalidArgumentException::class, new InputException('bad'));
    }
}
