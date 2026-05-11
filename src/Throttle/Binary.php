<?php

namespace Throttle;

use App\Runtime\SymbolBinaryUpload;
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
            $this->markModuleSymbolsPresent($app, $result['module'], $result['identifier']);
            UploadFailureBackoff::registerSuccess($app['root'], $result['module'], $result['identifier']);
            $app['redis']->hIncrBy('throttle:stats', 'binaries:accepted', 1);

            return new Response($result['message'] . ($result['degraded'] ? ' (degraded fallback)' : '') . "\n");
        } catch (\Throwable $e) {
            $app['monolog']->warning('Binary was stored, but symbol generation failed.', [
                'module' => $moduleHint,
                'identifier' => $identifierHint,
                'exception' => $e,
            ]);
            $app['redis']->hIncrBy('throttle:stats', 'binaries:accepted:no-symbols', 1);
            UploadFailureBackoff::registerFailure($app['root'], $moduleHint, $identifierHint, 'binary', 400, $e->getMessage());

            return new Response('Stored binary, but symbols were not generated: ' . $e->getMessage() . "\n");
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

    private function markModuleSymbolsPresent(Application $app, string $module, string $identifier): void
    {
        if (!isset($app['db'])) {
            return;
        }

        $updated = $app['db']->executeUpdate(
            'UPDATE module SET present = 1 WHERE name = ? AND identifier = ? AND present = 0',
            array($module, $identifier)
        );

        if ($updated > 0) {
            $app['redis']->hIncrBy('throttle:stats', 'symbols:module-present-updates', $updated);
        }
    }

    private function stringField(Application $app, string $field): ?string
    {
        $value = $app['request']->get($field);

        return is_string($value) && $value !== '' ? $value : null;
    }

}
