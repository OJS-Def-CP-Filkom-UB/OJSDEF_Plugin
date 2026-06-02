<?php

class ConfigScanner
{
    /** @var object */
    private $plugin;

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * @return array Audit config.inc.php — tidak expose nilai password/secret
     */
    public function scan(): array
    {
        return [
            'debug_mode'            => (bool) $this->_cfg('debug',    'debug_mode',    false),
            'show_errors'           => (bool) $this->_cfg('debug',    'show_errors',   false),
            'api_key_secret_length' => strlen((string) $this->_cfg('general',  'api_key_secret', '')),
            'force_ssl'             => (bool) $this->_cfg('security', 'force_ssl',     false),
            'allowed_hosts_set'     => !empty($this->_cfg('security', 'allowed_hosts', '')),
            'installed'             => (bool) $this->_cfg('general',  'installed',     false),
            'smtp_auth_enabled'     => !empty($this->_cfg('email',    'smtp_auth',     '')),
            'smtp_password_set'     => !empty($this->_cfg('email',    'smtp_password', '')),
            'db_driver'             => (string) $this->_cfg('database', 'driver',      ''),
            'db_host'               => (string) $this->_cfg('database', 'host',        ''),
            'db_password_empty'     => $this->_isDbPasswordEmpty(),
        ];
    }

    private function _isDbPasswordEmpty(): bool
    {
        $cfgVal = (string) $this->_cfg('database', 'password', '');
        if (!empty($cfgVal)) return false;

        $candidates = [
            'OJS_DB_PASSWORD', 'DB_PASSWORD', 'DATABASE_PASSWORD',
            'MYSQL_PASSWORD', 'POSTGRES_PASSWORD', 'PGPASSWORD',
        ];
        foreach ($candidates as $name) {
            $val = getenv($name);
            if ($val !== false && $val !== '') return false;
        }
        return true;
    }

    private function _cfg(string $section, string $key, $default)
    {
        if (!class_exists('Config')) return $default;
        return \Config::getVar($section, $key, $default);
    }
}
