<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Proxy;

use FOS\HttpCache\ProxyClient\Invalidation\ClearCapable;
use FOS\HttpCache\ProxyClient\Invalidation\TagCapable;
use FOS\HttpCache\ProxyClient\ProxyClient;
use Qoliber\TridentSymfony\Delivery\DeliveryFactory;

/**
 * FOSHttpCacheBundle's proxy client for Trident.
 *
 * An application that already invalidates through FOSHttpCache's
 * `CacheManager` (`invalidateTags()`, `clearCache()`) gets Trident's durable
 * delivery unchanged: tags go into the outbox (every configured instance,
 * retried until acknowledged) and are delivered on `flush()` — which the
 * bundle calls at the end of the request. Configure it with
 * `fos_http_cache.cache_manager.custom_proxy_client:
 * Qoliber\TridentSymfony\Proxy\TridentProxyClient` (the Trident bundle
 * prepends that when FOSHttpCacheBundle is enabled and has no proxy client).
 */
final class TridentProxyClient implements ProxyClient, TagCapable, ClearCapable
{
    public function __construct(private readonly DeliveryFactory $deliveries)
    {
    }

    public function invalidateTags(array $tags): static
    {
        $this->deliveries->delivery()->recordTags(array_map('strval', $tags));

        return $this;
    }

    public function clear(): static
    {
        $this->deliveries->delivery()->recordAll();

        return $this;
    }

    public function flush(): int
    {
        $report = $this->deliveries->delivery()->flush();

        return $report->delivered;
    }
}
