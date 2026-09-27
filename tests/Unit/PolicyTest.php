<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qoliber\Trident\Security\TokenVault;
use Qoliber\TridentSymfony\Config\SettingsProvider;
use Qoliber\TridentSymfony\EventSubscriber\CacheabilityVoter;
use Qoliber\TridentSymfony\EventSubscriber\RequestHardeningSubscriber;
use Qoliber\TridentSymfony\EventSubscriber\ResponsePolicySubscriber;
use Qoliber\TridentSymfony\Tags\ResponseTags;
use Qoliber\Trident\Tags\TagPolicy;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class PolicyTest extends TestCase
{
    private function run_(Request $request, Response $response, array $voters = [], array $tags = []): Response
    {
        $store = new MemoryStore();
        $provider = new SettingsProvider($store, 'k', TokenVault::DEFAULT_INFO, static fn (string $n): ?string => $n === 'TRIDENT_DEBUG_HEADERS' ? '1' : null);
        $rt = new ResponseTags();
        $rt->add(...$tags);
        $s = new ResponsePolicySubscriber($provider, new TagPolicy('', ['/^p_\d+$/' => 'p_overflow'], ['p_']), $rt, ['shop_page'], ['login_marker'], $voters);
        $request->attributes->set('_route', $request->attributes->get('_route', 'shop_page'));
        $s->onResponse(new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response));

        return $response;
    }

    public function testAnAnonymousPageIsSharedWithBoundedTags(): void
    {
        $r = $this->run_(Request::create('/p'), new Response('ok'), [], ['p_1', 'p_2']);
        self::assertStringContainsString('public', (string) $r->headers->get('Cache-Control'));
        self::assertStringContainsString('s-maxage=3600', (string) $r->headers->get('Cache-Control'));
        self::assertSame('all,p_1,p_2', $r->headers->get('X-Cache-Tags'));
        self::assertSame('cacheable', $r->headers->get('X-Trident-Decision'));
    }

    /**
     * @return array<string, array{callable(Request, Response): void, string}>
     */
    public static function refusals(): array
    {
        return [
            'set-cookie' => [static fn (Request $q, Response $r) => $r->headers->setCookie(Cookie::create('x', 'y')), 'set-cookie'],
            'status' => [static fn (Request $q, Response $r) => $r->setStatusCode(404), 'status 404'],
            'method' => [static fn (Request $q, Response $r) => $q->setMethod('POST'), 'method'],
            'bypass cookie' => [static fn (Request $q, Response $r) => $q->cookies->set('login_marker', '1'), 'cookie login_marker'],
        ];
    }

    /**
     * @dataProvider refusals
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testAPersonalRenderIsPrivate(callable $make, string $reason): void
    {
        $q = Request::create('/p');
        $r = new Response('ok');
        $make($q, $r);
        $this->run_($q, $r, [], ['p_1']);
        self::assertStringContainsString('private', (string) $r->headers->get('Cache-Control'));
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        self::assertFalse($r->headers->has('X-Cache-Tags'));
        self::assertSame('no-store; ' . $reason, $r->headers->get('X-Trident-Decision'));
    }

    public function testTheVisitorsSessionMakesItPersonalButANewEmptyOneDoesNot(): void
    {
        $q = Request::create('/p');
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $q->setSession($session);
        $this->run_($q, $r = new Response('ok'));
        self::assertSame('cacheable', $r->headers->get('X-Trident-Decision'), 'an empty, new session (flash bag read) is not personal');

        $q2 = Request::create('/p', 'GET', [], [$session->getName() => 'abc']);
        $s2 = new Session(new MockArraySessionStorage());
        $s2->start();
        $q2->setSession($s2);
        $this->run_($q2, $r2 = new Response('ok'));
        self::assertSame('no-store; session', $r2->headers->get('X-Trident-Decision'), 'the visitor\'s existing session');

        $q3 = Request::create('/p');
        $s3 = new Session(new MockArraySessionStorage());
        $s3->set('_cart', 22);
        $q3->setSession($s3);
        $this->run_($q3, $r3 = new Response('ok'));
        self::assertSame('no-store; session', $r3->headers->get('X-Trident-Decision'), 'a session that holds data');
    }

    public function testAVoterAndOtherRoutes(): void
    {
        $voter = new class implements CacheabilityVoter {
            public function refuse(Request $request, Response $response): ?string
            {
                return 'logged-in';
            }
        };
        $this->run_(Request::create('/p'), $r = new Response('ok'), [$voter]);
        self::assertSame('no-store; logged-in', $r->headers->get('X-Trident-Decision'));

        $q = Request::create('/cart');
        $q->attributes->set('_route', 'cart');
        $this->run_($q, $r2 = new Response('ok'));
        self::assertFalse($r2->headers->has('X-Trident-Decision'), 'routes that are not listed are left alone');
    }

    public function testAClientCannotSwitchOnEsi(): void
    {
        $q = Request::create('/p', 'GET', [], [], [], ['HTTP_SURROGATE_CAPABILITY' => 'x="ESI/1.0"']);
        (new RequestHardeningSubscriber())->onRequest(new RequestEvent($this->createMock(HttpKernelInterface::class), $q, HttpKernelInterface::MAIN_REQUEST));
        self::assertFalse($q->headers->has('Surrogate-Capability'));
    }

    public function testALateCookieUndoesTheSharing(): void
    {
        $store = new MemoryStore();
        $provider = new SettingsProvider($store, 'k', TokenVault::DEFAULT_INFO, static fn (string $n): ?string => $n === 'TRIDENT_DEBUG_HEADERS' ? '1' : null);
        $s = new ResponsePolicySubscriber($provider, new TagPolicy(), new ResponseTags(), ['shop_page'], [], []);
        $q = Request::create('/p');
        $q->attributes->set('_route', 'shop_page');
        $r = new Response('ok');
        $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $q, HttpKernelInterface::MAIN_REQUEST, $r);
        $s->onResponse($event);
        self::assertStringContainsString('public', (string) $r->headers->get('Cache-Control'));
        $r->headers->setCookie(Cookie::create('_currency_WEB', 'EUR'));
        $s->finalCheck($event);
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        self::assertFalse($r->headers->has('X-Cache-Tags'));
        self::assertSame('no-store; set-cookie (late)', $r->headers->get('X-Trident-Decision'));
    }
}
