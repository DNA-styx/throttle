<?php

namespace App\Security;

use App\Entity\ExternalAccount;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BootstrapAdminManager
{
    private bool $bootstrapped = false;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly string $bootstrapLogin,
        private readonly string $bootstrapEmail,
        private readonly string $bootstrapPassword,
    ) {
    }

    public function ensureBootstrapAdmin(): void
    {
        if ($this->bootstrapped) {
            return;
        }

        $this->bootstrapped = true;

        if (trim($this->bootstrapPassword) === '') {
            return;
        }

        $count = (int) $this->entityManager->createQuery('SELECT COUNT(u.id) FROM App\Entity\User u')->getSingleScalarResult();
        if ($count > 0) {
            return;
        }

        $login = mb_strtolower(trim($this->bootstrapLogin));
        $bootstrapEmail = mb_strtolower(trim($this->bootstrapEmail));
        if ($bootstrapEmail === '' && filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $bootstrapEmail = $login;
        }

        if ($bootstrapEmail === '' && $login === '') {
            return;
        }

        if ($login !== '' && $this->userRepository->findOneByLogin($login) !== null) {
            return;
        }

        $user = new User();
        $userName = $bootstrapEmail !== '' ? $bootstrapEmail : $login;
        if ($bootstrapEmail !== '') {
            $atPosition = mb_strrpos($bootstrapEmail, '@');
            $userName = mb_substr($bootstrapEmail, 0, ($atPosition !== false) ? $atPosition : null) ?: $bootstrapEmail;
        }

        $user->setName($userName);
        $user->setLogin($bootstrapEmail !== '' ? null : $login);
        $user->setRoles([User::ROLE_USER]);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $this->bootstrapPassword));
        if ($bootstrapEmail !== '') {
            $externalAccount = new ExternalAccount($user, 'email', $bootstrapEmail, $bootstrapEmail);
            $user->addExternalAccount($externalAccount);
            $user->setContactEmail($bootstrapEmail);
            $user->setEmailVerifiedAt(new \DateTimeImmutable());
            $this->entityManager->persist($externalAccount);
        }
        $user->setLastLogin(new \DateTimeImmutable());

        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Two first requests can race on a clean install. If another request
            // created the bootstrap user first, keep the current request alive.
            $this->entityManager->clear();
        }
    }
}
