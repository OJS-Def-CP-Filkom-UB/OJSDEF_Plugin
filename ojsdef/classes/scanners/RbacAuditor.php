<?php

class RbacAuditor
{
    /** @var object */
    private $plugin;

    // Tidak login selama ini = "inactive high privilege"
    const INACTIVE_THRESHOLD_SECONDS = 365 * 86400;

    // Role ID OJS: 1=Manager, 16=Site Admin
    const HIGH_PRIVILEGE_ROLES = [1, 16];

    public function __construct($plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * @return array {
     *   total_users: int,
     *   superadmin_count: int,
     *   multiple_superadmin: bool,
     *   inactive_high_priv_count: int,
     *   inactive_high_priv_users: array  (hanya user_id + last_login, tanpa PII)
     * }
     */
    public function scan(): array
    {
        if (!class_exists('DAORegistry')) {
            return $this->_emptyResult('DAORegistry not available');
        }
        try {
            $userDao = \DAORegistry::getDAO('UserDAO');
            if (!$userDao) return $this->_emptyResult('UserDAO not available');

            $superAdmins      = $userDao->getAdminUsers();
            $superAdminCount  = $superAdmins ? $superAdmins->getCount() : 0;

            $totalUsers   = 0;
            $inactiveHigh = [];
            $cutoffTime   = time() - self::INACTIVE_THRESHOLD_SECONDS;
            $allUsers     = $userDao->getUsersByContextId(null);

            if ($allUsers) {
                while ($user = $allUsers->next()) {
                    $totalUsers++;
                    $lastLogin = $user->getDateLastLogin() ? strtotime($user->getDateLastLogin()) : 0;
                    if ($lastLogin && $lastLogin < $cutoffTime && $this->_hasHighPrivilege($user)) {
                        $inactiveHigh[] = [
                            'user_id'    => $user->getId(),
                            'last_login' => $user->getDateLastLogin(),
                        ];
                    }
                }
            }

            return [
                'total_users'               => $totalUsers,
                'superadmin_count'          => $superAdminCount,
                'multiple_superadmin'       => $superAdminCount > 1,
                'inactive_high_priv_count'  => count($inactiveHigh),
                'inactive_high_priv_users'  => $inactiveHigh,
            ];
        } catch (\Throwable $e) {
            return $this->_emptyResult($e->getMessage());
        }
    }

    private function _hasHighPrivilege($user): bool
    {
        if (!class_exists('DAORegistry')) return false;
        try {
            $dao    = \DAORegistry::getDAO('UserGroupDAO');
            if (!$dao) return false;
            $groups = $dao->getByUserId($user->getId());
            while ($group = $groups->next()) {
                if (in_array($group->getRoleId(), self::HIGH_PRIVILEGE_ROLES, true)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // silent
        }
        return false;
    }

    private function _emptyResult(string $reason): array
    {
        return [
            'total_users'               => 0,
            'superadmin_count'          => 0,
            'multiple_superadmin'       => false,
            'inactive_high_priv_count'  => 0,
            'inactive_high_priv_users'  => [],
            'error'                     => $reason,
        ];
    }
}
