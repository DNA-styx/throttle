<?php

namespace App\Controller;

use App\Entity\User;
use App\Runtime\SymbolAdminManager;
use App\Runtime\SymbolBinaryUpload;
use App\Runtime\UploadFailureBackoff;
use App\Runtime\UploadSettings;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_ADMIN)]
class HealthController extends AbstractController
{
    private const SYMBOL_REQUEST_POLICY_PATH = '/var/symbol-request-policy.json';

    #[Route('/health', name: 'health', methods: ['GET', 'POST'])]
    public function index(Request $request, Connection $connection, KernelInterface $kernel, SymbolAdminManager $symbolAdminManager, #[Autowire('%app.legacy%')] array $legacyConfig): Response
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

        if ($request->isMethod('POST')) {
            if ($request->request->get('upload_settings_form') !== null) {
                if (!$this->isCsrfTokenValid('upload-settings', (string) $request->request->get('_token'))) {
                    return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
                }

                if ($request->request->get('reset_upload_settings') !== null) {
                    UploadSettings::delete($root);

                    return $this->redirectToRoute('health', ['upload_settings_reset' => 1]);
                }

                $uploadSettings = $this->readUploadSettingsFromRequest($request);
                $uploadSettingsErrors = $this->validateUploadSettings($uploadSettings);
                if ($uploadSettingsErrors === []) {
                    UploadSettings::save($root, $uploadSettings);

                    return $this->redirectToRoute('health', ['upload_settings_saved' => 1]);
                }
            } elseif (!$this->isCsrfTokenValid('symbol-request-policy', (string) $request->request->get('_token'))) {
                return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
            } else {
                if ($request->request->get('reset_policy') !== null) {
                    $this->deleteRuntimeSymbolRequestPolicy($root);

                    return $this->redirectToRoute('health', ['policy_reset' => 1]);
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

                    return $this->redirectToRoute('health', ['policy_saved' => 1]);
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
        $backoffStats = UploadFailureBackoff::stats($root);
        $symbolsOpen = $symbolFilter !== '' || $request->query->getBoolean('symbols_open');
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
            'symbolFilter' => $symbolFilter,
            'symbolsOpen' => $symbolsOpen,
            'backoffStats' => $backoffStats,
            'healthy' => !in_array(false, array_column($checks, 'ok'), true),
        ]);
    }

    #[Route('/health/symbols/refresh', name: 'health_symbols_refresh', methods: ['POST'])]
    public function refreshSymbols(Request $request, SymbolAdminManager $symbolAdminManager): Response
    {
        if (!$this->isCsrfTokenValid('health-symbols-refresh', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $result = $symbolAdminManager->refreshCaches(true);
        $this->addFlash('success', sprintf('Symbol cache refreshed. Scanned %d module rows and updated %d entries.', $result['scanned'], $result['updated']));

        return $this->redirectToRoute('health');
    }

    #[Route('/health/symbols/backoff/reset', name: 'health_symbols_backoff_reset', methods: ['POST'])]
    public function resetBackoff(Request $request, KernelInterface $kernel): Response
    {
        if (!$this->isCsrfTokenValid('health-symbols-backoff-reset', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $cleared = UploadFailureBackoff::clear($kernel->getProjectDir());
        $this->addFlash('success', sprintf('Upload failure backoff state cleared for %d module(s).', $cleared));

        return $this->redirectToRoute('health');
    }

    #[Route('/health/symbols/upload-binary', name: 'health_symbols_upload_binary', methods: ['POST'])]
    public function uploadBinary(Request $request, KernelInterface $kernel, SymbolAdminManager $symbolAdminManager): Response
    {
        if (!$this->isCsrfTokenValid('health-symbols-upload-binary', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $file = $request->files->get('binary_file');
        if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() <= 0) {
            $this->addFlash('danger', 'Select a binary file to upload.');

            return $this->redirectToRoute('health', ['symbols_open' => 1]);
        }

        try {
            $result = SymbolBinaryUpload::storeUploadedBinary($kernel->getProjectDir(), $file);
            UploadFailureBackoff::registerSuccess($kernel->getProjectDir(), $result['module'], $result['identifier']);
            $symbolAdminManager->refreshCaches(false);
            $this->addFlash(
                $result['degraded'] ? 'warning' : 'success',
                $result['degraded']
                    ? sprintf('Binary uploaded for %s/%s. Symbols were generated via nm fallback.', $result['module'], $result['identifier'])
                    : sprintf('Binary uploaded for %s/%s. Breakpad symbols are ready.', $result['module'], $result['identifier'])
            );
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Binary upload failed: ' . $e->getMessage());
        }

        return $this->redirectToRoute('health', ['symbols_open' => 1]);
    }

    #[Route('/health/symbols/export', name: 'health_symbols_export', methods: ['POST'])]
    public function exportSymbols(Request $request, SymbolAdminManager $symbolAdminManager): Response
    {
        if (!$this->isCsrfTokenValid('health-symbols-export', (string) $request->request->get('_token'))) {
            return new Response('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        $selected = $request->request->all('selected_symbols');
        if (!is_array($selected) || $selected === []) {
            $this->addFlash('danger', $request->request->has('delete_selected') ? 'Select at least one symbol entry to delete.' : 'Select at least one symbol entry to export.');

            return $this->redirectToRoute('health', ['symbols_open' => 1]);
        }

        if ($request->request->has('delete_selected')) {
            try {
                $result = $symbolAdminManager->deleteSelectedStoredSymbols(array_values(array_filter($selected, 'is_string')));
                if ($result['deleted_versions'] === 0) {
                    $this->addFlash('danger', 'No stored symbols matched the selected entries.');
                } else {
                    $this->addFlash('success', sprintf('Deleted %d stored symbol version(s).', $result['deleted_versions']));
                }
            } catch (\Throwable $e) {
                $this->addFlash('danger', 'Failed to delete stored symbols: ' . $e->getMessage());
            }

            return $this->redirectToRoute('health', ['symbols_open' => 1]);
        }

        $response = $symbolAdminManager->exportSelectedSymbols(array_values(array_filter($selected, 'is_string')));
        if (!$response instanceof BinaryFileResponse) {
            $this->addFlash('danger', 'No stored symbols matched the selected entries.');

            return $this->redirectToRoute('health', ['symbols_open' => 1]);
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
            $this->addFlash('danger', 'Select a stored symbol version to delete.');

            return $this->redirectToRoute('health', ['symbols_open' => 1]);
        }

        try {
            $result = $symbolAdminManager->deleteStoredSymbolVersion($module, $identifier);
            $this->addFlash('success', sprintf('Deleted stored symbols for %s/%s.', $result['module'], $result['identifier']));
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Failed to delete stored symbols: ' . $e->getMessage());
        }

        return $this->redirectToRoute('health', ['symbols_open' => 1]);
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
     * @return array{streaming_symbols_enabled: bool, upload_memory_limit: string, allow_anonymous_minidump_uploads: bool, upload_failure_backoff_enabled: bool, upload_failure_backoff_threshold: int, upload_failure_backoff_ttl: int}
     */
    private function readUploadSettingsFromRequest(Request $request): array
    {
        return [
            'streaming_symbols_enabled' => $request->request->getBoolean('streaming_symbols_enabled'),
            'upload_memory_limit' => strtoupper(trim((string) $request->request->get('upload_memory_limit', '256M'))),
            'allow_anonymous_minidump_uploads' => $request->request->getBoolean('allow_anonymous_minidump_uploads'),
            'upload_failure_backoff_enabled' => $request->request->getBoolean('upload_failure_backoff_enabled'),
            'upload_failure_backoff_threshold' => (int) $request->request->get('upload_failure_backoff_threshold', 3),
            'upload_failure_backoff_ttl' => (int) $request->request->get('upload_failure_backoff_ttl', 3600),
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

        return $errors;
    }
}
