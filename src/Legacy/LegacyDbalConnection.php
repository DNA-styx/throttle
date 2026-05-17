<?php

namespace App\Legacy;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

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
        return new LegacyDbalResult($this->connection->executeQuery($sql, $params, $this->normalizeTypes($types)));
    }

    /**
     * @param array<int, mixed> $params
     * @param array<int, mixed> $types
     */
    public function executeUpdate(string $sql, array $params = [], array $types = []): int
    {
        return $this->connection->executeStatement($sql, $params, $this->normalizeTypes($types));
    }

    public function transactional(callable $callback): mixed
    {
        return $this->connection->transactional(fn () => $callback($this));
    }

    public function getWrappedConnection(): Connection
    {
        return $this->connection;
    }

    /**
     * @param array<int, mixed> $types
     *
     * @return array<int, mixed>
     */
    private function normalizeTypes(array $types): array
    {
        foreach ($types as $key => $type) {
            if ($type === \PDO::PARAM_INT) {
                $types[$key] = ParameterType::INTEGER;
            } elseif ($type === \PDO::PARAM_BOOL) {
                $types[$key] = ParameterType::BOOLEAN;
            } elseif ($type === \PDO::PARAM_NULL) {
                $types[$key] = ParameterType::NULL;
            } elseif ($type === \PDO::PARAM_STR) {
                $types[$key] = ParameterType::STRING;
            } elseif (is_int($type) && $type === 101) {
                $types[$key] = ArrayParameterType::INTEGER;
            } elseif (is_int($type) && $type === 102) {
                $types[$key] = ArrayParameterType::STRING;
            }
        }

        return $types;
    }
}
