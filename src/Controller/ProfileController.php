<?php

namespace App\Controller;

use App\Entity\ExternalAccount;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_USER)]
class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'profile', methods: ['GET'])]
    public function show(Request $request, EntityManagerInterface $entityManager, Connection $connection): Response
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
        ]);
    }

    #[Route('/profile/token/statistics', name: 'profile_token_statistics', methods: ['GET'])]
    public function tokenStatistics(Connection $connection): Response
    {
        $user = $this->currentUser();

        return $this->render('profile/token_statistics.html.twig', [
            'user' => $user,
            'tokenStats' => $this->loadTokenStats($connection, $user),
            'servers' => $this->loadRecentServers($connection, $user, 100),
            'recentUsage' => $connection->fetchAllAssociative(
                'SELECT created_at, endpoint, remote_addr, account, module, identifier, bytes, status_code, user_agent
                 FROM upload_token_usage
                 WHERE owner_id = ?
                 ORDER BY created_at DESC
                 LIMIT 100',
                [$user->getId()],
            ),
            'recentAudit' => $this->loadRecentAudit($connection, $user),
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
    private function loadRecentAudit(Connection $connection, User $user): array
    {
        if ($this->isGranted(User::ROLE_ADMIN)) {
            return $connection->fetchAllAssociative(
                'SELECT created_at, endpoint, remote_addr, account, module, identifier, bytes, status_code, result, reason, token_suffix
                 FROM upload_token_audit
                 ORDER BY created_at DESC
                 LIMIT 100',
            );
        }

        return $connection->fetchAllAssociative(
            'SELECT created_at, endpoint, remote_addr, account, module, identifier, bytes, status_code, result, reason, token_suffix
             FROM upload_token_audit
             WHERE owner_id = ?
             ORDER BY created_at DESC
             LIMIT 100',
            [$user->getId()],
        );
    }
}
