<?php

class HmacSigner
{
    /** @var string */
    private $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Hasilkan HMAC-SHA256 signature.
     * Format: "sha256=<64-char-hex>"
     */
    public function sign(string $body, int $timestamp): string
    {
        $message = $timestamp . '.' . $body;
        return 'sha256=' . hash_hmac('sha256', $message, $this->apiKey);
    }

    /**
     * Verifikasi signature. Return false jika timestamp kadaluarsa
     * (lebih dari 5 menit) atau signature tidak cocok.
     */
    public function verify(string $signature, string $body, int $timestamp): bool
    {
        if (abs(time() - $timestamp) > 300) {
            return false;
        }
        $expected = $this->sign($body, $timestamp);
        return hash_equals($expected, $signature);
    }
}
