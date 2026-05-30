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
