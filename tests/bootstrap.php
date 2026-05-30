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
