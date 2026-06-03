<?php

namespace APP\plugins\generic\ojsdef;

use PKP\handler\Handler;
use PKP\plugins\PluginRegistry;

class OjsdefHandler extends Handler
{
    /**
     * Endpoint publik probe/trigger — autentikasi sesungguhnya via HMAC,
     * bukan sesi login OJS. Izinkan tanpa role assignment.
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        return true;
    }

    public function probe(array $args, $request): void
    {
        $plugin = PluginRegistry::getPlugin('generic', 'ojsdef');
        if (!$plugin) {
            $this->_json(503, ['error' => 'plugin_inactive']);
            return;
        }
        $plugin->_requireClasses();

        $body    = (string) file_get_contents('php://input');
        $payload = json_decode($body, true) ?? [];

        if (!$this->_verifyHmacFromBody($plugin, $body)) {
            $this->_json(401, ['error' => 'invalid_signature']);
            return;
        }

        $plugin->updateSetting(0, 'connection_mode', 'direct');

        $this->_json(200, [
            'challenge'      => $payload['challenge'] ?? '',
            'plugin_version' => '1.0.0',
        ]);
    }

    public function trigger(array $args, $request): void
    {
        $plugin = PluginRegistry::getPlugin('generic', 'ojsdef');
        if (!$plugin) {
            $this->_json(503, ['error' => 'plugin_inactive']);
            return;
        }
        $plugin->_requireClasses();

        $body    = (string) file_get_contents('php://input');
        $payload = json_decode($body, true) ?? [];

        if (!$this->_verifyHmacFromBody($plugin, $body)) {
            $this->_json(401, ['error' => 'invalid_signature']);
            return;
        }

        $jobId   = $payload['job_id'] ?? '';
        $modules = $payload['scan_modules'] ?? [
            'fingerprint', 'config', 'plugins', 'rbac', 'file_integrity', 'content'
        ];

        if (empty($jobId)) {
            $this->_json(400, ['error' => 'missing_job_id']);
            return;
        }

        $this->_json(202, ['status' => 'accepted', 'job_id' => $jobId]);
        $this->_closeConnection();

        $startTime    = time();
        $orchestrator = new \ScanOrchestrator($plugin);
        $result       = $orchestrator->runAll($jobId, $modules);
        $result['duration_seconds'] = time() - $startTime;

        $apiClient = new \ApiClient($plugin);
        if (!$apiClient->sendCallback($jobId, $result)) {
            $result['job_id'] = $jobId;
            $plugin->updateSetting(0, 'pending_callback', json_encode($result));
        }
    }

    private function _verifyHmacFromBody($plugin, string $body): bool
    {
        $signature = $_SERVER['HTTP_X_OJSDEF_SIGNATURE'] ?? '';
        $timestamp = (int) ($_SERVER['HTTP_X_OJSDEF_TIMESTAMP'] ?? 0);

        if (empty($signature) || $timestamp === 0) return false;

        $signer = new \HmacSigner((string) $plugin->getSetting(0, 'api_key'));
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

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\ojsdef\OjsdefHandler', '\OjsdefHandler');
}
