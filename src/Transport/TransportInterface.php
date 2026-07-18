<?php

declare(strict_types=1);

namespace SthAI\Transport;

use SthAI\Exception\TransportException;

/**
 * Sends HTTP requests for the client. Implementations report HTTP error
 * statuses through the returned HttpResponse rather than throwing; only
 * transport-level failures (connection, timeout, TLS) throw.
 */
interface TransportInterface
{
    /**
     * @param non-empty-string      $method  HTTP method, e.g. 'GET' or 'POST'
     * @param array<string, string> $headers header name => value
     * @param string|null           $body    raw request body, or null for body-less requests
     *
     * @throws TransportException when the request cannot be completed
     */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse;
}
