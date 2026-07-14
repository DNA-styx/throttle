<?php

namespace Throttle;

use App\Runtime\CrashSourceLookupManager;
use App\Runtime\StorageRetentionManager;
use App\Runtime\UploadFailureBackoff;
use App\Legacy\LegacyDbalConnection;
use Silex\Application;
use Symfony\Component\HttpClient\HttpClient;

class Crash
{
    private const SIGNATURE_NOTES_PER_PAGE = 5;
    private const DISCORD_STACK_TRACE_LINE_LIMIT = 18;

    private static function getSafeReturnPath(Application $app, ?string $return, string $fallbackRoute): string
    {
        if (is_string($return) && $return !== '' && str_starts_with($return, '/') && !str_starts_with($return, '//')) {
            $parts = parse_url($return);
            if ($parts !== false && !isset($parts['scheme']) && !isset($parts['host'])) {
                return $return;
            }
        }

        return $app['url_generator']->generate($fallbackRoute);
    }

    private static function toSteamId64(array $parts): ?string
    {
        if (count($parts) !== 3 || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
            return null;
        }

        $accountId = (((int) $parts[2]) * 2) + (int) $parts[1];

        return (string) (76561197960265728 + $accountId);
    }

    private static function generateId($app)
    {
        for ($i = 0; $i < 10; $i++) {
            $id = \Filesystem::readRandomCharacters(12);
            $path = $app['root'] . '/dumps/' . substr($id, 0, 2);

            if (\Filesystem::pathExists($path . '/' . $id . '.dmp')) {
                continue;
            }

            return array($id, $path);
        }

        throw new \Exception('MINIDUMP COLLISION');
    }

    private static function findUploadedMinidump($app): ?\Symfony\Component\HttpFoundation\File\UploadedFile
    {
        $preferred = $app['request']->files->get('upload_file_minidump');
        if ($preferred instanceof \Symfony\Component\HttpFoundation\File\UploadedFile && $preferred->isValid() && $preferred->getSize() > 0) {
            return $preferred;
        }

        foreach (self::flattenUploadedFiles($app['request']->files->all()) as $field => $file) {
            if (!$file->isValid() || $file->getSize() <= 0) {
                continue;
            }

            $name = strtolower($file->getClientOriginalName());
            if ($field === 'upload_file_minidump' || str_ends_with($name, '.dmp') || str_contains($field, 'dump')) {
                return $file;
            }
        }

        return null;
    }

    private static function flattenUploadedFiles(array $files, string $prefix = ''): array
    {
        $flattened = [];
        foreach ($files as $field => $file) {
            $name = $prefix === '' ? (string) $field : $prefix . '.' . $field;

            if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                $flattened[$name] = $file;
                continue;
            }

            if (is_array($file)) {
                $flattened += self::flattenUploadedFiles($file, $name);
            }
        }

        return $flattened;
    }

    private static function getProvidedUploadToken(Application $app): ?string
    {
        $provided = $app['request']->headers->get('X-Symbol-Upload-Token');
        if (!is_string($provided) || $provided === '') {
            $provided = $app['request']->headers->get('Authorization');
            if (is_string($provided) && preg_match('/^Bearer\s+(.+)$/', $provided, $matches) === 1) {
                $provided = $matches[1];
            }
        }

        if (!is_string($provided) || $provided === '') {
            $provided = $app['request']->request->get('token');
        }

        if (!is_string($provided) || $provided === '') {
            $provided = $app['request']->query->get('token');
        }

        return is_string($provided) ? $provided : null;
    }

    private static function hasValidCrashUploadToken(Application $app): bool
    {
        $provided = self::getProvidedUploadToken($app);
        if (!is_string($provided) || $provided === '') {
            return false;
        }

        $globalToken = $app['config']['symbol-upload-token'] ?? '';
        if (is_string($globalToken) && $globalToken !== '' && hash_equals($globalToken, $provided)) {
            return true;
        }

        $userId = $app['db']->executeQuery('SELECT id FROM user WHERE upload_token = ? AND uploads_blocked = 0 LIMIT 1', [$provided])->fetchColumn(0);

        return $userId !== false && $userId !== null;
    }

    private static function allowAnonymousMinidumpUploads(Application $app): bool
    {
        $settings = $app['config']['upload-settings'] ?? [];
        if (!is_array($settings)) {
            return true;
        }

        return array_key_exists('allow_anonymous_minidump_uploads', $settings)
            ? filter_var($settings['allow_anonymous_minidump_uploads'], FILTER_VALIDATE_BOOL)
            : true;
    }

    public static function getSymbolRequestPolicy(array $config = []): array
    {
        $defaults = [
            'deny-path-prefixes' => [],
            'deny-path-contains' => [],
            'deny-exact' => [],
            'deny-prefixes' => [],
            'deny-suffixes' => [],
            'allow-path-contains' => [],
            'allow-exact' => [],
            'allow-regex' => [],
        ];

        $configured = $config['symbol-request'] ?? [];
        if (!is_array($configured)) {
            return $defaults;
        }

        foreach ($defaults as $key => $value) {
            if (!isset($configured[$key]) || !is_array($configured[$key])) {
                $configured[$key] = $value;
            }
        }

        return $configured;
    }

    public static function getSymbolRequestDecision(string $module, array $config = []): array
    {
        $policy = self::getSymbolRequestPolicy($config);
        $module = str_replace('\\', '/', $module);
        $lower = strtolower($module);
        $basename = basename($lower);

        foreach ($policy['deny-path-prefixes'] as $prefix) {
            if ($prefix !== '' && str_starts_with($lower, strtolower((string) $prefix))) {
                return self::symbolRequestDecision($module, $basename, false, 'hard deny', 'deny-path-prefixes', (string) $prefix);
            }
        }

        foreach ($policy['deny-path-contains'] as $needle) {
            if ($needle !== '' && str_contains($lower, strtolower((string) $needle))) {
                return self::symbolRequestDecision($module, $basename, false, 'hard deny', 'deny-path-contains', (string) $needle);
            }
        }

        foreach ($policy['deny-exact'] as $exact) {
            if ($basename === strtolower((string) $exact)) {
                return self::symbolRequestDecision($module, $basename, false, 'hard deny', 'deny-exact', (string) $exact);
            }
        }

        foreach ($policy['allow-exact'] as $exact) {
            if ($basename === strtolower((string) $exact)) {
                return self::symbolRequestDecision($module, $basename, true, 'allow', 'allow-exact', (string) $exact);
            }
        }

        foreach ($policy['allow-path-contains'] as $needle) {
            if ($needle !== '' && str_contains($lower, strtolower((string) $needle))) {
                return self::symbolRequestDecision($module, $basename, true, 'allow', 'allow-path-contains', (string) $needle);
            }
        }

        foreach ($policy['allow-regex'] as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern !== '' && @preg_match($pattern, $basename) === 1) {
                return self::symbolRequestDecision($module, $basename, true, 'allow', 'allow-regex', $pattern);
            }
        }

        foreach ($policy['deny-prefixes'] as $prefix) {
            if ($prefix !== '' && str_starts_with($basename, strtolower((string) $prefix))) {
                return self::symbolRequestDecision($module, $basename, false, 'generic deny', 'deny-prefixes', (string) $prefix);
            }
        }

        foreach ($policy['deny-suffixes'] as $suffix) {
            if ($suffix !== '' && str_ends_with($basename, strtolower((string) $suffix))) {
                return self::symbolRequestDecision($module, $basename, false, 'generic deny', 'deny-suffixes', (string) $suffix);
            }
        }

        return self::symbolRequestDecision($module, $basename, true, 'default allow', null, null);
    }

    private static function shouldRequestSymbolsForModule(string $module, array $config = []): bool
    {
        return self::getSymbolRequestDecision($module, $config)['requested'];
    }

    private static function symbolRequestDecision(string $module, string $basename, bool $requested, string $stage, ?string $rule, ?string $value): array
    {
        return [
            'module' => $module,
            'basename' => $basename,
            'requested' => $requested,
            'stage' => $stage,
            'rule' => $rule,
            'value' => $value,
        ];
    }

    private static function getSymbolModuleName(string $module): string
    {
        return basename(str_replace('\\', '/', $module));
    }

    private static function hasLocalSymbolFile(Application $app, string $module, string $identifier): bool
    {
        $module = self::getSymbolModuleName($module);
        $symname = $module;
        if (stripos($symname, '.pdb') === strlen($symname) - 4) {
            $symname = substr($symname, 0, -4);
        }

        foreach ($app['config']['symbol-stores'] as $store) {
            if (file_exists($app['root'] . '/symbols/' . $store . '/' . $module . '/' . $identifier . '/' . $symname . '.sym.gz')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{owner_id: ?int, owner_kind: ?string, owner_name: ?string, can_manage: bool, can_view_sensitive: bool}|null
     */
    private static function getCrashAccess(Application $app, string $crash): ?array
    {
        $row = $app['db']->executeQuery(
            'SELECT crash.owner_id, server_owner.kind AS owner_kind, server_owner.name AS owner_name, user.allow_admin_sensitive_crash_access
             FROM crash
             LEFT JOIN server_owner ON server_owner.id = crash.owner_id
             LEFT JOIN user ON user.id = crash.owner_id
             WHERE crash.id = ?',
            [$crash]
        )->fetch();
        if ($row === false) {
            return null;
        }

        $ownerId = ($row['owner_id'] ?? null) !== null ? (int) $row['owner_id'] : null;
        $ownerKind = ($row['owner_kind'] ?? null) !== null ? (string) $row['owner_kind'] : null;
        $ownerName = ($row['owner_name'] ?? null) !== null ? (string) $row['owner_name'] : null;
        $allowAdminSensitiveCrashAccess = !empty($row['allow_admin_sensitive_crash_access']);

        if (!$app['user']) {
            return [
                'owner_id' => $ownerId,
                'owner_kind' => $ownerKind,
                'owner_name' => $ownerName,
                'can_manage' => false,
                'can_view_sensitive' => false,
            ];
        }

        if ($app['user']['admin']) {
            $isOwner = $ownerId !== null && in_array($ownerId, $app['user']['owner_ids'], true);

            return [
                'owner_id' => $ownerId,
                'owner_kind' => $ownerKind,
                'owner_name' => $ownerName,
                'can_manage' => true,
                'can_view_sensitive' => $isOwner || $ownerKind !== 'user' || $ownerId === null || $allowAdminSensitiveCrashAccess,
            ];
        }

        $canManage = $ownerId !== null && in_array($ownerId, $app['user']['owner_ids'], true);

        return [
            'owner_id' => $ownerId,
            'owner_kind' => $ownerKind,
            'owner_name' => $ownerName,
            'can_manage' => $canManage,
            'can_view_sensitive' => $canManage,
        ];
    }

    private static function canUserManage($app, $crash)
    {
        $access = self::getCrashAccess($app, (string) $crash);

        return $access === null ? null : $access['can_manage'];
    }

    private static function canUserViewSensitiveCrashData($app, $crash)
    {
        $access = self::getCrashAccess($app, (string) $crash);

        return $access === null ? null : $access['can_view_sensitive'];
    }

    private static function loadCrashStackhash(Application $app, string $id): ?string
    {
        $stackhash = $app['db']->executeQuery('SELECT stackhash FROM crash WHERE id = ?', array($id))->fetchColumn(0);

        return is_string($stackhash) && $stackhash !== '' ? $stackhash : null;
    }

    private static function getSignatureNotesPage(Application $app): int
    {
        $page = (int) $app['request']->get('notes_page', 1);

        return max(1, $page);
    }

    private static function buildSignatureNotesUrl(Application $app, string $id, int $page, string $hash = '#signature-notes'): string
    {
        return $app['url_generator']->generate('details', array('id' => $id, 'notes_page' => max(1, $page))) . $hash;
    }

    private static function loadSignatureNotes(Application $app, ?string $stackhash, int $page = 1): array
    {
        if ($stackhash === null || $stackhash === '') {
            return array(
                'items' => array(),
                'page' => 1,
                'pages' => 1,
                'total' => 0,
            );
        }

        $page = max(1, $page);
        $perPage = self::SIGNATURE_NOTES_PER_PAGE;
        $offset = ($page - 1) * $perPage;
        $userId = $app['user'] !== null ? (int) $app['user']['id'] : 0;
        $total = (int) $app['db']->executeQuery(
            'SELECT COUNT(*) FROM crash_signature_note WHERE stackhash = ?',
            array($stackhash)
        )->fetchColumn(0);
        $pages = max(1, (int) ceil($total / $perPage));
        if ($page > $pages) {
            $page = $pages;
            $offset = ($page - 1) * $perPage;
        }

        $notes = $app['db']->executeQuery(
            'SELECT crash_signature_note.id, crash_signature_note.stackhash, crash_signature_note.author_id, crash_signature_note.title, crash_signature_note.body, crash_signature_note.created_at, crash_signature_note.updated_at, crash_signature_note.pinned, crash_signature_note.pinned_at, server_owner.name AS author_name,
                    COALESCE(SUM(vote.value), 0) AS score,
                    COALESCE(SUM(CASE WHEN vote.value > 0 THEN 1 ELSE 0 END), 0) AS likes,
                    COALESCE(SUM(CASE WHEN vote.value < 0 THEN 1 ELSE 0 END), 0) AS dislikes,
                    COUNT(vote.id) AS vote_count,
                    COALESCE(MAX(CASE WHEN vote.voter_id = ? THEN vote.value ELSE 0 END), 0) AS user_vote
             FROM crash_signature_note
             LEFT JOIN server_owner ON server_owner.id = crash_signature_note.author_id
             LEFT JOIN crash_signature_note_vote vote ON vote.note_id = crash_signature_note.id
             WHERE crash_signature_note.stackhash = ?
             GROUP BY crash_signature_note.id, crash_signature_note.stackhash, crash_signature_note.author_id, crash_signature_note.title, crash_signature_note.body, crash_signature_note.created_at, crash_signature_note.updated_at, crash_signature_note.pinned, crash_signature_note.pinned_at, server_owner.name
             ORDER BY crash_signature_note.pinned DESC,
                      CASE
                        WHEN COUNT(vote.id) = 0 THEN 1
                        WHEN COALESCE(SUM(vote.value), 0) < 0 THEN 2
                        ELSE 0
                      END ASC,
                      COALESCE(SUM(vote.value), 0) DESC,
                      COALESCE(SUM(CASE WHEN vote.value > 0 THEN 1 ELSE 0 END), 0) DESC,
                      crash_signature_note.created_at DESC,
                      crash_signature_note.id DESC
             LIMIT ? OFFSET ?',
            array($userId, $stackhash, $perPage, $offset),
            array(\PDO::PARAM_INT, \PDO::PARAM_STR, \PDO::PARAM_INT, \PDO::PARAM_INT)
        )->fetchAll();

        foreach ($notes as &$note) {
            $note['body_html'] = self::renderMarkdown((string) $note['body']);
            $note['can_edit'] = $app['user'] !== null && (int) $note['author_id'] === (int) $app['user']['id'];
            $note['can_delete'] = $app['user'] !== null && ($app['user']['admin'] || (int) $note['author_id'] === (int) $app['user']['id']);
            $note['can_pin'] = $app['user'] !== null && $app['user']['admin'];
            $note['pinned'] = (bool) $note['pinned'];
            $note['pinned_at'] = $note['pinned_at'] ?: null;
            $note['score'] = (int) $note['score'];
            $note['likes'] = (int) $note['likes'];
            $note['dislikes'] = (int) $note['dislikes'];
            $note['vote_count'] = (int) $note['vote_count'];
            $note['user_vote'] = (int) $note['user_vote'];
        }
        unset($note);

        return array(
            'items' => $notes,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        );
    }

    private static function validateSignatureNoteInput(Application $app): array
    {
        $title = trim((string) $app['request']->request->get('title', ''));
        $body = trim((string) $app['request']->request->get('body', ''));

        if (mb_strlen($title) > 100) {
            $title = mb_substr($title, 0, 100);
        }

        if (mb_strlen($body) > 500) {
            $body = mb_substr($body, 0, 500);
        }

        return array($title, $body);
    }

    public static function renderMarkdownHtml(string $markdown): string
    {
        return self::renderMarkdown($markdown);
    }

    public static function renderAiMarkdownHtml(string $markdown): string
    {
        return self::renderMarkdown(self::normalizeAiMarkdown($markdown), false);
    }

    private static function renderMarkdown(string $markdown, bool $preserveSoftBreaks = true): string
    {
        $lines = preg_split('/\R/', trim(str_replace("\r\n", "\n", $markdown))) ?: array();
        $html = array();
        $list = null;
        $paragraph = array();

        $flushParagraph = static function () use (&$html, &$paragraph, $preserveSoftBreaks): void {
            if (empty($paragraph)) {
                return;
            }

            $separator = $preserveSoftBreaks ? '<br>' : ' ';
            $html[] = '<p>' . implode($separator, array_map([self::class, 'renderMarkdownInline'], $paragraph)) . '</p>';
            $paragraph = array();
        };
        $closeList = static function () use (&$html, &$list): void {
            if ($list !== null) {
                $html[] = '</' . $list . '>';
                $list = null;
            }
        };

        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '') {
                $flushParagraph();
                $closeList();
                continue;
            }

            if (preg_match('/^#{1,3}\s+(.+)$/', $line, $matches) === 1) {
                $flushParagraph();
                $closeList();
                $html[] = '<strong>' . self::renderMarkdownInline($matches[1]) . '</strong>';
                continue;
            }

            if (preg_match('/^>\s*(.+)$/', $line, $matches) === 1) {
                $flushParagraph();
                $closeList();
                $html[] = '<blockquote>' . self::renderMarkdownInline($matches[1]) . '</blockquote>';
                continue;
            }

            if (preg_match('/^- \[( |x)\]\s+(.+)$/i', $line, $matches) === 1) {
                $flushParagraph();
                if ($list !== 'ul') {
                    $closeList();
                    $html[] = '<ul>';
                    $list = 'ul';
                }
                $html[] = '<li>' . ($matches[1] === 'x' || $matches[1] === 'X' ? '&#9745; ' : '&#9744; ') . self::renderMarkdownInline($matches[2]) . '</li>';
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $line, $matches) === 1) {
                $flushParagraph();
                if ($list !== 'ul') {
                    $closeList();
                    $html[] = '<ul>';
                    $list = 'ul';
                }
                $html[] = '<li>' . self::renderMarkdownInline($matches[1]) . '</li>';
                continue;
            }

            if (preg_match('/^\d+\.\s+(.+)$/', $line, $matches) === 1) {
                $flushParagraph();
                if ($list !== 'ol') {
                    $closeList();
                    $html[] = '<ol>';
                    $list = 'ol';
                }
                $html[] = '<li>' . self::renderMarkdownInline($matches[1]) . '</li>';
                continue;
            }

            $closeList();
            $paragraph[] = $line;
        }

        $flushParagraph();
        $closeList();

        return implode("\n", $html);
    }

    private static function normalizeAiMarkdown(string $markdown): string
    {
        $markdown = str_replace("\r\n", "\n", $markdown);
        $markdown = str_replace("\r", "\n", $markdown);
        $markdown = preg_replace('/\x{FFFD}\s*/u', "\u{0445}", $markdown) ?? $markdown;
        $markdown = str_replace("\u{FFFD}", "\u{0445}", $markdown);
        $markdown = str_replace(["\u{00AD}", "\u{200B}", "\u{200C}", "\u{200D}", "\u{FEFF}"], '', $markdown);

        $lines = explode("\n", $markdown);
        $normalized = array();
        $buffer = '';

        $flushBuffer = static function () use (&$normalized, &$buffer): void {
            if ($buffer === '') {
                return;
            }

            $normalized[] = trim($buffer);
            $buffer = '';
        };

        foreach ($lines as $line) {
            $line = rtrim($line);
            $trimmed = ltrim($line);
            $isStructured = $trimmed !== '' && preg_match('/^(#{1,3}\s|>\s|-\s\[(?: |x|X)\]\s|[-*]\s|\d+\.\s)/u', $trimmed) === 1;

            if ($trimmed === '') {
                $flushBuffer();
                $normalized[] = '';
                continue;
            }

            if ($isStructured) {
                $flushBuffer();
                $normalized[] = $line;
                continue;
            }

            if ($buffer !== '' && preg_match('/[\p{L}\p{N})\]`.,:;!?-]$/u', $buffer) === 1) {
                $buffer .= ' ';
            }

            $buffer .= $trimmed;
        }

        $flushBuffer();

        return trim(implode("\n", $normalized));
    }

    private static function renderMarkdownInline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace_callback('/`([^`\n]+)`/', static fn ($matches) => '<code>' . $matches[1] . '</code>', $text) ?? $text;
        $text = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/\*([^*\n]+)\*/', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace_callback('/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/', static function (array $matches): string {
            return '<a href="' . $matches[2] . '" rel="nofollow noopener noreferrer" target="_blank">' . $matches[1] . '</a>';
        }, $text) ?? $text;

        return $text;
    }

    private static function buildSymbolCoverage(array $modules): array
    {
        $total = count($modules);
        $withSymbols = 0;
        $missing = 0;
        $invalid = 0;

        foreach ($modules as $module) {
            if (($module['identifier'] ?? '') === '000000000000000000000000000000000') {
                $invalid++;
            } elseif ((int) ($module['present'] ?? 0) === 1) {
                $withSymbols++;
            } else {
                $missing++;
            }
        }

        return array(
            'total' => $total,
            'with_symbols' => $withSymbols,
            'missing' => $missing,
            'invalid' => $invalid,
            'percent' => $total > 0 ? (int) round(($withSymbols / $total) * 100) : 0,
        );
    }

    /**
     * @param list<array<string, mixed>> $culpritCandidates
     * @param list<array<string, mixed>> $stack
     * @param list<array<string, mixed>> $modules
     * @return array{matched_modules: int, with_symbols: int, missing: int, invalid: int, percent: int, affected_modules: list<string>, warning: bool}
     */
    private static function buildLikelyCauseSymbolStatus(array $culpritCandidates, array $stack, array $modules): array
    {
        $modulesByBasename = array();
        foreach ($modules as $module) {
            $moduleName = (string) ($module['name'] ?? '');
            $basename = strtolower(basename(str_replace('\\', '/', $moduleName)));
            if ($basename === '' || isset($modulesByBasename[$basename])) {
                continue;
            }

            $modulesByBasename[$basename] = $module;
        }

        $relevantModules = array();
        foreach (array_slice($culpritCandidates, 0, 3) as $candidate) {
            $label = strtolower(trim((string) ($candidate['label'] ?? '')));
            if ($label === '' || str_starts_with($label, 'map: ')) {
                continue;
            }

            $candidateKey = basename(str_replace('\\', '/', $label));
            if ($candidateKey !== '' && isset($modulesByBasename[$candidateKey])) {
                $relevantModules[$candidateKey] = $modulesByBasename[$candidateKey];
            }
        }

        foreach (array_slice($stack, 0, 5) as $frame) {
            $moduleName = (string) ($frame['module'] ?? '');
            $basename = strtolower(basename(str_replace('\\', '/', $moduleName)));
            if ($basename !== '' && isset($modulesByBasename[$basename])) {
                $relevantModules[$basename] = $modulesByBasename[$basename];
            }
        }

        $withSymbols = 0;
        $missing = 0;
        $invalid = 0;
        $affectedModules = array();

        foreach ($relevantModules as $module) {
            $identifier = (string) ($module['identifier'] ?? '');
            $moduleName = basename(str_replace('\\', '/', (string) ($module['name'] ?? '')));

            if ($identifier === '000000000000000000000000000000000') {
                $invalid++;
                continue;
            }

            if ((int) ($module['present'] ?? 0) === 1) {
                $withSymbols++;
                continue;
            }

            $missing++;
            if ($moduleName !== '') {
                $affectedModules[] = $moduleName;
            }
        }

        sort($affectedModules, SORT_NATURAL | SORT_FLAG_CASE);
        $matchedModules = count($relevantModules);

        return array(
            'matched_modules' => $matchedModules,
            'with_symbols' => $withSymbols,
            'missing' => $missing,
            'invalid' => $invalid,
            'percent' => $matchedModules > 0 ? (int) round(($withSymbols / $matchedModules) * 100) : 0,
            'affected_modules' => array_values(array_unique($affectedModules)),
            'warning' => $matchedModules > 0 && $withSymbols < $matchedModules,
        );
    }

    private static function buildModuleCoverageRows(array $modules, array $config = []): array
    {
        foreach ($modules as &$module) {
            $name = (string) ($module['name'] ?? '');
            $identifier = (string) ($module['identifier'] ?? '');
            $basename = basename(str_replace('\\', '/', $name));
            $invalid = $identifier === '000000000000000000000000000000000';
            $present = (int) ($module['present'] ?? 0) === 1;
            $requestedByPolicy = !$invalid && self::shouldRequestSymbolsForModule($name, $config);

            if ($invalid) {
                $module['coverage_status'] = 'Invalid id';
                $module['coverage_class'] = 'warning';
                $module['policy_hint'] = 'Not a code module; symbols are not needed';
            } elseif ($present) {
                $module['coverage_status'] = 'Available';
                $module['coverage_class'] = 'success';
                $module['policy_hint'] = $requestedByPolicy ? 'Allowed by upload policy' : 'Symbols already available';
            } else {
                $module['coverage_status'] = 'Missing';
                $module['coverage_class'] = $requestedByPolicy ? 'danger' : 'muted';
                $module['policy_hint'] = $requestedByPolicy ? 'Allowed by upload policy' : 'Blocked by upload policy';
            }

            if ($invalid || str_ends_with(strtolower($basename), '.mmdb')) {
                $module['usefulness_hint'] = 'No';
            } elseif (self::isSystemRuntimeModule($basename)) {
                $module['usefulness_hint'] = 'Low';
            } elseif ($requestedByPolicy || self::isPluginLikeLabel($basename) || self::isServerEngineModule($basename)) {
                $module['usefulness_hint'] = 'High';
            } else {
                $module['usefulness_hint'] = 'Medium';
            }
        }
        unset($module);

        usort($modules, static function (array $a, array $b): int {
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $modules;
    }

    private static function buildCulpritCandidates(array $stack, array $modules, array $metadata, ?string $cmdline, ?string $consoleBlaming = null, ?array $terminalConsoleCause = null, ?array $rawSourcePawnChain = null): array
    {
        $candidates = array();
        $presentByModule = array();
        foreach ($modules as $module) {
            $moduleName = (string) ($module['name'] ?? '');
            $presentByModule[$moduleName] = (int) $module['present'] === 1;
            $presentByModule[basename(str_replace('\\', '/', $moduleName))] = (int) $module['present'] === 1;
        }

        $sourcePawnChain = $rawSourcePawnChain ?? self::detectSourcePawnCauseChain($stack, $metadata, 'Stack');
        $sourcePawnNativeHelperChain = $sourcePawnChain === null ? self::detectSourcePawnNativeHelperChain($stack, $metadata, 'Stack') : null;
        $hasSourcePawnChain = $sourcePawnChain !== null;
        $hasSourcePawnNativeHelperChain = $sourcePawnNativeHelperChain !== null;
        $hasStrongSourcePawnCause = $hasSourcePawnChain || $hasSourcePawnNativeHelperChain;
        $hasSourcePawnBridge = $hasStrongSourcePawnCause || self::stackHasSourcePawnBridge($stack);
        $hasConsoleBackedBridgeCause = !$hasStrongSourcePawnCause && $consoleBlaming !== null && $hasSourcePawnBridge;
        $hasTerminalConsoleBridgeCause = !$hasStrongSourcePawnCause && $terminalConsoleCause !== null && !empty($terminalConsoleCause['plugin']) && $hasSourcePawnBridge;
        $stackFirstCandidate = self::topConsistentCrashCandidate($stack);
        $stackFirstIsDetourBridge = $stackFirstCandidate !== null
            && self::isPluginLikeLabel((string) $stackFirstCandidate['label'])
            && !str_ends_with(strtolower((string) $stackFirstCandidate['label']), '.smx')
            && self::isDetourCrashFrame((string) ($stackFirstCandidate['function'] ?? ''), (string) ($stackFirstCandidate['rendered'] ?? ''));
        $sourcePawnPlugin = $sourcePawnChain !== null ? basename((string) $sourcePawnChain['plugin']) : null;
        $consoleBlamingInStack = $consoleBlaming !== null && self::stackContainsLabel($stack, basename($consoleBlaming));
        if ($sourcePawnChain !== null) {
            self::addCulpritCandidate(
                $candidates,
                $sourcePawnChain['plugin'],
                $sourcePawnChain['score'] + 80,
                10,
                $sourcePawnChain['reasons'],
                'Likely plugin cause'
            );
        }
        if ($sourcePawnNativeHelperChain !== null) {
            self::addCulpritCandidate(
                $candidates,
                $sourcePawnNativeHelperChain['label'],
                $sourcePawnNativeHelperChain['score'] + 24,
                14,
                $sourcePawnNativeHelperChain['reasons'],
                'Bridge'
            );
        }

        if ($consoleBlaming !== null && ($sourcePawnPlugin === null || strcasecmp(basename($consoleBlaming), $sourcePawnPlugin) === 0)) {
            self::addCulpritCandidate(
                $candidates,
                basename($consoleBlaming),
                $hasStrongSourcePawnCause
                    ? 38
                    : ($consoleBlamingInStack
                        ? ($hasSourcePawnBridge ? 78 : 64)
                        : ($stackFirstIsDetourBridge ? 12 : (($stackFirstCandidate !== null && !$hasTerminalConsoleBridgeCause) ? 20 : ($hasSourcePawnBridge ? 54 : 44)))),
                4,
                array_filter(array(
                    'Console log Blaming entry: ' . basename($consoleBlaming),
                    $hasConsoleBackedBridgeCause ? 'SourceMod bridge frames present; plugin debug frames are missing from stackwalk' : null,
                    !$consoleBlamingInStack ? 'Blamed plugin is not present in the native stack and is treated only as indirect evidence' : null,
                )),
                'Supporting signal'
            );
        }

        if ($terminalConsoleCause !== null && !empty($terminalConsoleCause['plugin']) && ($sourcePawnPlugin === null || strcasecmp(basename((string) $terminalConsoleCause['plugin']), $sourcePawnPlugin) === 0)) {
            $reasons = array('Terminal SourceMod exception block: ' . $terminalConsoleCause['plugin']);
            if (!empty($terminalConsoleCause['function'])) {
                $reasons[] = 'Console callsite: ' . $terminalConsoleCause['function'];
            }
            if (!empty($terminalConsoleCause['exception'])) {
                $reasons[] = 'Console exception: ' . $terminalConsoleCause['exception'];
            }

            self::addCulpritCandidate(
                $candidates,
                basename((string) $terminalConsoleCause['plugin']),
                $hasStrongSourcePawnCause ? 46 : ($hasSourcePawnBridge ? 180 : 130),
                6,
                $reasons,
                $hasStrongSourcePawnCause ? 'Supporting signal' : 'Likely plugin cause'
            );
        }

        if (!$hasStrongSourcePawnCause && $stackFirstCandidate !== null) {
            $failureScore = $stackFirstCandidate['same_prefix_count'] >= 3 ? 220 : 180;
            $rootScore = self::isBridgeFrame(
                (string) ($stackFirstCandidate['module'] ?? ''),
                (string) ($stackFirstCandidate['function'] ?? ''),
                (string) ($stackFirstCandidate['rendered'] ?? ''),
                (string) $stackFirstCandidate['label']
            ) ? 52 : 26;
            if ($stackFirstIsDetourBridge) {
                $rootScore = max($rootScore, 124);
            }
            if (!$hasTerminalConsoleBridgeCause) {
                $rootScore += 8;
            }
            if ($hasSourcePawnBridge) {
                $rootScore = max(12, $rootScore - 10);
            }

            self::addCulpritCandidate(
                $candidates,
                $stackFirstCandidate['label'],
                $rootScore,
                $failureScore,
                array_filter(array(
                    'Frame #' . $stackFirstCandidate['frame'] . ': ' . $stackFirstCandidate['rendered'],
                    $stackFirstCandidate['same_prefix_count'] >= 3 ? 'Top ' . $stackFirstCandidate['same_prefix_count'] . ' frames resolve to the same crash module' : null,
                    $hasConsoleBackedBridgeCause ? 'Older SourceMod console exception kept only as supporting signal' : null,
                )),
                'Native failure site'
            );
        }

        $frameContributionCounts = array();
        foreach ($stack as $index => $frame) {
            $module = (string) ($frame['module'] ?? '');
            $function = (string) ($frame['function'] ?? '');
            $rendered = (string) ($frame['rendered'] ?? '');
            $label = self::candidateLabelFromFrame($module, $function, $rendered);

            if ($label === null) {
                continue;
            }

            $rootScore = max(4, 18 - ($index * 2));
            $failureScore = max(0, 28 - ($index * 3));
            $kind = 'Candidate';
            $reasons = array('Frame #' . ($frame['frame'] ?? $index) . ': ' . $rendered);
            $isSmxLabel = str_ends_with(strtolower($label), '.smx');
            $isBridgeFrame = self::isBridgeFrame($module, $function, $rendered, $label);
            $isNativeHelperFrame = self::isSourcePawnNativeHelperFrame($module, $function, $rendered, $label);
            $isLifecycleFrame = self::isLifecycleCrashFrame($function, $rendered);

            if ($index === 0) {
                if (($hasStrongSourcePawnCause || $hasConsoleBackedBridgeCause || $hasTerminalConsoleBridgeCause) && self::isServerEngineModule($label)) {
                    $failureScore += 20;
                    $rootScore += 4;
                    $kind = 'Native failure site';
                    $reasons[] = $hasStrongSourcePawnCause ? 'Native crash site reached from SourcePawn/JIT chain' : 'Native crash site reached from SourceMod evidence';
                } else {
                    $rootScore += 10;
                    $failureScore += 44;
                }
            }

            if (self::isPluginLikeLabel($label)) {
                if ($isBridgeFrame && !$isSmxLabel) {
                    $rootScore += 36;
                    $failureScore += 8;
                    $kind = 'Bridge';
                    $reasons[] = 'SourceMod bridge frame';
                    if ($isLifecycleFrame) {
                        $rootScore += 40;
                        $reasons[] = 'Bridge frame is adjacent to player/entity removal or lifecycle callback logic';
                    }
                } else {
                    $rootScore += $isSmxLabel ? 88 : 44;
                    $kind = $isSmxLabel ? 'Likely plugin cause' : 'Bridge';
                    $reasons[] = $isSmxLabel ? 'SourceMod plugin frame' : 'SourceMod extension module';
                    if (!$isSmxLabel && self::isDetourCrashFrame($function, $rendered) && $index <= 1) {
                        $rootScore += 96;
                        $failureScore += 18;
                        $reasons[] = 'Top-of-stack native extension detour/callback frame';
                    }
                }
            } elseif ($isNativeHelperFrame && $hasSourcePawnNativeHelperChain) {
                $rootScore += 64;
                $failureScore += 8;
                $kind = 'Bridge';
                $reasons[] = 'SourceMod native memory helper frame';
            } elseif ($isBridgeFrame) {
                $rootScore += 28;
                $failureScore += 6;
                $kind = 'Bridge';
                $reasons[] = 'SourcePawn/JIT bridge frame';
                if ($isLifecycleFrame) {
                    $rootScore += 34;
                    $reasons[] = 'Bridge frame intersects with cleanup/remove/update callback flow';
                }
            }

            if (self::isServerEngineModule($label)) {
                $failureScore += ($hasStrongSourcePawnCause || $hasConsoleBackedBridgeCause || $hasTerminalConsoleBridgeCause) ? 22 : 36;
                $rootScore += ($hasStrongSourcePawnCause || $hasConsoleBackedBridgeCause || $hasTerminalConsoleBridgeCause) ? 2 : 10;
                if ($kind === 'Candidate') {
                    $kind = $index === 0 ? 'Native failure site' : 'Native module';
                }
                $reasons[] = 'Game or Source engine module';
            }

            if (preg_match('/__SourceHook_/i', $rendered) === 1) {
                $rootScore = max(1, $rootScore - 32);
                $failureScore = max(0, $failureScore - 24);
                $kind = 'Bridge';
                $reasons[] = 'Hook frame';
            }

            if (self::isSystemRuntimeModule($label) || self::isBootstrapRuntimeModule($label)) {
                $rootScore = 1;
                $failureScore = min($failureScore, 6);
                $kind = 'Runtime';
                $reasons[] = 'System/runtime module';
            }

            $moduleKey = $module !== '' && isset($presentByModule[$module]) ? $module : basename(str_replace('\\', '/', $module));
            if ($moduleKey !== '' && isset($presentByModule[$moduleKey]) && !$presentByModule[$moduleKey]) {
                $rootScore -= ($hasStrongSourcePawnCause || $hasConsoleBackedBridgeCause || $hasTerminalConsoleBridgeCause) && self::isServerEngineModule($label) ? 6 : 16;
                $reasons[] = 'Module has no symbols';
            }

            if ($stackFirstCandidate !== null && strcasecmp($label, $stackFirstCandidate['label']) === 0 && !$hasStrongSourcePawnCause && !$hasTerminalConsoleBridgeCause) {
                $rootScore += max(4, 14 - ($index * 3));
                $failureScore += max(10, 36 - ($index * 5));
                if ($kind === 'Candidate' || $kind === 'Bridge') {
                    $kind = 'Native failure site';
                }
                $reasons[] = 'Top-of-stack crash module';
            }

            $frameContributionCounts[$label] = ($frameContributionCounts[$label] ?? 0) + 1;
            [$rootScore, $failureScore] = self::applyFrameContributionMultiplier($rootScore, $failureScore, $frameContributionCounts[$label]);

            self::addCulpritCandidate($candidates, $label, $rootScore, $failureScore, $reasons, $kind);

            $sourcePawnFrame = self::parseSourcePawnFrame($rendered);
            if ($sourcePawnFrame !== null) {
                self::addCulpritCandidate($candidates, $sourcePawnFrame['plugin'], $rootScore + 42, 2, array(
                    'SourcePawn function: ' . $sourcePawnFrame['function'],
                    self::isSourcePawnEntryPoint($sourcePawnFrame['function']) ? 'Plugin entry point' : 'Plugin callsite',
                ), self::isSourcePawnEntryPoint($sourcePawnFrame['function']) ? 'Entry point' : 'Likely plugin cause');
            }
        }

        foreach (array('Plugin', 'SourceModPlugin') as $key) {
            if (!empty($metadata[$key]) && is_string($metadata[$key])) {
                self::addCulpritCandidate($candidates, basename($metadata[$key]), 30, 2, 'Crash metadata field: ' . $key, 'Supporting signal');
            }
        }

        foreach (array('Extension', 'SourceModExtension') as $key) {
            if (!empty($metadata[$key]) && is_string($metadata[$key])) {
                self::addCulpritCandidate($candidates, basename($metadata[$key]), 26, 2, 'Crash metadata field: ' . $key, 'Bridge');
            }
        }

        if (is_string($cmdline) && preg_match('/\+map\s+([^ ]+)/', $cmdline, $matches) === 1) {
            self::addCulpritCandidate($candidates, 'Map: ' . $matches[1], 8, 0, 'Active map from command line', 'Context');
        }

        if (empty($candidates)) {
            return array();
        }

        uasort($candidates, function ($a, $b) {
            return ($b['root_cause_score'] <=> $a['root_cause_score'])
                ?: ($b['failure_site_score'] <=> $a['failure_site_score'])
                ?: (self::culpritKindPriority((string) $b['kind']) <=> self::culpritKindPriority((string) $a['kind']));
        });
        $top = array_slice($candidates, 0, 6, true);
        $total = array_sum(array_map(static fn (array $candidate): int => max(1, (int) ($candidate['root_cause_score'] ?? 0)), $top));
        if ($total <= 0) {
            $total = array_sum(array_column($top, 'failure_site_score'));
        }

        return array_map(function ($candidate) use ($total) {
            $displayScore = max(1, (int) ($candidate['root_cause_score'] ?? 0));
            if ($displayScore <= 0) {
                $displayScore = max(1, (int) ($candidate['failure_site_score'] ?? 0));
            }
            $candidate['percent'] = $total > 0 ? (int) round(($displayScore / $total) * 100) : 0;
            $candidate['reasons'] = array_slice(array_values(array_unique($candidate['reasons'])), 0, 4);
            $candidate['kind_class'] = self::culpritKindClass($candidate['kind']);
            $candidate['kind_title'] = self::culpritKindTitle($candidate['kind']);
            $candidate['category'] = self::culpritCategory($candidate['kind']);
            $candidate['category_label'] = self::culpritCategoryLabel($candidate['category']);

            return $candidate;
        }, array_values($top));
    }

    private static function addCulpritCandidate(array &$candidates, string $label, int $rootCauseScore, int $failureSiteScore, string|array $reasons, string $kind = 'Candidate'): void
    {
        $label = trim($label);
        if ($label === '' || ($rootCauseScore <= 0 && $failureSiteScore <= 0)) {
            return;
        }

        if (!isset($candidates[$label])) {
            $candidates[$label] = array(
                'label' => $label,
                'root_cause_score' => 0,
                'failure_site_score' => 0,
                'percent' => 0,
                'kind' => $kind,
                'reasons' => array(),
            );
        }

        $candidates[$label]['root_cause_score'] += max(0, $rootCauseScore);
        $candidates[$label]['failure_site_score'] += max(0, $failureSiteScore);
        if (self::culpritKindPriority($kind) > self::culpritKindPriority((string) $candidates[$label]['kind'])) {
            $candidates[$label]['kind'] = $kind;
        }

        foreach ((array) $reasons as $reason) {
            if (is_string($reason) && trim($reason) !== '') {
                $candidates[$label]['reasons'][] = $reason;
            }
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function applyFrameContributionMultiplier(int $rootScore, int $failureScore, int $occurrence): array
    {
        $multipliers = [1 => 1.0, 2 => 0.65, 3 => 0.4];
        $multiplier = $multipliers[$occurrence] ?? 0.25;

        return [
            (int) round($rootScore * $multiplier),
            (int) round($failureScore * $multiplier),
        ];
    }

    private static function isLifecycleCrashFrame(string $function, string $rendered): bool
    {
        $haystack = $function . ' ' . $rendered;

        return preg_match('/UpdateOnRemove|RemovePlayer|Inactivate|Host_Changelevel|ChangeLevel|UTIL_RemoveImmediate|callback/i', $haystack) === 1;
    }

    private static function stackHasSourcePawnBridge(array $stack): bool
    {
        foreach ($stack as $frame) {
            $module = (string) ($frame['module'] ?? '');
            $function = (string) ($frame['function'] ?? '');
            $rendered = (string) ($frame['rendered'] ?? '');
            $label = self::candidateLabelFromFrame($module, $function, $rendered) ?? '';

            if (self::isBridgeFrame($module, $function, $rendered, $label)) {
                return true;
            }
        }

        return false;
    }

    private static function stackContainsLabel(array $stack, string $label): bool
    {
        foreach ($stack as $frame) {
            $frameLabel = self::candidateLabelFromFrame(
                (string) ($frame['module'] ?? ''),
                (string) ($frame['function'] ?? ''),
                (string) ($frame['rendered'] ?? '')
            );

            if ($frameLabel !== null && strcasecmp(basename($frameLabel), basename($label)) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function topConsistentCrashCandidate(array $stack): ?array
    {
        $prefix = [];
        foreach ($stack as $frame) {
            if (count($prefix) >= 3) {
                break;
            }

            $module = (string) ($frame['module'] ?? '');
            $function = (string) ($frame['function'] ?? '');
            $rendered = (string) ($frame['rendered'] ?? '');
            $label = self::candidateLabelFromFrame($module, $function, $rendered);
            if ($label === null || str_ends_with(strtolower($label), '.smx')) {
                break;
            }

            if (self::isSystemRuntimeModule($label) || self::isBootstrapRuntimeModule($label)) {
                break;
            }

            $prefix[] = [
                'label' => $label,
                'module' => $module,
                'function' => $function,
                'rendered' => $rendered,
                'frame' => $frame['frame'] ?? count($prefix),
            ];
        }

        if ($prefix === []) {
            return null;
        }

        $firstLabel = $prefix[0]['label'];
        $samePrefixCount = 0;
        foreach ($prefix as $item) {
            if (strcasecmp($item['label'], $firstLabel) !== 0) {
                break;
            }
            $samePrefixCount++;
        }

        if ($samePrefixCount < 2 && !self::isPluginLikeLabel($firstLabel) && !self::isServerEngineModule($firstLabel)) {
            return null;
        }

        $first = $prefix[0];
        $first['same_prefix_count'] = $samePrefixCount;

        return $first;
    }

    private static function detectSourcePawnCauseChain(array $stack, array $metadata, string $sourceLabel): ?array
    {
        $sourcePawnFrames = array();
        $sdkCallFrames = array();
        $bridgeFrames = array();

        foreach ($stack as $index => $frame) {
            $module = (string) ($frame['module'] ?? '');
            $function = (string) ($frame['function'] ?? '');
            $rendered = (string) ($frame['rendered'] ?? '');
            $sourcePawnFrame = self::parseSourcePawnFrame($rendered);

            if ($sourcePawnFrame !== null) {
                $sourcePawnFrame['index'] = $index;
                $sourcePawnFrame['frame'] = $frame['frame'] ?? $index;
                $sourcePawnFrame['rendered'] = $rendered;
                $sourcePawnFrames[] = $sourcePawnFrame;
            }

            if (preg_match('/\bSDKCall\b/i', $rendered . ' ' . $function) === 1) {
                $sdkCallFrames[] = array('index' => $index, 'frame' => $frame['frame'] ?? $index, 'rendered' => $rendered);
            }

            if (self::isBridgeFrame($module, $function, $rendered, self::candidateLabelFromFrame($module, $function, $rendered) ?? '')) {
                $bridgeFrames[] = array('index' => $index, 'frame' => $frame['frame'] ?? $index, 'rendered' => $rendered);
            }
        }

        if (empty($sourcePawnFrames)) {
            return null;
        }

        $anchorIndex = !empty($sdkCallFrames) ? (int) $sdkCallFrames[0]['index'] : (!empty($bridgeFrames) ? (int) $bridgeFrames[0]['index'] : 0);
        $callsite = null;
        foreach ($sourcePawnFrames as $sourcePawnFrame) {
            if ($sourcePawnFrame['index'] >= $anchorIndex) {
                $callsite = $sourcePawnFrame;
                break;
            }
        }
        $callsite ??= $sourcePawnFrames[0];

        $entryPoint = null;
        foreach ($sourcePawnFrames as $sourcePawnFrame) {
            if ($sourcePawnFrame['plugin'] !== $callsite['plugin'] || $sourcePawnFrame['index'] < $callsite['index']) {
                continue;
            }

            if (self::isSourcePawnEntryPoint($sourcePawnFrame['function'])) {
                $entryPoint = $sourcePawnFrame;
            }
        }

        $nativeSite = self::topNativeFailureSite($stack);
        $score = 150;
        $reasons = array(
            $sourceLabel . ' callsite frame #' . $callsite['frame'] . ': ' . $callsite['plugin'] . '::' . $callsite['function'],
        );

        if (!empty($sdkCallFrames)) {
            $score += 44;
            $reasons[] = $sourceLabel . ' bridge frame #' . $sdkCallFrames[0]['frame'] . ': SDKCall';
        } elseif (!empty($bridgeFrames)) {
            $score += 24;
            $reasons[] = $sourceLabel . ' bridge frame #' . $bridgeFrames[0]['frame'] . ': SourcePawn/JIT bridge';
        }

        if ($entryPoint !== null) {
            $score += 28;
            $reasons[] = $sourceLabel . ' entry frame #' . $entryPoint['frame'] . ': ' . $entryPoint['function'];
        }

        if ($nativeSite !== null) {
            $score += 18;
            $reasons[] = 'Native failure site: ' . $nativeSite;
        }

        if (self::metadataIndicatesInvalidPointer($metadata)) {
            $score += 12;
            $reasons[] = 'Invalid low-address pointer access';
        }

        return array(
            'plugin' => $callsite['plugin'],
            'score' => $score,
            'reasons' => $reasons,
        );
    }

    private static function detectSourcePawnNativeHelperChain(array $stack, array $metadata, string $sourceLabel): ?array
    {
        $helperFrames = array();
        $invocationFrames = array();
        $startupFrames = array();

        foreach ($stack as $index => $frame) {
            $module = (string) ($frame['module'] ?? '');
            $function = (string) ($frame['function'] ?? '');
            $rendered = (string) ($frame['rendered'] ?? '');
            $label = self::candidateLabelFromFrame($module, $function, $rendered) ?? '';

            if (self::isSourcePawnNativeHelperFrame($module, $function, $rendered, $label)) {
                $helperFrames[] = array(
                    'index' => $index,
                    'frame' => $frame['frame'] ?? $index,
                    'module' => $module,
                    'function' => $function,
                    'rendered' => $rendered,
                    'label' => $label,
                );
            }

            if (self::isSourcePawnNativeInvocationFrame($module, $function, $rendered, $label)) {
                $invocationFrames[] = array(
                    'index' => $index,
                    'frame' => $frame['frame'] ?? $index,
                    'rendered' => $rendered,
                );
            }

            if (self::isSourcePawnStartupBridgeFrame($module, $function, $rendered, $label)) {
                $startupFrames[] = array(
                    'index' => $index,
                    'frame' => $frame['frame'] ?? $index,
                    'rendered' => $rendered,
                );
            }
        }

        if ($helperFrames === [] || $invocationFrames === []) {
            return null;
        }

        $anchor = $helperFrames[0];
        $label = $anchor['label'] !== '' ? basename((string) $anchor['label']) : basename((string) $anchor['module']);
        if ($label === '') {
            $label = 'sourcemod.logic.so';
        }

        $reasons = array();
        if ((int) $anchor['index'] === 0) {
            $reasons[] = 'Top frame is SourceMod native memory helper';
        } else {
            $reasons[] = $sourceLabel . ' helper frame #' . $anchor['frame'] . ': ' . ($anchor['rendered'] !== '' ? $anchor['rendered'] : $label);
        }

        $reasons[] = 'SourcePawn/SourceMod native invocation chain present';

        if ($startupFrames !== []) {
            $reasons[] = $sourceLabel . ' startup frame #' . $startupFrames[0]['frame'] . ': ' . $startupFrames[0]['rendered'];
        }

        $engineAfterHelper = false;
        foreach ($stack as $frame) {
            $frameLabel = self::candidateLabelFromFrame((string) ($frame['module'] ?? ''), (string) ($frame['function'] ?? ''), (string) ($frame['rendered'] ?? ''));
            if ($frameLabel !== null && self::isServerEngineModule($frameLabel)) {
                $engineAfterHelper = true;
                break;
            }
        }
        if ($engineAfterHelper) {
            $reasons[] = 'Engine frames appear only after plugin/native bridge execution';
        }

        $score = 188;
        if (self::metadataIndicatesInvalidPointer($metadata)) {
            $score += 12;
            $reasons[] = 'Invalid low-address pointer access';
        }

        return array(
            'label' => $label,
            'score' => $score,
            'reasons' => $reasons,
        );
    }

    private static function loadRawSourcePawnCauseChain(Application $app, string $id, array $stack, array $metadata): ?array
    {
        if (self::detectSourcePawnCauseChain($stack, $metadata, 'Stack') !== null || !self::stackHasSourcePawnBridge($stack)) {
            return null;
        }

        $cachePath = $app['root'] . '/cache/carburetor-likely/' . substr($id, 0, 2) . '/' . $id . '.json.gz';
        if (\Filesystem::pathExists($cachePath)) {
            $cached = json_decode((string) gzdecode(\Filesystem::readFile($cachePath)), true);
            if (is_array($cached) && isset($cached['plugin'], $cached['score'], $cached['reasons'])) {
                return $cached;
            }
        }

        $dumpPath = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.dmp';
        $carburetorPath = $app['root'] . '/bin/carburetor';
        if (!\Filesystem::pathExists($dumpPath) || !\Filesystem::pathExists($carburetorPath)) {
            return null;
        }

        $symbolStores = array();
        foreach ((array) ($app['config']['symbol-stores'] ?? array()) as $store) {
            $symbolStores[] = $app['root'] . '/symbols/' . $store;
        }
        array_unshift($symbolStores, $app['root'] . '/cache/symbols');

        try {
            $carburetor = self::runCarburetor($app, $dumpPath, $symbolStores, 20);
            $data = json_decode($carburetor['stdout'], true);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $rawStack = self::stackRowsFromCarburetorData($data);
        if (empty($rawStack)) {
            return null;
        }

        $chain = self::detectSourcePawnCauseChain($rawStack, $metadata, 'Raw stack');
        if ($chain !== null) {
            \Filesystem::writeFile($cachePath, gzencode(json_encode($chain, JSON_UNESCAPED_SLASHES)));
        }

        return $chain;
    }

    private static function runCarburetor(Application $app, string $dumpPath, array $symbolStores, ?int $timeout = null, array $options = array()): array
    {
        $carburetorPath = $app['root'] . '/bin/carburetor';
        $future = new \ExecFuture('%s %Ls %s %Ls', $carburetorPath, $options, $dumpPath, $symbolStores);
        if ($timeout !== null) {
            $future->setTimeout($timeout);
        }

        try {
            [$stdout, $stderr] = $future->resolvex();
            $exitCode = 0;
        } catch (\CommandException $exception) {
            $stdout = $exception->getStdout();
            $stderr = $exception->getStderr();
            $exitCode = (int) $exception->getCode();
        }

        return array(
            'stdout' => $stdout ?? '',
            'stderr' => $stderr ?? '',
            'exit_code' => $exitCode ?? 0,
            'stderr_tail' => self::stderrTail($stderr ?? ''),
            'error_context' => self::extractCarburetorFailureContext($stderr ?? ''),
            'error' => $exitCode ? self::carburetorFailureMessage($exitCode, $dumpPath) : null,
        );
    }

    private static function carburetorFailureMessage(int $exitCode, string $dumpPath): string
    {
        $signal = self::exitCodeToSignalName($exitCode);
        if ($signal !== null) {
            return sprintf('carburetor crashed while analyzing %s (%s)', basename($dumpPath), $signal);
        }

        return sprintf('carburetor failed while analyzing %s (exit code %d)', basename($dumpPath), $exitCode);
    }

    private static function exitCodeToSignalName(int $exitCode): ?string
    {
        return match ($exitCode) {
            134 => 'SIGABRT',
            135 => 'SIGBUS',
            136 => 'SIGFPE',
            137 => 'SIGKILL',
            138 => 'SIGUSR1',
            139 => 'SIGSEGV',
            140 => 'SIGUSR2',
            141 => 'SIGPIPE',
            142 => 'SIGALRM',
            143 => 'SIGTERM',
            default => null,
        };
    }

    private static function stderrTail(string $stderr, int $maxLines = 20): string
    {
        $lines = \phutil_split_lines($stderr, false);
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        return trim(implode("\n", $lines));
    }

    private static function extractCarburetorFailureContext(string $stderr): ?array
    {
        if ($stderr === '') {
            return null;
        }

        $lines = \phutil_split_lines($stderr, false);
        $currentThread = null;
        $issues = array();

        foreach ($lines as $line) {
            if (preg_match('/Looking at thread .*:(\d+\/\d+) id (0x[0-9a-f]+)/i', $line, $matches) === 1) {
                $currentThread = sprintf('thread %s id %s', $matches[1], strtolower($matches[2]));
                continue;
            }

            if (preg_match('/MinidumpMemoryRegion request out of range:\s*(.+)$/', $line, $matches) === 1) {
                $issues[] = array(
                    'thread' => $currentThread,
                    'message' => trim($matches[1]),
                );
            }
        }

        if ($issues === array()) {
            return null;
        }

        $rendered = array();
        foreach (array_slice($issues, 0, 3) as $issue) {
            $rendered[] = ($issue['thread'] !== null ? $issue['thread'] . ': ' : '') . $issue['message'];
        }

        return array(
            'summary' => 'Likely carburetor trigger: out-of-range minidump memory while serializing processed thread state.',
            'lines' => $rendered,
        );
    }

    private static function loadProcessedFallbackStack(Application $app, string $id): ?array
    {
        $crash = $app['db']->executeQuery('SELECT thread, processed FROM crash WHERE id = ? LIMIT 1', array($id))->fetch();
        if (!is_array($crash) || (int) ($crash['processed'] ?? 0) !== 1) {
            return null;
        }

        $thread = $crash['thread'] ?? null;
        if ($thread === null || $thread === false) {
            return null;
        }

        if ((int) $thread === -1) {
            $thread = 0;
        }

        $stack = $app['db']->executeQuery(
            'SELECT frame, module, `function`, rendered, url FROM frame WHERE crash = ? AND thread = ? ORDER BY frame',
            array($id, $thread)
        )->fetchAll();

        return $stack !== array() ? $stack : null;
    }

    /**
     * @return array<string, array{module_basename: string, module_identifier: ?string, has_cache: bool, has_mapping: bool}>
     */
    private static function loadSourceLookupModuleState(Application $app, string $id): array
    {
        if (($app['config']['upload-settings']['crash_source_lookup_enabled'] ?? false) !== true) {
            return array();
        }

        $rows = $app['db']->executeQuery(
            'SELECT name, identifier FROM module WHERE crash = ? ORDER BY name',
            array($id)
        )->fetchAll();

        if (!is_array($rows) || $rows === array()) {
            return array();
        }

        $state = array();
        $invalidIdentifier = '000000000000000000000000000000000';

        foreach ($rows as $row) {
            $name = isset($row['name']) ? (string) $row['name'] : '';
            $identifier = isset($row['identifier']) ? (string) $row['identifier'] : '';
            $basename = self::getSymbolModuleName($name);
            if ($basename === '') {
                continue;
            }

            $identifier = ($identifier !== '' && $identifier !== $invalidIdentifier) ? $identifier : null;
            $key = mb_strtolower($basename);
            if (isset($state[$key]) && $state[$key]['module_identifier'] !== null) {
                continue;
            }

            $hasMapping = false;
            $hasCache = false;
            if ($identifier !== null) {
                $hasMapping = (bool) $app['db']->executeQuery(
                    'SELECT 1 FROM source_lookup_mapping WHERE module_basename = ? AND module_identifier = ? LIMIT 1',
                    array($basename, $identifier)
                )->fetchColumn(0);
                $hasCache = (bool) $app['db']->executeQuery(
                    'SELECT 1 FROM source_lookup_cache WHERE module_basename = ? AND module_identifier = ? LIMIT 1',
                    array($basename, $identifier)
                )->fetchColumn(0);
            }

            $state[$key] = array(
                'module_basename' => $basename,
                'module_identifier' => $identifier,
                'has_cache' => $hasCache,
                'has_mapping' => $hasMapping,
            );
        }

        return $state;
    }

    /**
     * @param list<array<string, mixed>> $stack
     * @return array<string, array<string, mixed>>
     */
    private static function loadVerifiedSourceLookupFrameState(Application $app, string $id, array $stack): array
    {
        if (($app['config']['upload-settings']['crash_source_lookup_enabled'] ?? false) !== true || $stack === array()) {
            return array();
        }

        try {
            $connection = $app['db'] instanceof LegacyDbalConnection ? $app['db']->getWrappedConnection() : $app['db'];
            if (!$connection instanceof \Doctrine\DBAL\Connection) {
                return array();
            }

            $manager = new CrashSourceLookupManager($connection, HttpClient::create(), $app['root']);

            return $manager->describeVerifiedFrameCachesForCrash($id, $stack);
        } catch (\Throwable) {
            return array();
        }
    }

    /**
     * @param list<array<string, mixed>> $stack
     * @return array<string, mixed>|null
     */
    private static function buildLoadFromAddressNotice(array $stack): ?array
    {
        if (!isset($stack[0]) || !is_array($stack[0])) {
            return null;
        }

        $frame = $stack[0];
        $module = self::getSymbolModuleName((string) ($frame['module'] ?? ''));
        $rendered = (string) ($frame['rendered'] ?? '');
        $function = (string) ($frame['function'] ?? '');
        $signature = trim($rendered !== '' ? $rendered : $function);

        if ($module !== 'sourcemod.logic.so') {
            return null;
        }

        if (!str_contains($signature, 'LoadFromAddress(SourcePawn::IPluginContext*, int const*)')) {
            return null;
        }

        return array(
            'severity' => 'warning',
            'text' => 'LoadFromAddress crash. Likely causes: invalid or stale memory address passed into <code>LoadFromAddress</code>; outdated gamedata or offsets after a game or extension update; plugin bug in manual memory reads or address arithmetic.',
        );
    }

    private static function stackRowsFromCarburetorData(array $data): array
    {
        $threadIndex = isset($data['requesting_thread']) ? (int) $data['requesting_thread'] : 0;
        $threads = $data['threads'] ?? null;
        if (!is_array($threads) || !isset($threads[$threadIndex]) || !is_array($threads[$threadIndex])) {
            return array();
        }

        $rows = array();
        foreach ($threads[$threadIndex] as $index => $frame) {
            if (!is_array($frame)) {
                continue;
            }

            $rendered = (string) ($frame['rendered'] ?? '');
            if ($rendered === '') {
                continue;
            }

            $module = '';
            $function = '';
            if (preg_match('/^([^!+ ]+)!([^+]+?)(?:\s+\+|$)/', $rendered, $matches) === 1) {
                $module = trim($matches[1]);
                $function = trim($matches[2]);
            } elseif (preg_match('/^([^ +]+)\s+\+/', $rendered, $matches) === 1) {
                $module = trim($matches[1]);
            }

            $rows[] = array(
                'frame' => $index,
                'module' => $module,
                'function' => $function,
                'rendered' => $rendered,
            );
        }

        return $rows;
    }

    private static function parseSourcePawnFrame(string $rendered): ?array
    {
        if (preg_match('/\[\s*([^\\[\\]]+\\.smx)::([^\\[\\]]+)\s*\]/i', $rendered, $matches) !== 1) {
            return null;
        }

        return array(
            'plugin' => basename(trim($matches[1])),
            'function' => self::normalizeSourcePawnFunction($matches[2]),
        );
    }

    private static function normalizeSourcePawnFunction(string $function): string
    {
        $function = trim($function);
        $function = preg_replace('/^\.\d+\./', '', $function) ?? $function;

        return $function;
    }

    private static function isSourcePawnEntryPoint(string $function): bool
    {
        return preg_match('/^(?:Command_|On[A-Z0-9_]|Event_|Timer_|Hook_|Native_)/', $function) === 1;
    }

    private static function isBridgeFrame(string $module, string $function, string $rendered, string $label): bool
    {
        $haystack = strtolower($module . ' ' . $function . ' ' . $rendered . ' ' . $label);

        return str_contains($haystack, 'sdkcall')
            || str_contains($haystack, 'callwrapper::execute')
            || str_contains($haystack, 'sourcepawn.jit')
            || str_contains($haystack, 'jit_code_')
            || str_contains($haystack, 'bintools.ext')
            || str_contains($haystack, 'sdktools.ext')
            || str_contains($haystack, 'sourcemod.');
    }

    private static function isDetourCrashFrame(string $function, string $rendered): bool
    {
        return preg_match('/Detour_|callback\(|callback$|Hook_|__SourceHook_/i', $function . ' ' . $rendered) === 1;
    }

    private static function isSourcePawnNativeHelperFrame(string $module, string $function, string $rendered, string $label): bool
    {
        $haystack = strtolower($module . ' ' . $function . ' ' . $rendered . ' ' . $label);

        return str_contains($haystack, 'loadfromaddress(')
            || str_contains($haystack, 'storetoaddress(')
            || str_contains($haystack, 'copyfromaddress(')
            || str_contains($haystack, 'copytoaddress(')
            || str_contains($haystack, 'fakenativerouter(');
    }

    private static function isSourcePawnNativeInvocationFrame(string $module, string $function, string $rendered, string $label): bool
    {
        $haystack = strtolower($module . ' ' . $function . ' ' . $rendered . ' ' . $label);

        return str_contains($haystack, 'fakenativerouter(')
            || str_contains($haystack, 'interpreter::invokenative')
            || str_contains($haystack, 'interpreter::visitsysreq_n')
            || str_contains($haystack, 'scriptedinvoker::invoke')
            || str_contains($haystack, 'scriptedinvoker::execute')
            || str_contains($haystack, 'plugincontext::invoke')
            || str_contains($haystack, 'environment::invoke')
            || str_contains($haystack, 'loadfromaddress(')
            || str_contains($haystack, 'storetoaddress(');
    }

    private static function isSourcePawnStartupBridgeFrame(string $module, string $function, string $rendered, string $label): bool
    {
        $haystack = strtolower($module . ' ' . $function . ' ' . $rendered . ' ' . $label);

        return str_contains($haystack, 'cpluginmanager::allpluginsloaded')
            || str_contains($haystack, 'doglobalpluginloads')
            || str_contains($haystack, 'levelinit(');
    }

    private static function topNativeFailureSite(array $stack): ?string
    {
        if (!isset($stack[0])) {
            return null;
        }

        $module = (string) ($stack[0]['module'] ?? '');
        $function = (string) ($stack[0]['function'] ?? '');
        $rendered = (string) ($stack[0]['rendered'] ?? '');
        $label = self::candidateLabelFromFrame($module, $function, $rendered);

        if ($label === null || (!self::isServerEngineModule($label) && !self::isSystemRuntimeModule($label))) {
            return null;
        }

        return $rendered !== '' ? $rendered : $label;
    }

    private static function metadataIndicatesInvalidPointer(array $metadata): bool
    {
        $reason = '';
        foreach (array('CrashReason', 'Crash Reason', 'crash_reason', 'Exception', 'Signal') as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key])) {
                $reason .= ' ' . $metadata[$key];
            }
        }

        $address = null;
        foreach (array('CrashAddress', 'Crash Address', 'crash_address', 'Address') as $key) {
            if (!isset($metadata[$key])) {
                continue;
            }

            $value = (string) $metadata[$key];
            if (preg_match('/0x([0-9a-f]+)/i', $value, $matches) === 1) {
                $address = hexdec($matches[1]);
                break;
            }

            if (ctype_digit($value)) {
                $address = (int) $value;
                break;
            }
        }

        return preg_match('/SIGSEGV|SEGV_MAPERR|access/i', $reason) === 1 && $address !== null && $address >= 0 && $address <= 4096;
    }

    private static function culpritKindPriority(string $kind): int
    {
        return match ($kind) {
            'Likely plugin cause' => 90,
            'Native failure site' => 70,
            'Entry point' => 60,
            'Bridge' => 40,
            'Supporting signal' => 30,
            'Native module' => 25,
            'Runtime' => 10,
            'Context' => 5,
            default => 20,
        };
    }

    private static function culpritKindClass(string $kind): string
    {
        return match ($kind) {
            'Likely plugin cause' => 'label-success',
            'Native failure site' => 'label-important',
            'Entry point' => 'label-warning',
            'Bridge' => 'label-info',
            'Runtime' => 'label-inverse',
            default => 'label-default',
        };
    }

    private static function culpritKindTitle(string $kind): string
    {
        return match ($kind) {
            'Likely plugin cause' => 'Most likely user-controlled cause. Prefer raw stack SourcePawn/JIT frames; terminal console exceptions are secondary evidence.',
            'Native failure site' => 'Native module where execution faulted. It may be where the crash happened, not the original cause.',
            'Entry point' => 'Plugin command, callback, timer, hook, or event that started the relevant SourcePawn path.',
            'Bridge' => 'Glue frame between SourcePawn/SourceMod and native engine code, such as SDKCall, bintools, or JIT.',
            'Supporting signal' => 'Extra evidence from metadata or logs that supports another candidate.',
            'Native module' => 'Engine or game binary involved in the stack, but less likely to be the root cause when plugin/bridge evidence exists.',
            'Runtime' => 'System/runtime library frame. Usually noise unless the crash frame is clearly inside it.',
            'Context' => 'Contextual information, not a direct crash cause.',
            default => 'Candidate produced by stack, symbol, metadata, or log heuristics.',
        };
    }

    private static function culpritCategory(string $kind): string
    {
        return match ($kind) {
            'Likely plugin cause', 'Entry point' => 'plugin',
            'Bridge' => 'bridge',
            'Native failure site', 'Native module', 'Runtime' => 'native',
            default => $kind === 'Context' ? 'context' : 'context',
        };
    }

    private static function culpritCategoryLabel(string $category): string
    {
        return match ($category) {
            'plugin' => 'Plugin chain',
            'bridge' => 'Extensions / bridges',
            'native' => 'Native failure site',
            default => 'Context',
        };
    }

    private static function groupCulpritCandidates(array $candidates): array
    {
        $groups = array(
            'plugin' => array('key' => 'plugin', 'label' => self::culpritCategoryLabel('plugin'), 'candidates' => array()),
            'bridge' => array('key' => 'bridge', 'label' => self::culpritCategoryLabel('bridge'), 'candidates' => array()),
            'native' => array('key' => 'native', 'label' => self::culpritCategoryLabel('native'), 'candidates' => array()),
            'context' => array('key' => 'context', 'label' => self::culpritCategoryLabel('context'), 'candidates' => array()),
        );

        foreach (array_slice($candidates, 1) as $candidate) {
            $category = (string) ($candidate['category'] ?? 'context');
            if (!isset($groups[$category])) {
                $category = 'context';
            }
            $groups[$category]['candidates'][] = $candidate;
        }

        return array_values(array_filter($groups, static fn (array $group): bool => !empty($group['candidates'])));
    }

    private static function candidateLabelFromFrame(string $module, string $function, string $rendered): ?string
    {
        if (preg_match('/\[\s*([^\\[\\]]+\\.smx)::/i', $rendered, $matches) === 1) {
            return $matches[1];
        }

        if ($module !== '') {
            return basename($module);
        }

        if ($function !== '') {
            return $function;
        }

        return null;
    }

    private static function isPluginLikeLabel(string $label): bool
    {
        return preg_match('/\.(?:smx|ext(?:\.[^ ]+)?\.so)$/i', $label) === 1 || str_contains(strtolower($label), '.ext.');
    }

    private static function isServerEngineModule(string $label): bool
    {
        return preg_match('/^(?:server_srv|engine_srv|dedicated_srv|datacache_srv|materialsystem_srv|studiorender_srv|vphysics_srv|vscript_srv|soundemittersystem_srv|shaderapiempty_srv|libtier0_srv|libvstdlib_srv)\.so$/i', basename($label)) === 1;
    }

    private static function isSystemRuntimeModule(string $label): bool
    {
        return preg_match('/^(?:ld-linux|linux-gate|steamclient\.so|libc\.so|libstdc\+\+|libgcc_s|libm\.so|libpthread|libdl|librt|libcurl|libcrypto|libssl|libgnutls|libgssapi|libkrb5|libk5crypto|libkrb5support|libldap|liblber|libssh|libsasl|libz\.so|libzstd|libbrotli|libnghttp2|libpsl|libidn|libunistring|libnettle|libhogweed|libtasn1|libp11-kit|libgmp|libffi|libresolv|libcom_err|libkeyutils|librtmp)/i', basename($label)) === 1;
    }

    private static function isBootstrapRuntimeModule(string $label): bool
    {
        return preg_match('/^(?:srcds_linux|hl2_linux|srcds_run)$/i', basename($label)) === 1;
    }

    private static function stripAnsiEscapeSequences(string $value): string
    {
        return preg_replace('/\x1b\[[0-9;?]*[ -\/]*[@-~]/', '', $value) ?? $value;
    }

    private static function classifyConsoleLine(string $message, ?string $activeGroup): array
    {
        $lower = strtolower($message);
        $isSourceModCrashLine = preg_match('/\[SM\]\s+(?:Exception reported|Blaming|Call stack trace:|\[\d+\])/i', $message) === 1;

        if (preg_match('/\[SM\]\s+Exception reported/i', $message) === 1) {
            return array('danger', 'sourcemod-exception');
        }

        if ($activeGroup === 'sourcemod-exception' && $isSourceModCrashLine) {
            return array('danger', 'sourcemod-exception');
        }

        if (preg_match('/\b(error|exception|crash|segmentation fault|fatal|assert|failed)\b/i', $message) === 1) {
            return array('danger', null);
        }

        if (preg_match('/\b(warning|missing|invalid|timeout|unknown command)\b/i', $message) === 1) {
            return array('warning', null);
        }

        if (preg_match('/\b(loaded|started|map)\b/i', $message) === 1 || preg_match('/\[SM\].*\bplugin\b/i', $message) === 1) {
            return array('info', null);
        }

        return array('', null);
    }

    private static function extractSourceModSnapshots(array &$metadata): array
    {
        $snapshots = array('plugins' => null, 'extensions' => null);
        $pluginKeys = array('SourceModPlugins', 'SourceModPluginList', 'SMPlugins', 'PluginsSnapshot');
        $extensionKeys = array('SourceModExtensions', 'SourceModExtensionList', 'SMExtensions', 'ExtensionsSnapshot');

        foreach ($pluginKeys as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key]) && trim($metadata[$key]) !== '') {
                $snapshots['plugins'] = self::normalizeSnapshotText($metadata[$key]);
                unset($metadata[$key]);
                break;
            }
        }

        foreach ($extensionKeys as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key]) && trim($metadata[$key]) !== '') {
                $snapshots['extensions'] = self::normalizeSnapshotText($metadata[$key]);
                unset($metadata[$key]);
                break;
            }
        }

        return $snapshots;
    }

    private static function normalizeSnapshotText(string $text): string
    {
        $text = str_replace(array('\\r\\n', '\\n', "\r\n", "\r"), "\n", $text);

        return trim($text);
    }

    /**
     * @param array<string, mixed> $crash
     * @return array<string, mixed>
     */
    private static function buildCrashDiagnosticContext(Application $app, string $id, array $crash): array
    {
        if ($crash['thread'] == -1) {
            $crash['thread'] = 0;
        }

        $crash['cmdline'] = self::sanitizeCrashCommandLine((string) $crash['cmdline']);
        $crash['metadata'] = json_decode((string) $crash['metadata'], true);
        if (!is_array($crash['metadata'])) {
            $crash['metadata'] = [];
        }

        $snapshots = self::extractSourceModSnapshots($crash['metadata']);

        if (isset($crash['metadata']['HasConsoleLog'])) {
            $crash['has_console_log'] = $crash['metadata']['HasConsoleLog'];
            unset($crash['metadata']['HasConsoleLog']);
        } else {
            $crash['has_console_log'] = false;
        }

        if (isset($crash['metadata']['ExtensionBuild'])) {
            unset($crash['metadata']['ExtensionBuild']);
        }

        $crash['signature_ignored'] = (int) ($crash['signature_ignored'] ?? 0) === 1;
        $crash['summary_only_reason'] = self::buildIgnoredSignatureSummaryReason($crash);

        $consoleCause = self::loadTerminalSourceModCause($app, $id, (bool) $crash['has_console_log']);
        $terminalConsoleCause = ($consoleCause['terminal'] ?? false) ? $consoleCause : null;
        $consoleBlaming = $consoleCause['supporting_blaming'] ?? ($terminalConsoleCause['blaming'] ?? null);
        $crash['dump_available'] = \Filesystem::pathExists($app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.dmp');
        $crash['retention'] = StorageRetentionManager::getCrashArtifactStatus($app['root'], $id);
        $crash['owner_kind'] = ($crash['owner_kind'] ?? null) !== null ? (string) $crash['owner_kind'] : null;
        $crash['owner_profile_id'] = ($crash['owner_kind'] ?? null) === 'user' && ($crash['owner'] ?? null) !== null ? (int) $crash['owner'] : null;

        ksort($crash['metadata']);

        $notices = $app['db']->executeQuery('SELECT severity, text FROM crashnotice JOIN notice ON notice.id = crashnotice.notice WHERE crash = ?', [$id])->fetchAll();
        $stack = $app['db']->executeQuery('SELECT frame, module, `function`, rendered, url FROM frame WHERE crash = ? AND thread = ? ORDER BY frame', [$id, $crash['thread']])->fetchAll();
        $modules = $app['db']->executeQuery('SELECT name, identifier, processed, present, HEX(base) AS base FROM module WHERE crash = ? ORDER BY name', [$id])->fetchAll();
        $modules = self::buildModuleCoverageRows($modules, $app['config']);
        $reprocessPendingModules = array();
        foreach ($modules as &$module) {
            $module['symbol_retention'] = StorageRetentionManager::getSymbolRetentionStatus($app['root'], (string) ($module['name'] ?? ''), (string) ($module['identifier'] ?? ''));
            $module['binary_retention'] = StorageRetentionManager::getBinaryRetentionStatus($app['root'], (string) ($module['name'] ?? ''), (string) ($module['identifier'] ?? ''));
            if (($module['symbol_retention']['deleted'] ?? false) === true) {
                $module['policy_hint'] = trim((string) ($module['policy_hint'] ?? '') . ' Deleted by cleanup.');
            } elseif (($module['binary_retention']['deleted'] ?? false) === true && (int) ($module['present'] ?? 0) !== 1) {
                $module['policy_hint'] = trim((string) ($module['policy_hint'] ?? '') . ' Binary was deleted by cleanup.');
            }
            if ((int) ($module['present'] ?? 0) === 1 && (int) ($module['processed'] ?? 0) === 0) {
                $reprocessPendingModules[] = basename(str_replace('\\', '/', (string) ($module['name'] ?? '')));
            }
        }
        unset($module);
        $reprocessPendingModules = array_values(array_unique(array_filter($reprocessPendingModules, static fn ($name): bool => is_string($name) && $name !== '')));
        sort($reprocessPendingModules, SORT_NATURAL | SORT_FLAG_CASE);
        $crash['reprocess_pending'] = count($reprocessPendingModules) > 0;
        $crash['reprocess_pending_module_count'] = count($reprocessPendingModules);
        $crash['reprocess_pending_modules'] = $reprocessPendingModules;
        $loadFromAddressNotice = self::buildLoadFromAddressNotice($stack);
        if ($loadFromAddressNotice !== null) {
            array_unshift($notices, $loadFromAddressNotice);
        }

        if ($crash['signature_ignored']) {
            $crash['dump_available'] = false;
            $crash['has_console_log'] = false;
            $crash['retention']['available'] = false;
            $crash['retention']['deleted'] = false;
            $crash['retention']['reason'] = 'ignored-signature';
            $processingLog = null;
            array_unshift($notices, [
                'severity' => 'warning',
                'text' => self::buildIgnoredSignatureNoticeText($crash),
            ]);
        }

        $verifiedSourceLookupFrames = self::loadVerifiedSourceLookupFrameState($app, $id, $stack);
        $rawSourcePawnChain = self::loadRawSourcePawnCauseChain($app, $id, $stack, $crash['metadata']);
        $culpritCandidates = self::buildCulpritCandidates($stack, $modules, $crash['metadata'], $crash['cmdline'], $consoleBlaming, $terminalConsoleCause, $rawSourcePawnChain);
        $stats = $app['db']->executeQuery('SELECT COUNT(DISTINCT crash.owner_id) AS owners, COUNT(DISTINCT crash.ip) AS ips, COUNT(*) AS crashes FROM crash, (SELECT owner_id, stackhash FROM crash WHERE id = ?) AS this WHERE this.stackhash = crash.stackhash', [$id])->fetch();
        $processingLog = $app['db']->executeQuery('SELECT created_at, status, duration_ms, message FROM crash_processing_log WHERE crash = ? ORDER BY created_at DESC LIMIT 1', [$id])->fetch();
        if ($processingLog === false) {
            $processingLog = null;
        }

        $outdated = false;
        if ($app['config']['accelerator']) {
            $outdated = !isset($crash['metadata']['ExtensionVersion']) || version_compare($crash['metadata']['ExtensionVersion'], $app['config']['accelerator'], '<');
        }

        $hasErrorString = false;
        if (isset($stack[0]['rendered'])) {
            $hasErrorString = preg_match('/^engine(_srv)?\\.so!Sys_Error(_Internal)?\\(/', $stack[0]['rendered']) === 1;
        }

        $showSourcePawnMessage = false;
        if (isset($crash['metadata']['SourceModVersion']) && version_compare($crash['metadata']['SourceModVersion'], '1.10.0.6431', '<')) {
            foreach ($stack as $frame) {
                if (preg_match('/^sourcepawn\\.jit\\.[^!]+!sp::[^:]+::Invoke/', $frame['rendered']) === 1) {
                    $showSourcePawnMessage = true;
                    break;
                }
            }
        }

        return [
            'crash' => $crash,
            'notices' => $notices,
            'stack' => $stack,
            'modules' => $modules,
            'stats' => $stats,
            'processing_log' => $processingLog,
            'sourcemod_snapshots' => $snapshots,
            'symbol_coverage' => self::buildSymbolCoverage($modules),
            'culprit_candidates' => $culpritCandidates,
            'culprit_groups' => self::groupCulpritCandidates($culpritCandidates),
            'likely_cause_symbol_status' => self::buildLikelyCauseSymbolStatus($culpritCandidates, $stack, $modules),
            'symbol_upload_log' => self::loadSymbolUploadLog($app, $modules),
            'verified_source_lookup_frames' => $verifiedSourceLookupFrames,
            'outdated' => $outdated,
            'has_error_string' => $hasErrorString,
            'show_sourcepawn_message' => $showSourcePawnMessage,
            'source_lookup_enabled' => (($app['config']['upload-settings']['crash_source_lookup_enabled'] ?? false) === true),
            'ai_analysis_enabled' => (($app['config']['upload-settings']['crash_ai_analysis_enabled'] ?? false) === true),
            'public_ai_history_count' => self::countPublicAiHistory($app, $id),
            'ai_history_items' => $app['crash-ai-history-items'] ?? [],
            'global_ai_analysis' => $crash['signature_ignored'] ? null : self::loadGlobalAiAnalysis($app, is_string($crash['stackhash'] ?? null) ? $crash['stackhash'] : null),
        ];
    }

    private static function isSignatureIgnoredCrash(Application $app, string $id): bool
    {
        return (int) $app['db']->executeQuery('SELECT COALESCE(signature_ignored, 0) FROM crash WHERE id = ?', [$id])->fetchColumn(0) === 1;
    }

    private static function assertCrashArtifactsAvailable(Application $app, string $id): void
    {
        if (!self::isSignatureIgnoredCrash($app, $id)) {
            return;
        }

        $app->abort(410, 'This crash keeps only the primary summary page because its signature is configured as ignored.');
    }

    private static function buildIgnoredSignatureSummaryReason(array $crash): string
    {
        $signature = trim((string) ($crash['signature_ignored_reason'] ?? ''));
        if ($signature === '') {
            return 'Ignored crash signature policy';
        }

        return 'Ignored crash signature policy: ' . $signature;
    }

    private static function buildIgnoredSignatureNoticeText(array $crash): string
    {
        return self::buildIgnoredSignatureSummaryReason($crash) . '. This crash keeps only the primary details page. Console history, raw dump access, processing logs, and Discord webhook delivery were skipped.';
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildPresubmitDiagnosticRecord(Application $app, string $rawSignature, ?object $parsedSignature, ?string $response, ?string $category, ?string $error = null): array
    {
        $responseFlagCount = null;
        if (is_string($response) && preg_match('/^[A-Z]\|([^|]*)\|/', $response, $matches) === 1) {
            $responseFlagCount = strlen($matches[1]);
        }

        return [
            'timestamp' => time(),
            'remote_ip' => $app['request']->getClientIp(),
            'content_type' => $app['request']->headers->get('Content-Type'),
            'content_length' => $app['request']->headers->get('Content-Length'),
            'crash_signature_length' => strlen($rawSignature),
            'raw_module_count' => substr_count($rawSignature, '|M|'),
            'raw_frame_count' => substr_count($rawSignature, '|F|'),
            'first_200' => substr($rawSignature, 0, 200),
            'last_200' => substr($rawSignature, -200),
            'parsed_module_count' => $parsedSignature !== null && isset($parsedSignature->modules) && is_array($parsedSignature->modules) ? count($parsedSignature->modules) : 0,
            'parsed_frame_count' => $parsedSignature !== null && isset($parsedSignature->frames) && is_array($parsedSignature->frames) ? count($parsedSignature->frames) : 0,
            'platform' => $parsedSignature->platform ?? null,
            'architecture' => $parsedSignature->architecture ?? null,
            'response' => $response,
            'response_mode' => is_string($response) && $response !== '' ? substr($response, 0, 1) : null,
            'response_flag_count' => $responseFlagCount,
            'diagnostic_category' => $category,
            'error' => $error,
        ];
    }

    private static function storePresubmitDiagnostic(Application $app, array $record): void
    {
        $path = $app['root'] . '/cache/presubmit-debug.jsonl';
        @mkdir(dirname($path), 0777, true);
        @file_put_contents($path, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function loadRelevantPresubmitDiagnostic(Application $app, ?string $ip, ?int $timestamp): ?array
    {
        $path = $app['root'] . '/cache/presubmit-debug.jsonl';
        if (!\Filesystem::pathExists($path)) {
            return null;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || $lines === []) {
            return null;
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;
        $windowBefore = 15 * 60;
        $windowAfter = 2 * 60;

        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }

            if ($ip !== null && ($decoded['remote_ip'] ?? null) !== $ip) {
                continue;
            }

            if ($timestamp !== null && isset($decoded['timestamp']) && is_numeric($decoded['timestamp'])) {
                $delta = (int) $timestamp - (int) $decoded['timestamp'];
                if ($delta < -$windowAfter || $delta > $windowBefore) {
                    continue;
                }

                $distance = abs($delta);
                if ($distance < $bestDistance) {
                    $best = $decoded;
                    $bestDistance = $distance;
                }

                continue;
            }

            return $decoded;
        }

        return $best;
    }

    public static function parsePresubmitSignature($signature)
    {
        $signature = array_reverse(explode('|', $signature));
        $version = (int)array_pop($signature);
        if ($version < 0 || $version > 2) {
            throw new \Exception('bad version');
        }

        $timestamp = time();
        $platform = '';
        $architecture = 'x86';
        if ($version > 1) {
            $timestamp = (int)array_pop($signature);
            $platform = array_pop($signature);
            $architecture = array_pop($signature);
        }

        $crashed = (int)array_pop($signature);
        $crash_reason = array_pop($signature);
        $crash_address = intval(array_pop($signature), 16);
        $requesting_thread = (int)array_pop($signature);

        $modules = [];
        $frames = [];

        while (!empty($signature)) {
            $type = array_pop($signature);
            switch ($type) {
                case 'M':
                    $file = array_pop($signature);
                    if (!strlen($platform)) {
                        switch (pathinfo($file, PATHINFO_EXTENSION)) {
                            case 'pdb':
                                $platform = 'windows';
                                break;
                            case 'dylib':
                                $platform = 'mac';
                                break;
                            case 'so':
                                $platform = 'linux';
                                break;
                        }
                    }
                    $modules[] = (object)[
                        'file' => $file,
                        'identifier' => array_pop($signature),
                    ];
                    break;
                case 'F':
                    $frames[] = (object)[
                        'module' => (int)array_pop($signature),
                        'offset' => intval(array_pop($signature), 16),
                    ];
                    break;
                default:
                    throw new \Exception('unknown field '.$type);
            }
        }

        return (object)compact('timestamp', 'platform', 'architecture', 'crashed', 'crash_reason', 'crash_address', 'requesting_thread', 'modules', 'frames');
    }

    public function presubmit(Application $app, $signature)
    {
        //$app['monolog']->warning('Presubmit: '.$signature);
        $rawSignature = (string) $signature;

        try {
            $signature = self::parsePresubmitSignature($signature);
        } catch (\Exception $e) {
            $app['monolog']->warning('Error parsing presubmit: '.$signature, [$e]);
            self::storePresubmitDiagnostic($app, self::buildPresubmitDiagnosticRecord(
                $app,
                $rawSignature,
                null,
                'E|'.$e->getMessage(),
                'parser_desync',
                $e->getMessage(),
            ));

            return 'E|'.$e->getMessage();
        }

        $app['redis']->hIncrBy('throttle:stats', 'crashes:presubmitted', 1);

        // TODO: Determine whether we want the crash dump...
        $return = 'Y|';
        foreach ($signature->modules as $module) {
            if (!self::shouldRequestSymbolsForModule($module->file, $app['config'])) {
                $return .= 'N';
                continue;
            }

            if ($module->identifier === '000000000000000000000000000000000') {
                $app['monolog']->warning('Ignoring module with invalid identifier: '.$module->file);
                $return .= 'N';
                continue;
            }

            if (self::hasLocalSymbolFile($app, $module->file, $module->identifier)) {
                $return .= 'N';
                continue;
            }

            $moduleName = self::getSymbolModuleName($module->file);
            if (UploadFailureBackoff::isSuppressed($app['root'], $moduleName, $module->identifier)) {
                $return .= 'N';
                continue;
            }

            $app['db']->executeUpdate('UPDATE module SET present = 0 WHERE name = ? AND identifier = ? AND present = 1', [$moduleName, $module->identifier]);
            $return .= 'U';
        }

        // Stick a random presubmit token on the end for testing.
        $return .= '|'.md5($return);
        $rawModuleCount = substr_count($rawSignature, '|M|');
        $parsedModuleCount = isset($signature->modules) && is_array($signature->modules) ? count($signature->modules) : 0;
        $responseFlagCount = preg_match('/^[A-Z]\|([^|]*)\|/', $return, $matches) === 1 ? strlen($matches[1]) : null;
        $category = 'ok';
        if ($rawModuleCount > 0 && $parsedModuleCount === 0) {
            $category = 'parser_desync';
        } elseif ($rawModuleCount !== $parsedModuleCount) {
            $category = 'parsed_modules_mismatch_raw_marker_count';
        } elseif ($responseFlagCount !== $parsedModuleCount) {
            $category = 'response_builder_bug';
        }

        self::storePresubmitDiagnostic($app, self::buildPresubmitDiagnosticRecord(
            $app,
            $rawSignature,
            $signature,
            $return,
            $category,
        ));

        return $return;
    }

    public function submit(Application $app)
    {
        //TODO
        //return $app->abort(503);
        //return 'Sorry, crash submission is currently disabled';

        $presubmit = $app['request']->get('CrashSignature');
        if ($presubmit !== null) {
            return $this->presubmit($app, $presubmit);
        }

        if (!self::allowAnonymousMinidumpUploads($app) && !self::hasValidCrashUploadToken($app)) {
            $app['redis']->hIncrBy('throttle:stats', 'crashes:rejected:invalid-token', 1);

            return new \Symfony\Component\HttpFoundation\Response('Forbidden', 403);
        }

        $app['redis']->hIncrBy('throttle:stats', 'crashes:submitted', 1);

        $minidump = self::findUploadedMinidump($app);

        if ($minidump === null || !$minidump->isValid() || $minidump->getSize() <= 0) {
            $app['monolog']->warning('Crash submit did not include a valid minidump.', [
                'file_fields' => array_keys(self::flattenUploadedFiles($app['request']->files->all())),
                'post_fields' => array_keys($app['request']->request->all()),
            ]);
            $app['redis']->hIncrBy('throttle:stats', 'crashes:rejected:no-minidump', 1);

            return $app['twig']->render('submit-empty.txt.twig');
        }

        $app['redis']->hIncrBy('throttle:stats', 'crashes:submitted:bytes', (int) $minidump->getSize());

        list($id, $path) = self::generateId($app);

        $ip = $app['request']->getClientIp();

        $ownerId = null;
        $providedUploadToken = self::getProvidedUploadToken($app);
        if (is_string($providedUploadToken) && $providedUploadToken !== '') {
            $tokenOwnerId = $app['db']->executeQuery('SELECT id FROM user WHERE upload_token = ? AND uploads_blocked = 0 LIMIT 1', [$providedUploadToken])->fetchColumn(0);
            if ($tokenOwnerId !== false && $tokenOwnerId !== null) {
                $ownerId = (int) $tokenOwnerId;
            }
        }

        $legacyOwnerId = $app['request']->request->get('UserID');
        if ($legacyOwnerId !== null) {
            $app['request']->request->remove('UserID');

            if ($legacyOwnerId === '0') {
                $legacyOwnerId = null;
            } elseif (stripos($legacyOwnerId, 'STEAM_') === 0) {
                $legacyOwnerId = self::toSteamId64(explode(':', $legacyOwnerId));
            } elseif (!ctype_digit($legacyOwnerId)) {
                $app['monolog']->warning('Bad owner provided in submit', ['id' => $id, 'owner' => $legacyOwnerId]);
                $legacyOwnerId = null;
            }

            if ($legacyOwnerId !== null) {
                $resolvedOwnerId = $app['owner_resolver']->resolveOwnerIdFromLegacyIdentifier($legacyOwnerId);
                if ($ownerId === null) {
                    $ownerId = $resolvedOwnerId;
                }
            }
        }

        $serverId = $app['request']->request->get('ServerID');
        if ($serverId !== null) {
            $app['request']->request->remove('ServerID');

            if (ctype_digit((string) $serverId)) {
                $server = $app['db']->executeQuery('SELECT id, owner_id FROM server WHERE id = ?', [(int) $serverId])->fetch();
                if ($server !== false) {
                    $serverId = (int) $server['id'];
                    if ($ownerId === null) {
                        $ownerId = (int) $server['owner_id'];
                    }
                } else {
                    $serverId = null;
                }
            } else {
                $serverId = null;
            }
        }

        if ($ownerId !== null) {
            $count = $app['db']->executeQuery('SELECT COUNT(*) AS count FROM crash WHERE owner_id = ? AND ip = ? AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$ownerId, $ip])->fetchColumn(0);
        } else {
            $count = $app['db']->executeQuery('SELECT COUNT(*) AS count FROM crash WHERE owner_id IS NULL AND ip = ? AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$ip])->fetchColumn(0);
        }

        if ($count > 12) {
            $app['redis']->hIncrBy('throttle:stats', 'crashes:rejected:rate-limit', 1);

            return $app['twig']->render('submit-reject.txt.twig');
        }

        $metadata = $app['request']->request->all();

        $raw_metadata = null;
        $metadata_file = $app['request']->files->get('upload_file_metadata');
        if ($metadata_file !== null && $metadata_file->isValid() && $metadata_file->getSize() > 0) {
            $raw_metadata = \Filesystem::readFile($metadata_file->getRealPath());

            $has_config = preg_match('/(?<=-------- CONFIG BEGIN --------)[^\\x00]+(?=-------- CONFIG END --------)/i', $raw_metadata, $metadata_config);
            if ($has_config === 1) {
                $metadata_config = trim($metadata_config[0]);
                $metadata_config = phutil_split_lines($metadata_config, false);

                // Merge with the existing metadata, overwrite existing.
                foreach ($metadata_config as $line) {
                    list($key, $value) = array_pad(explode('=', $line, 2), 2, '');

                    $key = trim($key);
                    $value = trim($value);

                    if (strlen($value)) {
                        $metadata[$key] = $value;
                    }
                }
            }

            $has_console = preg_match('/(?<=-------- CONSOLE HISTORY BEGIN --------)[^\\x00]+(?=-------- CONSOLE HISTORY END --------)/i', $raw_metadata, $metadata_console);
            if ($has_console === 1 && strlen(trim($metadata_console[0]))) {
                $metadata['HasConsoleLog'] = true;
            }

            foreach (array(
                'SourceModPlugins' => array('SOURCEMOD PLUGINS', 'SM PLUGINS', 'PLUGIN LIST'),
                'SourceModExtensions' => array('SOURCEMOD EXTENSIONS', 'SM EXTS', 'EXTENSION LIST'),
            ) as $metadataKey => $sectionNames) {
                foreach ($sectionNames as $sectionName) {
                    $pattern = '/(?<=-------- ' . preg_quote($sectionName, '/') . ' BEGIN --------)[^\\x00]+(?=-------- ' . preg_quote($sectionName, '/') . ' END --------)/i';
                    if (preg_match($pattern, $raw_metadata, $snapshot) === 1 && strlen(trim($snapshot[0]))) {
                        $metadata[$metadataKey] = trim($snapshot[0]);
                        break;
                    }
                }
            }
        }

        $command_line = null;
        if (isset($metadata['CommandLine'])) {
            $old = mb_substitute_character();
            mb_substitute_character(0xFFFD);

            $command_line = mb_convert_encoding($metadata['CommandLine'], 'UTF-8', 'UTF-8');
            unset($metadata['CommandLine']);

            mb_substitute_character($old);
        }

        // Strip any presubmit token, until we're ready to do something with them
        if (isset($metadata['PresubmitToken'])) {
            unset($metadata['PresubmitToken']);
        }

        $metadata = json_encode($metadata, JSON_FORCE_OBJECT|JSON_UNESCAPED_SLASHES);

        $app['db']->executeUpdate(
            'INSERT INTO crash (id, timestamp, ip, owner_id, server_id, metadata, cmdline) VALUES (?, NOW(), ?, ?, ?, ?, ?)',
            [$id, $ip, $ownerId, $serverId, $metadata, $command_line]
        );

        // Move after it's in the DB, to avoid a race condition with the cleanup code.
        \Filesystem::createDirectory($path, 0755, true);
        $minidump->move($path, $id . '.dmp');

        if ($raw_metadata) {
            $metapath = $path . '/' . $id . '.meta.txt.gz';
            \Filesystem::writeFile($metapath, gzencode($raw_metadata));
        }

        $app['redis']->hIncrBy('throttle:stats', 'crashes:accepted', 1);

/*
        try {
            $app['queue']->putInTube('carburetor', json_encode(array(
                'id' => $id,
                'owner' => $owner,
                'ip' => $ip,
            )));
        } catch (\Exception $e) {}
*/

        // Special code for handling breakpad-uploaded minidumps.
        // FIXME: This is mainly a hack for testing Electron.
        if ($app['request']->request->get('prod')) {
            $bid = '1000'; // First 2 bits specify UUID variant
            $map = array_merge(range('a', 'z'), range('2', '7'));
            for ($i = 0; $i < 12; $i++) {
                $bid .= sprintf('%05b', array_search($id[$i], $map));
            }
            $bid = str_split($bid, 8);

            $uuid = 'bee0cafe-0000-4000-'; // 4 = UUID version
            for ($i = 0; $i < 8; $i++) {
                $uuid .= sprintf('%02x', bindec($bid[$i]));
                if ($i === 1) $uuid .= '-';
            }

            return $uuid;
        }

        return $app['twig']->render('submit.txt.twig', array(
            'id' => $id,
        ));
    }

    public function details(Application $app, $id)
    {
        $access = self::getCrashAccess($app, (string) $id);
        if ($access === null) {
            if ($app['session']->getFlashBag()->get('internal')) {
                $app['session']->getFlashBag()->add('error_crash', 'That Crash ID does not exist.');

                return $app->redirect($app['url_generator']->generate('index'));
            }

            return $app->abort(404);
        }

        $crash = $app['db']->executeQuery('SELECT crash.id, UNIX_TIMESTAMP(crash.timestamp) AS timestamp, crash.ip AS ip, crash.owner_id AS owner, crash.server_id, crash.metadata, crash.cmdline, crash.thread, crash.processed, crash.failed, crash.stackhash, UNIX_TIMESTAMP(crash.lastview) AS lastview, crash.signature_ignored, crash.signature_ignored_reason, server_owner.name, server_owner.kind AS owner_kind FROM crash LEFT JOIN server_owner ON server_owner.id = crash.owner_id WHERE crash.id = ?', [$id])->fetch();

        if ($crash['lastview'] === null || (time() - $crash['lastview']) > (60 * 60 * 24)) {
            $app['db']->executeUpdate('UPDATE crash SET lastview = NOW() WHERE id = ?', array($id));
        }
        $diagnostics = self::buildCrashDiagnosticContext($app, (string) $id, $crash);
        $crash = $diagnostics['crash'];
        $notices = $diagnostics['notices'];
        $stack = $diagnostics['stack'];
        $modules = $diagnostics['modules'];
        $stats = $diagnostics['stats'];
        $signatureNotesPage = self::getSignatureNotesPage($app);
        $signatureNotes = self::loadSignatureNotes($app, $crash['stackhash'] ?? null, $signatureNotesPage);
        $userSignatureNote = null;
        if ($app['user'] !== null) {
            foreach ($signatureNotes['items'] as $note) {
                if ((int) $note['author_id'] === (int) $app['user']['id']) {
                    $userSignatureNote = $note;
                    break;
                }
            }
        }

        return $app['twig']->render('details.html.twig', array(
            'crash' => $crash,
            'can_manage' => $access['can_manage'],
            'can_view_sensitive' => $access['can_view_sensitive'],
            'notices' => $notices,
            'stack' => $stack,
            'modules' => $modules,
            'stats' => $stats,
            'signature_notes' => $signatureNotes['items'],
            'signature_notes_page' => $signatureNotes['page'],
            'signature_notes_pages' => $signatureNotes['pages'],
            'signature_notes_total' => $signatureNotes['total'],
            'user_signature_note' => $userSignatureNote,
            'can_create_signature_note' => $app['user'] !== null && ($app['user']['admin'] || $userSignatureNote === null),
            'outdated' => $diagnostics['outdated'],
            'has_error_string' => $diagnostics['has_error_string'],
            'show_sourcepawn_message' => $diagnostics['show_sourcepawn_message'],
            'symbol_coverage' => $diagnostics['symbol_coverage'],
            'culprit_candidates' => $diagnostics['culprit_candidates'],
            'culprit_groups' => $diagnostics['culprit_groups'],
            'likely_cause_symbol_status' => $diagnostics['likely_cause_symbol_status'],
            'processing_log' => $diagnostics['processing_log'],
            'sourcemod_snapshots' => $diagnostics['sourcemod_snapshots'],
            'symbol_upload_log' => $diagnostics['symbol_upload_log'],
            'verified_source_lookup_frames' => $diagnostics['verified_source_lookup_frames'],
            'source_lookup_enabled' => $diagnostics['source_lookup_enabled'],
            'ai_analysis_enabled' => $diagnostics['ai_analysis_enabled'],
            'public_ai_history_count' => $diagnostics['public_ai_history_count'],
            'ai_history_items' => $diagnostics['ai_history_items'],
            'global_ai_analysis' => $diagnostics['global_ai_analysis'],
        ));
    }

    /**
     * @param array<string, bool> $includes
     * @return array{text: string, sections: array<string, bool>, context: string}
     */
    public function buildAiAnalysisPacket(Application $app, string $id, string $context, array $includes): array
    {
        $crash = $app['db']->executeQuery('SELECT crash.id, UNIX_TIMESTAMP(crash.timestamp) AS timestamp, crash.ip AS ip, crash.owner_id AS owner, crash.server_id, crash.metadata, crash.cmdline, crash.thread, crash.processed, crash.failed, crash.stackhash, UNIX_TIMESTAMP(crash.lastview) AS lastview, crash.signature_ignored, crash.signature_ignored_reason, server_owner.name, server_owner.kind AS owner_kind FROM crash LEFT JOIN server_owner ON server_owner.id = crash.owner_id WHERE crash.id = ?', [$id])->fetch();
        if ($crash === false) {
            throw new \RuntimeException('Crash not found.');
        }

        if ($crash['thread'] == -1) {
            $crash['thread'] = 0;
        }

        $crash['cmdline'] = self::sanitizeCrashCommandLine((string) $crash['cmdline']);
        $crash['metadata'] = json_decode($crash['metadata'], true);
        if (!is_array($crash['metadata'])) {
            $crash['metadata'] = [];
        }

        $snapshots = self::extractSourceModSnapshots($crash['metadata']);
        if (isset($crash['metadata']['HasConsoleLog'])) {
            $crash['has_console_log'] = $crash['metadata']['HasConsoleLog'];
            unset($crash['metadata']['HasConsoleLog']);
        } else {
            $crash['has_console_log'] = false;
        }

        if (isset($crash['metadata']['ExtensionBuild'])) {
            unset($crash['metadata']['ExtensionBuild']);
        }

        ksort($crash['metadata']);

        $stack = $app['db']->executeQuery('SELECT frame, module, `function`, rendered, url FROM frame WHERE crash = ? AND thread = ? ORDER BY frame', array($id, $crash['thread']))->fetchAll();
        $modules = $app['db']->executeQuery('SELECT name, identifier, processed, present, HEX(base) AS base FROM module WHERE crash = ? ORDER BY name', array($id))->fetchAll();
        $modules = self::buildModuleCoverageRows($modules, $app['config']);
        $consoleCause = self::loadTerminalSourceModCause($app, $id, (bool) $crash['has_console_log']);
        $terminalConsoleCause = ($consoleCause['terminal'] ?? false) ? $consoleCause : null;
        $consoleBlaming = $consoleCause['supporting_blaming'] ?? ($terminalConsoleCause['blaming'] ?? null);
        $rawSourcePawnChain = self::loadRawSourcePawnCauseChain($app, $id, $stack, $crash['metadata']);
        $culpritCandidates = self::buildCulpritCandidates($stack, $modules, $crash['metadata'], $crash['cmdline'], $consoleBlaming, $terminalConsoleCause, $rawSourcePawnChain);
        $symbolCoverage = self::buildSymbolCoverage($modules);
        $consoleEntries = self::loadConsoleEntriesForAi($app, $id);

        $lines = [];
        $lines[] = sprintf('Crash ID: %s', $id);
        $lines[] = sprintf('Analysis context: %s', $context);
        $lines[] = '';

        if (!empty($includes['header_metadata'])) {
            $lines[] = '## Crash Header + Metadata';
            $lines[] = self::buildAiHeaderSection($crash);
            $lines[] = '';
        }

        if (!empty($includes['stack_trace'])) {
            $lines[] = '## Stack Trace';
            $lines[] = self::buildAiStackSection($stack);
            $lines[] = '';
        }

        if (!empty($includes['likely_cause'])) {
            $lines[] = '## Likely Cause';
            $lines[] = self::buildAiLikelyCauseSection($culpritCandidates);
            $lines[] = '';
        }

        if (!empty($includes['modules'])) {
            $lines[] = '## Modules';
            $lines[] = self::buildAiModulesSection($modules, $symbolCoverage);
            $lines[] = '';
        }

        if (!empty($includes['console'])) {
            $lines[] = '## Console History';
            $lines[] = self::buildAiConsoleSection($consoleEntries, $snapshots);
            $lines[] = '';
        }

        if (!empty($includes['raw'])) {
            $lines[] = '## Raw';
            $lines[] = self::buildAiRawSection($app, $id);
            $lines[] = '';
        }

        return [
            'text' => trim(implode("\n", array_filter($lines, static fn ($line): bool => $line !== null))),
            'sections' => $includes,
            'context' => $context,
        ];
    }

    /**
     * @return array{crash_id: string, stack_trace: string, likely_cause: string, likely_cause_details: string, likely_cause_supporting: string, likely_cause_full: string, console: string}
     */
    public function buildDiscordWebhookContext(Application $app, string $id, int $consoleLineLimit = 20, int $stackTraceLineLimit = self::DISCORD_STACK_TRACE_LINE_LIMIT): array
    {
        $crash = $app['db']->executeQuery(
            'SELECT crash.id, UNIX_TIMESTAMP(crash.timestamp) AS timestamp, crash.ip AS ip, crash.owner_id AS owner, crash.server_id, crash.metadata, crash.cmdline, crash.thread, crash.processed, crash.failed, crash.stackhash, UNIX_TIMESTAMP(crash.lastview) AS lastview, server_owner.name, server_owner.kind AS owner_kind
             FROM crash
             LEFT JOIN server_owner ON server_owner.id = crash.owner_id
             WHERE crash.id = ?',
            [$id]
        )->fetch();
        if ($crash === false) {
            throw new \RuntimeException('Crash not found.');
        }

        $diagnostics = self::buildCrashDiagnosticContext($app, $id, $crash);
        $consoleEntries = self::loadConsoleEntriesForAi($app, $id);

        return [
            'crash_id' => strtoupper(implode('-', str_split($id, 4))),
            'stack_trace' => self::buildAiStackSection($diagnostics['stack'], $stackTraceLineLimit),
            'likely_cause' => self::buildAiLikelyCauseSummary($diagnostics['culprit_candidates']),
            'likely_cause_details' => self::buildAiLikelyCauseDetails($diagnostics['culprit_candidates']),
            'likely_cause_supporting' => self::buildAiLikelyCauseSupportingSection($diagnostics['culprit_candidates']),
            'likely_cause_full' => self::buildAiLikelyCauseFullSection($diagnostics['culprit_candidates']),
            'console' => self::buildAiConsoleTailSection($consoleEntries, $consoleLineLimit),
        ];
    }

    public function symbols(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_view_sensitive = self::canUserViewSensitiveCrashData($app, $id);
        if ($can_view_sensitive === null) {
            $app->abort(404);
        }

        if (!$can_view_sensitive) {
            $app->abort(403);
        }

        return $app->redirect($app['url_generator']->generate('details', array('id' => $id)) . '#modules');
    }

    public function createSignatureNote(Application $app, string $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $stackhash = self::loadCrashStackhash($app, $id);
        if ($stackhash === null) {
            $app->abort(404);
        }

        [$title, $body] = self::validateSignatureNoteInput($app);
        if ($title === '' || $body === '') {
            $app['session']->getFlashBag()->add('error_note', 'Title and note body are required.');

            return $app->redirect(self::buildSignatureNotesUrl($app, $id, self::getSignatureNotesPage($app)));
        }

        if (!$app['user']['admin']) {
            $existing = $app['db']->executeQuery(
                'SELECT id FROM crash_signature_note WHERE stackhash = ? AND author_id = ? LIMIT 1',
                array($stackhash, (int) $app['user']['id'])
            )->fetchColumn(0);
            if ($existing !== false) {
                $app['session']->getFlashBag()->add('error_note', 'You already have a note for this crash signature. Edit your existing note instead.');

                return $app->redirect(self::buildSignatureNotesUrl($app, $id, self::getSignatureNotesPage($app)));
            }
        }

        $app['db']->executeUpdate(
            'INSERT INTO crash_signature_note (stackhash, author_id, title, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NULL)',
            array($stackhash, (int) $app['user']['id'], $title, $body, date('Y-m-d H:i:s'))
        );

        return $app->redirect(self::buildSignatureNotesUrl($app, $id, self::getSignatureNotesPage($app)));
    }

    public function editSignatureNote(Application $app, string $id, int $noteId)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $stackhash = self::loadCrashStackhash($app, $id);
        if ($stackhash === null) {
            $app->abort(404);
        }

        $note = $app['db']->executeQuery('SELECT id, stackhash, author_id FROM crash_signature_note WHERE id = ?', array($noteId))->fetch();
        if ($note === false || (string) $note['stackhash'] !== $stackhash) {
            $app->abort(404);
        }

        if ((int) $note['author_id'] !== (int) $app['user']['id']) {
            $app->abort(403);
        }

        [$title, $body] = self::validateSignatureNoteInput($app);
        if ($title === '' || $body === '') {
            $app['session']->getFlashBag()->add('error_note', 'Title and note body are required.');

            return $app->redirect(self::buildSignatureNotesUrl($app, $id, self::getSignatureNotesPage($app)));
        }

        $app['db']->executeUpdate(
            'UPDATE crash_signature_note SET title = ?, body = ?, updated_at = ? WHERE id = ?',
            array($title, $body, date('Y-m-d H:i:s'), $noteId)
        );

        return $app->redirect(self::buildSignatureNotesUrl($app, $id, self::getSignatureNotesPage($app)));
    }

    public function deleteSignatureNote(Application $app, string $id, int $noteId)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $stackhash = self::loadCrashStackhash($app, $id);
        if ($stackhash === null) {
            $app->abort(404);
        }

        $note = $app['db']->executeQuery('SELECT id, stackhash, author_id FROM crash_signature_note WHERE id = ?', array($noteId))->fetch();
        if ($note === false || (string) $note['stackhash'] !== $stackhash) {
            $app->abort(404);
        }

        if (!$app['user']['admin'] && (int) $note['author_id'] !== (int) $app['user']['id']) {
            $app->abort(403);
        }

        $app['db']->executeUpdate('DELETE FROM crash_signature_note WHERE id = ?', array($noteId));

        return $app->redirect(self::buildSignatureNotesUrl($app, $id, self::getSignatureNotesPage($app)));
    }

    public function voteSignatureNote(Application $app, string $id, int $noteId)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $stackhash = self::loadCrashStackhash($app, $id);
        if ($stackhash === null) {
            $app->abort(404);
        }

        $note = $app['db']->executeQuery('SELECT id, stackhash FROM crash_signature_note WHERE id = ?', array($noteId))->fetch();
        if ($note === false || (string) $note['stackhash'] !== $stackhash) {
            $app->abort(404);
        }

        $value = (int) $app['request']->request->get('value', 0);
        if ($value !== 1 && $value !== -1) {
            $app->abort(400);
        }

        $existing = $app['db']->executeQuery(
            'SELECT id, value FROM crash_signature_note_vote WHERE note_id = ? AND voter_id = ?',
            array($noteId, (int) $app['user']['id'])
        )->fetch();

        if ($existing !== false) {
            if ((int) $existing['value'] === $value) {
                $app['db']->executeUpdate('DELETE FROM crash_signature_note_vote WHERE id = ?', array((int) $existing['id']));
            } else {
                $app['db']->executeUpdate(
                    'UPDATE crash_signature_note_vote SET value = ?, updated_at = ? WHERE id = ?',
                    array($value, date('Y-m-d H:i:s'), (int) $existing['id'])
                );
            }
        } else {
            $now = date('Y-m-d H:i:s');
            $app['db']->executeUpdate(
                'INSERT INTO crash_signature_note_vote (note_id, voter_id, value, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                array($noteId, (int) $app['user']['id'], $value, $now, $now)
            );
        }

        return $app->redirect(self::buildSignatureNotesUrl($app, $id, self::getSignatureNotesPage($app)));
    }

    public function pinSignatureNote(Application $app, string $id, int $noteId)
    {
        if ($app['user'] === null || !$app['user']['admin']) {
            $app->abort(403);
        }

        $stackhash = self::loadCrashStackhash($app, $id);
        if ($stackhash === null) {
            $app->abort(404);
        }

        $note = $app['db']->executeQuery('SELECT id, stackhash, pinned FROM crash_signature_note WHERE id = ?', array($noteId))->fetch();
        if ($note === false || (string) $note['stackhash'] !== $stackhash) {
            $app->abort(404);
        }

        $pin = ((int) $note['pinned']) === 1 ? 0 : 1;
        $app['db']->executeUpdate(
            'UPDATE crash_signature_note SET pinned = ?, pinned_at = ? WHERE id = ?',
            array($pin, $pin === 1 ? date('Y-m-d H:i:s') : null, $noteId)
        );

        return $app->redirect(self::buildSignatureNotesUrl($app, $id, self::getSignatureNotesPage($app)));
    }

    private static function loadTerminalSourceModCause(Application $app, string $id, bool $hasConsoleLog): ?array
    {
        if (!$hasConsoleLog) {
            return null;
        }

        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.meta.txt';
        if (\Filesystem::pathExists($path . '.gz')) {
            $contents = gzdecode(\Filesystem::readFile($path . '.gz'));
        } elseif (\Filesystem::pathExists($path)) {
            $contents = \Filesystem::readFile($path);
        } else {
            return null;
        }

        if (!is_string($contents) || trim($contents) === '') {
            return null;
        }

        return self::extractTerminalSourceModCause($contents);
    }

    private static function extractTerminalConsoleBlaming(string $contents): ?string
    {
        return self::extractTerminalSourceModCause($contents)['supporting_blaming'] ?? null;
    }

    private static function extractTerminalSourceModCause(string $contents): ?array
    {
        $contents = self::stripAnsiEscapeSequences($contents);
        if (preg_match('/-------- CONSOLE HISTORY BEGIN --------(.+?)-------- CONSOLE HISTORY END --------/is', $contents, $matches) === 1) {
            $contents = $matches[1];
        }

        $lines = preg_split('/\R/', str_replace("\0", '', $contents)) ?: array();
        $normalized = array();
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '-------- CONSOLE HISTORY')) {
                continue;
            }

            $line = preg_replace('/^\d+\(\d+(?:\.\d+)?\):\s+/', '', $line) ?? $line;
            $line = preg_replace('/^\d+\s+\d+(?:\.\d+)?\s+/', '', $line) ?? $line;
            $line = trim($line);
            if ($line !== '') {
                $normalized[] = $line;
            }
        }

        if (empty($normalized)) {
            return null;
        }

        $exceptionIndexes = array();
        foreach ($normalized as $index => $line) {
            if (preg_match('/\[SM\]\s+Exception reported/i', $line) === 1) {
                $exceptionIndexes[] = $index;
            }
        }

        if (empty($exceptionIndexes)) {
            return null;
        }

        $latest = null;
        $lineCount = count($normalized);
        foreach ($exceptionIndexes as $position => $exceptionIndex) {
            $nextException = $exceptionIndexes[$position + 1] ?? $lineCount;
            $block = array(
                'plugin' => null,
                'function' => null,
                'exception' => null,
                'blaming' => null,
                'last_relevant' => $exceptionIndex,
            );

            for ($i = $exceptionIndex; $i < $nextException; $i++) {
                $line = $normalized[$i];
                if ($i === $exceptionIndex && preg_match('/\[SM\]\s+Exception reported:\s*(.+)$/i', $line, $matches) === 1) {
                    $block['exception'] = trim($matches[1]);
                    $block['last_relevant'] = $i;
                    continue;
                }

                if (preg_match('/\[SM\]\s+Blaming:\s*(?:\[[^\]]+\]\s*)?([^\r\n]+?\.smx)\b/i', $line, $matches) === 1) {
                    $block['blaming'] = basename(trim($matches[1]));
                    $block['plugin'] ??= $block['blaming'];
                    $block['last_relevant'] = $i;
                    continue;
                }

                $sourcePawnLine = self::parseSourceModConsoleSourceLine($line);
                if ($sourcePawnLine !== null) {
                    $block['plugin'] = $sourcePawnLine['plugin'];
                    $block['function'] = $sourcePawnLine['function'];
                    $block['last_relevant'] = $i;
                    continue;
                }

                if (self::isSourceModExceptionLine($line)) {
                    $block['last_relevant'] = $i;
                }
            }

            if ($block['plugin'] !== null || $block['blaming'] !== null) {
                $latest = $block;
            }
        }

        if ($latest === null || ($lineCount - 1 - (int) $latest['last_relevant']) > 80) {
            return null;
        }

        $tailLines = array_slice($normalized, (int) $latest['last_relevant'] + 1);
        $tailIsTerminal = count($tailLines) <= 3;
        if ($tailIsTerminal) {
            foreach ($tailLines as $tailLine) {
                if (!self::isBenignTerminalTailLine($tailLine)) {
                    $tailIsTerminal = false;
                    break;
                }
            }
        }

        $plugin = $latest['plugin'] ?? $latest['blaming'];

        return array(
            'plugin' => $tailIsTerminal ? $plugin : null,
            'function' => $tailIsTerminal ? $latest['function'] : null,
            'exception' => $tailIsTerminal ? $latest['exception'] : null,
            'blaming' => $tailIsTerminal ? $latest['blaming'] : null,
            'supporting_blaming' => $latest['blaming'] ?? $plugin,
            'terminal' => $tailIsTerminal,
        );
    }

    private static function isBenignTerminalTailLine(string $line): bool
    {
        $line = trim($line);
        if ($line === '') {
            return true;
        }

        if (preg_match('/^(?:Segmentation fault|Aborted|Wrote minidump to:|Crash dump|core dumped)/i', $line) === 1) {
            return true;
        }

        if (preg_match('/^(?:\/entrypoint\.sh:|container@|quit$|Error log file session closed\.?$)/i', $line) === 1) {
            return true;
        }

        if (preg_match('/^\[(?:Pterodactyl Daemon|System Panel)\]/i', $line) === 1) {
            return true;
        }

        return preg_match('/Server marked as (?:offline|stopping|crashed state)/i', $line) === 1;
    }

    private static function isSourceModExceptionLine(string $line): bool
    {
        if (preg_match('/\[SM\]\s+(?:Exception reported|Blaming:|Call stack trace:|\[\d+\]\s|Displaying call stack trace)/i', $line) === 1) {
            return true;
        }

        return preg_match('/^\[SM\]\s+Line\s+\d+/i', $line) === 1;
    }

    private static function parseSourceModConsoleSourceLine(string $line): ?array
    {
        if (preg_match('/(?:^|\s)([A-Za-z]:\\\\[^:\r\n]+?|\/[^:\r\n]+?|[^:\r\n]+?)\.sp::([A-Za-z_][A-Za-z0-9_]*)\b/i', $line, $matches) !== 1) {
            return null;
        }

        $path = str_replace('\\', '/', trim($matches[1]));
        $source = basename($path);
        $source = preg_replace('/^\[[^\]]+\]\s*/', '', $source) ?? $source;
        $source = preg_replace('/^\d+\./', '', $source) ?? $source;
        $plugin = $source . '.smx';

        return array(
            'plugin' => $plugin,
            'function' => trim($matches[2]),
        );
    }

    private static function loadSymbolUploadLog(Application $app, array $modules): array
    {
        $moduleNames = array();
        $identifiers = array();
        $pairs = array();

        foreach ($modules as $module) {
            $name = (string) ($module['name'] ?? '');
            $identifier = (string) ($module['identifier'] ?? '');
            if ($name === '' || $identifier === '') {
                continue;
            }

            $moduleNames[$name] = $name;
            $identifiers[$identifier] = $identifier;
            $pairs[$name . "\0" . $identifier] = true;
        }

        if ($moduleNames === array() || $identifiers === array()) {
            return array();
        }

        $rows = $app['db']->executeQuery(
            'SELECT created_at, endpoint, remote_addr, account, module, identifier, bytes, status_code, result, reason, token_suffix, user_agent
             FROM upload_token_audit
             WHERE endpoint IN (\'symbols\', \'binary\')
               AND module IN (?)
               AND identifier IN (?)
             ORDER BY created_at DESC
             LIMIT 100',
            array(array_values($moduleNames), array_values($identifiers)),
            array(102, 102)
        )->fetchAll();

        return array_values(array_filter($rows, static function (array $row) use ($pairs): bool {
            return isset($pairs[(string) ($row['module'] ?? '') . "\0" . (string) ($row['identifier'] ?? '')]);
        }));
    }

    public function download(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_view_sensitive = self::canUserViewSensitiveCrashData($app, $id);
        if ($can_view_sensitive === null) {
            $app->abort(404);
        }

        if (!$can_view_sensitive) {
            $app->abort(403);
        }

        self::assertCrashArtifactsAvailable($app, (string) $id);

        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.dmp';

        if (!\Filesystem::pathExists($path)) {
            $retention = StorageRetentionManager::getCrashArtifactStatus($app['root'], (string) $id);
            $app->abort(($retention['dump_deleted'] ?? false) ? 410 : 404, ($retention['dump_deleted'] ?? false) ? 'Minidump was deleted by storage cleanup.' : null);
        }

        return $app->sendFile($path)->setContentDisposition(\Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'crash_' . $id . '.dmp');
    }

    public function view(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_view_sensitive = self::canUserViewSensitiveCrashData($app, $id);
        if ($can_view_sensitive === null) {
            $app->abort(404);
        }

        if (!$can_view_sensitive) {
            $app->abort(403);
        }

        self::assertCrashArtifactsAvailable($app, (string) $id);

        return $app['twig']->render('view.html.twig', array('id' => $id));
    }

    public function logs(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_view_sensitive = self::canUserViewSensitiveCrashData($app, $id);
        if ($can_view_sensitive === null) {
            $app->abort(404);
        }

        if (!$can_view_sensitive) {
            $app->abort(403);
        }

        self::assertCrashArtifactsAvailable($app, (string) $id);

        $processing_logs = $app['db']->executeQuery('SELECT created_at, status, duration_ms, message, log FROM crash_processing_log WHERE crash = ? ORDER BY created_at DESC LIMIT 10', [$id])->fetchAll();

        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.txt';

        $logs = null;
        if (\Filesystem::pathExists($path . '.gz')) {
            $logs = gzdecode(\Filesystem::readFile($path . '.gz'));
        } else if (\Filesystem::pathExists($path)) {
            $logs = \Filesystem::readFile($path);
        }

        return $app['twig']->render('logs.html.twig', array(
            'id' => $id,
            'logs' => $logs,
            'processing_logs' => $processing_logs,
            'retention' => StorageRetentionManager::getCrashArtifactStatus($app['root'], (string) $id),
        ));
    }

    public function metadata(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_view_sensitive = self::canUserViewSensitiveCrashData($app, $id);
        if ($can_view_sensitive === null) {
            $app->abort(404);
        }

        if (!$can_view_sensitive) {
            $app->abort(403);
        }

        self::assertCrashArtifactsAvailable($app, (string) $id);

        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.meta.txt';

        $logs = null;
        if (\Filesystem::pathExists($path . '.gz')) {
            $logs = gzdecode(\Filesystem::readFile($path . '.gz'));
        } else if (\Filesystem::pathExists($path)) {
            $logs = \Filesystem::readFile($path);
        }

        return $app['twig']->render('logs.html.twig', array('id' => $id, 'logs' => $logs));
    }

    public function console(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_view_sensitive = self::canUserViewSensitiveCrashData($app, $id);
        if ($can_view_sensitive === null) {
            $app->abort(404);
        }

        if (!$can_view_sensitive) {
            $app->abort(403);
        }

        self::assertCrashArtifactsAvailable($app, (string) $id);

        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.meta.txt';

        $metadata = null;
        if (\Filesystem::pathExists($path . '.gz')) {
            $metadata = gzdecode(\Filesystem::readFile($path . '.gz'));
        } else if (\Filesystem::pathExists($path)) {
            $metadata = \Filesystem::readFile($path);
        }

        $console = [];
        if ($metadata !== null) {
            $ret = preg_match('/(?<=-------- CONSOLE HISTORY BEGIN --------)[^\\x00]+(?=-------- CONSOLE HISTORY END --------)/i', $metadata, $matches);
            if ($ret === 1) {
                $console = $matches[0]; // Get the console output.
                $console = trim($console); // Remove the extra newlines from the markers.
                $console = str_replace("\r\n", PHP_EOL, $console); // Normalize line endings.

                // Split the console output into individual prints.
                preg_match_all('/(\\d+)\\((\\d+\\.?\\d*)\\):  ([^\\x00]*?)(?=(?:\\d+\\(\\d+\\.\\d+\\):  )|$)/', $console, $console, PREG_SET_ORDER);

                $console = array_reverse($console); // Flip them back into chronological order.
                $activeGroup = null;
                $console = array_map(function (array $line) use (&$activeGroup): array {
                    $message = trim(self::stripAnsiEscapeSequences((string) ($line[3] ?? '')));
                    [$severity, $nextGroup] = self::classifyConsoleLine($message, $activeGroup);
                    $activeGroup = $nextGroup;

                    return array(
                        'tick' => trim((string) ($line[1] ?? '')),
                        'time' => trim((string) ($line[2] ?? '')),
                        'message' => $message,
                        'severity' => $severity,
                    );
                }, $console);
            }
        }

        return $app['twig']->render('console.html.twig', array('id' => $id, 'console' => $console));
    }

    private static function sanitizeCrashCommandLine(string $cmdline): string
    {
        return (string) preg_replace_callback(array_map(function($v) {
            return sprintf('/(?<=%s )[^ ]+/', preg_quote($v));
        }, [
            '+sv_password',
            '+rcon_password',
            '+sv_setsteamaccount',
        ]), function($matches) {
            return str_repeat('*', strlen($matches[0]));
        }, $cmdline);
    }

    /**
     * @return list<array{tick: string, time: string, message: string, severity: string}>
     */
    private static function loadConsoleEntriesForAi(Application $app, string $id): array
    {
        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.meta.txt';

        $metadata = null;
        if (\Filesystem::pathExists($path . '.gz')) {
            $metadata = gzdecode(\Filesystem::readFile($path . '.gz'));
        } elseif (\Filesystem::pathExists($path)) {
            $metadata = \Filesystem::readFile($path);
        }

        $console = [];
        if ($metadata !== null) {
            $ret = preg_match('/(?<=-------- CONSOLE HISTORY BEGIN --------)[^\\x00]+(?=-------- CONSOLE HISTORY END --------)/i', $metadata, $matches);
            if ($ret === 1) {
                $console = trim((string) $matches[0]);
                $console = str_replace("\r\n", PHP_EOL, $console);
                preg_match_all('/(\\d+)\\((\\d+\\.?\\d*)\\):  ([^\\x00]*?)(?=(?:\\d+\\(\\d+\\.\\d+\\):  )|$)/', $console, $console, PREG_SET_ORDER);
                $console = array_reverse($console);
                $activeGroup = null;
                $console = array_map(function (array $line) use (&$activeGroup): array {
                    $message = trim(self::stripAnsiEscapeSequences((string) ($line[3] ?? '')));
                    [$severity, $nextGroup] = self::classifyConsoleLine($message, $activeGroup);
                    $activeGroup = $nextGroup;

                    return array(
                        'tick' => trim((string) ($line[1] ?? '')),
                        'time' => trim((string) ($line[2] ?? '')),
                        'message' => $message,
                        'severity' => $severity,
                    );
                }, $console);
            }
        }

        return $console;
    }

    /**
     * @param array<string, mixed> $crash
     */
    private static function buildAiHeaderSection(array $crash): string
    {
        $lines = [];
        $lines[] = 'Uploaded: ' . date('M j, Y H:i', (int) ($crash['timestamp'] ?? time()));
        if (!empty($crash['ip'])) {
            $lines[] = 'Upload IP: ' . $crash['ip'];
        }
        if (!empty($crash['cmdline'])) {
            $lines[] = 'Command Line: ' . $crash['cmdline'];
        }
        foreach (($crash['metadata'] ?? []) as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $lines[] = sprintf('%s: %s', self::humanizeMetadataKeyForAi((string) $key), (string) $value);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $stack
     */
    private static function buildAiStackSection(array $stack, ?int $lineLimit = null): string
    {
        if ($stack === []) {
            return 'No processed stack frames are available.';
        }

        $lines = array_map(
            static fn (array $frame): string => sprintf('#%s %s', $frame['frame'] ?? '?', (string) ($frame['rendered'] ?? '[unknown frame]')),
            $stack
        );

        if ($lineLimit !== null && $lineLimit > 0 && count($lines) > $lineLimit) {
            $lines = array_slice($lines, 0, $lineLimit);
            $lines[] = '...[truncated]';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    private static function buildAiLikelyCauseSummary(array $candidates): string
    {
        if ($candidates === []) {
            return 'No likely-cause candidates were produced.';
        }

        $primary = $candidates[0];

        return sprintf('%s (%s, %d%%)', $primary['label'], $primary['kind'] ?? 'Candidate', (int) ($primary['percent'] ?? 0));
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    private static function buildAiLikelyCauseDetails(array $candidates): string
    {
        if ($candidates === []) {
            return '';
        }

        $lines = [];
        foreach (($candidates[0]['reasons'] ?? []) as $reason) {
            $lines[] = '- ' . $reason;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    private static function buildAiLikelyCauseSupportingSection(array $candidates): string
    {
        if (count($candidates) <= 1) {
            return '';
        }

        $lines = [];
        foreach (array_slice($candidates, 1) as $candidate) {
            $lines[] = sprintf('- %s (%s, %d%%)', $candidate['label'], $candidate['kind'] ?? 'Candidate', (int) ($candidate['percent'] ?? 0));
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    private static function buildAiLikelyCauseFullSection(array $candidates): string
    {
        if ($candidates === []) {
            return 'No likely-cause candidates were produced.';
        }

        $summary = self::buildAiLikelyCauseSummary($candidates);
        $details = self::buildAiLikelyCauseDetails($candidates);
        $supporting = self::buildAiLikelyCauseSupportingSection($candidates);
        $parts = [$summary];

        if ($details !== '') {
            $parts[] = $details;
        }

        if ($supporting !== '') {
            $parts[] = $supporting;
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    private static function buildAiLikelyCauseSection(array $candidates): string
    {
        return self::buildAiLikelyCauseFullSection($candidates);
    }

    /**
     * @param array<int, array<string, mixed>> $modules
     * @param array<string, mixed> $symbolCoverage
     */
    private static function buildAiModulesSection(array $modules, array $symbolCoverage): string
    {
        $lines = [];
        $lines[] = sprintf(
            'Coverage: %d%% (%d/%d with symbols, %d missing, %d invalid)',
            (int) ($symbolCoverage['percent'] ?? 0),
            (int) ($symbolCoverage['with_symbols'] ?? 0),
            (int) ($symbolCoverage['total'] ?? 0),
            (int) ($symbolCoverage['missing'] ?? 0),
            (int) ($symbolCoverage['invalid'] ?? 0)
        );

        foreach (array_slice($modules, 0, 30) as $module) {
            $lines[] = sprintf(
                '- %s | %s | %s | %s',
                (string) ($module['name'] ?? ''),
                (string) ($module['identifier'] ?? ''),
                (string) ($module['coverage_status'] ?? ''),
                (string) ($module['usefulness_hint'] ?? '')
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{tick: string, time: string, message: string, severity: string}> $consoleEntries
     * @param array<string, string> $snapshots
     */
    private static function buildAiConsoleSection(array $consoleEntries, array $snapshots): string
    {
        $lines = [];
        if ($consoleEntries === []) {
            $lines[] = 'No console history is available.';
        } else {
            foreach (array_slice($consoleEntries, -60) as $entry) {
                $lines[] = sprintf('[tick %s @ %s] %s', $entry['tick'], $entry['time'], $entry['message']);
            }
        }

        if (!empty($snapshots['plugins'])) {
            $lines[] = '';
            $lines[] = 'SourceMod plugins snapshot:';
            $lines[] = trim($snapshots['plugins']);
        }

        if (!empty($snapshots['extensions'])) {
            $lines[] = '';
            $lines[] = 'SourceMod extensions snapshot:';
            $lines[] = trim($snapshots['extensions']);
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{tick: string, time: string, message: string, severity: string}> $consoleEntries
     */
    private static function buildAiConsoleTailSection(array $consoleEntries, int $limit): string
    {
        if ($limit <= 0) {
            return '';
        }

        if ($consoleEntries === []) {
            return 'No console history is available.';
        }

        $lines = [];
        foreach (array_slice($consoleEntries, -$limit) as $entry) {
            $lines[] = sprintf('[tick %s @ %s] %s', $entry['tick'], $entry['time'], $entry['message']);
        }

        return implode("\n", $lines);
    }

    private static function buildAiRawSection(Application $app, string $id): string
    {
        $response = (new self())->carburetor_data($app, $id);
        $payload = json_decode((string) $response->getContent(), true);
        if (!is_array($payload)) {
            return 'Raw crash analysis is unavailable.';
        }

        $lines = [];
        if (!empty($payload['error'])) {
            $lines[] = 'Carburetor error: ' . $payload['error'];
        }
        if (!empty($payload['stderr_tail'])) {
            $lines[] = 'stderr tail:';
            $lines[] = trim((string) $payload['stderr_tail']);
        }
        if (!empty($payload['error_context']['summary'])) {
            $lines[] = 'Failure context: ' . $payload['error_context']['summary'];
        }
        if (!empty($payload['raw_stdout']) && is_string($payload['raw_stdout'])) {
            $lines[] = 'raw stdout:';
            $lines[] = trim($payload['raw_stdout']);
        } elseif (is_array($payload['fallback_stack'] ?? null)) {
            $lines[] = 'fallback stack:';
            foreach ($payload['fallback_stack'] as $frame) {
                if (is_array($frame)) {
                    $lines[] = sprintf('#%s %s', $frame['frame'] ?? '?', (string) ($frame['rendered'] ?? '[unknown frame]'));
                }
            }
        } else {
            $lines[] = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'Raw crash data unavailable.';
        }

        return implode("\n", $lines);
    }

    private static function humanizeMetadataKeyForAi(string $key): string
    {
        return trim((string) preg_replace('/(?<!^)([A-Z])/', ' $1', str_replace('_', ' ', $key)));
    }

    public function error(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_view_sensitive = self::canUserViewSensitiveCrashData($app, $id);
        if ($can_view_sensitive === null) {
            $app->abort(404);
        }

        if (!$can_view_sensitive) {
            $app->abort(403);
        }

        self::assertCrashArtifactsAvailable($app, (string) $id);

        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.dmp';

        if (!\Filesystem::pathExists($path)) {
            return $app->json(array('string' => 'Minidump file is unavailable.'));
        }

        try {
            $minidump = \Filesystem::readFile($path);

            $output = array();

            $output['header'] = $header = unpack('A4magic/Lversion/Lstream_count/Lstream_offset', $minidump);
            if ($header === false || !isset($header['stream_offset'])) {
                throw new \RuntimeException('Failed to parse minidump header.');
            }

            $stream_offset = $header['stream_offset'];
            $stream = false;
            do {
                $output['stream'] = $stream = unpack('Ltype/Lsize/Loffset', substr($minidump, $stream_offset, 16));
                $stream_offset += 16;
            } while ($stream !== false && isset($stream['type']) && $stream['type'] !== 3);

            if ($stream === false || !isset($stream['offset'])) {
                throw new \RuntimeException('Missing MD_THREAD_LIST_STREAM.');
            }

            $threadId = $app['db']->executeQuery('SELECT thread FROM crash WHERE id = ? AND processed = 1 LIMIT 1', array($id))->fetchColumn(0);
            if ($threadId === false || $threadId === null) {
                throw new \RuntimeException('No processed thread information is available.');
            }

            $output['thread'] = $thread = unpack('Lthread_id/Lsuspend_count/Lpriority_class/Lpriority/L2teb/L2stack_start/Lstack_size/Lstack_offset/Lcontext_size/Lcontext_offset', substr($minidump, $stream['offset'] + 4 + (((int) $threadId) * 48), 48));
            if ($thread === false || !isset($thread['context_offset'], $thread['stack_start1'], $thread['stack_offset'])) {
                throw new \RuntimeException('Failed to parse minidump thread information.');
            }

            $output['context_flags'] = $context_flags = unpack('Lflags', substr($minidump, $thread['context_offset'], 4));
            if ($context_flags === false || !isset($context_flags['flags']) || ($context_flags['flags'] & 0x10000) === 0) {
                throw new \RuntimeException('Bad context flags.');
            }

            $getRegisterOffset = static function (string $minidump, int|string $stackStart, int $registerOffset): int {
                $contextRegister = unpack('Lregister', substr($minidump, $registerOffset, 4));
                if ($contextRegister === false || !isset($contextRegister['register'])) {
                    throw new \RuntimeException('Failed to read minidump register state.');
                }

                return (int) bcsub(sprintf('%u', $contextRegister['register']), sprintf('%u', $stackStart));
            };

            $output['register_esp'] = $register_esp = $getRegisterOffset($minidump, $thread['stack_start1'], $thread['context_offset'] + 196);
            $output['register_ebp'] = $register_ebp = $getRegisterOffset($minidump, $thread['stack_start1'], $thread['context_offset'] + 180);

            $error_offset = 0;
            for ($i = 0; $i < 6; $i++) {
                $output['register_offset_'.$i] = $register_offset = $getRegisterOffset($minidump, $thread['stack_start1'], $thread['context_offset'] + 156 + ($i * 4));
                if ($register_offset >= $register_esp && $register_offset <= $register_ebp) {
                    $output['error_offset'] = $error_offset = $register_offset;
                    break;
                }
            }

            if ($error_offset === 0) {
                return $app->json(array('string' => 'Failed to extract error message.'));
            }

            $output['string_start'] = $string_start = $thread['stack_offset'] + $error_offset;
            $string_length = 0;

            while (isset($minidump[$string_start + $string_length]) && ord($minidump[$string_start + $string_length]) !== 0 && $string_length < 256) {
                $string_length++;
            }

            $output['string_length'] = $string_length;

            $output['error_string'] = $error_string = substr($minidump, $string_start, $string_length);

            // Remove non-ASCII chars, this needs a cleanup, but just fix the errors while encoding UTF-8 for now.
            $error_string = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '?', $error_string);

            return $app->json(array('string' => $error_string));
        } catch (\Throwable $exception) {
            return $app->json(array('string' => $exception->getMessage()));
        }
    }

    public function carburetor(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_view_sensitive = self::canUserViewSensitiveCrashData($app, $id);
        if ($can_view_sensitive === null) {
            $app->abort(404);
        }

        if (!$can_view_sensitive) {
            $app->abort(403);
        }

        self::assertCrashArtifactsAvailable($app, (string) $id);

        return $app['twig']->render('carburetor.html.twig', array(
            'id' => $id,
            'scan' => $app['request']->get('scan', null),
            'symbols' => $app['request']->get('symbols', null),
            'retention' => StorageRetentionManager::getCrashArtifactStatus($app['root'], (string) $id),
            'source_lookup_enabled' => (($app['config']['upload-settings']['crash_source_lookup_enabled'] ?? false) === true),
            'source_lookup_local_allowed' => (($app['user']['admin'] ?? false) === true),
            'ai_analysis_enabled' => (($app['config']['upload-settings']['crash_ai_analysis_enabled'] ?? false) === true),
            'public_ai_history_count' => self::countPublicAiHistory($app, (string) $id),
            'ai_history_items' => $app['crash-ai-history-items'] ?? [],
        ));
    }

    public function analyze_dump(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        if (!(($app['user']['admin'] ?? false) === true)) {
            $app->abort(403);
        }

        $can_view_sensitive = self::canUserViewSensitiveCrashData($app, $id);
        if ($can_view_sensitive === null) {
            $app->abort(404);
        }

        return $app['twig']->render('analyze_dump.html.twig', array(
            'id' => $id,
            'source_lookup_enabled' => (($app['config']['upload-settings']['crash_source_lookup_enabled'] ?? false) === true),
            'ai_analysis_enabled' => (($app['config']['upload-settings']['crash_ai_analysis_enabled'] ?? false) === true),
        ));
    }

    private static function countPublicAiHistory(Application $app, string $id): int
    {
        return (int) $app['db']->executeQuery(
            'SELECT COUNT(*) FROM crash_ai_analysis_history WHERE crash = ? AND is_public = 1',
            [$id],
        )->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function loadGlobalAiAnalysis(Application $app, ?string $stackhash): ?array
    {
        if (!is_string($stackhash) || trim($stackhash) === '') {
            return null;
        }

        $row = $app['db']->executeQuery(
            'SELECT status, provider, model, response_text, response_html, usage_json, error_summary, updated_at
             FROM crash_ai_global_analysis
             WHERE stackhash = ?',
            [trim($stackhash)]
        )->fetch();
        if ($row === false) {
            return null;
        }

        $usage = json_decode((string) ($row['usage_json'] ?? ''), true);

        return [
            'status' => (string) ($row['status'] ?? 'unknown'),
            'provider' => (string) ($row['provider'] ?? ''),
            'provider_label' => \App\Runtime\CrashAiProviderCatalog::label((string) ($row['provider'] ?? '')),
            'model' => (string) ($row['model'] ?? ''),
            'response_text' => (string) ($row['response_text'] ?? ''),
            'response_html' => (string) ($row['response_html'] ?? ''),
            'usage' => is_array($usage) ? $usage : null,
            'usage_summary' => \App\Runtime\CrashAiAnalysisManager::summarizeUsage(is_array($usage) ? $usage : null),
            'error_summary' => ($row['error_summary'] ?? null) !== null ? (string) $row['error_summary'] : null,
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }

    public function carburetor_data(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_view_sensitive = self::canUserViewSensitiveCrashData($app, $id);
        if ($can_view_sensitive === null) {
            $app->abort(404);
        }

        if (!$can_view_sensitive) {
            $app->abort(403);
        }

        self::assertCrashArtifactsAvailable($app, (string) $id);

        $options = [];
        if ($app['request']->get('scan', null) === 'no') {
            $options[] = '--no-scan';
        }

        $symbol_stores = [];
        if ($app['request']->get('symbols', null) !== 'no') {
            $symbol_stores = array_map(function ($p) use ($app) {
                return $app['root'].'/symbols/'.$p;
            }, $app['config']['symbol-stores']);

            array_unshift($symbol_stores, $app['root'].'/cache/symbols');
        }

        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.dmp';

        if (!\Filesystem::pathExists($path)) {
            $retention = StorageRetentionManager::getCrashArtifactStatus($app['root'], (string) $id);
            return new \Symfony\Component\HttpFoundation\Response(json_encode([
                'error' => ($retention['dump_deleted'] ?? false)
                    ? 'Minidump file was deleted by storage cleanup.'
                    : 'Minidump file is unavailable.',
            ]), 200, array(
                'Content-Type' => 'application/json',
            ));
        }

        set_time_limit(120);

        try {
            $carburetor = self::runCarburetor($app, $path, $symbol_stores, 120, $options);
            $stdout = $carburetor['stdout'];
            $decoded = json_decode($stdout, true);
            $hasStructuredData = is_array($decoded);
            $data = $hasStructuredData ? $decoded : array();

            if ($carburetor['exit_code'] !== 0) {
                $data['error'] = $carburetor['error'];
                $data['exit_code'] = $carburetor['exit_code'];
                $data['stderr_tail'] = $carburetor['stderr_tail'];
                $data['error_context'] = $carburetor['error_context'];
                if ($stdout !== '') {
                    $data['raw_stdout'] = $stdout;
                }
            } elseif (!$hasStructuredData) {
                $data = array(
                    'error' => 'Carburetor returned invalid data while analyzing this dump.',
                    'exit_code' => 0,
                    'stderr_tail' => $carburetor['stderr_tail'],
                    'error_context' => $carburetor['error_context'],
                );
                if ($stdout !== '') {
                    $data['raw_stdout'] = $stdout;
                }
            }
        } catch (\Throwable $exception) {
            $data = array(
                'error' => trim($exception->getMessage()) ?: 'Carburetor failed to analyze the minidump.',
            );
        }

        if (isset($data['error'])) {
            $fallbackStack = self::loadProcessedFallbackStack($app, $id);
            if ($fallbackStack !== null) {
                $data['fallback_stack'] = $fallbackStack;
                $data['problem_stack'] = array_slice($fallbackStack, 0, 8);
                $data['fallback_source'] = 'processed_stack';
            }
        }

        $data['source_lookup_modules'] = self::loadSourceLookupModuleState($app, $id);

        return new \Symfony\Component\HttpFoundation\Response(json_encode($data, JSON_UNESCAPED_SLASHES), 200, array(
            'Content-Type' => 'application/json',
        ));
    }

    public function analyze_dump_data(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        if (!(($app['user']['admin'] ?? false) === true)) {
            $app->abort(403);
        }

        $can_view_sensitive = self::canUserViewSensitiveCrashData($app, $id);
        if ($can_view_sensitive === null) {
            $app->abort(404);
        }
        if (!$can_view_sensitive) {
            $app->abort(403);
        }

        $crash = $app['db']->executeQuery('SELECT crash.id, UNIX_TIMESTAMP(crash.timestamp) AS timestamp, crash.ip AS ip, crash.owner_id AS owner, crash.server_id, crash.metadata, crash.cmdline, crash.thread, crash.processed, crash.failed, crash.stackhash, UNIX_TIMESTAMP(crash.lastview) AS lastview, crash.signature_ignored, crash.signature_ignored_reason, server_owner.name, server_owner.kind AS owner_kind FROM crash LEFT JOIN server_owner ON server_owner.id = crash.owner_id WHERE crash.id = ?', [$id])->fetch();
        $diagnostics = self::buildCrashDiagnosticContext($app, (string) $id, $crash);
        $rawResponse = $this->carburetor_data($app, $id);
        $rawPayload = json_decode((string) $rawResponse->getContent(), true);
        if (!is_array($rawPayload)) {
            $rawPayload = array(
                'error' => 'Failed to decode the raw carburetor response.',
                'raw_stdout' => (string) $rawResponse->getContent(),
            );
        }
        $presubmitDiagnostic = self::loadRelevantPresubmitDiagnostic(
            $app,
            isset($diagnostics['crash']['ip']) && is_string($diagnostics['crash']['ip']) ? $diagnostics['crash']['ip'] : null,
            isset($diagnostics['crash']['timestamp']) ? (int) $diagnostics['crash']['timestamp'] : null,
        );

        $modulesSentByDump = null;
        if (isset($rawPayload['modules']) && is_array($rawPayload['modules'])) {
            $modulesSentByDump = count($rawPayload['modules']);
        } elseif (isset($rawPayload['loaded_modules']) && is_array($rawPayload['loaded_modules'])) {
            $modulesSentByDump = count($rawPayload['loaded_modules']);
        }

        $payload = array(
            'crash' => array(
                'id' => $diagnostics['crash']['id'],
                'timestamp' => $diagnostics['crash']['timestamp'],
                'owner' => $diagnostics['crash']['owner'],
                'owner_name' => $diagnostics['crash']['name'] ?? null,
                'stackhash' => $diagnostics['crash']['stackhash'],
                'thread' => $diagnostics['crash']['thread'],
                'cmdline' => $diagnostics['crash']['cmdline'],
                'metadata' => $diagnostics['crash']['metadata'],
                'processed' => $diagnostics['crash']['processed'],
                'failed' => $diagnostics['crash']['failed'],
                'has_console_log' => $diagnostics['crash']['has_console_log'],
                'dump_available' => $diagnostics['crash']['dump_available'],
            ),
            'notices' => $diagnostics['notices'],
            'stats' => $diagnostics['stats'],
            'stack' => $diagnostics['stack'],
            'modules' => $diagnostics['modules'],
            'symbol_coverage' => $diagnostics['symbol_coverage'],
            'culprit_candidates' => $diagnostics['culprit_candidates'],
            'culprit_groups' => $diagnostics['culprit_groups'],
            'likely_cause_symbol_status' => $diagnostics['likely_cause_symbol_status'],
            'processing_log' => $diagnostics['processing_log'],
            'sourcemod_snapshots' => $diagnostics['sourcemod_snapshots'],
            'symbol_upload_log' => $diagnostics['symbol_upload_log'],
            'verified_source_lookup_frames' => $diagnostics['verified_source_lookup_frames'],
            'source_lookup_enabled' => $diagnostics['source_lookup_enabled'],
            'raw_analysis' => $rawPayload,
            'presubmit_diagnostics' => array(
                'raw_module_count' => $presubmitDiagnostic['raw_module_count'] ?? null,
                'parsed_module_count' => $presubmitDiagnostic['parsed_module_count'] ?? $modulesSentByDump,
                'response_flag_count' => $presubmitDiagnostic['response_flag_count'] ?? null,
                'diagnostic_category' => $presubmitDiagnostic['diagnostic_category'] ?? null,
                'crash_signature_length' => $presubmitDiagnostic['crash_signature_length'] ?? null,
                'response_mode' => $presubmitDiagnostic['response_mode'] ?? null,
                'recorded_at' => $presubmitDiagnostic['timestamp'] ?? null,
            ),
            'upload_diagnostics' => array(
                'modules_sent_by_dump' => $modulesSentByDump,
                'modules_with_symbols' => $diagnostics['symbol_coverage']['with_symbols'] ?? 0,
                'missing' => $diagnostics['symbol_coverage']['missing'] ?? 0,
                'invalid' => $diagnostics['symbol_coverage']['invalid'] ?? 0,
            ),
            'presubmit_debug_record' => $presubmitDiagnostic,
        );

        return new \Symfony\Component\HttpFoundation\Response(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 200, array(
            'Content-Type' => 'application/json',
        ));
    }

    public function reprocess(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        if (!$app['user']['admin']) {
            $app->abort(403);
        }

        $app['db']->transactional(function($db) use ($id) {
            $db->executeUpdate('DELETE FROM frame WHERE crash = ?', array($id));
            $db->executeUpdate('DELETE FROM module WHERE crash = ?', array($id));
            $db->executeUpdate('DELETE FROM crashnotice WHERE crash = ?', array($id));

            $db->executeUpdate('UPDATE crash SET thread = NULL, processed = FALSE, failed = FALSE, stackhash = NULL WHERE id = ?', array($id));
        });

        $return = self::getSafeReturnPath($app, $app['request']->get('return', null), 'dashboard');

        return $app->redirect($return);
    }

    public function delete(Application $app, $id)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $can_manage = self::canUserManage($app, $id);
        if ($can_manage === null) {
            $app->abort(404);
        }

        if (!$can_manage) {
            $app->abort(403);
        }

        $app['db']->executeUpdate('DELETE FROM crash WHERE id = ?', array($id));

        $return = self::getSafeReturnPath($app, $app['request']->get('return', null), 'dashboard');

        return $app->redirect($return);
    }

    public function dashboard(Application $app, $offset)
    {
        if ($app['user'] === null) {
            $app->abort(401);
        }

        $shared = $app['user']['owners'];
        $userid = $app['request']->get('user', null);
        if ($userid !== null && ctype_digit((string) $userid)) {
            $userid = (int) $userid;
        } else {
            $userid = null;
        }

        $hideIgnored = $app['request']->get('hide_ignored', '1') !== '0';
        $previous = $app['request']->get('previous', null);
        $previous = ctype_digit((string) $previous) ? (int) $previous : null;
        if ($previous !== null && ($offset === null || $previous <= $offset)) {
            $previous = null;
        }

        $allowed = null;
        if (!$app['user']['admin']) {
            $allowed = $app['user']['owner_ids'];

            if ($userid !== null && !in_array($userid, $allowed, true)) {
                $app->abort(403);
            }
        }

        $where = '';
        $params = [];
        $types = [];

        if ($offset !== null || $userid !== null || $allowed !== null) {
            $where .= 'WHERE ';


            if ($userid !== null || $allowed !== null) {
                if ($userid !== null) {
                    $where .= 'crash.owner_id = ?';
                    $params[] = $userid;
                    $types[] = \PDO::PARAM_INT;
                } else if ($allowed !== null) {
                    $where .= 'crash.owner_id IN (?)';
                    $params[] = $allowed;
                    $types[] = \Doctrine\DBAL\ArrayParameterType::INTEGER;
                }

                if ($offset !== null) {
                    $where .= ' AND ';
                }
            }

            if ($offset !== null) {
                $where .= 'timestamp < FROM_UNIXTIME(?)';
                $params[] = $offset;
                $types[] = \PDO::PARAM_INT;
            }
        }

        if ($hideIgnored) {
            $where .= $where === '' ? 'WHERE ' : ' AND ';
            $where .= 'COALESCE(crash.signature_ignored, 0) = 0';
        }

        $crashes = $app['db']->executeQuery('SELECT crash.id, UNIX_TIMESTAMP(crash.timestamp) as timestamp, crash.owner_id AS owner, crash.cmdline, crash.processed, crash.failed, crash.signature_ignored, crash.signature_ignored_reason, server_owner.name, NULL AS avatar, frame.module, frame.rendered, frame2.module as module2, frame2.rendered AS rendered2, (SELECT CONCAT(COUNT(*), \'-\', MIN(notice.severity)) FROM crashnotice JOIN notice ON crashnotice.notice = notice.id WHERE crashnotice.crash = crash.id) AS notice FROM crash LEFT JOIN server_owner ON crash.owner_id = server_owner.id LEFT JOIN frame ON crash.id = frame.crash AND crash.thread = frame.thread AND frame.frame = 0 LEFT JOIN frame AS frame2 ON crash.id = frame2.crash AND crash.thread = frame2.thread AND frame2.frame = 1 ' . $where . ' ORDER BY crash.timestamp DESC LIMIT 20', $params, $types)->fetchAll();

        foreach ($crashes as &$crash) {
            foreach ($shared as $owner) {
                if ($owner['id'] === $crash['owner']) {
                    $crash['avatar'] = $owner['avatar'];
                    break;
                }
            }
        }
        unset($crash);

        return $app['twig']->render('dashboard.html.twig', array(
            'userid' => $userid,
            'shared' => $shared,
            'offset' => $offset,
            'previous' => $previous,
            'hide_ignored' => $hideIgnored,
            'crashes' => $crashes,
        ));
    }
}

