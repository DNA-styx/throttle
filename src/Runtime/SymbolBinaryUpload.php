<?php

namespace App\Runtime;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

final class SymbolToolException extends \RuntimeException
{
    private array $context;

    public function __construct(
        string $message,
        array $context = [],
        ?\Throwable $previous = null
    ) {
        $this->context = $context;
        parent::__construct($message, 0, $previous);
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

final class SymbolBinaryUpload
{
    /**
     * @return array{module: string, identifier: string, bytes: int, degraded: bool, message: string, binary_path: string, symbol_path: string, warning?: string}
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
            $degradedWarning = null;

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
                        $details[] = $dumpError['summary'];
                    }
                    if ($moduleIdError !== null) {
                        $details[] = $moduleIdError['summary'];
                    }
                    if ($details === []) {
                        $details[] = 'unsupported binary or identifier could not be extracted';
                    }

                    throw new SymbolToolException(
                        implode('; ', $details),
                        [
                            'module' => $module,
                            'identifier' => $identifierHint,
                            'dump_syms' => $dumpError,
                            'breakpad_moduleid' => $moduleIdError,
                            'summary' => implode('; ', $details),
                        ]
                    );
                }

                $degraded = true;
                $degradedWarning = $dumpError['summary'] ?? 'dump_syms failed; public symbols generated via fallback';
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
                'warning' => $degradedWarning,
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
     * @return array{0: ?string, 1: array{name: string, id: string, operatingsystem: string, architecture: string}|null, 2: ?array}
     */
    private static function tryDumpSyms(string $root, string $binaryPath): array
    {
        $dumpSyms = self::resolveBinary($root, 'dump_syms');
        if ($dumpSyms === null) {
            return [null, null, self::toolFailureContext('dump_syms', 'dump_syms executable not found')];
        }

        $process = new Process([$dumpSyms, $binaryPath], timeout: 120);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return [null, null, self::unexpectedProcessExceptionContext('dump_syms', $e, $process, 'extracting symbols', basename($binaryPath))];
        }
        if (!$process->isSuccessful()) {
            return [null, null, self::processFailureContext('dump_syms', $process, 'extracting symbols', basename($binaryPath))];
        }

        $output = $process->getOutput();
        $firstLine = strtok($output, "\r\n");
        if (!is_string($firstLine) || preg_match('/^MODULE (?P<operatingsystem>[^ ]++) (?P<architecture>[^ ]++) (?P<id>[a-fA-F0-9]++) (?P<name>[^\/\\\\\r\n]++)$/', $firstLine, $info) !== 1) {
            return [null, null, self::toolFailureContext('dump_syms', 'dump_syms did not produce a valid MODULE header')];
        }

        return [$output, $info, null];
    }

    /**
     * @return array{0: ?string, 1: ?array}
     */
    private static function readBreakpadModuleIdentifier(string $root, string $binaryPath): array
    {
        $moduleIdBinary = self::resolveBinary($root, 'breakpad_moduleid');
        if ($moduleIdBinary === null) {
            return [null, self::toolFailureContext('breakpad_moduleid', 'breakpad_moduleid executable not found')];
        }

        $process = new Process([$moduleIdBinary, $binaryPath], timeout: 120);
        try {
            $process->run();
        } catch (\Throwable $e) {
            return [null, self::unexpectedProcessExceptionContext('breakpad_moduleid', $e, $process, 'reading module identifier', basename($binaryPath))];
        }
        if (!$process->isSuccessful()) {
            return [null, self::processFailureContext('breakpad_moduleid', $process, 'reading module identifier', basename($binaryPath))];
        }

        $identifier = trim($process->getOutput());

        $safeIdentifier = self::safeIdentifier($identifier);
        if ($safeIdentifier === null) {
            return [null, self::toolFailureContext('breakpad_moduleid', 'breakpad_moduleid returned an invalid identifier')];
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
        $nmCandidates = self::resolveNmCandidates($root);
        if ($nmCandidates === []) {
            throw new SymbolToolException(
                'No executable nm binary found.',
                self::toolFailureContext('nm', 'No executable nm binary found.')
            );
        }

        $architecture = self::detectArchitecture($binaryPath);
        $contents = 'MODULE Linux ' . $architecture . ' ' . $identifier . ' ' . $module . PHP_EOL;
        $errors = [];

        foreach ($nmCandidates as $nmBinary) {
            $process = new Process([$nmBinary, '-nC', $binaryPath], timeout: 120);
            try {
                $process->run();
            } catch (\Throwable $e) {
                $errors[] = self::unexpectedProcessExceptionContext(
                    'nm',
                    $e,
                    $process,
                    'generating fallback public symbols using ' . $nmBinary,
                    basename($binaryPath)
                );
                continue;
            }

            if (!$process->isSuccessful()) {
                $errors[] = self::processFailureContext(
                    'nm',
                    $process,
                    'generating fallback public symbols using ' . $nmBinary,
                    basename($binaryPath)
                );
                continue;
            }

            foreach (preg_split('/\R/', $process->getOutput()) ?: [] as $line) {
                if (!preg_match('/^0+([0-9a-fA-F]+) +[tT] +([0-9a-zA-Z_.* ,():&]+)$/', $line, $matches)) {
                    continue;
                }

                $contents .= 'PUBLIC ' . $matches[1] . ' 0 ' . $matches[2] . PHP_EOL;
            }

            return $contents;
        }

        $primary = $errors[0] ?? self::toolFailureContext('nm', 'nm failed while generating fallback public symbols');
        $summaries = array_values(array_unique(array_filter(array_map(
            static fn(array $context): ?string => $context['summary'] ?? null,
            $errors
        ))));
        $primary['summary'] = implode('; ', $summaries !== [] ? $summaries : [$primary['summary']]);

        throw new SymbolToolException($primary['summary'], $primary);
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

    /**
     * @return array<int, string>
     */
    private static function resolveNmCandidates(string $root): array
    {
        $candidates = [
            '/usr/bin/nm',
            '/bin/nm',
            \Filesystem::resolveBinary('nm'),
            self::resolveBinary($root, 'nm'),
        ];

        $resolved = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            if (!is_file($candidate) || !is_executable($candidate)) {
                continue;
            }

            if (!in_array($candidate, $resolved, true)) {
                $resolved[] = $candidate;
            }
        }

        return $resolved;
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

    private static function toolFailureContext(string $tool, string $summary, ?int $exitCode = null, ?int $signal = null, string $stdout = '', string $stderr = ''): array
    {
        return [
            'tool' => $tool,
            'exit_code' => $exitCode,
            'signal' => $signal,
            'signal_name' => self::signalName($signal),
            'stdout_tail' => self::outputTail($stdout),
            'stderr_tail' => self::outputTail($stderr),
            'summary' => $summary,
        ];
    }

    private static function processFailureContext(string $tool, Process $process, string $action, ?string $target = null): array
    {
        $exitCode = $process->getExitCode();
        $signal = self::extractSignal($process, $exitCode);
        $signalName = self::signalName($signal);
        $targetSuffix = $target !== null && $target !== '' ? ' from ' . $target : '';

        if ($signalName !== null) {
            $summary = sprintf(
                '%s %s with %s while %s%s',
                $tool,
                $signalName === 'SIGABRT' ? 'aborted' : 'crashed',
                $signalName,
                $action,
                $targetSuffix
            );
        } elseif ($exitCode !== null) {
            $summary = sprintf('%s failed with exit code %d while %s%s', $tool, $exitCode, $action, $targetSuffix);
        } else {
            $summary = sprintf('%s failed while %s%s', $tool, $action, $targetSuffix);
        }

        return self::toolFailureContext(
            $tool,
            $summary,
            $exitCode,
            $signal,
            $process->getOutput(),
            $process->getErrorOutput()
        );
    }

    private static function unexpectedProcessExceptionContext(string $tool, \Throwable $e, Process $process, string $action, ?string $target = null): array
    {
        $message = $e->getMessage();
        $signal = preg_match('/signal "(\d+)"/', $message, $matches) === 1 ? (int) $matches[1] : null;
        $signalName = self::signalName($signal);
        $targetSuffix = $target !== null && $target !== '' ? ' from ' . $target : '';

        if ($signalName !== null) {
            $summary = sprintf(
                '%s %s with %s while %s%s',
                $tool,
                $signalName === 'SIGABRT' ? 'aborted' : 'crashed',
                $signalName,
                $action,
                $targetSuffix
            );
        } else {
            $summary = sprintf('%s failed while %s%s: %s', $tool, $action, $targetSuffix, $message);
        }

        return self::toolFailureContext(
            $tool,
            $summary,
            $process->getExitCode(),
            $signal,
            $process->getOutput(),
            $process->getErrorOutput() !== '' ? $process->getErrorOutput() : $message
        );
    }

    private static function extractSignal(Process $process, ?int $exitCode): ?int
    {
        $signal = method_exists($process, 'getTermSignal') ? $process->getTermSignal() : null;
        if (is_int($signal) && $signal > 0) {
            return $signal;
        }

        if ($exitCode !== null && $exitCode >= 129 && $exitCode <= 255) {
            return $exitCode - 128;
        }

        return null;
    }

    private static function signalName(?int $signal): ?string
    {
        return match ($signal) {
            6 => 'SIGABRT',
            7 => 'SIGBUS',
            8 => 'SIGFPE',
            9 => 'SIGKILL',
            10 => 'SIGUSR1',
            11 => 'SIGSEGV',
            12 => 'SIGUSR2',
            13 => 'SIGPIPE',
            14 => 'SIGALRM',
            15 => 'SIGTERM',
            default => $signal !== null ? 'SIG' . $signal : null,
        };
    }

    private static function outputTail(string $output, int $maxLines = 8): string
    {
        $output = trim($output);
        if ($output === '') {
            return '';
        }

        $lines = preg_split('/\R/', $output) ?: [];
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        return mb_substr(trim(implode("\n", $lines)), 0, 400);
    }
}
