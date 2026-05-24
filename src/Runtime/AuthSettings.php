<?php

namespace App\Runtime;

final class AuthSettings
{
    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        $settings = UploadSettings::load($this->projectDir);

        return [
            'steam' => (bool) ($settings['auth_enable_steam'] ?? true),
            'discord' => (bool) ($settings['auth_enable_discord'] ?? true),
            'email_login_link' => (bool) ($settings['auth_enable_email_login_link'] ?? true),
            'password_login' => (bool) ($settings['auth_enable_password_login'] ?? true),
            'password_registration' => (bool) ($settings['auth_enable_password_registration'] ?? true),
            'password_reset' => (bool) ($settings['auth_enable_password_reset'] ?? true),
            'token_login' => (bool) ($settings['auth_enable_token_login'] ?? true),
        ];
    }

    public function isEnabled(string $method): bool
    {
        return $this->all()[$method] ?? false;
    }

    public function anyEnabled(): bool
    {
        return in_array(true, $this->all(), true);
    }
}
