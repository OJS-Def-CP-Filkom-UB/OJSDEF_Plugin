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
     * @return array{'code': int, 'body': array|null}
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
     * @return array{'code': int, 'body': array|null}
     */
    public function get(string $endpoint): array
    {
        // Body is empty string for GET — still HMAC-signed so middleware can authenticate
        $timestamp = time();
        $ch = curl_init($this->backendUrl . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $this->makeHeaders('', $timestamp),
        ]);
        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => (int) $code, 'body' => $response ? json_decode($response, true) : null];
    }

    /**
     * Build signed headers. Protected agar bisa ditest via subclass.
     * @return string[]
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

    /**
     * @return array{'code': int, 'body': array|null, 'error': string|null}
     */
    private function post(string $endpoint, array $data): array
    {
        $body      = json_encode($data);
        $timestamp = time();
        $url       = $this->backendUrl . $endpoint;
        $ch        = curl_init($url);
        $caPath    = $this->_getCaPath();
        $opts      = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $this->makeHeaders($body, $timestamp),
        ];
        if ($caPath) $opts[CURLOPT_CAINFO] = $caPath;
        curl_setopt_array($ch, $opts);
        $response  = curl_exec($ch);
        $code      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);
        if ($curlErrno) {
            error_log("[OJSDef] POST {$url} — curl #{$curlErrno}: {$curlError}");
        }
        return [
            'code'  => (int) $code,
            'body'  => $response ? json_decode($response, true) : null,
            'error' => $curlError ?: null,
        ];
    }

    private function _getCaPath(): ?string
    {
        static $cache = false;
        if ($cache !== false) return $cache;
        foreach ([
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/cert.pem',
            '/usr/local/share/certs/ca-root-nss.crt',
        ] as $p) {
            if (file_exists($p)) { $cache = $p; return $p; }
        }
        $cache = null;
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
