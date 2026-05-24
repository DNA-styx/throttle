<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AuthRedirector
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onSuccess(Request $request, string $defaultRoute = 'dashboard'): Response
    {
        $return = $request->query->get('return');
        if (!is_string($return) || $return === '') {
            $return = $request->request->get('return');
        }

        if (is_string($return) && $return !== '' && $return[0] === '/' && !str_starts_with($return, '//')) {
            return new RedirectResponse($return);
        }

        $targetPath = $request->getSession()->get('_security.main.target_path');
        if (is_string($targetPath) && $targetPath !== '' && $targetPath[0] === '/' && !str_starts_with($targetPath, '//')) {
            $request->getSession()->remove('_security.main.target_path');

            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate($defaultRoute));
    }

    public function onFailure(Request $request, string $message): Response
    {
        $request->getSession()->getFlashBag()->add('danger', $message);

        $params = [];
        $return = $request->query->get('return');
        if (!is_string($return) || $return === '') {
            $return = $request->request->get('return');
        }
        if (is_string($return) && $return !== '' && $return[0] === '/' && !str_starts_with($return, '//')) {
            $params['return'] = $return;
        }

        return new RedirectResponse($this->urlGenerator->generate('login', $params));
    }
}
