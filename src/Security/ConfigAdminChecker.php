<?php

namespace App\Security;

use App\Entity\User;

final class ConfigAdminChecker
{
    /**
     * @var array<int, string>|null
     */
    private ?array $configuredAdmins = null;

    public function __construct(private readonly string $adminUsers)
    {
    }

    public function isAdmin(?User $user): bool
    {
        if (!$user instanceof User) {
            return false;
        }

        foreach ($this->configuredAdmins() as $admin) {
            if (preg_match('/^user:(\d+)$/', $admin, $matches) === 1 && (int) $matches[1] === $user->getId()) {
                return true;
            }

            if (preg_match('/^\d{1,14}$/', $admin) === 1 && (int) $admin === $user->getId()) {
                return true;
            }

            $steamId = preg_match('/^steam:(\d{15,20})$/', $admin, $matches) === 1 ? $matches[1] : null;
            if ($steamId === null && preg_match('/^\d{15,20}$/', $admin) === 1) {
                $steamId = $admin;
            }

            if ($steamId !== null && $this->hasSteamId($user, $steamId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function configuredAdmins(): array
    {
        if ($this->configuredAdmins !== null) {
            return $this->configuredAdmins;
        }

        $items = preg_split('/[\s,;]+/', strtolower($this->adminUsers), -1, PREG_SPLIT_NO_EMPTY);

        return $this->configuredAdmins = is_array($items) ? array_values(array_unique($items)) : [];
    }

    private function hasSteamId(User $user, string $steamId): bool
    {
        foreach ($user->getExternalAccounts() as $externalAccount) {
            if ($externalAccount->getKind() === 'steam' && $externalAccount->getIdentifier() === $steamId) {
                return true;
            }
        }

        return false;
    }
}
