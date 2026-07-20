<?php

declare(strict_types=1);

namespace SthAI\Tests;

use SthAI\Exception\InputException;
use SthAI\Exception\ResponseException;
use SthAI\Image;
use SthAI\Model\EmbeddingParams;
use SthAI\Tests\Support\ClientTestCase;
use SthAI\Tests\Support\Fixtures;
use SthAI\Tests\Support\WarningCollector;

/**
 * embed(): single-input embeddings, instruction steering, and guards.
 */
final class EmbedTest extends ClientTestCase
{
    // Image sniffing only reads magic bytes, so a stub PNG is enough
    private const FAKE_PNG = "\x89PNG\r\n\x1a\nnot-a-real-image";

    public function testReturnsFloatVector(): void
    {
        $fixture = $this->transport->register('embed_single');
        $vector = $this->client()->embed(
            'The Beehive.',
            'Qwen/Qwen3-VL-Embedding-8B',
            false,
            null,
            [],
            [],
            32
        );
        $this->assertSame($fixture['response']['data'][0]['embedding'], $vector);
        $this->assertCount(32, $vector);
        $this->assertContainsOnly('float', $vector);
    }

    public function testChatFormRequestShape(): void
    {
        $this->transport->register('embed_single');
        $this->client()->embed('The Beehive.', 'Qwen/Qwen3-VL-Embedding-8B', false, null, [], [], 32);
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame([
            ['role' => 'system', 'content' => EmbeddingParams::DOCUMENT_INSTRUCTION],
            ['role' => 'user', 'content' => 'The Beehive.'],
            // The open assistant turn matches how the model was trained to embed
            ['role' => 'assistant', 'content' => ''],
        ], $body['messages']);
        $this->assertSame('float', $body['encoding_format']);
        $this->assertSame(32, $body['dimensions']);
        $this->assertTrue($body['continue_final_message']);
        // Overrides the chat-form server default so tokenization matches
        // batchEmbed's plain-input form
        $this->assertTrue($body['add_special_tokens']);
    }

    public function testDimensionsOmittedWhenNotGiven(): void
    {
        $this->transport->register('embed_single');
        $this->client()->embed('The Beehive.');
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertArrayNotHasKey('dimensions', $body);
    }

    public function testQueryUsesQueryInstruction(): void
    {
        $this->transport->register('embed_single');
        $this->client()->embed('capital of NZ?', 'Qwen/Qwen3-VL-Embedding-8B', true);
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame(
            ['role' => 'system', 'content' => EmbeddingParams::QUERY_INSTRUCTION],
            $body['messages'][0]
        );
    }

    public function testExplicitInstructionOverrides(): void
    {
        $this->transport->register('embed_single');
        $this->client()->embed('some text', 'Qwen/Qwen3-VL-Embedding-8B', true, 'Embed for clustering.');
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame(
            ['role' => 'system', 'content' => 'Embed for clustering.'],
            $body['messages'][0]
        );
    }

    public function testMultimodalContentParts(): void
    {
        $this->transport->register('embed_multimodal');
        $vector = $this->client()->embed(
            'A small red square.',
            'Qwen/Qwen3-VL-Embedding-8B',
            false,
            null,
            [Image::dataUriFromBytes(self::FAKE_PNG)]
        );
        $this->assertNotEmpty($vector);
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $userContent = $body['messages'][1]['content'];
        $this->assertSame(['type' => 'text', 'text' => 'A small red square.'], $userContent[0]);
        $this->assertSame('image_url', $userContent[1]['type']);
        $this->assertStringStartsWith('data:image/png;base64,', $userContent[1]['image_url']['url']);
    }

    public function testNoInputRaises(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('requires text and/or images');
        $this->client()->embed();
    }

    public function testEmptyStringTreatedAsNoText(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('requires text and/or images');
        $this->client()->embed('');
    }

    public function testZeroDimensionsRaises(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('positive integer');
        $this->client()->embed('text', 'Qwen/Qwen3-VL-Embedding-8B', false, null, [], [], 0);
    }

    public function testOversizedDimensionsWarns(): void
    {
        $this->transport->register('embed_single');
        $client = $this->client();
        [, $warnings] = WarningCollector::collect(static function () use ($client) {
            return $client->embed('text', 'Qwen/Qwen3-VL-Embedding-8B', false, null, [], [], 8192);
        });
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('exceeds the native 4096', $warnings[0]);
    }

    public function testUnevenDimensionsWarns(): void
    {
        $this->transport->register('embed_single');
        $client = $this->client();
        [, $warnings] = WarningCollector::collect(static function () use ($client) {
            return $client->embed('text', 'Qwen/Qwen3-VL-Embedding-8B', false, null, [], [], 3);
        });
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('does not divide evenly', $warnings[0]);
        $this->assertStringContainsString('2048, 1024', $warnings[0]);
    }

    public function testEmptyResponseDataRaises(): void
    {
        $fixture = Fixtures::load('embed_single');
        $fixture['response']['data'] = [];
        $this->transport->respond('POST', '/v1/embeddings', $fixture['response']);
        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('no embedding data');
        $this->client()->embed('text');
    }
}
