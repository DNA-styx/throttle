<?php

namespace App\EventSubscriber;

use App\Security\BootstrapAdminManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class BootstrapAdminSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BootstrapAdminManager $bootstrapAdminManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 1024],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->bootstrapAdminManager->ensureBootstrapAdmin();
    }
}
