<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony;

use Qoliber\Trident\Http\TransportFactory;
use Qoliber\Trident\Security\TokenVault;
use Qoliber\Trident\Admin\AdminService;
use Qoliber\TridentSymfony\Admin\AdminContextFactory;
use Qoliber\Trident\Admin\ShopAdapter;
use Qoliber\TridentSymfony\Admin\StaticShopAdapter;
use Qoliber\TridentSymfony\Command\PurgeCommand;
use Qoliber\TridentSymfony\Command\PurgeDrainCommand;
use Qoliber\TridentSymfony\Command\PurgeForgetCommand;
use Qoliber\TridentSymfony\Command\PurgeStatusCommand;
use Qoliber\TridentSymfony\Config\SettingsProvider;
use Qoliber\TridentSymfony\Delivery\DbalOutboxStore;
use Qoliber\TridentSymfony\Delivery\DeliveryFactory;
use Qoliber\TridentSymfony\Delivery\DoctrineOutboxRecorder;
use Qoliber\TridentSymfony\EventSubscriber\CacheabilityVoter;
use Qoliber\TridentSymfony\EventSubscriber\DeliverySubscriber;
use Qoliber\TridentSymfony\EventSubscriber\LoginMarkerSubscriber;
use Qoliber\TridentSymfony\EventSubscriber\RequestHardeningSubscriber;
use Qoliber\TridentSymfony\EventSubscriber\ResponsePolicySubscriber;
use Qoliber\TridentSymfony\Messenger\DrainOutboxHandler;
use Qoliber\TridentSymfony\Messenger\DrainSchedule;
use Qoliber\TridentSymfony\Proxy\TridentProxyClient;
use Qoliber\TridentSymfony\Storage\DbalSettingsStore;
use Qoliber\TridentSymfony\Tags\EntityTagResolver;
use Qoliber\TridentSymfony\Tags\ResponseTags;
use Qoliber\Trident\Tags\TagPolicy;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/**
 * Trident for Symfony: see README.md.
 */
final class TridentSymfonyBundle extends AbstractBundle
{
    protected string $extensionAlias = 'trident';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->enumNode('fos_proxy_client')->values(['auto', true, false])->defaultValue('auto')
                    ->info('Register Trident as FOSHttpCache\'s proxy client: auto = only when the app configured none')->end()
                ->scalarNode('token_info')->defaultValue(TokenVault::DEFAULT_INFO)
                    ->info('HKDF label of the sealed admin token; keep it once tokens are stored')->end()
                ->arrayNode('cacheable_routes')->scalarPrototype()->end()->defaultValue([])
                    ->info('Route names whose pages Trident may share (the response policy decides per render)')->end()
                ->arrayNode('bypass_cookies')->scalarPrototype()->end()->defaultValue([])
                    ->info('Request cookies that make a render personal (e.g. a login marker)')->end()
                ->scalarNode('login_marker')->defaultNull()
                    ->info('Cookie set while a user is signed in, for Trident\'s bypass_cookies (e.g. trident_auth); null = off. Needs symfony/security-bundle')->end()
                ->integerNode('s_maxage')->defaultValue(3600)->min(0)->end()
                ->integerNode('redeliver_after')->defaultValue(10)->min(0)
                    ->info('Seconds after which a delivered purge is delivered once more (the editor race); 0 = never')->end()
                ->integerNode('stale_while_revalidate')->defaultValue(86400)->min(0)->end()
                ->arrayNode('tags')->addDefaultsIfNotSet()->children()
                    ->arrayNode('identity_prefixes')->scalarPrototype()->end()->defaultValue([])->end()
                    ->arrayNode('overflow_families')->useAttributeAsKey('pattern')->scalarPrototype()->end()->defaultValue([])
                        ->info('regex on the unprefixed tag => its overflow tag')->end()
                ->end()->end()
                ->arrayNode('shop')->addDefaultsIfNotSet()->children()
                    ->arrayNode('hosts')->scalarPrototype()->end()->defaultValue([])->end()
                    ->arrayNode('urls')->scalarPrototype()->end()->defaultValue([])->end()
                ->end()->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $bundles = (array) $builder->getParameter('kernel.bundles');
        if (isset($bundles['DoctrineMigrationsBundle'])) {
            $builder->prependExtensionConfig('doctrine_migrations', [
                'migrations_paths' => ['Qoliber\\TridentSymfony\\Migrations' => __DIR__ . '/Migrations'],
            ]);
        }
        $this->prependMapping($builder);
        if (isset($bundles['FOSHttpCacheBundle']) && $this->fosProxyClientWanted($builder)) {
            $builder->prependExtensionConfig('fos_http_cache', [
                'cache_manager' => ['custom_proxy_client' => TridentProxyClient::class],
                'tags' => ['enabled' => true, 'response_header' => ResponsePolicySubscriber::HEADER],
            ]);
        }
    }

    /**
     * The outbox entity's mapping, for the default entity manager — in the
     * short `doctrine.orm.mappings` form or under `entity_managers:`.
     */
    private function prependMapping(ContainerBuilder $builder): void
    {
        $mapping = ['TridentSymfony' => [
            'type' => 'attribute',
            'is_bundle' => false,
            'dir' => __DIR__ . '/Delivery/Entity',
            'prefix' => 'Qoliber\\TridentSymfony\\Delivery\\Entity',
            'alias' => 'TridentSymfony',
        ]];
        $default = 'default';
        $managers = false;
        foreach ($builder->getExtensionConfig('doctrine') as $config) {
            $orm = (array) ($config['orm'] ?? []);
            $default = (string) ($orm['default_entity_manager'] ?? $default);
            $managers = $managers || isset($orm['entity_managers']);
        }
        $builder->prependExtensionConfig('doctrine', ['orm' => $managers
            ? ['entity_managers' => [$default => ['mappings' => $mapping]]]
            : ['mappings' => $mapping]]);
    }

    /**
     * Trident becomes FOSHttpCache's proxy client only when the application
     * configured none (`trident.fos_proxy_client: auto`, the default), or
     * when asked (`true`). An application's own Varnish/Symfony client is never
     * silently replaced.
     */
    private function fosProxyClientWanted(ContainerBuilder $builder): bool
    {
        $wanted = 'auto';
        foreach ($builder->getExtensionConfig('trident') as $config) {
            if (array_key_exists('fos_proxy_client', $config)) {
                $wanted = $config['fos_proxy_client'];
            }
        }
        if ($wanted === true || $wanted === 'true') {
            return true;
        }
        if ($wanted === false || $wanted === 'false') {
            return false;
        }
        foreach ($builder->getExtensionConfig('fos_http_cache') as $config) {
            if (!empty($config['proxy_client']) || !empty($config['cache_manager']['custom_proxy_client'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->registerForAutoconfiguration(EntityTagResolver::class)->addTag('trident.entity_tag_resolver');
        $builder->registerForAutoconfiguration(CacheabilityVoter::class)->addTag('trident.cacheability_voter');

        $s = $container->services()->defaults()->autowire(false)->autoconfigure(false);
        $s->set(DbalSettingsStore::class)->args([service('doctrine.dbal.default_connection')]);
        $s->set(SettingsProvider::class)->args([service(DbalSettingsStore::class), '%kernel.secret%', $config['token_info']])->tag('kernel.reset', ['method' => 'reset']);
        $s->set(TransportFactory::class);
        $s->set(TagPolicy::class)->args(['', $config['tags']['overflow_families'], $config['tags']['identity_prefixes']]);
        $s->set(DbalOutboxStore::class)->args([service('doctrine.dbal.default_connection')]);
        $s->set(DeliveryFactory::class)->args([
            service(SettingsProvider::class), service(DbalOutboxStore::class), service(TransportFactory::class),
            service('doctrine.dbal.default_connection'), service(TagPolicy::class), service('logger')->nullOnInvalid(),
            $config['redeliver_after'],
        ])->tag('kernel.reset', ['method' => 'reset']);
        $s->set(DoctrineOutboxRecorder::class)->args([
            tagged_iterator('trident.entity_tag_resolver'), service(DeliveryFactory::class), service('logger')->nullOnInvalid(),
        ])
            ->tag('doctrine.event_listener', ['event' => 'onFlush'])
            ->tag('doctrine.event_listener', ['event' => 'postFlush'])
            ->tag('doctrine.event_listener', ['event' => 'onClear'])
            ->tag('kernel.reset', ['method' => 'onClear']);
        $s->set(ResponseTags::class)->tag('kernel.reset', ['method' => 'reset']);
        $s->set(RequestHardeningSubscriber::class)->tag('kernel.event_subscriber');
        $bypassCookies = $config['bypass_cookies'];
        if ($config['login_marker'] !== null) {
            if (!interface_exists(TokenStorageInterface::class)) {
                throw new \LogicException('trident.login_marker needs symfony/security-bundle (composer require symfony/security-bundle).');
            }
            $s->set(LoginMarkerSubscriber::class)->args([service('security.token_storage'), $config['login_marker']])
                ->tag('kernel.event_subscriber')->tag('trident.cacheability_voter');
            $bypassCookies = array_values(array_unique([...$bypassCookies, $config['login_marker']]));
        }
        $s->set(ResponsePolicySubscriber::class)->args([
            service(SettingsProvider::class), service(TagPolicy::class), service(ResponseTags::class),
            $config['cacheable_routes'], $bypassCookies, tagged_iterator('trident.cacheability_voter'),
            $config['s_maxage'], $config['stale_while_revalidate'],
        ])->tag('kernel.event_subscriber');
        $s->set(DeliverySubscriber::class)->args([
            service(DeliveryFactory::class), service('cache.app')->nullOnInvalid(), service('logger')->nullOnInvalid(),
        ])->tag('kernel.event_subscriber');
        if (interface_exists(\FOS\HttpCache\ProxyClient\ProxyClient::class)) {
            $s->set(TridentProxyClient::class)->args([service(DeliveryFactory::class)])->public();
        }
        foreach ([PurgeStatusCommand::class, PurgeDrainCommand::class, PurgeForgetCommand::class, PurgeCommand::class] as $command) {
            $s->set($command)->args([service(DeliveryFactory::class)])->tag('console.command');
        }
        $s->set(StaticShopAdapter::class)->args([$config['shop']['hosts'], $config['shop']['urls']]);
        if (!$builder->hasAlias(ShopAdapter::class) && !$builder->hasDefinition(ShopAdapter::class)) {
            $s->alias(ShopAdapter::class, StaticShopAdapter::class);
        }
        $s->set(AdminContextFactory::class)->args([service(SettingsProvider::class), service(DeliveryFactory::class)]);
        $s->set(AdminService::class)->args([
            service(AdminContextFactory::class), service(ShopAdapter::class), service(TransportFactory::class), service('logger')->nullOnInvalid(),
        ])->public();
        if (interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            $s->set(DrainOutboxHandler::class)->args([service(DeliveryFactory::class)])->tag('messenger.message_handler');
        }
        if (interface_exists(ScheduleProviderInterface::class)) {
            $s->set(DrainSchedule::class)->tag('scheduler.schedule_provider', ['name' => 'trident']);
        }
    }
}
