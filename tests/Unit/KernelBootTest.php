<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Tests\Unit;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use FOS\HttpCacheBundle\FOSHttpCacheBundle;
use PHPUnit\Framework\TestCase;
use Qoliber\Trident\Admin\AdminService;
use Qoliber\TridentSymfony\Delivery\DeliveryFactory;
use Qoliber\TridentSymfony\Delivery\Entity\OutboxRow;
use Qoliber\TridentSymfony\EventSubscriber\LoginMarkerSubscriber;
use Qoliber\TridentSymfony\TridentSymfonyBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * The bundle boots in a plain Symfony application — no Sylius, no
 * FOSHttpCacheBundle, no migrations bundle — and keeps out of the way of an
 * application's own FOSHttpCache proxy client.
 */
final class KernelBootTest extends TestCase
{
    private static int $n = 0;

    private mixed $exceptionHandler = null;

    protected function setUp(): void
    {
        $this->exceptionHandler = self::exceptionHandler();
    }

    protected function tearDown(): void
    {
        // Symfony 8's kernel installs its own exception handler when it boots;
        // a test leaves PHP's handler stack as it found it.
        for ($i = 0; $i < 10 && self::exceptionHandler() !== $this->exceptionHandler; ++$i) {
            restore_exception_handler();
        }
    }

    private static function exceptionHandler(): mixed
    {
        $handler = set_exception_handler(null);
        restore_exception_handler();

        return $handler;
    }

    /**
     * @param list<class-string> $extraBundles
     * @param callable(ContainerConfigurator): void $configure
     */
    private static function kernel(array $extraBundles, callable $configure): Kernel
    {
        $dir = sys_get_temp_dir() . '/trident-kernel-' . getmypid() . '-' . (++self::$n);

        return new class ('test', true, $extraBundles, $configure, $dir) extends Kernel {
            use MicroKernelTrait;

            /** @param list<class-string> $extra */
            public function __construct(string $env, bool $debug, private readonly array $extra, private readonly \Closure|array|string $cfg, private readonly string $dir)
            {
                parent::__construct($env, $debug);
            }

            public function registerBundles(): iterable
            {
                yield new FrameworkBundle();
                yield new DoctrineBundle();
                foreach ($this->extra as $b) {
                    yield new $b();
                }
                yield new TridentSymfonyBundle();
            }

            public function getCacheDir(): string
            {
                return $this->dir . '/cache';
            }

            public function getLogDir(): string
            {
                return $this->dir . '/log';
            }

            public function getProjectDir(): string
            {
                return $this->dir;
            }

            protected function configureContainer(ContainerConfigurator $c): void
            {
                $c->extension('framework', ['secret' => 'test', 'test' => true, 'http_method_override' => false, 'handle_all_throwables' => true, 'php_errors' => ['log' => true]]);
                ($this->cfg)($c);
            }
        };
    }

    public function testAPlainSymfonyAppBoots(): void
    {
        $k = self::kernel([], static function (ContainerConfigurator $c): void {
            $c->extension('doctrine', ['dbal' => ['url' => 'sqlite:///:memory:'], 'orm' => []]);
        });
        $k->boot();
        $c = $k->getContainer()->get('test.service_container');
        self::assertInstanceOf(DeliveryFactory::class, $c->get(DeliveryFactory::class));
        self::assertInstanceOf(AdminService::class, $c->get(AdminService::class));
        self::assertTrue($c->get('doctrine')->getManager()->getMetadataFactory()->hasMetadataFor(OutboxRow::class) || $c->get('doctrine')->getManager()->getClassMetadata(OutboxRow::class) !== null);
        $k->shutdown();
    }

    public function testTheMappingLandsInEntityManagersConfigs(): void
    {
        $k = self::kernel([], static function (ContainerConfigurator $c): void {
            $c->extension('doctrine', [
                'dbal' => ['url' => 'sqlite:///:memory:'],
                'orm' => ['default_entity_manager' => 'main', 'entity_managers' => ['main' => ['connection' => 'default']]],
            ]);
        });
        $k->boot();
        $em = $k->getContainer()->get('test.service_container')->get('doctrine')->getManager('main');
        self::assertSame('trident_purge_outbox', $em->getClassMetadata(OutboxRow::class)->getTableName());
        $k->shutdown();
    }

    public function testAnAppsOwnFosProxyClientIsKept(): void
    {
        $k = self::kernel([FOSHttpCacheBundle::class], static function (ContainerConfigurator $c): void {
            $c->extension('doctrine', ['dbal' => ['url' => 'sqlite:///:memory:'], 'orm' => []]);
            $c->extension('fos_http_cache', ['proxy_client' => ['varnish' => ['http' => ['servers' => ['127.0.0.1:6081']]]]]);
        });
        $k->boot();
        // Which client FOS's cache manager is built with, read from the compiled
        // container (instantiating Varnish's would need an HTTPlug client).
        $dumped = implode("\n", array_map('file_get_contents', glob($k->getCacheDir() . '/Container*/*.php') ?: []));
        self::assertStringContainsString('Varnish', $dumped);
        self::assertDoesNotMatchRegularExpression('/CacheManager\\(new \\\\?Qoliber/', $dumped);
        self::assertStringNotContainsString("'fos_http_cache.default_proxy_client' => 'Qoliber", $dumped);
        $k->shutdown();
    }

    public function testWithoutAClientTridentBecomesFossProxyClient(): void
    {
        $k = self::kernel([FOSHttpCacheBundle::class], static function (ContainerConfigurator $c): void {
            $c->extension('doctrine', ['dbal' => ['url' => 'sqlite:///:memory:'], 'orm' => []]);
        });
        $k->boot();
        $c = $k->getContainer()->get('test.service_container');
        self::assertInstanceOf(\Qoliber\TridentSymfony\Proxy\TridentProxyClient::class, $c->get('fos_http_cache.default_proxy_client'));
        $k->shutdown();
    }

    public function testTheLoginMarkerIsWiredWithSecurity(): void
    {
        $k = self::kernel([SecurityBundle::class], static function (ContainerConfigurator $c): void {
            $c->extension('doctrine', ['dbal' => ['url' => 'sqlite:///:memory:'], 'orm' => []]);
            $c->extension('security', [
                'providers' => ['users' => ['memory' => ['users' => ['editor' => ['password' => 'editor', 'roles' => ['ROLE_EDITOR']]]]]],
                'firewalls' => ['main' => ['lazy' => true, 'provider' => 'users']],
            ]);
            $c->extension('trident', ['login_marker' => 'trident_auth', 'bypass_cookies' => ['other']]);
        });
        $k->boot();
        $c = $k->getContainer()->get('test.service_container');
        self::assertInstanceOf(LoginMarkerSubscriber::class, $c->get(LoginMarkerSubscriber::class));
        // The marker is a bypass cookie of the response policy too.
        $dumped = implode("\n", array_map('file_get_contents', glob($k->getCacheDir() . '/Container*/*.php') ?: []));
        self::assertMatchesRegularExpression("/'other', 'trident_auth'/", $dumped);
        $k->shutdown();
    }

    public function testWithoutTheLoginMarkerNothingNeedsSecurity(): void
    {
        $k = self::kernel([], static function (ContainerConfigurator $c): void {
            $c->extension('doctrine', ['dbal' => ['url' => 'sqlite:///:memory:'], 'orm' => []]);
        });
        $k->boot();
        self::assertFalse($k->getContainer()->get('test.service_container')->has(LoginMarkerSubscriber::class));
        $k->shutdown();
    }
}
