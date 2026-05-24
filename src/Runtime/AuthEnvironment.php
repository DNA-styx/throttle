<?php

namespace App\Runtime;

final class AuthEnvironment
{
    public function __construct(
        private readonly string $mailerDsn,
        private readonly string $mailerFrom,
        private readonly string $steamApiKey,
        private readonly string $discordClientId,
        private readonly string $discordClientSecret,
    ) {
    }

    public function isMailerConfigured(): bool
    {
        $dsn = trim($this->mailerDsn);
        $from = trim($this->mailerFrom);

        return $dsn !== ''
            && !str_starts_with(strtolower($dsn), 'null://')
            && $from !== '';
    }

    public function mailerNotice(): ?string
    {
        if ($this->isMailerConfigured()) {
            return null;
        }

        return 'Email delivery is not configured on this server yet. Set MAILER_DSN and MAILER_FROM first.';
    }

    public function getMailerFrom(): string
    {
        return trim($this->mailerFrom);
    }

    /**
     * @return array{scheme:string,host:string,port:string,encryption:string,auth_mode:string,username:string,configured:bool}
     */
    public function mailerSummary(): array
    {
        $dsn = trim($this->mailerDsn);
        if ($dsn === '' || str_starts_with(strtolower($dsn), 'null://')) {
            return [
                'scheme' => '',
                'host' => '',
                'port' => '',
                'encryption' => '',
                'auth_mode' => '',
                'username' => '',
                'configured' => false,
            ];
        }

        $parts = parse_url($dsn) ?: [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        return [
            'scheme' => (string) ($parts['scheme'] ?? ''),
            'host' => (string) ($parts['host'] ?? ''),
            'port' => isset($parts['port']) ? (string) $parts['port'] : '',
            'encryption' => (string) ($query['encryption'] ?? ''),
            'auth_mode' => (string) ($query['auth_mode'] ?? ''),
            'username' => isset($parts['user']) ? rawurldecode((string) $parts['user']) : '',
            'configured' => true,
        ];
    }

    public function isDiscordConfigured(): bool
    {
        return trim($this->discordClientId) !== ''
            && trim($this->discordClientSecret) !== '';
    }

    public function isSteamApiConfigured(): bool
    {
        return trim($this->steamApiKey) !== '';
    }

    public function steamNotice(): string
    {
        if ($this->isSteamApiConfigured()) {
            return 'Steam login is enabled. STEAM_API_KEY is configured for profile lookups and related integrations.';
        }

        return 'Steam OpenID sign-in can still work without STEAM_API_KEY, but Steam profile lookups and related integrations will stay limited until it is configured.';
    }

    public function discordNotice(): ?string
    {
        if ($this->isDiscordConfigured()) {
            return null;
        }

        return 'Discord login is not configured yet. Set OAUTH_DISCORD_CLIENT_ID and OAUTH_DISCORD_CLIENT_SECRET, then add the exact callback URL in the Discord Developer Portal.';
    }

    public function getDiscordClientIdMasked(): string
    {
        $clientId = trim($this->discordClientId);
        if ($clientId === '') {
            return '';
        }

        if (strlen($clientId) <= 8) {
            return str_repeat('*', strlen($clientId));
        }

        return substr($clientId, 0, 4) . str_repeat('*', max(0, strlen($clientId) - 8)) . substr($clientId, -4);
    }

    public function getSteamApiKeyMasked(): string
    {
        $apiKey = trim($this->steamApiKey);
        if ($apiKey === '') {
            return '';
        }

        if (strlen($apiKey) <= 8) {
            return str_repeat('*', strlen($apiKey));
        }

        return substr($apiKey, 0, 4) . str_repeat('*', max(0, strlen($apiKey) - 8)) . substr($apiKey, -4);
    }
}
