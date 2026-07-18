<?php

declare(strict_types=1);

namespace SthAI\Tests;

use SthAI\Client;
use SthAI\Exception\HttpException;
use SthAI\Exception\InvalidArgumentException;
use SthAI\Response\ModelCard;
use SthAI\Tests\Support\ClientTestCase;

final class ClientCoreTest extends ClientTestCase
{
    public function testMissingApiKeyThrows(): void
    {
        // Make sure the constructor cannot fall back to a real environment key
        putenv('STHAI_KEY');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('apiKey');
        new Client('', 'ai.sitehost.nz', true, null, false, true, $this->transport);
    }

    public function testApiKeyFallsBackToEnvironment(): void
    {
        putenv('STHAI_KEY=env-key');

        try {
            $this->transport->register('health');
            $client = new Client(null, 'ai.sitehost.nz', true, null, false, true, $this->transport);
            $client->healthy();
            $this->assertSame(
                'Bearer env-key',
                $this->transport->lastCall()->headers['Authorization']
            );
        } finally {
            putenv('STHAI_KEY');
        }
    }

    public function testHttpsUrlByDefault(): void
    {
        $this->transport->register('health');
        $this->client()->healthy();
        $this->assertSame('https://ai.sitehost.nz/health', $this->transport->lastCall()->url);
    }

    public function testInsecureUrl(): void
    {
        $this->transport->register('health');
        $this->client(['secure' => false])->healthy();
        $this->assertSame('http://ai.sitehost.nz/health', $this->transport->lastCall()->url);
    }

    public function testAuthorizationHeaderSent(): void
    {
        $this->transport->register('health');
        $this->client()->healthy();
        $this->assertSame('Bearer test-key', $this->transport->lastCall()->headers['Authorization']);
    }

    public function testNoSessionPinByDefault(): void
    {
        $this->transport->register('health');
        $this->client()->healthy();
        $this->assertArrayNotHasKey(Client::SESSION_PIN_HEADER, $this->transport->lastCall()->headers);
    }

    public function testExplicitSessionPinHeader(): void
    {
        $this->transport->register('health');
        $this->client(['sessionPin' => 'my-pin'])->healthy();
        $this->assertSame('my-pin', $this->transport->lastCall()->headers[Client::SESSION_PIN_HEADER]);
    }

    public function testAutoSessionGeneratesPin(): void
    {
        $this->transport->register('health');
        $this->client(['autoSession' => true])->healthy();
        $pin = $this->transport->lastCall()->headers[Client::SESSION_PIN_HEADER];
        $this->assertSame(48, strlen($pin));
        $this->assertTrue(ctype_xdigit($pin));
    }

    public function testHealthyTrueOn200(): void
    {
        $this->transport->register('health');
        $this->assertTrue($this->client()->healthy());
    }

    public function testHealthyFalseOn500(): void
    {
        $this->transport->respond('GET', '/health', ['status' => 'down'], 500);
        $this->assertFalse($this->client()->healthy());
    }

    public function testModelsReturnsModelCards(): void
    {
        $fixture = $this->transport->register('models');
        $models = $this->client()->models();

        $this->assertContainsOnlyInstancesOf(ModelCard::class, $models);
        $ids = array_map(static function (ModelCard $card): string {
            return $card->id;
        }, $models);
        $expected = array_map(static function (array $entry): string {
            return $entry['id'];
        }, $fixture['response']['data']);
        $this->assertSame($expected, $ids);
    }

    public function testHttpErrorRaisedOnErrorStatus(): void
    {
        $this->transport->respond('GET', '/v1/models', ['error' => 'nope'], 401);

        try {
            $this->client()->models();
            $this->fail('expected HttpException');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
            $this->assertStringContainsString('/v1/models', $exception->getMessage());
            $this->assertSame('{"error":"nope"}', $exception->getResponseBody());
        }
    }
}
