<?php

namespace App\Controller;

use App\Entity\User;
use App\Runtime\AdminUserManager;
use App\Runtime\AuthEnvironment;
use App\Runtime\StorageRetentionManager;
use App\Runtime\SymbolAdminManager;
use App\Runtime\SymbolBinaryUpload;
use App\Runtime\SymbolToolException;
use App\Runtime\UploadFailureBackoff;
use App\Runtime\UploadSettings;
use App\Security\AuthMailer;
use App\Util\HumanSize;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_ADMIN)]
class HealthController extends AbstractController
{
    private const SYMBOL_REQUEST_POLICY_PATH = '/var/symbol-request-policy.json';

    #[Route('/health', name: 'health', methods: ['GET', 'POST'])]
    public function index(Request $request, Connection $connection, KernelInterface $kernel, SymbolAdminManager $symbolAdminManager, StorageRetentionManager $storageRetentionManager, AuthEnvironment $authEnvironment, AdminUserManager $adminUserManager, #[Autowire('%app.legacy%')] array $legacyConfig): Response
    {
        $root = $kernel->getProjectDir();
        $checks = [];
        $policyErrors = [];
        $uploadSettingsErrors = [];
        $policyTestInput = '';
        $policyTest = null;
        $runtimePolicy = $this->loadRuntimeSymbolRequestPolicy($root);
        $uploadSettings = UploadSettings::load($root);
        $symbolFilter = trim((string) $request->query->get('symbol_filter', ''));
        $recentUploadOffset = max(0, $request->query->getInt('recent_upload_offset', 0));

        if ($request->isMethod('POST')) {
            if ($request->request->get('upload_settings_form') !== null) {
                if (!$this->isCsrfTokenValid('upload-settings', (string) $request->request->get('_token'))) {
                    return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
                }

                if ($request->request->get('reset_upload_settings') !== null) {
                    UploadSettings::delete($root);

                    return $this->redirectToHealth(['upload_settings_reset' => 1], 'upload-processing');
                }

                $uploadSettings = $this->readUploadSettingsFromRequest($request);
                $uploadSettingsErrors = $this->validateUploadSettings($uploadSettings);
                if ($uploadSettingsErrors === []) {
                    UploadSettings::save($root, $uploadSettings);

                    return $this->redirectToHealth(['upload_settings_saved' => 1], 'upload-processing');
                }
            } elseif (!$this->isCsrfTokenValid('symbol-request-policy', (string) $request->request->get('_token'))) {
                return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
            } else {
                if ($request->request->get('reset_policy') !== null) {
                    $this->deleteRuntimeSymbolRequestPolicy($root);

                    return $this->redirectToHealth(['policy_reset' => 1], 'upload-processing');
                }

                $runtimePolicy = $this->readSymbolRequestPolicyFromRequest($request);
                $policyErrors = $this->validateSymbolRequestPolicy($runtimePolicy);
                $policyTestInput = trim((string) $request->request->get('policy_test_module', ''));

                if ($request->request->get('test_policy') !== null) {
                    if ($policyErrors === [] && $policyTestInput !== '') {
                        $policyTest = \Throttle\Crash::getSymbolRequestDecision($policyTestInput, ['symbol-request' => $runtimePolicy]);
                    }
                } elseif ($policyErrors === []) {
                    $this->saveRuntimeSymbolRequestPolicy($root, $runtimePolicy);

                    return $this->redirectToHealth(['policy_saved' => 1], 'upload-processing');
                }
            }
        }

        $effectiveLegacyConfig = $legacyConfig;
        if ($runtimePolicy !== null) {
            $effectiveLegacyConfig['symbol-request'] = $runtimePolicy;
        }

        $checks[] = $this->check('PHP version', version_compare(PHP_VERSION, '8.4.0', '>='), PHP_VERSION);

        try {
            $connection->fetchOne('SELECT 1');
            $checks[] = $this->check('Database', true, 'Connected');
        } catch (\Throwable $e) {
            $checks[] = $this->check('Database', false, $e->getMessage());
        }

        foreach (['var/cache', 'var/log', 'cache', 'dumps', 'symbols'] as $path) {
            $fullPath = $root . '/' . $path;
            $checks[] = $this->check($path . ' writable', is_dir($fullPath) && is_writable($fullPath), $fullPath);
        }

        foreach (['carburetor', 'minidump_stackwalk', 'dump_syms', 'breakpad_moduleid', 'nm'] as $binary) {
            $path = $root . '/bin/' . $binary;
            $checks[] = $this->check('bin/' . $binary, is_file($path) && is_executable($path), $path);
        }

        $queue = [
            'pending' => (int) $connection->fetchOne('SELECT COUNT(*) FROM crash WHERE processed = 0'),
            'failed' => (int) $connection->fetchOne('SELECT COUNT(*) FROM crash WHERE processed = 1 AND failed = 1'),
            'processed_today' => (int) $connection->fetchOne('SELECT COUNT(*) FROM crash WHERE processed = 1 AND timestamp >= CURDATE()'),
            'latest_processed' => $connection->fetchOne('SELECT MAX(created_at) FROM crash_processing_log'),
        ];

        $symbolGroups = $symbolAdminManager->listStoredSymbols($symbolFilter !== '' ? $symbolFilter : null);
        $symbolEntriesCount = 0;
        foreach ($symbolGroups as $group) {
            $symbolEntriesCount += count($group['entries']);
        }
        $symbolDiagnostics = $symbolAdminManager->buildDiagnostics($recentUploadOffset, 15);
        $storageCleanupSummary = $storageRetentionManager->getLastRunSummary();
        $storageCleanupUsage = $storageRetentionManager->getUsageSummary();
        $storageCleanupMegabytes = [];
        foreach ($uploadSettings['storage_cleanup'] as $category => $categorySettings) {
            $storageCleanupMegabytes[$category] = $this->sizeLimitToMegabytes((string) ($categorySettings['max_total_size'] ?? '0'));
        }
        $backoffStats = UploadFailureBackoff::stats($root);
        $symbolsOpen = $symbolFilter !== '' || $request->query->getBoolean('symbols_open');
        $symbolUploadsOpen = $request->query->getBoolean('symbol_uploads_open');
        $symbolRequestPolicy = \Throttle\Crash::getSymbolRequestPolicy($effectiveLegacyConfig);
        $policyEmpty = true;
        foreach ($symbolRequestPolicy as $values) {
            if ($values !== []) {
                $policyEmpty = false;
                break;
            }
        }

        return $this->render('health/index.html.twig', [
            'checks' => $checks,
            'authEnvironment' => $authEnvironment,
            'mailerSummary' => $authEnvironment->mailerSummary(),
            'mailerTestDefaultTo' => $authEnvironment->getMailerFrom() ?: '',
            'discordCallbackUrl' => $this->generateUrl('login_discord', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'userSummary' => $adminUserManager->summary(),
            'queue' => $queue,
            'uploadSettings' => $uploadSettings,
            'uploadSettingsErrors' => $uploadSettingsErrors,
            'uploadSettingsSaved' => $request->query->getBoolean('upload_settings_saved'),
            'uploadSettingsReset' => $request->query->getBoolean('upload_settings_reset'),
            'uploadSettingsSource' => is_file(UploadSettings::path($root)) ? UploadSettings::PATH : 'Default config',
            'currentMemoryLimit' => ini_get('memory_limit'),
            'symbolRequestPolicy' => $symbolRequestPolicy,
            'symbolRequestPolicyFields' => $this->buildSymbolRequestPolicyFields($symbolRequestPolicy),
            'symbolRequestPolicyErrors' => $policyErrors,
            'symbolRequestPolicySaved' => $request->query->getBoolean('policy_saved'),
            'symbolRequestPolicyReset' => $request->query->getBoolean('policy_reset'),
            'symbolRequestPolicySource' => $runtimePolicy === null ? 'Default config' : self::SYMBOL_REQUEST_POLICY_PATH,
            'symbolRequestPolicyEmpty' => $policyEmpty,
            'policyTestInput' => $policyTestInput,
            'policyTest' => $policyTest,
            'symbolGroups' => $symbolGroups,
            'symbolEntriesCount' => $symbolEntriesCount,
            'symbolDiagnostics' => $symbolDiagnostics,
            'symbolFilter' => $symbolFilter,
            'symbolsOpen' => $symbolsOpen,
            'symbolUploadsOpen' => $symbolUploadsOpen,
            'backoffStats' => $backoffStats,
            'storageCleanupSummary' => $storageCleanupSummary,
            'storageCleanupUsage' => $storageCleanupUsage,
            'storageCleanupMegabytes' => $storageCleanupMegabytes,
            'healthy' => !in_array(false, array_column($checks, 'ok'), true),
        ]);
    }

    #[Route('/health/auth/test-email', name: 'health_auth_test_email', methods: ['POST'])]
    public function sendTestEmail(Request $request, AuthEnvironment $authEnvironment, AuthMailer $authMailer): Response
    {
        if (!$this->isCsrfTokenValid('health-auth-test-email', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        if (!$authEnvironment->isMailerConfigured()) {
            $this->addHealthFlash('auth', 'danger', $authEnvironment->mailerNotice());

            return $this->redirectToHealth([], 'auth-mail');
        }

        $to = mb_strtolower(trim((string) $request->request->get('email', '')));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->addHealthFlash('auth', 'danger', 'Enter a valid recipient email for the SMTP test.');

            return $this->redirectToHealth([], 'auth-mail');
        }

        try {
            $authMailer->sendDiagnostic($to, $request->getSchemeAndHttpHost());
            $this->addHealthFlash('auth', 'success', sprintf('Test email sent to %s.', $to));
        } catch (\Throwable $e) {
            $this->addHealthFlash('auth', 'danger', 'SMTP test failed: ' . $e->getMessage());
        }

        return $this->redirectToHealth([], 'auth-mail');
    }

    #[Route('/health/symbols/refresh', name: 'health_symbols_refresh', methods: ['POST'])]
    public function refreshSymbols(Request $request, SymbolAdminManager $symbolAdminManager): Response
    {
        if (!$this->isCsrfTokenValid('health-symbols-refresh', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $result = $symbolAdminManager->refreshCaches(true);
        if ($result['scanned'] === 0) {
            $diagnostics = $symbolAdminManager->buildDiagnostics();
            $this->addHealthFlash(
                'symbols',
                'warning',
                sprintf(
                    'Symbol cache refresh scanned 0 module rows. Stored symbols on disk: %d, stored binaries on disk: %d. This means refresh has nothing in the module table to link against yet.',
                    (int) ($diagnostics['stored_symbol_versions'] ?? 0),
                    (int) ($diagnostics['stored_binary_versions'] ?? 0),
                )
            );
        } else {
            $this->addHealthFlash('symbols', 'success', sprintf('Symbol cache refreshed. Scanned %d module rows and updated %d entries.', $result['scanned'], $result['updated']));
        }

        return $this->redirectToHealth(['symbol_uploads_open' => 1], 'symbol-maintenance');
    }

    #[Route('/health/symbols/backoff/reset', name: 'health_symbols_backoff_reset', methods: ['POST'])]
    public function resetBackoff(Request $request, KernelInterface $kernel): Response
    {
        if (!$this->isCsrfTokenValid('health-symbols-backoff-reset', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $cleared = UploadFailureBackoff::clear($kernel->getProjectDir());
        $this->addHealthFlash('backoff', 'success', sprintf('Upload failure backoff state cleared for %d module(s).', $cleared));

        return $this->redirectToHealth([], 'backoff-state');
    }

    #[Route('/health/symbols/upload-binary', name: 'health_symbols_upload_binary', methods: ['POST'])]
    public function uploadBinary(Request $request, KernelInterface $kernel, SymbolAdminManager $symbolAdminManager, Connection $connection): Response
    {
        if (!$this->isCsrfTokenValid('health-symbols-upload-binary', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $files = $request->files->all('binary_files');
        if (!is_array($files) || $files === []) {
            $single = $request->files->get('binary_file');
            $files = $single instanceof UploadedFile ? [$single] : [];
        }

        $files = array_values(array_filter($files, static fn (mixed $file): bool => $file instanceof UploadedFile && $file->isValid() && ($file->getSize() ?? 0) > 0));
        if ($files === []) {
            $this->addHealthFlash('symbols', 'danger', 'Select a binary file to upload.');

            return $this->redirectToHealth(['symbols_open' => 1, 'symbol_uploads_open' => 1], 'symbol-maintenance');
        }

        $projectDir = $kernel->getProjectDir();
        $successfulUploads = 0;
        foreach ($files as $file) {
            try {
                $result = SymbolBinaryUpload::storeUploadedBinary($projectDir, $file);
                UploadFailureBackoff::registerSuccess($projectDir, $result['module'], $result['identifier']);
                StorageRetentionManager::clearVersionMarkers($projectDir, $result['module'], $result['identifier']);
                $this->recordManualUploadAudit(
                    $connection,
                    $request,
                    'binary',
                    $result['module'],
                    $result['identifier'],
                    (int) $result['bytes'],
                    200,
                    $result['degraded'] ? 'accepted-manual-degraded' : 'accepted-manual',
                    $result['warning'] ?? 'Uploaded from health page'
                );
                $successfulUploads++;

                $message = sprintf('Binary uploaded for %s/%s. ', $result['module'], $result['identifier']);
                if ($result['degraded']) {
                    $message .= isset($result['warning']) && $result['warning'] !== ''
                        ? $result['warning'] . '; public symbols were generated via fallback.'
                        : 'Symbols were generated via nm fallback.';
                } else {
                    $message .= 'Breakpad symbols are ready.';
                }

                $this->addHealthFlash(
                    'symbols',
                    $result['degraded'] ? 'warning' : 'success',
                    $message
                );
            } catch (\Throwable $e) {
                $context = $e instanceof SymbolToolException ? $e->getContext() : [];
                $name = $file->getClientOriginalName() ?: $file->getFilename();
                $this->recordManualUploadAudit(
                    $connection,
                    $request,
                    'binary',
                    null,
                    null,
                    (int) ($file->getSize() ?? 0),
                    500,
                    'rejected-manual',
                    $context['summary'] ?? $e->getMessage()
                );
                $this->addHealthFlash('symbols', 'danger', sprintf('Binary upload failed for %s: %s', $name, $context['summary'] ?? $e->getMessage()));
            }
        }

        if ($successfulUploads > 0) {
            $symbolAdminManager->refreshCaches(false);
        }

        return $this->redirectToHealth(['symbols_open' => 1, 'symbol_uploads_open' => 1], 'symbol-maintenance');
    }

    #[Route('/health/storage-cleanup/run', name: 'health_storage_cleanup_run', methods: ['POST'])]
    public function runStorageCleanup(Request $request, StorageRetentionManager $storageRetentionManager): Response
    {
        if (!$this->isCsrfTokenValid('health-storage-cleanup-run', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $summary = $storageRetentionManager->runCleanup('manual');
        $this->addHealthFlash(
            'storage_cleanup',
            'success',
            sprintf(
                'Storage cleanup finished. Deleted %d file(s) and reclaimed %s.',
                (int) ($summary['deleted_files'] ?? 0),
                HumanSize::format((int) ($summary['reclaimed_bytes'] ?? 0))
            )
        );

        return $this->redirectToHealth([], 'upload-processing');
    }

    #[Route('/health/symbols/export', name: 'health_symbols_export', methods: ['POST'])]
    public function exportSymbols(Request $request, SymbolAdminManager $symbolAdminManager): Response
    {
        if (!$this->isCsrfTokenValid('health-symbols-export', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $selected = $request->request->all('selected_symbols');
        if (!is_array($selected) || $selected === []) {
            $this->addHealthFlash('symbols', 'danger', $request->request->has('delete_selected') ? 'Select at least one symbol entry to delete.' : 'Select at least one symbol entry to export.');

            return $this->redirectToHealth(['symbols_open' => 1], 'stored-symbols');
        }

        if ($request->request->has('delete_selected')) {
            try {
                $result = $symbolAdminManager->deleteSelectedStoredSymbols(array_values(array_filter($selected, 'is_string')));
                if ($result['deleted_versions'] === 0) {
                    $this->addHealthFlash('symbols', 'danger', 'No stored symbols matched the selected entries.');
                } else {
                    $this->addHealthFlash('symbols', 'success', sprintf('Deleted %d stored symbol version(s).', $result['deleted_versions']));
                }
            } catch (\Throwable $e) {
                $this->addHealthFlash('symbols', 'danger', 'Failed to delete stored symbols: ' . $e->getMessage());
            }

            return $this->redirectToHealth(['symbols_open' => 1], 'stored-symbols');
        }

        $response = $symbolAdminManager->exportSelectedSymbols(array_values(array_filter($selected, 'is_string')));
        if (!$response instanceof BinaryFileResponse) {
            $this->addHealthFlash('symbols', 'danger', 'No stored symbols matched the selected entries.');

            return $this->redirectToHealth(['symbols_open' => 1], 'stored-symbols');
        }

        return $response;
    }

    #[Route('/health/symbols/delete', name: 'health_symbols_delete', methods: ['POST'])]
    public function deleteSymbolVersion(Request $request, SymbolAdminManager $symbolAdminManager): Response
    {
        if (!$this->isCsrfTokenValid('health-symbols-delete', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $module = trim((string) $request->request->get('module', ''));
        $identifier = trim((string) $request->request->get('identifier', ''));
        if ($module === '' || $identifier === '') {
            $this->addHealthFlash('symbols', 'danger', 'Select a stored symbol version to delete.');

            return $this->redirectToHealth(['symbols_open' => 1], 'stored-symbols');
        }

        try {
            $result = $symbolAdminManager->deleteStoredSymbolVersion($module, $identifier);
            $this->addHealthFlash('symbols', 'success', sprintf('Deleted stored symbols for %s/%s.', $result['module'], $result['identifier']));
        } catch (\Throwable $e) {
            $this->addHealthFlash('symbols', 'danger', 'Failed to delete stored symbols: ' . $e->getMessage());
        }

        return $this->redirectToHealth(['symbols_open' => 1], 'stored-symbols');
    }

    /**
     * @return array{name: string, ok: bool, detail: string}
     */
    private function check(string $name, bool $ok, string $detail): array
    {
        return ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }

    /**
     * @return array<string, array<int, string>>|null
     */
    private function loadRuntimeSymbolRequestPolicy(string $root): ?array
    {
        $path = $root . self::SYMBOL_REQUEST_POLICY_PATH;
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }

        $policy = [];
        foreach ($decoded as $key => $values) {
            if (!is_string($key) || !is_array($values)) {
                return null;
            }

            $policy[$key] = array_values(array_filter($values, static fn ($value): bool => is_string($value) && $value !== ''));
        }

        return $policy;
    }

    private function saveRuntimeSymbolRequestPolicy(string $root, array $policy): void
    {
        $path = $root . self::SYMBOL_REQUEST_POLICY_PATH;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    }

    private function deleteRuntimeSymbolRequestPolicy(string $root): void
    {
        $path = $root . self::SYMBOL_REQUEST_POLICY_PATH;
        if (is_file($path)) {
            unlink($path);
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function readSymbolRequestPolicyFromRequest(Request $request): array
    {
        $policy = [];
        foreach (array_keys(\Throttle\Crash::getSymbolRequestPolicy()) as $rule) {
            $value = $request->request->all('symbol_request_policy')[$rule] ?? '';
            $policy[$rule] = $this->splitPolicyLines(is_string($value) ? $value : '');
        }

        return $policy;
    }

    /**
     * @return array<int, string>
     */
    private function splitPolicyLines(string $value): array
    {
        $lines = preg_split('/\R/', $value) ?: [];
        $items = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $items[] = $line;
            }
        }

        return array_values(array_unique($items));
    }

    /**
     * @return array<int, string>
     */
    private function validateSymbolRequestPolicy(array $policy): array
    {
        $errors = [];
        foreach ($policy['allow-regex'] ?? [] as $pattern) {
            if (@preg_match($pattern, '') === false) {
                $errors[] = sprintf('Invalid allow-regex pattern: %s', $pattern);
            }
        }

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    private function buildSymbolRequestPolicyFields(array $policy): array
    {
        $fields = [];
        foreach ($policy as $rule => $values) {
            $fields[$rule] = implode("\n", $values);
        }

        return $fields;
    }

    /**
     * @return array{streaming_symbols_enabled: bool, upload_memory_limit: string, allow_anonymous_minidump_uploads: bool, upload_failure_backoff_enabled: bool, upload_failure_backoff_threshold: int, upload_failure_backoff_ttl: int, crash_source_lookup_enabled: bool, crash_ai_analysis_enabled: bool, auth_enable_steam: bool, auth_enable_discord: bool, auth_enable_email_login_link: bool, auth_enable_password_login: bool, auth_enable_password_registration: bool, auth_enable_password_reset: bool, auth_enable_token_login: bool}
     */
    private function readUploadSettingsFromRequest(Request $request): array
    {
        $storageCleanup = [];
        foreach (array_keys(StorageRetentionManager::defaults()) as $category) {
            $storageCleanup[$category] = [
                'enabled' => $request->request->getBoolean('storage_cleanup_' . $category . '_enabled'),
                'max_total_size' => $this->megabytesToSizeLimit((string) $request->request->get('storage_cleanup_' . $category . '_max_total_size', '0')),
                'max_age_days' => (int) $request->request->get('storage_cleanup_' . $category . '_max_age_days', 0),
            ];
        }

        return [
            'streaming_symbols_enabled' => $request->request->getBoolean('streaming_symbols_enabled'),
            'upload_memory_limit' => strtoupper(trim((string) $request->request->get('upload_memory_limit', '256M'))),
            'allow_anonymous_minidump_uploads' => $request->request->getBoolean('allow_anonymous_minidump_uploads'),
            'upload_failure_backoff_enabled' => $request->request->getBoolean('upload_failure_backoff_enabled'),
            'upload_failure_backoff_threshold' => (int) $request->request->get('upload_failure_backoff_threshold', 3),
            'upload_failure_backoff_ttl' => (int) $request->request->get('upload_failure_backoff_ttl', 3600),
            'crash_source_lookup_enabled' => $request->request->getBoolean('crash_source_lookup_enabled'),
            'crash_ai_analysis_enabled' => $request->request->getBoolean('crash_ai_analysis_enabled'),
            'auth_enable_steam' => $request->request->getBoolean('auth_enable_steam'),
            'auth_enable_discord' => $request->request->getBoolean('auth_enable_discord'),
            'auth_enable_email_login_link' => $request->request->getBoolean('auth_enable_email_login_link'),
            'auth_enable_password_login' => $request->request->getBoolean('auth_enable_password_login'),
            'auth_enable_password_registration' => $request->request->getBoolean('auth_enable_password_registration'),
            'auth_enable_password_reset' => $request->request->getBoolean('auth_enable_password_reset'),
            'auth_enable_token_login' => $request->request->getBoolean('auth_enable_token_login'),
            'storage_cleanup' => $storageCleanup,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function validateUploadSettings(array $settings): array
    {
        $errors = [];
        if (!UploadSettings::isValidMemoryLimit((string) ($settings['upload_memory_limit'] ?? ''))) {
            $errors[] = 'Invalid upload memory limit. Use values like 256M, 512M, 1G, or -1.';
        }
        if ((int) ($settings['upload_failure_backoff_threshold'] ?? 0) < 1) {
            $errors[] = 'Upload failure backoff threshold must be 1 or greater.';
        }
        if ((int) ($settings['upload_failure_backoff_ttl'] ?? 0) < 60) {
            $errors[] = 'Upload failure backoff TTL must be at least 60 seconds.';
        }
        if (
            !($settings['auth_enable_steam'] ?? false)
            && !($settings['auth_enable_discord'] ?? false)
            && !($settings['auth_enable_email_login_link'] ?? false)
            && !($settings['auth_enable_password_login'] ?? false)
            && !($settings['auth_enable_token_login'] ?? false)
        ) {
            $errors[] = 'At least one sign-in method must remain enabled.';
        }

        foreach (($settings['storage_cleanup'] ?? []) as $category => $categorySettings) {
            if (!StorageRetentionManager::isValidSizeLimit((string) ($categorySettings['max_total_size'] ?? '0'))) {
                $errors[] = sprintf('Invalid max total size for %s cleanup. Use values like 0, 500M, 2G, or 1T.', str_replace('_', ' ', $category));
            }
            if ((int) ($categorySettings['max_age_days'] ?? 0) < 0) {
                $errors[] = sprintf('Max age for %s cleanup must be 0 or greater.', str_replace('_', ' ', $category));
            }
        }

        return $errors;
    }

    private function addHealthFlash(string $section, string $level, string $message): void
    {
        $this->addFlash($section . '_' . $level, $message);
    }

    private function redirectToHealth(array $parameters = [], ?string $fragment = null): Response
    {
        if ($fragment !== null && $fragment !== '') {
            $parameters['_fragment'] = $fragment;
        }

        return $this->redirectToRoute('health', $parameters);
    }

    private function recordManualUploadAudit(Connection $connection, Request $request, string $endpoint, ?string $module, ?string $identifier, int $bytes, int $statusCode, string $result, ?string $reason): void
    {
        $user = $this->getUser();
        $connection->insert('upload_token_audit', [
            'owner_id' => $user instanceof User ? $user->getId() : null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'endpoint' => $endpoint,
            'remote_addr' => $request->getClientIp(),
            'account' => null,
            'module' => $module,
            'identifier' => $identifier,
            'bytes' => $bytes,
            'status_code' => $statusCode,
            'result' => $result,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
            'token_suffix' => null,
            'user_agent' => mb_substr((string) $request->headers->get('User-Agent'), 0, 255),
        ]);
    }

    private function megabytesToSizeLimit(string $value): string
    {
        $megabytes = max(0, (int) trim($value));

        return $megabytes > 0 ? ($megabytes . 'M') : '0';
    }

    private function sizeLimitToMegabytes(string $value): int
    {
        $bytes = StorageRetentionManager::parseSizeLimit($value);

        return $bytes > 0 ? (int) floor($bytes / (1024 * 1024)) : 0;
    }
}
