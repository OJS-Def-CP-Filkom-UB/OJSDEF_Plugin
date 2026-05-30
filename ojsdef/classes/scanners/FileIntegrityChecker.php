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
