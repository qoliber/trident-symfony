<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Qoliber\Trident\Security\TokenVault;
use Qoliber\TridentSymfony\Config\SettingsProvider;
use Qoliber\TridentSymfony\Delivery\DbalOutboxStore;
use Qoliber\TridentSymfony\Migrations\Version20260927000000;
use Qoliber\TridentSymfony\Storage\DbalSettingsStore;

/**
 * The stores on a real database (SQLite in memory), with the schema created by
 * the bundle's migration — the migration is portable (Schema API), so it runs
 * on SQLite as on MySQL and PostgreSQL.
 */
final class StoresTest extends TestCase
{
    /**
     * SQLite in memory, or the database in TRIDENT_TEST_DSN (bin/pg-smoke.sh runs
     * this suite against PostgreSQL).
     */
    private static function connection(bool $migrated = true): Connection
    {
        $dsn = getenv('TRIDENT_TEST_DSN');
        if (is_string($dsn) && $dsn !== '') {
            $c = DriverManager::getConnection((new \Doctrine\DBAL\Tools\DsnParser(['pgsql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql', 'mysql' => 'pdo_mysql']))->parse($dsn));
            foreach (['trident_purge_outbox', 'trident_settings'] as $t) {
                $c->executeStatement('DROP TABLE IF EXISTS ' . $t);
            }
        } else {
            $c = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        }
        if ($migrated) {
            $schema = new Schema();
            (new Version20260927000000($c, new NullLogger()))->up($schema);
            foreach ($schema->toSql($c->getDatabasePlatform()) as $sql) {
                $c->executeStatement($sql);
            }
        }

        return $c;
    }

    /**
     * status is for monitoring: a purge owed for a few seconds is normal (exit 0);
     * one older than --stale-after (default 900 s) is not (exit 1).
     */
    public function testStatusFailsOnlyWhenAPurgeIsOlderThanStaleAfter(): void
    {
        $c = self::connection();
        $store = new DbalOutboxStore($c);
        $env = static fn (string $name): ?string => ['TRIDENT_INSTANCES' => '{"edge":{"api_url":"http://edge:9301","api_token":"t"}}', 'TRIDENT_ALLOWED_API_HOSTS' => 'edge'][$name] ?? null;
        $settings = new SettingsProvider(new DbalSettingsStore($c), 'k', TokenVault::DEFAULT_INFO, $env);
        $factory = new \Qoliber\TridentSymfony\Delivery\DeliveryFactory($settings, $store, new \Qoliber\Trident\Http\TransportFactory(), $c, new \Qoliber\Trident\Tags\TagPolicy());
        $status = new \Symfony\Component\Console\Tester\CommandTester(new \Qoliber\TridentSymfony\Command\PurgeStatusCommand($factory));
        self::assertSame(0, $status->execute([]), 'nothing owed');
        $store->record('edge', ['p_1'], time() - 5, time() - 5);
        self::assertSame(0, $status->execute([]), 'owed for 5 s: within the default 900 s');
        self::assertSame(1, $status->execute(['--stale-after' => '2']), 'owed for 5 s: older than 2 s');
        self::assertStringContainsString('pending: 1', $status->getDisplay());
    }

    /** `trident:purge:drain --now`: the far-future cutoff binds on a real database. */
    public function testDrainAllDeliversScheduledRowsAtOnce(): void
    {
        $store = new DbalOutboxStore(self::connection());
        $store->recordScheduled('edge', ['p_1'], 100, 110);
        $store->record('edge', ['p_2'], 100, 460);
        $http = (new \Qoliber\Trident\Testing\FakeTransport())->answer('edge', 200, '{"purged":1,"mode":"soft"}');
        $drainer = new \Qoliber\Trident\Delivery\Drainer($store, [new \Qoliber\Trident\Delivery\Instance('edge', 'http://edge:9301', 't')], static fn ($i) => new \Qoliber\Trident\Delivery\PurgeClient($i, $http));
        self::assertSame(0, $drainer->drain(10, 100)->delivered);
        self::assertSame(2, $drainer->drainAll(10, 100)->delivered);
        self::assertSame([], $store->byIds([1, 2]));
    }

    public function testRecordThenDrainDeliversAndRemoves(): void
    {
        $store = new DbalOutboxStore(self::connection());
        $store->record('edge', ['p_1', 'p_2'], 100, 100);
        $http = (new \Qoliber\Trident\Testing\FakeTransport())->answer('edge', 200, '{"purged":2,"mode":"soft"}');
        $instance = new \Qoliber\Trident\Delivery\Instance('edge', 'http://edge:9301', 't');
        $report = (new \Qoliber\Trident\Delivery\Drainer($store, [$instance], static fn ($i) => new \Qoliber\Trident\Delivery\PurgeClient($i, $http)))->drain(10, 100);
        self::assertSame(1, $report->delivered);
        self::assertSame(0, $store->stats(100)['pending']);
    }

    public function testAMissingTableNeverPoisonsTheShopsTransaction(): void
    {
        $c = self::connection(false);
        $c->beginTransaction();
        self::assertSame([], (new DbalSettingsStore($c))->all());
        self::assertSame(1, (int) $c->fetchOne('SELECT 1'), 'the outer transaction is still usable');
        $c->rollBack();
    }

    public function testByIdsAndRemoveAreChunked(): void
    {
        $store = new DbalOutboxStore(self::connection());
        $ids = [];
        for ($i = 0; $i < 2500; ++$i) {
            $ids[] = $store->record('edge', ['t' . $i], 100, 200);
        }
        self::assertCount(2500, $store->byIds($ids), 'more ids than one IN (...) holds');
        $store->remove($ids);
        self::assertSame([], $store->byIds($ids));
    }

    public function testAScheduledRowIsNotPendingUntilDueAndIsDeduplicated(): void
    {
        $store = new DbalOutboxStore(self::connection());
        $store->record('edge', ['a'], 100, 220);
        self::assertSame(1, $store->recordScheduled('edge', ['a'], 100, 110));
        self::assertSame(0, $store->recordScheduled('edge', ['a'], 101, 111), 'the same scheduled set once');
        $stats = $store->stats(105);
        self::assertSame(1, $stats['pending']);
        self::assertSame(1, $stats['scheduled']);
        self::assertSame(2, $store->stats(115)['pending'], 'owed once due');
        self::assertCount(1, $store->due(10, 112, false, ['edge']));
    }

    public function testNotMigratedIsNothingConfiguredButAFailedReadThrows(): void
    {
        self::assertSame([], (new DbalSettingsStore(self::connection(false)))->all());
        $c = self::connection();
        $c->close();
        $broken = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => '/nonexistent/dir/db.sqlite']);
        $this->expectException(\Throwable::class);
        (new DbalSettingsStore($broken))->all();
    }

    public function testAFailedSettingsReadIsLoggedAndMarked(): void
    {
        $broken = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => '/nonexistent/dir/db.sqlite']);
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $critical = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                if ($level === 'critical') {
                    $this->critical[] = (string) $message;
                }
            }
        };
        $p = new SettingsProvider(new DbalSettingsStore($broken), 'k', TokenVault::DEFAULT_INFO, static fn (): ?string => null, $logger);
        $s = $p->get();
        self::assertTrue($s->readFailed);
        self::assertSame([], $s->instances);
        self::assertContains(SettingsProvider::READ_FAILED, $s->errors);
        self::assertCount(1, $logger->critical);
    }
}
