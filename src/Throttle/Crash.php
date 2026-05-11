<?php

namespace Throttle;

use App\Runtime\UploadFailureBackoff;
use Silex\Application;

class Crash
{
    private const SIGNATURE_NOTES_PER_PAGE = 5;

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

        $userId = $app['db']->executeQuery('SELECT id FROM user WHERE upload_token = ? LIMIT 1', [$provided])->fetchColumn(0);

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

    private static function canUserManage($app, $crash)
    {
        $ownerId = $app['db']->executeQuery('SELECT owner_id FROM crash WHERE crash.id = ?', [$crash])->fetchColumn(0);
        if ($ownerId === false) {
            return null;
        }

        if (!$app['user']) {
            return false;
        }

        if ($app['user']['admin']) {
            return true;
        }

        return $ownerId !== null && in_array((int) $ownerId, $app['user']['owner_ids'], true);
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

    private static function renderMarkdown(string $markdown): string
    {
        $lines = preg_split('/\R/', trim(str_replace("\r\n", "\n", $markdown))) ?: array();
        $html = array();
        $list = null;
        $paragraph = array();

        $flushParagraph = static function () use (&$html, &$paragraph): void {
            if (empty($paragraph)) {
                return;
            }

            $html[] = '<p>' . implode('<br>', array_map([self::class, 'renderMarkdownInline'], $paragraph)) . '</p>';
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
        $hasSourcePawnChain = $sourcePawnChain !== null;
        $hasSourcePawnBridge = $hasSourcePawnChain || self::stackHasSourcePawnBridge($stack);
        $hasConsoleBackedBridgeCause = !$hasSourcePawnChain && $consoleBlaming !== null && $hasSourcePawnBridge;
        $hasTerminalConsoleBridgeCause = !$hasSourcePawnChain && $terminalConsoleCause !== null && !empty($terminalConsoleCause['plugin']) && $hasSourcePawnBridge;
        $sourcePawnPlugin = $sourcePawnChain !== null ? basename((string) $sourcePawnChain['plugin']) : null;
        if ($sourcePawnChain !== null) {
            self::addCulpritCandidate(
                $candidates,
                $sourcePawnChain['plugin'],
                $sourcePawnChain['score'],
                $sourcePawnChain['reasons'],
                'Likely plugin cause'
            );
        }

        if ($consoleBlaming !== null && ($sourcePawnPlugin === null || strcasecmp(basename($consoleBlaming), $sourcePawnPlugin) === 0)) {
            self::addCulpritCandidate(
                $candidates,
                basename($consoleBlaming),
                $hasSourcePawnChain ? 34 : ($hasSourcePawnBridge ? 180 : 110),
                array_filter(array(
                    'Console log Blaming entry: ' . basename($consoleBlaming),
                    $hasConsoleBackedBridgeCause ? 'SourceMod bridge frames present; plugin debug frames are missing from stackwalk' : null,
                )),
                $hasSourcePawnChain ? 'Supporting signal' : 'Likely plugin cause'
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
                $hasSourcePawnChain ? 42 : ($hasSourcePawnBridge ? 210 : 130),
                $reasons,
                $hasSourcePawnChain ? 'Supporting signal' : 'Likely plugin cause'
            );
        }

        foreach ($stack as $index => $frame) {
            $module = (string) ($frame['module'] ?? '');
            $function = (string) ($frame['function'] ?? '');
            $rendered = (string) ($frame['rendered'] ?? '');
            $label = self::candidateLabelFromFrame($module, $function, $rendered);

            if ($label === null) {
                continue;
            }

            $score = max(8, 42 - ($index * 4));
            $kind = 'Candidate';
            $reasons = array('Frame #' . ($frame['frame'] ?? $index) . ': ' . $rendered);
            $isSmxLabel = str_ends_with(strtolower($label), '.smx');
            $isBridgeFrame = self::isBridgeFrame($module, $function, $rendered, $label);

            if ($index === 0) {
                if (($hasSourcePawnChain || $hasConsoleBackedBridgeCause || $hasTerminalConsoleBridgeCause) && self::isServerEngineModule($label)) {
                    $score += 8;
                    $kind = 'Native failure site';
                    $reasons[] = $hasSourcePawnChain ? 'Native crash site reached from SourcePawn/JIT chain' : 'Native crash site reached from SourceMod evidence';
                } else {
                    $score += 28;
                }
            }

            if (self::isPluginLikeLabel($label)) {
                if ($isBridgeFrame && !$isSmxLabel) {
                    $score -= 18;
                    $kind = 'Bridge';
                    $reasons[] = 'SourceMod bridge frame';
                } else {
                    $score += $isSmxLabel ? 44 : 22;
                    $kind = $isSmxLabel ? 'Likely plugin cause' : 'Bridge';
                    $reasons[] = $isSmxLabel ? 'SourceMod plugin frame' : 'SourceMod extension module';
                }
            } elseif ($isBridgeFrame) {
                $score -= 24;
                $kind = 'Bridge';
                $reasons[] = 'SourcePawn/JIT bridge frame';
            }

            if (self::isServerEngineModule($label)) {
                $score += ($hasSourcePawnChain || $hasConsoleBackedBridgeCause || $hasTerminalConsoleBridgeCause) ? 4 : 14;
                if ($kind === 'Candidate') {
                    $kind = $index === 0 ? 'Native failure site' : 'Native module';
                }
                $reasons[] = 'Game or Source engine module';
            }

            if (preg_match('/__SourceHook_/i', $rendered) === 1) {
                $score -= 55;
                $kind = 'Bridge';
                $reasons[] = 'Hook frame';
            }

            if (self::isSystemRuntimeModule($label) || self::isBootstrapRuntimeModule($label)) {
                $score -= 60;
                $kind = 'Runtime';
                $reasons[] = 'System/runtime module';
            }

            $moduleKey = $module !== '' && isset($presentByModule[$module]) ? $module : basename(str_replace('\\', '/', $module));
            if ($moduleKey !== '' && isset($presentByModule[$moduleKey]) && !$presentByModule[$moduleKey]) {
                $score -= ($hasSourcePawnChain || $hasConsoleBackedBridgeCause || $hasTerminalConsoleBridgeCause) && self::isServerEngineModule($label) ? 10 : 18;
                $reasons[] = 'Module has no symbols';
            }

            self::addCulpritCandidate($candidates, $label, $score, $reasons, $kind);

            $sourcePawnFrame = self::parseSourcePawnFrame($rendered);
            if ($sourcePawnFrame !== null) {
                self::addCulpritCandidate($candidates, $sourcePawnFrame['plugin'], $score + 28, array(
                    'SourcePawn function: ' . $sourcePawnFrame['function'],
                    self::isSourcePawnEntryPoint($sourcePawnFrame['function']) ? 'Plugin entry point' : 'Plugin callsite',
                ), self::isSourcePawnEntryPoint($sourcePawnFrame['function']) ? 'Entry point' : 'Likely plugin cause');
            }
        }

        foreach (array('Plugin', 'SourceModPlugin') as $key) {
            if (!empty($metadata[$key]) && is_string($metadata[$key])) {
                self::addCulpritCandidate($candidates, basename($metadata[$key]), 28, 'Crash metadata field: ' . $key, 'Supporting signal');
            }
        }

        foreach (array('Extension', 'SourceModExtension') as $key) {
            if (!empty($metadata[$key]) && is_string($metadata[$key])) {
                self::addCulpritCandidate($candidates, basename($metadata[$key]), 24, 'Crash metadata field: ' . $key, 'Bridge');
            }
        }

        if (is_string($cmdline) && preg_match('/\+map\s+([^ ]+)/', $cmdline, $matches) === 1) {
            self::addCulpritCandidate($candidates, 'Map: ' . $matches[1], 8, 'Active map from command line', 'Context');
        }

        if (empty($candidates)) {
            return array();
        }

        uasort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
        $top = array_slice($candidates, 0, 6, true);
        $total = array_sum(array_column($top, 'score'));

        return array_map(function ($candidate) use ($total) {
            $candidate['percent'] = $total > 0 ? (int) round(($candidate['score'] / $total) * 100) : 0;
            $candidate['reasons'] = array_slice(array_values(array_unique($candidate['reasons'])), 0, 4);
            $candidate['kind_class'] = self::culpritKindClass($candidate['kind']);
            $candidate['kind_title'] = self::culpritKindTitle($candidate['kind']);
            $candidate['category'] = self::culpritCategory($candidate['kind']);
            $candidate['category_label'] = self::culpritCategoryLabel($candidate['category']);

            return $candidate;
        }, array_values($top));
    }

    private static function addCulpritCandidate(array &$candidates, string $label, int $score, string|array $reasons, string $kind = 'Candidate'): void
    {
        $label = trim($label);
        if ($label === '' || $score <= 0) {
            return;
        }

        if (!isset($candidates[$label])) {
            $candidates[$label] = array('label' => $label, 'score' => 0, 'percent' => 0, 'kind' => $kind, 'reasons' => array());
        }

        $candidates[$label]['score'] += max(1, $score);
        if (self::culpritKindPriority($kind) > self::culpritKindPriority((string) $candidates[$label]['kind'])) {
            $candidates[$label]['kind'] = $kind;
        }

        foreach ((array) $reasons as $reason) {
            if (is_string($reason) && trim($reason) !== '') {
                $candidates[$label]['reasons'][] = $reason;
            }
        }
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
            $future = new \ExecFuture('%s %s %Ls', $carburetorPath, $dumpPath, $symbolStores);
            $future->setTimeout(20);
            [$stdout,] = $future->resolvex();
            $data = json_decode($stdout, true);
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

        try {
            $signature = self::parsePresubmitSignature($signature);
        } catch (\Exception $e) {
            $app['monolog']->warning('Error parsing presubmit: '.$signature, [$e]);

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
                $ownerId = $app['owner_resolver']->resolveOwnerIdFromLegacyIdentifier($legacyOwnerId);
            }
        }

        $serverId = $app['request']->request->get('ServerID');
        if ($serverId !== null) {
            $app['request']->request->remove('ServerID');

            if (ctype_digit((string) $serverId)) {
                $server = $app['db']->executeQuery('SELECT id, owner_id FROM server WHERE id = ?', [(int) $serverId])->fetch();
                if ($server !== false) {
                    $serverId = (int) $server['id'];
                    $ownerId = (int) $server['owner_id'];
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
        $can_manage = self::canUserManage($app, $id);
        if ($can_manage === null) {
            if ($app['session']->getFlashBag()->get('internal')) {
                $app['session']->getFlashBag()->add('error_crash', 'That Crash ID does not exist.');

                return $app->redirect($app['url_generator']->generate('index'));
            }

            return $app->abort(404);
        }

        $crash = $app['db']->executeQuery('SELECT crash.id, UNIX_TIMESTAMP(crash.timestamp) AS timestamp, crash.ip AS ip, crash.owner_id AS owner, crash.server_id, crash.metadata, crash.cmdline, crash.thread, crash.processed, crash.failed, crash.stackhash, UNIX_TIMESTAMP(crash.lastview) AS lastview, server_owner.name FROM crash LEFT JOIN server_owner ON server_owner.id = crash.owner_id WHERE crash.id = ?', [$id])->fetch();

        if ($crash['lastview'] === null || (time() - $crash['lastview']) > (60 * 60 * 24)) {
            $app['db']->executeUpdate('UPDATE crash SET lastview = NOW() WHERE id = ?', array($id));
        }

        if ($crash['thread'] == -1) {
            $crash['thread'] = 0;
        }

        $crash['cmdline'] = preg_replace_callback(array_map(function($v) {
            return sprintf('/(?<=%s )[^ ]+/', preg_quote($v));
        }, [
            '+sv_password',
            '+rcon_password',
            '+sv_setsteamaccount',
        ]), function($matches) {
            return str_repeat('*', strlen($matches[0]));
        }, $crash['cmdline']);

        $crash['metadata'] = json_decode($crash['metadata'], true);

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

        $terminalConsoleCause = self::loadTerminalSourceModCause($app, $id, (bool) $crash['has_console_log']);
        $crash['dump_available'] = \Filesystem::pathExists($app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.dmp');

        ksort($crash['metadata']);

        $notices = $app['db']->executeQuery('SELECT severity, text FROM crashnotice JOIN notice ON notice.id = crashnotice.notice WHERE crash = ?', array($id))->fetchAll();
        $stack = $app['db']->executeQuery('SELECT frame, module, function, rendered, url FROM frame WHERE crash = ? AND thread = ? ORDER BY frame', array($id, $crash['thread']))->fetchAll();
        $modules = $app['db']->executeQuery('SELECT name, identifier, processed, present, HEX(base) AS base FROM module WHERE crash = ? ORDER BY name', array($id))->fetchAll();
        $modules = self::buildModuleCoverageRows($modules, $app['config']);
        $rawSourcePawnChain = self::loadRawSourcePawnCauseChain($app, $id, $stack, $crash['metadata']);
        $culpritCandidates = self::buildCulpritCandidates($stack, $modules, $crash['metadata'], $crash['cmdline'], $terminalConsoleCause['blaming'] ?? null, $terminalConsoleCause, $rawSourcePawnChain);
        $stats = $app['db']->executeQuery('SELECT COUNT(DISTINCT crash.owner_id) AS owners, COUNT(DISTINCT crash.ip) AS ips, COUNT(*) AS crashes FROM crash, (SELECT owner_id, stackhash FROM crash WHERE id = ?) AS this WHERE this.stackhash = crash.stackhash', [$id])->fetch();
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
        $processing_log = $app['db']->executeQuery('SELECT created_at, status, duration_ms, message FROM crash_processing_log WHERE crash = ? ORDER BY created_at DESC LIMIT 1', [$id])->fetch();
        if ($processing_log === false) {
            $processing_log = null;
        }

        $outdated = false;
        if ($app['config']['accelerator']) {
            $outdated = !isset($crash['metadata']['ExtensionVersion']) || version_compare($crash['metadata']['ExtensionVersion'], $app['config']['accelerator'], '<');
        }

        $has_error_string = false;
        if (isset($stack[0]['rendered'])) {
            $has_error_string = preg_match('/^engine(_srv)?\\.so!Sys_Error(_Internal)?\\(/', $stack[0]['rendered']) === 1;
        }

        $show_sourcepawn_message = false;
        if (isset($crash['metadata']['SourceModVersion']) && version_compare($crash['metadata']['SourceModVersion'], '1.10.0.6431', '<')) {
            foreach ($stack as $frame) {
                if (preg_match('/^sourcepawn\\.jit\\.[^!]+!sp::[^:]+::Invoke/', $frame['rendered']) === 1) {
                    $show_sourcepawn_message = true;
                    break;
                }
            }
        }

        return $app['twig']->render('details.html.twig', array(
            'crash' => $crash,
            'can_manage' => $can_manage,
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
            'outdated' => $outdated,
            'has_error_string' => $has_error_string,
            'show_sourcepawn_message' => $show_sourcepawn_message,
            'symbol_coverage' => self::buildSymbolCoverage($modules),
            'culprit_candidates' => $culpritCandidates,
            'culprit_groups' => self::groupCulpritCandidates($culpritCandidates),
            'processing_log' => $processing_log,
            'sourcemod_snapshots' => $snapshots,
            'symbol_upload_log' => self::loadSymbolUploadLog($app, $modules),
        ));
    }

    public function symbols(Application $app, $id)
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
        return self::extractTerminalSourceModCause($contents)['blaming'] ?? null;
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

        $best = null;
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
                $best = $block;
            }
        }

        if ($best === null || ($lineCount - 1 - (int) $best['last_relevant']) > 80) {
            return null;
        }

        return array(
            'plugin' => $best['plugin'] ?? $best['blaming'],
            'function' => $best['function'],
            'exception' => $best['exception'],
            'blaming' => $best['blaming'],
        );
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

        $can_manage = self::canUserManage($app, $id);
        if ($can_manage === null) {
            $app->abort(404);
        }

        if (!$can_manage) {
            $app->abort(403);
        }

        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.dmp';

        if (!\Filesystem::pathExists($path)) {
            $app->abort(404);
        }

        return $app->sendFile($path)->setContentDisposition(\Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'crash_' . $id . '.dmp');
    }

    public function view(Application $app, $id)
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

        return $app['twig']->render('view.html.twig', array('id' => $id));
    }

    public function logs(Application $app, $id)
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

        $processing_logs = $app['db']->executeQuery('SELECT created_at, status, duration_ms, message, log FROM crash_processing_log WHERE crash = ? ORDER BY created_at DESC LIMIT 10', [$id])->fetchAll();

        $path = $app['root'] . '/dumps/' . substr($id, 0, 2) . '/' . $id . '.txt';

        $logs = null;
        if (\Filesystem::pathExists($path . '.gz')) {
            $logs = gzdecode(\Filesystem::readFile($path . '.gz'));
        } else if (\Filesystem::pathExists($path)) {
            $logs = \Filesystem::readFile($path);
        }

        return $app['twig']->render('logs.html.twig', array('id' => $id, 'logs' => $logs, 'processing_logs' => $processing_logs));
    }

    public function metadata(Application $app, $id)
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

        $can_manage = self::canUserManage($app, $id);
        if ($can_manage === null) {
            $app->abort(404);
        }

        if (!$can_manage) {
            $app->abort(403);
        }

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

    public function error(Application $app, $id)
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

        $can_manage = self::canUserManage($app, $id);
        if ($can_manage === null) {
            $app->abort(404);
        }

        if (!$can_manage) {
            $app->abort(403);
        }

        return $app['twig']->render('carburetor.html.twig', array(
            'id' => $id,
            'scan' => $app['request']->get('scan', null),
            'symbols' => $app['request']->get('symbols', null),
        ));
    }

    public function carburetor_data(Application $app, $id)
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
            return new \Symfony\Component\HttpFoundation\Response(json_encode([
                'error' => 'Minidump file is unavailable.',
            ]), 200, array(
                'Content-Type' => 'application/json',
            ));
        }

        set_time_limit(120);

        try {
            list($stdout, $stderr) = execx($app['root'].'/bin/carburetor %Ls %s %Ls', $options, $path, $symbol_stores);
        } catch (\Throwable $exception) {
            $stdout = json_encode([
                'error' => trim($exception->getMessage()) ?: 'Carburetor failed to analyze the minidump.',
            ]);
        }

        return new \Symfony\Component\HttpFoundation\Response($stdout, 200, array(
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

        $crashes = $app['db']->executeQuery('SELECT crash.id, UNIX_TIMESTAMP(crash.timestamp) as timestamp, crash.owner_id AS owner, crash.cmdline, crash.processed, crash.failed, server_owner.name, NULL AS avatar, frame.module, frame.rendered, frame2.module as module2, frame2.rendered AS rendered2, (SELECT CONCAT(COUNT(*), \'-\', MIN(notice.severity)) FROM crashnotice JOIN notice ON crashnotice.notice = notice.id WHERE crashnotice.crash = crash.id) AS notice FROM crash LEFT JOIN server_owner ON crash.owner_id = server_owner.id LEFT JOIN frame ON crash.id = frame.crash AND crash.thread = frame.thread AND frame.frame = 0 LEFT JOIN frame AS frame2 ON crash.id = frame2.crash AND crash.thread = frame2.thread AND frame2.frame = 1 ' . $where . ' ORDER BY crash.timestamp DESC LIMIT 20', $params, $types)->fetchAll();

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
            'crashes' => $crashes,
        ));
    }
}

