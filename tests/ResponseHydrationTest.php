<?php

declare(strict_types=1);

namespace SthAI\Tests;

use PHPUnit\Framework\TestCase;
use SthAI\Response\EmbeddingResponse;
use SthAI\Response\InferenceResponse;
use SthAI\Response\ModelCard;
use SthAI\Response\RerankResponse;
use SthAI\Response\UsageInfo;

final class ResponseHydrationTest extends TestCase
{
    public function testUsageWireKeyHydratesUsageInfo(): void
    {
        $response = InferenceResponse::fromArray([
            'id' => 'x',
            'model' => 'm',
            'choices' => [],
            'usage' => ['prompt_tokens' => 7, 'total_tokens' => 7],
        ]);
        $this->assertSame(7, $response->usageInfo->promptTokens);
    }

    public function testUsageSummaryIncludesCachedTokens(): void
    {
        $info = UsageInfo::fromArray([
            'prompt_tokens' => 100,
            'total_tokens' => 140,
            'completion_tokens' => 40,
            'prompt_tokens_details' => ['cached_tokens' => 64],
        ]);
        $usage = $info->summary();
        $this->assertSame(100, $usage->inputTokens);
        $this->assertSame(40, $usage->outputTokens);
        $this->assertSame(64, $usage->cachedTokens);
    }

    public function testUsageSummaryDefaultsMissingDetailsToZero(): void
    {
        $info = UsageInfo::fromArray([
            'prompt_tokens' => 10,
            'total_tokens' => 10,
            'completion_tokens' => null,
        ]);
        $usage = $info->summary();
        $this->assertSame(10, $usage->inputTokens);
        $this->assertSame(0, $usage->outputTokens);
        $this->assertSame(0, $usage->cachedTokens);
    }

    public function testOutputOnEmptyChoicesIsEmpty(): void
    {
        $response = InferenceResponse::fromArray([
            'id' => 'x',
            'model' => 'm',
            'choices' => [],
            'usage' => [],
        ]);
        $output = $response->output();
        $this->assertNull($output->text);
        $this->assertNull($output->reasoning);
    }

    public function testOutputCarriesTextAndReasoning(): void
    {
        $response = InferenceResponse::fromArray([
            'id' => 'x',
            'model' => 'm',
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'answer',
                        'reasoning' => 'because',
                    ],
                    'finish_reason' => 'stop',
                ],
            ],
            'usage' => [],
        ]);
        $this->assertSame('answer', $response->output()->text);
        $this->assertSame('because', $response->output()->reasoning);
        $this->assertSame('stop', $response->choices[0]->finishReason);
    }

    public function testRerankUsageIsInputOnly(): void
    {
        $response = RerankResponse::fromArray([
            'id' => 'x',
            'model' => 'm',
            'usage' => ['prompt_tokens' => 9, 'total_tokens' => 9],
            'results' => [],
        ]);
        $usage = $response->usage();
        $this->assertSame(9, $usage->inputTokens);
        $this->assertSame(0, $usage->outputTokens);
    }

    public function testRerankOutputReturnsHydratedResults(): void
    {
        $response = RerankResponse::fromArray([
            'id' => 'x',
            'model' => 'm',
            'usage' => [],
            'results' => [
                ['index' => 1, 'document' => ['text' => 'a', 'multi_modal' => null], 'relevance_score' => 0.9],
                ['index' => 0, 'document' => ['text' => 'b', 'multi_modal' => null], 'relevance_score' => 0.1],
            ],
        ]);
        $results = $response->output();
        $this->assertCount(2, $results);
        // Results keep the server's order; index maps back to the input list
        $this->assertSame(1, $results[0]->index);
        $this->assertSame('a', $results[0]->document->text);
        $this->assertSame(0.9, $results[0]->relevanceScore);
    }

    public function testEmbeddingOutputSortsByIndex(): void
    {
        $response = EmbeddingResponse::fromArray([
            'id' => 'x',
            'data' => [
                ['index' => 1, 'embedding' => [0.2]],
                ['index' => 0, 'embedding' => [0.1]],
            ],
            'usage' => ['prompt_tokens' => 3, 'total_tokens' => 3],
        ]);
        $this->assertSame([[0.1], [0.2]], $response->output());
        $this->assertSame(3, $response->usage()->inputTokens);
    }

    public function testModelCardHydration(): void
    {
        $card = ModelCard::fromArray([
            'id' => 'Qwen/Qwen3.6-27B',
            'object' => 'model',
            'created' => 1783894642,
            'owned_by' => 'sitehost',
        ]);
        $this->assertSame('Qwen/Qwen3.6-27B', $card->id);
        $this->assertSame('sitehost', $card->ownedBy);
        $this->assertNull($card->maxModelLen);
        $this->assertSame('model', $card->toArray()['object']);
    }

    public function testToArrayKeepsTheFullPayload(): void
    {
        $payload = [
            'id' => 'x',
            'model' => 'm',
            'choices' => [],
            'usage' => [],
            'system_fingerprint' => 'c03-infer-a',
            'kv_transfer_params' => null,
        ];
        $this->assertSame($payload, InferenceResponse::fromArray($payload)->toArray());
    }
}
