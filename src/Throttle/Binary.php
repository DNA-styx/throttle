<?php

namespace Throttle;

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

        $module = $this->safeBasename(
            $this->stringField($app, 'debug_file_path')
            ?: $this->stringField($app, 'code_file_path')
            ?: $file->getClientOriginalName()
        );
        if ($module === null) {
            $app['redis']->hIncrBy('throttle:stats', 'binaries:rejected:bad-name', 1);

            return new Response('Invalid binary name', 400);
        }

        $identifier = $this->safeIdentifier(
            $this->stringField($app, 'debug_identifier')
            ?: $this->stringField($app, 'code_identifier')
        );
        if ($identifier === null) {
            $app['redis']->hIncrBy('throttle:stats', 'binaries:rejected:bad-identifier', 1);

            return new Response('Invalid binary identifier', 400);
        }

        $app['redis']->hIncrBy('throttle:stats', 'binaries:submitted', 1);
        $app['redis']->hIncrBy('throttle:stats', 'binaries:submitted:bytes', (int) $file->getSize());

        $binaryDirectory = $app['root'] . '/symbols/binaries/' . $module . '/' . $identifier;
        \Filesystem::createDirectory($binaryDirectory, 0777, true);

        $binaryPath = $binaryDirectory . '/' . $module;
        if (is_file($binaryPath)) {
            \Filesystem::remove($binaryPath);
        }

        $file->move($binaryDirectory, $module);
        @chmod($binaryPath, 0640);

        $symbolMessage = '';
        try {
            $symbolMessage = $this->dumpSymbols($app, $binaryPath, $module, $identifier);
            $app['redis']->hIncrBy('throttle:stats', 'binaries:accepted', 1);
        } catch (\Throwable $e) {
            $app['monolog']->warning('Binary was stored, but symbol generation failed.', [
                'module' => $module,
                'identifier' => $identifier,
                'exception' => $e,
            ]);
            $app['redis']->hIncrBy('throttle:stats', 'binaries:accepted:no-symbols', 1);
            $symbolMessage = 'Stored binary, but symbols were not generated: ' . $e->getMessage();
        }

        return new Response($symbolMessage . "\n");
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

    private function dumpSymbols(Application $app, string $binaryPath, string $module, string $identifier): string
    {
        $dumpSyms = $app['root'] . '/bin/dump_syms';
        if (is_file($dumpSyms) && is_executable($dumpSyms)) {
            try {
                [$stdout,] = (new \ExecFuture('%s %s', $dumpSyms, $binaryPath))
                    ->setTimeout(120)
                    ->resolvex();

                $firstLine = strtok($stdout, "\r\n");
                if (is_string($firstLine) && preg_match('/^MODULE (?P<operatingsystem>[^ ]++) (?P<architecture>[^ ]++) (?P<id>[a-fA-F0-9]++) (?P<name>[^\/\\\\\r\n]++)$/', $firstLine, $info) === 1) {
                    $this->writeCompressedSymbolFile($app, $info['name'], $info['id'], $stdout);

                    return sprintf('Stored binary and generated symbols for %s/%s/%s/%s', $info['name'], $info['id'], $info['operatingsystem'], $info['architecture']);
                }
            } catch (\Throwable $e) {
                $app['monolog']->warning('dump_syms failed for uploaded binary, falling back to nm.', [
                    'module' => $module,
                    'identifier' => $identifier,
                    'exception' => $e,
                ]);
            }
        }

        return $this->dumpPublicSymbolsWithNm($app, $binaryPath, $module, $identifier);
    }

    private function dumpPublicSymbolsWithNm(Application $app, string $binaryPath, string $module, string $identifier): string
    {
        $nmBinary = \Filesystem::resolveBinary('nm') ?? ($app['root'] . '/bin/nm');
        if (!is_file($nmBinary) || !is_executable($nmBinary)) {
            throw new \RuntimeException('No executable nm binary found.');
        }

        $architecture = $this->detectArchitecture($binaryPath);
        $contents = 'MODULE Linux ' . $architecture . ' ' . $identifier . ' ' . $module . PHP_EOL;
        $publicRecords = 0;

        $future = (new \ExecFuture('%s -nC %s', $nmBinary, $binaryPath))->setTimeout(120);
        foreach (new \LinesOfALargeExecFuture($future) as $line) {
            if (!preg_match('/^0+([0-9a-fA-F]+) +[tT] +([0-9a-zA-Z_.* ,():&]+)$/', $line, $matches)) {
                continue;
            }

            $contents .= 'PUBLIC ' . $matches[1] . ' 0 ' . $matches[2] . PHP_EOL;
            $publicRecords++;
        }

        $this->writeCompressedSymbolFile($app, $module, $identifier, $contents);

        return sprintf('Stored binary and generated %d public symbols for %s/%s/Linux/%s', $publicRecords, $module, $identifier, $architecture);
    }

    private function writeCompressedSymbolFile(Application $app, string $module, string $identifier, string $contents): void
    {
        $symbolName = $module;
        if (strtolower(pathinfo($symbolName, PATHINFO_EXTENSION)) === 'pdb') {
            $symbolName = substr($symbolName, 0, -4);
        }

        $path = $app['root'] . '/symbols/public/' . $module . '/' . $identifier;
        \Filesystem::createDirectory($path, 0777, true);
        \Filesystem::writeFile($path . '/' . $symbolName . '.sym.gz', gzencode($contents));
    }

    private function stringField(Application $app, string $field): ?string
    {
        $value = $app['request']->get($field);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function safeBasename(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $basename = basename(str_replace('\\', '/', $path));

        return preg_match('/^[^\/\\\\\r\n\x00]{1,255}$/', $basename) === 1 ? $basename : null;
    }

    private function safeIdentifier(?string $identifier): ?string
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        return preg_match('/^[a-zA-Z0-9]{6,128}$/', $identifier) === 1 ? $identifier : null;
    }

    private function detectArchitecture(string $binary): string
    {
        $handle = @fopen($binary, 'rb');
        if ($handle === false) {
            return 'x86';
        }

        try {
            $header = fread($handle, 20);
            if ($header === false || strlen($header) < 20 || substr($header, 0, 4) !== "\x7FELF") {
                return 'x86';
            }

            $machine = unpack('v', substr($header, 18, 2))[1] ?? 0;

            return match ($machine) {
                62 => 'x86_64',
                183 => 'arm64',
                default => 'x86',
            };
        } finally {
            fclose($handle);
        }
    }
}
