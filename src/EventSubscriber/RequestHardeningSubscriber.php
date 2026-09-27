<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A client must never be able to switch on ESI rendering: a
 * `Surrogate-Capability` header sent by a visitor would make the application
 * render `<esi:include>` markup that Trident then fills with DEFAULT-context
 * fragments and stores under that visitor's variant. It is removed from every
 * main request before anything reads it; a trusted surrogate adds it back
 * further down the stack if the platform supports ESI.
 */
final class RequestHardeningSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 2048]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getRequest()->headers->remove('Surrogate-Capability');
        }
    }
}
