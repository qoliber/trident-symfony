<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Qoliber\Trident\Security\TokenVault;
use Qoliber\TridentSymfony\Config\SettingsProvider;
use Qoliber\TridentSymfony\Storage\DbalSettingsStore;

final class SettingsTest extends TestCase
{
    public function testAStoredTokenIsSealedToItsUrlAndStopsAfterAUrlChange(): void
    {
        $store = new MemoryStore();
        $env = static fn (string $n): ?string => null;
        $p = new SettingsProvider($store, 'kernel-secret', TokenVault::DEFAULT_INFO, $env);

        $p->save('http://trident:9301', 's3cret', 'soft');
        self::assertTrue(TokenVault::isSealed($store->data['api_token']));
        self::assertStringNotContainsString('s3cret', $store->data['api_token']);
        self::assertSame('s3cret', $p->get()->instances[0]->apiToken);
        self::assertFalse(isset($p->adminView()['api_token']), 'the view never carries the token');

        $p->save('http://attacker:9301', null, null);
        self::assertSame('', $p->get()->instances[0]->apiToken, 'the token does not travel to the new URL');
        self::assertSame(SettingsProvider::TOKEN_ERROR, $p->adminView()['token_error']);

        $p->save(null, 'new-token', null);
        self::assertSame('new-token', $p->get()->instances[0]->apiToken);
    }
}

/** An in-memory DbalSettingsStore. */
final class MemoryStore extends DbalSettingsStore
{
    /** @var array<string, string> */
    public array $data = [];

    public function __construct()
    {
    }

    public function all(): array
    {
        return $this->data;
    }

    public function set(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($this->data[$name]);
        } else {
            $this->data[$name] = $value;
        }
    }
}
