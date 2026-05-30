<?php

use PHPUnit\Framework\TestCase;

class ContentInjectionDetectorTest extends TestCase
{
    private ContentInjectionDetector $detector;

    protected function setUp(): void
    {
        $plugin = new stdClass();
        $this->detector = new ContentInjectionDetector($plugin);
    }

    public function test_detects_gambling_keyword_slot_gacor(): void
    {
        $this->assertNotEmpty(
            $this->detector->testDetect('Kunjungi kami untuk slot gacor dan bonus besar.')
        );
    }

    public function test_detects_gambling_keyword_judi_online(): void
    {
        $this->assertNotEmpty(
            $this->detector->testDetect('Daftar di situs judi online terpercaya kami.')
        );
    }

    public function test_detects_gambling_keyword_sbobet(): void
    {
        $this->assertNotEmpty(
            $this->detector->testDetect('Link alternatif sbobet terbaru 2026.')
        );
    }

    public function test_detects_hidden_iframe(): void
    {
        $this->assertNotEmpty(
            $this->detector->testDetect('<iframe src="https://evil.xyz" style="display:none"></iframe>')
        );
    }

    public function test_detects_js_redirect(): void
    {
        $this->assertNotEmpty(
            $this->detector->testDetect('window.location = "https://phishing.click";')
        );
    }

    public function test_detects_base64_eval(): void
    {
        $this->assertNotEmpty(
            $this->detector->testDetect('eval(base64_decode("SGVsbG8="));')
        );
    }

    public function test_detects_phishing_tld(): void
    {
        $this->assertNotEmpty(
            $this->detector->testDetect('<a href="https://malicious.xyz/steal">Click</a>')
        );
    }

    public function test_clean_academic_text_returns_empty(): void
    {
        $this->assertEmpty(
            $this->detector->testDetect('This abstract discusses machine learning and climate change.')
        );
    }

    public function test_clean_html_returns_empty(): void
    {
        $this->assertEmpty(
            $this->detector->testDetect('<p>A normal <strong>academic</strong> journal abstract.</p>')
        );
    }
}
