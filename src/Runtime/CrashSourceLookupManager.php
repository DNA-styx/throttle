<?php

namespace App\Runtime;

use Doctrine\DBAL\Connection;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CrashSourceLookupManager
{
    private const LOOKUP_DEADLINE_SECONDS = 6.0;
    private const HTTP_TIMEOUT_SECONDS = 2.5;
    private const PROCESS_TIMEOUT_SECONDS = 4.0;
    private const GIT_PROCESS_TIMEOUT_SECONDS = 4.0;
    private const CTAGS_PROCESS_TIMEOUT_SECONDS = 15.0;
    private const EXECUTABLE_CHECK_TIMEOUT_SECONDS = 2.0;
    private const MAX_GITHUB_CANDIDATE_FILES = 40;
    private const MAX_LOCAL_CANDIDATE_FILES = 40;
    private const MAX_LOCAL_TREE_FILES = 250;
    private const GITHUB_FETCH_BATCH_SIZE = 6;
    private const DIRECT_FETCH_FALLBACK_COUNT = 4;
    private const MAX_DIRECT_GITHUB_CANDIDATE_FILES = 16;
    private const CACHE_PATH = '/var/source-cache';
    private const GITHUB_FILE_CACHE = '/var/source-cache/github-files';
    private const GITHUB_TREE_CACHE = '/var/source-cache/github-trees';
    private const GITHUB_SEARCH_CACHE = '/var/source-cache/github-search';
    private const LOCAL_INDEX_CACHE = '/var/source-cache/local-index';
    private const GITHUB_SOURCE = 'github';
    private const LOCAL_SOURCE = 'local';
    private const SAVED_SOURCE = 'saved';
    private const INVALID_IDENTIFIER = '000000000000000000000000000000000';
    /** @var list<string> */
    private const SOURCE_EXTENSIONS = ['cpp', 'cxx', 'cc', 'c', 'hpp', 'hh', 'hxx', 'h', 'inl', 'ipp', 'php'];
    /** @var list<string> */
    private const GITHUB_PREFIXES = [
        'src',
        'source',
        'include',
        'lib',
        'app',
        'server',
        'client',
        'shared',
        'game',
        'code',
        '',
    ];
    /** @var list<string> */
    private const LOW_SIGNAL_TOKENS = ['sv', 'tf', 'mp', 'ai', 'so', 'dll', 'srv', 'ext', 'lib', 'bin', 'mod', 'src'];

    public function __construct(
        private readonly Connection $connection,
        private readonly HttpClientInterface $httpClient,
        private readonly string $projectDir,
    ) {
    }

    public function isEnabled(): bool
    {
        return (UploadSettings::load($this->projectDir)['crash_source_lookup_enabled'] ?? false) === true;
    }

    /**
     * @return array<string, array{module_basename: string, module_identifier: ?string, has_cache: bool, has_mapping: bool}>
     */
    public function describeModulesForCrash(string $crashId): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $modules = [];
        foreach ($this->loadCrashModules($crashId) as $row) {
            $basename = $this->normalizeModuleBasename((string) ($row['name'] ?? ''));
            if ($basename === '') {
                continue;
            }

            $identifier = (string) ($row['identifier'] ?? '');
            if ($identifier === '' || $identifier === self::INVALID_IDENTIFIER) {
                $identifier = null;
            }

            $key = mb_strtolower($basename);
            if (isset($modules[$key]) && $modules[$key]['module_identifier'] !== null) {
                continue;
            }

            $modules[$key] = [
                'module_basename' => $basename,
                'module_identifier' => $identifier,
                'has_cache' => $identifier !== null && $this->getCachedResult($basename, $identifier) !== null,
                'has_mapping' => $identifier !== null && $this->getSavedMapping($basename, $identifier) !== null,
            ];
        }

        return $modules;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function describeForCrash(string $crashId, array $input, bool $allowLocalOverride): array
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException('Source lookup is disabled.');
        }

        $moduleInfo = $this->resolveCrashModule($crashId, (string) ($input['module'] ?? ''), (string) ($input['symbol'] ?? ''));
        $mapping = $moduleInfo['module_identifier'] !== null
            ? $this->getSavedMapping($moduleInfo['module_basename'], $moduleInfo['module_identifier'])
            : null;
        $cache = $moduleInfo['module_identifier'] !== null
            ? $this->getCachedResult($moduleInfo['module_basename'], $moduleInfo['module_identifier'])
            : null;

        return $this->buildStateResponse($moduleInfo, $mapping, $cache, $allowLocalOverride);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function lookupForCrash(string $crashId, array $input, bool $canPersistMapping, bool $allowLocalOverride): array
    {
        if (!$this->isEnabled()) {
            throw new \RuntimeException('Source lookup is disabled.');
        }

        $moduleInfo = $this->resolveCrashModule($crashId, (string) ($input['module'] ?? ''), (string) ($input['symbol'] ?? ''));
        if ($moduleInfo['module_basename'] === '' || $moduleInfo['symbol'] === '') {
            throw new \InvalidArgumentException('Module and symbol are required.');
        }
        if ($moduleInfo['module_identifier'] === null) {
            throw new \RuntimeException(sprintf('No Breakpad module identifier was found for %s in this crash.', $moduleInfo['module_basename']));
        }

        $savedMapping = $this->getSavedMapping($moduleInfo['module_basename'], $moduleInfo['module_identifier']);
        $sourceType = trim((string) ($input['source_type'] ?? self::GITHUB_SOURCE));
        $effective = match ($sourceType) {
            self::SAVED_SOURCE => $this->resolveSavedMapping($moduleInfo, $savedMapping),
            self::GITHUB_SOURCE => $this->resolveGithubMapping($input),
            self::LOCAL_SOURCE => $this->resolveLocalMapping($input, $allowLocalOverride),
            default => throw new \InvalidArgumentException('Unsupported source type.'),
        };

        $parsed = $this->parseSymbol($moduleInfo['runtime_module'], $moduleInfo['symbol']);
        $reload = !empty($input['reload']);
        $lookup = $effective['source_type'] === self::GITHUB_SOURCE
            ? $this->findBestGithubMatch($effective['github_repo_url'], $effective['github_ref'], (string) ($input['github_pat'] ?? ''), $parsed, $moduleInfo['module_basename'], $reload)
            : $this->findBestLocalMatch($effective['local_root'], $parsed, $moduleInfo['module_basename']);
        $lookup['warnings'] = $this->sanitizeLookupWarnings($lookup['warnings'] ?? []);
        $existingCache = $this->getCachedResult($moduleInfo['module_basename'], $moduleInfo['module_identifier']);

        if ($lookup['match'] === null) {
            $summaryReason = $this->buildShortNoMatchReason($parsed, $effective, (bool) ($lookup['repository_miss'] ?? false), $lookup['warnings'] ?? []);
            if ($existingCache !== null) {
                $summaryReason = 'No better source result was found. Keeping the cached result.';
            }

            return $this->buildStateResponse(
                $moduleInfo,
                $savedMapping,
                $existingCache,
                $allowLocalOverride,
                [
                    'status' => 'no_match',
                    'summary_reason' => $summaryReason,
                    'reason' => $this->buildNoMatchReason($parsed, $lookup['attempted_paths'], $effective, $lookup['warnings'] ?? [], (bool) ($lookup['repository_miss'] ?? false)),
                    'resolved_repo' => $effective['github_repo_url'],
                    'resolved_ref' => $effective['github_ref'],
                    'input_repo' => $effective['github_repo_url'],
                    'input_ref' => $effective['github_ref'],
                    'input_local_root' => $effective['local_root'],
                    'input_source_type' => $effective['source_type'],
                    'candidate_paths' => $lookup['attempted_paths'],
                    'provider_trace' => $lookup['provider_trace'] ?? [],
                    'rejection_reasons' => $lookup['rejection_reasons'] ?? [],
                ]
            );
        }

        if ($canPersistMapping && $sourceType !== self::SAVED_SOURCE) {
            $this->saveMapping($moduleInfo['module_basename'], $moduleInfo['module_identifier'], $effective);
            $savedMapping = $this->getSavedMapping($moduleInfo['module_basename'], $moduleInfo['module_identifier']);
        }

        $this->saveCachedResult(
            $moduleInfo['module_basename'],
            $moduleInfo['module_identifier'],
            $effective,
            $lookup['match'],
            $lookup['provider_trace'] ?? [],
            $lookup['rejection_reasons'] ?? []
        );

        $cache = $this->getCachedResult($moduleInfo['module_basename'], $moduleInfo['module_identifier']);

        return $this->buildStateResponse(
            $moduleInfo,
            $savedMapping,
            $cache,
            $allowLocalOverride,
            [
                'status' => 'ok',
                'mapping_saved' => $canPersistMapping && $sourceType !== self::SAVED_SOURCE,
                'cache_hit' => true,
                'input_repo' => $effective['github_repo_url'],
                'input_ref' => $effective['github_ref'],
                'input_local_root' => $effective['local_root'],
                'input_source_type' => $effective['source_type'],
                'candidate_paths' => $lookup['attempted_paths'],
                'provider_trace' => $lookup['provider_trace'] ?? [],
                'rejection_reasons' => $lookup['rejection_reasons'] ?? [],
            ]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSavedMapping(string $moduleBasename, string $moduleIdentifier): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT module_basename, module_identifier, source_type, github_repo_url, github_ref, local_root, updated_at
             FROM source_lookup_mapping
             WHERE module_basename = ? AND module_identifier = ?',
            [$moduleBasename, $moduleIdentifier]
        );

        if (!is_array($row) || $row === []) {
            return null;
        }

        return [
            'module_basename' => (string) $row['module_basename'],
            'module_identifier' => (string) $row['module_identifier'],
            'source_type' => (string) $row['source_type'],
            'github_repo_url' => $row['github_repo_url'] !== null ? (string) $row['github_repo_url'] : null,
            'github_ref' => $row['github_ref'] !== null ? (string) $row['github_ref'] : null,
            'local_root' => $row['local_root'] !== null ? (string) $row['local_root'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getCachedResult(string $moduleBasename, string $moduleIdentifier): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT module_basename, module_identifier, source_type, github_repo_url, github_ref, resolved_file, line_start, line_end, snippet, match_quality, github_url, warning, provider_trace, rejection_reasons, updated_at
             FROM source_lookup_cache
             WHERE module_basename = ? AND module_identifier = ?',
            [$moduleBasename, $moduleIdentifier]
        );

        if (!is_array($row) || $row === []) {
            return null;
        }

        $normalizedMatch = $this->normalizeCachedMatchMetadata(
            isset($row['match_quality']) ? (string) $row['match_quality'] : 'approximate',
            isset($row['warning']) && $row['warning'] !== null ? (string) $row['warning'] : null,
            isset($row['snippet']) ? (string) $row['snippet'] : ''
        );
        $focusedSnippetLineNumber = isset($row['snippet']) ? $this->extractFocusedSnippetLineNumber((string) $row['snippet']) : null;
        $displayLine = $focusedSnippetLineNumber ?? (int) $row['line_start'];

        return [
            'module_basename' => (string) $row['module_basename'],
            'module_identifier' => (string) $row['module_identifier'],
            'source_type' => (string) $row['source_type'],
            'github_repo_url' => $row['github_repo_url'] !== null ? (string) $row['github_repo_url'] : null,
            'github_ref' => $row['github_ref'] !== null ? (string) $row['github_ref'] : null,
            'resolved_file' => (string) $row['resolved_file'],
            'line_start' => $displayLine,
            'line_end' => $displayLine,
            'snippet' => (string) $row['snippet'],
            'match_quality' => $normalizedMatch['quality'],
            'github_url' => $row['github_url'] !== null ? (string) $row['github_url'] : null,
            'warning' => $normalizedMatch['warning'],
            'provider_trace' => $this->decodeJsonList($row['provider_trace'] ?? null),
            'rejection_reasons' => $this->decodeJsonList($row['rejection_reasons'] ?? null),
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * @return array{module_basename: string, module_identifier: ?string, runtime_module: string, symbol: string, matched_module_name: ?string}
     */
    private function resolveCrashModule(string $crashId, string $moduleInput, string $symbol): array
    {
        $runtimeModule = trim($moduleInput);
        $symbol = trim($symbol);
        $moduleBasename = $this->extractModuleBasename($runtimeModule, $symbol);

        $rows = $this->loadCrashModules($crashId);

        $matchedIdentifier = null;
        $matchedModuleName = null;
        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');
            $identifier = (string) ($row['identifier'] ?? '');
            if ($name === '' || $identifier === '' || $identifier === self::INVALID_IDENTIFIER) {
                continue;
            }

            if (mb_strtolower($this->normalizeModuleBasename($name)) !== mb_strtolower($moduleBasename)) {
                continue;
            }

            $matchedIdentifier = $identifier;
            $matchedModuleName = $name;
            break;
        }

        return [
            'module_basename' => $moduleBasename,
            'module_identifier' => $matchedIdentifier,
            'runtime_module' => $runtimeModule,
            'symbol' => $symbol,
            'matched_module_name' => $matchedModuleName,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCrashModules(string $crashId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT name, identifier FROM module WHERE crash = ? ORDER BY name',
            [$crashId]
        );
    }

    private function extractModuleBasename(string $moduleInput, string $symbol): string
    {
        $moduleInput = trim($moduleInput);
        if ($moduleInput !== '') {
            return $this->normalizeModuleBasename($moduleInput);
        }

        $parsed = $this->parseSymbol('', $symbol);

        return $this->normalizeModuleBasename($parsed['module'] ?? '');
    }

    private function normalizeModuleBasename(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = str_replace('\\', '/', $value);
        if (str_contains($value, '!')) {
            [$value] = explode('!', $value, 2);
        }

        return basename($value);
    }

    /**
     * @param array<string, mixed>|null $saved
     * @return array{source_type: string, github_repo_url: ?string, github_ref: ?string, local_root: ?string}
     */
    private function resolveSavedMapping(array $moduleInfo, ?array $saved): array
    {
        if ($saved === null) {
            throw new \RuntimeException(sprintf('No saved source mapping exists for %s (%s).', $moduleInfo['module_basename'], $moduleInfo['module_identifier'] ?? 'unknown'));
        }

        return [
            'source_type' => (string) $saved['source_type'],
            'github_repo_url' => $saved['github_repo_url'] !== null ? (string) $saved['github_repo_url'] : null,
            'github_ref' => $saved['github_ref'] !== null && trim((string) $saved['github_ref']) !== '' ? (string) $saved['github_ref'] : 'master',
            'local_root' => $saved['local_root'] !== null ? (string) $saved['local_root'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{source_type: string, github_repo_url: string, github_ref: string, local_root: null}
     */
    private function resolveGithubMapping(array $input): array
    {
        $repoUrl = $this->normalizeGithubRepo((string) ($input['github_repo_url'] ?? ''));
        $ref = trim((string) ($input['github_ref'] ?? ''));
        if ($ref === '') {
            $ref = 'master';
        }

        return [
            'source_type' => self::GITHUB_SOURCE,
            'github_repo_url' => $repoUrl,
            'github_ref' => $ref,
            'local_root' => null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{source_type: string, github_repo_url: null, github_ref: null, local_root: string}
     */
    private function resolveLocalMapping(array $input, bool $allowLocalOverride): array
    {
        if (!$allowLocalOverride) {
            throw new \RuntimeException('Only administrators can set a local source path.');
        }

        $localRoot = trim((string) ($input['local_root'] ?? ''));
        if ($localRoot === '' || !$this->isAbsolutePath($localRoot) || !is_dir($localRoot)) {
            throw new \RuntimeException('Local source path must be an existing absolute directory on the server.');
        }

        return [
            'source_type' => self::LOCAL_SOURCE,
            'github_repo_url' => null,
            'github_ref' => null,
            'local_root' => rtrim($localRoot, '/\\'),
        ];
    }

    /**
     * @param array{source_type: string, github_repo_url: ?string, github_ref: ?string, local_root: ?string} $mapping
     */
    private function saveMapping(string $moduleBasename, string $moduleIdentifier, array $mapping): void
    {
        $payload = [
            'module_basename' => $moduleBasename,
            'module_identifier' => $moduleIdentifier,
            'source_type' => $mapping['source_type'],
            'github_repo_url' => $mapping['github_repo_url'],
            'github_ref' => $mapping['github_ref'],
            'local_root' => $mapping['local_root'],
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];

        if ($this->getSavedMapping($moduleBasename, $moduleIdentifier) === null) {
            $this->connection->insert('source_lookup_mapping', $payload);

            return;
        }

        $this->connection->update(
            'source_lookup_mapping',
            $payload,
            ['module_basename' => $moduleBasename, 'module_identifier' => $moduleIdentifier]
        );
    }

    /**
     * @param array{source_type: string, github_repo_url: ?string, github_ref: ?string, local_root: ?string} $effective
     * @param array{relative_path: string, line_start: int, line_end: int, snippet: string, quality: string, warning: ?string, github_url: ?string} $match
     * @param list<string> $providerTrace
     * @param list<string> $rejectionReasons
     */
    private function saveCachedResult(string $moduleBasename, string $moduleIdentifier, array $effective, array $match, array $providerTrace = [], array $rejectionReasons = []): void
    {
        $payload = [
            'module_basename' => $moduleBasename,
            'module_identifier' => $moduleIdentifier,
            'source_type' => $effective['source_type'],
            'github_repo_url' => $effective['github_repo_url'],
            'github_ref' => $effective['github_ref'],
            'resolved_file' => $match['relative_path'],
            'line_start' => $match['line_start'],
            'line_end' => $match['line_end'],
            'snippet' => $match['snippet'],
            'match_quality' => $match['quality'],
            'github_url' => $match['github_url'],
            'warning' => $match['warning'],
            'provider_trace' => json_encode(array_values($providerTrace), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'rejection_reasons' => json_encode(array_values($rejectionReasons), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];

        if ($this->getCachedResult($moduleBasename, $moduleIdentifier) === null) {
            $this->connection->insert('source_lookup_cache', $payload);

            return;
        }

        $this->connection->update(
            'source_lookup_cache',
            $payload,
            ['module_basename' => $moduleBasename, 'module_identifier' => $moduleIdentifier]
        );
    }

    /**
     * @param array{module: string, signature: string, method: string, class: ?string, namespaces: list<string>, offset: ?string} $symbol
     * @return array{match: array<string, mixed>|null, attempted_paths: list<string>, warnings: list<string>, provider_trace: list<string>, rejection_reasons: list<string>, repository_miss: bool}
     */
    private function findBestGithubMatch(string $repoUrl, string $ref, string $pat, array $symbol, string $moduleBasename, bool $reload): array
    {
        $startedAt = microtime(true);
        $deadlineAt = $startedAt + self::LOOKUP_DEADLINE_SECONDS;
        $attemptedPaths = [];
        $best = null;
        $warnings = [];
        $providerTrace = [];
        $rejectionReasons = [];
        $isFreeGlobalSymbol = $symbol['class'] === null && $symbol['namespaces'] === [];
        $directRelativePaths = array_values(array_unique($this->buildCandidateRelativePaths($symbol, $moduleBasename)));
        $providerTrace[] = sprintf('direct_candidates=%d', count($directRelativePaths));
        if (count($directRelativePaths) > self::MAX_DIRECT_GITHUB_CANDIDATE_FILES) {
            $providerTrace[] = sprintf('direct_candidate_cap=%d', self::MAX_DIRECT_GITHUB_CANDIDATE_FILES);
            $directRelativePaths = array_slice($directRelativePaths, 0, self::MAX_DIRECT_GITHUB_CANDIDATE_FILES);
        }

        $directFallbackPaths = array_slice($directRelativePaths, 0, self::DIRECT_FETCH_FALLBACK_COUNT);
        $directBatchedPaths = array_slice($directRelativePaths, self::DIRECT_FETCH_FALLBACK_COUNT);
        $providerTrace[] = sprintf('direct_fallback_count=%d', count($directFallbackPaths));

        foreach ($directFallbackPaths as $relativePath) {
            if ($this->hasDeadlineExpired($deadlineAt)) {
                $providerTrace[] = 'deadline_exceeded=true';
                $warnings['deadline'] = 'Source lookup timed out before direct candidate evaluation finished.';
                break;
            }

            $attemptedPaths[] = $relativePath;
            try {
                $fetched = $this->fetchGithubFileContents($repoUrl, $ref, $relativePath, $pat, $reload, $deadlineAt);
            } catch (\Throwable $e) {
                $fetched = [
                    'body' => null,
                    'warning' => $this->isGithubNotFoundError($e->getMessage())
                        ? null
                        : 'Failed to fetch GitHub file ' . $relativePath . ': ' . $e->getMessage(),
                ];
            }
            if (($fetched['warning'] ?? null) !== null) {
                $warnings[$fetched['warning']] = $fetched['warning'];
            }
            if (($fetched['body'] ?? null) === null) {
                continue;
            }

            $match = $this->scoreFileContents($relativePath, $fetched['body'], $symbol, $moduleBasename);
            if ($match === null) {
                $rejectionReasons[] = $relativePath . ': no structural match';
                continue;
            }

            $match['github_url'] = $this->buildGithubBlobUrl($repoUrl, $ref, $relativePath, $match['line_start']);
            if ($best === null || $match['score'] > $best['score']) {
                $best = $match;
            }
        }

        if ($best !== null) {
            $providerTrace[] = sprintf('selected_direct=%s:%d quality=%s', $best['relative_path'], $best['line_start'], $best['quality']);

            return [
                'match' => $best,
                'attempted_paths' => array_values(array_unique($attemptedPaths)),
                'warnings' => array_values($warnings),
                'provider_trace' => array_values(array_unique($providerTrace)),
                'rejection_reasons' => array_values(array_unique(array_slice($rejectionReasons, 0, 12))),
                'repository_miss' => false,
            ];
        }

        $providerTrace[] = sprintf('direct_fetch_batch_size=%d', self::GITHUB_FETCH_BATCH_SIZE);
        foreach (array_chunk($directBatchedPaths, self::GITHUB_FETCH_BATCH_SIZE) as $batchPaths) {
            if ($this->hasDeadlineExpired($deadlineAt)) {
                $providerTrace[] = 'deadline_exceeded=true';
                $warnings['deadline'] = 'Source lookup timed out before direct candidate evaluation finished.';
                break;
            }

            try {
                $fetchedBatch = $this->fetchGithubFileContentsBatch($repoUrl, $ref, $batchPaths, $pat, $reload, $deadlineAt);
            } catch (\Throwable $e) {
                $warning = $this->isGithubNotFoundError($e->getMessage())
                    ? null
                    : 'Failed to fetch GitHub direct candidate batch: ' . $e->getMessage();
                if ($warning !== null) {
                    $warnings[$warning] = $warning;
                }
                $fetchedBatch = [];
            }

            foreach ($batchPaths as $relativePath) {
                $attemptedPaths[] = $relativePath;
                $fetched = $fetchedBatch[$relativePath] ?? ['body' => null, 'warning' => null];
                if (($fetched['warning'] ?? null) !== null) {
                    $warnings[$fetched['warning']] = $fetched['warning'];
                }
                if (($fetched['body'] ?? null) === null) {
                    continue;
                }

                $match = $this->scoreFileContents($relativePath, $fetched['body'], $symbol, $moduleBasename);
                if ($match === null) {
                    $rejectionReasons[] = $relativePath . ': no structural match';
                    continue;
                }

                $match['github_url'] = $this->buildGithubBlobUrl($repoUrl, $ref, $relativePath, $match['line_start']);
                if ($best === null || $match['score'] > $best['score']) {
                    $best = $match;
                }
            }
        }

        if ($best !== null) {
            $providerTrace[] = sprintf('selected_direct=%s:%d quality=%s', $best['relative_path'], $best['line_start'], $best['quality']);

            return [
                'match' => $best,
                'attempted_paths' => array_values(array_unique($attemptedPaths)),
                'warnings' => array_values($warnings),
                'provider_trace' => array_values(array_unique($providerTrace)),
                'rejection_reasons' => array_values(array_unique(array_slice($rejectionReasons, 0, 12))),
                'repository_miss' => false,
            ];
        }

        $relativePaths = $directRelativePaths;
        $searchPaths = $this->fetchGithubSearchPaths($repoUrl, $ref, $pat, $symbol, $reload, $deadlineAt);
        $providerTrace[] = sprintf('github_search_paths=%d', count($searchPaths));
        $treePaths = [];
        if (!$this->hasDeadlineExpired($deadlineAt) && (!$isFreeGlobalSymbol || $searchPaths !== [])) {
            try {
                $treePaths = $this->fetchGithubTreePaths($repoUrl, $ref, $pat, $reload, $deadlineAt);
            } catch (\RuntimeException $e) {
                $warnings['tree_metadata'] = $e->getMessage();
                $providerTrace[] = 'github_tree_skipped=true';
            }
        }
        $providerTrace[] = sprintf('github_tree_paths=%d', count($treePaths));
        if ($isFreeGlobalSymbol && $searchPaths === []) {
            $relativePaths = [];
        } else {
            $relativePaths = array_merge(
                $relativePaths,
                $this->buildTreeAssistedCandidatePaths(
                    $treePaths,
                    $symbol,
                    $moduleBasename
                ),
                $searchPaths
            );
        }
        $uniqueRelativePaths = array_values(array_unique($relativePaths));
        $providerTrace[] = sprintf('merged_candidates=%d', count($uniqueRelativePaths));
        if (count($uniqueRelativePaths) > self::MAX_GITHUB_CANDIDATE_FILES) {
            $providerTrace[] = sprintf('candidate_cap=%d', self::MAX_GITHUB_CANDIDATE_FILES);
            $uniqueRelativePaths = array_slice($uniqueRelativePaths, 0, self::MAX_GITHUB_CANDIDATE_FILES);
        }

        $providerTrace[] = sprintf('fetch_batch_size=%d', self::GITHUB_FETCH_BATCH_SIZE);
        foreach (array_chunk($uniqueRelativePaths, self::GITHUB_FETCH_BATCH_SIZE) as $batchPaths) {
            if ($this->hasDeadlineExpired($deadlineAt)) {
                $providerTrace[] = 'deadline_exceeded=true';
                $warnings['deadline'] = 'Source lookup timed out before candidate evaluation finished.';
                break;
            }

            $fetchedBatch = $this->fetchGithubFileContentsBatch($repoUrl, $ref, $batchPaths, $pat, $reload, $deadlineAt);
            foreach ($batchPaths as $relativePath) {
                $attemptedPaths[] = $relativePath;
                $fetched = $fetchedBatch[$relativePath] ?? ['body' => null, 'warning' => null];
                if ($fetched['warning'] !== null) {
                    $warnings[$fetched['warning']] = $fetched['warning'];
                }
                if ($fetched['body'] === null) {
                    continue;
                }

                $match = $this->scoreFileContents($relativePath, $fetched['body'], $symbol, $moduleBasename);
                if ($match === null) {
                    $rejectionReasons[] = $relativePath . ': no structural match';
                    continue;
                }

                $match['github_url'] = $this->buildGithubBlobUrl($repoUrl, $ref, $relativePath, $match['line_start']);
                if ($best === null || $match['score'] > $best['score']) {
                    $best = $match;
                }
            }
        }

        if ($best !== null) {
            $providerTrace[] = sprintf('selected=%s:%d quality=%s', $best['relative_path'], $best['line_start'], $best['quality']);
        }

        $repositoryMiss = $best === null
            && $searchPaths === []
            && $isFreeGlobalSymbol;
        if ($repositoryMiss) {
            $providerTrace[] = 'repository_miss=true';
            $rejectionReasons[] = 'Repository search returned no files containing this symbol text; the selected repository may not include source for this module/function.';
        }

        return [
            'match' => $best,
            'attempted_paths' => array_values(array_unique($attemptedPaths)),
            'warnings' => array_values($warnings),
            'provider_trace' => array_values(array_unique($providerTrace)),
            'rejection_reasons' => array_values(array_unique(array_slice($rejectionReasons, 0, 12))),
            'repository_miss' => $repositoryMiss,
        ];
    }

    /**
     * @param array{module: string, signature: string, method: string, class: ?string, namespaces: list<string>, offset: ?string} $symbol
     * @return array{match: array<string, mixed>|null, attempted_paths: list<string>, provider_trace: list<string>, rejection_reasons: list<string>}
     */
    private function findBestLocalMatch(string $sourceRoot, array $symbol, string $moduleBasename): array
    {
        $deadlineAt = microtime(true) + self::LOOKUP_DEADLINE_SECONDS;
        $attemptedPaths = [];
        $best = null;
        $providerTrace = [];
        $rejectionReasons = [];
        $warnings = [];
        $indexMetadata = $this->ensureLocalSymbolIndex($sourceRoot, reload: false, deadlineAt: $deadlineAt);
        if ($indexMetadata !== null) {
            $providerTrace[] = sprintf('local_index=%s fingerprint=%s', $indexMetadata['index_type'], $indexMetadata['fingerprint']);
        }

        $rgCandidates = $this->discoverLocalRipgrepCandidates($sourceRoot, $symbol, $deadlineAt);
        if ($rgCandidates !== []) {
            $providerTrace[] = sprintf('ripgrep_candidates=%d', count($rgCandidates));
            foreach ($rgCandidates as $candidate) {
                $attemptedPaths[] = $candidate;
            }
        }

        $candidatePaths = array_values(array_unique(array_merge($rgCandidates, $this->buildCandidateRelativePaths($symbol, $moduleBasename))));
        if (count($candidatePaths) > self::MAX_LOCAL_CANDIDATE_FILES) {
            $providerTrace[] = sprintf('candidate_cap=%d', self::MAX_LOCAL_CANDIDATE_FILES);
            $candidatePaths = array_slice($candidatePaths, 0, self::MAX_LOCAL_CANDIDATE_FILES);
        }

        foreach ($candidatePaths as $relativePath) {
            if ($this->hasDeadlineExpired($deadlineAt)) {
                $providerTrace[] = 'deadline_exceeded=true';
                $warnings[] = 'Source lookup timed out before local candidate evaluation finished.';
                break;
            }

            $attemptedPaths[] = $relativePath;
            $absolutePath = rtrim($sourceRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (!is_file($absolutePath)) {
                continue;
            }

            $contents = @file_get_contents($absolutePath);
            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $match = $this->scoreFileContents($relativePath, $contents, $symbol, $moduleBasename);
            if ($match === null) {
                $rejectionReasons[] = $relativePath . ': no structural match';
                continue;
            }

            $best = $match;
            break;
        }

        if ($best === null && !$this->hasDeadlineExpired($deadlineAt)) {
            $best = $this->findBestMatchInLocalTree($sourceRoot, $symbol, $moduleBasename, $deadlineAt);
        }

        return [
            'match' => $best,
            'attempted_paths' => array_values(array_unique($attemptedPaths !== [] ? $attemptedPaths : $this->buildCandidateRelativePaths($symbol, $moduleBasename))),
            'provider_trace' => array_values(array_unique($providerTrace)),
            'rejection_reasons' => array_values(array_unique(array_slice($rejectionReasons, 0, 12))),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @return array{body: ?string, warning: ?string}
     */
    private function fetchGithubFileContents(string $repoUrl, string $ref, string $relativePath, string $pat, bool $reload, float $deadlineAt): array
    {
        if ($this->hasDeadlineExpired($deadlineAt)) {
            return ['body' => null, 'warning' => 'Source lookup timed out before GitHub file fetch started.'];
        }

        [$owner, $repo] = $this->parseGithubRepoParts($repoUrl);
        $cacheKey = sha1(strtolower($owner . '/' . $repo . '@' . $ref . ':' . $relativePath));
        $cacheDir = $this->projectDir . self::GITHUB_FILE_CACHE . '/' . $cacheKey;
        $bodyPath = $cacheDir . '/body.txt';

        if ($reload) {
            \Filesystem::remove($cacheDir);
        }

        if (\Filesystem::pathExists($bodyPath)) {
            return ['body' => \Filesystem::readFile($bodyPath), 'warning' => null];
        }

        $headers = [
            'User-Agent' => 'Throttle source lookup',
            'Accept' => 'application/vnd.github+json',
        ];
        if ($pat !== '') {
            $headers['Authorization'] = 'Bearer ' . trim($pat);
        }

        if ($pat === '') {
            try {
                $rawUrl = sprintf(
                    'https://raw.githubusercontent.com/%s/%s/%s/%s',
                    rawurlencode($owner),
                    rawurlencode($repo),
                    str_replace('%2F', '/', rawurlencode($ref)),
                    str_replace('%2F', '/', rawurlencode($relativePath))
                );
                $rawResponse = $this->httpClient->request('GET', $rawUrl, [
                    'headers' => ['User-Agent' => 'Throttle source lookup'],
                    'timeout' => self::HTTP_TIMEOUT_SECONDS,
                ]);
                $rawStatus = $rawResponse->getStatusCode();
                if ($rawStatus === 200) {
                    $rawBody = $rawResponse->getContent();
                    if ($rawBody !== '') {
                        \Filesystem::createDirectory($cacheDir, 0775, true);
                        \Filesystem::writeFile($bodyPath, $rawBody);

                        return ['body' => $rawBody, 'warning' => null];
                    }
                } elseif ($rawStatus === 404) {
                    return ['body' => null, 'warning' => null];
                } elseif ($rawStatus === 401 || $rawStatus === 403) {
                    return [
                        'body' => null,
                        'warning' => sprintf('GitHub blocked raw file fetch for %s with HTTP %d.', $relativePath, $rawStatus),
                    ];
                }
            } catch (HttpClientExceptionInterface|\Throwable) {
                // Fall back to contents API below.
            }
        }

        $apiUrl = sprintf(
            'https://api.github.com/repos/%s/%s/contents/%s?ref=%s',
            rawurlencode($owner),
            rawurlencode($repo),
            str_replace('%2F', '/', rawurlencode($relativePath)),
            rawurlencode($ref)
        );

        try {
            $response = $this->httpClient->request('GET', $apiUrl, [
                'headers' => $headers,
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
            if ($status === 404) {
                return ['body' => null, 'warning' => null];
            }
            if ($status === 401 || $status === 403) {
                return [
                    'body' => null,
                    'warning' => sprintf('GitHub blocked file fetch for %s with HTTP %d.', $relativePath, $status),
                ];
            }
            if ($status >= 400) {
                return [
                    'body' => null,
                    'warning' => sprintf('GitHub contents request failed with HTTP %d for %s.', $status, $relativePath),
                ];
            }

            $payload = $response->toArray(false);
            $body = null;
            if (isset($payload['content']) && is_string($payload['content']) && $payload['content'] !== '') {
                $decoded = base64_decode(str_replace("\n", '', $payload['content']), true);
                if (is_string($decoded)) {
                    $body = $decoded;
                }
            }

            if ($body === null && isset($payload['download_url']) && is_string($payload['download_url']) && $payload['download_url'] !== '') {
                $download = $this->httpClient->request('GET', $payload['download_url'], [
                    'headers' => $headers,
                    'timeout' => self::HTTP_TIMEOUT_SECONDS,
                ]);
                $downloadStatus = $download->getStatusCode();
                if ($downloadStatus === 404) {
                    return ['body' => null, 'warning' => null];
                }
                if ($downloadStatus === 401 || $downloadStatus === 403) {
                    return [
                        'body' => null,
                        'warning' => sprintf('GitHub blocked raw file download for %s with HTTP %d.', $relativePath, $downloadStatus),
                    ];
                }
                if ($downloadStatus >= 400) {
                    return [
                        'body' => null,
                        'warning' => sprintf('GitHub raw download failed with HTTP %d for %s.', $relativePath, $downloadStatus),
                    ];
                }
                $body = $download->getContent();
            }

            if (!is_string($body) || $body === '') {
                return ['body' => null, 'warning' => null];
            }

            \Filesystem::createDirectory($cacheDir, 0775, true);
            \Filesystem::writeFile($bodyPath, $body);

            return ['body' => $body, 'warning' => null];
        } catch (HttpClientExceptionInterface|\Throwable $e) {
            if ($this->isGithubNotFoundError($e->getMessage())) {
                return ['body' => null, 'warning' => null];
            }

            return [
                'body' => null,
                'warning' => 'Failed to fetch GitHub file ' . $relativePath . ': ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @param list<string> $relativePaths
     * @return array<string, array{body: ?string, warning: ?string}>
     */
    private function fetchGithubFileContentsBatch(string $repoUrl, string $ref, array $relativePaths, string $pat, bool $reload, float $deadlineAt): array
    {
        $results = [];
        if ($relativePaths === []) {
            return $results;
        }

        if ($pat !== '') {
            foreach ($relativePaths as $relativePath) {
                $results[$relativePath] = $this->fetchGithubFileContents($repoUrl, $ref, $relativePath, $pat, $reload, $deadlineAt);
            }

            return $results;
        }

        [$owner, $repo] = $this->parseGithubRepoParts($repoUrl);
        $pending = [];
        $responses = [];

        foreach ($relativePaths as $relativePath) {
            if ($this->hasDeadlineExpired($deadlineAt)) {
                $results[$relativePath] = [
                    'body' => null,
                    'warning' => 'Source lookup timed out before GitHub file fetch started.',
                ];
                continue;
            }

            $cacheKey = sha1(strtolower($owner . '/' . $repo . '@' . $ref . ':' . $relativePath));
            $cacheDir = $this->projectDir . self::GITHUB_FILE_CACHE . '/' . $cacheKey;
            $bodyPath = $cacheDir . '/body.txt';

            if ($reload) {
                \Filesystem::remove($cacheDir);
            }

            if (\Filesystem::pathExists($bodyPath)) {
                $results[$relativePath] = ['body' => \Filesystem::readFile($bodyPath), 'warning' => null];
                continue;
            }

            $rawUrl = sprintf(
                'https://raw.githubusercontent.com/%s/%s/%s/%s',
                rawurlencode($owner),
                rawurlencode($repo),
                str_replace('%2F', '/', rawurlencode($ref)),
                str_replace('%2F', '/', rawurlencode($relativePath))
            );

            $response = $this->httpClient->request('GET', $rawUrl, [
                'headers' => ['User-Agent' => 'Throttle source lookup'],
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
            ]);

            $responses[] = $response;
            $pending[spl_object_id($response)] = [
                'relative_path' => $relativePath,
                'cache_dir' => $cacheDir,
                'body_path' => $bodyPath,
            ];
        }

        if ($responses === []) {
            return $results;
        }

        try {
            foreach ($this->httpClient->stream($responses, self::HTTP_TIMEOUT_SECONDS) as $response => $chunk) {
                if (!$chunk->isLast()) {
                    continue;
                }

                $meta = $pending[spl_object_id($response)] ?? null;
                if (!is_array($meta)) {
                    continue;
                }

                $relativePath = (string) $meta['relative_path'];
                try {
                    $status = $response->getStatusCode();
                    if ($status === 200) {
                        $body = $response->getContent(false);
                        if ($body !== '') {
                            \Filesystem::createDirectory((string) $meta['cache_dir'], 0775, true);
                            \Filesystem::writeFile((string) $meta['body_path'], $body);
                            $results[$relativePath] = ['body' => $body, 'warning' => null];
                        } else {
                            $results[$relativePath] = ['body' => null, 'warning' => null];
                        }
                    } elseif ($status === 404) {
                        $results[$relativePath] = ['body' => null, 'warning' => null];
                    } elseif ($status === 401 || $status === 403) {
                        $results[$relativePath] = [
                            'body' => null,
                            'warning' => sprintf('GitHub blocked raw file fetch for %s with HTTP %d.', $relativePath, $status),
                        ];
                    } else {
                        $results[$relativePath] = [
                            'body' => null,
                            'warning' => sprintf('GitHub raw download failed with HTTP %d for %s.', $status, $relativePath),
                        ];
                    }
                } catch (HttpClientExceptionInterface|\Throwable $e) {
                    if ($this->isGithubNotFoundError($e->getMessage())) {
                        $results[$relativePath] = ['body' => null, 'warning' => null];
                        continue;
                    }

                    $results[$relativePath] = [
                        'body' => null,
                        'warning' => 'Failed to fetch GitHub file ' . $relativePath . ': ' . $e->getMessage(),
                    ];
                }
            }
        } catch (HttpClientExceptionInterface|\Throwable $e) {
            foreach ($pending as $meta) {
                $relativePath = (string) $meta['relative_path'];
                if (!isset($results[$relativePath])) {
                    if ($this->isGithubNotFoundError($e->getMessage())) {
                        $results[$relativePath] = ['body' => null, 'warning' => null];
                        continue;
                    }

                    $results[$relativePath] = [
                        'body' => null,
                        'warning' => 'Failed to fetch GitHub file ' . $relativePath . ': ' . $e->getMessage(),
                    ];
                }
            }
        }

        foreach ($relativePaths as $relativePath) {
            if (!isset($results[$relativePath])) {
                $results[$relativePath] = ['body' => null, 'warning' => null];
            }
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private function fetchGithubTreePaths(string $repoUrl, string $ref, string $pat, bool $reload, float $deadlineAt): array
    {
        if ($this->hasDeadlineExpired($deadlineAt)) {
            return [];
        }

        [$owner, $repo] = $this->parseGithubRepoParts($repoUrl);
        $cacheKey = sha1(strtolower($owner . '/' . $repo . '@' . $ref));
        $cacheDir = $this->projectDir . self::GITHUB_TREE_CACHE . '/' . $cacheKey;
        $pathsFile = $cacheDir . '/paths.json';

        if ($reload) {
            \Filesystem::remove($cacheDir);
        }

        if (\Filesystem::pathExists($pathsFile)) {
            $decoded = json_decode(\Filesystem::readFile($pathsFile), true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map('strval', $decoded), static fn (string $path): bool => $path !== ''));
            }
        }

        $headers = [
            'User-Agent' => 'Throttle source lookup',
            'Accept' => 'application/vnd.github+json',
        ];
        if ($pat !== '') {
            $headers['Authorization'] = 'Bearer ' . trim($pat);
        }

        try {
            $commitResponse = $this->httpClient->request('GET', sprintf(
                'https://api.github.com/repos/%s/%s/commits/%s',
                rawurlencode($owner),
                rawurlencode($repo),
                rawurlencode($ref)
            ), [
                'headers' => $headers,
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
            ]);
            if ($commitResponse->getStatusCode() >= 400) {
                throw new \RuntimeException(sprintf('GitHub commit lookup failed with HTTP %d.', $commitResponse->getStatusCode()));
            }

            $commitPayload = $commitResponse->toArray(false);
            $treeSha = $commitPayload['commit']['tree']['sha'] ?? null;
            if (!is_string($treeSha) || $treeSha === '') {
                return [];
            }

            $treeResponse = $this->httpClient->request('GET', sprintf(
                'https://api.github.com/repos/%s/%s/git/trees/%s?recursive=1',
                rawurlencode($owner),
                rawurlencode($repo),
                rawurlencode($treeSha)
            ), [
                'headers' => $headers,
                'timeout' => self::HTTP_TIMEOUT_SECONDS,
            ]);
            if ($treeResponse->getStatusCode() >= 400) {
                throw new \RuntimeException(sprintf('GitHub tree lookup failed with HTTP %d.', $treeResponse->getStatusCode()));
            }

            $treePayload = $treeResponse->toArray(false);
            $paths = [];
            foreach (($treePayload['tree'] ?? []) as $entry) {
                if (!is_array($entry) || ($entry['type'] ?? '') !== 'blob') {
                    continue;
                }

                $path = isset($entry['path']) ? (string) $entry['path'] : '';
                if ($path === '') {
                    continue;
                }

                $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($extension, self::SOURCE_EXTENSIONS, true)) {
                    continue;
                }

                $paths[] = str_replace('\\', '/', $path);
            }

            \Filesystem::createDirectory($cacheDir, 0775, true);
            \Filesystem::writeFile($pathsFile, json_encode(array_values(array_unique($paths)), JSON_UNESCAPED_SLASHES));

            return array_values(array_unique($paths));
        } catch (HttpClientExceptionInterface|\Throwable $e) {
            throw new \RuntimeException('Failed to fetch GitHub tree metadata: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array{module: string, signature: string, method: string, class: ?string, namespaces: list<string>, offset: ?string} $symbol
     * @param list<string> $treePaths
     * @return list<string>
     */
    private function buildTreeAssistedCandidatePaths(array $treePaths, array $symbol, string $moduleBasename): array
    {
        if ($treePaths === []) {
            return [];
        }

        $methodToken = $this->normalizeFilenameToken(ltrim($symbol['method'], '_'));
        $classHints = $this->buildClassFilenameHints($symbol['class']);
        $classToken = $classHints !== [] ? $classHints[0] : '';
        $namespaceTokens = array_values(array_filter(array_map([$this, 'normalizeFilenameToken'], $symbol['namespaces'])));
        $filenameHints = $this->buildFilenameHints($symbol);
        $moduleTokens = $this->filterSignalTokens(
            explode('_', $this->normalizeFilenameToken(preg_replace('/\.[^.]+$/', '', $moduleBasename) ?? $moduleBasename))
        );
        $methodParts = $methodToken !== '' ? explode('_', $methodToken) : [];
        $searchTokens = array_values(array_unique(array_filter(array_merge(
            $filenameHints,
            $namespaceTokens,
            $classHints,
            $this->filterSignalTokens($methodParts),
            $moduleTokens
        ))));

        $scored = [];
        foreach ($treePaths as $path) {
            $pathLower = mb_strtolower($path);
            $basename = mb_strtolower((string) pathinfo($path, PATHINFO_FILENAME));
            $pathTokens = $this->tokenizePathSignals($pathLower);
            $score = 0;

            foreach ($filenameHints as $hint) {
                if ($hint !== '' && $basename === $hint) {
                    $score += 90;
                } elseif ($hint !== '' && (str_contains($basename, $hint) || in_array($hint, $pathTokens, true))) {
                    $score += 35;
                }
            }

            foreach ($namespaceTokens as $token) {
                if ($token !== '' && in_array($token, $pathTokens, true)) {
                    $score += 30;
                }
            }

            foreach ($moduleTokens as $token) {
                if ($token !== '' && in_array($token, $pathTokens, true)) {
                    $score += 12;
                }
            }

            foreach ($searchTokens as $token) {
                if ($token !== '' && in_array($token, $pathTokens, true)) {
                    $score += 6;
                }
            }

            if (preg_match('~\.(?:cpp|cc|cxx)$~i', $path) === 1) {
                $score += 8;
            }

            if (str_contains($pathLower, '/game/') || str_contains($pathLower, '/server/')) {
                $score += 4;
            }

            if ($score <= 0) {
                continue;
            }

            $scored[$path] = $score;
        }

        arsort($scored, SORT_NUMERIC);

        return array_slice(array_keys($scored), 0, 40);
    }

    /**
     * @param array{module: string, signature: string, method: string, class: ?string, namespaces: list<string>, offset: ?string} $symbol
     * @return list<string>
     */
    private function fetchGithubSearchPaths(string $repoUrl, string $ref, string $pat, array $symbol, bool $reload, float $deadlineAt): array
    {
        if ($this->hasDeadlineExpired($deadlineAt)) {
            return [];
        }

        [$owner, $repo] = $this->parseGithubRepoParts($repoUrl);
        $queries = $this->buildGithubSearchQueries($owner, $repo, $symbol);
        if ($queries === []) {
            return [];
        }

        $headers = [
            'User-Agent' => 'Throttle source lookup',
            'Accept' => 'application/vnd.github+json',
        ];
        if ($pat !== '') {
            $headers['Authorization'] = 'Bearer ' . trim($pat);
        }

        $allPaths = [];
        foreach ($queries as $query) {
            if ($this->hasDeadlineExpired($deadlineAt)) {
                break;
            }

            $cacheKey = sha1(strtolower($owner . '/' . $repo . '@' . $ref . ':' . $query));
            $cacheDir = $this->projectDir . self::GITHUB_SEARCH_CACHE . '/' . $cacheKey;
            $pathsFile = $cacheDir . '/paths.json';

            if ($reload) {
                \Filesystem::remove($cacheDir);
            }

            if (\Filesystem::pathExists($pathsFile)) {
                $decoded = json_decode(\Filesystem::readFile($pathsFile), true);
                if (is_array($decoded)) {
                    foreach (array_filter(array_map('strval', $decoded), static fn (string $path): bool => $path !== '') as $path) {
                        $allPaths[] = $path;
                    }
                    continue;
                }
            }

            try {
                $response = $this->httpClient->request('GET', 'https://api.github.com/search/code?q=' . rawurlencode($query), [
                    'headers' => $headers,
                    'timeout' => self::HTTP_TIMEOUT_SECONDS,
                ]);
                if (in_array($response->getStatusCode(), [401, 403, 422], true)) {
                    continue;
                }
                if ($response->getStatusCode() >= 400) {
                    continue;
                }

                $payload = $response->toArray(false);
                $paths = [];
                foreach (($payload['items'] ?? []) as $item) {
                    if (!is_array($item) || !isset($item['path'])) {
                        continue;
                    }

                    $path = (string) $item['path'];
                    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                    if ($path === '' || !in_array($extension, self::SOURCE_EXTENSIONS, true)) {
                        continue;
                    }

                    $paths[] = str_replace('\\', '/', $path);
                    $allPaths[] = str_replace('\\', '/', $path);
                }

                \Filesystem::createDirectory($cacheDir, 0775, true);
                \Filesystem::writeFile($pathsFile, json_encode(array_values(array_unique($paths)), JSON_UNESCAPED_SLASHES));
            } catch (HttpClientExceptionInterface|\Throwable) {
                continue;
            }
        }

        return array_values(array_unique($allPaths));
    }

    /**
     * @param array{module: string, signature: string, method: string, class: ?string, namespaces: list<string>, offset: ?string} $symbol
     */
    private function buildGithubSearchQueries(string $owner, string $repo, array $symbol): array
    {
        if ($symbol['method'] === '') {
            return [];
        }

        $trimmedMethod = ltrim($symbol['method'], '_');
        $queries = [];
        if ($symbol['class'] !== null) {
            $queries[] = sprintf('repo:%s/%s "%s::%s"', $owner, $repo, $symbol['class'], $symbol['method']);
            $queries[] = sprintf('repo:%s/%s "%s::%s("', $owner, $repo, $symbol['class'], $symbol['method']);
        }
        $queries[] = sprintf('repo:%s/%s "%s("', $owner, $repo, $trimmedMethod);
        $queries[] = sprintf('repo:%s/%s "%s("', $owner, $repo, $symbol['method']);
        $queries[] = sprintf('repo:%s/%s "%s"', $owner, $repo, $trimmedMethod);
        $queries[] = sprintf('repo:%s/%s "%s"', $owner, $repo, $symbol['method']);

        foreach ($this->buildFilenameHints($symbol) as $hint) {
            $queries[] = sprintf('repo:%s/%s path:%s "%s("', $owner, $repo, $hint, $trimmedMethod);
            $queries[] = sprintf('repo:%s/%s path:%s "%s"', $owner, $repo, $hint, $trimmedMethod);
        }

        return array_values(array_unique($queries));
    }

    /**
     * @param array{module: string, signature: string, method: string, class: ?string, namespaces: list<string>, offset: ?string} $symbol
     * @return array<string, mixed>|null
     */
    private function findBestMatchInLocalTree(string $sourceRoot, array $symbol, string $moduleBasename, float $deadlineAt): ?array
    {
        $method = $symbol['method'];
        if ($method === '') {
            return null;
        }

        $files = $this->collectCandidateFiles($sourceRoot);
        $best = null;
        $checked = 0;
        foreach ($files as $path) {
            if ($this->hasDeadlineExpired($deadlineAt) || $checked >= self::MAX_LOCAL_TREE_FILES) {
                break;
            }
            ++$checked;

            $relativePath = ltrim(str_replace('\\', '/', substr($path, strlen(rtrim($sourceRoot, '/\\')))), '/');
            $contents = @file_get_contents($path);
            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $match = $this->scoreFileContents($relativePath, $contents, $symbol, $moduleBasename);
            if ($match === null) {
                continue;
            }

            if ($best === null || $match['score'] > $best['score']) {
                $best = $match;
            }
        }

        if ($best === null || $best['score'] < 40) {
            return null;
        }

        $best['github_url'] = null;

        return $best;
    }

    /**
     * @return list<string>
     */
    private function collectCandidateFiles(string $sourceRoot): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if (preg_match('~[\\\\/](?:\.git|vendor|node_modules|var|cache|build|dist)[\\\\/]~i', $path) === 1) {
                continue;
            }

            $extension = strtolower((string) $file->getExtension());
            if (!in_array($extension, self::SOURCE_EXTENSIONS, true)) {
                continue;
            }

            if ($file->getSize() > 1024 * 1024 * 2) {
                continue;
            }

            $files[] = $path;
        }

        return $files;
    }

    /**
     * @param array{module: string, signature: string, method: string, class: ?string, namespaces: list<string>, offset: ?string} $symbol
     * @return array<string, mixed>|null
     */
    private function scoreFileContents(string $relativePath, string $contents, array $symbol, string $moduleBasename): ?array
    {
        if ($contents === '') {
            return null;
        }

        $lines = preg_split('/\R/', $contents) ?: [];
        $lowerContents = mb_strtolower($contents);
        $score = 0;
        $bestLine = null;
        $bestLineScore = 0;
        $hasMethodText = str_contains($contents, $symbol['method']);

        $class = $symbol['class'];
        $method = preg_quote($symbol['method'], '/');
        $classFamily = $class !== null ? preg_replace('/^[CI](?=[A-Z])/', '', $class) ?? $class : null;
        $hasClassDeclaration = $class !== null
            && preg_match(sprintf('/\b(?:class|struct)\s+%s\b/', preg_quote($class, '/')), $contents) === 1;
        $hasRelatedClassDeclaration = $classFamily !== null
            && preg_match(sprintf('/\b(?:class|struct)\s+[IC]?%s\b/', preg_quote($classFamily, '/')), $contents) === 1;
        $explicitClassPattern = $class !== null
            ? sprintf('/\b%s\s*::\s*%s\s*\(/', preg_quote($class, '/'), $method)
            : null;
        $memberPattern = sprintf('/\b%s\s*\(/', $method);
        $pathLower = mb_strtolower($relativePath);
        $basenameLower = mb_strtolower((string) pathinfo($relativePath, PATHINFO_FILENAME));
        $pathTokens = $this->tokenizePathSignals($pathLower);
        $filenameHints = $this->buildFilenameHints($symbol);
        $moduleHints = $this->buildModuleFilenameHints($moduleBasename);
        $moduleMatched = false;
        $bestLineText = null;
        $bestLineHasOpeningBrace = false;
        $bestLineLooksLikeDeclaration = false;

        if (!$hasMethodText && !$this->pathMatchesHints($basenameLower, $pathLower, $filenameHints)) {
            return null;
        }

        foreach ($lines as $index => $line) {
            $lineScore = 0;
            if ($explicitClassPattern !== null && preg_match($explicitClassPattern, $line) === 1) {
                $lineScore += 120;
            } elseif ($class !== null && $hasClassDeclaration && preg_match($memberPattern, $line) === 1) {
                $lineScore += 45;
            } elseif ($class !== null && $hasRelatedClassDeclaration && preg_match($memberPattern, $line) === 1) {
                $lineScore += 28;
            } elseif ($class === null && preg_match($memberPattern, $line) === 1) {
                $lineScore += 45;
            } else {
                continue;
            }

            if (preg_match('/\b(?:virtual|inline|static|constexpr|template|auto|void|int|float|double|bool|char|struct|class|public|private|protected|override|const|final|noexcept|decltype)/', $line) === 1) {
                $lineScore += 15;
            }

            if (str_contains($line, '{')) {
                $lineScore += 8;
            }

            if ($lineScore > $bestLineScore) {
                $bestLineScore = $lineScore;
                $bestLine = $index + 1;
                $bestLineText = $line;
            }
        }

        if ($bestLine === null && !$this->pathMatchesHints($basenameLower, $pathLower, $filenameHints)) {
            return null;
        }

        if ($bestLine !== null) {
            $bestLineText = $bestLineText ?? ($lines[$bestLine - 1] ?? '');
            [$bestLineHasOpeningBrace, $bestLineLooksLikeDeclaration] = $this->analyzeMatchedSignatureLine($lines, $bestLine);
        }

        $score += $bestLineScore;
        if ($hasClassDeclaration) {
            $score += 25;
        } elseif ($hasRelatedClassDeclaration) {
            $score += 12;
        }

        $namespaceSignature = $symbol['namespaces'] !== [] ? 'namespace ' . implode('::', $symbol['namespaces']) : '';
        if ($namespaceSignature !== '' && str_contains($contents, $namespaceSignature)) {
            $score += 35;
        }

        foreach ($symbol['namespaces'] as $namespace) {
            if (str_contains($lowerContents, mb_strtolower($namespace))) {
                $score += 8;
            }
        }

        if ($class !== null && str_contains($pathLower, mb_strtolower($class))) {
            $score += 8;
        }

        if ($symbol['namespaces'] !== []) {
            $lastNamespace = mb_strtolower((string) end($symbol['namespaces']));
            if ($lastNamespace !== '' && str_contains($pathLower, $lastNamespace)) {
                $score += 10;
            }
        }

        foreach ($filenameHints as $hint) {
            if ($hint !== '' && $basenameLower === $hint) {
                $score += 40;
                break;
            }
        }

        foreach ($moduleHints as $hint) {
            if ($hint !== '' && ($basenameLower === $hint || in_array($hint, $pathTokens, true) || str_contains($basenameLower, $hint))) {
                $score += 32;
                $moduleMatched = true;
                break;
            }
        }

        if ($class === null && $symbol['namespaces'] === []) {
            $genericMethod = $this->normalizeFilenameToken($symbol['method']);
            if (in_array($genericMethod, ['main', 'think', 'run', 'frame', 'update', 'init', 'shutdown'], true)
                && !$moduleMatched
                && !$this->pathMatchesHints($basenameLower, $pathLower, $filenameHints)
            ) {
                return null;
            }
        }

        $isDefinitionMatch = $bestLine !== null
            && $bestLineHasOpeningBrace
            && (
                ($explicitClassPattern !== null && preg_match($explicitClassPattern, (string) $bestLineText) === 1)
                || preg_match($memberPattern, (string) $bestLineText) === 1
            );

        $quality = 'approximate';
        $warning = 'Best-effort match based on module symbol, namespace, class, and method name.';
        if ($bestLineLooksLikeDeclaration) {
            $quality = 'declaration';
            $warning = 'Only a declaration was found, not the implementation.';
        } elseif ($isDefinitionMatch && $bestLineScore >= 120 && ($class !== null || count($symbol['namespaces']) >= 1)) {
            $quality = 'verified';
            $warning = null;
        } elseif ($isDefinitionMatch && $namespaceSignature !== '' && str_contains($contents, $namespaceSignature) && $bestLineScore >= 60) {
            $quality = 'verified';
            $warning = null;
        }

        $focusLine = $bestLine ?? 1;
        [$snippet, $snippetStart, $snippetEnd] = $this->buildSnippet($lines, $focusLine);

        return [
            'score' => $score,
            'quality' => $quality,
            'warning' => $warning,
            'relative_path' => $relativePath,
            'line_start' => $focusLine,
            'line_end' => $focusLine,
            'snippet' => $snippet,
            'github_url' => null,
        ];
    }

    /**
     * @param list<string> $lines
     * @return array{0: string, 1: int, 2: int}
     */
    private function buildSnippet(array $lines, int $centerLine): array
    {
        $start = max(1, $centerLine - 50);
        $end = min(count($lines), $centerLine + 50);
        $width = strlen((string) $end);
        $snippet = [];
        for ($line = $start; $line <= $end; $line++) {
            $prefix = $line === $centerLine ? '>' : ' ';
            $snippet[] = sprintf('%s %s: %s', $prefix, str_pad((string) $line, $width, ' ', STR_PAD_LEFT), $lines[$line - 1]);
        }

        return [implode("\n", $snippet), $start, $end];
    }

    /**
     * @return array{module: string, signature: string, method: string, class: ?string, namespaces: list<string>, offset: ?string}
     */
    private function parseSymbol(string $module, string $symbol): array
    {
        $signature = trim($symbol);
        if (str_contains($signature, '!')) {
            [, $signature] = explode('!', $signature, 2);
        }

        $offset = null;
        if (preg_match('/\s+\+\s+(0x[0-9a-f]+)$/i', $signature, $matches) === 1) {
            $offset = $matches[1];
            $signature = trim(substr($signature, 0, -strlen($matches[0])));
        }

        $signature = preg_replace('/\s*\([^)]*\)\s*$/', '', $signature) ?? $signature;
        $parts = array_values(array_filter(array_map('trim', explode('::', $signature)), static fn (string $part): bool => $part !== ''));
        $method = $parts !== [] ? array_pop($parts) : $signature;
        $class = null;
        if ($parts !== []) {
            $class = array_pop($parts);
        }

        return [
            'module' => $module,
            'signature' => $signature,
            'method' => trim($method),
            'class' => $class !== null ? trim($class) : null,
            'namespaces' => array_map(static fn (string $part): string => trim($part), $parts),
            'offset' => $offset,
        ];
    }

    private function normalizeGithubRepo(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \RuntimeException('GitHub repository is required.');
        }

        $value = preg_replace('~\.git$~i', '', $value) ?? $value;
        if (preg_match('~^https?://github\.com/([^/]+)/([^/]+?)(?:/tree/[^/]+)?/?$~i', $value, $matches) === 1) {
            return 'https://github.com/' . $matches[1] . '/' . $matches[2];
        }

        if (preg_match('~^([^/]+)/([^/]+)$~', $value, $matches) === 1) {
            return 'https://github.com/' . $matches[1] . '/' . $matches[2];
        }

        throw new \RuntimeException('GitHub repository must be in the form https://github.com/owner/repo or owner/repo.');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseGithubRepoParts(string $repoUrl): array
    {
        if (preg_match('~^https?://github\.com/([^/]+)/([^/]+)$~i', $repoUrl, $matches) !== 1) {
            throw new \RuntimeException('Unsupported GitHub repository URL.');
        }

        return [$matches[1], $matches[2]];
    }

    private function buildGithubBlobUrl(string $repoUrl, string $ref, string $relativePath, int $focusLine): string
    {
        return sprintf(
            '%s/blob/%s/%s#L%d',
            rtrim($repoUrl, '/'),
            str_replace('%2F', '/', rawurlencode($ref)),
            str_replace('%2F', '/', rawurlencode($relativePath)),
            $focusLine
        );
    }

    /**
     * @param array{module: string, signature: string, method: string, class: ?string, namespaces: list<string>, offset: ?string} $symbol
     * @return list<string>
     */
    private function buildFilenameHints(array $symbol): array
    {
        $hints = [];
        if ($symbol['namespaces'] !== []) {
            $lastNamespace = (string) end($symbol['namespaces']);
            $hint = $this->normalizeFilenameToken($lastNamespace);
            if ($hint !== '') {
                $hints[] = $hint;
            }
        }

        $trimmedMethod = ltrim($symbol['method'], '_');
        $methodHint = $this->normalizeFilenameToken(preg_replace('/^(?:FrameUpdate|OnAdd|OnRemove)/', '', $trimmedMethod) ?? $trimmedMethod);
        if ($methodHint !== '') {
            $hints[] = $methodHint;
        }

        foreach ($this->buildClassFilenameHints($symbol['class']) as $hint) {
            $hints[] = $hint;
        }

        return array_values(array_unique(array_filter($hints, static fn (string $hint): bool => $hint !== '')));
    }

    /**
     * @return list<string>
     */
    private function buildClassFilenameHints(?string $class): array
    {
        if ($class === null || trim($class) === '') {
            return [];
        }

        $hints = [];
        $normalized = $this->normalizeFilenameToken($class);
        if ($normalized !== '') {
            $hints[] = $normalized;
        }

        $stripped = preg_replace('/^[CI](?=[A-Z])/', '', $class) ?? $class;
        $strippedNormalized = $this->normalizeFilenameToken($stripped);
        if ($strippedNormalized !== '') {
            $hints[] = $strippedNormalized;
            $hints[] = str_replace('_', '', $strippedNormalized);
            $hints[] = 'i_' . $strippedNormalized;
            $hints[] = 'c_' . $strippedNormalized;
            $hints[] = 'i' . str_replace('_', '', $strippedNormalized);
            $hints[] = 'c' . str_replace('_', '', $strippedNormalized);
        }

        if ($strippedNormalized !== '' && str_ends_with($strippedNormalized, '_rules')) {
            $rulesBase = preg_replace('/_rules$/', '', $strippedNormalized) ?? $strippedNormalized;
            if ($rulesBase !== '') {
                $compactRulesBase = str_replace('_', '', $rulesBase);
                $hints[] = $rulesBase;
                $hints[] = $compactRulesBase;
                $hints[] = $rulesBase . '_gamerules';
                $hints[] = $compactRulesBase . '_gamerules';
                $hints[] = $compactRulesBase . 'gamerules';
                $hints[] = 'gamerules';
            }
        }

        return array_values(array_unique(array_filter($hints, static fn (string $hint): bool => $hint !== '')));
    }

    /**
     * @param array{module: string, signature: string, method: string, class: ?string, namespaces: list<string>, offset: ?string} $symbol
     * @return list<string>
     */
    private function buildCandidateRelativePaths(array $symbol, string $moduleBasename = ''): array
    {
        if ($symbol['class'] === null && $symbol['namespaces'] === []) {
            return [];
        }

        $directories = array_map([$this, 'normalizeFilenameToken'], array_slice($symbol['namespaces'], 0, -1));
        $methodBasedHints = $this->buildFilenameHints($symbol);
        $moduleHints = $this->buildModuleFilenameHints($moduleBasename);
        $filenames = array_values(array_unique(array_filter(array_merge(
            $methodBasedHints,
            $moduleHints
        ), static fn (string $value): bool => $value !== '')));
        $candidates = [];

        foreach (self::GITHUB_PREFIXES as $prefix) {
            foreach ($filenames as $filename) {
                if ($filename === '') {
                    continue;
                }

                foreach (self::SOURCE_EXTENSIONS as $extension) {
                    $segments = [];
                    if ($prefix !== '') {
                        $segments[] = $prefix;
                    }
                    foreach ($directories as $directory) {
                        if ($directory !== '') {
                            $segments[] = $directory;
                        }
                    }
                    $segments[] = $filename . '.' . $extension;
                    $candidates[] = implode('/', $segments);
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return list<string>
     */
    private function buildModuleFilenameHints(string $moduleBasename): array
    {
        $moduleBase = preg_replace('/\.[^.]+$/', '', $moduleBasename) ?? $moduleBasename;
        $moduleToken = $this->normalizeFilenameToken($moduleBase);
        if ($moduleToken === '') {
            return [];
        }

        $hints = [$moduleToken];
        foreach (preg_split('/_+/', $moduleToken) ?: [] as $part) {
            if ($part === '' || in_array($part, self::LOW_SIGNAL_TOKENS, true)) {
                continue;
            }

            $hints[] = $part;
        }

        return array_values(array_unique($hints));
    }

    private function normalizeFilenameToken(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value) ?? $value;
        $value = preg_replace('/[^A-Za-z0-9]+/', '_', $value) ?? $value;
        $value = trim(mb_strtolower($value), '_');

        return $value;
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function filterSignalTokens(array $tokens): array
    {
        $filtered = [];
        foreach ($tokens as $token) {
            $token = trim(mb_strtolower((string) $token));
            if ($token === '' || strlen($token) < 3 || in_array($token, self::LOW_SIGNAL_TOKENS, true)) {
                continue;
            }

            $filtered[] = $token;
        }

        return array_values(array_unique($filtered));
    }

    /**
     * @return list<string>
     */
    private function tokenizePathSignals(string $path): array
    {
        $parts = preg_split('/[^a-z0-9]+/', mb_strtolower($path)) ?: [];

        return $this->filterSignalTokens($parts);
    }

    /**
     * @param list<string> $filenameHints
     */
    private function pathMatchesHints(string $basenameLower, string $pathLower, array $filenameHints): bool
    {
        foreach ($filenameHints as $hint) {
            if ($hint !== '' && ($basenameLower === $hint || str_contains($pathLower, '/' . $hint . '.'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $lines
     */
    private function locateMethodLine(array $lines, string $method): ?int
    {
        $pattern = sprintf('/\b%s\s*\(/', preg_quote($method, '/'));
        foreach ($lines as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function discoverLocalRipgrepCandidates(string $sourceRoot, array $symbol, float $deadlineAt): array
    {
        if ($this->hasDeadlineExpired($deadlineAt)) {
            return [];
        }

        $binary = $this->findExecutable(['rg', 'ripgrep']);
        if ($binary === null) {
            return [];
        }

        $needles = array_values(array_unique(array_filter([
            $symbol['class'] !== null ? $symbol['class'] . '::' . $symbol['method'] : null,
            ltrim($symbol['method'], '_') . '(',
            $symbol['method'] . '(',
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '')));

        $paths = [];
        foreach ($needles as $needle) {
            if ($this->hasDeadlineExpired($deadlineAt)) {
                break;
            }

            $args = [$binary, '--files-with-matches', '--no-messages', '--glob', '*.{c,cc,cpp,cxx,h,hh,hpp,hxx,ipp,inl,php}', $needle, $sourceRoot];
            $process = new Process($args, $sourceRoot);
            $process->setTimeout(self::PROCESS_TIMEOUT_SECONDS);

            try {
                $process->run();
            } catch (\Throwable) {
                continue;
            }

            if (!$process->isSuccessful() && !in_array($process->getExitCode(), [0, 1], true)) {
                continue;
            }

            $output = trim($process->getOutput());
            if ($output === '') {
                continue;
            }

            foreach (preg_split('/\R/', $output) ?: [] as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $normalized = str_replace('\\', '/', $line);
                $rootNormalized = str_replace('\\', '/', rtrim($sourceRoot, '/\\'));
                if (str_starts_with($normalized, $rootNormalized . '/')) {
                    $normalized = substr($normalized, strlen($rootNormalized) + 1);
                }

                $paths[] = ltrim($normalized, '/');
            }
        }

        return array_values(array_unique(array_filter($paths, static fn (string $path): bool => $path !== '')));
    }

    /**
     * @return array{source_root: string, fingerprint: string, index_type: string, built_at: string}|null
     */
    private function ensureLocalSymbolIndex(string $sourceRoot, bool $reload = false, ?float $deadlineAt = null): ?array
    {
        if ($deadlineAt !== null && $this->hasDeadlineExpired($deadlineAt)) {
            return null;
        }

        $binary = $this->findExecutable(['ctags']);
        if ($binary === null) {
            return null;
        }

        $fingerprint = $this->computeSourceRootFingerprint($sourceRoot);
        if ($fingerprint === null) {
            return null;
        }

        $cacheDir = $this->projectDir . self::LOCAL_INDEX_CACHE . '/' . sha1($sourceRoot . '@' . $fingerprint);
        $tagsPath = $cacheDir . '/tags.txt';
        if ($reload) {
            \Filesystem::remove($cacheDir);
        }

        if (!\Filesystem::pathExists($tagsPath)) {
            if ($deadlineAt !== null && $this->hasDeadlineExpired($deadlineAt)) {
                return null;
            }

            \Filesystem::createDirectory($cacheDir, 0775, true);
            $args = [$binary, '-x', '--fields=+n', '--c-kinds=+fp', '--c++-kinds=+fp', '-R', $sourceRoot];
            $process = new Process($args, $sourceRoot);
            $process->setTimeout(self::CTAGS_PROCESS_TIMEOUT_SECONDS);
            try {
                $process->mustRun();
                \Filesystem::writeFile($tagsPath, $process->getOutput());
            } catch (\Throwable) {
                return null;
            }
        }

        $payload = [
            'source_root_hash' => sha1($sourceRoot),
            'source_root' => $sourceRoot,
            'fingerprint' => $fingerprint,
            'index_type' => 'ctags-x',
            'cache_path' => $tagsPath,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];

        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM source_lookup_local_index WHERE source_root_hash = ? AND fingerprint = ?',
            [$payload['source_root_hash'], $fingerprint]
        );

        if ($exists === false) {
            $this->connection->insert('source_lookup_local_index', $payload);
        } else {
            $this->connection->update(
                'source_lookup_local_index',
                $payload,
                ['source_root_hash' => $payload['source_root_hash'], 'fingerprint' => $fingerprint]
            );
        }

        return [
            'source_root' => $sourceRoot,
            'fingerprint' => $fingerprint,
            'index_type' => 'ctags-x',
            'built_at' => $payload['updated_at'],
        ];
    }

    private function computeSourceRootFingerprint(string $sourceRoot): ?string
    {
        if (!is_dir($sourceRoot)) {
            return null;
        }

        $gitBinary = $this->findExecutable(['git']);
        if ($gitBinary !== null) {
            $process = new Process([$gitBinary, '-C', $sourceRoot, 'rev-parse', 'HEAD']);
            $process->setTimeout(self::GIT_PROCESS_TIMEOUT_SECONDS);
            try {
                $process->mustRun();
                $head = trim($process->getOutput());
                if ($head !== '') {
                    return $head;
                }
            } catch (\Throwable) {
            }
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS)
        );

        $count = 0;
        $maxMtime = 0;
        $bytes = 0;
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $ext = strtolower((string) $file->getExtension());
            if (!in_array($ext, self::SOURCE_EXTENSIONS, true)) {
                continue;
            }
            ++$count;
            $maxMtime = max($maxMtime, (int) $file->getMTime());
            $bytes += (int) $file->getSize();
        }

        return sha1($sourceRoot . '|' . $count . '|' . $maxMtime . '|' . $bytes);
    }

    private function findExecutable(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $process = new Process([$candidate, '--version']);
            $process->setTimeout(self::EXECUTABLE_CHECK_TIMEOUT_SECONDS);
            try {
                $process->run();
                if ($process->isSuccessful()) {
                    return $candidate;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function decodeJsonList(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded), static fn (string $item): bool => trim($item) !== ''));
    }

    /**
     * @param array{module: string, signature: string, method: string, class: ?string, namespaces: list<string>, offset: ?string} $parsed
     * @param list<string> $attemptedPaths
     * @param list<string> $warnings
     * @param array{source_type: string, github_repo_url: ?string, github_ref: ?string, local_root: ?string} $effective
     */
    private function buildNoMatchReason(array $parsed, array $attemptedPaths, array $effective, array $warnings = [], bool $repositoryMiss = false): string
    {
        $parts = [
            sprintf(
                'No matching source file or method was found for this symbol. Parsed method: %s; class: %s; namespaces: %s; filename hints: %s.',
                $parsed['method'] !== '' ? $parsed['method'] : '[none]',
                $parsed['class'] ?? '[none]',
                $parsed['namespaces'] !== [] ? implode('::', $parsed['namespaces']) : '[none]',
                ($hints = $this->buildFilenameHints($parsed)) !== [] ? implode(', ', $hints) : '[none]'
            ),
        ];

        if ($effective['source_type'] === self::GITHUB_SOURCE) {
            $parts[] = sprintf(
                'Resolved GitHub target: %s @ %s.',
                $effective['github_repo_url'] ?? '[none]',
                $effective['github_ref'] ?? '[none]'
            );
            if ($repositoryMiss) {
                $parts[] = 'The selected repository may not contain source for this module/function.';
            }
        }

        if ($attemptedPaths !== [] && !$repositoryMiss) {
            $parts[] = 'Candidate paths: ' . implode(', ', array_slice($attemptedPaths, 0, 12));
        }

        if ($warnings !== []) {
            $parts[] = 'Fetch warnings: ' . implode(' ', array_slice($warnings, 0, 4));
        }

        return implode(' ', $parts);
    }

    /**
     * @param array{module: string, signature: string, method: string, class: ?string, namespaces: list<string>, offset: ?string} $parsed
     * @param list<string> $warnings
     * @param array{source_type: string, github_repo_url: ?string, github_ref: ?string, local_root: ?string} $effective
     */
    private function buildShortNoMatchReason(array $parsed, array $effective, bool $repositoryMiss = false, array $warnings = []): string
    {
        if ($repositoryMiss && $effective['source_type'] === self::GITHUB_SOURCE) {
            return 'Source not found in the selected repository.';
        }

        foreach ($warnings as $warning) {
            if (str_contains(mb_strtolower($warning), 'timed out') || str_contains(mb_strtolower($warning), 'time limit')) {
                return 'Source lookup timed out before a match was found.';
            }
            if (str_contains($warning, 'HTTP 401') || str_contains($warning, 'HTTP 403')) {
                return 'GitHub access was denied while fetching source files.';
            }
        }

        if ($effective['source_type'] === self::SAVED_SOURCE) {
            return 'No matching source was found using the saved mapping.';
        }

        if ($effective['source_type'] === self::LOCAL_SOURCE) {
            return 'No matching source file was found in the selected local path.';
        }

        return 'No matching source file or method was found.';
    }

    private function hasDeadlineExpired(float $deadlineAt): bool
    {
        return microtime(true) >= $deadlineAt;
    }

    /**
     * @return array{quality: string, warning: ?string}
     */
    private function normalizeCachedMatchMetadata(string $quality, ?string $warning, string $snippet): array
    {
        if ($quality !== 'verified') {
            return ['quality' => $quality, 'warning' => $warning];
        }

        $focusLine = $this->extractFocusedSnippetLine($snippet);
        if ($focusLine !== null && $this->looksLikeDeclarationOnlyLine($focusLine)) {
            return [
                'quality' => 'declaration',
                'warning' => 'Only a declaration was found, not the implementation.',
            ];
        }

        return ['quality' => $quality, 'warning' => $warning];
    }

    private function extractFocusedSnippetLine(string $snippet): ?string
    {
        foreach (preg_split('/\R/', $snippet) ?: [] as $line) {
            if (preg_match('/^\>\s+\d+\:\s*(.*)$/', $line, $matches) === 1) {
                return trim((string) $matches[1]);
            }
        }

        return null;
    }

    private function extractFocusedSnippetLineNumber(string $snippet): ?int
    {
        foreach (preg_split('/\R/', $snippet) ?: [] as $line) {
            if (preg_match('/^\>\s+(\d+)\:\s*(.*)$/', $line, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    private function looksLikeDeclarationOnlyLine(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return false;
        }

        if (str_contains($trimmed, '{')) {
            return false;
        }

        if (preg_match('/\)\s*(?:const\s*)?(?:override\s*)?(?:final\s*)?(?:noexcept\s*)?(?:=\s*0\s*)?;$/', $trimmed) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string> $lines
     * @return array{0: bool, 1: bool}
     */
    private function analyzeMatchedSignatureLine(array $lines, int $lineNumber): array
    {
        $line = $lines[$lineNumber - 1] ?? '';
        $trimmed = trim($line);
        $hasOpeningBrace = str_contains($trimmed, '{');
        if (!$hasOpeningBrace) {
            for ($i = $lineNumber; $i < min(count($lines), $lineNumber + 3); ++$i) {
                $next = trim((string) ($lines[$i] ?? ''));
                if ($next === '') {
                    continue;
                }

                if (str_starts_with($next, '{')) {
                    $hasOpeningBrace = true;
                }
                break;
            }
        }

        return [$hasOpeningBrace, $this->looksLikeDeclarationOnlyLine($trimmed)];
    }

    /**
     * @param list<string> $warnings
     * @return list<string>
     */
    private function sanitizeLookupWarnings(array $warnings): array
    {
        $sanitized = [];
        foreach ($warnings as $warning) {
            $warning = trim((string) $warning);
            if ($warning === '') {
                continue;
            }

            if ($this->isGithubNotFoundError($warning) && str_contains(mb_strtolower($warning), 'raw.githubusercontent.com')) {
                continue;
            }

            $sanitized[] = $warning;
        }

        return array_values(array_unique($sanitized));
    }

    private function isGithubNotFoundError(string $message): bool
    {
        $message = mb_strtolower($message);

        return str_contains($message, ' 404 ')
            || str_contains($message, 'http/2 404')
            || str_contains($message, 'http 404')
            || str_contains($message, 'not found');
    }

    /**
     * @param array{module_basename: string, module_identifier: ?string, runtime_module: string, symbol: string, matched_module_name: ?string} $moduleInfo
     * @param array<string, mixed>|null $mapping
     * @param array<string, mixed>|null $cache
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function buildStateResponse(array $moduleInfo, ?array $mapping, ?array $cache, bool $allowLocalOverride, array $extra = []): array
    {
        $response = [
            'status' => $cache !== null ? 'ok' : 'empty',
            'module_basename' => $moduleInfo['module_basename'],
            'module_identifier' => $moduleInfo['module_identifier'],
            'runtime_module' => $moduleInfo['runtime_module'],
            'symbol' => $moduleInfo['symbol'],
            'cache_hit' => $cache !== null,
            'has_mapping' => $mapping !== null,
            'preferred_tab' => $cache !== null ? 'source' : 'find',
            'resolved_repo' => $cache['github_repo_url'] ?? $mapping['github_repo_url'] ?? null,
            'resolved_ref' => $cache['github_ref'] ?? $mapping['github_ref'] ?? null,
            'allow_local_override' => $allowLocalOverride,
            'mapping' => $mapping,
            'provider_trace' => $cache['provider_trace'] ?? [],
            'rejection_reasons' => $cache['rejection_reasons'] ?? [],
        ];

        if ($cache !== null) {
            $displayRepo = $cache['github_repo_url'] ?? $mapping['github_repo_url'] ?? null;
            $displayRef = $cache['github_ref'] ?? $mapping['github_ref'] ?? null;
            $response['source_type'] = $cache['source_type'];
            $response['match_quality'] = $cache['match_quality'] ?? 'approximate';
            $response['file'] = $cache['resolved_file'] ?? '';
            $response['line_start'] = $cache['line_start'] ?? 0;
            $response['line_end'] = $cache['line_end'] ?? 0;
            $response['snippet'] = $cache['snippet'] ?? '';
            $response['github_url'] = $displayRepo !== null
                && $displayRef !== null
                && ($cache['resolved_file'] ?? '') !== ''
                && (int) ($cache['line_start'] ?? 0) > 0
                ? $this->buildGithubBlobUrl((string) $displayRepo, (string) $displayRef, (string) $cache['resolved_file'], (int) $cache['line_start'])
                : ($cache['github_url'] ?? null);
            $response['warning'] = $cache['warning'] ?? null;
        } else {
            $response['reason'] = 'No cached source result exists for this module yet.';
        }

        foreach ($extra as $key => $value) {
            $response[$key] = $value;
        }

        return $response;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }
}
