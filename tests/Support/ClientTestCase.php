<?php

declare(strict_types=1);

namespace SthAI\Tests\Support;

use PHPUnit\Framework\TestCase;
use SthAI\Client;

/**
 * Base case wiring a fresh MockTransport into a client with a dummy key.
 */
abstract class ClientTestCase extends TestCase
{
    protected MockTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new MockTransport();
    }

    /**
     * A client with a dummy key backed by $this->transport. Overrides
     * apply on top of the defaults (keys: apiKey, fqdn, secure,
     * sessionPin, autoSession, writeHistory).
     *
     * @param array<string, mixed> $overrides
     */
    protected function client(array $overrides = []): Client
    {
        return new Client(
            $overrides['apiKey'] ?? 'test-key',
            $overrides['fqdn'] ?? 'ai.sitehost.nz',
            $overrides['secure'] ?? true,
            $overrides['sessionPin'] ?? null,
            $overrides['autoSession'] ?? false,
            $overrides['writeHistory'] ?? true,
            $this->transport
        );
    }
}
