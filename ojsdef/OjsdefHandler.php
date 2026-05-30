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
