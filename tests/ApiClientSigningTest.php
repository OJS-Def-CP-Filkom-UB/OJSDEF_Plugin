<?php

use PHPUnit\Framework\TestCase;

class TestableApiClient extends ApiClient
{
    public function buildHeaders(string $body, int $timestamp): array
    {
        return $this->makeHeaders($body, $timestamp);
    }
}

class ApiClientSigningTest extends TestCase
{
    private TestableApiClient $client;

    protected function setUp(): void
    {
        $this->client = new TestableApiClient(
            'https://api.ojsdef.id',
            'ojsdef_pk_live_testkey123456789',
            'uuid-target-123'
        );
    }

    public function test_headers_contain_required_fields(): void
    {
        $headers = $this->client->buildHeaders('{"event":"heartbeat"}', time());
        $keys    = array_map(fn($h) => explode(':', $h)[0], $headers);
        $this->assertContains('Content-Type', $keys);
        $this->assertContains('X-OJSDef-Signature', $keys);
        $this->assertContains('X-OJSDef-Timestamp', $keys);
        $this->assertContains('X-OJSDef-Target-ID', $keys);
    }

    public function test_signature_header_starts_with_sha256(): void
    {
        $headers    = $this->client->buildHeaders('{"test":"value"}', time());
        $sigHeaders = array_values(array_filter($headers, fn($h) => strpos($h, 'X-OJSDef-Signature') === 0));
        $this->assertStringContainsString('sha256=', $sigHeaders[0]);
    }
}
