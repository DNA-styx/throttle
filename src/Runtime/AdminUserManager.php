<?php

namespace App\Runtime;

use App\Entity\ExternalAccount;
use App\Entity\User;
use App\Repository\ExternalAccountRepository;
use App\Repository\UserRepository;
use App\Security\ConfigAdminChecker;
use App\Security\UserAccessManager;
use Doctrine\DBAL\Connection;

final class AdminUserManager
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UserRepository $userRepository,
        private readonly ExternalAccountRepository $externalAccountRepository,
        private readonly ConfigAdminChecker $configAdminChecker,
        private readonly UserAccessManager $userAccessManager,
    ) {
    }

    /**
     * @return array{
     *     total:int,
     *     banned:int,
     *     uploads_blocked:int,
     *     with_steam:int,
     *     with_discord:int,
     *     with_password:int
     * }
     */
    public function summary(): array
    {
        return [
            'total' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user'),
            'banned' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user WHERE is_banned = 1'),
            'uploads_blocked' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user WHERE uploads_blocked = 1'),
            'with_steam' => (int) $this->connection->fetchOne('SELECT COUNT(DISTINCT user_id) FROM external_account WHERE kind = ?', ['steam']),
            'with_discord' => (int) $this->connection->fetchOne('SELECT COUNT(DISTINCT user_id) FROM external_account WHERE kind = ?', ['discord']),
            'with_password' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM user WHERE password_hash IS NOT NULL AND password_hash != ?', ['']),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     total:int,
     *     page:int,
     *     per_page:int,
     *     total_pages:int
     * }
     */
    public function listUsers(array $filters, int $page, int $perPage = 25): array
    {
        $conditions = [];
        $params = [];

        if (($id = trim((string) ($filters['id'] ?? ''))) !== '') {
            if (ctype_digit($id)) {
                $conditions[] = 'u.id = ?';
                $params[] = (int) $id;
            } else {
                $conditions[] = '1 = 0';
            }
        }

        if (($email = mb_strtolower(trim((string) ($filters['email'] ?? '')))) !== '') {
            $conditions[] = 'EXISTS (SELECT 1 FROM external_account ea WHERE ea.user_id = u.id AND ea.kind = ? AND ea.identifier LIKE ?)';
            $params[] = 'email';
            $params[] = '%' . $email . '%';
        }

        if (($steam = trim((string) ($filters['steam_id'] ?? ''))) !== '') {
            $conditions[] = 'EXISTS (SELECT 1 FROM external_account ea WHERE ea.user_id = u.id AND ea.kind = ? AND ea.identifier = ?)';
            $params[] = 'steam';
            $params[] = $steam;
        }

        if (($discord = trim((string) ($filters['discord_id'] ?? ''))) !== '') {
            $conditions[] = 'EXISTS (SELECT 1 FROM external_account ea WHERE ea.user_id = u.id AND ea.kind = ? AND ea.identifier = ?)';
            $params[] = 'discord';
            $params[] = $discord;
        }

        if (($role = trim((string) ($filters['role'] ?? ''))) !== '') {
            if ($role === User::ROLE_ADMIN) {
                $conditions[] = 'JSON_CONTAINS(u.roles, ?)';
                $params[] = '"ROLE_ADMIN"';
            } elseif ($role === User::ROLE_ALLOWED_TO_SWITCH) {
                $conditions[] = 'JSON_CONTAINS(u.roles, ?)';
                $params[] = '"ROLE_ALLOWED_TO_SWITCH"';
            } elseif ($role === User::ROLE_USER) {
                // every stored user matches ROLE_USER
            }
        }

        $this->applyBooleanFilter($conditions, $params, 'u.is_banned', $filters['banned'] ?? null);
        $this->applyBooleanFilter($conditions, $params, 'u.uploads_blocked', $filters['uploads_blocked'] ?? null);
        $this->applyBooleanFilter($conditions, $params, 'u.email_verified_at IS NOT NULL', $filters['email_verified'] ?? null, raw: true);
        $this->applyBooleanFilter($conditions, $params, '(u.password_hash IS NOT NULL AND u.password_hash != \'\')', $filters['has_local_password'] ?? null, raw: true);

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM user u ' . $where,
            $params,
        );

        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;

        $rows = $this->connection->fetchAllAssociative(
            'SELECT u.id,
                    so.name,
                    u.roles,
                    u.login,
                    u.last_login,
                    u.email_verified_at,
                    u.password_hash,
                    u.upload_token,
                    u.is_banned,
                    u.uploads_blocked,
                    u.banned_at,
                    u.banned_reason,
                    email.identifier AS contact_email,
                    steam.identifier AS steam_id,
                    discord.identifier AS discord_id,
                    (SELECT COUNT(*) FROM team t WHERE t.owner_id = u.id) AS teams_count,
                    (SELECT COUNT(*) FROM server s LEFT JOIN team st ON st.id = s.owner_id WHERE s.owner_id = u.id OR st.owner_id = u.id) AS servers_count,
                    (SELECT COUNT(*) FROM crash c LEFT JOIN team ct ON ct.id = c.owner_id WHERE c.owner_id = u.id OR ct.owner_id = u.id) AS crashes_count,
                    (SELECT COUNT(*) FROM share sh WHERE sh.owner = u.id) AS outgoing_share_total,
                    (SELECT COUNT(*) FROM share sh WHERE sh.owner = u.id AND sh.accepted IS NOT NULL) AS outgoing_share_accepted,
                    (SELECT COUNT(*) FROM share sh WHERE sh.user = u.id) AS incoming_share_total,
                    (SELECT COUNT(*) FROM share sh WHERE sh.user = u.id AND sh.accepted IS NOT NULL) AS incoming_share_accepted
             FROM user u
             INNER JOIN server_owner so ON so.id = u.id
             LEFT JOIN external_account email ON email.id = u.contact_email_id
             LEFT JOIN external_account steam ON steam.user_id = u.id AND steam.kind = ?
             LEFT JOIN external_account discord ON discord.user_id = u.id AND discord.kind = ?
             ' . $where . '
             ORDER BY u.id DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            array_merge(['steam', 'discord'], $params),
        );

        $userIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $users = $this->loadUsersByIds($userIds);

        foreach ($rows as &$row) {
            $user = $users[(int) $row['id']] ?? null;
            $decodedRoles = is_string($row['roles'] ?? null) ? json_decode($row['roles'], true) : null;
            $row['roles'] = is_array($decodedRoles) ? $decodedRoles : [];
            $row['has_password'] = ($row['password_hash'] ?? null) !== null && ($row['password_hash'] ?? '') !== '';
            $row['email_verified'] = $row['email_verified_at'] !== null;
            $row['has_upload_token'] = ($row['upload_token'] ?? '') !== '';
            $row['effective_admin'] = $user instanceof User ? $this->configAdminChecker->isAdmin($user) : false;
            $row['admin_source'] = $user instanceof User ? $this->adminSource($user) : 'none';
            $row['usable_login_methods'] = $user instanceof User ? $this->userAccessManager->countUsableLoginMethods($user) : 0;
        }
        unset($row);

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userDetails(User $user): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT u.id,
                    so.name,
                    u.login,
                    u.last_login,
                    u.email_verified_at,
                    u.is_banned,
                    u.uploads_blocked,
                    u.banned_at,
                    u.banned_reason,
                    (SELECT COUNT(*) FROM team t WHERE t.owner_id = u.id) AS teams_count,
                    (SELECT COUNT(*) FROM server s LEFT JOIN team st ON st.id = s.owner_id WHERE s.owner_id = u.id OR st.owner_id = u.id) AS servers_count,
                    (SELECT COUNT(*) FROM crash c LEFT JOIN team ct ON ct.id = c.owner_id WHERE c.owner_id = u.id OR ct.owner_id = u.id) AS crashes_count,
                    (SELECT COUNT(*) FROM share sh WHERE sh.owner = u.id) AS outgoing_share_total,
                    (SELECT COUNT(*) FROM share sh WHERE sh.owner = u.id AND sh.accepted IS NOT NULL) AS outgoing_share_accepted,
                    (SELECT COUNT(*) FROM share sh WHERE sh.user = u.id) AS incoming_share_total,
                    (SELECT COUNT(*) FROM share sh WHERE sh.user = u.id AND sh.accepted IS NOT NULL) AS incoming_share_accepted
             FROM user u
             INNER JOIN server_owner so ON so.id = u.id
             WHERE u.id = ?',
            [$user->getId()],
        );
        if ($row === false) {
            return null;
        }

        $externalAccounts = [];
        foreach ($user->getExternalAccounts() as $externalAccount) {
            $externalAccounts[] = [
                'id' => $externalAccount->getId(),
                'kind' => $externalAccount->getKind(),
                'identifier' => $externalAccount->getIdentifier(),
                'display_name' => $externalAccount->getDisplayName(),
                'last_login' => $externalAccount->getLastLogin()?->format('Y-m-d H:i:s'),
                'is_contact_email' => $externalAccount->getKind() === 'email'
                    && $user->getContactEmail() !== null
                    && hash_equals($externalAccount->getIdentifier(), $user->getContactEmail()),
            ];
        }

        return [
            'user' => $user,
            'stats' => $row,
            'external_accounts' => $externalAccounts,
            'effective_admin' => $this->configAdminChecker->isAdmin($user),
            'admin_source' => $this->adminSource($user),
            'usable_login_methods' => $this->userAccessManager->countUsableLoginMethods($user),
        ];
    }

    public function adminSource(User $user): string
    {
        $db = in_array(User::ROLE_ADMIN, $user->getRoles(), true);
        $config = $this->isConfigAdmin($user);

        return match (true) {
            $db && $config => 'database + APP_ADMINS',
            $db => 'database role',
            $config => 'APP_ADMINS',
            default => 'none',
        };
    }

    public function isLastEffectiveAdmin(User $target): bool
    {
        if (!$this->isEffectiveAdmin($target)) {
            return false;
        }

        return $this->countEffectiveAdmins() <= 1;
    }

    public function countEffectiveAdmins(): int
    {
        $count = 0;
        foreach ($this->userRepository->findAll() as $user) {
            if ($this->isEffectiveAdmin($user)) {
                ++$count;
            }
        }

        return $count;
    }

    public function findExternalAccountById(User $user, int $externalAccountId): ?ExternalAccount
    {
        $externalAccount = $this->externalAccountRepository->find($externalAccountId);
        if (!$externalAccount instanceof ExternalAccount || $externalAccount->getUser()->getId() !== $user->getId()) {
            return null;
        }

        return $externalAccount;
    }

    public function isConfigAdmin(User $user): bool
    {
        return $this->configAdminChecker->isAdmin($user);
    }

    public function isEffectiveAdmin(User $user): bool
    {
        return in_array(User::ROLE_ADMIN, $user->getRoles(), true) || $this->isConfigAdmin($user);
    }

    /**
     * @param list<int> $ids
     * @return array<int, User>
     */
    private function loadUsersByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $users = [];
        foreach ($this->userRepository->findBy(['id' => $ids]) as $user) {
            $users[$user->getId()] = $user;
        }

        return $users;
    }

    /**
     * @param list<string> $conditions
     * @param list<mixed> $params
     */
    private function applyBooleanFilter(array &$conditions, array &$params, string $expression, mixed $value, bool $raw = false): void
    {
        if (!is_string($value) || $value === '' || $value === 'all') {
            return;
        }

        $sql = $raw ? $expression : ($expression . ' = ?');
        if ($value === 'yes') {
            $conditions[] = $sql;
            if (!$raw) {
                $params[] = 1;
            }
            return;
        }

        if ($value === 'no') {
            $conditions[] = $raw ? sprintf('NOT (%s)', $expression) : ($expression . ' = ?');
            if (!$raw) {
                $params[] = 0;
            }
        }
    }
}
