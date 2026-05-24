<?php

namespace App\Controller;

use App\Entity\ExternalAccount;
use App\Entity\User;
use App\Runtime\AuthEnvironment;
use App\Runtime\CrashAiProviderCatalog;
use App\Runtime\UploadSettings;
use App\Runtime\AuthSettings;
use App\Security\SocialLinkManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use App\Runtime\UserAiConfigManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[IsGranted(User::ROLE_USER)]
class ProfileController extends AbstractController
{
    private const TOKEN_ACTIVITY_PAGE_SIZE = 50;

    #[Route('/profile', name: 'profile', methods: ['GET'])]
    public function show(Request $request, EntityManagerInterface $entityManager, Connection $connection, UserAiConfigManager $aiConfigManager, KernelInterface $kernel, AuthSettings $authSettings, AuthEnvironment $authEnvironment): Response
    {
        $user = $this->currentUser();
        if ($user->getUploadToken() === '') {
            $user->regenerateUploadToken();
            $entityManager->flush();
        }
        $steamAccount = $this->findExternalAccount($user, 'steam');
        $discordAccount = $this->findExternalAccount($user, 'discord');
        $baseUrl = $request->getSchemeAndHttpHost();
        $coreLines = [];
        if ($steamAccount !== null) {
            $coreLines[] = '"MinidumpAccount" "' . $steamAccount->getIdentifier() . '"';
            $coreLines[] = '';
        }
        $coreConfig = implode("\n", array_merge($coreLines, [
            '"MinidumpSymbolUpload" "3"',
            '"MinidumpBinaryUpload" "yes"',
            '"MinidumpPresubmit" "yes"',
            '',
            '"MinidumpUrl" "' . $baseUrl . '/submit?token=' . $user->getUploadToken() . '"',
            '"MinidumpSymbolUrl" "' . $baseUrl . '/symbols/submit?token=' . $user->getUploadToken() . '"',
            '"MinidumpBinaryUrl" "' . $baseUrl . '/binary/submit?token=' . $user->getUploadToken() . '"',
        ]));

        return $this->render('profile/show.html.twig', [
            'user' => $user,
            'steamAccount' => $steamAccount,
            'discordAccount' => $discordAccount,
            'coreConfig' => $coreConfig,
            'coreConfigBaseUrl' => $baseUrl,
            'tokenStats' => $this->loadTokenStats($connection, $user),
            'recentServers' => $this->loadRecentServers($connection, $user, 5),
            'aiConfigs' => $aiConfigManager->listConfigsForUser($user->getId()),
            'aiProviderTemplates' => $aiConfigManager->providerTemplates(),
            'defaultAiPrompt' => CrashAiProviderCatalog::DEFAULT_PROMPT,
            'aiAnalysisEnabled' => UploadSettings::load($kernel->getProjectDir())['crash_ai_analysis_enabled'],
            'authMethods' => $authSettings->all(),
            'contactEmail' => $user->getContactEmail(),
            'canUnlinkSteam' => $steamAccount !== null && $this->countUsableLoginMethods($user, $authSettings) > 1,
            'canUnlinkDiscord' => $discordAccount !== null && $this->countUsableLoginMethods($user, $authSettings) > 1,
            'avatarSeed' => $this->avatarSeed($user),
            'mailerConfigured' => $authEnvironment->isMailerConfigured(),
            'mailerNotice' => $authEnvironment->mailerNotice(),
            'discordConfigured' => $authEnvironment->isDiscordConfigured(),
            'discordNotice' => $authEnvironment->discordNotice(),
            'discordCallbackUrl' => $this->generateUrl('login_discord', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    #[Route('/profile/token/statistics', name: 'profile_token_statistics', methods: ['GET'])]
    public function tokenStatistics(Request $request, Connection $connection): Response
    {
        $user = $this->currentUser();
        $usageTotal = $this->countRecentUsage($connection, $user);
        $auditTotal = $this->countRecentAudit($connection, $user);
        $usagePagination = $this->buildPagination($this->getPositivePage($request, 'usage_page'), $usageTotal);
        $auditPagination = $this->buildPagination($this->getPositivePage($request, 'audit_page'), $auditTotal);

        return $this->render('profile/token_statistics.html.twig', [
            'user' => $user,
            'tokenStats' => $this->loadTokenStats($connection, $user),
            'servers' => $this->loadRecentServers($connection, $user, 100),
            'recentUsage' => $this->loadRecentUsage($connection, $user, $usagePagination['page_size'], $usagePagination['offset']),
            'usagePagination' => $usagePagination,
            'recentAudit' => $this->loadRecentAudit($connection, $user, $auditPagination['page_size'], $auditPagination['offset']),
            'auditPagination' => $auditPagination,
        ]);
    }

    #[Route('/profile/token/regenerate', name: 'profile_regenerate_token', methods: ['POST'])]
    public function regenerateToken(Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('regenerate-upload-token', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $this->currentUser()->regenerateUploadToken();
        $entityManager->flush();

        $this->addFlash('success', 'Upload token regenerated.');

        return $this->redirectToRoute('profile', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/profile/theme', name: 'profile_theme', methods: ['POST'])]
    public function saveTheme(Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('profile-theme', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $theme = (string) $request->request->get('theme', User::THEME_LIGHT);
        if (!in_array($theme, User::THEMES, true)) {
            return new Response('Invalid theme preference.', Response::HTTP_BAD_REQUEST);
        }

        $this->currentUser()->setTheme($theme);
        $entityManager->flush();

        $this->addFlash('success', 'Theme preference saved.');

        return $this->redirectToRoute('profile', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/profile/email', name: 'profile_email', methods: ['POST'])]
    public function saveEmail(Request $request, EntityManagerInterface $entityManager, \App\Repository\ExternalAccountRepository $externalAccountRepository, \App\Security\AuthMailer $authMailer, AuthEnvironment $authEnvironment): Response
    {
        if (!$this->isCsrfTokenValid('profile-email', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }
        if (!$authEnvironment->isMailerConfigured()) {
            $this->addFlash('danger', $authEnvironment->mailerNotice());

            return $this->redirectToRoute('profile');
        }

        $user = $this->currentUser();
        $email = mb_strtolower(trim((string) $request->request->get('email', '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addFlash('danger', 'Enter a valid email address.');

            return $this->redirectToRoute('profile');
        }

        $existing = $externalAccountRepository->findOneBy([
            'kind' => 'email',
            'identifier' => $email,
        ]);
        if ($existing !== null && $existing->getUser()->getId() !== $user->getId()) {
            $this->addFlash('danger', 'This email is already linked to another user.');

            return $this->redirectToRoute('profile');
        }

        foreach ($user->getExternalAccounts() as $externalAccount) {
            if ($externalAccount->getKind() !== 'email' || $externalAccount->getIdentifier() === $email) {
                continue;
            }

            $user->removeExternalAccount($externalAccount);
            $entityManager->remove($externalAccount);
        }

        $emailAccount = $this->findExternalAccount($user, 'email');
        if ($emailAccount === null) {
            $emailAccount = new ExternalAccount($user, 'email', $email, $email);
            $user->addExternalAccount($emailAccount);
            $entityManager->persist($emailAccount);
        }

        $user->setContactEmail($email);
        $user->setEmailVerifiedAt(null);
        $entityManager->flush();

        $authMailer->sendVerification($user, $email);
        $this->addFlash('success', 'Email saved. Check your inbox to confirm it. If you do not see the message, check the Spam folder too.');

        return $this->redirectToRoute('profile');
    }

    #[Route('/profile/password', name: 'profile_password', methods: ['POST'])]
    public function savePassword(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher): Response
    {
        if (!$this->isCsrfTokenValid('profile-password', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $user = $this->currentUser();
        $current = (string) $request->request->get('current_password', '');
        $new = (string) $request->request->get('new_password', '');
        $confirm = (string) $request->request->get('confirm_password', '');
        $hadPassword = $user->hasPassword();

        if ($new === '' || strlen($new) < 8) {
            $this->addFlash('danger', 'Password must be at least 8 characters long.');

            return $this->redirectToRoute('profile');
        }

        if ($new !== $confirm) {
            $this->addFlash('danger', 'Password confirmation does not match.');

            return $this->redirectToRoute('profile');
        }

        if ($user->hasPassword() && !$passwordHasher->isPasswordValid($user, $current)) {
            $this->addFlash('danger', 'Current password is incorrect.');

            return $this->redirectToRoute('profile');
        }

        $user->setPasswordHash($passwordHasher->hashPassword($user, $new));
        $entityManager->flush();

        $this->addFlash('success', $hadPassword ? 'Password updated.' : 'Password set.');

        return $this->redirectToRoute('profile');
    }

    #[Route('/profile/link/{kind}', name: 'profile_link_external', methods: ['GET'])]
    public function linkExternal(string $kind, Request $request, AuthSettings $authSettings, AuthEnvironment $authEnvironment, SocialLinkManager $socialLinkManager): Response
    {
        $route = match ($kind) {
            'steam' => 'login_steam',
            'discord' => 'login_discord',
            default => null,
        };

        if ($route === null) {
            return new Response('Unsupported provider.', Response::HTTP_NOT_FOUND);
        }

        if (!$authSettings->isEnabled($kind)) {
            return new Response('This login method is disabled.', Response::HTTP_FORBIDDEN);
        }
        if ($kind === 'discord' && !$authEnvironment->isDiscordConfigured()) {
            $this->addFlash('danger', $authEnvironment->discordNotice());

            return $this->redirectToRoute('profile');
        }

        $returnPath = $this->generateUrl('profile');
        $socialLinkManager->begin($this->currentUser(), $kind, $returnPath);
        $request->getSession()->set('_security.main.target_path', $returnPath);

        return $this->redirectToRoute($route, [
            'return' => $returnPath,
        ]);
    }

    #[Route('/profile/unlink/{kind}', name: 'profile_unlink_external', methods: ['POST'])]
    public function unlinkExternal(string $kind, Request $request, EntityManagerInterface $entityManager, AuthSettings $authSettings): Response
    {
        if (!$this->isCsrfTokenValid('profile-unlink-' . $kind, (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $user = $this->currentUser();
        $externalAccount = $this->findExternalAccount($user, $kind);
        if ($externalAccount === null) {
            $this->addFlash('danger', 'This account is not linked.');

            return $this->redirectToRoute('profile');
        }

        if ($this->countUsableLoginMethods($user, $authSettings) <= 1) {
            $this->addFlash('danger', 'You cannot unlink the last available sign-in method.');

            return $this->redirectToRoute('profile');
        }

        $user->removeExternalAccount($externalAccount);
        $entityManager->remove($externalAccount);
        $entityManager->flush();

        $this->addFlash('success', ucfirst($kind) . ' account unlinked.');

        return $this->redirectToRoute('profile');
    }

    #[Route('/profile/ai-config/save', name: 'profile_ai_config_save', methods: ['POST'])]
    public function saveAiConfig(Request $request, UserAiConfigManager $aiConfigManager): Response
    {
        if (!$this->isCsrfTokenValid('profile-ai-config-save', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        try {
            $config = $aiConfigManager->saveConfig($this->currentUser(), $request->request->all());
            $this->addFlash('success', sprintf('AI config "%s" saved.', $config['display_name']));
        } catch (\Throwable $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('profile', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/profile/ai-config/delete', name: 'profile_ai_config_delete', methods: ['POST'])]
    public function deleteAiConfig(Request $request, UserAiConfigManager $aiConfigManager): Response
    {
        if (!$this->isCsrfTokenValid('profile-ai-config-delete', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $configId = (int) $request->request->get('config_id', 0);
        if ($configId < 1) {
            $this->addFlash('danger', 'Select an AI config to delete.');

            return $this->redirectToRoute('profile', [], Response::HTTP_SEE_OTHER);
        }

        try {
            $aiConfigManager->deleteConfig($this->currentUser(), $configId);
            $this->addFlash('success', 'AI config deleted.');
        } catch (\Throwable $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('profile', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/profile/delete-account', name: 'profile_delete_account', methods: ['POST'])]
    public function deleteAccount(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher, TokenStorageInterface $tokenStorage): Response
    {
        if (!$this->isCsrfTokenValid('profile-delete-account', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $user = $this->currentUser();
        if (mb_strtoupper(trim((string) $request->request->get('confirm_delete', ''))) !== 'DELETE') {
            $this->addFlash('danger', 'Type DELETE to confirm account removal.');

            return $this->redirectToRoute('profile');
        }

        if ($user->hasPassword() && !$passwordHasher->isPasswordValid($user, (string) $request->request->get('current_password', ''))) {
            $this->addFlash('danger', 'Current password is incorrect.');

            return $this->redirectToRoute('profile');
        }

        $entityManager->remove($user);
        $entityManager->flush();

        $tokenStorage->setToken(null);
        $request->getSession()->invalidate();

        return $this->redirectToRoute('login');
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function findExternalAccount(User $user, string $kind): ?ExternalAccount
    {
        foreach ($user->getExternalAccounts() as $externalAccount) {
            if ($externalAccount->getKind() === $kind) {
                return $externalAccount;
            }
        }

        return null;
    }

    private function countUsableLoginMethods(User $user, AuthSettings $authSettings): int
    {
        $count = 0;
        if ($user->hasLocalLogin() && $authSettings->isEnabled('password_login')) {
            ++$count;
        }
        if ($authSettings->isEnabled('token_login') && $user->getUploadToken() !== '') {
            ++$count;
        }
        if ($authSettings->isEnabled('email_login_link') && $user->isEmailVerified() && $user->getContactEmail() !== null) {
            ++$count;
        }
        if ($authSettings->isEnabled('steam') && $this->findExternalAccount($user, 'steam') !== null) {
            ++$count;
        }
        if ($authSettings->isEnabled('discord') && $this->findExternalAccount($user, 'discord') !== null) {
            ++$count;
        }

        return $count;
    }

    private function avatarSeed(User $user): string
    {
        return $user->getContactEmail()
            ?? $user->getLogin()
            ?? 'user-' . $user->getId();
    }

    /**
     * @return array{uses: int, bytes: int, first_used: ?string, last_used: ?string, crashes: int, symbols: int, binaries: int}
     */
    private function loadTokenStats(Connection $connection, User $user): array
    {
        $stats = $connection->fetchAssociative(
            'SELECT COUNT(*) AS uses,
                    COALESCE(SUM(bytes), 0) AS bytes,
                    MIN(created_at) AS first_used,
                    MAX(created_at) AS last_used,
                    SUM(CASE WHEN endpoint = \'crash\' THEN 1 ELSE 0 END) AS crashes,
                    SUM(CASE WHEN endpoint = \'symbols\' THEN 1 ELSE 0 END) AS symbols,
                    SUM(CASE WHEN endpoint = \'binary\' THEN 1 ELSE 0 END) AS binaries
             FROM upload_token_usage
             WHERE owner_id = ?',
            [$user->getId()],
        ) ?: [];

        return [
            'uses' => (int) ($stats['uses'] ?? 0),
            'bytes' => (int) ($stats['bytes'] ?? 0),
            'first_used' => $stats['first_used'] ?? null,
            'last_used' => $stats['last_used'] ?? null,
            'crashes' => (int) ($stats['crashes'] ?? 0),
            'symbols' => (int) ($stats['symbols'] ?? 0),
            'binaries' => (int) ($stats['binaries'] ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRecentServers(Connection $connection, User $user, int $limit): array
    {
        return $connection->fetchAllAssociative(
            'SELECT remote_addr,
                    account,
                    MAX(created_at) AS last_used,
                    COUNT(*) AS uses,
                    COALESCE(SUM(bytes), 0) AS bytes
             FROM upload_token_usage
             WHERE owner_id = ?
             GROUP BY remote_addr, account
             ORDER BY last_used DESC
             LIMIT ' . $limit,
            [$user->getId()],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRecentUsage(Connection $connection, User $user, int $limit, int $offset): array
    {
        return $connection->fetchAllAssociative(
            'SELECT created_at, endpoint, remote_addr, account, module, identifier, bytes, status_code, user_agent
             FROM upload_token_usage
             WHERE owner_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            [$user->getId()],
        );
    }

    private function countRecentUsage(Connection $connection, User $user): int
    {
        return (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM upload_token_usage
             WHERE owner_id = ?',
            [$user->getId()],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRecentAudit(Connection $connection, User $user, int $limit, int $offset): array
    {
        if ($this->isGranted(User::ROLE_ADMIN)) {
            return $connection->fetchAllAssociative(
                'SELECT created_at, endpoint, remote_addr, account, module, identifier, bytes, status_code, result, reason, token_suffix
                 FROM upload_token_audit
                 ORDER BY created_at DESC, id DESC
                 LIMIT ' . $limit . ' OFFSET ' . $offset,
            );
        }

        return $connection->fetchAllAssociative(
            'SELECT created_at, endpoint, remote_addr, account, module, identifier, bytes, status_code, result, reason, token_suffix
             FROM upload_token_audit
             WHERE owner_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset,
            [$user->getId()],
        );
    }

    private function countRecentAudit(Connection $connection, User $user): int
    {
        if ($this->isGranted(User::ROLE_ADMIN)) {
            return (int) $connection->fetchOne(
                'SELECT COUNT(*)
                 FROM upload_token_audit',
            );
        }

        return (int) $connection->fetchOne(
            'SELECT COUNT(*)
             FROM upload_token_audit
             WHERE owner_id = ?',
            [$user->getId()],
        );
    }

    private function getPositivePage(Request $request, string $parameter): int
    {
        $value = $request->query->get($parameter, '1');
        if (!is_scalar($value) || !ctype_digit((string) $value)) {
            return 1;
        }

        return max(1, (int) $value);
    }

    /**
     * @return array{page: int, page_size: int, total: int, total_pages: int, offset: int, previous_page: ?int, next_page: ?int}
     */
    private function buildPagination(int $requestedPage, int $total): array
    {
        $totalPages = max(1, (int) ceil($total / self::TOKEN_ACTIVITY_PAGE_SIZE));
        $page = min($requestedPage, $totalPages);

        return [
            'page' => $page,
            'page_size' => self::TOKEN_ACTIVITY_PAGE_SIZE,
            'total' => $total,
            'total_pages' => $totalPages,
            'offset' => ($page - 1) * self::TOKEN_ACTIVITY_PAGE_SIZE,
            'previous_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $page < $totalPages ? $page + 1 : null,
        ];
    }
}
