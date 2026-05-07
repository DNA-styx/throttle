<?php

namespace App\Legacy;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Silex\Application;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Environment;

class LegacyBridgeFactory
{
    private Connection $connection;
    private Environment $twig;
    private UrlGeneratorInterface $urlGenerator;
    private LoggerInterface $logger;
    private Security $security;
    private CrashOwnerResolver $crashOwnerResolver;
    private string $projectDir;

    /** @var array<string, mixed> */
    private array $legacyConfig;

    private string $redisUrl;

    /**
     * @param array<string, mixed> $legacyConfig
     */
    public function __construct(
        Connection $connection,
        Environment $twig,
        UrlGeneratorInterface $urlGenerator,
        LoggerInterface $logger,
        Security $security,
        CrashOwnerResolver $crashOwnerResolver,
        string $projectDir,
        array $legacyConfig,
        string $redisUrl
    ) {
        $this->connection = $connection;
        $this->twig = $twig;
        $this->urlGenerator = $urlGenerator;
        $this->logger = $logger;
        $this->security = $security;
        $this->crashOwnerResolver = $crashOwnerResolver;
        $this->projectDir = $projectDir;
        $this->legacyConfig = $legacyConfig;
        $this->redisUrl = $redisUrl;
    }

    public function createHttp(Request $request): Application
    {
        $app = new Application();
        $user = $this->getUser();
        $legacyRequest = LegacyRequest::fromBaseRequest($request);

        $app['db'] = new LegacyDbalConnection($this->connection);
        $app['request'] = $legacyRequest;
        $app['session'] = $legacyRequest->getSession();
        $app['url_generator'] = $this->urlGenerator;
        $app['monolog'] = $this->logger;
        $app['root'] = $this->projectDir;
        $app['config'] = $this->legacyConfig;
        $app['feature'] = ['subscriptions' => false];
        $app['user'] = $this->buildLegacyUser($user);
        $app['redis'] = new LegacyRedis($this->connectRedis(), $this->redisUrl);
        $app['owner_resolver'] = $this->crashOwnerResolver;
        $app['twig'] = new LegacyTwigRenderer($this->twig, $this->buildTemplateAppContext($legacyRequest, $user));

        return $app;
    }

    public function createConsole(): Application
    {
        $app = new Application();
        $app['db'] = new LegacyDbalConnection($this->connection);
        $app['monolog'] = $this->logger;
        $app['root'] = $this->projectDir;
        $app['config'] = $this->legacyConfig;
        $app['feature'] = ['subscriptions' => false];
        $app['user'] = null;
        $app['redis'] = new LegacyRedis($this->connectRedis(), $this->redisUrl);
        $app['owner_resolver'] = $this->crashOwnerResolver;

        return $app;
    }

    private function getUser(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildLegacyUser(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->getId(),
            'name' => $user->getName(),
            'avatar' => $this->crashOwnerResolver->getAvatarForUser($user),
            'pending' => 0,
            'admin' => $this->crashOwnerResolver->isAdmin($user),
            'owner_ids' => $this->crashOwnerResolver->getAllowedOwnerIds($user),
            'owners' => $this->crashOwnerResolver->getAllowedOwners($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTemplateAppContext(Request $request, ?User $user): array
    {
        return [
            'request' => $request,
            'session' => $request->getSession(),
            'user' => $this->buildLegacyUser($user),
            'config' => $this->legacyConfig,
            'feature' => ['subscriptions' => false],
            'version' => null,
        ];
    }

    private function connectRedis(): ?\Redis
    {
        if ($this->redisUrl === '' || !class_exists(\Redis::class)) {
            return null;
        }

        $parts = parse_url($this->redisUrl);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }

        $redis = new \Redis();
        $port = isset($parts['port']) ? (int) $parts['port'] : 6379;

        try {
            $redis->connect($parts['host'], $port, 1.0);
        } catch (\RedisException) {
            return null;
        }

        return $redis;
    }
}
