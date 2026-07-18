<?php

declare(strict_types=1);

namespace SthAI\Transport;

use SthAI\Exception\TransportException;

/**
 * Default transport built on ext-curl. Deliberately thin: it contains no
 * logic beyond curl plumbing, so everything above it can be tested offline
 * against a mock transport.
 */
final class CurlTransport implements TransportInterface
{
    private int $timeout;

    private int $connectTimeout;

    public function __construct(int $timeout = 120, int $connectTimeout = 10)
    {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * @param non-empty-string      $method
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportException('failed to initialize curl');
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($handle);
        if ($responseBody === false) {
            $error = curl_error($handle);
            $errno = curl_errno($handle);
            curl_close($handle);

            throw new TransportException(sprintf('curl error %d: %s', $errno, $error));
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        // CURLOPT_RETURNTRANSFER makes curl_exec return the body string
        return new HttpResponse($statusCode, (string) $responseBody);
    }
}
