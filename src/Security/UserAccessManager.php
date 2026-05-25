<?php

namespace App\Security;

use App\Entity\ExternalAccount;
use App\Entity\User;
use App\Runtime\AuthSettings;

final class UserAccessManager
{
    public function __construct(
        private readonly AuthSettings $authSettings,
    ) {
    }

    public function isInteractiveLoginBlocked(User $user): bool
    {
        return $user->isBanned();
    }

    public function isUploadTokenBlocked(User $user): bool
    {
        return $user->getUploadsBlocked();
    }

    public function canUseProfileUploadToken(User $user): bool
    {
        return !$this->isUploadTokenBlocked($user);
    }

    public function canManageOwnAuthMethods(User $user): bool
    {
        return !$user->isBanned();
    }

    public function findExternalAccount(User $user, string $kind): ?ExternalAccount
    {
        foreach ($user->getExternalAccounts() as $externalAccount) {
            if ($externalAccount->getKind() === $kind) {
                return $externalAccount;
            }
        }

        return null;
    }

    /**
     * @param array{
     *     exclude_external_account_id?: ?int,
     *     exclude_password?: bool,
     *     exclude_email_link?: bool,
     *     exclude_token_login?: bool
     * } $options
     */
    public function countUsableLoginMethods(User $user, array $options = []): int
    {
        $excludeExternalAccountId = isset($options['exclude_external_account_id']) ? (int) $options['exclude_external_account_id'] : null;
        $excludePassword = (bool) ($options['exclude_password'] ?? false);
        $excludeEmailLink = (bool) ($options['exclude_email_link'] ?? false);
        $excludeTokenLogin = (bool) ($options['exclude_token_login'] ?? false);

        $count = 0;

        if (!$excludePassword && $user->hasLocalLogin() && $this->authSettings->isEnabled('password_login')) {
            ++$count;
        }

        if (
            !$excludeTokenLogin
            && $this->authSettings->isEnabled('token_login')
            && $user->getUploadToken() !== ''
            && !$user->getUploadsBlocked()
        ) {
            ++$count;
        }

        $contactEmailAccountId = $this->findContactEmailExternalAccountId($user);
        if (
            !$excludeEmailLink
            && $this->authSettings->isEnabled('email_login_link')
            && $user->isEmailVerified()
            && $contactEmailAccountId !== null
            && $contactEmailAccountId !== $excludeExternalAccountId
        ) {
            ++$count;
        }

        if ($this->authSettings->isEnabled('steam')) {
            $steam = $this->findExternalAccount($user, 'steam');
            if ($steam !== null && $steam->getId() !== $excludeExternalAccountId) {
                ++$count;
            }
        }

        if ($this->authSettings->isEnabled('discord')) {
            $discord = $this->findExternalAccount($user, 'discord');
            if ($discord !== null && $discord->getId() !== $excludeExternalAccountId) {
                ++$count;
            }
        }

        return $count;
    }

    private function findContactEmailExternalAccountId(User $user): ?int
    {
        $contactEmail = $user->getContactEmail();
        if ($contactEmail === null) {
            return null;
        }

        foreach ($user->getExternalAccounts() as $externalAccount) {
            if ($externalAccount->getKind() === 'email' && hash_equals($externalAccount->getIdentifier(), $contactEmail)) {
                return $externalAccount->getId();
            }
        }

        return null;
    }
}

