<?php

namespace App\Runtime;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class StorageRetentionManager
{
    private const STATE_PATH = '/var/storage-retention-state.json';
    private const AUTO_INTERVAL_SECONDS = 3600;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public static function defaults(): array
    {
        return [
            'crash_artifacts' => [
                'enabled' => false,
                'max_total_size' => '0',
                'max_age_days' => 0,
            ],
            'symbols' => [
                'enabled' => false,
                'max_total_size' => '0',
                'max_age_days' => 0,
            ],
            'binaries' => [
                'enabled' => false,
                'max_total_size' => '0',
                'max_age_days' => 0,
            ],
        ];
    }

    public static function normalizeSettings(mixed $settings): array
    {
        $defaults = self::defaults();
        if (!is_array($settings)) {
            return $defaults;
        }

        $normalized = [];
        foreach ($defaults as $category => $categoryDefaults) {
            $value = $settings[$category] ?? [];
            $normalized[$category] = [
                'enabled' => is_array($value) && array_key_exists('enabled', $value)
                    ? filter_var($value['enabled'], FILTER_VALIDATE_BOOL)
                    : $categoryDefaults['enabled'],
                'max_total_size' => self::normalizeSizeString(
                    is_array($value) && isset($value['max_total_size']) && is_scalar($value['max_total_size'])
                        ? (string) $value['max_total_size']
                        : $categoryDefaults['max_total_size']
                ),
                'max_age_days' => max(
                    0,
                    is_array($value) && isset($value['max_age_days']) && is_scalar($value['max_age_days'])
                        ? (int) $value['max_age_days']
                        : $categoryDefaults['max_age_days']
                ),
            ];
        }

        return $normalized;
    }

    public static function isValidSizeLimit(string $value): bool
    {
        return preg_match('/^(?:0|[1-9][0-9]*(?:K|M|G|T)?)$/i', trim($value)) === 1;
    }

    public static function normalizeSizeString(string $value): string
    {
        $value = strtoupper(trim($value));

        return self::isValidSizeLimit($value) ? $value : '0';
    }

    public static function parseSizeLimit(string $value): int
    {
        $value = self::normalizeSizeString($value);
        if ($value === '0') {
            return 0;
        }

        $unit = strtoupper(substr($value, -1));
        $multiplier = match ($unit) {
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
            'T' => 1024 * 1024 * 1024 * 1024,
            default => 1,
        };

        if ($multiplier !== 1) {
            $value = substr($value, 0, -1);
        }

        return (int) $value * $multiplier;
    }

    public function getLastRunSummary(): ?array
    {
        $state = self::loadState($this->projectDir);

        return isset($state['last_run']) && is_array($state['last_run']) ? $state['last_run'] : null;
    }

    public function getUsageSummary(): array
    {
        $summary = [];
        foreach (array_keys(self::defaults()) as $category) {
            $candidates = $this->scanCategoryCandidates($category);
            $summary[$category] = [
                'files' => count($candidates),
                'bytes' => array_sum(array_map(static fn(array $candidate): int => $candidate['size'], $candidates)),
            ];
        }

        return $summary;
    }

    public function runAutomaticCleanup(): ?array
    {
        $settings = UploadSettings::load($this->projectDir)['storage_cleanup'] ?? self::defaults();
        if (!$this->hasEnabledCategory($settings)) {
            return null;
        }

        $state = self::loadState($this->projectDir);
        $lastAutoRunAt = (int) (($state['auto']['last_completed_at'] ?? 0));
        if ($lastAutoRunAt > 0 && (time() - $lastAutoRunAt) < self::AUTO_INTERVAL_SECONDS) {
            return null;
        }

        return $this->runCleanup('automatic');
    }

    public function runCleanup(string $mode = 'manual'): array
    {
        $settings = UploadSettings::load($this->projectDir)['storage_cleanup'] ?? self::defaults();
        $state = self::loadState($this->projectDir);
        $now = time();

        $summary = [
            'mode' => $mode,
            'ran_at' => $now,
            'deleted_files' => 0,
            'reclaimed_bytes' => 0,
            'categories' => [],
        ];

        foreach ($settings as $category => $categorySettings) {
            $categorySummary = $this->cleanupCategory($category, $categorySettings, $state, $now);
            $summary['categories'][$category] = $categorySummary;
            $summary['deleted_files'] += $categorySummary['deleted_files'];
            $summary['reclaimed_bytes'] += $categorySummary['reclaimed_bytes'];
        }

        $state['last_run'] = $summary;
        if ($mode === 'automatic') {
            $state['auto']['last_completed_at'] = $now;
        }

        self::saveState($this->projectDir, $state);

        return $summary;
    }

    public static function getCrashArtifactStatus(string $root, string $crashId): array
    {
        $state = self::loadState($root);
        $artifacts = $state['crash_artifacts']['crashes'][$crashId] ?? [];
        $basePath = $root . '/dumps/' . substr($crashId, 0, 2) . '/' . $crashId;
        $dumpExists = is_file($basePath . '.dmp');
        $stackwalkExists = is_file($basePath . '.txt') || is_file($basePath . '.txt.gz');

        $dumpDeletedAt = !$dumpExists && isset($artifacts['dump_deleted_at']) ? (int) $artifacts['dump_deleted_at'] : null;
        $stackwalkDeletedAt = !$stackwalkExists && isset($artifacts['stackwalk_deleted_at']) ? (int) $artifacts['stackwalk_deleted_at'] : null;

        return [
            'dump_deleted' => $dumpDeletedAt !== null,
            'dump_deleted_at' => $dumpDeletedAt,
            'dump_reason' => $dumpDeletedAt !== null ? (string) ($artifacts['dump_reason'] ?? 'cleanup') : null,
            'stackwalk_deleted' => $stackwalkDeletedAt !== null,
            'stackwalk_deleted_at' => $stackwalkDeletedAt,
            'stackwalk_reason' => $stackwalkDeletedAt !== null ? (string) ($artifacts['stackwalk_reason'] ?? 'cleanup') : null,
            'has_deleted_artifacts' => $dumpDeletedAt !== null || $stackwalkDeletedAt !== null,
        ];
    }

    public static function getSymbolRetentionStatus(string $root, string $module, string $identifier): array
    {
        $state = self::loadState($root);
        $key = $module . "\0" . $identifier;
        $symbolName = str_ends_with(strtolower($module), '.pdb') ? substr($module, 0, -4) : $module;
        $exists = is_file($root . '/symbols/public/' . $module . '/' . $identifier . '/' . $symbolName . '.sym.gz');
        $entry = $state['symbols']['versions'][$key] ?? null;

        return [
            'deleted' => !$exists && is_array($entry) && isset($entry['deleted_at']),
            'deleted_at' => (!$exists && is_array($entry) && isset($entry['deleted_at'])) ? (int) $entry['deleted_at'] : null,
            'reason' => (!$exists && is_array($entry) && isset($entry['deleted_at'])) ? (string) ($entry['reason'] ?? 'cleanup') : null,
        ];
    }

    public static function getBinaryRetentionStatus(string $root, string $module, string $identifier): array
    {
        $state = self::loadState($root);
        $key = $module . "\0" . $identifier;
        $directory = $root . '/symbols/binaries/' . $module . '/' . $identifier;
        $exists = is_dir($directory) && self::directoryContainsFiles($directory);
        $entry = $state['binaries']['versions'][$key] ?? null;

        return [
            'deleted' => !$exists && is_array($entry) && isset($entry['deleted_at']),
            'deleted_at' => (!$exists && is_array($entry) && isset($entry['deleted_at'])) ? (int) $entry['deleted_at'] : null,
            'reason' => (!$exists && is_array($entry) && isset($entry['deleted_at'])) ? (string) ($entry['reason'] ?? 'cleanup') : null,
        ];
    }

    public static function clearVersionMarkers(string $root, string $module, string $identifier): void
    {
        $state = self::loadState($root);
        $key = $module . "\0" . $identifier;
        unset($state['symbols']['versions'][$key], $state['binaries']['versions'][$key]);
        self::saveState($root, $state);
    }

    private function cleanupCategory(string $category, array $settings, array &$state, int $now): array
    {
        $summary = [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'deleted_files' => 0,
            'reclaimed_bytes' => 0,
            'current_bytes' => 0,
            'reason' => null,
        ];

        if (!$summary['enabled']) {
            return $summary;
        }

        $candidates = $this->scanCategoryCandidates($category);
        $summary['current_bytes'] = array_sum(array_map(static fn(array $candidate): int => $candidate['size'], $candidates));

        $maxAgeDays = max(0, (int) ($settings['max_age_days'] ?? 0));
        $maxTotalSize = self::parseSizeLimit((string) ($settings['max_total_size'] ?? '0'));
        $deletionQueue = [];

        if ($maxAgeDays > 0) {
            $ageThreshold = $now - ($maxAgeDays * 86400);
            foreach ($candidates as $candidate) {
                if ($candidate['mtime'] <= $ageThreshold) {
                    $deletionQueue[$candidate['path']] = ['candidate' => $candidate, 'reason' => 'age'];
                }
            }
        }

        $remainingBytes = $summary['current_bytes'] - array_sum(array_map(
            static fn(array $entry): int => $entry['candidate']['size'],
            $deletionQueue
        ));

        if ($maxTotalSize > 0 && $remainingBytes > $maxTotalSize) {
            usort($candidates, static fn(array $left, array $right): int => ($left['mtime'] <=> $right['mtime']) ?: strcmp($left['path'], $right['path']));
            foreach ($candidates as $candidate) {
                if (isset($deletionQueue[$candidate['path']])) {
                    continue;
                }
                if ($remainingBytes <= $maxTotalSize) {
                    break;
                }

                $deletionQueue[$candidate['path']] = ['candidate' => $candidate, 'reason' => 'size'];
                $remainingBytes -= $candidate['size'];
            }
        }

        foreach ($deletionQueue as $entry) {
            $candidate = $entry['candidate'];
            if (!is_file($candidate['path'])) {
                continue;
            }

            @unlink($candidate['path']);
            $summary['deleted_files']++;
            $summary['reclaimed_bytes'] += $candidate['size'];
            $this->recordDeletionMarker($state, $category, $candidate, (string) $entry['reason'], $now);
        }

        if ($summary['deleted_files'] > 0) {
            $summary['reason'] = implode(', ', array_values(array_unique(array_map(
                static fn(array $entry): string => (string) $entry['reason'],
                $deletionQueue
            ))));
        }

        return $summary;
    }

    private function scanCategoryCandidates(string $category): array
    {
        return match ($category) {
            'crash_artifacts' => $this->scanCrashArtifactCandidates(),
            'symbols' => $this->scanSymbolCandidates(),
            'binaries' => $this->scanBinaryCandidates(),
            default => [],
        };
    }

    private function scanCrashArtifactCandidates(): array
    {
        $root = $this->projectDir . '/dumps';
        if (!is_dir($root)) {
            return [];
        }

        $candidates = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $filename = $file->getFilename();
            if (!preg_match('/^(?P<id>[0-9A-Za-z]{12})\.(?P<suffix>dmp|txt|txt\.gz)$/', $filename, $matches)) {
                continue;
            }
            if (str_contains($filename, '.meta.')) {
                continue;
            }

            $candidates[] = [
                'path' => $file->getPathname(),
                'size' => (int) $file->getSize(),
                'mtime' => (int) $file->getMTime(),
                'key' => $matches['id'],
                'kind' => $matches['suffix'] === 'dmp' ? 'dump' : 'stackwalk',
            ];
        }

        return $candidates;
    }

    private function scanSymbolCandidates(): array
    {
        $root = $this->projectDir . '/symbols/public';
        if (!is_dir($root)) {
            return [];
        }

        $candidates = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.sym.gz')) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $parts = explode('/', $relative);
            if (count($parts) < 3) {
                continue;
            }

            $candidates[] = [
                'path' => $file->getPathname(),
                'size' => (int) $file->getSize(),
                'mtime' => (int) $file->getMTime(),
                'key' => $parts[0] . "\0" . $parts[1],
                'module' => $parts[0],
                'identifier' => $parts[1],
            ];
        }

        return $candidates;
    }

    private function scanBinaryCandidates(): array
    {
        $root = $this->projectDir . '/symbols/binaries';
        if (!is_dir($root)) {
            return [];
        }

        $candidates = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $parts = explode('/', $relative);
            if (count($parts) < 3) {
                continue;
            }

            $candidates[] = [
                'path' => $file->getPathname(),
                'size' => (int) $file->getSize(),
                'mtime' => (int) $file->getMTime(),
                'key' => $parts[0] . "\0" . $parts[1],
                'module' => $parts[0],
                'identifier' => $parts[1],
            ];
        }

        return $candidates;
    }

    private function recordDeletionMarker(array &$state, string $category, array $candidate, string $reason, int $now): void
    {
        if ($category === 'crash_artifacts') {
            $state['crash_artifacts']['crashes'][$candidate['key']][$candidate['kind'] . '_deleted_at'] = $now;
            $state['crash_artifacts']['crashes'][$candidate['key']][$candidate['kind'] . '_reason'] = $reason;

            return;
        }

        $bucket = $category === 'symbols' ? 'symbols' : 'binaries';
        $state[$bucket]['versions'][$candidate['key']] = [
            'deleted_at' => $now,
            'reason' => $reason,
        ];
    }

    private function hasEnabledCategory(array $settings): bool
    {
        foreach ($settings as $category) {
            if (($category['enabled'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private static function loadState(string $root): array
    {
        $path = $root . self::STATE_PATH;
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function saveState(string $root, array $state): void
    {
        $path = $root . self::STATE_PATH;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    }

    private static function directoryContainsFiles(string $directory): bool
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (is_file($directory . '/' . $entry)) {
                return true;
            }
        }

        return false;
    }
}
