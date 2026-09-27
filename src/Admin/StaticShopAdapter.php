<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Admin;

use Qoliber\Trident\Admin\ShopAdapter;

/**
 * The default {@see ShopAdapter} for a plain Symfony application: the hosts
 * and catalogue URLs come from configuration (`trident.shop.hosts`,
 * `trident.shop.urls`); no entity purges. A platform bundle replaces it.
 */
final class StaticShopAdapter implements ShopAdapter
{
    /**
     * @param list<string> $hosts `scheme://host[:port]`
     * @param list<string> $urls  absolute catalogue URLs
     */
    public function __construct(private readonly array $hosts = [], private readonly array $urls = [])
    {
    }

    public function hosts(): array
    {
        $out = [];
        foreach ($this->hosts as $url) {
            $p = parse_url($url);
            if (isset($p['host'])) {
                $out[] = strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
            }
        }

        return array_values(array_unique($out));
    }

    public function scheme(string $host): string
    {
        foreach ($this->hosts as $url) {
            $p = parse_url($url);
            if (isset($p['host']) && strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') === $host) {
                return strtolower($p['scheme'] ?? 'https');
            }
        }

        return 'https';
    }

    public function catalogUrls(int $limit): array
    {
        return \array_slice($this->urls, 0, $limit);
    }

    public function entityKinds(): array
    {
        return [];
    }

    public function entityTags(string $kind, array $ids): array
    {
        throw new \InvalidArgumentException(sprintf('Unknown kind "%s"', $kind));
    }
}
