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
        if ($statusCode !== Response::HTTP_UNAUTHORIZED && $statusCode !== Response::HTTP_FORBIDDEN) {
            return;
        }

        $request = $event->getRequest();
        if ($request->isXmlHttpRequest()) {
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
}
