<?php

namespace App\Tests\Throttle;

use PHPUnit\Framework\TestCase;
use Throttle\Crash;

class CrashDetailsTest extends TestCase
{
    public function testLoadFromAddressNoticeAppearsForExactTopFrame(): void
    {
        $note = $this->invokePrivateStatic('buildLoadFromAddressNotice', [[
            [
                'frame' => 0,
                'module' => 'sourcemod.logic.so',
                'function' => 'LoadFromAddress',
                'rendered' => 'sourcemod.logic.so!LoadFromAddress(SourcePawn::IPluginContext*, int const*) + 0xd6',
            ],
        ]]);

        self::assertIsArray($note);
        self::assertSame('warning', $note['severity'] ?? null);
        self::assertStringContainsString('invalid or stale memory address', $note['text'] ?? '');
        self::assertStringContainsString('outdated gamedata or offsets', $note['text'] ?? '');
    }

    public function testLoadFromAddressNoticeDoesNotAppearWhenFrameIsNotTop(): void
    {
        $note = $this->invokePrivateStatic('buildLoadFromAddressNotice', [[
            [
                'frame' => 0,
                'module' => 'engine_srv.so',
                'function' => 'Host_NewGame',
                'rendered' => 'engine_srv.so!Host_NewGame(char*, bool, bool, char const*, char const*, bool) + 0x530',
            ],
            [
                'frame' => 1,
                'module' => 'sourcemod.logic.so',
                'function' => 'LoadFromAddress',
                'rendered' => 'sourcemod.logic.so!LoadFromAddress(SourcePawn::IPluginContext*, int const*) + 0xd6',
            ],
        ]]);

        self::assertNull($note);
    }

    public function testLoadFromAddressNoticeDoesNotAppearForDifferentSignature(): void
    {
        $note = $this->invokePrivateStatic('buildLoadFromAddressNotice', [[
            [
                'frame' => 0,
                'module' => 'sourcemod.logic.so',
                'function' => 'FakeNativeRouter',
                'rendered' => 'sourcemod.logic.so!FakeNativeRouter(SourcePawn::IPluginContext*, int const*, void*) + 0x165',
            ],
        ]]);

        self::assertNull($note);
    }

    /**
     * @param list<mixed> $args
     */
    private function invokePrivateStatic(string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod(Crash::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $args);
    }
}
