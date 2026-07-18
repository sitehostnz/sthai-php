<?php

declare(strict_types=1);

namespace SthAI\Tests\Support;

/**
 * Captures E_USER_WARNING messages raised while a callable runs, letting
 * execution continue - the equivalent of pytest.warns for the client's
 * trigger_error() warnings (PHPUnit's expectWarning() is deprecated and
 * would abort the call under test).
 */
final class WarningCollector
{
    /**
     * Run the callable, returning [result, collected warning messages].
     *
     * @return array{0: mixed, 1: string[]}
     */
    public static function collect(callable $callable): array
    {
        $warnings = [];
        set_error_handler(
            static function (int $errno, string $message) use (&$warnings): bool {
                $warnings[] = $message;

                return true; // handled: suppress normal reporting
            },
            E_USER_WARNING
        );

        try {
            $result = $callable();
        } finally {
            restore_error_handler();
        }

        return [$result, $warnings];
    }
}
