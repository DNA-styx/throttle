<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;

trait LegacyResponseTrait
{
    private function legacyResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        return new Response((string) $result);
    }
}
