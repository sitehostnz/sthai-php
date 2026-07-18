<?php

declare(strict_types=1);

namespace SthAI\Internal;

use SthAI\Exception\InvalidArgumentException;

/**
 * Renderer for the {instruction}/{text} embedding templates, matching the
 * semantics of Python's str.format for the subset the client uses:
 * exactly those two placeholders, with {{ and }} as literal-brace escapes.
 *
 * @internal
 */
final class Template
{
    // Sentinels the escaped braces are parked in during substitution;
    // control bytes that cannot appear in a legitimate template
    private const OPEN_SENTINEL = "\x00";
    private const CLOSE_SENTINEL = "\x01";

    /**
     * Render the template, rejecting any placeholder other than
     * {instruction} and {text}.
     */
    public static function render(string $template, ?string $instruction, string $text): string
    {
        // Park escaped braces first so they are invisible to validation
        $working = str_replace(
            ['{{', '}}'],
            [self::OPEN_SENTINEL, self::CLOSE_SENTINEL],
            $template
        );

        // With escapes parked and the two valid placeholders removed, any
        // remaining brace is a stray placeholder or an unbalanced brace
        if (preg_match('/[{}]/', str_replace(['{instruction}', '{text}'], '', $working)) === 1) {
            throw new InvalidArgumentException(
                'template must use only the {instruction} and {text} placeholders; '
                . 'escape literal braces as {{ and }}'
            );
        }

        // strtr substitutes in a single parallel pass, so an instruction
        // containing "{text}" cannot be expanded a second time
        $rendered = strtr($working, [
            '{instruction}' => (string) $instruction,
            '{text}' => $text,
        ]);

        return str_replace(
            [self::OPEN_SENTINEL, self::CLOSE_SENTINEL],
            ['{', '}'],
            $rendered
        );
    }

    /**
     * Whether the template contains the named placeholder (escaped braces
     * do not count).
     */
    public static function hasPlaceholder(string $template, string $name): bool
    {
        $working = str_replace(['{{', '}}'], '', $template);

        return strpos($working, '{' . $name . '}') !== false;
    }
}
