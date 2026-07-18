<?php

declare(strict_types=1);

namespace SthAI\Tests;

use PHPUnit\Framework\TestCase;
use SthAI\Exception\InvalidArgumentException;
use SthAI\Internal\Template;
use SthAI\Model\EmbeddingParams;

final class TemplateTest extends TestCase
{
    public function testRendersBothPlaceholders(): void
    {
        $this->assertSame(
            'sys: guide, user: hello',
            Template::render('sys: {instruction}, user: {text}', 'guide', 'hello')
        );
    }

    public function testNullInstructionRendersEmpty(): void
    {
        $this->assertSame(': hello', Template::render('{instruction}: {text}', null, 'hello'));
    }

    public function testTextOnlyTemplatePassesTextThrough(): void
    {
        $this->assertSame('raw input', Template::render('{text}', 'ignored', 'raw input'));
    }

    public function testEscapedBracesBecomeLiterals(): void
    {
        $this->assertSame('{"json": "hello"}', Template::render('{{"json": "{text}"}}', null, 'hello'));
    }

    public function testInstructionContainingPlaceholderIsNotReExpanded(): void
    {
        // strtr substitutes in one parallel pass, unlike sequential replaces
        $this->assertSame(
            '{text} then hello',
            Template::render('{instruction} then {text}', '{text}', 'hello')
        );
    }

    public function testUnknownPlaceholderIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Template::render('{other} {text}', null, 'hello');
    }

    public function testUnbalancedBraceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Template::render('broken { {text}', null, 'hello');
    }

    public function testHasPlaceholder(): void
    {
        $this->assertTrue(Template::hasPlaceholder('{instruction} {text}', 'instruction'));
        $this->assertFalse(Template::hasPlaceholder('{text}', 'instruction'));
        // Escaped braces do not count as a placeholder
        $this->assertFalse(Template::hasPlaceholder('{{instruction}}', 'instruction'));
    }

    public function testQwenEmbeddingTemplateRendersLikePython(): void
    {
        // Mirrors what sthai-py's str.format produces for the built-in template
        $rendered = Template::render(
            EmbeddingParams::QWEN_3_VL_EMBEDDING_TEMPLATE,
            EmbeddingParams::DOCUMENT_INSTRUCTION,
            'some document'
        );
        $this->assertSame(
            "<|im_start|>system\nRepresent the user's input.<|im_end|>\n"
            . "<|im_start|>user\nsome document<|im_end|>\n"
            . "<|im_start|>assistant\n",
            $rendered
        );
    }

    public function testEmbeddingParamsForKnownModel(): void
    {
        $params = EmbeddingParams::forModel('Qwen/Qwen3-VL-Embedding-8B');
        $this->assertNotNull($params);
        $this->assertSame(4096, $params->getDimensions());
        $this->assertSame(EmbeddingParams::DOCUMENT_INSTRUCTION, $params->getInstruction(false));
        $this->assertSame(EmbeddingParams::QUERY_INSTRUCTION, $params->getInstruction(true));
        $this->assertSame(EmbeddingParams::QWEN_3_VL_EMBEDDING_TEMPLATE, $params->getTemplate());
    }

    public function testEmbeddingParamsForUnknownModelIsNull(): void
    {
        $this->assertNull(EmbeddingParams::forModel('acme/unknown'));
    }
}
