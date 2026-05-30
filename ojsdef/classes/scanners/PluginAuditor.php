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
        $audit    = $this->_buildAuditList();
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
