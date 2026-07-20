<?php

declare(strict_types=1);

namespace SthAI\Tests;

use SthAI\Exception\InputException;
use SthAI\Response\RerankResult;
use SthAI\Tests\Support\ClientTestCase;

/**
 * rerank(): result ordering, wire shape, and guards.
 */
final class RerankTest extends ClientTestCase
{
    private const QUERY = 'What is the capital of New Zealand?';
    private const DOCUMENTS = [
        'The capital of New Zealand is Wellington.',
        'Auckland has the largest population in New Zealand.',
        'Kiwi are nocturnal flightless birds native to New Zealand.',
        "The All Blacks are New Zealand's national rugby team.",
    ];

    public function testResultsSortedByRelevance(): void
    {
        $this->transport->register('rerank');
        $results = $this->client()->rerank(self::QUERY, self::DOCUMENTS);
        $this->assertCount(count(self::DOCUMENTS), $results);
        $this->assertContainsOnlyInstancesOf(RerankResult::class, $results);

        $scores = array_map(static function (RerankResult $result): float {
            return $result->relevanceScore;
        }, $results);
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);

        // Each index maps back to the position in the input documents list
        foreach ($results as $result) {
            $this->assertSame(self::DOCUMENTS[$result->index], $result->document->text);
        }
    }

    public function testMinimalRequestBody(): void
    {
        $this->transport->register('rerank');
        $this->client()->rerank(self::QUERY, self::DOCUMENTS);
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame(self::QUERY, $body['query']);
        $this->assertSame(self::DOCUMENTS, $body['documents']);
        // Null means "return all documents": the server default applies
        $this->assertArrayNotHasKey('top_n', $body);
        $this->assertArrayNotHasKey('instruction', $body);
    }

    public function testTopNLimitsResults(): void
    {
        $this->transport->register('rerank_top_n');
        $results = $this->client()->rerank(self::QUERY, self::DOCUMENTS, 'Qwen/Qwen3-VL-Reranker-8B', 2);
        $this->assertCount(2, $results);
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame(2, $body['top_n']);
    }

    public function testInstructionPassthrough(): void
    {
        $this->transport->register('rerank');
        $this->client()->rerank(
            self::QUERY,
            self::DOCUMENTS,
            'Qwen/Qwen3-VL-Reranker-8B',
            null,
            'Rank by factual accuracy.'
        );
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame('Rank by factual accuracy.', $body['instruction']);
    }

    public function testMultimodalQueryEncoding(): void
    {
        $this->transport->register('rerank');
        $query = ['content' => [['type' => 'text', 'text' => self::QUERY]]];
        $this->client()->rerank($query, self::DOCUMENTS);
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame($query, $body['query']);
    }

    public function testEmptyDocumentsRaises(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('at least one document');
        $this->client()->rerank(self::QUERY, []);
    }

    public function testZeroTopNRaises(): void
    {
        $this->expectException(InputException::class);
        $this->expectExceptionMessage('positive integer');
        $this->client()->rerank(self::QUERY, self::DOCUMENTS, 'Qwen/Qwen3-VL-Reranker-8B', 0);
    }
}
