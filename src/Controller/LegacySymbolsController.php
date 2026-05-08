<?php

namespace App\Controller;

use App\Legacy\LegacyBridgeFactory;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class LegacySymbolsController extends AbstractController
{
    use LegacyResponseTrait;

    private LegacyBridgeFactory $legacyBridgeFactory;
    private AuthorizationCheckerInterface $authorizationChecker;
    private UserRepository $userRepository;
    private Connection $connection;
    private string $symbolUploadToken;

    public function __construct(LegacyBridgeFactory $legacyBridgeFactory, AuthorizationCheckerInterface $authorizationChecker, UserRepository $userRepository, Connection $connection, string $symbolUploadToken)
    {
        $this->legacyBridgeFactory = $legacyBridgeFactory;
        $this->authorizationChecker = $authorizationChecker;
        $this->userRepository = $userRepository;
        $this->connection = $connection;
        $this->symbolUploadToken = $symbolUploadToken;
    }

    #[Route('/symbols/submit', name: 'symbols_submit', methods: ['POST'])]
    public function submit(Request $request): Response
    {
        $tokenUser = $this->findTokenUser($request);
        if (!$this->canUploadSymbols($request, $tokenUser)) {
            $this->recordAudit($request, null, 'symbols', 'rejected', Response::HTTP_FORBIDDEN, 'invalid token', null, null, 0);

            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        $response = $this->legacyResponse((new \Throttle\Symbols())->submit($this->legacyBridgeFactory->createHttp($request)));
        $this->recordTokenUsage($request, $response, $tokenUser, 'symbols');

        return $response;
    }

    #[Route('/binary/submit', name: 'binary_submit', methods: ['POST'])]
    public function binary(Request $request): Response
    {
        $tokenUser = $this->findTokenUser($request);
        if (!$this->canUploadSymbols($request, $tokenUser)) {
            $this->recordAudit($request, null, 'binary', 'rejected', Response::HTTP_FORBIDDEN, 'invalid token', null, null, 0);

            return new Response('Forbidden', Response::HTTP_FORBIDDEN);
        }

        $response = $this->legacyResponse((new \Throttle\Binary())->submit($this->legacyBridgeFactory->createHttp($request)));
        $this->recordTokenUsage($request, $response, $tokenUser, 'binary');

        return $response;
    }

    private function canUploadSymbols(Request $request, ?\App\Entity\User $tokenUser): bool
    {
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            return true;
        }

        if ($tokenUser !== null) {
            return true;
        }

        $provided = $this->getProvidedToken($request);

        return is_string($provided) && $this->symbolUploadToken !== '' && hash_equals($this->symbolUploadToken, $provided);
    }

    private function findTokenUser(Request $request): ?\App\Entity\User
    {
        $provided = $this->getProvidedToken($request);
        if (!is_string($provided) || $provided === '') {
            return null;
        }

        return $this->userRepository->findOneBy(['uploadToken' => $provided]);
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

    private function recordTokenUsage(Request $request, Response $response, ?\App\Entity\User $tokenUser, string $endpoint): void
    {
        $module = null;
        $identifier = null;
        $bytes = 0;

        if ($endpoint === 'symbols') {
            $data = $request->request->get('symbol_file');
            if ($data === null) {
                $data = $request->getContent();
            }
            if (is_string($data)) {
                $bytes = strlen($data);
                $firstLine = strtok($data, "\r\n");
                if (is_string($firstLine) && preg_match('/^MODULE [^ ]+ [^ ]+ (?P<id>[a-fA-F0-9]+) (?P<name>[^\/\\\\\r\n]+)$/', $firstLine, $matches) === 1) {
                    $module = $matches['name'];
                    $identifier = $matches['id'];
                }
            }
        } else {
            $file = $request->files->get('code_file');
            if ($file instanceof UploadedFile) {
                $bytes = (int) $file->getSize();
                $module = basename(str_replace('\\', '/', (string) (
                    $request->request->get('debug_file_path')
                    ?: $request->request->get('code_file_path')
                    ?: $file->getClientOriginalName()
                )));
            }
            $rawIdentifier = $request->request->get('debug_identifier') ?: $request->request->get('code_identifier');
            $identifier = is_string($rawIdentifier) ? $rawIdentifier : null;
        }

        $result = $response->getStatusCode() >= 400 ? 'rejected' : 'accepted';
        $reason = $response->getStatusCode() >= 400 ? trim(strip_tags((string) $response->getContent())) : null;
        $this->recordAudit($request, $tokenUser?->getId(), $endpoint, $result, $response->getStatusCode(), $reason, $module, $identifier, $bytes);

        if ($tokenUser === null) {
            return;
        }

        $this->connection->insert('upload_token_usage', [
            'owner_id' => $tokenUser->getId(),
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'endpoint' => $endpoint,
            'remote_addr' => $request->getClientIp(),
            'account' => $this->stringRequestValue($request, 'UserID') ?? $this->stringRequestValue($request, 'MinidumpAccount'),
            'module' => $module,
            'identifier' => $identifier,
            'bytes' => $bytes,
            'status_code' => $response->getStatusCode(),
            'user_agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 255),
        ]);
    }

    private function recordAudit(Request $request, ?int $ownerId, string $endpoint, string $result, int $statusCode, ?string $reason, ?string $module, ?string $identifier, int $bytes): void
    {
        $provided = $this->getProvidedToken($request);

        $this->connection->insert('upload_token_audit', [
            'owner_id' => $ownerId,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'endpoint' => $endpoint,
            'remote_addr' => $request->getClientIp(),
            'account' => $this->stringRequestValue($request, 'UserID') ?? $this->stringRequestValue($request, 'MinidumpAccount'),
            'module' => $module,
            'identifier' => $identifier,
            'bytes' => $bytes,
            'status_code' => $statusCode,
            'result' => $result,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'token_suffix' => is_string($provided) && $provided !== '' ? substr($provided, -8) : null,
            'user_agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 255),
        ]);
    }

    private function stringRequestValue(Request $request, string $key): ?string
    {
        $value = $request->request->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
