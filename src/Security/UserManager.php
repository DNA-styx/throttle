<?php

namespace App\Security;

use App\Entity\ExternalAccount;
use App\Entity\User;
use App\Message\CheckUserRegisteredMessage;
use App\Repository\ExternalAccountRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class UserManager
{
    private EntityManagerInterface $entityManager;
    private ExternalAccountRepository $externalAccountRepository;
    private UserRepository $userRepository;
    private MessageBusInterface $bus;
    private int $loginLinkLifetime;

    public function __construct(EntityManagerInterface $entityManager, ExternalAccountRepository $externalAccountRepository, UserRepository $userRepository, MessageBusInterface $bus, int $loginLinkLifetime)
    {
        $this->entityManager = $entityManager;
        $this->externalAccountRepository = $externalAccountRepository;
        $this->userRepository = $userRepository;
        $this->bus = $bus;
        $this->loginLinkLifetime = $loginLinkLifetime;
    }

    public function findOrCreateUserForExternalAccount(string $kind, string $identifier, string $displayName, string $newUserDisplayName): User
    {
        $externalAccount = $this->externalAccountRepository->findOneBy([
            'kind' => $kind,
            'identifier' => $identifier,
        ]);

        if ($externalAccount !== null) {
            $user = $externalAccount->getUser();

            // If the user's existing name matches the existing external account display name, update it.
            // TODO: This doesn't work for Discord where we strip the discriminator off the user display name.
            if ($user->getName() === $externalAccount->getDisplayName()) {
                $user->setName($displayName);
            }

            $user->setLastLogin(new \DateTimeImmutable());
            $externalAccount->setDisplayName($displayName);
            $externalAccount->setLastLogin(new \DateTimeImmutable());

            $this->entityManager->flush();

            return $user;
        }

        // TODO: Display a warning interstitial that the user might be creating a new account.
        $user = new User();
        $user->setName($newUserDisplayName);
        $user->setLastLogin(new \DateTimeImmutable());

        $externalAccount = new ExternalAccount($user, $kind, $identifier, $displayName);
        $externalAccount->setLastLogin(new \DateTimeImmutable());
        $user->addExternalAccount($externalAccount);

        $this->entityManager->persist($user);
        $this->entityManager->persist($externalAccount);
        $this->entityManager->flush();

        return $user;
    }

    public function findByLogin(string $login): ?User
    {
        return $this->userRepository->findOneByLogin($login);
    }

    public function findByEmail(string $emailAddress): ?User
    {
        $externalAccount = $this->externalAccountRepository->findOneBy([
            'kind' => 'email',
            'identifier' => mb_strtolower(trim($emailAddress)),
        ]);

        return $externalAccount?->getUser();
    }

    public function findOrCreateUserForEmailAddress(string $emailAddress): User
    {
        $emailAddress = mb_strtolower(trim($emailAddress));
        $atPosition = mb_strrpos($emailAddress, '@');
        $displayName = mb_substr($emailAddress, 0, ($atPosition !== false) ? $atPosition : null);

        $user = $this->findOrCreateUserForExternalAccount('email', $emailAddress, $emailAddress, $displayName);

        // If findOrCreateUserForExternalAccount just created this user for us, schedule a check that they logged in.
        // TODO: It would probably be significantly cleaner to implement our own LoginLink implementation that
        //       did all the work post-login - there is really no reason to do it before they click the link...
        //       As another alternative, we may be able to use a temporary in-memory User for the login link,
        //       and implement a custom user provider that calls us to create the real user when the link is consumed.
        if ($user->getLastLogin() === null) {
            $this->bus->dispatch(new CheckUserRegisteredMessage($user->getId()), [
                DelayStamp::delayFor(new \DateInterval(sprintf('PT%dS', $this->loginLinkLifetime * 3))),
            ]);
        }

        return $user;
    }

    public function createLocalUser(?string $login, string $emailAddress, string $displayName): User
    {
        $emailAddress = mb_strtolower(trim($emailAddress));
        $atPosition = mb_strrpos($emailAddress, '@');
        $fallbackDisplayName = mb_substr($emailAddress, 0, ($atPosition !== false) ? $atPosition : null);

        $user = new User();
        $user->setName($displayName !== '' ? $displayName : ($fallbackDisplayName !== '' ? $fallbackDisplayName : $emailAddress));
        $user->setLogin($login);

        $externalAccount = new ExternalAccount($user, 'email', $emailAddress, $emailAddress);
        $user->addExternalAccount($externalAccount);
        $user->setContactEmail($externalAccount->getIdentifier());

        $this->entityManager->persist($user);
        $this->entityManager->persist($externalAccount);
        $this->entityManager->flush();

        return $user;
    }

    public function linkExternalAccount(User $user, string $kind, string $identifier, string $displayName): ExternalAccount
    {
        $existing = $this->externalAccountRepository->findOneBy([
            'kind' => $kind,
            'identifier' => $identifier,
        ]);
        if ($existing !== null && $existing->getUser()->getId() !== $user->getId()) {
            throw new \RuntimeException('This account is already linked to another user.');
        }

        if ($existing === null) {
            $existing = new ExternalAccount($user, $kind, $identifier, $displayName);
            $user->addExternalAccount($existing);
            $this->entityManager->persist($existing);
        } else {
            $existing->setDisplayName($displayName);
        }

        $existing->setLastLogin(new \DateTimeImmutable());
        $this->entityManager->flush();

        return $existing;
    }

    public function unlinkExternalAccount(User $user, ExternalAccount $externalAccount): void
    {
        if ($externalAccount->getUser()->getId() !== $user->getId()) {
            throw new \RuntimeException('This account belongs to another user.');
        }

        if ($externalAccount->getKind() === 'email' && $user->getContactEmail() === $externalAccount->getIdentifier()) {
            $user->setContactEmail(null);
        }

        $user->removeExternalAccount($externalAccount);
        $this->entityManager->remove($externalAccount);
        $this->entityManager->flush();
    }
}
