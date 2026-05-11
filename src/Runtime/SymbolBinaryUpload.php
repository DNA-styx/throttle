<?php

namespace App\Runtime;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class SymbolBinaryUpload
{
    /**
     * @return array{module: string, identifier: string, bytes: int, degraded: bool, message: string, binary_path: string, symbol_path: string}
     */
    public static function storeUploadedBinary(string $root, UploadedFile $file, ?string $moduleHint = null, ?string $identifierHint = null): array
    {
        if (!$file->isValid() || $file->getSize() <= 0) {
            throw new \RuntimeException('Missing binary file.');
        }

        $bytes = (int) $file->getSize();
        $originalName = $moduleHint ?: $file->getClientOriginalName();
        $lowerOriginalName = strtolower($originalName);
        if (str_ends_with($lowerOriginalName, '.sym') || str_ends_with($lowerOriginalName, '.sym.gz')) {
            throw new \RuntimeException('Uploaded file looks like a symbol file, not a binary. Upload the raw module binary such as .so, .dll, or executable instead.');
        }
        $module = self::safeBasename($originalName);
        if ($module === null) {
            throw new \RuntimeException('Invalid binary name.');
        }

        $working = self::storeTemporaryUpload($root, $file, $module);
        $workingPath = $working['path'];

        try {
            [$dumpOutput, $dumpInfo, $dumpError] = self::tryDumpSyms($root, $workingPath);

            $degraded = false;
            $identifier = null;
            $moduleFromBinary = $module;

            if ($dumpInfo !== null) {
                $moduleFromBinary = $dumpInfo['name'];
                $identifier = $dumpInfo['id'];
            } else {
                $identifier = self::safeIdentifier($identifierHint);
                $moduleIdError = null;
                if ($identifier === null) {
                    [$identifier, $moduleIdError] = self::readBreakpadModuleIdentifier($root, $workingPath);
                }

                if ($identifier === null) {
                    $details = [];
                    if ($dumpError !== null) {
                        $details[] = 'dump_syms failed: ' . $dumpError;
                    }
                    if ($moduleIdError !== null) {
                        $details[] = 'breakpad_moduleid failed: ' . $moduleIdError;
                    }
                    if ($details === []) {
                        $details[] = 'unsupported binary or identifier could not be extracted';
                    }

                    throw new \RuntimeException(implode('; ', $details));
                }

                $degraded = true;
            }

            $binaryPath = self::finalizeBinaryStorage($root, $workingPath, $moduleFromBinary, $identifier);

            if ($dumpInfo !== null && $dumpOutput !== null) {
                $symbolPath = self::writeCompressedSymbolFile($root, $dumpInfo['name'], $dumpInfo['id'], $dumpOutput);

                return [
                    'module' => $dumpInfo['name'],
                    'identifier' => $dumpInfo['id'],
                    'bytes' => $bytes,
                    'degraded' => false,
                    'message' => sprintf('Stored binary and generated symbols for %s/%s/%s/%s', $dumpInfo['name'], $dumpInfo['id'], $dumpInfo['operatingsystem'], $dumpInfo['architecture']),
                    'binary_path' => $binaryPath,
                    'symbol_path' => $symbolPath,
                ];
            }

            $symbolOutput = self::buildPublicSymbolsWithNm($root, $binaryPath, $moduleFromBinary, $identifier);
            $symbolPath = self::writeCompressedSymbolFile($root, $moduleFromBinary, $identifier, $symbolOutput);
            $publicCount = substr_count($symbolOutput, "\nPUBLIC ");

            return [
                'module' => $moduleFromBinary,
                'identifier' => $identifier,
                'bytes' => $bytes,
                'degraded' => $degraded,
                'message' => sprintf('Stored binary and generated %d public symbols for %s/%s', $publicCount, $moduleFromBinary, $identifier),
                'binary_path' => $binaryPath,
                'symbol_path' => $symbolPath,
            ];
        } finally {
            self::cleanupTemporaryUpload($working['directory']);
        }
    }

    public static function safeBasename(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $basename = basename(str_replace('\\', '/', $path));

        return preg_match('/^[^\/\\\\\r\n\x00]{1,255}$/', $basename) === 1 ? $basename : null;
    }

    public static function safeIdentifier(?string $identifier): ?string
    {
        if ($identifier === null || $identifier === '') {
            return null;
        }

        return preg_match('/^[a-zA-Z0-9]{6,128}$/', $identifier) === 1 ? $identifier : null;
    }

    /**
     * @return array{0: ?string, 1: array{name: string, id: string, operatingsystem: string, architecture: string}|null, 2: ?string}
     */
    private static function tryDumpSyms(string $root, string $binaryPath): array
    {
        $dumpSyms = self::resolveBinary($root, 'dump_syms');
        if ($dumpSyms === null) {
            return [null, null, 'dump_syms executable not found'];
        }

        $process = new Process([$dumpSyms, $binaryPath], timeout: 120);
        $process->run();
        if (!$process->isSuccessful()) {
            return [null, null, self::compactProcessError($process)];
        }

        $output = $process->getOutput();
        $firstLine = strtok($output, "\r\n");
        if (!is_string($firstLine) || preg_match('/^MODULE (?P<operatingsystem>[^ ]++) (?P<architecture>[^ ]++) (?P<id>[a-fA-F0-9]++) (?P<name>[^\/\\\\\r\n]++)$/', $firstLine, $info) !== 1) {
            return [null, null, 'dump_syms did not produce a valid MODULE header'];
        }

        return [$output, $info, null];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function readBreakpadModuleIdentifier(string $root, string $binaryPath): array
    {
        $moduleIdBinary = self::resolveBinary($root, 'breakpad_moduleid');
        if ($moduleIdBinary === null) {
            return [null, 'breakpad_moduleid executable not found'];
        }

        $process = new Process([$moduleIdBinary, $binaryPath], timeout: 120);
        $process->run();
        if (!$process->isSuccessful()) {
            return [null, self::compactProcessError($process)];
        }

        $identifier = trim($process->getOutput());

        $safeIdentifier = self::safeIdentifier($identifier);
        if ($safeIdentifier === null) {
            return [null, 'breakpad_moduleid returned an invalid identifier'];
        }

        return [$safeIdentifier, null];
    }

    /**
     * @return array{directory: string, path: string}
     */
    private static function storeTemporaryUpload(string $root, UploadedFile $file, string $module): array
    {
        $directory = $root . '/var/tmp-binary-upload/' . bin2hex(random_bytes(8));
        \Filesystem::createDirectory($directory, 0777, true);

        $file->move($directory, $module);
        $path = $directory . '/' . $module;
        @chmod($path, 0640);

        return ['directory' => $directory, 'path' => $path];
    }

    private static function finalizeBinaryStorage(string $root, string $workingPath, string $module, string $identifier): string
    {
        $binaryDirectory = $root . '/symbols/binaries/' . $module . '/' . $identifier;
        \Filesystem::createDirectory($binaryDirectory, 0777, true);

        $binaryPath = $binaryDirectory . '/' . $module;
        if (is_file($binaryPath)) {
            \Filesystem::remove($binaryPath);
        }

        if (!@rename($workingPath, $binaryPath)) {
            if (!@copy($workingPath, $binaryPath)) {
                throw new \RuntimeException('Could not store uploaded binary.');
            }
            @unlink($workingPath);
        }

        @chmod($binaryPath, 0640);

        return $binaryPath;
    }

    private static function buildPublicSymbolsWithNm(string $root, string $binaryPath, string $module, string $identifier): string
    {
        $nmBinary = \Filesystem::resolveBinary('nm') ?? self::resolveBinary($root, 'nm');
        if ($nmBinary === null) {
            throw new \RuntimeException('No executable nm binary found.');
        }

        $architecture = self::detectArchitecture($binaryPath);
        $contents = 'MODULE Linux ' . $architecture . ' ' . $identifier . ' ' . $module . PHP_EOL;

        $process = new Process([$nmBinary, '-nC', $binaryPath], timeout: 120);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        foreach (preg_split('/\R/', $process->getOutput()) ?: [] as $line) {
            if (!preg_match('/^0+([0-9a-fA-F]+) +[tT] +([0-9a-zA-Z_.* ,():&]+)$/', $line, $matches)) {
                continue;
            }

            $contents .= 'PUBLIC ' . $matches[1] . ' 0 ' . $matches[2] . PHP_EOL;
        }

        return $contents;
    }

    private static function writeCompressedSymbolFile(string $root, string $module, string $identifier, string $contents): string
    {
        $symbolName = $module;
        if (strtolower(pathinfo($symbolName, PATHINFO_EXTENSION)) === 'pdb') {
            $symbolName = substr($symbolName, 0, -4);
        }

        $path = $root . '/symbols/public/' . $module . '/' . $identifier;
        \Filesystem::createDirectory($path, 0777, true);

        $symbolPath = $path . '/' . $symbolName . '.sym.gz';
        \Filesystem::writeFile($symbolPath, gzencode($contents));

        return $symbolPath;
    }

    private static function resolveBinary(string $root, string $name): ?string
    {
        $candidate = $root . '/bin/' . $name;
        if (is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }

        return null;
    }

    private static function detectArchitecture(string $binary): string
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

    private static function cleanupTemporaryUpload(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $directory . '/' . $file;
            if (is_file($path)) {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }

    private static function compactProcessError(Process $process): string
    {
        $message = trim($process->getErrorOutput());
        if ($message === '') {
            $message = trim($process->getOutput());
        }
        if ($message === '') {
            $message = 'process exited with code ' . $process->getExitCode();
        }

        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return mb_substr($message, 0, 220);
    }
}
