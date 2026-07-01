<?php

namespace App\Runtime;

use App\Legacy\LegacyBridgeFactory;
use Doctrine\DBAL\Connection;

final class CrashAiGlobalAnalysisManager
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LegacyBridgeFactory $legacyBridgeFactory,
        private readonly SiteAiConfigManager $siteAiConfigManager,
        private readonly CrashAiAnalysisManager $crashAiAnalysisManager,
    ) {
    }

    public function refreshForCrash(string $crashId): void
    {
        $config = $this->siteAiConfigManager->loadExecutionConfig();
        if ($config === null) {
            return;
        }

        $crash = $this->connection->fetchAssociative(
            'SELECT id, stackhash, processed, failed FROM crash WHERE id = ?',
            [$crashId]
        );
        if ($crash === false || (int) ($crash['processed'] ?? 0) !== 1 || (int) ($crash['failed'] ?? 0) === 1) {
            return;
        }

        $stackhash = trim((string) ($crash['stackhash'] ?? ''));
        if ($stackhash === '') {
            return;
        }

        $app = $this->legacyBridgeFactory->createConsole();
        $packet = (new \Throttle\Crash())->buildAiAnalysisPacket($app, $crashId, 'details', [
            'stack_trace' => true,
            'likely_cause' => true,
            'modules' => true,
            'console' => true,
            'header_metadata' => true,
            'raw' => false,
        ]);

        $prompt = trim((string) ($config['prompt'] ?? CrashAiProviderCatalog::DEFAULT_PROMPT));
        $finalPrompt = rtrim($prompt) . "\n\n---\n\nCrash data:\n" . $packet['text'];
        $inputHash = hash('sha256', json_encode([
            'provider' => (string) ($config['provider'] ?? ''),
            'model' => (string) ($config['model'] ?? ''),
            'base_url' => (string) ($config['base_url'] ?? ''),
            'temperature' => $config['temperature'] ?? null,
            'max_tokens' => $config['max_tokens'] ?? null,
            'prompt' => $prompt,
            'packet' => $packet['text'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $finalPrompt);

        $existing = $this->connection->fetchAssociative(
            'SELECT id, input_hash, status FROM crash_ai_global_analysis WHERE stackhash = ?',
            [$stackhash]
        );
        if ($existing !== false && (string) ($existing['input_hash'] ?? '') === $inputHash && (string) ($existing['status'] ?? '') === 'ok') {
            return;
        }

        try {
            $result = $this->crashAiAnalysisManager->executePrompt($config, $finalPrompt);
            $payload = [
                'stackhash' => $stackhash,
                'status' => 'ok',
                'provider' => (string) ($config['provider'] ?? ''),
                'model' => (string) ($config['model'] ?? ''),
                'input_hash' => $inputHash,
                'prompt_snapshot' => $prompt,
                'response_text' => (string) ($result['text'] ?? ''),
                'response_html' => \Throttle\Crash::renderAiMarkdownHtml((string) ($result['text'] ?? '')),
                'usage_json' => isset($result['usage']) && is_array($result['usage']) ? json_encode($result['usage'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'error_summary' => null,
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $e) {
            $payload = [
                'stackhash' => $stackhash,
                'status' => 'failed',
                'provider' => (string) ($config['provider'] ?? ''),
                'model' => (string) ($config['model'] ?? ''),
                'input_hash' => $inputHash,
                'prompt_snapshot' => $prompt,
                'response_text' => null,
                'response_html' => null,
                'usage_json' => null,
                'error_summary' => mb_substr($e->getMessage(), 0, 65535),
                'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ];
        }

        if ($existing === false) {
            $payload['created_at'] = $payload['updated_at'];
            $this->connection->insert('crash_ai_global_analysis', $payload);

            return;
        }

        $this->connection->update('crash_ai_global_analysis', $payload, ['id' => (int) $existing['id']]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadByStackhash(?string $stackhash): ?array
    {
        if (!is_string($stackhash) || trim($stackhash) === '') {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT status, provider, model, response_text, response_html, usage_json, error_summary, updated_at
             FROM crash_ai_global_analysis
             WHERE stackhash = ?',
            [trim($stackhash)]
        );
        if ($row === false) {
            return null;
        }

        $usage = json_decode((string) ($row['usage_json'] ?? ''), true);

        return [
            'status' => (string) ($row['status'] ?? 'unknown'),
            'provider' => (string) ($row['provider'] ?? ''),
            'provider_label' => CrashAiProviderCatalog::label((string) ($row['provider'] ?? '')),
            'model' => (string) ($row['model'] ?? ''),
            'response_text' => (string) ($row['response_text'] ?? ''),
            'response_html' => (string) ($row['response_html'] ?? ''),
            'usage' => is_array($usage) ? $usage : null,
            'usage_summary' => CrashAiAnalysisManager::summarizeUsage(is_array($usage) ? $usage : null),
            'error_summary' => ($row['error_summary'] ?? null) !== null ? (string) $row['error_summary'] : null,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    /**
     * @return array{status: string, provider: string, provider_label: string, model: string, duration_ms: int, response_text: string}
     */
    public function testCurrentConfig(): array
    {
        $config = $this->siteAiConfigManager->loadExecutionConfig();
        if ($config === null) {
            throw new \RuntimeException('Global AI configuration is incomplete or disabled.');
        }

        $result = $this->crashAiAnalysisManager->executePrompt(
            $config,
            'Reply with one short line confirming the provider is working for crash analysis.'
        );

        return [
            'status' => 'ok',
            'provider' => (string) ($config['provider'] ?? ''),
            'provider_label' => CrashAiProviderCatalog::label((string) ($config['provider'] ?? '')),
            'model' => (string) ($config['model'] ?? ''),
            'duration_ms' => (int) ($result['duration_ms'] ?? 0),
            'response_text' => (string) ($result['text'] ?? ''),
        ];
    }
}
