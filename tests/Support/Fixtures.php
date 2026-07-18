<?php

declare(strict_types=1);

namespace SthAI\Tests\Support;

/**
 * Loader for the captured live exchanges in tests/fixtures/. The fixture
 * files are shared verbatim with sthai-py; each records the endpoint,
 * method, status code, the request that was sent and the response body.
 */
final class Fixtures
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $name): array
    {
        $path = __DIR__ . '/../fixtures/' . $name . '.json';
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('missing fixture: ' . $path);
        }

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
