<?php

declare(strict_types=1);

namespace SthAI\Tests;

use SthAI\Exception\ResponseParseException;
use SthAI\Response\InferenceResponse;
use SthAI\Tests\Support\ClientTestCase;
use SthAI\Tests\Support\Fixtures;

/**
 * response() and structuredResponse(): one-off inference and structured
 * (schema-enforced) outputs.
 */
final class StructuredResponseTest extends ClientTestCase
{
    /**
     * Mirrors the CityInfo schema captured in the response_struct fixture.
     *
     * @var array<string, mixed>
     */
    private const CITY_INFO_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'country' => ['type' => 'string'],
            'population' => ['type' => 'integer'],
        ],
        'required' => ['name', 'country', 'population'],
    ];

    public function testPlainResponseReturnsInferenceResponse(): void
    {
        $this->transport->register('chat_simple');
        $client = $this->client();
        $result = $client->response('hello');
        $this->assertInstanceOf(InferenceResponse::class, $result);
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertArrayNotHasKey('response_format', $body);
        $this->assertSame($result, $client->lastResponse());
    }

    public function testResponseDoesNotTouchHistory(): void
    {
        $this->transport->register('chat_simple');
        $client = $this->client();
        $client->chat('first');
        $client->response('one-off');
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame(['user'], array_column($body['messages'], 'role'));

        $client->chat('second');
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        // Only the chat() turns are in history; the one-off never joined it
        $userContents = [];
        foreach ($body['messages'] as $message) {
            if ($message['role'] === 'user') {
                $userContents[] = $message['content'];
            }
        }
        $this->assertSame(['first', 'second'], $userContents);
    }

    public function testSchemaResponseParsedAndSchemaSent(): void
    {
        $fixture = $this->transport->register('response_struct');
        $result = $this->client()->structuredResponse(
            'Give me basic facts about Wellington, New Zealand.',
            self::CITY_INFO_SCHEMA,
            'CityInfo'
        );

        $content = $fixture['response']['choices'][0]['message']['content'];
        $this->assertSame(json_decode($content, true), $result);
        $this->assertSame('Wellington', $result['name']);
        $this->assertIsInt($result['population']);

        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $responseFormat = $body['response_format'];
        $this->assertSame('json_schema', $responseFormat['type']);
        $this->assertSame('CityInfo', $responseFormat['json_schema']['name']);
        // The schema itself goes over the wire as "schema"
        $this->assertSame(self::CITY_INFO_SCHEMA, $responseFormat['json_schema']['schema']);
    }

    public function testNullSchemaUsesJsonObjectMode(): void
    {
        $fixture = $this->transport->register('response_json_object');
        $result = $this->client()->structuredResponse('Return a JSON object.');
        $content = $fixture['response']['choices'][0]['message']['content'];
        $this->assertSame(json_decode($content, true), $result);
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame(['type' => 'json_object'], $body['response_format']);
    }

    public function testTruncatedResponseRaisesHelpfulError(): void
    {
        $this->transport->register('response_truncated');
        $this->expectException(ResponseParseException::class);
        $this->expectExceptionMessage('token limit');
        $this->client()->structuredResponse(
            'facts please',
            self::CITY_INFO_SCHEMA,
            'CityInfo',
            'Qwen/Qwen3.8-27B',
            10
        );
    }

    public function testProseResponseRaisesNotValidJson(): void
    {
        // The server occasionally skips the schema with thinking enabled and
        // returns prose; parsing must name that failure rather than crash
        $this->respondWithContent('Sure! Wellington is the capital of New Zealand.');
        $this->expectException(ResponseParseException::class);
        $this->expectExceptionMessage('not valid JSON');
        $this->client()->structuredResponse('facts please', self::CITY_INFO_SCHEMA);
    }

    public function testMissingContentRaises(): void
    {
        $this->respondWithContent(null);
        $this->expectException(ResponseParseException::class);
        $this->expectExceptionMessage('no text content');
        $this->client()->structuredResponse('facts please', self::CITY_INFO_SCHEMA);
    }

    public function testScalarContentRaises(): void
    {
        // A scalar top level is valid JSON but not a usable structured result
        $this->respondWithContent('42');
        $this->expectException(ResponseParseException::class);
        $this->expectExceptionMessage('scalar');
        $this->client()->structuredResponse('facts please');
    }

    /**
     * Serve the response_struct fixture with its message content replaced.
     */
    private function respondWithContent(?string $content): void
    {
        $fixture = Fixtures::load('response_struct');
        $fixture['response']['choices'][0]['message']['content'] = $content;
        $this->transport->respond('POST', '/v1/chat/completions', $fixture['response']);
    }
}
