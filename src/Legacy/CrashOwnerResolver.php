<?php

namespace App\Legacy;

use App\Entity\ServerOwner;
use App\Entity\User;
use App\Security\ConfigAdminChecker;
use Doctrine\DBAL\Connection;

class CrashOwnerResolver
{
    private Connection $connection;
    private ConfigAdminChecker $configAdminChecker;

    public function __construct(Connection $connection, ConfigAdminChecker $configAdminChecker)
    {
        $this->connection = $connection;
        $this->configAdminChecker = $configAdminChecker;
    }

    public function isAdmin(?User $user): bool
    {
        return ($user?->getRoles() !== null && in_array(User::ROLE_ADMIN, $user->getRoles(), true))
            || $this->configAdminChecker->isAdmin($user);
    }

    /**
     * @return array<int, int>
     */
    public function getAllowedOwnerIds(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return $user->getServerOwners()
            ->map(static fn (ServerOwner $owner): int => $owner->getId())
            ->getValues();
    }

    /**
     * @return array<int, array{id:int,name:string,avatar:string|null}>
     */
    public function getAllowedOwners(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_map(function (ServerOwner $owner): array {
            return [
                'id' => $owner->getId(),
                'name' => $owner->getName(),
                'avatar' => $owner instanceof User ? $this->getAvatarForUser($owner) : null,
            ];
        }, $user->getServerOwners()->getValues());
    }

    public function resolveOwnerIdFromLegacyIdentifier(string $legacyIdentifier): ?int
    {
        $ownerId = $this->connection->fetchOne(
            'SELECT user_id FROM external_account WHERE kind = ? AND identifier = ? LIMIT 1',
            ['steam', $legacyIdentifier]
        );

        return ($ownerId === false) ? null : (int) $ownerId;
    }

    public function getAvatarForUser(User $user): string
    {
        $seed = $user->getContactEmail();
        if ($seed === null) {
            $seed = sprintf('user-%d', $user->getId());
        }

        return sprintf(
            'https://secure.gravatar.com/avatar/%s?s=80&r=any&default=identicon&forcedefault=1',
            md5($seed)
        );
    }
}
