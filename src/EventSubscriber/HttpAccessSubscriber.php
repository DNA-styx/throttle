<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

final class HttpAccessSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Security $security,
        private readonly Environment $twig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 128],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof HttpExceptionInterface && !$exception instanceof AccessDeniedException) {
            return;
        }

        $statusCode = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_FORBIDDEN;
        if ($statusCode !== Response::HTTP_UNAUTHORIZED
            && $statusCode !== Response::HTTP_FORBIDDEN
            && $statusCode !== Response::HTTP_NOT_FOUND
            && $statusCode !== Response::HTTP_METHOD_NOT_ALLOWED
            && $statusCode !== Response::HTTP_GONE) {
            return;
        }

        $request = $event->getRequest();
        if ($request->isXmlHttpRequest()) {
            return;
        }

        if (
            ($statusCode === Response::HTTP_NOT_FOUND
                || $statusCode === Response::HTTP_METHOD_NOT_ALLOWED
                || $statusCode === Response::HTTP_GONE)
            && $this->expectsHtml($request, $statusCode)
        ) {
            $event->setResponse(new Response($this->twig->render('errors/http_status.html.twig', [
                'status_code' => $statusCode,
                'title' => match ($statusCode) {
                    Response::HTTP_GONE => 'Data no longer available',
                    Response::HTTP_METHOD_NOT_ALLOWED => 'Action not available',
                    default => 'Page not found',
                },
                'comment' => match ($statusCode) {
                    Response::HTTP_GONE => 'The requested crash data was deleted by storage cleanup and can no longer be opened.',
                    Response::HTTP_METHOD_NOT_ALLOWED => 'This action must be submitted from the site form and cannot be opened directly in the browser.',
                    default => 'The requested crash or page was not found.',
                },
            ]), $statusCode));

            return;
        }

        if ($statusCode === Response::HTTP_UNAUTHORIZED || $this->security->getUser() === null) {
            $return = $request->getRequestUri();
            if ($return === '' || $return[0] !== '/' || str_starts_with($return, '//')) {
                $return = '/';
            }

            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('login', [
                'return' => $return,
            ])));

            return;
        }
        $event->setResponse(new Response($this->twig->render('security/access_denied.html.twig'), Response::HTTP_FORBIDDEN));
    }

    private function expectsHtml(\Symfony\Component\HttpFoundation\Request $request, int $statusCode): bool
    {
        if ($statusCode !== Response::HTTP_METHOD_NOT_ALLOWED && $request->getMethod() !== 'GET') {
            return false;
        }

        $accept = strtolower((string) $request->headers->get('Accept', ''));
        if ($accept === '') {
            return true;
        }

        return str_contains($accept, 'text/html');
    }
}
