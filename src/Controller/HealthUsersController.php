<?php

namespace App\Controller;

use App\Entity\ExternalAccount;
use App\Entity\User;
use App\Repository\ExternalAccountRepository;
use App\Repository\UserRepository;
use App\Runtime\AdminUserManager;
use App\Security\UserAccessManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_ADMIN)]
final class HealthUsersController extends AbstractController
{
    private const PER_PAGE = 25;

    #[Route('/health/users', name: 'health_users', methods: ['GET'])]
    #[Route('/health/users/{id}', name: 'health_user_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function index(Request $request, AdminUserManager $adminUserManager, UserRepository $userRepository, ?int $id = null): Response
    {
        $filters = $this->readFilters($request);
        $page = $this->positiveInt($request->query->get('page', '1'));
        $users = $adminUserManager->listUsers($filters, $page, self::PER_PAGE);
        $selectedUser = $id !== null ? $userRepository->find($id) : null;
        if ($id !== null && !$selectedUser instanceof User) {
            throw $this->createNotFoundException();
        }

        return $this->render('health/users.html.twig', [
            'filters' => $filters,
            'list' => $users,
            'selected' => $selectedUser instanceof User ? $adminUserManager->userDetails($selectedUser) : null,
            'selectedUserId' => $selectedUser?->getId(),
            'managedRoles' => User::MANAGED_ROLES,
        ]);
    }

    #[Route('/health/users/{id}/roles', name: 'health_user_roles', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateRoles(int $id, Request $request, UserRepository $userRepository, EntityManagerInterface $entityManager, AdminUserManager $adminUserManager): Response
    {
        $target = $this->targetUser($userRepository, $id);
        $this->assertCsrf($request, 'health-user-roles-' . $target->getId());

        $selectedRoles = array_values(array_intersect(
            User::MANAGED_ROLES,
            array_filter($request->request->all('roles'), 'is_string')
        ));

        $wouldBeDbAdmin = in_array(User::ROLE_ADMIN, $selectedRoles, true);
        if (
            !$wouldBeDbAdmin
            && !$adminUserManager->isConfigAdmin($target)
            && $adminUserManager->isLastEffectiveAdmin($target)
        ) {
            $this->addFlash('danger', 'You cannot remove ROLE_ADMIN from the last effective administrator.');

            return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
        }

        $target->setRoles($selectedRoles);
        $entityManager->flush();

        $this->addFlash('success', 'Roles updated.');

        return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
    }

    #[Route('/health/users/{id}/email', name: 'health_user_email', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateEmail(
        int $id,
        Request $request,
        UserRepository $userRepository,
        ExternalAccountRepository $externalAccountRepository,
        EntityManagerInterface $entityManager,
        UserAccessManager $userAccessManager,
    ): Response {
        $target = $this->targetUser($userRepository, $id);
        $this->assertCsrf($request, 'health-user-email-' . $target->getId());

        $email = mb_strtolower(trim((string) $request->request->get('email', '')));
        $verified = $request->request->getBoolean('email_verified');
        $legacyLoginWillRemain = $target->getLogin() !== null && $target->getLogin() !== '';

        if ($email === '') {
            if (
                $target->hasPassword()
                && !$legacyLoginWillRemain
                && $userAccessManager->countUsableLoginMethods($target, [
                    'exclude_password' => true,
                    'exclude_email_link' => true,
                ]) <= 0
            ) {
                $this->addFlash('danger', 'You cannot remove the last usable sign-in method from this account.');

                return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
            }

            foreach ($target->getExternalAccounts() as $externalAccount) {
                if ($externalAccount->getKind() !== 'email') {
                    continue;
                }

                $target->removeExternalAccount($externalAccount);
                $entityManager->remove($externalAccount);
            }

            $target->setContactEmail(null);
            $target->setEmailVerifiedAt(null);
            $entityManager->flush();

            $this->addFlash('success', 'Contact email removed.');

            return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('danger', 'Enter a valid email address.');

            return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
        }

        $existing = $externalAccountRepository->findOneBy([
            'kind' => 'email',
            'identifier' => $email,
        ]);
        if ($existing !== null && $existing->getUser()->getId() !== $target->getId()) {
            $this->addFlash('danger', 'This email is already linked to another user.');

            return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
        }

        $currentEmailAccount = null;
        foreach ($target->getExternalAccounts() as $externalAccount) {
            if ($externalAccount->getKind() !== 'email') {
                continue;
            }

            if ($externalAccount->getIdentifier() === $email) {
                $currentEmailAccount = $externalAccount;
                continue;
            }

            $target->removeExternalAccount($externalAccount);
            $entityManager->remove($externalAccount);
        }

        if (!$currentEmailAccount instanceof ExternalAccount) {
            $currentEmailAccount = new ExternalAccount($target, 'email', $email, $email);
            $target->addExternalAccount($currentEmailAccount);
            $entityManager->persist($currentEmailAccount);
        }

        $target->setContactEmail($email);
        $target->setEmailVerifiedAt($verified ? new \DateTimeImmutable() : null);
        $entityManager->flush();

        $this->addFlash('success', 'Contact email updated.');

        return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
    }

    #[Route('/health/users/{id}/ban', name: 'health_user_ban', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateBan(int $id, Request $request, UserRepository $userRepository, EntityManagerInterface $entityManager): Response
    {
        $target = $this->targetUser($userRepository, $id);
        $this->assertCsrf($request, 'health-user-ban-' . $target->getId());

        if ($this->currentUser()->getId() === $target->getId()) {
            $this->addFlash('danger', 'You cannot ban your own account.');

            return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
        }

        $ban = $request->request->getBoolean('ban');
        $target->setIsBanned($ban);
        $target->setBannedReason($ban ? (string) $request->request->get('banned_reason', '') : null);
        if ($ban) {
            $target->setBannedAt(new \DateTimeImmutable());
        }
        $entityManager->flush();

        $this->addFlash('success', $ban ? 'User banned.' : 'User unbanned.');

        return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
    }

    #[Route('/health/users/{id}/uploads', name: 'health_user_uploads', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateUploads(int $id, Request $request, UserRepository $userRepository, EntityManagerInterface $entityManager): Response
    {
        $target = $this->targetUser($userRepository, $id);
        $this->assertCsrf($request, 'health-user-uploads-' . $target->getId());

        $blocked = $request->request->getBoolean('uploads_blocked');
        $target->setUploadsBlocked($blocked);
        $entityManager->flush();

        $this->addFlash('success', $blocked ? 'Profile token uploads and token login blocked.' : 'Profile token uploads and token login restored.');

        return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
    }

    #[Route('/health/users/{id}/token/regenerate', name: 'health_user_regenerate_token', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function regenerateToken(int $id, Request $request, UserRepository $userRepository, EntityManagerInterface $entityManager): Response
    {
        $target = $this->targetUser($userRepository, $id);
        $this->assertCsrf($request, 'health-user-token-' . $target->getId());

        $target->regenerateUploadToken();
        $entityManager->flush();

        $this->addFlash('success', 'Upload token regenerated.');

        return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
    }

    #[Route('/health/users/{id}/unlink/{externalAccountId}', name: 'health_user_unlink_external', methods: ['POST'], requirements: ['id' => '\d+', 'externalAccountId' => '\d+'])]
    public function unlinkExternal(
        int $id,
        int $externalAccountId,
        Request $request,
        UserRepository $userRepository,
        AdminUserManager $adminUserManager,
        UserAccessManager $userAccessManager,
        EntityManagerInterface $entityManager,
    ): Response {
        $target = $this->targetUser($userRepository, $id);
        $this->assertCsrf($request, 'health-user-unlink-' . $target->getId() . '-' . $externalAccountId);

        $externalAccount = $adminUserManager->findExternalAccountById($target, $externalAccountId);
        if (!$externalAccount instanceof ExternalAccount) {
            throw $this->createNotFoundException();
        }

        $remainingMethods = $this->remainingLoginMethodsAfterUnlink($target, $externalAccount, $userAccessManager);
        if ($remainingMethods <= 0) {
            $this->addFlash('danger', 'You cannot remove the last usable sign-in method from this account.');

            return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
        }

        if ($externalAccount->getKind() === 'email' && $target->getContactEmail() !== null && hash_equals($target->getContactEmail(), $externalAccount->getIdentifier())) {
            $target->setContactEmail(null);
            $target->setEmailVerifiedAt(null);
        }

        $target->removeExternalAccount($externalAccount);
        $entityManager->remove($externalAccount);
        $entityManager->flush();

        $this->addFlash('success', ucfirst($externalAccount->getKind()) . ' account unlinked.');

        return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
    }

    #[Route('/health/users/{id}/delete', name: 'health_user_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteUser(int $id, Request $request, UserRepository $userRepository, AdminUserManager $adminUserManager, EntityManagerInterface $entityManager): Response
    {
        $target = $this->targetUser($userRepository, $id);
        $this->assertCsrf($request, 'health-user-delete-' . $target->getId());

        if ($this->currentUser()->getId() === $target->getId()) {
            $this->addFlash('danger', 'You cannot delete your own account from admin tools.');

            return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
        }

        if ($adminUserManager->isLastEffectiveAdmin($target)) {
            $this->addFlash('danger', 'You cannot delete the last effective administrator.');

            return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
        }

        if (mb_strtoupper(trim((string) $request->request->get('confirm_delete', ''))) !== 'DELETE') {
            $this->addFlash('danger', 'Type DELETE to confirm account removal.');

            return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
        }

        $details = $adminUserManager->userDetails($target);
        $stats = is_array($details) ? ($details['stats'] ?? null) : null;
        if (is_array($stats)) {
            $ownedRecords = [
                'teams' => (int) ($stats['teams_count'] ?? 0),
                'servers' => (int) ($stats['servers_count'] ?? 0),
                'crashes' => (int) ($stats['crashes_count'] ?? 0),
                'outgoing shares' => (int) ($stats['outgoing_share_total'] ?? 0),
                'incoming shares' => (int) ($stats['incoming_share_total'] ?? 0),
            ];

            foreach ($ownedRecords as $label => $count) {
                if ($count > 0) {
                    $this->addFlash('danger', sprintf('This account still has %d %s. Remove or transfer linked data first, or use a ban instead of deletion.', $count, $label));

                    return $this->redirectToRoute('health_user_show', ['id' => $target->getId()]);
                }
            }
        }

        $entityManager->remove($target);
        $entityManager->flush();

        $this->addFlash('success', 'User deleted.');

        return $this->redirectToRoute('health_users');
    }

    /**
     * @return array{
     *     id:string,
     *     email:string,
     *     steam_id:string,
     *     discord_id:string,
     *     role:string,
     *     banned:string,
     *     uploads_blocked:string,
     *     email_verified:string,
     *     has_local_password:string
     * }
     */
    private function readFilters(Request $request): array
    {
        return [
            'id' => trim((string) $request->query->get('id', '')),
            'email' => trim((string) $request->query->get('email', '')),
            'steam_id' => trim((string) $request->query->get('steam_id', '')),
            'discord_id' => trim((string) $request->query->get('discord_id', '')),
            'role' => trim((string) $request->query->get('role', '')),
            'banned' => trim((string) $request->query->get('banned', 'all')),
            'uploads_blocked' => trim((string) $request->query->get('uploads_blocked', 'all')),
            'email_verified' => trim((string) $request->query->get('email_verified', 'all')),
            'has_local_password' => trim((string) $request->query->get('has_local_password', 'all')),
        ];
    }

    private function targetUser(UserRepository $userRepository, int $id): User
    {
        $user = $userRepository->find($id);
        if (!$user instanceof User) {
            throw $this->createNotFoundException();
        }

        return $user;
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function assertCsrf(Request $request, string $tokenId): void
    {
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    private function positiveInt(mixed $value): int
    {
        if (!is_scalar($value) || !ctype_digit((string) $value)) {
            return 1;
        }

        return max(1, (int) $value);
    }

    private function remainingLoginMethodsAfterUnlink(User $user, ExternalAccount $externalAccount, UserAccessManager $userAccessManager): int
    {
        $options = [
            'exclude_external_account_id' => $externalAccount->getId(),
        ];

        if (
            $externalAccount->getKind() === 'email'
            && $user->getContactEmail() !== null
            && hash_equals($user->getContactEmail(), $externalAccount->getIdentifier())
        ) {
            $options['exclude_email_link'] = true;
            if ($user->getLogin() === null || $user->getLogin() === '') {
                $options['exclude_password'] = true;
            }
        }

        return $userAccessManager->countUsableLoginMethods($user, $options);
    }
}
