<?php

namespace App\Legacy;

use Symfony\Component\HttpFoundation\Request;

class LegacyRequest extends Request
{
    public static function fromBaseRequest(Request $request, bool $includeContent = true): self
    {
        $legacyRequest = new self(
            $request->query->all(),
            $request->request->all(),
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all(),
            $includeContent ? $request->getContent() : ''
        );

        if ($request->hasSession()) {
            $legacyRequest->setSession($request->getSession());
        }

        $legacyRequest->setLocale($request->getLocale());
        $legacyRequest->setDefaultLocale($request->getDefaultLocale());

        return $legacyRequest;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $attributes = $this->attributes->all();
        if (array_key_exists($key, $attributes)) {
            return $attributes[$key];
        }

        $query = $this->query->all();
        if (array_key_exists($key, $query)) {
            return $query[$key];
        }

        $request = $this->request->all();
        if (array_key_exists($key, $request)) {
            return $request[$key];
        }

        return $default;
    }
}
