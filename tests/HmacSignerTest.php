<?php

use PHPUnit\Framework\TestCase;

class HmacSignerTest extends TestCase
{
    private HmacSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new HmacSigner('test-api-key-secret-32-chars-long');
    }

    public function test_sign_returns_sha256_prefixed_hex(): void
    {
        $signature = $this->signer->sign('{"event":"test"}', 1748563200);
        $this->assertStringStartsWith('sha256=', $signature);
        $this->assertMatchesRegularExpression('/^sha256=[a-f0-9]{64}$/', $signature);
    }

    public function test_sign_is_deterministic(): void
    {
        $sig1 = $this->signer->sign('body', 1748563200);
        $sig2 = $this->signer->sign('body', 1748563200);
        $this->assertSame($sig1, $sig2);
    }

    public function test_different_body_produces_different_signature(): void
    {
        $sig1 = $this->signer->sign('body1', 1748563200);
        $sig2 = $this->signer->sign('body2', 1748563200);
        $this->assertNotSame($sig1, $sig2);
    }

    public function test_verify_returns_true_for_valid_signature(): void
    {
        $body      = '{"event":"heartbeat"}';
        $timestamp = time();
        $signature = $this->signer->sign($body, $timestamp);
        $this->assertTrue($this->signer->verify($signature, $body, $timestamp));
    }

    public function test_verify_returns_false_for_wrong_body(): void
    {
        $timestamp = time();
        $signature = $this->signer->sign('correct-body', $timestamp);
        $this->assertFalse($this->signer->verify($signature, 'tampered-body', $timestamp));
    }

    public function test_verify_returns_false_for_expired_timestamp(): void
    {
        $body      = '{"event":"heartbeat"}';
        $oldTime   = time() - 400; // lebih dari 300 detik yang lalu
        $signature = $this->signer->sign($body, $oldTime);
        $this->assertFalse($this->signer->verify($signature, $body, $oldTime));
    }

    public function test_verify_returns_false_for_future_timestamp(): void
    {
        $body       = '{"event":"heartbeat"}';
        $futureTime = time() + 400;
        $signature  = $this->signer->sign($body, $futureTime);
        $this->assertFalse($this->signer->verify($signature, $body, $futureTime));
    }

    public function test_verify_returns_false_for_wrong_prefix(): void
    {
        $body      = '{"event":"heartbeat"}';
        $timestamp = time();
        $signature = 'md5=' . md5($body); // prefix salah
        $this->assertFalse($this->signer->verify($signature, $body, $timestamp));
    }
}
