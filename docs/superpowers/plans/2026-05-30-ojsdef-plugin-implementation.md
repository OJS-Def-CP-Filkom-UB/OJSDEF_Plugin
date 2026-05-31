# OJSDef Plugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bangun plugin PHP OJS (Generic Plugin) yang mengumpulkan data audit internal dari instalasi OJS dan mengirimkannya ke OJSDef backend via HMAC-SHA256 signed HTTP callback.

**Architecture:** Plugin berjalan sebagai site-wide GenericPlugin di OJS. Ia mengirim heartbeat setiap 5 menit via hook `Core::loadBaseData`, menerima scan trigger dari backend melalui custom HTTP endpoint `/index.php/index/ojsdef/trigger`, menjalankan 6 scanner module secara sequential, lalu POST hasilnya ke backend via `/plugin/v1/callback`. Untuk server di balik firewall, backend dapat trigger scan via respons heartbeat (Heartbeat Mode fallback).

**Tech Stack:** PHP 7.4+ (tanpa fitur 8.x-only), cURL native, OJS GenericPlugin API (3.3.x–3.5.x), Smarty templates, PHPUnit 10 (dev/testing only via Composer)

**Spec:** `docs/superpowers/specs/2026-05-30-ojsdef-plugin-design.md`

---

## File Map

| File | Tanggung Jawab |
|------|----------------|
| `ojsdef/OjsdefPlugin.php` | Main plugin class: register hooks, heartbeat, settings |
| `ojsdef/OjsdefHandler.php` | HTTP handler: `/ojsdef/trigger` dan `/ojsdef/probe` |
| `ojsdef/version.xml` | Metadata plugin OJS |
| `ojsdef/locale/en_US/locale.po` | Locale strings |
| `ojsdef/classes/HmacSigner.php` | Sign + verify HMAC-SHA256 |
| `ojsdef/classes/ApiClient.php` | cURL HTTP client ke OJSDef backend |
| `ojsdef/classes/ScanOrchestrator.php` | Koordinasi semua scanner, build payload |
| `ojsdef/classes/OjsdefSettingsForm.php` | Form handler untuk settings UI |
| `ojsdef/classes/scanners/FingerprintScanner.php` | OJS version + plugin list |
| `ojsdef/classes/scanners/ConfigScanner.php` | config.inc.php audit |
| `ojsdef/classes/scanners/PluginAuditor.php` | Plugin versions + disabled-but-installed |
| `ojsdef/classes/scanners/RbacAuditor.php` | Users + roles audit |
| `ojsdef/classes/scanners/FileIntegrityChecker.php` | SHA-256 file hash comparison |
| `ojsdef/classes/scanners/ContentInjectionDetector.php` | DB regex scan (gambling/malware) |
| `ojsdef/templates/settingsForm.tpl` | Smarty template settings form di OJS admin |
| `tests/bootstrap.php` | PHPUnit bootstrap: OJS stubs + constants |
| `tests/HmacSignerTest.php` | Unit test HmacSigner |
| `tests/ApiClientSigningTest.php` | Unit test signing logic ApiClient |
| `tests/ContentInjectionDetectorTest.php` | Unit test regex patterns |
| `composer.json` | PHPUnit dev dependency |
| `phpunit.xml` | PHPUnit config |

---

## Task 1: Plugin Scaffold + Git Init

**Files:**
- Create: `ojsdef/version.xml`
- Create: `ojsdef/OjsdefPlugin.php` (skeleton)
- Create: `ojsdef/locale/en_US/locale.po`
- Create: `ojsdef/templates/settingsForm.tpl` (placeholder)

- [ ] **Step 1: Init git repo dan buat struktur direktori**

```bash
cd D:\Kuliahku\Capstone\workspace\OJSDEF-Plugin
git init
mkdir -p ojsdef/classes/scanners
mkdir -p ojsdef/locale/en_US
mkdir -p ojsdef/templates
mkdir -p tests
```

- [ ] **Step 2: Buat `ojsdef/version.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE version SYSTEM "../../../lib/pkp/dtd/pluginVersion.dtd">
<version>
    <application>ojsdef</application>
    <type>plugins.generic</type>
    <release>1.0.0.0</release>
    <date>2026-05-30</date>
    <lazy-load>1</lazy-load>
    <installedLocales>
        <locale name="en_US"/>
    </installedLocales>
</version>
```

- [ ] **Step 3: Buat skeleton `ojsdef/OjsdefPlugin.php`**

```php
<?php

import('lib.pkp.classes.plugins.GenericPlugin');

class OjsdefPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        return $success;
    }

    public function isSitePlugin()
    {
        return true;
    }

    public function getDisplayName()
    {
        return __('plugins.generic.ojsdef.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.ojsdef.description');
    }
}
```

- [ ] **Step 4: Buat `ojsdef/locale/en_US/locale.po`**

```po
msgid ""
msgstr ""
"Project-Id-Version: OJSDef Plugin 1.0\n"
"MIME-Version: 1.0\n"
"Content-Type: text/plain; charset=UTF-8\n"

msgid "plugins.generic.ojsdef.displayName"
msgstr "OJSDef Security Scanner"

msgid "plugins.generic.ojsdef.description"
msgstr "Connects this OJS installation to the OJSDef security platform for internal audit scanning."

msgid "plugins.generic.ojsdef.settings.backendUrl"
msgstr "OJSDef Backend URL"

msgid "plugins.generic.ojsdef.settings.apiKey"
msgstr "API Key"

msgid "plugins.generic.ojsdef.settings.targetId"
msgstr "Target ID"

msgid "plugins.generic.ojsdef.settings.save"
msgstr "Save"

msgid "plugins.generic.ojsdef.status.connection"
msgstr "Connection Status"

msgid "plugins.generic.ojsdef.status.connected"
msgstr "Connected"

msgid "plugins.generic.ojsdef.status.disconnected"
msgstr "Disconnected"

msgid "plugins.generic.ojsdef.status.directMode"
msgstr "Direct Mode - Scan starts in under 10 seconds."

msgid "plugins.generic.ojsdef.status.heartbeatMode"
msgstr "Heartbeat Mode - Scan starts within 5 minutes."

msgid "plugins.generic.ojsdef.status.unknown"
msgstr "Unknown (not yet detected)"

msgid "plugins.generic.ojsdef.status.firewallWarning"
msgstr "Plugin is behind a firewall. Backend cannot reach the plugin directly. Scans will still work via heartbeat."

msgid "plugins.generic.ojsdef.status.lastHeartbeat"
msgstr "Last Heartbeat"

msgid "plugins.generic.ojsdef.testConnection"
msgstr "Test Connection"

msgid "plugins.generic.ojsdef.settings.setup"
msgstr "Setup Instructions"

msgid "plugins.generic.ojsdef.settings.setup.description"
msgstr "Follow these steps to connect this OJS installation to OJSDef:"

msgid "plugins.generic.ojsdef.settings.step1"
msgstr "Login to OJSDef Dashboard and add this OJS URL as a target."

msgid "plugins.generic.ojsdef.settings.step2"
msgstr "Copy the API Key and Target ID from the OJSDef dashboard."

msgid "plugins.generic.ojsdef.settings.step3"
msgstr "Paste both values below and click Save."
```

- [ ] **Step 5: Buat placeholder `ojsdef/templates/settingsForm.tpl`**

```smarty
{* Placeholder - diisi di Task 12 *}
<p>OJSDef Settings Form</p>
```

- [ ] **Step 6: Commit scaffold**

```bash
git add ojsdef/
git commit -m "feat: scaffold OJSDef plugin structure and version.xml"
```

---

## Task 2: Testing Infrastructure

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `tests/bootstrap.php`

- [ ] **Step 1: Buat `composer.json`**

```json
{
  "require-dev": {
    "phpunit/phpunit": "^10.5"
  },
  "autoload-dev": {
    "classmap": [
      "ojsdef/classes/"
    ]
  }
}
```

- [ ] **Step 2: Install PHPUnit**

```bash
composer install
```

Expected output: `Installing phpunit/phpunit (10.x.x)` dan direktori `vendor/` terbuat.

- [ ] **Step 3: Buat `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true">
    <testsuites>
        <testsuite name="OJSDef Plugin Tests">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

- [ ] **Step 4: Buat `tests/bootstrap.php` dengan OJS stubs**

```php
<?php

// OJS constants
define('BASE_SYS_DIR', __DIR__ . '/fixtures/ojs');

// Autoload plugin classes
spl_autoload_register(function (string $class): void {
    $map = [
        'HmacSigner'               => __DIR__ . '/../ojsdef/classes/HmacSigner.php',
        'ApiClient'                => __DIR__ . '/../ojsdef/classes/ApiClient.php',
        'ScanOrchestrator'         => __DIR__ . '/../ojsdef/classes/ScanOrchestrator.php',
        'FingerprintScanner'       => __DIR__ . '/../ojsdef/classes/scanners/FingerprintScanner.php',
        'ConfigScanner'            => __DIR__ . '/../ojsdef/classes/scanners/ConfigScanner.php',
        'PluginAuditor'            => __DIR__ . '/../ojsdef/classes/scanners/PluginAuditor.php',
        'RbacAuditor'              => __DIR__ . '/../ojsdef/classes/scanners/RbacAuditor.php',
        'FileIntegrityChecker'     => __DIR__ . '/../ojsdef/classes/scanners/FileIntegrityChecker.php',
        'ContentInjectionDetector' => __DIR__ . '/../ojsdef/classes/scanners/ContentInjectionDetector.php',
    ];
    if (isset($map[$class])) {
        require_once $map[$class];
    }
});

// OJS stub: Config class
if (!class_exists('Config')) {
    class Config
    {
        private static array $vars = [];

        public static function setVar(string $section, string $key, $value): void
        {
            self::$vars[$section][$key] = $value;
        }

        public static function getVar(string $section, string $key, $default = null)
        {
            return self::$vars[$section][$key] ?? $default;
        }

        public static function reset(): void
        {
            self::$vars = [];
        }
    }
}

// OJS stub: DAORegistry
if (!class_exists('DAORegistry')) {
    class DAORegistry
    {
        private static array $daos = [];

        public static function registerDAO(string $name, $dao): void
        {
            self::$daos[$name] = $dao;
        }

        public static function getDAO(string $name)
        {
            return self::$daos[$name] ?? null;
        }
    }
}

// OJS stub: Hook
if (!class_exists('Hook')) {
    class Hook
    {
        public static function add(string $hookName, callable $callback): void
        {
            // no-op in tests
        }
    }
}
```

- [ ] **Step 5: Buat direktori fixtures**

```bash
mkdir -p tests/fixtures/ojs/classes
```

- [ ] **Step 6: Verifikasi PHPUnit berjalan**

```bash
vendor/bin/phpunit
```

Expected: `No tests found.` atau `OK (0 tests, 0 assertions)` — bukan error fatal.

- [ ] **Step 7: Commit**

```bash
git add composer.json phpunit.xml tests/
git commit -m "feat: add PHPUnit testing infrastructure with OJS stubs"
```

---

## Task 3: HmacSigner

**Files:**
- Create: `ojsdef/classes/HmacSigner.php`
- Create: `tests/HmacSignerTest.php`

- [ ] **Step 1: Tulis failing test `tests/HmacSignerTest.php`**

```php
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
```

- [ ] **Step 2: Jalankan test - pastikan FAIL**

```bash
vendor/bin/phpunit tests/HmacSignerTest.php
```

Expected: `Error: Class "HmacSigner" not found`

- [ ] **Step 3: Implementasi `ojsdef/classes/HmacSigner.php`**

```php
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
```

- [ ] **Step 4: Jalankan test - pastikan PASS**

```bash
vendor/bin/phpunit tests/HmacSignerTest.php
```

Expected: `OK (8 tests, 8 assertions)`

- [ ] **Step 5: Commit**

```bash
git add ojsdef/classes/HmacSigner.php tests/HmacSignerTest.php
git commit -m "feat: add HmacSigner with HMAC-SHA256 sign/verify and full test coverage"
```

---

## Task 4: ApiClient

**Files:**
- Create: `ojsdef/classes/ApiClient.php`
- Create: `tests/ApiClientSigningTest.php`

- [ ] **Step 1: Tulis failing test `tests/ApiClientSigningTest.php`**

```php
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
        $sigHeaders = array_values(array_filter($headers, fn($h) => str_starts_with($h, 'X-OJSDef-Signature')));
        $this->assertStringContainsString('sha256=', $sigHeaders[0]);
    }
}
```

- [ ] **Step 2: Jalankan test - pastikan FAIL**

```bash
vendor/bin/phpunit tests/ApiClientSigningTest.php
```

Expected: `Error: Class "ApiClient" not found`

- [ ] **Step 3: Implementasi `ojsdef/classes/ApiClient.php`**

```php
<?php

class ApiClient
{
    /** @var string */
    private $backendUrl;
    /** @var string */
    private $apiKey;
    /** @var string */
    private $targetId;
    /** @var HmacSigner */
    private $signer;

    /**
     * Constructor dua mode:
     * - Testing: new ApiClient('https://...', 'api_key', 'target_id')
     * - Production: new ApiClient($pluginObject)
     *
     * @param string|object $backendUrlOrPlugin
     */
    public function __construct($backendUrlOrPlugin, string $apiKey = '', string $targetId = '')
    {
        if (is_string($backendUrlOrPlugin)) {
            $this->backendUrl = rtrim($backendUrlOrPlugin, '/');
            $this->apiKey     = $apiKey;
            $this->targetId   = $targetId;
        } else {
            $plugin           = $backendUrlOrPlugin;
            $this->backendUrl = rtrim((string) $plugin->getSetting(0, 'backend_url'), '/');
            $this->apiKey     = (string) $plugin->getSetting(0, 'api_key');
            $this->targetId   = (string) $plugin->getSetting(0, 'target_id');
        }
        $this->signer = new HmacSigner($this->apiKey);
    }

    /**
     * Kirim heartbeat ke /plugin/v1/heartbeat.
     * @return array ['code' => int, 'body' => array|null]
     */
    public function sendHeartbeat(array $extra = []): array
    {
        $payload = array_merge([
            'target_id'      => $this->targetId,
            'plugin_version' => '1.0.0',
            'ojs_version'    => $this->_getOjsVersion(),
            'php_version'    => phpversion(),
        ], $extra);

        return $this->post('/plugin/v1/heartbeat', $payload);
    }

    /**
     * Kirim hasil scan ke /plugin/v1/callback. Retry 3x dengan backoff.
     */
    public function sendCallback(string $jobId, array $data): bool
    {
        $payload = [
            'event'            => 'audit_data',
            'target_id'        => $this->targetId,
            'job_id'           => $jobId,
            'plugin_version'   => '1.0.0',
            'timestamp'        => gmdate('Y-m-d\TH:i:s\Z'),
            'duration_seconds' => $data['duration_seconds'] ?? 0,
            'status'           => $data['status'] ?? 'completed',
            'data'             => $data,
        ];

        foreach ([0, 5, 10] as $delay) {
            if ($delay > 0) sleep($delay);
            $result = $this->post('/plugin/v1/callback', $payload);
            if ($result['code'] === 202) return true;
        }
        return false;
    }

    /**
     * GET request ke backend (digunakan untuk fetch checksums).
     * @return array ['code' => int, 'body' => array|null]
     */
    public function get(string $endpoint): array
    {
        $ch = curl_init($this->backendUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ['X-OJSDef-Target-ID: ' . $this->targetId],
        ]);
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => (int) $code, 'body' => $response ? json_decode($response, true) : null];
    }

    /**
     * Build signed headers. Protected agar bisa ditest via subclass.
     */
    protected function makeHeaders(string $body, int $timestamp): array
    {
        return [
            'Content-Type: application/json',
            'X-OJSDef-Signature: ' . $this->signer->sign($body, $timestamp),
            'X-OJSDef-Timestamp: ' . $timestamp,
            'X-OJSDef-Target-ID: ' . $this->targetId,
        ];
    }

    private function post(string $endpoint, array $data): array
    {
        $body      = json_encode($data);
        $timestamp = time();
        $ch        = curl_init($this->backendUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $this->makeHeaders($body, $timestamp),
        ]);
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => (int) $code, 'body' => $response ? json_decode($response, true) : null];
    }

    private function _getOjsVersion(): string
    {
        if (!class_exists('DAORegistry')) return 'unknown';
        try {
            $dao = \DAORegistry::getDAO('VersionDAO');
            if (!$dao) return 'unknown';
            $v = $dao->getCurrentVersion('ojs2', true);
            return $v ? $v->getVersionString() : 'unknown';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }
}
```

- [ ] **Step 4: Jalankan test - pastikan PASS**

```bash
vendor/bin/phpunit tests/ApiClientSigningTest.php
```

Expected: `OK (2 tests, 5 assertions)`

- [ ] **Step 5: Commit**

```bash
git add ojsdef/classes/ApiClient.php tests/ApiClientSigningTest.php
git commit -m "feat: add ApiClient with cURL HTTP, HMAC signing, heartbeat, callback with retry"
```

---

## Task 5: Plugin Hooks — Heartbeat + LoadHandler

**Files:**
- Modify: `ojsdef/OjsdefPlugin.php` (ganti seluruh isi)

- [ ] **Step 1: Ganti seluruh isi `ojsdef/OjsdefPlugin.php`**

```php
<?php

import('lib.pkp.classes.plugins.GenericPlugin');

class OjsdefPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled()) {
            // Route /index.php/index/ojsdef/* ke OjsdefHandler
            Hook::add('LoadHandler', [$this, 'callbackLoadHandler']);
            // Kirim heartbeat setiap 5 menit pada request OJS apapun
            Hook::add('Core::loadBaseData', [$this, 'maybeSendHeartbeat']);
        }

        return $success;
    }

    public function callbackLoadHandler(string $hookName, array &$args): bool
    {
        if ($args[0] === 'ojsdef') {
            define('HANDLER_CLASS', 'OjsdefHandler');
            define('HANDLER_FILE', $this->getPluginPath() . '/OjsdefHandler.php');
            return true;
        }
        return false;
    }

    public function maybeSendHeartbeat(string $hookName, array $args): void
    {
        $lastHeartbeat = (int) $this->getSetting(0, 'last_heartbeat_at');
        if ((time() - $lastHeartbeat) < 300) return;

        $this->updateSetting(0, 'last_heartbeat_at', time());
        ignore_user_abort(true);

        $this->_requireClasses();
        $extra     = $this->_buildHeartbeatExtra();
        $apiClient = new ApiClient($this);
        $result    = $apiClient->sendHeartbeat($extra);

        // Heartbeat Mode: backend minta plugin jalankan scan
        if (!empty($result['body']['scan_requested']) && !empty($result['body']['job_id'])) {
            $this->_runScanFromHeartbeat(
                $result['body']['job_id'],
                $result['body']['scan_modules'] ?? []
            );
        }

        // Retry pending callback jika ada dari scan sebelumnya yang gagal kirim
        $pending = $this->getSetting(0, 'pending_callback');
        if ($pending) {
            $pendingData = json_decode($pending, true);
            if ($pendingData && $apiClient->sendCallback($pendingData['job_id'], $pendingData)) {
                $this->updateSetting(0, 'pending_callback', null);
            }
        }
    }

    private function _runScanFromHeartbeat(string $jobId, array $modules): void
    {
        $this->_requireClasses();
        $orchestrator = new ScanOrchestrator($this);
        $startTime    = time();
        $result       = $orchestrator->runAll($jobId, $modules);
        $result['duration_seconds'] = time() - $startTime;

        $apiClient = new ApiClient($this);
        if (!$apiClient->sendCallback($jobId, $result)) {
            $result['job_id'] = $jobId;
            $this->updateSetting(0, 'pending_callback', json_encode($result));
        }
    }

    private function _buildHeartbeatExtra(): array
    {
        $mode    = $this->getSetting(0, 'connection_mode') ?? 'unknown';
        $baseUrl = $this->_detectBaseUrl();
        $extra   = [
            'trigger_endpoint' => $baseUrl . '/index.php/index/ojsdef/trigger',
            'probe_endpoint'   => $baseUrl . '/index.php/index/ojsdef/probe',
            'connection_mode'  => $mode,
        ];
        if ($mode === 'unknown') {
            $challenge = bin2hex(random_bytes(16));
            $extra['reachability_challenge'] = $challenge;
            $this->updateSetting(0, 'reachability_challenge', $challenge);
        }
        return $extra;
    }

    private function _detectBaseUrl(): string
    {
        if (class_exists('Config')) {
            $baseUrl = Config::getVar('general', 'base_url', '');
            if ($baseUrl) return rtrim($baseUrl, '/');
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }

    public function _requireClasses(): void
    {
        $base = $this->getPluginPath() . '/classes/';
        require_once $base . 'HmacSigner.php';
        require_once $base . 'ApiClient.php';
        require_once $base . 'ScanOrchestrator.php';
        require_once $base . 'scanners/FingerprintScanner.php';
        require_once $base . 'scanners/ConfigScanner.php';
        require_once $base . 'scanners/PluginAuditor.php';
        require_once $base . 'scanners/RbacAuditor.php';
        require_once $base . 'scanners/FileIntegrityChecker.php';
        require_once $base . 'scanners/ContentInjectionDetector.php';
    }

    public function isSitePlugin()   { return true; }
    public function getDisplayName() { return __('plugins.generic.ojsdef.displayName'); }
    public function getDescription() { return __('plugins.generic.ojsdef.description'); }

    // manage() dan getActions() diimplementasi di Task 12
    public function manage($args, $request) { return parent::manage($args, $request); }
}
```

- [ ] **Step 2: Verifikasi PHP syntax**

```bash
php -l ojsdef/OjsdefPlugin.php
```

Expected: `No syntax errors detected in ojsdef/OjsdefPlugin.php`

- [ ] **Step 3: Commit**

```bash
git add ojsdef/OjsdefPlugin.php
git commit -m "feat: add LoadHandler and heartbeat hooks with Heartbeat Mode scan trigger"
```

---

## Task 6: OjsdefHandler — Probe dan Trigger

**Files:**
- Create: `ojsdef/OjsdefHandler.php`

- [ ] **Step 1: Buat `ojsdef/OjsdefHandler.php`**

```php
<?php

import('classes.handler.Handler');

class OjsdefHandler extends Handler
{
    /**
     * POST /index.php/index/ojsdef/probe
     * Dipanggil backend satu kali untuk test reachability.
     */
    public function probe(array $args, $request): void
    {
        $plugin = PluginRegistry::getPlugin('generic', 'ojsdef');
        $plugin->_requireClasses();

        if (!$this->_verifyHmac($plugin)) {
            $this->_json(401, ['error' => 'invalid_signature']);
            return;
        }

        $payload = json_decode(file_get_contents('php://input'), true);
        $plugin->updateSetting(0, 'connection_mode', 'direct');

        $this->_json(200, [
            'challenge'      => $payload['challenge'] ?? '',
            'plugin_version' => '1.0.0',
        ]);
    }

    /**
     * POST /index.php/index/ojsdef/trigger
     * Dipanggil backend untuk memulai internal scan (Direct Mode).
     * Respond 202 segera, lalu jalankan scan di background.
     */
    public function trigger(array $args, $request): void
    {
        $plugin = PluginRegistry::getPlugin('generic', 'ojsdef');
        $plugin->_requireClasses();

        if (!$this->_verifyHmac($plugin)) {
            $this->_json(401, ['error' => 'invalid_signature']);
            return;
        }

        $payload = json_decode(file_get_contents('php://input'), true);
        $jobId   = $payload['job_id'] ?? '';
        $modules = $payload['scan_modules'] ?? [
            'fingerprint', 'config', 'plugins', 'rbac', 'file_integrity', 'content'
        ];

        if (empty($jobId)) {
            $this->_json(400, ['error' => 'missing_job_id']);
            return;
        }

        // Respond 202 dan tutup koneksi ke backend
        $this->_json(202, ['status' => 'accepted', 'job_id' => $jobId]);
        $this->_closeConnection();

        // Scan dijalankan setelah koneksi ditutup
        $startTime    = time();
        $orchestrator = new ScanOrchestrator($plugin);
        $result       = $orchestrator->runAll($jobId, $modules);
        $result['duration_seconds'] = time() - $startTime;

        $apiClient = new ApiClient($plugin);
        if (!$apiClient->sendCallback($jobId, $result)) {
            $result['job_id'] = $jobId;
            $plugin->updateSetting(0, 'pending_callback', json_encode($result));
        }
    }

    private function _verifyHmac($plugin): bool
    {
        $signature = $_SERVER['HTTP_X_OJSDEF_SIGNATURE'] ?? '';
        $timestamp = (int) ($_SERVER['HTTP_X_OJSDEF_TIMESTAMP'] ?? 0);
        $body      = file_get_contents('php://input');

        if (empty($signature) || $timestamp === 0) return false;

        $signer = new HmacSigner((string) $plugin->getSetting(0, 'api_key'));
        return $signer->verify($signature, $body, $timestamp);
    }

    private function _json(int $code, array $data): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    private function _closeConnection(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            ignore_user_abort(true);
            ob_end_flush();
            flush();
        }
    }
}
```

- [ ] **Step 2: Verifikasi PHP syntax**

```bash
php -l ojsdef/OjsdefHandler.php
```

Expected: `No syntax errors detected in ojsdef/OjsdefHandler.php`

- [ ] **Step 3: Commit**

```bash
git add ojsdef/OjsdefHandler.php
git commit -m "feat: add OjsdefHandler with HMAC-verified probe and trigger endpoints"
```

---

## Task 7: ScanOrchestrator

**Files:**
- Create: `ojsdef/classes/ScanOrchestrator.php`

- [ ] **Step 1: Buat `ojsdef/classes/ScanOrchestrator.php`**

```php
<?php

class ScanOrchestrator
{
    /** @var object */
    private $plugin;

    /** @var array<string, string> */
    private $scannerMap = [
        'fingerprint'    => 'FingerprintScanner',
        'config'         => 'ConfigScanner',
        'plugins'        => 'PluginAuditor',
        'rbac'           => 'RbacAuditor',
        'file_integrity' => 'FileIntegrityChecker',
        'content'        => 'ContentInjectionDetector',
    ];

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Jalankan scanner yang diminta. Modul yang gagal tidak menghentikan yang lain.
     *
     * @param string   $jobId
     * @param string[] $modules Nama modul yang akan dijalankan
     * @return array {
     *   modules_completed: string[],
     *   modules_failed: array<string,string>,
     *   results: array<string,array>,
     *   status: 'completed'|'partial'
     * }
     */
    public function runAll(string $jobId, array $modules): array
    {
        $results = [];
        $errors  = [];

        foreach ($modules as $module) {
            if (!isset($this->scannerMap[$module])) {
                $errors[$module] = 'Unknown module';
                continue;
            }
            $className = $this->scannerMap[$module];
            try {
                $scanner          = new $className($this->plugin);
                $results[$module] = $scanner->scan();
            } catch (\Throwable $e) {
                $errors[$module] = $e->getMessage();
            }
        }

        return [
            'modules_completed' => array_keys($results),
            'modules_failed'    => $errors,
            'results'           => $results,
            'status'            => empty($errors) ? 'completed' : 'partial',
        ];
    }
}
```

- [ ] **Step 2: Verifikasi PHP syntax**

```bash
php -l ojsdef/classes/ScanOrchestrator.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add ojsdef/classes/ScanOrchestrator.php
git commit -m "feat: add ScanOrchestrator with per-module error isolation"
```

---

## Task 8: FingerprintScanner + ConfigScanner

**Files:**
- Create: `ojsdef/classes/scanners/FingerprintScanner.php`
- Create: `ojsdef/classes/scanners/ConfigScanner.php`

- [ ] **Step 1: Buat `ojsdef/classes/scanners/FingerprintScanner.php`**

```php
<?php

class FingerprintScanner
{
    /** @var object */
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * @return array {
     *   ojs_version: string,
     *   php_version: string,
     *   server_os: string,
     *   plugin_count: int,
     *   plugins: array
     * }
     */
    public function scan(): array
    {
        $plugins = $this->_getPluginList();
        return [
            'ojs_version'  => $this->_getOjsVersion(),
            'php_version'  => phpversion(),
            'server_os'    => php_uname('s') . ' ' . php_uname('r'),
            'plugin_count' => count($plugins),
            'plugins'      => $plugins,
        ];
    }

    private function _getOjsVersion(): string
    {
        if (!class_exists('DAORegistry')) return 'unknown';
        try {
            $dao = \DAORegistry::getDAO('VersionDAO');
            if (!$dao) return 'unknown';
            $v = $dao->getCurrentVersion('ojs2', true);
            return $v ? $v->getVersionString() : 'unknown';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }

    private function _getPluginList(): array
    {
        if (!class_exists('PluginRegistry')) return [];
        $plugins = [];
        try {
            \PluginRegistry::loadAllPlugins();
            foreach (\PluginRegistry::getPlugins() as $category => $categoryPlugins) {
                foreach ($categoryPlugins as $name => $plugin) {
                    $ver       = method_exists($plugin, 'getCurrentVersion') ? $plugin->getCurrentVersion() : null;
                    $plugins[] = [
                        'name'     => $name,
                        'category' => $category,
                        'version'  => $ver ? $ver->getVersionString() : null,
                        'enabled'  => (bool) $plugin->getEnabled(),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Kembalikan list kosong jika PluginRegistry gagal
        }
        return $plugins;
    }
}
```

- [ ] **Step 2: Buat `ojsdef/classes/scanners/ConfigScanner.php`**

```php
<?php

class ConfigScanner
{
    /** @var object */
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * @return array Audit config.inc.php — tidak expose nilai password/secret
     */
    public function scan(): array
    {
        return [
            'debug_mode'            => (bool) $this->_cfg('debug',    'debug_mode',    false),
            'show_errors'           => (bool) $this->_cfg('debug',    'show_errors',   false),
            'api_key_secret_length' => strlen((string) $this->_cfg('general',  'api_key_secret', '')),
            'force_ssl'             => (bool) $this->_cfg('security', 'force_ssl',     false),
            'allowed_hosts_set'     => !empty($this->_cfg('security', 'allowed_hosts', '')),
            'installed'             => (bool) $this->_cfg('general',  'installed',     false),
            'smtp_auth_enabled'     => !empty($this->_cfg('email',    'smtp_auth',     '')),
            'smtp_password_set'     => !empty($this->_cfg('email',    'smtp_password', '')),
            'db_driver'             => (string) $this->_cfg('database', 'driver',      ''),
            'db_host'               => (string) $this->_cfg('database', 'host',        ''),
            'db_password_empty'     =>  empty($this->_cfg('database', 'password',      '')),
        ];
    }

    private function _cfg(string $section, string $key, $default)
    {
        if (!class_exists('Config')) return $default;
        return \Config::getVar($section, $key, $default);
    }
}
```

- [ ] **Step 3: Verifikasi syntax kedua file**

```bash
php -l ojsdef/classes/scanners/FingerprintScanner.php
php -l ojsdef/classes/scanners/ConfigScanner.php
```

Expected: `No syntax errors detected` untuk keduanya.

- [ ] **Step 4: Commit**

```bash
git add ojsdef/classes/scanners/FingerprintScanner.php ojsdef/classes/scanners/ConfigScanner.php
git commit -m "feat: add FingerprintScanner and ConfigScanner modules"
```

---

## Task 9: PluginAuditor + RbacAuditor

**Files:**
- Create: `ojsdef/classes/scanners/PluginAuditor.php`
- Create: `ojsdef/classes/scanners/RbacAuditor.php`

- [ ] **Step 1: Buat `ojsdef/classes/scanners/PluginAuditor.php`**

```php
<?php

class PluginAuditor
{
    /** @var object */
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * @return array {
     *   total_installed: int,
     *   total_enabled: int,
     *   disabled_but_installed: array,
     *   plugins: array
     * }
     */
    public function scan(): array
    {
        $audit = $this->_buildAuditList();
        $disabled = array_values(array_filter($audit, function ($p) { return !$p['enabled']; }));
        $enabled  = array_filter($audit, function ($p) { return $p['enabled']; });

        return [
            'total_installed'        => count($audit),
            'total_enabled'          => count($enabled),
            'disabled_but_installed' => $disabled,
            'plugins'                => $audit,
        ];
    }

    private function _buildAuditList(): array
    {
        if (!class_exists('PluginRegistry')) return [];
        $audit = [];
        try {
            \PluginRegistry::loadAllPlugins();
            foreach (\PluginRegistry::getPlugins() as $category => $plugins) {
                foreach ($plugins as $name => $plugin) {
                    $ver     = method_exists($plugin, 'getCurrentVersion') ? $plugin->getCurrentVersion() : null;
                    $audit[] = [
                        'name'     => $name,
                        'category' => $category,
                        'version'  => $ver ? $ver->getVersionString() : null,
                        'enabled'  => (bool) $plugin->getEnabled(),
                        'path'     => method_exists($plugin, 'getPluginPath') ? $plugin->getPluginPath() : '',
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Kembalikan list kosong
        }
        return $audit;
    }
}
```

- [ ] **Step 2: Buat `ojsdef/classes/scanners/RbacAuditor.php`**

```php
<?php

class RbacAuditor
{
    /** @var object */
    private $plugin;

    // Tidak login selama ini = "inactive high privilege"
    const INACTIVE_THRESHOLD_SECONDS = 365 * 86400;

    // Role ID OJS: 1=Manager, 16=Site Admin
    const HIGH_PRIVILEGE_ROLES = [1, 16];

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * @return array {
     *   total_users: int,
     *   superadmin_count: int,
     *   multiple_superadmin: bool,
     *   inactive_high_priv_count: int,
     *   inactive_high_priv_users: array  (hanya user_id + last_login, tanpa PII)
     * }
     */
    public function scan(): array
    {
        if (!class_exists('DAORegistry')) {
            return $this->_emptyResult('DAORegistry not available');
        }
        try {
            $userDao = \DAORegistry::getDAO('UserDAO');
            if (!$userDao) return $this->_emptyResult('UserDAO not available');

            $superAdmins  = $userDao->getAdminUsers();
            $superAdminCount = $superAdmins ? $superAdmins->getCount() : 0;

            $totalUsers  = 0;
            $inactiveHigh = [];
            $cutoffTime  = time() - self::INACTIVE_THRESHOLD_SECONDS;
            $allUsers    = $userDao->getUsersByContextId(null);

            if ($allUsers) {
                while ($user = $allUsers->next()) {
                    $totalUsers++;
                    $lastLogin = $user->getDateLastLogin() ? strtotime($user->getDateLastLogin()) : 0;
                    if ($lastLogin && $lastLogin < $cutoffTime && $this->_hasHighPrivilege($user)) {
                        $inactiveHigh[] = [
                            'user_id'    => $user->getId(),
                            'last_login' => $user->getDateLastLogin(),
                        ];
                    }
                }
            }

            return [
                'total_users'               => $totalUsers,
                'superadmin_count'          => $superAdminCount,
                'multiple_superadmin'       => $superAdminCount > 1,
                'inactive_high_priv_count'  => count($inactiveHigh),
                'inactive_high_priv_users'  => $inactiveHigh,
            ];
        } catch (\Throwable $e) {
            return $this->_emptyResult($e->getMessage());
        }
    }

    private function _hasHighPrivilege($user): bool
    {
        if (!class_exists('DAORegistry')) return false;
        try {
            $dao    = \DAORegistry::getDAO('UserGroupDAO');
            if (!$dao) return false;
            $groups = $dao->getByUserId($user->getId());
            while ($group = $groups->next()) {
                if (in_array($group->getRoleId(), self::HIGH_PRIVILEGE_ROLES, true)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // silent
        }
        return false;
    }

    private function _emptyResult(string $reason): array
    {
        return [
            'total_users'               => 0,
            'superadmin_count'          => 0,
            'multiple_superadmin'       => false,
            'inactive_high_priv_count'  => 0,
            'inactive_high_priv_users'  => [],
            'error'                     => $reason,
        ];
    }
}
```

- [ ] **Step 3: Verifikasi syntax**

```bash
php -l ojsdef/classes/scanners/PluginAuditor.php
php -l ojsdef/classes/scanners/RbacAuditor.php
```

Expected: `No syntax errors detected` untuk keduanya.

- [ ] **Step 4: Commit**

```bash
git add ojsdef/classes/scanners/PluginAuditor.php ojsdef/classes/scanners/RbacAuditor.php
git commit -m "feat: add PluginAuditor and RbacAuditor scanner modules"
```

---

## Task 10: FileIntegrityChecker

**Files:**
- Create: `ojsdef/classes/scanners/FileIntegrityChecker.php`

- [ ] **Step 1: Buat `ojsdef/classes/scanners/FileIntegrityChecker.php`**

```php
<?php

class FileIntegrityChecker
{
    /** @var object */
    private $plugin;

    /** @var ApiClient */
    private $apiClient;

    const CACHE_TTL = 604800; // 7 hari dalam detik

    public function __construct($plugin)
    {
        $this->plugin    = $plugin;
        $this->apiClient = new ApiClient($plugin);
    }

    /**
     * @return array {
     *   status: 'completed'|'skipped',
     *   total_checked: int,
     *   modified: int,
     *   missing: int,
     *   findings: array,
     *   reason?: string
     * }
     */
    public function scan(): array
    {
        $ojsVersion = $this->_getOjsVersion();
        $checksums  = $this->_getChecksums($ojsVersion);

        if ($checksums === null) {
            return [
                'status'        => 'skipped',
                'reason'        => 'checksums_unavailable',
                'total_checked' => 0,
                'modified'      => 0,
                'missing'       => 0,
                'findings'      => [],
            ];
        }

        $ojsRoot  = defined('BASE_SYS_DIR') ? BASE_SYS_DIR : '';
        $modified = 0;
        $missing  = 0;
        $checked  = 0;
        $findings = [];

        foreach ($checksums as $relativePath => $officialHash) {
            $fullPath = $ojsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $checked++;

            if (!file_exists($fullPath)) {
                $missing++;
                $findings[] = ['path' => $relativePath, 'status' => 'missing'];
                continue;
            }

            $localHash = hash_file('sha256', $fullPath);
            if ($localHash !== $officialHash) {
                $modified++;
                $findings[] = [
                    'path'       => $relativePath,
                    'status'     => 'modified',
                    'local_hash' => $localHash,
                ];
            }
        }

        return [
            'status'        => 'completed',
            'total_checked' => $checked,
            'modified'      => $modified,
            'missing'       => $missing,
            'findings'      => $findings,
        ];
    }

    /**
     * Fetch checksums dari backend dengan cache 7 hari di plugin_settings.
     * Return null jika tidak tersedia.
     */
    private function _getChecksums(string $version): ?array
    {
        if ($version === 'unknown') return null;

        $cacheKey = 'checksums_' . preg_replace('/[^a-z0-9]/', '_', strtolower($version));
        $cachedAt = (int) $this->plugin->getSetting(0, $cacheKey . '_at');

        if ($cachedAt && (time() - $cachedAt) < self::CACHE_TTL) {
            $cached = $this->plugin->getSetting(0, $cacheKey);
            if ($cached) return json_decode($cached, true);
        }

        $result = $this->apiClient->get('/plugin/v1/checksums?version=' . urlencode($version));
        if ($result['code'] === 200 && is_array($result['body'])) {
            $this->plugin->updateSetting(0, $cacheKey,          json_encode($result['body']));
            $this->plugin->updateSetting(0, $cacheKey . '_at',  time());
            return $result['body'];
        }

        return null;
    }

    private function _getOjsVersion(): string
    {
        if (!class_exists('DAORegistry')) return 'unknown';
        try {
            $dao = \DAORegistry::getDAO('VersionDAO');
            if (!$dao) return 'unknown';
            $v = $dao->getCurrentVersion('ojs2', true);
            return $v ? $v->getVersionString() : 'unknown';
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }
}
```

- [ ] **Step 2: Verifikasi syntax**

```bash
php -l ojsdef/classes/scanners/FileIntegrityChecker.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add ojsdef/classes/scanners/FileIntegrityChecker.php
git commit -m "feat: add FileIntegrityChecker with SHA-256 comparison and 7-day cache"
```

---

## Task 11: ContentInjectionDetector + Tests

**Files:**
- Create: `ojsdef/classes/scanners/ContentInjectionDetector.php`
- Create: `tests/ContentInjectionDetectorTest.php`

- [ ] **Step 1: Tulis failing test `tests/ContentInjectionDetectorTest.php`**

```php
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
```

- [ ] **Step 2: Jalankan test - pastikan FAIL**

```bash
vendor/bin/phpunit tests/ContentInjectionDetectorTest.php
```

Expected: `Error: Class "ContentInjectionDetector" not found`

- [ ] **Step 3: Buat `ojsdef/classes/scanners/ContentInjectionDetector.php`**

```php
<?php

class ContentInjectionDetector
{
    /** @var object */
    private $plugin;

    /** @var array<string, string> Regex patterns injeksi konten ilegal */
    private $patterns = [
        'gambling_keyword' =>
            '/\b(bet365|sbobet|togel|slot[\s_-]?gacor|judi[\s_-]?online|poker[\s_-]?online|casino|pragmatic|maxwin|scatter[\s_-]?hitam|bandar[\s_-]?bola|agen[\s_-]?slot|bonus[\s_-]?new[\s_-]?member)\b/i',

        'hidden_iframe' =>
            '/<iframe[^>]*(display\s*:\s*none|visibility\s*:\s*hidden|width\s*=\s*["\']?\s*0\s*["\']?)[^>]*>/i',

        'js_redirect' =>
            '/window\s*\.\s*location(\s*\.\s*href)?\s*=|document\s*\.\s*location\s*=\s*["\'][^"\']*["\']\s*;/i',

        'base64_eval' =>
            '/\beval\s*\(\s*(base64_decode|unescape|atob)\s*\(/i',

        'phishing_tld' =>
            '/https?:\/\/[^\s"\'<>\)]+\.(xyz|top|click|loan|gq|ml|cf|ga)(\/[^\s"\'<>\)]*)?[\s"\'<>\)]/i',
    ];

    /** @var string[] Fields artikel yang dicek */
    private $fieldsToCheck = ['abstract', 'title', 'coverage'];

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Scan seluruh artikel OJS untuk injeksi konten ilegal.
     *
     * @return array {
     *   total_scanned: int,
     *   affected_count: int,
     *   detections: array
     * }
     */
    public function scan(): array
    {
        $detections   = [];
        $totalScanned = 0;

        if (!class_exists('DAORegistry')) {
            return ['total_scanned' => 0, 'affected_count' => 0, 'detections' => []];
        }

        try {
            // OJS 3.4.x+: SubmissionDAO; OJS 3.3.x: ArticleDAO
            $daoName = class_exists('SubmissionDAO') ? 'SubmissionDAO' : 'ArticleDAO';
            $dao     = \DAORegistry::getDAO($daoName);
            if (!$dao) {
                return ['total_scanned' => 0, 'affected_count' => 0,
                        'detections' => [], 'error' => $daoName . ' not available'];
            }

            $submissions = $dao->getAll(true);
            while ($submission = $submissions->next()) {
                $totalScanned++;
                $detections = array_merge($detections, $this->_scanSubmission($submission));
            }
        } catch (\Throwable $e) {
            return ['total_scanned' => $totalScanned, 'affected_count' => 0,
                    'detections' => [], 'error' => $e->getMessage()];
        }

        $affectedIds = array_unique(array_column($detections, 'submission_id'));

        return [
            'total_scanned'  => $totalScanned,
            'affected_count' => count($affectedIds),
            'detections'     => $detections,
        ];
    }

    /**
     * Digunakan HANYA di unit test — deteksi pattern pada string bebas.
     * @return string[] List pattern name yang cocok
     */
    public function testDetect(string $text): array
    {
        $matched = [];
        foreach ($this->patterns as $name => $regex) {
            if (preg_match($regex, $text)) {
                $matched[] = $name;
            }
        }
        return $matched;
    }

    private function _scanSubmission($submission): array
    {
        $detections = [];
        $subId      = $submission->getId();

        foreach ($this->fieldsToCheck as $field) {
            $content = $this->_getField($submission, $field);
            if (empty($content)) continue;

            foreach ($this->patterns as $patternName => $regex) {
                if (preg_match($regex, $content, $matches)) {
                    $detections[] = [
                        'submission_id' => $subId,
                        'field'         => $field,
                        'pattern'       => $patternName,
                        'excerpt'       => substr($matches[0], 0, 100),
                    ];
                }
            }
        }
        return $detections;
    }

    private function _getField($submission, string $field): string
    {
        $methodMap = [
            'abstract' => 'getAbstract',
            'title'    => 'getTitle',
            'coverage' => 'getCoverage',
        ];
        $method = $methodMap[$field] ?? null;
        if (!$method || !method_exists($submission, $method)) return '';
        $value = $submission->$method();
        if (is_array($value)) return implode(' ', array_values($value));
        return (string) $value;
    }
}
```

- [ ] **Step 4: Jalankan test - pastikan PASS**

```bash
vendor/bin/phpunit tests/ContentInjectionDetectorTest.php
```

Expected: `OK (9 tests, 9 assertions)`

- [ ] **Step 5: Jalankan semua test**

```bash
vendor/bin/phpunit
```

Expected: `OK (19 tests, 22 assertions)`

- [ ] **Step 6: Commit**

```bash
git add ojsdef/classes/scanners/ContentInjectionDetector.php tests/ContentInjectionDetectorTest.php
git commit -m "feat: add ContentInjectionDetector with 5 regex patterns and full test coverage"
```

---

## Task 12: Settings UI

**Files:**
- Create: `ojsdef/classes/OjsdefSettingsForm.php`
- Modify: `ojsdef/OjsdefPlugin.php` (tambah manage() + getActions())
- Modify: `ojsdef/templates/settingsForm.tpl` (ganti placeholder)

- [ ] **Step 1: Buat `ojsdef/classes/OjsdefSettingsForm.php`**

```php
<?php

import('lib.pkp.classes.form.Form');

class OjsdefSettingsForm extends Form
{
    /** @var object */
    private $plugin;

    public function __construct($plugin)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->plugin = $plugin;

        $this->addCheck(new \FormValidatorUrl($this, 'backend_url', 'required',
            'plugins.generic.ojsdef.settings.backendUrl'));
        $this->addCheck(new \FormValidator($this, 'api_key', 'required',
            'plugins.generic.ojsdef.settings.apiKey'));
        $this->addCheck(new \FormValidator($this, 'target_id', 'required',
            'plugins.generic.ojsdef.settings.targetId'));
        $this->addCheck(new \FormValidatorPost($this));
        $this->addCheck(new \FormValidatorCSRF($this));
    }

    public function initData(): void
    {
        $this->setData('backend_url',      $this->plugin->getSetting(0, 'backend_url')     ?? 'https://api.ojsdef.id');
        $this->setData('api_key',          $this->plugin->getSetting(0, 'api_key')         ?? '');
        $this->setData('target_id',        $this->plugin->getSetting(0, 'target_id')       ?? '');
        $this->setData('connection_mode',  $this->plugin->getSetting(0, 'connection_mode') ?? 'unknown');
        $last = $this->plugin->getSetting(0, 'last_heartbeat_at');
        $this->setData('last_heartbeat_at', $last ? date('Y-m-d H:i:s', (int) $last) : null);
    }

    public function readInputData(): void
    {
        $this->readUserVars(['backend_url', 'api_key', 'target_id']);
    }

    public function execute(...$functionArgs): mixed
    {
        $this->plugin->updateSetting(0, 'backend_url',       $this->getData('backend_url'));
        $this->plugin->updateSetting(0, 'api_key',           $this->getData('api_key'));
        $this->plugin->updateSetting(0, 'target_id',         $this->getData('target_id'));
        // Reset agar probe diulangi dengan settings baru
        $this->plugin->updateSetting(0, 'connection_mode',   'unknown');
        $this->plugin->updateSetting(0, 'last_heartbeat_at', 0);
        return parent::execute(...$functionArgs);
    }
}
```

- [ ] **Step 2: Tambah manage() dan getActions() di `ojsdef/OjsdefPlugin.php`**

Ganti baris `public function manage($args, $request) { return parent::manage($args, $request); }` dengan:

```php
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) return $actions;

        $router = $request->getRouter();
        import('lib.pkp.classes.linkAction.request.AjaxModal');

        array_unshift($actions, new \LinkAction(
            'settings',
            new \AjaxModal(
                $router->url($request, null, null, 'manage', null,
                    ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        ));

        array_unshift($actions, new \LinkAction(
            'testConnection',
            new \RemoteActionConfirmationModal(
                $request->getSession(),
                __('plugins.generic.ojsdef.testConnection'),
                null,
                $router->url($request, null, null, 'manage', null,
                    ['verb' => 'testConnection', 'plugin' => $this->getName(), 'category' => 'generic'])
            ),
            __('plugins.generic.ojsdef.testConnection'),
            null
        ));

        return $actions;
    }

    public function manage($args, $request)
    {
        $verb = $request->getUserVar('verb');

        switch ($verb) {
            case 'settings':
                $this->_requireClasses();
                require_once $this->getPluginPath() . '/classes/OjsdefSettingsForm.php';
                $form = new OjsdefSettingsForm($this);
                if ($request->isPost()) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        return new \JSONMessage(true);
                    }
                }
                $form->initData();
                return new \JSONMessage(true, $form->fetch($request));

            case 'testConnection':
                $this->_requireClasses();
                $extra  = $this->_buildHeartbeatExtra();
                $result = (new ApiClient($this))->sendHeartbeat($extra);
                return new \JSONMessage($result['code'] === 200, [
                    'status' => $result['code'] === 200 ? 'ok' : 'error',
                ]);

            default:
                return parent::manage($args, $request);
        }
    }
```

- [ ] **Step 3: Ganti seluruh isi `ojsdef/templates/settingsForm.tpl`**

```smarty
{**
 * templates/settingsForm.tpl
 * OJSDef Plugin Settings Form
 *}
<script>
    $(function() {
        $('#ojsdefSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
    });
</script>

<form class="pkp_form" id="ojsdefSettingsForm" method="post"
      action="{url router=$smarty.const.ROUTE_PAGE page="management" op="plugin"
               plugin="ojsdef" category="generic"}">
    {csrf}
    {include file="controllers/notification/inlineNotification.tpl"
             notificationId="ojsdefFormNotification"}

    {* Setup Instructions *}
    <div class="pkp_helpers_clear">
        <h3>{translate key="plugins.generic.ojsdef.settings.setup"}</h3>
        <p>{translate key="plugins.generic.ojsdef.settings.setup.description"}</p>
        <ol>
            <li>{translate key="plugins.generic.ojsdef.settings.step1"}</li>
            <li>{translate key="plugins.generic.ojsdef.settings.step2"}</li>
            <li>{translate key="plugins.generic.ojsdef.settings.step3"}</li>
        </ol>
    </div>

    {* Connection Settings *}
    {fbvFormArea id="ojsdefSettings"}
        {fbvFormSection}
            {fbvElement type="text" id="backend_url" required="true"
                label="plugins.generic.ojsdef.settings.backendUrl"
                value=$backend_url size=$fbvStyles.size.MEDIUM}
        {/fbvFormSection}
        {fbvFormSection}
            {fbvElement type="text" id="api_key" required="true"
                label="plugins.generic.ojsdef.settings.apiKey"
                value=$api_key size=$fbvStyles.size.LARGE}
        {/fbvFormSection}
        {fbvFormSection}
            {fbvElement type="text" id="target_id" required="true"
                label="plugins.generic.ojsdef.settings.targetId"
                value=$target_id size=$fbvStyles.size.LARGE}
        {/fbvFormSection}
    {/fbvFormArea}

    {* Connection Status (read-only) *}
    <div class="pkp_helpers_clear">
        <h3>{translate key="plugins.generic.ojsdef.status.connection"}</h3>
        <table class="pkpTable">
            <tbody>
                <tr>
                    <td><strong>{translate key="plugins.generic.ojsdef.status.connection"}</strong></td>
                    <td>
                        {if $last_heartbeat_at}
                            {translate key="plugins.generic.ojsdef.status.connected"}
                        {else}
                            {translate key="plugins.generic.ojsdef.status.disconnected"}
                        {/if}
                    </td>
                </tr>
                <tr>
                    <td><strong>Mode</strong></td>
                    <td>
                        {if $connection_mode == 'direct'}
                            {translate key="plugins.generic.ojsdef.status.directMode"}
                        {elseif $connection_mode == 'heartbeat'}
                            {translate key="plugins.generic.ojsdef.status.heartbeatMode"}
                        {else}
                            {translate key="plugins.generic.ojsdef.status.unknown"}
                        {/if}
                    </td>
                </tr>
                <tr>
                    <td><strong>{translate key="plugins.generic.ojsdef.status.lastHeartbeat"}</strong></td>
                    <td>{$last_heartbeat_at|default:"—"}</td>
                </tr>
            </tbody>
        </table>

        {if $connection_mode == 'heartbeat'}
        <div class="pkp_notification pkp_notification_warning">
            <p>{translate key="plugins.generic.ojsdef.status.firewallWarning"}</p>
        </div>
        {/if}
    </div>

    {fbvFormButtons submitText="plugins.generic.ojsdef.settings.save"}
</form>
```

- [ ] **Step 4: Verifikasi PHP syntax**

```bash
php -l ojsdef/classes/OjsdefSettingsForm.php
php -l ojsdef/OjsdefPlugin.php
```

Expected: `No syntax errors detected` untuk keduanya.

- [ ] **Step 5: Jalankan semua tests - pastikan masih PASS**

```bash
vendor/bin/phpunit
```

Expected: `OK (19 tests, 22 assertions)`

- [ ] **Step 6: Commit**

```bash
git add ojsdef/classes/OjsdefSettingsForm.php ojsdef/OjsdefPlugin.php ojsdef/templates/settingsForm.tpl
git commit -m "feat: add Settings UI with connection status display and Test Connection action"
```

---

## Task 13: Packaging + Integration Test

**Files:**
- Create: `.gitignore`

- [ ] **Step 1: Buat `.gitignore`**

```
vendor/
composer.lock
*.zip
```

- [ ] **Step 2: Jalankan full test suite dengan output verbose**

```bash
vendor/bin/phpunit --testdox
```

Expected semua PASS:
```
HmacSigner
 ✔ Sign returns sha256 prefixed hex
 ✔ Sign is deterministic
 ✔ Different body produces different signature
 ✔ Verify returns true for valid signature
 ✔ Verify returns false for wrong body
 ✔ Verify returns false for expired timestamp
 ✔ Verify returns false for future timestamp
 ✔ Verify returns false for wrong prefix

ApiClientSigning
 ✔ Headers contain required fields
 ✔ Signature header starts with sha256

ContentInjectionDetector
 ✔ Detects gambling keyword slot gacor
 ✔ Detects gambling keyword judi online
 ✔ Detects gambling keyword sbobet
 ✔ Detects hidden iframe
 ✔ Detects js redirect
 ✔ Detects base64 eval
 ✔ Detects phishing tld
 ✔ Clean academic text returns empty
 ✔ Clean html returns empty
```

- [ ] **Step 3: Buat plugin ZIP untuk distribusi**

```bash
# Dari direktori OJSDEF-Plugin/
Compress-Archive -Path ojsdef -DestinationPath ojsdef-plugin-1.0.0.zip -Force
```

File `ojsdef-plugin-1.0.0.zip` berisi direktori `ojsdef/` — siap diupload ke OJS.

- [ ] **Step 4: Integration test manual di OJS**

Syarat: OJS 3.3.x atau 3.4.x terinstall dan berjalan di environment development.

```
1. Upload ojsdef-plugin-1.0.0.zip:
   OJS Admin Panel -> Website Settings -> Plugins -> Upload a New Plugin

2. Enable plugin:
   Generic Plugins -> OJSDef Security Scanner -> Enable (toggle)

3. Konfigurasi plugin:
   OJSDef Security Scanner -> Settings
   - Backend URL: [URL backend OJSDef]
   - API Key: [dari OJSDef dashboard]
   - Target ID: [dari OJSDef dashboard]
   Klik Save

4. Verifikasi heartbeat:
   - Kunjungi halaman OJS apapun (trigger Core::loadBaseData)
   - Cek OJSDef dashboard -> target status harus berubah jadi "Connected"
   - Cek connection_mode: "direct" atau "heartbeat"

5. Test probe (Direct Mode):
   - Jika connection_mode = "direct": backend berhasil reach plugin
   - Jika connection_mode = "heartbeat": plugin di balik firewall

6. Trigger scan dari OJSDef dashboard:
   - Klik "Run Scan" (pilih Internal atau Full)
   - Tunggu callback diterima backend (~45-60 detik)
   - Verifikasi findings muncul di dashboard OJSDef

7. Verifikasi payload callback (lihat backend logs):
   Pastikan semua 6 modul ada di modules_completed:
   ["fingerprint", "config", "plugins", "rbac", "file_integrity", "content"]
```

- [ ] **Step 5: Final commit dan tag**

```bash
git add .gitignore
git commit -m "chore: add gitignore"
git tag v1.0.0 -m "OJSDef Plugin v1.0.0 - MVP Release"
```

---

## Self-Review

### Spec Coverage

| Requirement dari Spec | Task |
|----------------------|------|
| GenericPlugin, isSitePlugin() | Task 1, 5 |
| version.xml untuk OJS 3.3.x–3.5.x | Task 1 |
| PHP 7.4 compatible (no 8.x-only features) | Semua task — tidak ada constructor property promotion, union types, dll |
| HmacSigner: sign + verify + anti-replay 300 detik | Task 3 |
| ApiClient: cURL, POST signed, GET unsigned | Task 4 |
| ApiClient: sendHeartbeat() dengan extra fields | Task 4, 5 |
| ApiClient: sendCallback() retry 3x | Task 4 |
| Heartbeat setiap 5 menit via Core::loadBaseData | Task 5 |
| reachability_challenge di first heartbeat (mode=unknown) | Task 5 |
| scan_requested dari heartbeat response (Heartbeat Mode) | Task 5 |
| pending_callback retry di heartbeat berikutnya | Task 5 |
| OjsdefHandler.probe(): verifikasi HMAC + update connection_mode = direct | Task 6 |
| OjsdefHandler.trigger(): respond 202 + fastcgi_finish_request + scan background | Task 6 |
| ScanOrchestrator: runAll() dengan error isolation per modul | Task 7 |
| FingerprintScanner: ojs_version, php_version, plugin list | Task 8 |
| ConfigScanner: 11 config fields, tidak expose password | Task 8 |
| PluginAuditor: disabled_but_installed | Task 9 |
| RbacAuditor: inactive >1 tahun, hanya user_id (tidak expose PII) | Task 9 |
| FileIntegrityChecker: fetch checksums dari backend, cache 7 hari | Task 10 |
| ContentInjectionDetector: 5 patterns, 100-char excerpt | Task 11 |
| Settings form: 3 field input | Task 12 |
| Status display: connected/disconnected, direct/heartbeat/unknown | Task 12 |
| Firewall warning banner ketika mode=heartbeat | Task 12 |
| Locale strings en_US | Task 1 |
| Plugin ZIP untuk distribusi | Task 13 |

### Placeholder Scan

Tidak ada "TBD", "TODO", atau "implement later" dalam plan ini.

### Type Consistency

- `HmacSigner::sign(string $body, int $timestamp): string` — digunakan di `ApiClient::makeHeaders()` dan `OjsdefHandler::_verifyHmac()`
- `ApiClient::sendHeartbeat(array $extra = []): array` returns `['code'=>int,'body'=>?array]` — konsisten di `OjsdefPlugin::maybeSendHeartbeat()`
- `ApiClient::sendCallback(string $jobId, array $data): bool` — konsisten di `OjsdefPlugin::_runScanFromHeartbeat()` dan `OjsdefHandler::trigger()`
- `ApiClient::get(string $endpoint): array` returns `['code'=>int,'body'=>?array]` — konsisten di `FileIntegrityChecker::_getChecksums()`
- `ScanOrchestrator::runAll(string $jobId, array $modules): array` returns `{modules_completed,modules_failed,results,status}` — konsisten di `OjsdefPlugin::_runScanFromHeartbeat()` dan `OjsdefHandler::trigger()`
- Semua scanner: `__construct($plugin)` + `scan(): array` — konsisten dengan `ScanOrchestrator::$scannerMap`
- `ContentInjectionDetector::testDetect(string $text): array` — hanya digunakan di test, tidak di production path
