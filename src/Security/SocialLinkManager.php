<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final class SocialLinkManager
{
    private const SESSION_KIND = 'auth.link.kind';
    private const SESSION_USER = 'auth.link.user_id';
    private const SESSION_RETURN = 'auth.link.return';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Security $security,
    ) {
    }

    public function begin(User $user, string $kind, string $returnPath): void
    {
        $session = $this->requestStack->getSession();
        $session->set(self::SESSION_KIND, $kind);
        $session->set(self::SESSION_USER, $user->getId());
        $session->set(self::SESSION_RETURN, $returnPath);
    }

    /**
     * @return array{kind:string,user_id:int,return:string}|null
     */
    public function current(): ?array
    {
        $session = $this->requestStack->getSession();
        $kind = $session->get(self::SESSION_KIND);
        $userId = $session->get(self::SESSION_USER);
        $return = $session->get(self::SESSION_RETURN, '/profile');

        if (!is_string($kind) || !is_int($userId)) {
            return null;
        }

        $currentUser = $this->security->getUser();
        if (!$currentUser instanceof User || $currentUser->getId() !== $userId) {
            return null;
        }

        if (!is_string($return) || $return === '' || $return[0] !== '/' || str_starts_with($return, '//')) {
            $return = '/profile';
        }

        return [
            'kind' => $kind,
            'user_id' => $userId,
            'return' => $return,
        ];
    }

    public function clear(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove(self::SESSION_KIND);
        $session->remove(self::SESSION_USER);
        $session->remove(self::SESSION_RETURN);
    }
}
