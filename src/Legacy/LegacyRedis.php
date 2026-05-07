<?php

namespace App\Legacy;

class LegacyRedis
{
    private ?\Redis $redis;
    private ?string $host;
    private int $port;
    private ?string $password;
    private int $database;

    public function __construct(?\Redis $redis, ?string $redisUrl = null)
    {
        $this->redis = $redis;
        $this->host = null;
        $this->port = 6379;
        $this->password = null;
        $this->database = 0;

        if ($redisUrl === null || $redis !== null) {
            return;
        }

        $parts = parse_url($redisUrl);
        if ($parts === false || !isset($parts['host'])) {
            return;
        }

        $this->host = $parts['host'];
        $this->port = isset($parts['port']) ? (int) $parts['port'] : 6379;
        $this->password = isset($parts['pass']) ? urldecode($parts['pass']) : null;
        $this->database = isset($parts['path']) ? max(0, (int) ltrim($parts['path'], '/')) : 0;
    }

    public function hIncrBy(string $key, string $field, int $value): int
    {
        if ($this->redis !== null) {
            return $this->redis->hIncrBy($key, $field, $value);
        }

        return (int) ($this->execute(['HINCRBY', $key, $field, (string) $value]) ?? 0);
    }

    public function hGet(string $key, string $field): string|false
    {
        if ($this->redis !== null) {
            return $this->redis->hGet($key, $field);
        }

        $value = $this->execute(['HGET', $key, $field]);

        return is_string($value) ? $value : false;
    }

    /**
     * @param array<int, string> $keys
     *
     * @return array<int, mixed>
     */
    public function mGet(array $keys): array
    {
        if ($this->redis !== null) {
            return $this->redis->mGet($keys);
        }

        $result = $this->execute(array_merge(['MGET'], $keys));
        if (!is_array($result)) {
            return array_fill(0, count($keys), false);
        }

        return array_map(static fn ($value) => is_string($value) ? $value : false, $result);
    }

    public function rPush(string $key, string $value): int
    {
        if ($this->redis !== null) {
            return $this->redis->rPush($key, $value);
        }

        return (int) ($this->execute(['RPUSH', $key, $value]) ?? 0);
    }

    public function ttl(string $key): int
    {
        if ($this->redis !== null) {
            return $this->redis->ttl($key);
        }

        return (int) ($this->execute(['TTL', $key]) ?? -1);
    }

    public function setEx(string $key, int $ttl, string $value): bool
    {
        if ($this->redis !== null) {
            return $this->redis->setEx($key, $ttl, $value);
        }

        return $this->execute(['SETEX', $key, (string) $ttl, $value]) === 'OK';
    }

    public function get(string $key): string|false
    {
        if ($this->redis !== null) {
            return $this->redis->get($key);
        }

        $value = $this->execute(['GET', $key]);

        return is_string($value) ? $value : false;
    }

    public function set(string $key, string $value): bool
    {
        if ($this->redis !== null) {
            return $this->redis->set($key, $value);
        }

        return $this->execute(['SET', $key, $value]) === 'OK';
    }

    public function del(string ...$keys): int
    {
        if ($this->redis !== null) {
            return $this->redis->del(...$keys);
        }

        return (int) ($this->execute(array_merge(['DEL'], $keys)) ?? 0);
    }

    private function execute(array $arguments): mixed
    {
        if ($this->host === null) {
            return null;
        }

        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errorCode,
            $errorMessage,
            1.0
        );

        if (!is_resource($socket)) {
            return null;
        }

        stream_set_timeout($socket, 1);

        try {
            if ($this->password !== null && $this->password !== '') {
                $this->writeCommand($socket, ['AUTH', $this->password]);
                $auth = $this->readReply($socket);
                if ($auth !== 'OK') {
                    return null;
                }
            }

            if ($this->database > 0) {
                $this->writeCommand($socket, ['SELECT', (string) $this->database]);
                $select = $this->readReply($socket);
                if ($select !== 'OK') {
                    return null;
                }
            }

            $this->writeCommand($socket, $arguments);

            return $this->readReply($socket);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     * @param array<int, string> $arguments
     */
    private function writeCommand($socket, array $arguments): void
    {
        $payload = '*' . count($arguments) . "\r\n";
        foreach ($arguments as $argument) {
            $payload .= '$' . strlen($argument) . "\r\n" . $argument . "\r\n";
        }

        fwrite($socket, $payload);
    }

    /**
     * @param resource $socket
     */
    private function readReply($socket): mixed
    {
        $line = fgets($socket);
        if ($line === false || $line === '') {
            return null;
        }

        $prefix = $line[0];
        $payload = rtrim(substr($line, 1), "\r\n");

        return match ($prefix) {
            '+' => $payload,
            '-' => null,
            ':' => (int) $payload,
            '$' => $this->readBulkReply($socket, (int) $payload),
            '*' => $this->readArrayReply($socket, (int) $payload),
            default => null,
        };
    }

    /**
     * @param resource $socket
     */
    private function readBulkReply($socket, int $length): ?string
    {
        if ($length < 0) {
            return null;
        }

        $data = '';
        $remaining = $length + 2;

        while ($remaining > 0 && !feof($socket)) {
            $chunk = fread($socket, $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        if (strlen($data) < $length + 2) {
            return null;
        }

        return substr($data, 0, $length);
    }

    /**
     * @param resource $socket
     * @return array<int, mixed>|null
     */
    private function readArrayReply($socket, int $length): ?array
    {
        if ($length < 0) {
            return null;
        }

        $items = [];
        for ($i = 0; $i < $length; $i++) {
            $items[] = $this->readReply($socket);
        }

        return $items;
    }
}
