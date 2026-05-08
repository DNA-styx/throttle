<?php

namespace App\Security\Voter;

use App\Entity\Server;
use App\Entity\User;
use App\Security\ConfigAdminChecker;

/**
 * @extends EntityActionVoter<Server>
 */
class ServerVoter extends EntityActionVoter
{
    public const EDIT = 'SERVER_EDIT';
    public const VIEW = 'SERVER_VIEW';

    public function __construct(private readonly ConfigAdminChecker $configAdminChecker)
    {
    }

    protected function supportedEntityType(): string
    {
        return Server::class;
    }

    protected function supportedActions(): array
    {
        return [self::EDIT, self::VIEW];
    }

    protected function canUserPerformAction(User $user, string $action, object $subject): bool
    {
        if (\in_array(User::ROLE_ADMIN, $user->getRoles(), true) || $this->configAdminChecker->isAdmin($user)) {
            return true;
        }

        return match ($action) {
            self::EDIT, self::VIEW => $user->getServerOwners()->contains($subject->getOwner()),
            default => false,
        };
    }
}
