<?php

declare(strict_types=1);

namespace SthAI\Tests\Support;

/**
 * One request the client sent to the mock transport.
 */
final class Call
{
    public string $method;

    public string $url;

    public string $path;

    /** @var array<string, string> */
    public array $headers;

    /**
     * The request body decoded from JSON, or null for body-less requests.
     *
     * @var array<string, mixed>|null
     */
    public ?array $body;

    /**
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $body
     */
    public function __construct(string $method, string $url, string $path, array $headers, ?array $body)
    {
        $this->method = $method;
        $this->url = $url;
        $this->path = $path;
        $this->headers = $headers;
        $this->body = $body;
    }
}
