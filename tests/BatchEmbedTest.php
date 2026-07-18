<?php

declare(strict_types=1);

namespace SthAI\Tests;

use SthAI\Exception\InvalidArgumentException;
use SthAI\Internal\Template;
use SthAI\Model\EmbeddingParams;
use SthAI\Tests\Support\ClientTestCase;
use SthAI\Tests\Support\Fixtures;
use SthAI\Tests\Support\WarningCollector;

/**
 * batchEmbed(): local templating, result ordering, and guards.
 */
final class BatchEmbedTest extends ClientTestCase
{
    private const TEXTS = [
        'Wellington is the capital of New Zealand.',
        'Auckland is the largest city in New Zealand.',
        'The kiwi is a flightless bird.',
    ];

    public function testOneVectorPerTextInOrder(): void
    {
        $fixture = $this->transport->register('batch_embed');
        $vectors = $this->client()->batchEmbed(
            self::TEXTS,
            'Qwen/Qwen3-VL-Embedding-8B',
            false,
            null,
            null,
            32
        );
        $expected = array_column($fixture['response']['data'], 'embedding');
        $this->assertSame($expected, $vectors);
        foreach ($vectors as $vector) {
            $this->assertCount(32, $vector);
        }
    }

    public function testOutOfOrderResponseResortedByIndex(): void
    {
        $fixture = Fixtures::load('batch_embed');
        $byIndex = array_column($fixture['response']['data'], null, 'index');
        $fixture['response']['data'] = [$byIndex[2], $byIndex[0], $byIndex[1]];
        $this->transport->respond('POST', '/v1/embeddings', $fixture['response']);

        $vectors = $this->client()->batchEmbed(self::TEXTS);
        $this->assertSame(
            [$byIndex[0]['embedding'], $byIndex[1]['embedding'], $byIndex[2]['embedding']],
            $vectors
        );
    }

    public function testInputsAreLocallyTemplated(): void
    {
        $this->transport->register('batch_embed');
        $this->client()->batchEmbed(self::TEXTS);
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $expected = [];
        foreach (self::TEXTS as $text) {
            $expected[] = Template::render(
                EmbeddingParams::QWEN_3_VL_EMBEDDING_TEMPLATE,
                EmbeddingParams::DOCUMENT_INSTRUCTION,
                $text
            );
        }
        $this->assertSame($expected, $body['input']);
        $this->assertSame('float', $body['encoding_format']);
    }

    public function testCustomRawTemplate(): void
    {
        $this->transport->register('batch_embed');
        $this->client()->batchEmbed(self::TEXTS, 'unknown/model', false, null, '{text}');
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame(self::TEXTS, $body['input']);
    }

    public function testEmptyListRaises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one text');
        $this->client()->batchEmbed([]);
    }

    public function testEmptyStringMemberRaises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty strings');
        $this->client()->batchEmbed(['fine', '']);
    }

    public function testUnknownModelWithoutTemplateRaises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no known embedding template');
        $this->client()->batchEmbed(['text'], 'unknown/model');
    }

    public function testTemplateWithoutInstructionPlaceholderWarns(): void
    {
        $this->transport->register('batch_embed');
        $client = $this->client();
        [, $warnings] = WarningCollector::collect(static function () use ($client) {
            return $client->batchEmbed(self::TEXTS, 'Qwen/Qwen3-VL-Embedding-8B', true, null, '{text}');
        });
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('no {instruction} placeholder', $warnings[0]);
    }

    public function testStrayTemplatePlaceholderRaises(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('escape literal braces');
        $this->client()->batchEmbed(['text'], 'Qwen/Qwen3-VL-Embedding-8B', false, null, '{text} {oops}');
    }
}
