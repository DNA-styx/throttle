<?php

namespace App\Controller;

use App\Legacy\LegacyBridgeFactory;
use App\Runtime\CrashAiAnalysisManager;
use App\Runtime\CrashSourceLookupManager;
use App\Runtime\UploadSettings;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

class LegacyCrashController extends AbstractController
{
    use LegacyResponseTrait;

    private LegacyBridgeFactory $legacyBridgeFactory;
    private UserRepository $userRepository;
    private Connection $connection;
    private string $projectDir;
    private string $symbolUploadToken;

    public function __construct(LegacyBridgeFactory $legacyBridgeFactory, UserRepository $userRepository, Connection $connection, KernelInterface $kernel, string $symbolUploadToken)
    {
        $this->legacyBridgeFactory = $legacyBridgeFactory;
        $this->userRepository = $userRepository;
        $this->connection = $connection;
        $this->projectDir = $kernel->getProjectDir();
        $this->symbolUploadToken = $symbolUploadToken;
    }

    #[Route('/submit', name: 'submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        if (!$this->canSubmitMinidump($request)) {
            $this->recordRejectedMinidumpUpload($request, Response::HTTP_FORBIDDEN, 'invalid token');

            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        $response = $this->legacyResponse((new \Throttle\Crash())->submit($this->legacyBridgeFactory->createHttp($request)));
        $this->recordTokenUsage($request, $response);

        return $response;
    }

    #[Route('/submit', name: 'submit_get', methods: ['GET'])]
    public function submitGet(Request $request): Response
    {
        $app = $this->legacyBridgeFactory->createHttp($request);

        return new Response($app['twig']->render('error.html.twig', [
            'icon' => 'remove-sign',
            'title' => 'Method Not Allowed',
            'comment' => 'The Accelerator extension must be used to upload crash dumps.',
        ]));
    }

    #[Route('/{id}/download', name: 'download', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function download(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->download($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/view', name: 'view', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function view(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->view($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/logs', name: 'logs', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function logs(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->logs($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/symbols', name: 'symbol_coverage', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function symbols(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->symbols($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/metadata', name: 'metadata', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function metadata(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->metadata($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/console', name: 'console', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function console(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->console($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/error', name: 'error', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function error(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->error($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/carburetor', name: 'carburetor', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function carburetor(Request $request, string $id, CrashAiAnalysisManager $crashAiAnalysisManager): Response
    {
        $app = $this->legacyBridgeFactory->createHttp($request);
        $this->attachAiHistoryContext($app, $id, $crashAiAnalysisManager);

        return $this->legacyResponse((new \Throttle\Crash())->carburetor($app, $id));
    }

    #[Route('/{id}/carburetor/data', name: 'carburetor_data', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function carburetorData(Request $request, string $id): Response
    {
        return $this->legacyResponse((new \Throttle\Crash())->carburetor_data($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/carburetor/source-lookup', name: 'carburetor_source_lookup', methods: ['POST'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function carburetorSourceLookup(Request $request, string $id, CrashSourceLookupManager $sourceLookupManager): Response
    {
        if (!$this->isCsrfTokenValid('carburetor-source-lookup:'.$id, (string) $request->request->get('_token'))) {
            return $this->json(['status' => 'error', 'reason' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $app = $this->legacyBridgeFactory->createHttp($request);
        if ($app['user'] === null) {
            return $this->json(['status' => 'error', 'reason' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $ownerId = $this->connection->fetchOne('SELECT owner_id FROM crash WHERE id = ?', [$id]);
        if ($ownerId === false) {
            return $this->json(['status' => 'error', 'reason' => 'Crash not found.'], Response::HTTP_NOT_FOUND);
        }

        $canManage = $app['user']['admin'] || ($ownerId !== null && in_array((int) $ownerId, $app['user']['owner_ids'], true));
        if (!$canManage) {
            return $this->json(['status' => 'error', 'reason' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        $action = (string) $request->request->get('action', 'lookup');

        try {
            $payload = [
                'module' => $request->request->get('module'),
                'symbol' => $request->request->get('symbol'),
                'source_type' => $request->request->get('source_type'),
                'github_repo_url' => $request->request->get('github_repo_url'),
                'github_ref' => $request->request->get('github_ref'),
                'github_pat' => $request->request->get('github_pat'),
                'local_root' => $request->request->get('local_root'),
                'reload' => $request->request->getBoolean('reload'),
            ];

            $result = match ($action) {
                'load_cached' => $sourceLookupManager->describeForCrash($id, $payload, $app['user']['admin']),
                'lookup', 'reload' => $sourceLookupManager->lookupForCrash($id, $payload, true, $app['user']['admin']),
                default => throw new \InvalidArgumentException('Unsupported source lookup action.'),
            };

            return $this->json($result);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            if (str_contains(mb_strtolower($message), 'raw.githubusercontent.com') && str_contains(mb_strtolower($message), '404')) {
                $message = 'Source not found in the selected repository.';
            }

            return $this->json([
                'status' => 'error',
                'reason' => $message,
            ], $action === 'load_cached' ? Response::HTTP_BAD_REQUEST : Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}/ai-analyze', name: 'crash_ai_analyze', methods: ['POST'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function aiAnalyze(Request $request, string $id, CrashAiAnalysisManager $crashAiAnalysisManager): Response
    {
        if (!$this->isCsrfTokenValid('crash-ai-analyze:'.$id, (string) $request->request->get('_token'))) {
            return $this->json(['status' => 'error', 'reason' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        if ((UploadSettings::load($this->projectDir)['crash_ai_analysis_enabled'] ?? false) !== true) {
            return $this->json(['status' => 'error', 'reason' => 'AI crash analysis is disabled by the administrator.'], Response::HTTP_FORBIDDEN);
        }

        $app = $this->legacyBridgeFactory->createHttp($request);
        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User || $app['user'] === null) {
            return $this->json(['status' => 'error', 'reason' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        $ownerId = $this->connection->fetchOne('SELECT owner_id FROM crash WHERE id = ?', [$id]);
        if ($ownerId === false) {
            return $this->json(['status' => 'error', 'reason' => 'Crash not found.'], Response::HTTP_NOT_FOUND);
        }

        $canManage = $app['user']['admin'] || ($ownerId !== null && in_array((int) $ownerId, $app['user']['owner_ids'], true));
        if (!$canManage) {
            return $this->json(['status' => 'error', 'reason' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $result = $crashAiAnalysisManager->analyze($user, $app, $id, $request->request->all());

            return $this->json($result);
        } catch (\Throwable $e) {
            return $this->json([
                'status' => 'error',
                'reason' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/ai-history', name: 'crash_ai_history', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function aiHistory(Request $request, string $id, CrashAiAnalysisManager $crashAiAnalysisManager): Response
    {
        if ((UploadSettings::load($this->projectDir)['crash_ai_analysis_enabled'] ?? false) !== true) {
            return $this->json(['status' => 'error', 'reason' => 'AI crash analysis is disabled by the administrator.'], Response::HTTP_FORBIDDEN);
        }

        $app = $this->legacyBridgeFactory->createHttp($request);
        $ownerId = $this->connection->fetchOne('SELECT owner_id FROM crash WHERE id = ?', [$id]);
        if ($ownerId === false) {
            return $this->json(['status' => 'error', 'reason' => 'Crash not found.'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->getUser();
        $canManage = $app['user'] !== null && ($app['user']['admin'] || ($ownerId !== null && in_array((int) $ownerId, $app['user']['owner_ids'], true)));

        try {
            return $this->json([
                'status' => 'ok',
                'items' => $crashAiAnalysisManager->listHistoryForCrash($id, $user instanceof \App\Entity\User ? $user : null, $canManage),
                'can_ask' => $user instanceof \App\Entity\User && $canManage,
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'status' => 'error',
                'reason' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}/ai-history/{historyId}/visibility', name: 'crash_ai_history_visibility', methods: ['POST'], requirements: ['id' => '[0-9a-zA-Z]{12}', 'historyId' => '\d+'], priority: -10)]
    public function aiHistoryVisibility(Request $request, string $id, int $historyId, CrashAiAnalysisManager $crashAiAnalysisManager): Response
    {
        if (!$this->isCsrfTokenValid('crash-ai-analyze:'.$id, (string) $request->request->get('_token'))) {
            return $this->json(['status' => 'error', 'reason' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        if ((UploadSettings::load($this->projectDir)['crash_ai_analysis_enabled'] ?? false) !== true) {
            return $this->json(['status' => 'error', 'reason' => 'AI crash analysis is disabled by the administrator.'], Response::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user instanceof \App\Entity\User) {
            return $this->json(['status' => 'error', 'reason' => 'Authentication required.'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $item = $crashAiAnalysisManager->updateHistoryVisibility($user, $id, $historyId, !empty($request->request->get('is_public')));

            return $this->json([
                'status' => 'ok',
                'item' => $item,
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'status' => 'error',
                'reason' => $e->getMessage(),
            ], Response::HTTP_BAD_REQUEST);
        }
    }

    #[Route('/{id}/reprocess', name: 'reprocess', methods: ['POST'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function reprocess(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('reprocess-crash:'.$id, (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        return $this->legacyResponse((new \Throttle\Crash())->reprocess($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function delete(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('delete-crash:'.$id, (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        return $this->legacyResponse((new \Throttle\Crash())->delete($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/signature-notes', name: 'signature_note_create', methods: ['POST'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -10)]
    public function createSignatureNote(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('signature-note:'.$id, (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        return $this->legacyResponse((new \Throttle\Crash())->createSignatureNote($this->legacyBridgeFactory->createHttp($request), $id));
    }

    #[Route('/{id}/signature-notes/{noteId}/edit', name: 'signature_note_edit', methods: ['POST'], requirements: ['id' => '[0-9a-zA-Z]{12}', 'noteId' => '\d+'], priority: -10)]
    public function editSignatureNote(Request $request, string $id, int $noteId): Response
    {
        if (!$this->isCsrfTokenValid('signature-note-edit:'.$noteId, (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        return $this->legacyResponse((new \Throttle\Crash())->editSignatureNote($this->legacyBridgeFactory->createHttp($request), $id, $noteId));
    }

    #[Route('/{id}/signature-notes/{noteId}/delete', name: 'signature_note_delete', methods: ['POST'], requirements: ['id' => '[0-9a-zA-Z]{12}', 'noteId' => '\d+'], priority: -10)]
    public function deleteSignatureNote(Request $request, string $id, int $noteId): Response
    {
        if (!$this->isCsrfTokenValid('signature-note-delete:'.$noteId, (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        return $this->legacyResponse((new \Throttle\Crash())->deleteSignatureNote($this->legacyBridgeFactory->createHttp($request), $id, $noteId));
    }

    #[Route('/{id}/signature-notes/{noteId}/vote', name: 'signature_note_vote', methods: ['POST'], requirements: ['id' => '[0-9a-zA-Z]{12}', 'noteId' => '\d+'], priority: -10)]
    public function voteSignatureNote(Request $request, string $id, int $noteId): Response
    {
        if (!$this->isCsrfTokenValid('signature-note-vote:'.$noteId, (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        return $this->legacyResponse((new \Throttle\Crash())->voteSignatureNote($this->legacyBridgeFactory->createHttp($request), $id, $noteId));
    }

    #[Route('/{id}/signature-notes/{noteId}/pin', name: 'signature_note_pin', methods: ['POST'], requirements: ['id' => '[0-9a-zA-Z]{12}', 'noteId' => '\d+'], priority: -10)]
    public function pinSignatureNote(Request $request, string $id, int $noteId): Response
    {
        if (!$this->isCsrfTokenValid('signature-note-pin:'.$noteId, (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        return $this->legacyResponse((new \Throttle\Crash())->pinSignatureNote($this->legacyBridgeFactory->createHttp($request), $id, $noteId));
    }

    #[Route('/{crashId}', name: 'details_crashid', methods: ['GET'], requirements: ['crashId' => '[0-9a-zA-Z]{4}(?:-[0-9a-zA-Z]{4}){2}'], priority: -95)]
    public function formattedId(string $crashId): Response
    {
        return $this->redirectToRoute('details', ['id' => strtolower(str_replace('-', '', $crashId))]);
    }

    #[Route('/{uuid}', name: 'details_uuid', methods: ['GET'], requirements: ['uuid' => '[0-9a-fA-F-]{36}'], priority: -100)]
    public function uuid(string $uuid): Response
    {
        $uuid = substr($uuid, 20, 3).substr($uuid, 24);
        $uuid = str_split($uuid);
        $bid = '';
        for ($i = 0; $i < 15; $i++) {
            $bid .= sprintf('%04b', hexdec($uuid[$i]));
        }
        $bid = str_split($bid, 5);

        $id = '';
        $map = array_merge(range('a', 'z'), range('2', '7'));
        for ($i = 0; $i < 12; $i++) {
            $id .= $map[bindec($bid[$i])];
        }

        return $this->redirectToRoute('details', ['id' => $id]);
    }

    #[Route('/{id}', name: 'details', methods: ['GET'], requirements: ['id' => '[0-9a-zA-Z]{12}'], priority: -100)]
    public function details(Request $request, string $id, CrashAiAnalysisManager $crashAiAnalysisManager): Response
    {
        $app = $this->legacyBridgeFactory->createHttp($request);
        $this->attachAiHistoryContext($app, $id, $crashAiAnalysisManager);

        return $this->legacyResponse((new \Throttle\Crash())->details($app, $id));
    }

    private function attachAiHistoryContext(\Silex\Application $app, string $id, CrashAiAnalysisManager $crashAiAnalysisManager): void
    {
        $app['crash-ai-history-items'] = [];

        if ((UploadSettings::load($this->projectDir)['crash_ai_analysis_enabled'] ?? false) !== true) {
            return;
        }

        $ownerId = $this->connection->fetchOne('SELECT owner_id FROM crash WHERE id = ?', [$id]);
        if ($ownerId === false) {
            return;
        }

        $user = $this->getUser();
        $canManage = $app['user'] !== null && ($app['user']['admin'] || ($ownerId !== null && in_array((int) $ownerId, $app['user']['owner_ids'], true)));

        try {
            $app['crash-ai-history-items'] = $crashAiAnalysisManager->listHistoryForCrash($id, $user instanceof \App\Entity\User ? $user : null, $canManage);
        } catch (\Throwable) {
            $app['crash-ai-history-items'] = [];
        }
    }

    private function recordTokenUsage(Request $request, Response $response): void
    {
        $provided = $this->getProvidedToken($request);
        if (!is_string($provided) || $provided === '') {
            return;
        }

        $tokenUser = $this->userRepository->findOneBy(['uploadToken' => $provided]);
        $globalTokenValid = $this->isGlobalUploadToken($provided);
        $bytes = 0;
        foreach ($request->files->all() as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $bytes += (int) $file->getSize();
            }
        }

        $identifier = null;
        if (preg_match('/Crash ID:\s*([A-Z0-9-]+)/i', (string) $response->getContent(), $matches) === 1) {
            $identifier = strtoupper($matches[1]);
        }

        $remoteAddr = $this->detectServerAddress($request) ?? $request->getClientIp();
        $account = $this->stringRequestValue($request, 'UserID') ?? $this->stringRequestValue($request, 'MinidumpAccount');
        $validToken = $tokenUser !== null || $globalTokenValid;
        $result = !$validToken || $response->getStatusCode() >= 400 ? 'rejected' : 'accepted';
        $reason = !$validToken ? 'invalid token' : ($response->getStatusCode() >= 400 ? trim(strip_tags((string) $response->getContent())) : null);

        $this->recordAudit($tokenUser?->getId(), $request, 'crash', $remoteAddr, $account, null, $identifier, $bytes, $response->getStatusCode(), $result, $reason, $provided);

        if ($tokenUser === null) {
            return;
        }

        $this->connection->insert('upload_token_usage', [
            'owner_id' => $tokenUser->getId(),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'endpoint' => 'crash',
            'remote_addr' => $remoteAddr,
            'account' => $account,
            'module' => null,
            'identifier' => $identifier,
            'bytes' => $bytes,
            'status_code' => $response->getStatusCode(),
            'user_agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 255),
        ]);
    }

    private function canSubmitMinidump(Request $request): bool
    {
        $settings = UploadSettings::load($this->projectDir);
        if (($settings['allow_anonymous_minidump_uploads'] ?? true) === true) {
            return true;
        }

        $provided = $this->getProvidedToken($request);
        if (!is_string($provided) || $provided === '') {
            return false;
        }

        if ($this->isGlobalUploadToken($provided)) {
            return true;
        }

        return $this->userRepository->findOneBy(['uploadToken' => $provided]) !== null;
    }

    private function isGlobalUploadToken(?string $provided): bool
    {
        return is_string($provided)
            && $provided !== ''
            && $this->symbolUploadToken !== ''
            && hash_equals($this->symbolUploadToken, $provided);
    }

    private function recordRejectedMinidumpUpload(Request $request, int $statusCode, string $reason): void
    {
        $bytes = 0;
        foreach ($request->files->all() as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $bytes += (int) $file->getSize();
            }
        }

        $this->recordAudit(
            null,
            $request,
            'crash',
            $this->detectServerAddress($request) ?? $request->getClientIp(),
            $this->stringRequestValue($request, 'UserID') ?? $this->stringRequestValue($request, 'MinidumpAccount'),
            null,
            null,
            $bytes,
            $statusCode,
            'rejected',
            $reason,
            $this->getProvidedToken($request)
        );
    }

    private function recordAudit(?int $ownerId, Request $request, string $endpoint, ?string $remoteAddr, ?string $account, ?string $module, ?string $identifier, int $bytes, int $statusCode, string $result, ?string $reason, ?string $token): void
    {
        $this->connection->insert('upload_token_audit', [
            'owner_id' => $ownerId,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'endpoint' => $endpoint,
            'remote_addr' => $remoteAddr,
            'account' => $account,
            'module' => $module,
            'identifier' => $identifier,
            'bytes' => $bytes,
            'status_code' => $statusCode,
            'result' => $result,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'token_suffix' => is_string($token) && $token !== '' ? substr($token, -8) : null,
            'user_agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 255),
        ]);
    }

    private function getProvidedToken(Request $request): ?string
    {
        $provided = $request->headers->get('X-Symbol-Upload-Token');
        if (!is_string($provided) || $provided === '') {
            $provided = $request->headers->get('Authorization');
            if (is_string($provided) && preg_match('/^Bearer\s+(.+)$/', $provided, $matches) === 1) {
                $provided = $matches[1];
            }
        }

        if (!is_string($provided) || $provided === '') {
            $provided = $request->request->get('token');
        }

        if (!is_string($provided) || $provided === '') {
            $provided = $request->query->get('token');
        }

        return is_string($provided) ? $provided : null;
    }

    private function stringRequestValue(Request $request, string $key): ?string
    {
        $value = $request->request->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function detectServerAddress(Request $request): ?string
    {
        foreach ([
            'PublicIP',
            'PublicIp',
            'public_ip',
            'ServerIP',
            'ServerIp',
            'ServerAddress',
            'ServerAddr',
            'HostIP',
            'HostIp',
        ] as $key) {
            $address = $this->normalizeServerAddress($this->stringRequestValue($request, $key), $this->detectServerPort($request));
            if ($address !== null) {
                return $address;
            }
        }

        $metadata = $request->files->get('upload_file_metadata');
        if ($metadata instanceof UploadedFile && $metadata->isValid() && $metadata->getSize() > 0) {
            $contents = file_get_contents($metadata->getRealPath());
            if (is_string($contents)) {
                $address = $this->detectServerAddressFromText($contents);
                if ($address !== null) {
                    return $address;
                }
            }
        }

        return null;
    }

    private function detectServerAddressFromText(string $text): ?string
    {
        if (preg_match('/public\s+IP\s+from\s+Steam:\s*([0-9]{1,3}(?:\.[0-9]{1,3}){3})(?::(\d{1,5}))?/i', $text, $matches) === 1) {
            return $this->normalizeServerAddress($matches[1], $matches[2] ?? null);
        }

        $address = null;
        if (preg_match('/\b(?:PublicIP|PublicIp|public_ip|ServerIP|ServerIp|ServerAddress|ServerAddr|HostIP|HostIp)\s*[:=]\s*([0-9]{1,3}(?:\.[0-9]{1,3}){3})(?::(\d{1,5}))?/i', $text, $matches) === 1) {
            $address = $matches[1] . (isset($matches[2]) && $matches[2] !== '' ? ':' . $matches[2] : '');
        }

        $port = null;
        if (preg_match('/\b(?:hostport|port|ServerPort|GamePort)\s*[:=]\s*(\d{1,5})\b/i', $text, $matches) === 1) {
            $port = $matches[1];
        }

        return $this->normalizeServerAddress($address, $port);
    }

    private function detectServerPort(Request $request): ?string
    {
        foreach (['ServerPort', 'GamePort', 'hostport', 'port', 'Port'] as $key) {
            $port = $this->stringRequestValue($request, $key);
            if (is_string($port) && preg_match('/^\d{1,5}$/', $port) === 1) {
                return $port;
            }
        }

        return null;
    }

    private function normalizeServerAddress(?string $address, ?string $port = null): ?string
    {
        if (!is_string($address) || $address === '') {
            return null;
        }

        $address = trim($address);
        if (preg_match('/^([0-9]{1,3}(?:\.[0-9]{1,3}){3})(?::(\d{1,5}))?$/', $address, $matches) !== 1) {
            return null;
        }

        $ip = $matches[1];
        $detectedPort = $matches[2] ?? $port;
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        if (is_string($detectedPort) && preg_match('/^\d{1,5}$/', $detectedPort) === 1) {
            $portNumber = (int) $detectedPort;
            if ($portNumber > 0 && $portNumber <= 65535) {
                return $ip . ':' . $portNumber;
            }
        }

        return $ip;
    }
}
