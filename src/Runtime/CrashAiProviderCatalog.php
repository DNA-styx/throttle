<?php

namespace App\Runtime;

final class CrashAiProviderCatalog
{
    public const PROVIDER_OPENAI = 'openai';
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_GEMINI = 'gemini';
    public const PROVIDER_OPENROUTER = 'openrouter';
    public const PROVIDER_OPENAI_COMPATIBLE = 'openai_compatible';

    public const DEFAULT_PROMPT = <<<PROMPT
You are analyzing a Source engine / SourceMod crash report.

Your task:
1. Identify the most likely root cause.
2. Distinguish root cause from failure site if they differ.
3. Explain why using only the provided evidence.
4. Mention uncertainty when the evidence is weak.
5. Give short next debugging steps.

Output format:
- Likely root cause
- Failure site
- Why
- Confidence
- Next checks
PROMPT;

    /**
     * @return array<string, array{label: string, default_base_url: string|null, help: string}>
     */
    public static function definitions(): array
    {
        return [
            self::PROVIDER_OPENAI => [
                'label' => 'OpenAI',
                'default_base_url' => null,
                'help' => 'Uses the OpenAI Responses API.',
            ],
            self::PROVIDER_ANTHROPIC => [
                'label' => 'Anthropic',
                'default_base_url' => null,
                'help' => 'Uses the Anthropic Messages API.',
            ],
            self::PROVIDER_GEMINI => [
                'label' => 'Google Gemini',
                'default_base_url' => null,
                'help' => 'Uses the Gemini generateContent API.',
            ],
            self::PROVIDER_OPENROUTER => [
                'label' => 'OpenRouter',
                'default_base_url' => 'https://openrouter.ai/api/v1',
                'help' => 'Uses an OpenAI-compatible API via OpenRouter.',
            ],
            self::PROVIDER_OPENAI_COMPATIBLE => [
                'label' => 'Custom OpenAI-compatible',
                'default_base_url' => '',
                'help' => 'For self-hosted or third-party APIs compatible with the OpenAI chat/completions shape.',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function providers(): array
    {
        return array_keys(self::definitions());
    }

    public static function isSupported(string $provider): bool
    {
        return isset(self::definitions()[$provider]);
    }

    public static function label(string $provider): string
    {
        return self::definitions()[$provider]['label'] ?? $provider;
    }

    public static function defaultBaseUrl(string $provider): ?string
    {
        return self::definitions()[$provider]['default_base_url'] ?? null;
    }
}
