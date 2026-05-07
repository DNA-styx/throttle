<?php

namespace Silex;

use ArrayAccess;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Application implements ArrayAccess
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->values);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->values[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->values[(string) $offset]);
    }

    public function abort(int $statusCode, string $message = ''): never
    {
        throw new HttpException($statusCode, $message);
    }

    public function redirect(string $url, int $status = Response::HTTP_FOUND): RedirectResponse
    {
        return new RedirectResponse($url, $status);
    }

    /**
     * @param array<string, string> $headers
     */
    public function json(mixed $data, int $status = Response::HTTP_OK, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers);
    }

    public function sendFile(string $path): BinaryFileResponse
    {
        return new BinaryFileResponse($path);
    }

    public function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
