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
