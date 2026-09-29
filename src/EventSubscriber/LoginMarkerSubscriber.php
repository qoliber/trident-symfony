<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The login marker (`trident.login_marker`, e.g. `trident_auth`): a cookie set
 * while a user is signed in and cleared once nobody is. Trident sees it BEFORE
 * it looks up a page (list it in Trident's `[cache.key] bypass_cookies`) and
 * passes the request to the application. Without it, a signed-in visitor
 * asking for a page that an anonymous render put in the cache gets that page:
 * "Sign in" where their name should be.
 *
 * Also a voter: a render for a signed-in user is never shared, even when the
 * authentication read no session (remember-me, a stateless token).
 */
final class LoginMarkerSubscriber implements EventSubscriberInterface, CacheabilityVoter
{
    public function __construct(
        private readonly TokenStorageInterface $tokens,
        private readonly string $cookie,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Before the response policy (-900): the Set-Cookie added here keeps
        // the render that changed the marker from being stored.
        return [KernelEvents::RESPONSE => ['onResponse', -512]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $marked = $event->getRequest()->cookies->has($this->cookie);
        $signedIn = $this->signedIn();
        if ($signedIn && !$marked) {
            $event->getResponse()->headers->setCookie(
                Cookie::create($this->cookie, '1', 0, '/', null, null, true, false, Cookie::SAMESITE_LAX),
            );
        } elseif (!$signedIn && $marked) {
            $event->getResponse()->headers->clearCookie($this->cookie, '/', null, false, true, Cookie::SAMESITE_LAX);
        }
    }

    public function refuse(Request $request, Response $response): ?string
    {
        return $this->signedIn() ? 'signed in' : null;
    }

    private function signedIn(): bool
    {
        return $this->tokens->getToken()?->getUser() !== null;
    }
}
