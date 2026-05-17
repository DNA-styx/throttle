<?php

namespace App\Tests\Throttle;

use PHPUnit\Framework\TestCase;
use Throttle\Crash;

class CrashLikelyCauseTest extends TestCase
{
    public function testHelperChainPrefersSourceModBridgeOverEngine(): void
    {
        $candidates = $this->buildCandidates([
            ['frame' => 0, 'module' => 'sourcemod.logic.so', 'function' => 'LoadFromAddress', 'rendered' => 'sourcemod.logic.so!LoadFromAddress(SourcePawn::IPluginContext*, int const*) + 0xd6'],
            ['frame' => 4, 'module' => 'sourcepawn.vm.so', 'function' => 'sp::Environment::Invoke', 'rendered' => 'sourcepawn.vm.so!sp::Environment::Invoke(sp::PluginContext*, ke::RefPtr<sp::MethodInfo> const&, int*) + 0xf7'],
            ['frame' => 7, 'module' => 'sourcemod.logic.so', 'function' => 'FakeNativeRouter', 'rendered' => 'sourcemod.logic.so!FakeNativeRouter(SourcePawn::IPluginContext*, int const*, void*) + 0x165'],
            ['frame' => 8, 'module' => 'sourcepawn.vm.so', 'function' => 'sp::Interpreter::invokeNative', 'rendered' => 'sourcepawn.vm.so!sp::Interpreter::invokeNative(unsigned int) + 0x85'],
            ['frame' => 18, 'module' => 'sourcepawn.vm.so', 'function' => 'sp::ScriptedInvoker::Execute', 'rendered' => 'sourcepawn.vm.so!sp::ScriptedInvoker::Execute(int*) + 0x6d'],
            ['frame' => 19, 'module' => 'sourcemod.logic.so', 'function' => 'CPluginManager::AllPluginsLoaded', 'rendered' => 'sourcemod.logic.so!CPluginManager::AllPluginsLoaded() + 0xbd'],
            ['frame' => 23, 'module' => 'engine_srv.so', 'function' => 'CServerPlugin::LevelInit', 'rendered' => 'engine_srv.so!CServerPlugin::LevelInit(char const*, char const*, char const*, char const*, bool, bool) + 0xa8'],
            ['frame' => 24, 'module' => 'engine_srv.so', 'function' => 'Host_NewGame', 'rendered' => 'engine_srv.so!Host_NewGame(char*, bool, bool, char const*, char const*, bool) + 0x530'],
        ]);

        self::assertSame('sourcemod.logic.so', $candidates[0]['label']);
        self::assertSame('Bridge', $candidates[0]['kind']);
        self::assertContains('Top frame is SourceMod native memory helper', $candidates[0]['reasons']);

        $engineCandidate = $this->findCandidate($candidates, 'engine_srv.so');
        self::assertNotNull($engineCandidate);
        self::assertContains($engineCandidate['kind'], ['Native failure site', 'Native module']);
    }

    public function testExplicitSmxFrameStillWins(): void
    {
        $candidates = $this->buildCandidates([
            ['frame' => 0, 'module' => 'sourcepawn.vm.so', 'function' => '', 'rendered' => '[my_plugin.smx::OnPluginStart] line 42'],
            ['frame' => 1, 'module' => 'sourcemod.logic.so', 'function' => 'FakeNativeRouter', 'rendered' => 'sourcemod.logic.so!FakeNativeRouter(SourcePawn::IPluginContext*, int const*, void*) + 0x165'],
            ['frame' => 2, 'module' => 'engine_srv.so', 'function' => 'Host_NewGame', 'rendered' => 'engine_srv.so!Host_NewGame(char*, bool, bool, char const*, char const*, bool) + 0x530'],
        ]);

        self::assertSame('my_plugin.smx', $candidates[0]['label']);
        self::assertSame('Likely plugin cause', $candidates[0]['kind']);
    }

    public function testRealEngineCrashStillPicksEngine(): void
    {
        $candidates = $this->buildCandidates([
            ['frame' => 0, 'module' => 'engine_srv.so', 'function' => 'Host_NewGame', 'rendered' => 'engine_srv.so!Host_NewGame(char*, bool, bool, char const*, char const*, bool) + 0x530'],
            ['frame' => 1, 'module' => 'engine_srv.so', 'function' => 'CHostState::State_NewGame', 'rendered' => 'engine_srv.so!CHostState::State_NewGame() + 0x54'],
            ['frame' => 2, 'module' => 'engine_srv.so', 'function' => 'CHostState::FrameUpdate', 'rendered' => 'engine_srv.so!CHostState::FrameUpdate(float) + 0x17c'],
        ]);

        self::assertSame('engine_srv.so', $candidates[0]['label']);
        self::assertSame('Native failure site', $candidates[0]['kind']);
    }

    public function testStartupHelperChainStillPrefersBridgeWithoutPluginFrame(): void
    {
        $candidates = $this->buildCandidates([
            ['frame' => 0, 'module' => 'sourcemod.logic.so', 'function' => 'StoreToAddress', 'rendered' => 'sourcemod.logic.so!StoreToAddress(SourcePawn::IPluginContext*, int const*) + 0xaa'],
            ['frame' => 1, 'module' => 'sourcepawn.vm.so', 'function' => 'sp::PluginContext::Invoke', 'rendered' => 'sourcepawn.vm.so!sp::PluginContext::Invoke(unsigned int, int const*, unsigned int, int*) + 0x2cb'],
            ['frame' => 2, 'module' => 'sourcepawn.vm.so', 'function' => 'sp::ScriptedInvoker::Execute', 'rendered' => 'sourcepawn.vm.so!sp::ScriptedInvoker::Execute(int*) + 0x6d'],
            ['frame' => 3, 'module' => 'sourcemod.logic.so', 'function' => 'CPluginManager::AllPluginsLoaded', 'rendered' => 'sourcemod.logic.so!CPluginManager::AllPluginsLoaded() + 0xbd'],
            ['frame' => 4, 'module' => 'sourcemod.2.tf2.so', 'function' => 'SourceModBase::DoGlobalPluginLoads', 'rendered' => 'sourcemod.2.tf2.so!SourceModBase::DoGlobalPluginLoads() + 0xe8'],
            ['frame' => 5, 'module' => 'engine_srv.so', 'function' => 'CServerPlugin::LevelInit', 'rendered' => 'engine_srv.so!CServerPlugin::LevelInit(char const*, char const*, char const*, char const*, bool, bool) + 0xa8'],
        ]);

        self::assertSame('sourcemod.logic.so', $candidates[0]['label']);
        self::assertSame('Bridge', $candidates[0]['kind']);
    }

    /**
     * @param array<int, array<string, mixed>> $stack
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildCandidates(array $stack): array
    {
        $method = new \ReflectionMethod(Crash::class, 'buildCulpritCandidates');
        $method->setAccessible(true);

        /** @var array<int, array<string, mixed>> $candidates */
        $candidates = $method->invoke(null, $stack, [], [], null, null, null, null);

        return $candidates;
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     *
     * @return array<string, mixed>|null
     */
    private function findCandidate(array $candidates, string $label): ?array
    {
        foreach ($candidates as $candidate) {
            if (($candidate['label'] ?? null) === $label) {
                return $candidate;
            }
        }

        return null;
    }
}
