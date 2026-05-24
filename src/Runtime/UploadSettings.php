<?php

namespace App\Runtime;

final class UploadSettings
{
    public const PATH = '/var/upload-settings.json';

    /**
     * @return array{streaming_symbols_enabled: bool, upload_memory_limit: string, allow_anonymous_minidump_uploads: bool, upload_failure_backoff_enabled: bool, upload_failure_backoff_threshold: int, upload_failure_backoff_ttl: int, crash_source_lookup_enabled: bool, crash_ai_analysis_enabled: bool, auth_enable_steam: bool, auth_enable_discord: bool, auth_enable_email_login_link: bool, auth_enable_password_login: bool, auth_enable_password_registration: bool, auth_enable_password_reset: bool, auth_enable_token_login: bool}
     */
    public static function defaults(): array
    {
        return [
            'streaming_symbols_enabled' => true,
            'upload_memory_limit' => '256M',
            'allow_anonymous_minidump_uploads' => true,
            'upload_failure_backoff_enabled' => true,
            'upload_failure_backoff_threshold' => 3,
            'upload_failure_backoff_ttl' => 3600,
            'crash_source_lookup_enabled' => false,
            'crash_ai_analysis_enabled' => false,
            'auth_enable_steam' => true,
            'auth_enable_discord' => true,
            'auth_enable_email_login_link' => true,
            'auth_enable_password_login' => true,
            'auth_enable_password_registration' => true,
            'auth_enable_password_reset' => true,
            'auth_enable_token_login' => true,
        ];
    }

    /**
     * @return array{streaming_symbols_enabled: bool, upload_memory_limit: string, allow_anonymous_minidump_uploads: bool, upload_failure_backoff_enabled: bool, upload_failure_backoff_threshold: int, upload_failure_backoff_ttl: int, crash_source_lookup_enabled: bool, crash_ai_analysis_enabled: bool, auth_enable_steam: bool, auth_enable_discord: bool, auth_enable_email_login_link: bool, auth_enable_password_login: bool, auth_enable_password_registration: bool, auth_enable_password_reset: bool, auth_enable_token_login: bool}
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
     * @return array{streaming_symbols_enabled: bool, upload_memory_limit: string, allow_anonymous_minidump_uploads: bool, upload_failure_backoff_enabled: bool, upload_failure_backoff_threshold: int, upload_failure_backoff_ttl: int, crash_source_lookup_enabled: bool, crash_ai_analysis_enabled: bool, auth_enable_steam: bool, auth_enable_discord: bool, auth_enable_email_login_link: bool, auth_enable_password_login: bool, auth_enable_password_registration: bool, auth_enable_password_reset: bool, auth_enable_token_login: bool}
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

        $backoffThreshold = isset($settings['upload_failure_backoff_threshold']) && is_scalar($settings['upload_failure_backoff_threshold'])
            ? (int) $settings['upload_failure_backoff_threshold']
            : $defaults['upload_failure_backoff_threshold'];
        if ($backoffThreshold < 1) {
            $backoffThreshold = $defaults['upload_failure_backoff_threshold'];
        }

        $backoffTtl = isset($settings['upload_failure_backoff_ttl']) && is_scalar($settings['upload_failure_backoff_ttl'])
            ? (int) $settings['upload_failure_backoff_ttl']
            : $defaults['upload_failure_backoff_ttl'];
        if ($backoffTtl < 60) {
            $backoffTtl = $defaults['upload_failure_backoff_ttl'];
        }

        return [
            'streaming_symbols_enabled' => array_key_exists('streaming_symbols_enabled', $settings)
                ? filter_var($settings['streaming_symbols_enabled'], FILTER_VALIDATE_BOOL)
                : $defaults['streaming_symbols_enabled'],
            'upload_memory_limit' => $memoryLimit,
            'allow_anonymous_minidump_uploads' => array_key_exists('allow_anonymous_minidump_uploads', $settings)
                ? filter_var($settings['allow_anonymous_minidump_uploads'], FILTER_VALIDATE_BOOL)
                : $defaults['allow_anonymous_minidump_uploads'],
            'upload_failure_backoff_enabled' => array_key_exists('upload_failure_backoff_enabled', $settings)
                ? filter_var($settings['upload_failure_backoff_enabled'], FILTER_VALIDATE_BOOL)
                : $defaults['upload_failure_backoff_enabled'],
            'upload_failure_backoff_threshold' => $backoffThreshold,
            'upload_failure_backoff_ttl' => $backoffTtl,
            'crash_source_lookup_enabled' => array_key_exists('crash_source_lookup_enabled', $settings)
                ? filter_var($settings['crash_source_lookup_enabled'], FILTER_VALIDATE_BOOL)
                : $defaults['crash_source_lookup_enabled'],
            'crash_ai_analysis_enabled' => array_key_exists('crash_ai_analysis_enabled', $settings)
                ? filter_var($settings['crash_ai_analysis_enabled'], FILTER_VALIDATE_BOOL)
                : $defaults['crash_ai_analysis_enabled'],
            'auth_enable_steam' => array_key_exists('auth_enable_steam', $settings)
                ? filter_var($settings['auth_enable_steam'], FILTER_VALIDATE_BOOL)
                : $defaults['auth_enable_steam'],
            'auth_enable_discord' => array_key_exists('auth_enable_discord', $settings)
                ? filter_var($settings['auth_enable_discord'], FILTER_VALIDATE_BOOL)
                : $defaults['auth_enable_discord'],
            'auth_enable_email_login_link' => array_key_exists('auth_enable_email_login_link', $settings)
                ? filter_var($settings['auth_enable_email_login_link'], FILTER_VALIDATE_BOOL)
                : $defaults['auth_enable_email_login_link'],
            'auth_enable_password_login' => array_key_exists('auth_enable_password_login', $settings)
                ? filter_var($settings['auth_enable_password_login'], FILTER_VALIDATE_BOOL)
                : $defaults['auth_enable_password_login'],
            'auth_enable_password_registration' => array_key_exists('auth_enable_password_registration', $settings)
                ? filter_var($settings['auth_enable_password_registration'], FILTER_VALIDATE_BOOL)
                : $defaults['auth_enable_password_registration'],
            'auth_enable_password_reset' => array_key_exists('auth_enable_password_reset', $settings)
                ? filter_var($settings['auth_enable_password_reset'], FILTER_VALIDATE_BOOL)
                : $defaults['auth_enable_password_reset'],
            'auth_enable_token_login' => array_key_exists('auth_enable_token_login', $settings)
                ? filter_var($settings['auth_enable_token_login'], FILTER_VALIDATE_BOOL)
                : $defaults['auth_enable_token_login'],
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
