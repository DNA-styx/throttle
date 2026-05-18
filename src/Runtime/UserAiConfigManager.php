<?php

namespace App\Runtime;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final class UserAiConfigManager
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AiSettingsCrypto $crypto,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listConfigsForUser(int $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, owner_id, enabled, display_name, provider, model, api_key_encrypted, base_url, temperature, max_tokens, default_prompt, extra_options_json, is_default, updated_at
             FROM user_ai_config
             WHERE owner_id = ?
             ORDER BY is_default DESC, updated_at DESC, id DESC',
            [$userId],
        );

        return array_map(fn (array $row): array => $this->normalizeSummaryRow($row), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEnabledLegacySummariesForUser(int $userId): array
    {
        return array_values(array_map(
            static fn (array $row): array => [
                'id' => $row['id'],
                'display_name' => $row['display_name'],
                'provider' => $row['provider'],
                'provider_label' => $row['provider_label'],
                'model' => $row['model'],
                'default_prompt' => $row['default_prompt'],
                'is_default' => $row['is_default'],
            ],
            array_filter($this->listConfigsForUser($userId), static fn (array $row): bool => (bool) $row['enabled'])
        ));
    }

    /**
     * @return array{id: int, display_name: string, provider: string, provider_label: string, model: string, base_url: ?string, temperature: ?float, max_tokens: ?int, default_prompt: string, extra_options_json: string, enabled: bool, is_default: bool, api_key: string, extra_options: array<string, mixed>}
     */
    public function requireConfigForUser(int $userId, int $configId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, owner_id, enabled, display_name, provider, model, api_key_encrypted, base_url, temperature, max_tokens, default_prompt, extra_options_json, is_default
             FROM user_ai_config
             WHERE id = ? AND owner_id = ?',
            [$configId, $userId],
        );
        if ($row === false) {
            throw new \RuntimeException('AI configuration was not found.');
        }

        $summary = $this->normalizeSummaryRow($row);
        $encrypted = (string) ($row['api_key_encrypted'] ?? '');
        if ($encrypted === '') {
            throw new \RuntimeException('AI configuration is missing an API key.');
        }

        $summary['api_key'] = $this->crypto->decrypt($encrypted);
        $summary['extra_options'] = $this->decodeExtraOptions(($row['extra_options_json'] ?? null) !== null ? (string) $row['extra_options_json'] : null);

        return $summary;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function saveConfig(User $user, array $input): array
    {
        $id = isset($input['id']) && ctype_digit((string) $input['id']) ? (int) $input['id'] : null;
        $provider = trim((string) ($input['provider'] ?? ''));
        if (!CrashAiProviderCatalog::isSupported($provider)) {
            throw new \RuntimeException('Unsupported AI provider.');
        }

        $displayName = trim((string) ($input['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = CrashAiProviderCatalog::label($provider);
        }

        $model = trim((string) ($input['model'] ?? ''));
        if ($model === '') {
            throw new \RuntimeException('Model is required.');
        }

        $baseUrl = trim((string) ($input['base_url'] ?? ''));
        if ($provider === CrashAiProviderCatalog::PROVIDER_OPENROUTER && $baseUrl === '') {
            $baseUrl = (string) CrashAiProviderCatalog::defaultBaseUrl($provider);
        }
        if ($provider === CrashAiProviderCatalog::PROVIDER_OPENAI_COMPATIBLE && $baseUrl === '') {
            throw new \RuntimeException('Base URL is required for custom OpenAI-compatible providers.');
        }

        $temperature = $this->normalizeTemperature($input['temperature'] ?? null);
        $maxTokens = $this->normalizeMaxTokens($input['max_tokens'] ?? null);
        $defaultPrompt = trim((string) ($input['default_prompt'] ?? ''));
        if ($defaultPrompt === '') {
            $defaultPrompt = CrashAiProviderCatalog::DEFAULT_PROMPT;
        }
        $extraOptionsJson = $this->normalizeExtraOptionsJson($input['extra_options_json'] ?? null);

        $enabled = !empty($input['enabled']);
        $isDefault = !empty($input['is_default']);
        $apiKey = trim((string) ($input['api_key'] ?? ''));

        $existing = null;
        if ($id !== null) {
            $existing = $this->connection->fetchAssociative(
                'SELECT id, owner_id, api_key_encrypted FROM user_ai_config WHERE id = ? AND owner_id = ?',
                [$id, $user->getId()],
            );
            if ($existing === false) {
                throw new \RuntimeException('AI configuration was not found.');
            }
        }

        $encryptedKey = null;
        if ($apiKey !== '') {
            $encryptedKey = $this->crypto->encrypt($apiKey);
        } elseif ($existing !== null) {
            $encryptedKey = (string) ($existing['api_key_encrypted'] ?? '');
        }

        if (!is_string($encryptedKey) || $encryptedKey === '') {
            throw new \RuntimeException('API key is required.');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($isDefault) {
            $this->connection->executeStatement('UPDATE user_ai_config SET is_default = 0 WHERE owner_id = ?', [$user->getId()]);
        }

        if ($id === null) {
            $this->connection->insert('user_ai_config', [
                'owner_id' => $user->getId(),
                'enabled' => $enabled ? 1 : 0,
                'display_name' => mb_substr($displayName, 0, 100),
                'provider' => $provider,
                'model' => mb_substr($model, 0, 255),
                'api_key_encrypted' => $encryptedKey,
                'base_url' => $baseUrl !== '' ? mb_substr($baseUrl, 0, 1024) : null,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'default_prompt' => $defaultPrompt,
                'extra_options_json' => $extraOptionsJson,
                'is_default' => $isDefault ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->connection->lastInsertId();
        } else {
            $this->connection->update('user_ai_config', [
                'enabled' => $enabled ? 1 : 0,
                'display_name' => mb_substr($displayName, 0, 100),
                'provider' => $provider,
                'model' => mb_substr($model, 0, 255),
                'api_key_encrypted' => $encryptedKey,
                'base_url' => $baseUrl !== '' ? mb_substr($baseUrl, 0, 1024) : null,
                'temperature' => $temperature,
                'max_tokens' => $maxTokens,
                'default_prompt' => $defaultPrompt,
                'extra_options_json' => $extraOptionsJson,
                'is_default' => $isDefault ? 1 : 0,
                'updated_at' => $now,
            ], ['id' => $id, 'owner_id' => $user->getId()]);
        }

        if (!$this->hasDefaultConfig($user->getId())) {
            $this->connection->executeStatement(
                'UPDATE user_ai_config SET is_default = 1 WHERE id = ? AND owner_id = ?',
                [$id, $user->getId()],
            );
        }

        return $this->requireConfigSummaryForUser($user->getId(), $id);
    }

    public function deleteConfig(User $user, int $configId): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, owner_id, is_default FROM user_ai_config WHERE id = ? AND owner_id = ?',
            [$configId, $user->getId()],
        );
        if ($row === false) {
            throw new \RuntimeException('AI configuration was not found.');
        }

        $this->connection->delete('user_ai_config', ['id' => $configId, 'owner_id' => $user->getId()]);

        if ((int) ($row['is_default'] ?? 0) === 1) {
            $nextId = $this->connection->fetchOne(
                'SELECT id FROM user_ai_config WHERE owner_id = ? ORDER BY id ASC LIMIT 1',
                [$user->getId()],
            );
            if ($nextId !== false) {
                $this->connection->executeStatement('UPDATE user_ai_config SET is_default = 1 WHERE id = ?', [$nextId]);
            }
        }
    }

    /**
     * @return array{id: int, display_name: string, provider: string, provider_label: string, model: string, base_url: ?string, temperature: ?float, max_tokens: ?int, default_prompt: string, enabled: bool, is_default: bool}
     */
    public function requireConfigSummaryForUser(int $userId, int $configId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, owner_id, enabled, display_name, provider, model, api_key_encrypted, base_url, temperature, max_tokens, default_prompt, extra_options_json, is_default
             FROM user_ai_config
             WHERE id = ? AND owner_id = ?',
            [$configId, $userId],
        );
        if ($row === false) {
            throw new \RuntimeException('AI configuration was not found.');
        }

        return $this->normalizeSummaryRow($row);
    }

    /**
     * @return array<string, array{label: string, default_base_url: string|null, help: string}>
     */
    public function providerTemplates(): array
    {
        return CrashAiProviderCatalog::definitions();
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, display_name: string, provider: string, provider_label: string, model: string, base_url: ?string, temperature: ?float, max_tokens: ?int, default_prompt: string, extra_options_json: string, enabled: bool, is_default: bool, api_key_configured: bool, api_key_mask: ?string}
     */
    private function normalizeSummaryRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'provider' => (string) ($row['provider'] ?? ''),
            'provider_label' => CrashAiProviderCatalog::label((string) ($row['provider'] ?? '')),
            'model' => (string) ($row['model'] ?? ''),
            'base_url' => ($row['base_url'] ?? null) !== null ? (string) $row['base_url'] : null,
            'temperature' => ($row['temperature'] ?? null) !== null ? (float) $row['temperature'] : null,
            'max_tokens' => ($row['max_tokens'] ?? null) !== null ? (int) $row['max_tokens'] : null,
            'default_prompt' => (string) ($row['default_prompt'] ?? CrashAiProviderCatalog::DEFAULT_PROMPT),
            'extra_options_json' => ($row['extra_options_json'] ?? null) !== null ? (string) $row['extra_options_json'] : '',
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'is_default' => (int) ($row['is_default'] ?? 0) === 1,
            'api_key_configured' => ((string) ($row['api_key_encrypted'] ?? '')) !== '',
            'api_key_mask' => $this->buildApiKeyMask(($row['api_key_encrypted'] ?? null) !== null ? (string) $row['api_key_encrypted'] : null),
        ];
    }

    private function buildApiKeyMask(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        try {
            $apiKey = $this->crypto->decrypt($encrypted);
        } catch (\Throwable) {
            return 'Saved';
        }

        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return 'Saved';
        }

        $suffixLength = min(6, strlen($apiKey));

        return '••••' . substr($apiKey, -$suffixLength);
    }

    private function hasDefaultConfig(int $userId): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_ai_config WHERE owner_id = ? AND is_default = 1',
            [$userId],
        ) > 0;
    }

    private function normalizeTemperature(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_scalar($value) || !is_numeric((string) $value)) {
            throw new \RuntimeException('Temperature must be a number.');
        }

        $temperature = (float) $value;
        if ($temperature < 0 || $temperature > 2) {
            throw new \RuntimeException('Temperature must be between 0 and 2.');
        }

        return round($temperature, 2);
    }

    private function normalizeMaxTokens(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_scalar($value) || !ctype_digit((string) $value)) {
            throw new \RuntimeException('Max tokens must be a positive integer.');
        }

        $maxTokens = (int) $value;
        if ($maxTokens < 1 || $maxTokens > 131072) {
            throw new \RuntimeException('Max tokens must be between 1 and 131072.');
        }

        return $maxTokens;
    }

    private function normalizeExtraOptionsJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $json = trim((string) $value);
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Extra request JSON must be a valid JSON object.');
        }

        return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeExtraOptions(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
