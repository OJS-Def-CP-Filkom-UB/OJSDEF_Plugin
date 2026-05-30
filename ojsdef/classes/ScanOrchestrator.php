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
