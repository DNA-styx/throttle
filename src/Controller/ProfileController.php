<?php

namespace App\Controller;

use App\Entity\ExternalAccount;
use App\Entity\User;
use App\Runtime\CrashAiProviderCatalog;
use App\Runtime\UploadSettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use App\Runtime\UserAiConfigManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_USER)]
class ProfileController extends AbstractController
{
    private const TOKEN_ACTIVITY_PAGE_SIZE = 50;

    #[Route('/profile', name: 'profile', methods: ['GET'])]
    public function show(Request $request, EntityManagerInterface $entityManager, Connection $connection, UserAiConfigManager $aiConfigManager, KernelInterface $kernel): Response
    {
        $user = $this->currentUser();
        if ($user->getUploadToken() === '') {
            $user->regenerateUploadToken();
            $entityManager->flush();
        }
        $steamAccount = $this->findExternalAccount($user, 'steam');
        $baseUrl = $request->getSchemeAndHttpHost();
        $steamId = $steamAccount?->getIdentifier() ?? 'YOUR_STEAMID64';
        $coreConfig = implode("\n", [
            '"MinidumpAccount" "' . $steamId . '"',
            '',
            '"MinidumpSymbolUpload" "3"',
            '"MinidumpBinaryUpload" "yes"',
            '"MinidumpPresubmit" "yes"',
            '',
            '"MinidumpUrl" "' . $baseUrl . '/submit?token=' . $user->getUploadToken() . '"',
            '"MinidumpSymbolUrl" "' . $baseUrl . '/symbols/submit?token=' . $user->getUploadToken() . '"',
            '"MinidumpBinaryUrl" "' . $baseUrl . '/binary/submit?token=' . $user->getUploadToken() . '"',
        ]);

        return $this->render('profile/show.html.twig', [
            'user' => $user,
            'steamAccount' => $steamAccount,
            'coreConfig' => $coreConfig,
            'coreConfigBaseUrl' => $baseUrl,
            'tokenStats' => $this->loadTokenStats($connection, $user),
            'recentServers' => $this->loadRecentServers($connection, $user, 5),
            'aiConfigs' => $aiConfigManager->listConfigsForUser($user->getId()),
            'aiProviderTemplates' => $aiConfigManager->providerTemplates(),
            'defaultAiPrompt' => CrashAiProviderCatalog::DEFAULT_PROMPT,
            'aiAnalysisEnabled' => UploadSettings::load($kernel->getProjectDir())['crash_ai_analysis_enabled'],
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
