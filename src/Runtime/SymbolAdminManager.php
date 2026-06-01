<?php

namespace App\Runtime;

use App\Legacy\LegacyBridgeFactory;
use App\Runtime\CrashReprocessMarker;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

final class SymbolAdminManager
{
    public function __construct(
        private readonly LegacyBridgeFactory $legacyBridgeFactory,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{scanned: int, updated: int, cache_flushed: bool}
     */
    public function refreshCaches(bool $clean = true): array
    {
        $app = $this->legacyBridgeFactory->createConsole();

        $symbols = \Filesystem::listDirectory($app['root'] . '/symbols');
        $query = $app['db']->executeQuery(
            'SELECT DISTINCT name, identifier FROM module WHERE identifier != \'000000000000000000000000000000000\'' . ($clean ? '' : ' AND present = 0')
        );

        $modules = [];
        while (($module = $query->fetch()) !== false) {
            $modules[] = $module;
        }

        $updated = 0;
        foreach ($modules as $module) {
            $found = false;
            $symname = $module['name'];
            if (stripos($symname, '.pdb') === strlen($symname) - 4) {
                $symname = substr($symname, 0, -4);
            }

            foreach ($symbols as $path) {
                if (file_exists($app['root'] . '/symbols/' . $path . '/' . $module['name'] . '/' . $module['identifier'] . '/' . $symname . '.sym.gz')) {
                    $found = true;
                    break;
                }
            }

            if (!$found && !$clean) {
                continue;
            }

            $app['db']->executeUpdate(
                'UPDATE module SET present = ? WHERE name = ? AND identifier = ?',
                [(int) $found, $module['name'], $module['identifier']]
            );
            $updated++;
        }

        $lock = \PhutilFileLock::newForPath($app['root'] . '/cache/process.lck');
        $lock->lock(300);

        try {
            $app['redis']->del('throttle:cache:symbol');
            $app['redis']->del('throttle:cache:repo');
        } finally {
            $lock->unlock();
        }

        return [
            'scanned' => count($modules),
            'updated' => $updated,
            'cache_flushed' => true,
        ];
    }

    /**
     * @return array<int, array{
     *   module: string,
     *   version_count: int,
     *   entries: array<int, array{
     *     module: string,
     *     identifier: string,
     *     file: string,
     *     file_path: string,
     *     binary_path: string|null,
     *     bytes: int,
     *     modified_at: int
     *   }>
     * }>
     */
    public function listStoredSymbols(?string $filter = null, int $limit = 500): array
    {
        $root = $this->projectDir . '/symbols/public';
        if (!is_dir($root)) {
            return [];
        }

        $filter = $filter !== null ? trim($filter) : '';
        $filterNeedle = $filter !== '' ? mb_strtolower($filter) : null;
        $entries = [];

        foreach (\Filesystem::listDirectory($root, false) as $module) {
            $modulePath = $root . '/' . $module;
            if (!is_dir($modulePath)) {
                continue;
            }

            foreach (\Filesystem::listDirectory($modulePath, false) as $identifier) {
                $identifierPath = $modulePath . '/' . $identifier;
                if (!is_dir($identifierPath)) {
                    continue;
                }

                foreach (\Filesystem::listDirectory($identifierPath, false) as $file) {
                    if (!str_ends_with($file, '.sym.gz')) {
                        continue;
                    }

                    $haystack = mb_strtolower($module . ' ' . $identifier . ' ' . $file);
                    if ($filterNeedle !== null && !str_contains($haystack, $filterNeedle)) {
                        continue;
                    }

                    $filePath = $identifierPath . '/' . $file;
                    $binaryPath = $this->findStoredBinaryPath($module, $identifier);
                    $entries[] = [
                        'module' => $module,
                        'identifier' => $identifier,
                        'file' => $file,
                        'file_path' => $filePath,
                        'binary_path' => $binaryPath,
                        'binary_retention' => StorageRetentionManager::getBinaryRetentionStatus($this->projectDir, $module, $identifier),
                        'bytes' => is_file($filePath) ? (int) filesize($filePath) : 0,
                        'modified_at' => is_file($filePath) ? (int) filemtime($filePath) : 0,
                    ];

                    if (count($entries) >= $limit) {
                        break 3;
                    }
                }
            }
        }

        usort($entries, static function (array $left, array $right): int {
            return ($right['modified_at'] <=> $left['modified_at'])
                ?: strcmp($left['module'], $right['module'])
                ?: strcmp($left['identifier'], $right['identifier']);
        });

        $groups = [];
        foreach ($entries as $entry) {
            $module = $entry['module'];
            if (!isset($groups[$module])) {
                $groups[$module] = [
                    'module' => $module,
                    'version_count' => 0,
                    'entries' => [],
                ];
            }

            $groups[$module]['entries'][] = $entry;
            $groups[$module]['version_count']++;
        }

        return array_values($groups);
    }

    public function buildDiagnostics(int $recentUploadOffset = 0, int $recentUploadLimit = 15): array
    {
        $app = $this->legacyBridgeFactory->createConsole();
        $recentUploadOffset = max(0, $recentUploadOffset);
        $recentUploadLimit = max(1, $recentUploadLimit);

        $moduleRows = (int) $app['db']->fetchOne('SELECT COUNT(*) FROM module');
        $moduleVersions = (int) $app['db']->fetchOne('SELECT COUNT(*) FROM (SELECT DISTINCT name, identifier FROM module) AS versions');
        $crashesWithModules = (int) $app['db']->fetchOne('SELECT COUNT(DISTINCT crash) FROM module');
        $staleCrashes = CrashReprocessMarker::countCrashesAwaitingReprocess($app['db']);
        $processedCrashes = (int) $app['db']->fetchOne('SELECT COUNT(*) FROM crash WHERE processed = 1');
        $storedSymbolVersions = $this->countStoredSymbolVersions();
        $storedBinaryVersions = $this->countStoredBinaryVersions();
        $lastProcessedAt = $app['db']->fetchOne('SELECT MAX(created_at) FROM crash_processing_log');
        $lastUploadAt = $app['db']->fetchOne(
            'SELECT MAX(created_at) FROM upload_token_audit WHERE endpoint IN (\'symbols\', \'binary\') AND status_code IS NOT NULL AND status_code < 400'
        );
        $recentUploadRows = $app['db']->executeQuery(
            'SELECT created_at, endpoint, module, identifier, status_code, result, reason
             FROM upload_token_audit
             WHERE endpoint IN (\'symbols\', \'binary\')
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?',
            [$recentUploadLimit + 1, $recentUploadOffset],
            [\PDO::PARAM_INT, \PDO::PARAM_INT]
        )->fetchAll();
        $hasMoreUploads = count($recentUploadRows) > $recentUploadLimit;
        $recentUploads = array_slice($recentUploadRows, 0, $recentUploadLimit);

        $summary = [
            'has_warning' => false,
            'title' => null,
            'body' => null,
        ];

        if ($moduleRows === 0 && $storedSymbolVersions > 0) {
            $summary = [
                'has_warning' => true,
                'title' => 'Symbols are present on disk, but the database has no module inventory.',
                'body' => 'Refresh only syncs known module rows with the filesystem. It cannot link symbol files when the module table is empty.',
            ];
        } elseif ($moduleRows === 0 && $processedCrashes > 0) {
            $summary = [
                'has_warning' => true,
                'title' => 'Processed crashes exist, but module inventory is empty.',
                'body' => 'This usually means crashes were processed on another system, module rows were lost during migration, or the processing pipeline has not rebuilt module data yet.',
            ];
        } elseif ($staleCrashes > 0) {
            $summary = [
                'has_warning' => true,
                'title' => 'Symbols now exist, but some crashes still await reprocessing.',
                'body' => sprintf('Throttle has %d crash report(s) with symbols available in the module table but stale processed output. The next crash:process --update pass should rebuild their stack and symbol coverage.', $staleCrashes),
            ];
        } elseif ($moduleRows > 0 && $storedSymbolVersions === 0) {
            $summary = [
                'has_warning' => true,
                'title' => 'No stored symbols were found on disk.',
                'body' => 'Throttle knows about crash modules, but there are no stored symbol versions to match against them.',
            ];
        } elseif ($moduleRows === 0 && $lastUploadAt !== false && $lastProcessedAt !== false && strtotime((string) $lastUploadAt) > strtotime((string) $lastProcessedAt)) {
            $summary = [
                'has_warning' => true,
                'title' => 'Uploads happened after the last processing run.',
                'body' => 'Symbols or binaries were uploaded, but crashes have not been reprocessed since then, so module-to-symbol linkage is still stale.',
            ];
        }

        return [
            'module_rows' => $moduleRows,
            'module_versions' => $moduleVersions,
            'crashes_with_modules' => $crashesWithModules,
            'stale_crashes' => $staleCrashes,
            'processed_crashes' => $processedCrashes,
            'stored_symbol_versions' => $storedSymbolVersions,
            'stored_binary_versions' => $storedBinaryVersions,
            'last_processed_at' => $lastProcessedAt,
            'last_successful_upload_at' => $lastUploadAt,
            'recent_uploads' => $recentUploads,
            'recent_upload_pager' => [
                'offset' => $recentUploadOffset,
                'newest_offset' => $recentUploadOffset > 0 ? 0 : null,
                'older_offset' => $hasMoreUploads ? $recentUploadOffset + $recentUploadLimit : null,
                'limit' => $recentUploadLimit,
            ],
            'summary' => $summary,
        ];
    }

    /**
     * @param array<int, string> $selected
     */
    public function exportSelectedSymbols(array $selected): ?BinaryFileResponse
    {
        $entries = [];
        foreach ($selected as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            [$module, $identifier] = array_pad(explode('|', $value, 2), 2, null);
            if (!is_string($module) || !is_string($identifier) || $module === '' || $identifier === '') {
                continue;
            }

            $basePath = $this->projectDir . '/symbols/public/' . $module . '/' . $identifier;
            if (!is_dir($basePath)) {
                continue;
            }

            foreach (\Filesystem::listDirectory($basePath, false) as $file) {
                if (str_ends_with($file, '.sym.gz')) {
                    $entries[] = [$module, $identifier, $file, $basePath . '/' . $file];
                }
            }
        }

        if ($entries === []) {
            return null;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'throttle-symbol-export-');
        if ($tempPath === false) {
            throw new \RuntimeException('Could not create export archive.');
        }

        $zipPath = $tempPath . '.zip';
        @unlink($tempPath);

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create export archive.');
        }

        foreach ($entries as [$module, $identifier, $file, $path]) {
            $zip->addFile($path, $module . '/' . $identifier . '/' . $file);
        }

        $zip->close();

        $response = new BinaryFileResponse($zipPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'throttle-symbols-export-' . date('Ymd-His') . '.zip');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    public function deleteStoredSymbolVersion(string $module, string $identifier): array
    {
        $publicPath = $this->projectDir . '/symbols/public/' . $module . '/' . $identifier;
        $binaryPath = $this->projectDir . '/symbols/binaries/' . $module . '/' . $identifier;

        if (!is_dir($publicPath)) {
            throw new \RuntimeException(sprintf('Stored symbols for %s/%s were not found.', $module, $identifier));
        }

        $deletedFiles = 0;
        foreach (\Filesystem::listDirectory($publicPath, false) as $file) {
            $path = $publicPath . '/' . $file;
            if (is_file($path)) {
                unlink($path);
                $deletedFiles++;
            }
        }

        $this->removeEmptyDirectoryTree($publicPath, $this->projectDir . '/symbols/public/' . $module);

        if (is_dir($binaryPath)) {
            foreach (\Filesystem::listDirectory($binaryPath, false) as $file) {
                $path = $binaryPath . '/' . $file;
                if (is_file($path)) {
                    unlink($path);
                }
            }

            $this->removeEmptyDirectoryTree($binaryPath, $this->projectDir . '/symbols/binaries/' . $module);
        }

        $this->refreshCaches(true);

        return [
            'module' => $module,
            'identifier' => $identifier,
            'deleted_files' => $deletedFiles,
        ];
    }

    /**
     * @param array<int, string> $selected
     * @return array{deleted_versions: int}
     */
    public function deleteSelectedStoredSymbols(array $selected): array
    {
        $deletedVersions = 0;

        foreach ($selected as $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            [$module, $identifier] = array_pad(explode('|', $value, 2), 2, null);
            if (!is_string($module) || !is_string($identifier) || $module === '' || $identifier === '') {
                continue;
            }

            $publicPath = $this->projectDir . '/symbols/public/' . $module . '/' . $identifier;
            if (!is_dir($publicPath)) {
                continue;
            }

            $this->deleteStoredSymbolVersion($module, $identifier);
            $deletedVersions++;
        }

        return [
            'deleted_versions' => $deletedVersions,
        ];
    }

    private function removeEmptyDirectoryTree(string $leaf, string $modulePath): void
    {
        if (is_dir($leaf) && \Filesystem::listDirectory($leaf, false) === []) {
            rmdir($leaf);
        }

        if (is_dir($modulePath) && \Filesystem::listDirectory($modulePath, false) === []) {
            rmdir($modulePath);
        }
    }

    private function findStoredBinaryPath(string $module, string $identifier): ?string
    {
        $binaryDirectory = $this->projectDir . '/symbols/binaries/' . $module . '/' . $identifier;
        if (!is_dir($binaryDirectory)) {
            return null;
        }

        $preferred = $binaryDirectory . '/' . $module;
        if (is_file($preferred)) {
            return $preferred;
        }

        foreach (\Filesystem::listDirectory($binaryDirectory, false) as $file) {
            $path = $binaryDirectory . '/' . $file;
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private function countStoredSymbolVersions(): int
    {
        $root = $this->projectDir . '/symbols/public';
        if (!is_dir($root)) {
            return 0;
        }

        $count = 0;
        foreach (\Filesystem::listDirectory($root, false) as $module) {
            $modulePath = $root . '/' . $module;
            if (!is_dir($modulePath)) {
                continue;
            }

            foreach (\Filesystem::listDirectory($modulePath, false) as $identifier) {
                $identifierPath = $modulePath . '/' . $identifier;
                if (!is_dir($identifierPath)) {
                    continue;
                }

                foreach (\Filesystem::listDirectory($identifierPath, false) as $file) {
                    if (str_ends_with($file, '.sym.gz')) {
                        $count++;
                        break;
                    }
                }
            }
        }

        return $count;
    }

    private function countStoredBinaryVersions(): int
    {
        $root = $this->projectDir . '/symbols/binaries';
        if (!is_dir($root)) {
            return 0;
        }

        $count = 0;
        foreach (\Filesystem::listDirectory($root, false) as $module) {
            $modulePath = $root . '/' . $module;
            if (!is_dir($modulePath)) {
                continue;
            }

            foreach (\Filesystem::listDirectory($modulePath, false) as $identifier) {
                $identifierPath = $modulePath . '/' . $identifier;
                if (is_dir($identifierPath) && StorageRetentionManager::getBinaryRetentionStatus($this->projectDir, $module, $identifier)['deleted'] === false && $this->findStoredBinaryPath($module, $identifier) !== null) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
