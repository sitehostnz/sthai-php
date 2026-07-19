<?php

declare(strict_types=1);

namespace SthAI\Tests;

use SthAI\Exception\HttpException;
use SthAI\Exception\InvalidArgumentException;
use SthAI\Response\InferenceResponse;
use SthAI\Tests\Support\ClientTestCase;

/**
 * chat(): responses, request wiring, and conversation history semantics.
 */
final class ChatTest extends ClientTestCase
{
    private const PNG = "\x89PNG\r\n\x1a\nrest-of-file";

    public function testReturnsInferenceResponse(): void
    {
        $fixture = $this->transport->register('chat_simple');
        $response = $this->client()->chat('Reply with exactly: kia ora');
        $this->assertInstanceOf(InferenceResponse::class, $response);
        $message = $fixture['response']['choices'][0]['message'];
        $this->assertSame($message['content'], $response->output()->text);
    }

    public function testUsageSummaryMatchesFixture(): void
    {
        $fixture = $this->transport->register('chat_simple');
        $usage = $this->client()->chat('hello')->usage();
        $wireUsage = $fixture['response']['usage'];
        $this->assertSame($wireUsage['prompt_tokens'], $usage->inputTokens);
        $this->assertSame($wireUsage['completion_tokens'], $usage->outputTokens);
    }

    public function testThinkingResponseHasReasoning(): void
    {
        $this->transport->register('chat_thinking');
        $client = $this->client();
        $response = $client->chat('What is 17 + 25?', 'Qwen/Qwen3.6-27B', null, null, true);
        $this->assertNotNull($response->output()->reasoning);
        $this->assertNotSame('', $response->output()->reasoning);
        $this->assertSame($response->output()->reasoning, $client->lastReasoning());
    }

    public function testLastResponseReturnsDecoded(): void
    {
        $this->transport->register('chat_simple');
        $client = $this->client();
        $this->assertNull($client->lastResponse());
        $response = $client->chat('hello');
        $this->assertSame($response, $client->lastResponse());
    }

    public function testMinimalRequestBody(): void
    {
        $this->transport->register('chat_simple');
        $this->client()->chat('hello');
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        // Unset optionals must be omitted entirely, not sent as null
        $this->assertSame(['messages', 'model', 'chat_template_kwargs'], array_keys($body));
        $this->assertSame([['role' => 'user', 'content' => 'hello']], $body['messages']);
        $this->assertSame(['enable_thinking' => false], $body['chat_template_kwargs']);
    }

    public function testOptionalParamsOnTheWire(): void
    {
        $this->transport->register('chat_simple');
        $this->client()->chat('hello', 'Qwen/Qwen3.6-27B', 50, 0.2, true);
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        // maxTokens maps to the non-deprecated max_completion_tokens field
        $this->assertSame(50, $body['max_completion_tokens']);
        $this->assertArrayNotHasKey('max_tokens', $body);
        $this->assertSame(0.2, $body['temperature']);
        $this->assertSame(['enable_thinking' => true], $body['chat_template_kwargs']);
    }

    public function testImagePartsOnTheWire(): void
    {
        $this->transport->register('chat_simple');
        $this->client()->chat(
            "What's in this image?",
            'Qwen/Qwen3.6-27B',
            null,
            null,
            false,
            null,
            ['https://example.com/a.jpg'],
            [$this->pngFixtureFile()]
        );
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $content = $body['messages'][0]['content'];
        $this->assertSame('text', $content[0]['type']);
        $this->assertSame("What's in this image?", $content[0]['text']);
        $this->assertSame('https://example.com/a.jpg', $content[1]['image_url']['url']);
        $this->assertStringStartsWith('data:image/png;base64,', $content[2]['image_url']['url']);
    }

    public function testHistoryAccumulates(): void
    {
        $fixture = $this->transport->register('chat_simple');
        $client = $this->client();
        $client->chat('first');
        $client->chat('second');
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $messages = $body['messages'];
        $this->assertSame(['user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertSame('first', $messages[0]['content']);
        $this->assertSame(
            $fixture['response']['choices'][0]['message']['content'],
            $messages[1]['content']
        );
        $this->assertSame('second', $messages[2]['content']);
    }

    public function testUseHistoryFalseNeitherSendsNorRecords(): void
    {
        $this->transport->register('chat_simple');
        $client = $this->client();
        $client->chat('first');
        $client->chat('standalone', 'Qwen/Qwen3.6-27B', null, null, false, null, [], [], false);
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame(['user'], array_column($body['messages'], 'role'));

        $client->chat('third');
        // The standalone turn must not have leaked into the stored history
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $userContents = [];
        foreach ($body['messages'] as $message) {
            if ($message['role'] === 'user') {
                $userContents[] = $message['content'];
            }
        }
        $this->assertSame(['first', 'third'], $userContents);
    }

    public function testWriteHistoryFalseNeverRecords(): void
    {
        $this->transport->register('chat_simple');
        $ephemeral = $this->client(['writeHistory' => false]);
        $ephemeral->chat('first');
        $ephemeral->chat('second');
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame(['user'], array_column($body['messages'], 'role'));
    }

    public function testSystemPromptPrependedNotStored(): void
    {
        $this->transport->register('chat_simple');
        $client = $this->client();
        $client->chat('first', 'Qwen/Qwen3.6-27B', null, null, false, 'Be terse.');
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame(['role' => 'system', 'content' => 'Be terse.'], $body['messages'][0]);

        $client->chat('second');
        // No system prompt this call, so none appears - it is per-call only
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame(['user', 'assistant', 'user'], array_column($body['messages'], 'role'));
    }

    public function testClearHistory(): void
    {
        $this->transport->register('chat_simple');
        $client = $this->client();
        $client->chat('first');
        $client->clearHistory();
        $client->chat('second');
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame(['user'], array_column($body['messages'], 'role'));
    }

    public function testHistoryGetterReturnsStoredTurns(): void
    {
        $fixture = $this->transport->register('chat_simple');
        $client = $this->client();
        $this->assertSame([], $client->history());

        $client->chat('first');
        $this->assertSame([
            ['role' => 'user', 'content' => 'first'],
            ['role' => 'assistant', 'content' => $fixture['response']['choices'][0]['message']['content']],
        ], $client->history());
    }

    public function testSetHistoryRestoresConversation(): void
    {
        $this->transport->register('chat_simple');
        $client = $this->client();
        $client->setHistory([
            ['role' => 'user', 'content' => 'earlier question'],
            ['role' => 'assistant', 'content' => 'earlier answer'],
        ]);

        $client->chat('follow-up');
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame(['user', 'assistant', 'user'], array_column($body['messages'], 'role'));
        $this->assertSame('earlier question', $body['messages'][0]['content']);
        $this->assertSame('follow-up', $body['messages'][2]['content']);
    }

    public function testSetHistoryRejectsMalformedTurns(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('history turn');
        $this->client()->setHistory([['role' => 'user']]);
    }

    public function testWriteHistoryAccessors(): void
    {
        $this->transport->register('chat_simple');
        $client = $this->client();
        $this->assertTrue($client->writeHistory());

        $client->setWriteHistory(false);
        $this->assertFalse($client->writeHistory());
        $client->chat('first');
        $this->assertSame([], $client->history());
    }

    public function testHistoryUsageAccumulates(): void
    {
        $fixture = $this->transport->register('chat_simple');
        $client = $this->client();
        $this->assertSame(0, $client->historyUsage()->inputTokens);

        $client->chat('first');
        $client->chat('second');
        $usage = $fixture['response']['usage'];
        $total = $client->historyUsage();
        $this->assertSame($usage['prompt_tokens'] * 2, $total->inputTokens);
        $this->assertSame($usage['completion_tokens'] * 2, $total->outputTokens);
    }

    public function testHistoryUsageExcludesUnrecordedCalls(): void
    {
        $this->transport->register('chat_simple');
        $client = $this->client();
        $client->chat('standalone', 'Qwen/Qwen3.6-27B', null, null, false, null, [], [], false);
        $this->assertSame(0, $client->historyUsage()->inputTokens);
    }

    public function testHistoryUsageResetsWithHistory(): void
    {
        $this->transport->register('chat_simple');
        $client = $this->client();
        $client->chat('first');
        $client->clearHistory();
        $this->assertSame(0, $client->historyUsage()->inputTokens);

        $client->chat('again');
        $client->setHistory([['role' => 'user', 'content' => 'restored']]);
        $this->assertSame(0, $client->historyUsage()->inputTokens);
    }

    public function testFailedCallDoesNotWriteHistory(): void
    {
        $this->transport->register('error_bad_model');
        $client = $this->client();

        try {
            $client->chat('doomed');
            $this->fail('expected HttpException');
        } catch (HttpException $exception) {
            $this->assertGreaterThanOrEqual(400, $exception->getStatusCode());
        }

        $this->transport->register('chat_simple');
        $client->chat('second');
        $body = $this->transport->lastCall()->body;
        $this->assertNotNull($body);
        $this->assertSame(['user'], array_column($body['messages'], 'role'));
    }

    /**
     * A temp PNG file for image_files-style input, cleaned up afterwards.
     */
    private function pngFixtureFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sthai-test-');
        $this->assertNotFalse($path);
        file_put_contents($path, self::PNG);
        // tempnam files persist; make sure the test run removes them
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });

        return $path;
    }
}
