<?php

namespace App\Runtime;

final class UploadFailureBackoff
{
    public const PATH = '/var/upload-failure-backoff.json';

    public static function path(string $root): string
    {
        return $root . self::PATH;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function load(string $root): array
    {
        $path = self::path($root);
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        $entries = [];
        foreach ($decoded as $key => $entry) {
            if (!is_string($key) || !is_array($entry)) {
                continue;
            }

            $module = isset($entry['module']) && is_string($entry['module']) ? $entry['module'] : null;
            $identifier = isset($entry['identifier']) && is_string($entry['identifier']) ? $entry['identifier'] : null;
            if ($module === null || $identifier === null) {
                continue;
            }

            $entries[$key] = [
                'module' => $module,
                'identifier' => $identifier,
                'count' => max(0, (int) ($entry['count'] ?? 0)),
                'last_failure_at' => (int) ($entry['last_failure_at'] ?? 0),
                'blocked_until' => max(0, (int) ($entry['blocked_until'] ?? 0)),
                'last_status_code' => isset($entry['last_status_code']) ? (int) $entry['last_status_code'] : null,
                'last_reason' => isset($entry['last_reason']) && is_string($entry['last_reason']) ? $entry['last_reason'] : null,
                'last_endpoint' => isset($entry['last_endpoint']) && is_string($entry['last_endpoint']) ? $entry['last_endpoint'] : null,
            ];
        }

        return self::purgeExpiredEntries($root, $entries, false);
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     */
    public static function save(string $root, array $entries): void
    {
        $path = self::path($root);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    }

    public static function clear(string $root): int
    {
        $entries = self::load($root);
        $count = count($entries);
        $path = self::path($root);
        if (is_file($path)) {
            unlink($path);
        }

        return $count;
    }

    public static function registerFailure(string $root, string $module, string $identifier, string $endpoint, int $statusCode, ?string $reason): void
    {
        $settings = UploadSettings::load($root);
        if (($settings['upload_failure_backoff_enabled'] ?? true) !== true) {
            return;
        }

        $threshold = max(1, (int) ($settings['upload_failure_backoff_threshold'] ?? 3));
        $ttl = max(60, (int) ($settings['upload_failure_backoff_ttl'] ?? 3600));
        $entries = self::load($root);
        $key = self::key($module, $identifier);
        $now = time();

        $entry = $entries[$key] ?? [
            'module' => $module,
            'identifier' => $identifier,
            'count' => 0,
            'last_failure_at' => 0,
            'blocked_until' => 0,
            'last_status_code' => null,
            'last_reason' => null,
            'last_endpoint' => null,
        ];

        if ((int) $entry['blocked_until'] < $now && ((int) $entry['last_failure_at'] + $ttl) < $now) {
            $entry['count'] = 0;
        }

        $entry['count'] = (int) $entry['count'] + 1;
        $entry['last_failure_at'] = $now;
        $entry['last_status_code'] = $statusCode;
        $entry['last_reason'] = $reason;
        $entry['last_endpoint'] = $endpoint;
        if ((int) $entry['count'] >= $threshold) {
            $entry['blocked_until'] = $now + $ttl;
        }

        $entries[$key] = $entry;

        self::save($root, $entries);
    }

    public static function registerSuccess(string $root, string $module, string $identifier): void
    {
        $entries = self::load($root);
        $key = self::key($module, $identifier);
        if (!isset($entries[$key])) {
            return;
        }

        unset($entries[$key]);
        if ($entries === []) {
            self::clear($root);

            return;
        }

        self::save($root, $entries);
    }

    public static function isSuppressed(string $root, string $module, string $identifier): bool
    {
        $settings = UploadSettings::load($root);
        if (($settings['upload_failure_backoff_enabled'] ?? true) !== true) {
            return false;
        }

        $entries = self::load($root);
        $key = self::key($module, $identifier);
        if (!isset($entries[$key])) {
            return false;
        }

        return (int) ($entries[$key]['blocked_until'] ?? 0) > time();
    }

    /**
     * @return array{entries: array<int, array<string, mixed>>, total: int, suppressed: int}
     */
    public static function stats(string $root): array
    {
        $entries = array_values(self::load($root));
        usort($entries, static function (array $left, array $right): int {
            return ((int) ($right['blocked_until'] ?? 0) <=> (int) ($left['blocked_until'] ?? 0))
                ?: ((int) ($right['last_failure_at'] ?? 0) <=> (int) ($left['last_failure_at'] ?? 0));
        });

        $suppressed = 0;
        $now = time();
        foreach ($entries as $entry) {
            if ((int) ($entry['blocked_until'] ?? 0) > $now) {
                $suppressed++;
            }
        }

        return [
            'entries' => $entries,
            'total' => count($entries),
            'suppressed' => $suppressed,
        ];
    }

    private static function key(string $module, string $identifier): string
    {
        return strtolower($module) . '|' . strtoupper($identifier);
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     *
     * @return array<string, array<string, mixed>>
     */
    private static function purgeExpiredEntries(string $root, array $entries, bool $persist = true): array
    {
        $settings = UploadSettings::load($root);
        $ttl = max(60, (int) ($settings['upload_failure_backoff_ttl'] ?? 3600));
        $now = time();
        $changed = false;

        foreach ($entries as $key => $entry) {
            $blockedUntil = (int) ($entry['blocked_until'] ?? 0);
            $lastFailureAt = (int) ($entry['last_failure_at'] ?? 0);
            if ($blockedUntil > 0 && $blockedUntil > $now) {
                continue;
            }

            if ($lastFailureAt > 0 && ($lastFailureAt + $ttl) >= $now) {
                continue;
            }

            unset($entries[$key]);
            $changed = true;
        }

        if ($persist && $changed) {
            if ($entries === []) {
                self::clear($root);
            } else {
                self::save($root, $entries);
            }
        }

        return $entries;
    }
}
