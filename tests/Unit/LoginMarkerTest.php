<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qoliber\TridentSymfony\EventSubscriber\LoginMarkerSubscriber;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * The login marker follows the signed-in state both ways, and a signed-in
 * render is refused for sharing even when no session was read.
 */
final class LoginMarkerTest extends TestCase
{
    private static function subscriber(bool $signedIn): LoginMarkerSubscriber
    {
        $tokens = new TokenStorage();
        if ($signedIn) {
            $tokens->setToken(new UsernamePasswordToken(new InMemoryUser('editor', null, ['ROLE_EDITOR']), 'main', ['ROLE_EDITOR']));
        }

        return new LoginMarkerSubscriber($tokens, 'trident_auth');
    }

    /**
     * @param array<string, string> $cookies
     */
    private static function respond(LoginMarkerSubscriber $subscriber, array $cookies, int $type = HttpKernelInterface::MAIN_REQUEST): Response
    {
        $request = Request::create('/article/cache-tags-explained', 'GET', [], $cookies);
        $response = new Response('page');
        $subscriber->onResponse(new ResponseEvent(new class () implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        }, $request, $type, $response));

        return $response;
    }

    private static function marker(Response $response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'trident_auth') {
                return $cookie;
            }
        }

        return null;
    }

    public function testSigningInSetsTheMarker(): void
    {
        $cookie = self::marker(self::respond(self::subscriber(true), []));
        self::assertNotNull($cookie);
        self::assertSame('1', $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame(0, $cookie->getExpiresTime(), 'a session cookie: it must not outlive the login');
    }

    public function testAnExistingMarkerIsNotSetAgain(): void
    {
        self::assertNull(self::marker(self::respond(self::subscriber(true), ['trident_auth' => '1'])));
    }

    public function testSigningOutClearsTheMarker(): void
    {
        $cookie = self::marker(self::respond(self::subscriber(false), ['trident_auth' => '1']));
        self::assertNotNull($cookie);
        self::assertTrue($cookie->isCleared());
    }

    public function testAnAnonymousVisitorGetsNoCookie(): void
    {
        self::assertSame([], self::respond(self::subscriber(false), [])->headers->getCookies());
    }

    public function testSubRequestsAreLeftAlone(): void
    {
        self::assertSame([], self::respond(self::subscriber(true), [], HttpKernelInterface::SUB_REQUEST)->headers->getCookies());
    }

    public function testASignedInRenderIsNeverShared(): void
    {
        $request = Request::create('/');
        self::assertSame('signed in', self::subscriber(true)->refuse($request, new Response()));
        self::assertNull(self::subscriber(false)->refuse($request, new Response()));
    }
}
