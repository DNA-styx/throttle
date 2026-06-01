<?php

namespace App\Runtime;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final class UserDiscordWebhookManager
{
    public const MAX_WEBHOOKS_PER_USER = 5;

    private const DEFAULT_TEMPLATE = <<<'TEXT'
New crash `{crash_id}`
`{likely_cause}`

```{stack_trace}```

```{console}```
TEXT;

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
            'SELECT id, owner_id, enabled, display_name, webhook_url_encrypted, message_template, console_line_limit, created_at, updated_at
             FROM user_discord_webhook
             WHERE owner_id = ?
             ORDER BY updated_at DESC, id DESC',
            [$userId],
        );

        return array_map(fn (array $row): array => $this->normalizeSummaryRow($row), $rows);
    }

    public function countConfigsForUser(int $userId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user_discord_webhook WHERE owner_id = ?',
            [$userId],
        );
    }

    /**
     * @return list<array{id: int, display_name: string, webhook_url: string, message_template: string, console_line_limit: int, enabled: bool}>
     */
    public function listEnabledDeliveryTargetsForUser(int $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, display_name, webhook_url_encrypted, message_template, console_line_limit, stack_trace_line_limit, enabled
             FROM user_discord_webhook
             WHERE owner_id = ? AND enabled = 1
             ORDER BY id ASC',
            [$userId],
        );

        $targets = [];
        foreach ($rows as $row) {
            $encrypted = (string) ($row['webhook_url_encrypted'] ?? '');
            if ($encrypted === '') {
                continue;
            }

            try {
                $webhookUrl = $this->crypto->decrypt($encrypted);
            } catch (\Throwable) {
                continue;
            }

            if (!$this->isValidDiscordWebhookUrl($webhookUrl)) {
                continue;
            }

            $targets[] = [
                'id' => (int) ($row['id'] ?? 0),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'webhook_url' => $webhookUrl,
                'message_template' => (string) ($row['message_template'] ?? ''),
                'console_line_limit' => max(0, (int) ($row['console_line_limit'] ?? 20)),
                'stack_trace_line_limit' => $this->normalizeStackTraceLineLimit($row['stack_trace_line_limit'] ?? 18),
                'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            ];
        }

        return $targets;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveConfig(User $user, array $input): array
    {
        $normalized = $this->normalizeInput($user, $input, true);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        if ($normalized['id'] === null) {
            $this->connection->insert('user_discord_webhook', [
                'owner_id' => $user->getId(),
                'enabled' => $normalized['enabled'] ? 1 : 0,
                'display_name' => $normalized['display_name'],
                'webhook_url_encrypted' => $normalized['webhook_url_encrypted'],
                'message_template' => $normalized['message_template'],
                'console_line_limit' => $normalized['console_line_limit'],
                'stack_trace_line_limit' => $normalized['stack_trace_line_limit'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->connection->lastInsertId();
        } else {
            $id = $normalized['id'];
            $this->connection->update('user_discord_webhook', [
                'enabled' => $normalized['enabled'] ? 1 : 0,
                'display_name' => $normalized['display_name'],
                'webhook_url_encrypted' => $normalized['webhook_url_encrypted'],
                'message_template' => $normalized['message_template'],
                'console_line_limit' => $normalized['console_line_limit'],
                'stack_trace_line_limit' => $normalized['stack_trace_line_limit'],
                'updated_at' => $now,
            ], ['id' => $id, 'owner_id' => $user->getId()]);
        }

        return $this->requireConfigSummaryForUser($user->getId(), $id);
    }

    public function deleteConfig(User $user, int $configId): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id FROM user_discord_webhook WHERE id = ? AND owner_id = ?',
            [$configId, $user->getId()],
        );
        if ($row === false) {
            throw new \RuntimeException('Webhook configuration was not found.');
        }

        $this->connection->delete('user_discord_webhook', [
            'id' => $configId,
            'owner_id' => $user->getId(),
        ]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: ?int, display_name: string, webhook_url: string, message_template: string, console_line_limit: int, stack_trace_line_limit: int, enabled: bool}
     */
    public function buildDeliveryTargetFromInput(User $user, array $input): array
    {
        $normalized = $this->normalizeInput($user, $input, false);

        return [
            'id' => $normalized['id'],
            'display_name' => $normalized['display_name'],
            'webhook_url' => $normalized['webhook_url'],
            'message_template' => $normalized['message_template'],
            'console_line_limit' => $normalized['console_line_limit'],
            'stack_trace_line_limit' => $normalized['stack_trace_line_limit'],
            'enabled' => $normalized['enabled'],
        ];
    }

    public function renderMessageTemplate(?string $template, array $context): string
    {
        $template = trim((string) $template) !== ''
            ? (string) $template
            : self::DEFAULT_TEMPLATE;

        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{' . $key . '}'] = is_scalar($value) ? (string) $value : '';
        }

        return strtr($template, $replacements);
    }

    public function defaultTemplate(): string
    {
        return self::DEFAULT_TEMPLATE;
    }

    /**
     * @return array{id: int, display_name: string, message_template: string, console_line_limit: int, stack_trace_line_limit: int, enabled: bool, webhook_configured: bool, webhook_mask: ?string, created_at: ?string, updated_at: ?string}
     */
    public function requireConfigSummaryForUser(int $userId, int $configId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, owner_id, enabled, display_name, webhook_url_encrypted, message_template, console_line_limit, stack_trace_line_limit, created_at, updated_at
             FROM user_discord_webhook
             WHERE id = ? AND owner_id = ?',
            [$configId, $userId],
        );
        if ($row === false) {
            throw new \RuntimeException('Webhook configuration was not found.');
        }

        return $this->normalizeSummaryRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, display_name: string, message_template: string, console_line_limit: int, stack_trace_line_limit: int, enabled: bool, webhook_configured: bool, webhook_mask: ?string, created_at: ?string, updated_at: ?string}
     */
    private function normalizeSummaryRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'display_name' => (string) ($row['display_name'] ?? ''),
            'message_template' => (string) ($row['message_template'] ?? ''),
            'console_line_limit' => max(0, (int) ($row['console_line_limit'] ?? 20)),
            'stack_trace_line_limit' => $this->normalizeStackTraceLineLimit($row['stack_trace_line_limit'] ?? 18),
            'enabled' => (int) ($row['enabled'] ?? 0) === 1,
            'webhook_configured' => ((string) ($row['webhook_url_encrypted'] ?? '')) !== '',
            'webhook_mask' => $this->buildWebhookMask(($row['webhook_url_encrypted'] ?? null) !== null ? (string) $row['webhook_url_encrypted'] : null),
            'created_at' => ($row['created_at'] ?? null) !== null ? (string) $row['created_at'] : null,
            'updated_at' => ($row['updated_at'] ?? null) !== null ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: ?int, display_name: string, webhook_url: string, webhook_url_encrypted: string, message_template: string, console_line_limit: int, stack_trace_line_limit: int, enabled: bool}
     */
    private function normalizeInput(User $user, array $input, bool $enforceLimit): array
    {
        $id = isset($input['id']) && ctype_digit((string) $input['id']) ? (int) $input['id'] : null;
        $existing = null;
        if ($id !== null) {
            $existing = $this->connection->fetchAssociative(
                'SELECT id, owner_id, webhook_url_encrypted FROM user_discord_webhook WHERE id = ? AND owner_id = ?',
                [$id, $user->getId()],
            );
            if ($existing === false) {
                throw new \RuntimeException('Webhook configuration was not found.');
            }
        } elseif ($enforceLimit && $this->countConfigsForUser($user->getId()) >= self::MAX_WEBHOOKS_PER_USER) {
            throw new \RuntimeException(sprintf('You can save up to %d Discord webhooks.', self::MAX_WEBHOOKS_PER_USER));
        }

        $displayName = trim((string) ($input['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = $id !== null ? 'Discord webhook #' . $id : 'Discord webhook #' . ($this->countConfigsForUser($user->getId()) + 1);
        }

        $webhookUrl = trim((string) ($input['webhook_url'] ?? ''));
        if ($webhookUrl === '' && $existing !== null) {
            $encrypted = (string) ($existing['webhook_url_encrypted'] ?? '');
            if ($encrypted === '') {
                throw new \RuntimeException('Webhook URL is required.');
            }

            try {
                $webhookUrl = $this->crypto->decrypt($encrypted);
            } catch (\Throwable) {
                throw new \RuntimeException('Saved webhook URL could not be decrypted. Enter it again.');
            }
        }

        if ($webhookUrl === '') {
            throw new \RuntimeException('Webhook URL is required.');
        }

        if (!$this->isValidDiscordWebhookUrl($webhookUrl)) {
            throw new \RuntimeException('Enter a valid Discord webhook URL.');
        }

        $consoleLineLimit = $this->normalizeConsoleLineLimit($input['console_line_limit'] ?? 20);
        $stackTraceLineLimit = $this->normalizeStackTraceLineLimit($input['stack_trace_line_limit'] ?? 18);
        $messageTemplate = str_replace("\r\n", "\n", (string) ($input['message_template'] ?? ''));

        return [
            'id' => $id,
            'display_name' => mb_substr($displayName, 0, 100),
            'webhook_url' => $webhookUrl,
            'webhook_url_encrypted' => $this->crypto->encrypt($webhookUrl),
            'message_template' => $messageTemplate,
            'console_line_limit' => $consoleLineLimit,
            'stack_trace_line_limit' => $stackTraceLineLimit,
            'enabled' => !empty($input['enabled']),
        ];
    }

    private function normalizeConsoleLineLimit(mixed $value): int
    {
        if (!is_scalar($value) || !preg_match('/^\d+$/', (string) $value)) {
            throw new \RuntimeException('Console line limit must be a non-negative integer.');
        }

        $limit = (int) $value;
        if ($limit < 0 || $limit > 200) {
            throw new \RuntimeException('Console line limit must be between 0 and 200.');
        }

        return $limit;
    }

    private function normalizeStackTraceLineLimit(mixed $value): int
    {
        if (!is_scalar($value) || !preg_match('/^\d+$/', (string) $value)) {
            throw new \RuntimeException('Stack trace line limit must be a non-negative integer.');
        }

        $limit = (int) $value;
        if ($limit < 0 || $limit > 100) {
            throw new \RuntimeException('Stack trace line limit must be between 0 and 100.');
        }

        return $limit;
    }

    private function isValidDiscordWebhookUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($scheme !== 'https') {
            return false;
        }

        if (!in_array($host, ['discord.com', 'ptb.discord.com', 'canary.discord.com', 'discordapp.com'], true)) {
            return false;
        }

        return preg_match('#^/api/webhooks/\d+/[A-Za-z0-9._-]+$#', $path) === 1;
    }

    private function buildWebhookMask(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        try {
            $url = $this->crypto->decrypt($encrypted);
        } catch (\Throwable) {
            return 'Saved';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return 'Saved';
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        $segments = explode('/', $path);
        $count = count($segments);
        if ($count >= 4) {
            $webhookId = $segments[$count - 2];
            $token = $segments[$count - 1];

            return sprintf('ID %s… · token …%s', substr($webhookId, 0, 6), substr($token, -6));
        }

        return 'Saved';
    }
}
