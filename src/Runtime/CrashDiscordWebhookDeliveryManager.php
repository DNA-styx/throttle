<?php

namespace App\Runtime;

use App\Entity\User;
use App\Legacy\LegacyBridgeFactory;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CrashDiscordWebhookDeliveryManager
{
    private const DISCORD_CONTENT_LIMIT = 1950;

    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $httpClient,
        private readonly LegacyBridgeFactory $legacyBridgeFactory,
        private readonly UserDiscordWebhookManager $webhookManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function notifyCrashProcessed(string $crashId): void
    {
        $ownerId = $this->connection->fetchOne(
            'SELECT CASE
                    WHEN so.kind = ? THEN t.owner_id
                    WHEN so.kind = ? THEN so.id
                    ELSE NULL
                END AS user_owner_id
             FROM crash c
             LEFT JOIN server_owner so ON so.id = c.owner_id
             LEFT JOIN team t ON t.id = so.id
             WHERE c.id = ?',
            ['team', 'user', $crashId],
        );
        if ($ownerId === false || $ownerId === null) {
            return;
        }

        $targets = $this->webhookManager->listEnabledDeliveryTargetsForUser((int) $ownerId);
        if ($targets === []) {
            return;
        }

        $app = $this->legacyBridgeFactory->createConsole();
        $crashRuntime = new \Throttle\Crash();

        foreach ($targets as $target) {
            $webhookId = (int) ($target['id'] ?? 0);
            if ($webhookId < 1 || $this->alreadyDelivered($crashId, $webhookId)) {
                continue;
            }

            try {
                $context = $crashRuntime->buildDiscordWebhookContext(
                    $app,
                    $crashId,
                    (int) ($target['console_line_limit'] ?? 20),
                    (int) ($target['stack_trace_line_limit'] ?? 18),
                );
                $message = $this->webhookManager->renderMessageTemplate((string) ($target['message_template'] ?? ''), $context);
                $statusCode = $this->sendWebhookMessage((string) $target['webhook_url'], $message);
                $this->recordDelivery($crashId, $webhookId, $statusCode);
            } catch (\Throwable $e) {
                $this->logger->warning('Crash Discord webhook delivery failed.', [
                    'crash' => $crashId,
                    'webhook_id' => $webhookId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function sendTest(User $user, array $input): void
    {
        $target = $this->webhookManager->buildDeliveryTargetFromInput($user, $input);
        $sampleContext = [
            'crash_id' => 'TEST-CRASH-0001',
            'stack_trace' => "#0 server_srv.so!ExampleFunction() + 0x59\n#1 engine_srv.so + 0x16be82",
            'likely_cause' => 'example.ext.so (Likely plugin cause, 82%)',
            'likely_cause_details' => "- Raw stack callsite frame #2: ExampleFunction\n- SourceMod/JIT bridge frame observed",
            'likely_cause_supporting' => "- accelerator.ext.so (Bridge, 18%)\n- sourcepawn.jit.x86.so (Bridge, 14%)",
            'likely_cause_full' => "example.ext.so (Likely plugin cause, 82%)\n- Raw stack callsite frame #2: ExampleFunction\n- SourceMod/JIT bridge frame observed\n\n- accelerator.ext.so (Bridge, 18%)\n- sourcepawn.jit.x86.so (Bridge, 14%)",
            'console' => "[tick 100 @ 00:10] L 06/01/2026 - 21:00:00: [SM] Blaming: example.smx\n[tick 100 @ 00:10] L 06/01/2026 - 21:00:00: [SM] Call stack trace:",
        ];

        $message = $this->webhookManager->renderMessageTemplate((string) ($target['message_template'] ?? ''), $sampleContext);
        $this->sendWebhookMessage((string) $target['webhook_url'], $message);
    }

    private function alreadyDelivered(string $crashId, int $webhookId): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM crash_discord_webhook_delivery WHERE crash = ? AND webhook_id = ?',
            [$crashId, $webhookId],
        ) > 0;
    }

    private function recordDelivery(string $crashId, int $webhookId, int $statusCode): void
    {
        $this->connection->insert('crash_discord_webhook_delivery', [
            'crash' => $crashId,
            'webhook_id' => $webhookId,
            'status_code' => $statusCode,
            'delivered_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function sendWebhookMessage(string $webhookUrl, string $message): int
    {
        $content = trim($message);
        if ($content === '') {
            throw new \RuntimeException('Webhook message is empty after placeholder substitution.');
        }

        if (mb_strlen($content) > self::DISCORD_CONTENT_LIMIT) {
            $content = rtrim(mb_substr($content, 0, self::DISCORD_CONTENT_LIMIT - 16)) . "\n...[truncated]";
        }

        $response = $this->httpClient->request('POST', $webhookUrl, [
            'json' => [
                'content' => $content,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getContent(false);
        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf(
                'Discord webhook returned HTTP %d%s',
                $statusCode,
                $body !== '' ? ': ' . mb_substr(trim($body), 0, 300) : ''
            ));
        }

        return $statusCode;
    }
}
