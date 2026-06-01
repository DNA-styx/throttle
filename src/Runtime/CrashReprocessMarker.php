<?php

namespace App\Runtime;

final class CrashReprocessMarker
{
    /**
     * @param object $connection Doctrine DBAL connection or legacy wrapper with executeUpdate/fetchOne
     *
     * @return array{updated_modules: int, stale_crashes: int}
     */
    public static function markModuleSymbolsPresentAndScheduleReprocess(object $connection, string $module, string $identifier): array
    {
        $updatedModules = self::executeUpdate(
            $connection,
            'UPDATE module SET present = 1 WHERE name = ? AND identifier = ? AND present = 0',
            [$module, $identifier]
        );

        $staleCrashes = (int) $connection->fetchOne(
            'SELECT COUNT(DISTINCT crash) FROM module WHERE name = ? AND identifier = ? AND present = 1 AND processed = 0',
            [$module, $identifier]
        );

        return [
            'updated_modules' => $updatedModules,
            'stale_crashes' => $staleCrashes,
        ];
    }

    /**
     * @param object $connection Doctrine DBAL connection or legacy wrapper with fetchOne
     */
    public static function countCrashesAwaitingReprocess(object $connection): int
    {
        return (int) $connection->fetchOne('SELECT COUNT(DISTINCT crash) FROM module WHERE present = 1 AND processed = 0');
    }

    /**
     * @param object $connection Doctrine DBAL connection or legacy wrapper
     * @param array<int, mixed> $params
     */
    private static function executeUpdate(object $connection, string $sql, array $params): int
    {
        if (method_exists($connection, 'executeUpdate')) {
            return (int) $connection->executeUpdate($sql, $params);
        }

        return (int) $connection->executeStatement($sql, $params);
    }
}
