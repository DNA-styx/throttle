<?php

namespace App\Runtime;

use App\Entity\User;
use Doctrine\DBAL\Connection;

final class AuthTokenManager
{
    public const PURPOSE_EMAIL_LOGIN = 'email_login';
    public const PURPOSE_EMAIL_VERIFY = 'email_verify';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public function __construct(
        private readonly Connection $connection,
        private readonly int $emailLoginLifetime,
        private readonly int $emailVerifyLifetime,
        private readonly int $passwordResetLifetime,
    ) {
    }

    /**
     * @return array{token:string,expires_at:\DateTimeImmutable}
     */
    public function createEmailLoginToken(User $user, string $email): array
    {
        return $this->createToken($user, self::PURPOSE_EMAIL_LOGIN, $email, $this->emailLoginLifetime);
    }

    /**
     * @return array{token:string,expires_at:\DateTimeImmutable}
     */
    public function createEmailVerificationToken(User $user, string $email): array
    {
        $this->invalidateOutstandingTokens($user, self::PURPOSE_EMAIL_VERIFY);

        return $this->createToken($user, self::PURPOSE_EMAIL_VERIFY, $email, $this->emailVerifyLifetime);
    }

    /**
     * @return array{token:string,expires_at:\DateTimeImmutable}
     */
    public function createPasswordResetToken(User $user, string $email): array
    {
        $this->invalidateOutstandingTokens($user, self::PURPOSE_PASSWORD_RESET);

        return $this->createToken($user, self::PURPOSE_PASSWORD_RESET, $email, $this->passwordResetLifetime);
    }

    /**
     * @return array{user_id:int,email:?string,purpose:string}|null
     */
    public function consumeToken(string $token, string $purpose): ?array
    {
        $row = $this->findValidRow($token, $purpose);
        if ($row === null) {
            return null;
        }

        $this->connection->update('auth_flow_token', [
            'consumed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], [
            'id' => (int) $row['id'],
        ]);

        return [
            'user_id' => (int) $row['user_id'],
            'email' => $row['email'] !== null ? (string) $row['email'] : null,
            'purpose' => (string) $row['purpose'],
        ];
    }

    /**
     * @return array{user_id:int,email:?string,purpose:string}|null
     */
    public function findValidToken(string $token, string $purpose): ?array
    {
        $row = $this->findValidRow($token, $purpose);
        if ($row === null) {
            return null;
        }

        return [
            'user_id' => (int) $row['user_id'],
            'email' => $row['email'] !== null ? (string) $row['email'] : null,
            'purpose' => (string) $row['purpose'],
        ];
    }

    private function invalidateOutstandingTokens(User $user, string $purpose): void
    {
        $this->connection->executeStatement(
            'UPDATE auth_flow_token
             SET consumed_at = ?
             WHERE user_id = ? AND purpose = ? AND consumed_at IS NULL',
            [
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                $user->getId(),
                $purpose,
            ],
        );
    }

    /**
     * @return array{token:string,expires_at:\DateTimeImmutable}
     */
    private function createToken(User $user, string $purpose, ?string $email, int $lifetime): array
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = new \DateTimeImmutable(sprintf('+%d seconds', $lifetime));

        $this->connection->insert('auth_flow_token', [
            'user_id' => $user->getId(),
            'purpose' => $purpose,
            'email' => $email,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'consumed_at' => null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findValidRow(string $token, string $purpose): ?array
    {
        $hash = hash('sha256', $token);
        $row = $this->connection->fetchAssociative(
            'SELECT id, user_id, email, purpose, expires_at, consumed_at
             FROM auth_flow_token
             WHERE token_hash = ? AND purpose = ?
             LIMIT 1',
            [$hash, $purpose],
        );

        if (!is_array($row) || (string) ($row['purpose'] ?? '') !== $purpose) {
            return null;
        }

        if ($row['consumed_at'] !== null) {
            return null;
        }

        $expiresAt = new \DateTimeImmutable((string) $row['expires_at']);
        if ($expiresAt < new \DateTimeImmutable()) {
            return null;
        }

        return $row;
    }
}
