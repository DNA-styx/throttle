<?php

namespace App\Controller;

use App\Entity\User;
use App\Runtime\UploadSettings;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
    public function index(Request $request, Connection $connection, KernelInterface $kernel, #[Autowire('%app.legacy%')] array $legacyConfig): Response
    {
        $root = $kernel->getProjectDir();
        $checks = [];
        $policyErrors = [];
        $uploadSettingsErrors = [];
        $policyTestInput = '';
        $policyTest = null;
        $runtimePolicy = $this->loadRuntimeSymbolRequestPolicy($root);
        $uploadSettings = UploadSettings::load($root);

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

        return $this->render('health/index.html.twig', [
            'checks' => $checks,
            'queue' => $queue,
            'uploadSettings' => $uploadSettings,
            'uploadSettingsErrors' => $uploadSettingsErrors,
            'uploadSettingsSaved' => $request->query->getBoolean('upload_settings_saved'),
            'uploadSettingsReset' => $request->query->getBoolean('upload_settings_reset'),
            'uploadSettingsSource' => is_file(UploadSettings::path($root)) ? UploadSettings::PATH : 'Default config',
            'currentMemoryLimit' => ini_get('memory_limit'),
            'symbolRequestPolicy' => \Throttle\Crash::getSymbolRequestPolicy($effectiveLegacyConfig),
            'symbolRequestPolicyFields' => $this->buildSymbolRequestPolicyFields(\Throttle\Crash::getSymbolRequestPolicy($effectiveLegacyConfig)),
            'symbolRequestPolicyErrors' => $policyErrors,
            'symbolRequestPolicySaved' => $request->query->getBoolean('policy_saved'),
            'symbolRequestPolicyReset' => $request->query->getBoolean('policy_reset'),
            'symbolRequestPolicySource' => $runtimePolicy === null ? 'Default config' : self::SYMBOL_REQUEST_POLICY_PATH,
            'policyTestInput' => $policyTestInput,
            'policyTest' => $policyTest,
            'healthy' => !in_array(false, array_column($checks, 'ok'), true),
        ]);
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
     * @return array{streaming_symbols_enabled: bool, upload_memory_limit: string, allow_anonymous_minidump_uploads: bool}
     */
    private function readUploadSettingsFromRequest(Request $request): array
    {
        return [
            'streaming_symbols_enabled' => $request->request->getBoolean('streaming_symbols_enabled'),
            'upload_memory_limit' => strtoupper(trim((string) $request->request->get('upload_memory_limit', '256M'))),
            'allow_anonymous_minidump_uploads' => $request->request->getBoolean('allow_anonymous_minidump_uploads'),
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

        return $errors;
    }
}
