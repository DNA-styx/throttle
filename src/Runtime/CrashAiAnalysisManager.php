<?php

namespace App\Runtime;

use App\Entity\User;
use Throttle\Crash;
use Doctrine\DBAL\Connection;
use Silex\Application;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CrashAiAnalysisManager
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly UserAiConfigManager $userAiConfigManager,
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{status: string, provider: string, provider_label: string, model: string, duration_ms: int, response_text: string, usage: array<string, mixed>|null, usage_summary: array<string, int>|null, request_sections: array<string, bool>, history_id: int}
     */
    public function analyze(User $user, Application $app, string $crashId, array $input): array
    {
        $configId = isset($input['ai_config_id']) && ctype_digit((string) $input['ai_config_id']) ? (int) $input['ai_config_id'] : 0;
        if ($configId < 1) {
            throw new \RuntimeException('Select an AI configuration.');
        }

        $config = $this->userAiConfigManager->requireConfigForUser($user->getId(), $configId);
        if (!$config['enabled']) {
            throw new \RuntimeException('The selected AI configuration is disabled.');
        }

        $context = (string) ($input['context'] ?? 'details');
        if (!in_array($context, ['details', 'raw'], true)) {
            $context = 'details';
        }

        $prompt = trim((string) ($input['prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = $config['default_prompt'];
        }

        $sections = [
            'stack_trace' => !empty($input['include_stack_trace']),
            'likely_cause' => !empty($input['include_likely_cause']),
            'modules' => !empty($input['include_modules']),
            'console' => !empty($input['include_console']),
            'header_metadata' => !empty($input['include_header_metadata']),
            'raw' => !empty($input['include_raw']),
        ];

        $packet = (new \Throttle\Crash())->buildAiAnalysisPacket($app, $crashId, $context, $sections);
        $finalPrompt = rtrim($prompt) . "\n\n---\n\nCrash data:\n" . $packet['text'];

        $startedAt = microtime(true);
        try {
            $result = match ($config['provider']) {
                CrashAiProviderCatalog::PROVIDER_OPENAI => $this->requestOpenAi($config, $finalPrompt),
                CrashAiProviderCatalog::PROVIDER_ANTHROPIC => $this->requestAnthropic($config, $finalPrompt),
                CrashAiProviderCatalog::PROVIDER_GEMINI => $this->requestGemini($config, $finalPrompt),
                CrashAiProviderCatalog::PROVIDER_OPENROUTER,
                CrashAiProviderCatalog::PROVIDER_OPENAI_COMPATIBLE => $this->requestOpenAiCompatible($config, $finalPrompt),
                default => throw new \RuntimeException('Unsupported AI provider.'),
            };
        } catch (\Throwable $e) {
            $this->recordAudit($user->getId(), $crashId, $config, 'failed', (int) round((microtime(true) - $startedAt) * 1000), strlen($finalPrompt), $e->getMessage(), $context);
            throw $e;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $this->recordAudit($user->getId(), $crashId, $config, 'ok', $durationMs, strlen($finalPrompt), null, $context);
        $historyId = $this->recordHistory($user->getId(), $crashId, $config, $context, $prompt, $sections, $result['text'], $result['usage']);

        return [
            'status' => 'ok',
            'provider' => $config['provider'],
            'provider_label' => $config['provider_label'],
            'model' => $config['model'],
            'duration_ms' => $durationMs,
            'response_text' => $result['text'],
            'usage' => $result['usage'],
            'usage_summary' => self::summarizeUsage($result['usage']),
            'request_sections' => $sections,
            'history_id' => $historyId,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listHistoryForCrash(string $crashId, ?User $viewer, bool $canManage): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT history.id,
                    history.user_id,
                    history.provider,
                    history.model,
                    history.context,
                    history.prompt,
                    history.request_sections_json,
                    history.response_text,
                    history.usage_json,
                    history.is_public,
                    history.created_at,
                    owner.name AS user_name
             FROM crash_ai_analysis_history history
             LEFT JOIN server_owner owner ON owner.id = history.user_id
             WHERE history.crash = ?' . ($canManage ? '' : ' AND history.is_public = 1') . '
             ORDER BY history.created_at DESC, history.id DESC',
            [$crashId],
        );

        return array_map(function (array $row) use ($viewer, $canManage): array {
            $sections = json_decode((string) ($row['request_sections_json'] ?? ''), true);
            $usage = json_decode((string) ($row['usage_json'] ?? ''), true);
            $ownerId = (int) ($row['user_id'] ?? 0);
            $canToggle = $viewer instanceof User && ($viewer->getId() === $ownerId || in_array(User::ROLE_ADMIN, $viewer->getRoles(), true));

            return [
                'id' => (int) ($row['id'] ?? 0),
                'user_id' => $ownerId,
                'user_name' => (string) ($row['user_name'] ?? ''),
                'provider' => (string) ($row['provider'] ?? ''),
                'provider_label' => CrashAiProviderCatalog::label((string) ($row['provider'] ?? '')),
                'model' => (string) ($row['model'] ?? ''),
                'context' => (string) ($row['context'] ?? 'details'),
                'prompt' => (string) ($row['prompt'] ?? ''),
                'request_sections' => is_array($sections) ? $sections : [],
                'response_text' => (string) ($row['response_text'] ?? ''),
                'response_html' => Crash::renderAiMarkdownHtml((string) ($row['response_text'] ?? '')),
                'usage' => is_array($usage) ? $usage : null,
                'usage_summary' => self::summarizeUsage(is_array($usage) ? $usage : null),
                'is_public' => (int) ($row['is_public'] ?? 0) === 1,
                'created_at' => (string) ($row['created_at'] ?? ''),
                'can_toggle_visibility' => $canToggle,
                'visible_to_viewer' => $canManage || (int) ($row['is_public'] ?? 0) === 1,
            ];
        }, $rows);
    }

    public function countPublicHistoryForCrash(string $crashId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM crash_ai_analysis_history WHERE crash = ? AND is_public = 1',
            [$crashId],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function updateHistoryVisibility(User $user, string $crashId, int $historyId, bool $isPublic): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, user_id FROM crash_ai_analysis_history WHERE id = ? AND crash = ?',
            [$historyId, $crashId],
        );
        if ($row === false) {
            throw new \RuntimeException('AI history entry was not found.');
        }

        $ownerId = (int) ($row['user_id'] ?? 0);
        if ($ownerId !== $user->getId() && !in_array(User::ROLE_ADMIN, $user->getRoles(), true)) {
            throw new \RuntimeException('You cannot change visibility for this AI history entry.');
        }

        $this->connection->update('crash_ai_analysis_history', [
            'is_public' => $isPublic ? 1 : 0,
        ], ['id' => $historyId]);

        $items = $this->listHistoryForCrash($crashId, $user, true);
        foreach ($items as $item) {
            if ((int) ($item['id'] ?? 0) === $historyId) {
                return $item;
            }
        }

        throw new \RuntimeException('AI history entry was not found after update.');
    }

    /**
     * @param array<string, mixed> $config
     * @return array{text: string, usage: array<string, mixed>|null}
     */
    private function requestOpenAi(array $config, string $prompt): array
    {
        $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', [
            'headers' => [
                'Authorization' => 'Bearer ' . $config['api_key'],
                'Content-Type' => 'application/json',
            ],
            'json' => $this->mergeRequestOptions(array_filter([
                'model' => $config['model'],
                'input' => $prompt,
                'temperature' => $config['temperature'],
                'max_output_tokens' => $config['max_tokens'],
            ], static fn ($value): bool => $value !== null), $config),
            'timeout' => 120,
        ]);

        $payload = $this->decodeJsonPayload($response->getContent(false));
        $text = trim($this->normalizeModelText((string) ($payload['output_text'] ?? '')));
        if ($text === '' && isset($payload['output']) && is_array($payload['output'])) {
            $parts = [];
            foreach ($payload['output'] as $item) {
                if (!is_array($item) || !isset($item['content']) || !is_array($item['content'])) {
                    continue;
                }
                foreach ($item['content'] as $content) {
                    if (is_array($content) && isset($content['text']) && is_string($content['text'])) {
                        $parts[] = $this->normalizeModelText($content['text']);
                    }
                }
            }
            $text = trim(implode("\n\n", $parts));
        }

        if ($text === '') {
            throw new \RuntimeException('OpenAI returned an empty analysis.');
        }

        return ['text' => $text, 'usage' => isset($payload['usage']) && is_array($payload['usage']) ? $payload['usage'] : null];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{text: string, usage: array<string, mixed>|null}
     */
    private function requestAnthropic(array $config, string $prompt): array
    {
        $response = $this->httpClient->request('POST', 'https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key' => $config['api_key'],
                'anthropic-version' => '2023-06-01',
                'Content-Type' => 'application/json',
            ],
            'json' => $this->mergeRequestOptions(array_filter([
                'model' => $config['model'],
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => $config['temperature'],
                'max_tokens' => $config['max_tokens'] ?? 2048,
            ], static fn ($value): bool => $value !== null), $config),
            'timeout' => 120,
        ]);

        $payload = $this->decodeJsonPayload($response->getContent(false));
        $parts = [];
        foreach (($payload['content'] ?? []) as $content) {
            if (is_array($content) && ($content['type'] ?? null) === 'text' && isset($content['text']) && is_string($content['text'])) {
                $parts[] = $this->normalizeModelText($content['text']);
            }
        }
        $text = trim(implode("\n\n", $parts));
        if ($text === '') {
            throw new \RuntimeException('Anthropic returned an empty analysis.');
        }

        return ['text' => $text, 'usage' => isset($payload['usage']) && is_array($payload['usage']) ? $payload['usage'] : null];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{text: string, usage: array<string, mixed>|null}
     */
    private function requestGemini(array $config, string $prompt): array
    {
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
            rawurlencode($config['model']),
            rawurlencode($config['api_key'])
        );

        $response = $this->httpClient->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => $this->mergeRequestOptions([
                'contents' => [[
                    'role' => 'user',
                    'parts' => [['text' => $prompt]],
                ]],
                'generationConfig' => array_filter([
                    'temperature' => $config['temperature'],
                    'maxOutputTokens' => $config['max_tokens'],
                ], static fn ($value): bool => $value !== null),
            ], $config),
            'timeout' => 120,
        ]);

        $payload = $this->decodeJsonPayload($response->getContent(false));
        $parts = [];
        foreach (($payload['candidates'] ?? []) as $candidate) {
            $contentParts = $candidate['content']['parts'] ?? null;
            if (!is_array($contentParts)) {
                continue;
            }
            foreach ($contentParts as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $parts[] = $this->normalizeModelText($part['text']);
                }
            }
        }

        $text = trim(implode("\n\n", $parts));
        if ($text === '') {
            throw new \RuntimeException('Gemini returned an empty analysis.');
        }

        return ['text' => $text, 'usage' => isset($payload['usageMetadata']) && is_array($payload['usageMetadata']) ? $payload['usageMetadata'] : null];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{text: string, usage: array<string, mixed>|null}
     */
    private function requestOpenAiCompatible(array $config, string $prompt): array
    {
        $baseUrl = (string) ($config['base_url'] ?? '');
        if ($baseUrl === '') {
            $baseUrl = (string) CrashAiProviderCatalog::defaultBaseUrl($config['provider']);
        }
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '') {
            throw new \RuntimeException('Base URL is required for the selected provider.');
        }

        $response = $this->httpClient->request('POST', $baseUrl . '/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $config['api_key'],
                'Content-Type' => 'application/json',
            ],
            'json' => $this->mergeRequestOptions(array_filter([
                'model' => $config['model'],
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => $config['temperature'],
                'max_tokens' => $config['max_tokens'],
            ], static fn ($value): bool => $value !== null), $config),
            'timeout' => 120,
        ]);

        $payload = $this->decodeJsonPayload($response->getContent(false));
        $text = trim($this->normalizeModelText((string) ($payload['choices'][0]['message']['content'] ?? '')));
        if ($text === '') {
            throw new \RuntimeException('The selected provider returned an empty analysis.');
        }

        return ['text' => $text, 'usage' => isset($payload['usage']) && is_array($payload['usage']) ? $payload['usage'] : null];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function recordAudit(int $userId, string $crashId, array $config, string $status, int $durationMs, int $requestBytes, ?string $errorSummary, string $context): void
    {
        $this->connection->insert('crash_ai_analysis_audit', [
            'crash' => $crashId,
            'user_id' => $userId,
            'provider' => $config['provider'],
            'model' => mb_substr((string) $config['model'], 0, 255),
            'context' => mb_substr($context, 0, 32),
            'status' => mb_substr($status, 0, 32),
            'duration_ms' => $durationMs,
            'request_bytes' => $requestBytes,
            'error_summary' => $errorSummary !== null ? mb_substr($errorSummary, 0, 1024) : null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, bool> $sections
     * @param array<string, mixed>|null $usage
     */
    private function recordHistory(int $userId, string $crashId, array $config, string $context, string $prompt, array $sections, string $responseText, ?array $usage): int
    {
        $this->connection->insert('crash_ai_analysis_history', [
            'crash' => $crashId,
            'user_id' => $userId,
            'provider' => $config['provider'],
            'model' => mb_substr((string) $config['model'], 0, 255),
            'context' => mb_substr($context, 0, 32),
            'prompt' => $prompt,
            'request_sections_json' => json_encode($sections, JSON_UNESCAPED_SLASHES),
            'response_text' => $responseText,
            'usage_json' => $usage !== null ? json_encode($usage, JSON_UNESCAPED_SLASHES) : null,
            'is_public' => 0,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @param array<string, mixed> $basePayload
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function mergeRequestOptions(array $basePayload, array $config): array
    {
        $extra = $config['extra_options'] ?? [];
        if (!is_array($extra) || $extra === []) {
            return $basePayload;
        }

        $merged = array_replace_recursive($extra, $basePayload);

        return is_array($merged) ? $merged : $basePayload;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonPayload(string $body): array
    {
        $payload = json_decode($body, true);
        if (is_array($payload)) {
            return $payload;
        }

        foreach ([
            @mb_convert_encoding($body, 'UTF-8', 'Windows-1251'),
            @mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1'),
        ] as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            $payload = json_decode($candidate, true);
            if (is_array($payload)) {
                return $payload;
            }
        }

        throw new \RuntimeException('Provider returned invalid JSON.');
    }

    private function normalizeModelText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            foreach (['Windows-1251', 'ISO-8859-1'] as $encoding) {
                $candidate = @mb_convert_encoding($text, 'UTF-8', $encoding);
                if (is_string($candidate) && $candidate !== '' && mb_check_encoding($candidate, 'UTF-8')) {
                    $text = $candidate;
                    break;
                }
            }
        }

        $text = preg_replace('/\x{FFFD}\s*/u', "\u{0445}", $text) ?? $text;
        $text = str_replace("\u{FFFD}", "\u{0445}", $text);
        $text = str_replace(["\u{00AD}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"], '', $text);

        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /**
     * @param array<string, mixed>|null $usage
     * @return array<string, int>|null
     */
    private static function summarizeUsage(?array $usage): ?array
    {
        if ($usage === null) {
            return null;
        }

        $input = self::firstInt($usage, [
            'input_tokens',
            'prompt_tokens',
            'inputTokenCount',
            'promptTokenCount',
        ]);
        $output = self::firstInt($usage, [
            'output_tokens',
            'completion_tokens',
            'outputTokenCount',
            'candidatesTokenCount',
            'completionTokenCount',
        ]);
        $total = self::firstInt($usage, [
            'total_tokens',
            'totalTokenCount',
        ]);

        if ($total === null && ($input !== null || $output !== null)) {
            $total = (int) ($input ?? 0) + (int) ($output ?? 0);
        }

        if ($input === null && $output === null && $total === null) {
            return null;
        }

        return array_filter([
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $total,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $usage
     * @param list<string> $keys
     */
    private static function firstInt(array $usage, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($usage[$key]) && is_numeric((string) $usage[$key])) {
                return (int) $usage[$key];
            }
        }

        return null;
    }
}
