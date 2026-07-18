<?php

declare(strict_types=1);

namespace SthAI\Tests\Support;

use SthAI\Transport\HttpResponse;
use SthAI\Transport\TransportInterface;

/**
 * Routes (method, path) to canned responses and records every call,
 * mirroring the Python suite's MockBackend. Tests exercise the full
 * client path - URL building, headers, body encoding - without any
 * network access.
 */
final class MockTransport implements TransportInterface
{
    /** @var array<string, array{int, string}> keyed "METHOD path" */
    private array $routes = [];

    /** @var Call[] */
    public array $calls = [];

    /**
     * Route a captured fixture by name; returns it for assertions.
     *
     * @return array<string, mixed>
     */
    public function register(string $name): array
    {
        $fixture = Fixtures::load($name);
        $this->respond(
            $fixture['method'],
            $fixture['endpoint'],
            $fixture['response'],
            $fixture['status_code']
        );

        return $fixture;
    }

    /**
     * Route an arbitrary response body (arrays are encoded as JSON,
     * strings pass through raw).
     *
     * @param array<string, mixed>|string $body
     */
    public function respond(string $method, string $endpoint, $body, int $statusCode = 200): void
    {
        $content = is_string($body)
            ? $body
            : json_encode($body, JSON_THROW_ON_ERROR);
        $this->routes[$method . ' ' . $endpoint] = [$statusCode, $content];
    }

    public function lastCall(): Call
    {
        if ($this->calls === []) {
            throw new \RuntimeException('no calls were made to the mock transport');
        }

        return $this->calls[count($this->calls) - 1];
    }

    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $this->calls[] = new Call(
            $method,
            $url,
            $path,
            $headers,
            $body !== null ? json_decode($body, true, 512, JSON_THROW_ON_ERROR) : null
        );

        $route = $this->routes[$method . ' ' . $path] ?? null;
        if ($route === null) {
            throw new \RuntimeException(sprintf('no fixture registered for %s %s', $method, $path));
        }

        return new HttpResponse($route[0], $route[1]);
    }
}
