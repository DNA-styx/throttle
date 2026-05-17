<?php

namespace App\Tests\Runtime;

use App\Runtime\CrashSourceLookupManager;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CrashSourceLookupManagerTest extends TestCase
{
    public function testSourceModLogicAddsDirectCandidateForSmnCore(): void
    {
        $manager = $this->createManager();
        $symbol = [
            'module' => 'sourcemod.logic.so',
            'signature' => 'LoadFromAddress',
            'method' => 'LoadFromAddress',
            'class' => null,
            'namespaces' => [],
            'offset' => '0xd6',
        ];

        $paths = $this->invokePrivate($manager, 'buildCandidateRelativePaths', [$symbol, 'sourcemod.logic.so']);

        self::assertContains('core/logic/smn_core.cpp', $paths);
    }

    public function testSourceModLogicTreeScoringPrioritizesSmnCore(): void
    {
        $manager = $this->createManager();
        $symbol = [
            'module' => 'sourcemod.logic.so',
            'signature' => 'LoadFromAddress',
            'method' => 'LoadFromAddress',
            'class' => null,
            'namespaces' => [],
            'offset' => '0xd6',
        ];
        $treePaths = [
            'core/logic/smn_entities.cpp',
            'core/logic/smn_core.cpp',
            'plugins/include/sourcemod.inc',
            'public/IShareSys.h',
        ];

        $paths = $this->invokePrivate($manager, 'buildTreeAssistedCandidatePaths', [$treePaths, $symbol, 'sourcemod.logic.so']);

        self::assertSame('core/logic/smn_core.cpp', $paths[0] ?? null);
    }

    public function testExactFreeFunctionDefinitionCanBeVerified(): void
    {
        $manager = $this->createManager();
        $symbol = [
            'module' => 'sourcemod.logic.so',
            'signature' => 'LoadFromAddress',
            'method' => 'LoadFromAddress',
            'class' => null,
            'namespaces' => [],
            'offset' => '0xd6',
        ];
        $contents = <<<'CPP'
static cell_t LoadFromAddress(IPluginContext *pContext, const cell_t *params)
{
    return 0;
}
CPP;

        $match = $this->invokePrivate($manager, 'scoreFileContents', ['core/logic/smn_core.cpp', $contents, $symbol, 'sourcemod.logic.so']);

        self::assertIsArray($match);
        self::assertSame('verified', $match['quality'] ?? null);
        self::assertSame(1, $match['line_start'] ?? null);
    }

    public function testVerifiedCacheMatchesExactFrameSignature(): void
    {
        $manager = $this->createManager();
        $cache = [
            'match_quality' => 'verified',
            'snippet' => <<<'TXT'
> 868: static cell_t LoadFromAddress(IPluginContext *pContext, const cell_t *params)
  869: {
TXT,
        ];

        $matches = $this->invokePrivate($manager, 'cachedResultMatchesFrame', [
            $cache,
            'sourcemod.logic.so',
            'LoadFromAddress',
            'sourcemod.logic.so!LoadFromAddress(SourcePawn::IPluginContext*, int const*) + 0xd6',
        ]);

        self::assertTrue($matches);
    }

    public function testVerifiedCacheDoesNotMatchDifferentFrameSignature(): void
    {
        $manager = $this->createManager();
        $cache = [
            'match_quality' => 'verified',
            'snippet' => <<<'TXT'
> 868: static cell_t LoadFromAddress(IPluginContext *pContext, const cell_t *params)
  869: {
TXT,
        ];

        $matches = $this->invokePrivate($manager, 'cachedResultMatchesFrame', [
            $cache,
            'sourcemod.logic.so',
            'FakeNativeRouter',
            'sourcemod.logic.so!FakeNativeRouter(SourcePawn::IPluginContext*, int const*, void*) + 0x165',
        ]);

        self::assertFalse($matches);
    }

    private function createManager(): CrashSourceLookupManager
    {
        /** @var Connection $connection */
        $connection = $this->createMock(Connection::class);
        /** @var HttpClientInterface $httpClient */
        $httpClient = $this->createMock(HttpClientInterface::class);

        return new CrashSourceLookupManager($connection, $httpClient, 'O:\\GitHub\\throttle');
    }

    /**
     * @param list<mixed> $args
     */
    private function invokePrivate(object $object, string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }
}
