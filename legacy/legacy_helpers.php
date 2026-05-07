<?php

use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Process\Process;

if (!function_exists('id')) {
    function id(mixed $value): mixed
    {
        return $value;
    }
}

if (!class_exists('CommandException')) {
    class CommandException extends RuntimeException
    {
        private string $stdout;
        private string $stderr;

        public function __construct(string $message, string $stdout = '', string $stderr = '', int $code = 0)
        {
            parent::__construct($message, $code);
            $this->stdout = $stdout;
            $this->stderr = $stderr;
        }

        public function getStdout(): string
        {
            return $this->stdout;
        }

        public function getStderr(): string
        {
            return $this->stderr;
        }
    }
}

if (!class_exists('PhutilLockException')) {
    class PhutilLockException extends RuntimeException
    {
    }
}

if (!class_exists('PhutilFileLock')) {
    class PhutilFileLock
    {
        private string $path;
        /** @var resource|null */
        private $handle = null;

        private function __construct(string $path)
        {
            $this->path = $path;
        }

        public static function newForPath(string $path): self
        {
            return new self($path);
        }

        public function lock(int $timeout = 0): void
        {
            $directory = dirname($this->path);
            if ($directory !== '' && $directory !== '.') {
                Filesystem::createDirectory($directory, 0777, true);
            }

            $handle = fopen($this->path, 'c+');
            if ($handle === false) {
                throw new RuntimeException(sprintf('Failed to open lock file: %s', $this->path));
            }

            $start = microtime(true);
            do {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    $this->handle = $handle;

                    return;
                }

                if ($timeout === 0) {
                    fclose($handle);
                    throw new PhutilLockException(sprintf('Failed to acquire lock: %s', $this->path));
                }

                usleep(100000);
            } while ((microtime(true) - $start) < $timeout);

            fclose($handle);
            throw new PhutilLockException(sprintf('Timed out waiting for lock: %s', $this->path));
        }

        public function unlock(): void
        {
            if ($this->handle === null) {
                return;
            }

            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}

if (!class_exists('ExecFuture')) {
    class ExecFuture
    {
        private string $command;
        private ?string $cwd = null;
        private ?float $timeout = null;

        public function __construct(string $pattern, mixed ...$arguments)
        {
            $this->command = legacy_format_command($pattern, $arguments);
        }

        public function setCWD(string $cwd): self
        {
            $this->cwd = $cwd;

            return $this;
        }

        public function setTimeout(float $timeout): self
        {
            $this->timeout = $timeout;

            return $this;
        }

        public function start(): self
        {
            return $this;
        }

        public function resolve(): array
        {
            $process = Process::fromShellCommandline($this->command, $this->cwd);
            if ($this->timeout !== null) {
                $process->setTimeout($this->timeout);
            }

            $process->run();

            return [
                $process->getExitCode() ?? 1,
                $process->getOutput(),
                $process->getErrorOutput(),
            ];
        }

        public function resolvex(): array
        {
            [$exitCode, $stdout, $stderr] = $this->resolve();
            if ($exitCode !== 0) {
                throw new CommandException(
                    sprintf('Command failed with exit code %d: %s', $exitCode, $this->command),
                    $stdout,
                    $stderr,
                    $exitCode
                );
            }

            return [$stdout, $stderr];
        }
    }
}

if (!class_exists('LinesOfALargeExecFuture')) {
    class LinesOfALargeExecFuture implements IteratorAggregate
    {
        private ExecFuture $future;

        public function __construct(ExecFuture $future)
        {
            $this->future = $future;
        }

        public function getIterator(): Traversable
        {
            [$stdout,] = $this->future->resolvex();

            foreach (phutil_split_lines($stdout, false) as $line) {
                yield $line;
            }
        }
    }
}

if (!class_exists('Filesystem')) {
    class Filesystem
    {
        public static function resolvePath(string $path): string
        {
            $resolved = realpath($path);

            return $resolved !== false ? $resolved : $path;
        }

        public static function pathExists(string $path): bool
        {
            return file_exists($path);
        }

        public static function readRandomCharacters(int $length): string
        {
            $alphabet = array_merge(range('a', 'z'), range('2', '7'));
            $bytes = random_bytes($length);
            $output = '';

            for ($i = 0; $i < $length; $i++) {
                $output .= $alphabet[ord($bytes[$i]) % count($alphabet)];
            }

            return $output;
        }

        public static function readFile(string $path): string
        {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException(sprintf('Failed to read file: %s', $path));
            }

            return $contents;
        }

        public static function writeFile(string $path, string $contents): void
        {
            $directory = dirname($path);
            if ($directory !== '' && $directory !== '.') {
                self::createDirectory($directory, 0777, true);
            }

            if (file_put_contents($path, $contents) === false) {
                throw new RuntimeException(sprintf('Failed to write file: %s', $path));
            }
        }

        public static function appendFile(string $path, string $contents): void
        {
            $directory = dirname($path);
            if ($directory !== '' && $directory !== '.') {
                self::createDirectory($directory, 0777, true);
            }

            if (file_put_contents($path, $contents, FILE_APPEND) === false) {
                throw new RuntimeException(sprintf('Failed to append file: %s', $path));
            }
        }

        public static function createDirectory(string $path, int $mode = 0777, bool $recursive = false): string
        {
            if (!is_dir($path) && !mkdir($path, $mode, $recursive) && !is_dir($path)) {
                throw new RuntimeException(sprintf('Failed to create directory: %s', $path));
            }

            return $path;
        }

        public static function remove(string $path): void
        {
            (new SymfonyFilesystem())->remove($path);
        }

        /**
         * @return array<int, string>
         */
        public static function listDirectory(string $path, bool $includeHidden = true): array
        {
            $entries = scandir($path);
            if ($entries === false) {
                return [];
            }

            return array_values(array_filter($entries, static function (string $entry) use ($includeHidden): bool {
                if ($entry === '.' || $entry === '..') {
                    return false;
                }

                if (!$includeHidden && str_starts_with($entry, '.')) {
                    return false;
                }

                return true;
            }));
        }

        public static function resolveBinary(string $binary): ?string
        {
            $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
            foreach ($paths as $path) {
                $candidate = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $binary;
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }

            return null;
        }
    }
}

if (!class_exists('FutureIterator')) {
    class FutureIterator implements IteratorAggregate
    {
        private array $futures;

        public function __construct(array $futures)
        {
            $this->futures = $futures;
        }

        public function limit(int $limit): self
        {
            return $this;
        }

        public function getIterator(): Traversable
        {
            foreach ($this->futures as $key => $future) {
                yield $key => $future;
            }
        }
    }
}

if (!class_exists('HTTPFutureResponseStatus')) {
    class HTTPFutureResponseStatus extends RuntimeException
    {
        public function isError(): bool
        {
            return true;
        }
    }
}

if (!class_exists('HTTPFutureHTTPResponseStatus')) {
    class HTTPFutureHTTPResponseStatus extends HTTPFutureResponseStatus
    {
        private int $statusCode;
        /** @var array<int, int> */
        private array $expectedStatus;

        /**
         * @param array<int, int> $expectedStatus
         */
        public function __construct(int $statusCode, array $expectedStatus)
        {
            parent::__construct(sprintf('HTTP request returned status %d', $statusCode), $statusCode);
            $this->statusCode = $statusCode;
            $this->expectedStatus = $expectedStatus;
        }

        public function getStatusCode(): int
        {
            return $this->statusCode;
        }

        public function isError(): bool
        {
            return !in_array($this->statusCode, $this->expectedStatus, true);
        }
    }
}

if (!class_exists('HTTPSFuture')) {
    class HTTPSFuture
    {
        private string $url;
        /** @var array<int, string> */
        private array $headers = [];
        /** @var array<int, int> */
        private array $expectedStatus = [200];

        public function __construct(string $url)
        {
            $this->url = $url;
        }

        public function addHeader(string $name, string $value): self
        {
            $this->headers[] = $name . ': ' . $value;

            return $this;
        }

        /**
         * @param array<int, int> $statusCodes
         */
        public function setExpectStatus(array $statusCodes): self
        {
            $this->expectedStatus = $statusCodes;

            return $this;
        }

        /**
         * @return array{0: HTTPFutureResponseStatus, 1: string, 2: array<int, string>}
         */
        public function resolve(): array
        {
            $context = stream_context_create([
                'http' => [
                    'ignore_errors' => true,
                    'header' => implode("\r\n", $this->headers),
                    'timeout' => 30,
                ],
                'https' => [
                    'ignore_errors' => true,
                    'header' => implode("\r\n", $this->headers),
                    'timeout' => 30,
                ],
            ]);

            $body = @file_get_contents($this->url, false, $context);
            $responseHeaders = $http_response_header ?? [];

            if ($body === false && empty($responseHeaders)) {
                return [new HTTPFutureResponseStatus(sprintf('HTTP request failed: %s', $this->url)), '', []];
            }

            $statusCode = 0;
            foreach ($responseHeaders as $header) {
                if (preg_match('{^HTTP/\S+\s+(\d+)}', $header, $matches) === 1) {
                    $statusCode = (int) $matches[1];
                    break;
                }
            }

            return [new HTTPFutureHTTPResponseStatus($statusCode, $this->expectedStatus), $body ?: '', $responseHeaders];
        }
    }
}

if (!function_exists('phutil_split_lines')) {
    /**
     * @return array<int, string>
     */
    function phutil_split_lines(string $input, bool $retainEndings = true): array
    {
        if ($input === '') {
            return [];
        }

        preg_match_all('/.*(?:\r\n|\r|\n|$)/', $input, $matches);
        $lines = array_filter($matches[0], static fn (string $line): bool => $line !== '');

        if (!$retainEndings) {
            $lines = array_map(static fn (string $line): string => rtrim($line, "\r\n"), $lines);
        }

        return array_values($lines);
    }
}

if (!function_exists('execx')) {
    function execx(string $pattern, mixed ...$arguments): array
    {
        $command = legacy_format_command($pattern, $arguments);
        $process = Process::fromShellCommandline($command);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new CommandException(
                sprintf('Command failed with exit code %d: %s', $process->getExitCode() ?? 1, $command),
                $process->getOutput(),
                $process->getErrorOutput(),
                $process->getExitCode() ?? 1
            );
        }

        return [$process->getOutput(), $process->getErrorOutput()];
    }
}

if (!function_exists('legacy_format_command')) {
    function legacy_format_command(string $pattern, array $arguments): string
    {
        $index = 0;

        return preg_replace_callback('/%Ls|%s|%d/', static function (array $matches) use ($arguments, &$index): string {
            $argument = $arguments[$index++] ?? null;

            return match ($matches[0]) {
                '%Ls' => implode(' ', array_map('escapeshellarg', is_array($argument) ? $argument : [])),
                '%d' => (string) (int) $argument,
                default => escapeshellarg((string) $argument),
            };
        }, $pattern) ?? $pattern;
    }
}
