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

        $ownerIds = $user->getServerOwners()
            ->map(static fn (ServerOwner $owner): int => $owner->getId())
            ->getValues();

        foreach ($this->loadAcceptedSharedOwners($user->getId()) as $owner) {
            $ownerIds[] = $owner['id'];
        }

        return array_values(array_unique($ownerIds));
    }

    /**
     * @return array<int, array{id:int,name:string,avatar:string|null}>
     */
    public function getAllowedOwners(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $owners = array_map(function (ServerOwner $owner): array {
            return [
                'id' => $owner->getId(),
                'name' => $owner->getName(),
                'avatar' => $owner instanceof User ? $this->getAvatarForUser($owner) : null,
            ];
        }, $user->getServerOwners()->getValues());

        $seen = [];
        foreach ($owners as $owner) {
            $seen[$owner['id']] = true;
        }

        foreach ($this->loadAcceptedSharedOwners($user->getId()) as $owner) {
            if (isset($seen[$owner['id']])) {
                continue;
            }

            $owners[] = [
                'id' => $owner['id'],
                'name' => $owner['name'],
                'avatar' => $owner['kind'] === 'user'
                    ? $this->buildAvatarFromSeed($owner['contact_email'] ?? sprintf('user-%d', $owner['id']))
                    : null,
            ];
            $seen[$owner['id']] = true;
        }

        return $owners;
    }

    /**
     * @return array<int, array{id:int,name:string,kind:string|null,contact_email:string|null}>
     */
    private function loadAcceptedSharedOwners(int $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT so.id, so.name, so.kind, email.identifier AS contact_email
             FROM share sh
             INNER JOIN server_owner so ON so.id = sh.owner
             LEFT JOIN user u ON u.id = so.id
             LEFT JOIN external_account email ON email.id = u.contact_email_id
             WHERE sh.user = ? AND sh.accepted IS NOT NULL
             ORDER BY sh.accepted DESC',
            [$userId]
        );

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'kind' => isset($row['kind']) ? (string) $row['kind'] : null,
                'contact_email' => isset($row['contact_email']) && $row['contact_email'] !== null ? (string) $row['contact_email'] : null,
            ];
        }, $rows);
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

        return $this->buildAvatarFromSeed($seed);
    }

    private function buildAvatarFromSeed(string $seed): string
    {
        return sprintf(
            'https://secure.gravatar.com/avatar/%s?s=80&r=any&default=identicon&forcedefault=1',
            md5($seed)
        );
    }
}
