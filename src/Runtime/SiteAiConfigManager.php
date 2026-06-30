<?php

namespace App\Runtime;

final class SiteAiConfigManager
{
    public function __construct(
        private readonly string $projectDir,
        private readonly AiSettingsCrypto $crypto,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function loadSummary(): array
    {
        return $this->normalizeSummary((array) (UploadSettings::load($this->projectDir)['global_ai_analysis'] ?? []));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadExecutionConfig(): ?array
    {
        $config = $this->loadSummary();
        $encrypted = (string) ($config['api_key_encrypted'] ?? '');
        if (!$config['enabled'] || $config['provider'] === '' || $config['model'] === '' || $encrypted === '') {
            return null;
        }

        $config['api_key'] = $this->crypto->decrypt($encrypted);
        $config['extra_options'] = $this->decodeExtraOptions(($config['extra_options_json'] ?? null) !== null ? (string) $config['extra_options_json'] : null);

        return $config;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        $settings = UploadSettings::load($this->projectDir);
        $existing = (array) ($settings['global_ai_analysis'] ?? []);
        $provider = trim((string) ($input['provider'] ?? ''));
        if (!CrashAiProviderCatalog::isSupported($provider)) {
            throw new \RuntimeException('Unsupported AI provider.');
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

        $apiKey = trim((string) ($input['api_key'] ?? ''));
        $encryptedKey = $apiKey !== ''
            ? $this->crypto->encrypt($apiKey)
            : (string) ($existing['api_key_encrypted'] ?? '');
        if (!empty($input['enabled']) && $encryptedKey === '') {
            throw new \RuntimeException('API key is required.');
        }

        $prompt = trim((string) ($input['prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = CrashAiProviderCatalog::DEFAULT_PROMPT;
        }

        $settings['global_ai_analysis'] = [
            'enabled' => !empty($input['enabled']),
            'provider' => $provider,
            'model' => mb_substr($model, 0, 255),
            'api_key_encrypted' => $encryptedKey,
            'base_url' => $baseUrl !== '' ? mb_substr($baseUrl, 0, 1024) : null,
            'temperature' => $this->normalizeTemperature($input['temperature'] ?? null),
            'max_tokens' => $this->normalizeMaxTokens($input['max_tokens'] ?? null),
            'prompt' => $prompt,
            'extra_options_json' => $this->normalizeExtraOptionsJson($input['extra_options_json'] ?? null),
        ];
        UploadSettings::save($this->projectDir, $settings);

        return $this->loadSummary();
    }

    /**
     * @return array<string, array{label: string, default_base_url: string|null, help: string}>
     */
    public function providerTemplates(): array
    {
        return CrashAiProviderCatalog::definitions();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeSummary(array $config): array
    {
        $provider = trim((string) ($config['provider'] ?? ''));
        $model = trim((string) ($config['model'] ?? ''));
        $baseUrl = ($config['base_url'] ?? null) !== null ? trim((string) $config['base_url']) : null;
        $prompt = trim((string) ($config['prompt'] ?? ''));

        return [
            'enabled' => !empty($config['enabled']),
            'provider' => CrashAiProviderCatalog::isSupported($provider) ? $provider : '',
            'provider_label' => CrashAiProviderCatalog::label($provider),
            'model' => $model,
            'api_key_encrypted' => (string) ($config['api_key_encrypted'] ?? ''),
            'api_key_configured' => ((string) ($config['api_key_encrypted'] ?? '')) !== '',
            'api_key_mask' => $this->buildApiKeyMask(($config['api_key_encrypted'] ?? null) !== null ? (string) $config['api_key_encrypted'] : null),
            'base_url' => $baseUrl !== '' ? $baseUrl : null,
            'temperature' => ($config['temperature'] ?? null) !== null ? (float) $config['temperature'] : null,
            'max_tokens' => ($config['max_tokens'] ?? null) !== null ? (int) $config['max_tokens'] : null,
            'prompt' => $prompt !== '' ? $prompt : CrashAiProviderCatalog::DEFAULT_PROMPT,
            'extra_options_json' => ($config['extra_options_json'] ?? null) !== null ? (string) $config['extra_options_json'] : '',
        ];
    }

    private function buildApiKeyMask(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        try {
            $apiKey = trim($this->crypto->decrypt($encrypted));
        } catch (\Throwable) {
            return 'Saved';
        }

        if ($apiKey === '') {
            return 'Saved';
        }

        return '••••' . substr($apiKey, -min(6, strlen($apiKey)));
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
