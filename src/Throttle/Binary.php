<?php

namespace Throttle;

use App\Runtime\CrashReprocessMarker;
use App\Runtime\SymbolBinaryUpload;
use App\Runtime\SymbolToolException;
use App\Runtime\UploadFailureBackoff;
use Silex\Application;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

class Binary
{
    public function submit(Application $app): Response
    {
        $file = $this->findUploadedBinaryFile($app);
        if (!$file instanceof UploadedFile || !$file->isValid() || $file->getSize() <= 0) {
            $app['redis']->hIncrBy('throttle:stats', 'binaries:rejected:no-file', 1);

            return new Response('Missing binary file', 400);
        }

        $moduleHint = SymbolBinaryUpload::safeBasename(
            $this->stringField($app, 'debug_file_path')
            ?: $this->stringField($app, 'code_file_path')
            ?: $file->getClientOriginalName()
        );
        if ($moduleHint === null) {
            $app['redis']->hIncrBy('throttle:stats', 'binaries:rejected:bad-name', 1);

            return new Response('Invalid binary name', 400);
        }

        $identifierHint = SymbolBinaryUpload::safeIdentifier(
            $this->stringField($app, 'debug_identifier')
            ?: $this->stringField($app, 'code_identifier')
        );
        if ($identifierHint === null) {
            $app['redis']->hIncrBy('throttle:stats', 'binaries:rejected:bad-identifier', 1);

            return new Response('Invalid binary identifier', 400);
        }

        $app['redis']->hIncrBy('throttle:stats', 'binaries:submitted', 1);
        $app['redis']->hIncrBy('throttle:stats', 'binaries:submitted:bytes', (int) $file->getSize());

        try {
            $result = SymbolBinaryUpload::storeUploadedBinary($app['root'], $file, $moduleHint, $identifierHint);
            $this->setUploadInfo($app, $result['module'], $result['identifier'], (int) $file->getSize());
            $reprocess = $this->markModuleSymbolsPresent($app, $result['module'], $result['identifier']);
            UploadFailureBackoff::registerSuccess($app['root'], $result['module'], $result['identifier']);
            $app['redis']->hIncrBy('throttle:stats', 'binaries:accepted', 1);

            $message = $result['message'];
            if ($result['degraded']) {
                $message .= !empty($result['warning']) ? ' (' . $result['warning'] . '; public symbols generated via fallback)' : ' (degraded fallback)';
            }
            if (($reprocess['stale_crashes'] ?? 0) > 0) {
                $message .= ' Marked ' . (int) $reprocess['stale_crashes'] . ' crash report(s) for reprocessing.';
            }

            return new Response($message . "\n");
        } catch (\Throwable $e) {
            $context = $e instanceof SymbolToolException ? $e->getContext() : [];
            $app['monolog']->warning('Binary was stored, but symbol generation failed.', [
                'module' => $moduleHint,
                'identifier' => $identifierHint,
                'tool' => $context['tool'] ?? null,
                'exit_code' => $context['exit_code'] ?? null,
                'signal' => $context['signal_name'] ?? null,
                'stderr_tail' => $context['stderr_tail'] ?? null,
                'summary' => $context['summary'] ?? $e->getMessage(),
                'exception' => $e,
            ]);
            $app['redis']->hIncrBy('throttle:stats', 'binaries:accepted:no-symbols', 1);
            UploadFailureBackoff::registerFailure($app['root'], $moduleHint, $identifierHint, 'binary', 400, $context['summary'] ?? $e->getMessage());

            return new Response('Stored binary, but symbols were not generated: ' . ($context['summary'] ?? $e->getMessage()) . "\n");
        }
    }

    private function findUploadedBinaryFile(Application $app): ?UploadedFile
    {
        foreach (array('code_file', 'upload_file_binary', 'upload_file_code', 'binary_file', 'binary') as $field) {
            $file = $app['request']->files->get($field);
            if ($file instanceof UploadedFile && $file->isValid() && $file->getSize() > 0) {
                return $file;
            }
        }

        return null;
    }

    private function markModuleSymbolsPresent(Application $app, string $module, string $identifier): array
    {
        if (!isset($app['db'])) {
            return ['updated_modules' => 0, 'stale_crashes' => 0];
        }

        $result = CrashReprocessMarker::markModuleSymbolsPresentAndScheduleReprocess($app['db'], $module, $identifier);

        if (($result['updated_modules'] ?? 0) > 0) {
            $app['redis']->hIncrBy('throttle:stats', 'symbols:module-present-updates', (int) $result['updated_modules']);
        }

        return $result;
    }

    private function stringField(Application $app, string $field): ?string
    {
        $value = $app['request']->get($field);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function setUploadInfo(Application $app, string $module, string $identifier, int $bytes): void
    {
        $info = ['module' => $module, 'identifier' => $identifier, 'bytes' => $bytes];
        if (isset($app['base_request'])) {
            $app['base_request']->attributes->set('_symbol_upload_info', $info);
        }
        $app['request']->attributes->set('_symbol_upload_info', $info);
    }

}
