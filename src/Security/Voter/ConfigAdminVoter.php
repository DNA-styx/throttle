<?php

namespace App\Security\Voter;

use App\Entity\User;
use App\Security\ConfigAdminChecker;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

class ConfigAdminVoter extends Voter
{
    public function __construct(private readonly ConfigAdminChecker $configAdminChecker)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === User::ROLE_ADMIN;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $this->configAdminChecker->isAdmin($user);
    }
}
