<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\EventSubscriber;

use Qoliber\TridentSymfony\Config\SettingsProvider;
use Qoliber\TridentSymfony\Tags\ResponseTags;
use Qoliber\Trident\Tags\TagPolicy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Decides, AFTER the page has rendered, whether Trident may share it.
 *
 * Shared (`public, max-age=0, s-maxage=…, stale-while-revalidate=…` plus the
 * bounded `X-Cache-Tags`) only when every rule holds:
 * - a main GET/HEAD request for a route listed as cacheable;
 * - status 200;
 * - no `Set-Cookie` on the response;
 * - the render did not read the visitor's session (the session was not
 *   started, or it is a new, empty one);
 * - the request carries none of the bypass cookies (e.g. a login marker);
 * - no platform voter refuses.
 * Otherwise a page of a cacheable route goes out `private, no-store` (the
 * visitor still receives cached pages of other visitors' anonymous renders;
 * only this render is not stored). Other routes are left alone.
 *
 * Tags come from FOSHttpCache's `X-Cache-Tags` (if the app tags with it) and
 * from {@see ResponseTags}; both are bounded by {@see TagPolicy}.
 */
final class ResponsePolicySubscriber implements EventSubscriberInterface
{
    public const HEADER = 'X-Cache-Tags';
    private const SHARED = '_trident_shared';

    /**
     * @param list<string>            $routes       cacheable route names
     * @param list<string>            $bypassCookies
     * @param iterable<CacheabilityVoter> $voters
     */
    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly TagPolicy $policy,
        private readonly ResponseTags $tags,
        private readonly array $routes,
        private readonly array $bypassCookies,
        private readonly iterable $voters,
        private readonly int $sMaxAge = 3600,
        private readonly int $staleWhileRevalidate = 86400,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the application, FOSHttpCache's tags (0) and the platform's
        // markers, but BEFORE Symfony's session listener (-1000): it saves and
        // closes the session, after which isStarted() no longer says that
        // this render read it.
        return [KernelEvents::RESPONSE => [['onResponse', -900], ['finalCheck', -4096]]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!\in_array((string) $request->attributes->get('_route'), $this->routes, true)) {
            return;
        }
        $reason = $this->refusal($request, $response);
        $settings = $this->settings->get();
        if ($reason === null) {
            $existing = (string) $response->headers->get(self::HEADER, '');
            $tags = array_merge($existing !== '' ? array_map('trim', explode(',', $existing)) : [], $this->tags->all());
            $response->headers->set(self::HEADER, $this->policy->withPrefix($settings->tagPrefix)->headerValue($tags));
            $response->headers->set('Cache-Control', sprintf('public, max-age=0, s-maxage=%d, stale-while-revalidate=%d', $this->sMaxAge, $this->staleWhileRevalidate));
            $response->headers->remove('Expires');
            // Verified above: no visitor's session was read. Symfony's session
            // listener (-1000) would still turn a render that merely opened an
            // empty session into `private`; this is its documented opt-out.
            $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
            $request->attributes->set(self::SHARED, true);
        } else {
            $response->headers->remove(self::HEADER);
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        if ($settings->debugHeaders) {
            $response->headers->set('X-Trident-Decision', $reason === null ? 'cacheable' : 'no-store; ' . $reason);
        }
    }

    /**
     * The render read a visitor's session: the session was started AND it is
     * the visitor's existing one (the request carried its cookie) or it holds
     * data now. A layout that merely opens an empty, brand-new session (e.g.
     * to read flash messages) reads nothing personal and sets no cookie.
     */
    private static function personalSession(Request $request): bool
    {
        $session = $request->getSession();
        if (!$session->isStarted()) {
            return false;
        }

        return $request->cookies->has($session->getName()) || $session->all() !== [];
    }

    /**
     * After every other response listener — Symfony's session listener
     * (-1000), Sylius's cookie storage (-1024), anything an app adds: a page
     * decided shared that has a cookie now is personal after all.
     */
    public function finalCheck(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$event->isMainRequest() || $request->attributes->get(self::SHARED) !== true) {
            return;
        }
        if ($response->headers->getCookies() === [] && !$response->headers->has('Set-Cookie')) {
            return;
        }
        $response->headers->remove(self::HEADER);
        $response->headers->set('Cache-Control', 'private, no-store');
        if ($this->settings->get()->debugHeaders) {
            $response->headers->set('X-Trident-Decision', 'no-store; set-cookie (late)');
        }
    }

    private function refusal(Request $request, Response $response): ?string
    {
        if (!$request->isMethodCacheable()) {
            return 'method';
        }
        if ($response->getStatusCode() !== 200) {
            return 'status ' . $response->getStatusCode();
        }
        foreach ($this->bypassCookies as $cookie) {
            if ($request->cookies->has($cookie)) {
                return 'cookie ' . $cookie;
            }
        }
        if ($response->headers->getCookies() !== [] || $response->headers->has('Set-Cookie')) {
            return 'set-cookie';
        }
        if ($request->hasSession(true) && self::personalSession($request)) {
            return 'session';
        }
        foreach ($this->voters as $voter) {
            if (($why = $voter->refuse($request, $response)) !== null) {
                return $why;
            }
        }

        return null;
    }
}
