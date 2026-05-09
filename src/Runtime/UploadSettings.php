<?php

namespace App\Runtime;

final class UploadSettings
{
    public const PATH = '/var/upload-settings.json';

    /**
     * @return array{streaming_symbols_enabled: bool, upload_memory_limit: string, allow_anonymous_minidump_uploads: bool}
     */
    public static function defaults(): array
    {
        return [
            'streaming_symbols_enabled' => true,
            'upload_memory_limit' => '256M',
            'allow_anonymous_minidump_uploads' => true,
        ];
    }

    /**
     * @return array{streaming_symbols_enabled: bool, upload_memory_limit: string, allow_anonymous_minidump_uploads: bool}
     */
    public static function load(string $root): array
    {
        $path = self::path($root);
        if (!is_file($path)) {
            return self::defaults();
        }

        $contents = file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            return self::defaults();
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return self::defaults();
        }

        return self::normalize($decoded);
    }

    public static function save(string $root, array $settings): void
    {
        $path = self::path($root);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode(self::normalize($settings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    }

    public static function delete(string $root): void
    {
        $path = self::path($root);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public static function path(string $root): string
    {
        return $root . self::PATH;
    }

    /**
     * @return array{streaming_symbols_enabled: bool, upload_memory_limit: string, allow_anonymous_minidump_uploads: bool}
     */
    public static function normalize(array $settings): array
    {
        $defaults = self::defaults();
        $memoryLimit = isset($settings['upload_memory_limit']) && is_scalar($settings['upload_memory_limit'])
            ? strtoupper(trim((string) $settings['upload_memory_limit']))
            : $defaults['upload_memory_limit'];

        if (!self::isValidMemoryLimit($memoryLimit)) {
            $memoryLimit = $defaults['upload_memory_limit'];
        }

        return [
            'streaming_symbols_enabled' => array_key_exists('streaming_symbols_enabled', $settings)
                ? filter_var($settings['streaming_symbols_enabled'], FILTER_VALIDATE_BOOL)
                : $defaults['streaming_symbols_enabled'],
            'upload_memory_limit' => $memoryLimit,
            'allow_anonymous_minidump_uploads' => array_key_exists('allow_anonymous_minidump_uploads', $settings)
                ? filter_var($settings['allow_anonymous_minidump_uploads'], FILTER_VALIDATE_BOOL)
                : $defaults['allow_anonymous_minidump_uploads'],
        ];
    }

    public static function isValidMemoryLimit(string $value): bool
    {
        return preg_match('/^(?:-1|[1-9][0-9]*(?:K|M|G)?)$/i', trim($value)) === 1;
    }

    public static function applyMemoryLimit(string $root): void
    {
        $settings = self::load($root);
        if ($settings['upload_memory_limit'] !== '') {
            @ini_set('memory_limit', $settings['upload_memory_limit']);
        }
    }
}
