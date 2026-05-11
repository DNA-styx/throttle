<?php

namespace App\Runtime;

use App\Legacy\LegacyBridgeFactory;
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
     * @return array<int, array{module: string, identifier: string, file: string, file_path: string, binary_path: string|null, bytes: int, modified_at: int}>
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
                    $binaryPath = $this->projectDir . '/symbols/binaries/' . $module . '/' . $identifier . '/' . $module;
                    $entries[] = [
                        'module' => $module,
                        'identifier' => $identifier,
                        'file' => $file,
                        'file_path' => $filePath,
                        'binary_path' => is_file($binaryPath) ? $binaryPath : null,
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

        return $entries;
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
}
