<?php

namespace App\Legacy;

use Doctrine\DBAL\Connection;

class LegacyDbalConnection
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array<int, mixed> $params
     * @param array<int, mixed> $types
     */
    public function executeQuery(string $sql, array $params = [], array $types = []): LegacyDbalResult
    {
        return new LegacyDbalResult($this->connection->executeQuery($sql, $params, $types));
    }

    /**
     * @param array<int, mixed> $params
     * @param array<int, mixed> $types
     */
    public function executeUpdate(string $sql, array $params = [], array $types = []): int
    {
        return $this->connection->executeStatement($sql, $params, $types);
    }

    public function transactional(callable $callback): mixed
    {
        return $this->connection->transactional(fn () => $callback($this));
    }
}
