<?php

namespace APP\plugins\generic\ojsdef;

use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\request\RemoteActionConfirmationModal;
use APP\plugins\generic\ojsdef\classes\OjsdefSettingsForm;

class OjsdefPlugin extends GenericPlugin
{
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);

        if ($success && $this->getEnabled()) {
            Hook::add('LoadHandler', [$this, 'callbackLoadHandler']);
            Hook::add('TemplateManager::display', [$this, 'maybeSendHeartbeat']);
        }

        return $success;
    }

    public function callbackLoadHandler(string $hookName, array &$args): bool
    {
        if ($args[0] === 'ojsdef') {
            define('HANDLER_CLASS', OjsdefHandler::class);
            define('HANDLER_FILE', $this->getPluginPath() . '/OjsdefHandler.php');
            return true;
        }
        return false;
    }

    public function maybeSendHeartbeat(string $hookName, array $args): bool
    {
        $lastHeartbeat = (int) $this->getSetting(0, 'last_heartbeat_at');
        if ((time() - $lastHeartbeat) < 300) return false;

        $this->updateSetting(0, 'last_heartbeat_at', time());
        ignore_user_abort(true);

        $this->_requireClasses();
        $extra     = $this->_buildHeartbeatExtra();
        $apiClient = new \ApiClient($this);
        $result    = $apiClient->sendHeartbeat($extra);

        if (!empty($result['body']['scan_requested']) && !empty($result['body']['job_id'])) {
            $this->_runScanFromHeartbeat(
                $result['body']['job_id'],
                $result['body']['scan_modules'] ?? []
            );
        }

        $pending = $this->getSetting(0, 'pending_callback');
        if ($pending) {
            $pendingData = json_decode($pending, true);
            if ($pendingData && $apiClient->sendCallback($pendingData['job_id'], $pendingData)) {
                $this->updateSetting(0, 'pending_callback', null);
            }
        }

        return false;
    }

    private function _runScanFromHeartbeat(string $jobId, array $modules): void
    {
        @set_time_limit(0);
        ignore_user_abort(true);
        $this->_requireClasses();
        $orchestrator = new \ScanOrchestrator($this);
        $startTime    = time();
        $result       = $orchestrator->runAll($jobId, $modules);
        $result['duration_seconds'] = time() - $startTime;

        $apiClient = new \ApiClient($this);
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
        if (class_exists('\Config')) {
            $baseUrl = \Config::getVar('general', 'base_url', '');
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

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) return $actions;

        $router = $request->getRouter();

        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null,
                    ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        ));

        array_unshift($actions, new LinkAction(
            'testConnection',
            new RemoteActionConfirmationModal(
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
                require_once $this->getPluginPath() . '/classes/OjsdefSettingsForm.php';
                $form = new OjsdefSettingsForm($this);
                if (!$request->getUserVar('save')) {
                    $form->initData();
                    return new JSONMessage(true, $form->fetch($request));
                }
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    return new JSONMessage(true);
                }
                return new JSONMessage(false);

            case 'testConnection':
                $this->_requireClasses();
                $extra  = $this->_buildHeartbeatExtra();
                $result = (new \ApiClient($this))->sendHeartbeat($extra);

                if ($result['code'] === 200) {
                    return new JSONMessage(true);
                }

                $detail  = !empty($result['error']) ? $result['error'] : 'HTTP ' . $result['code'];
                $message = __('plugins.generic.ojsdef.testConnection.failed')
                           . ' — ' . htmlspecialchars($detail);
                return new JSONMessage(false, $message);

            default:
                return parent::manage($args, $request);
        }
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\ojsdef\OjsdefPlugin', '\OjsdefPlugin');
}
