<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Storage;

use Doctrine\DBAL\Connection;

/**
 * The admin screen's settings (`trident_settings`, name => value): the API
 * URL, the SEALED token and the purge mode. The token is never stored in
 * plain text — {@see \Qoliber\TridentSymfony\Config\SettingsProvider::save()}
 * seals it to its URL before it gets here.
 */
class DbalSettingsStore
{
    public const TABLE = 'trident_settings';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The stored settings; [] when the table does not exist (not migrated:
     * nothing configured). A failed read THROWS — "the database is away" is
     * not "nothing is configured".
     *
     * The table is looked up in the schema first: on PostgreSQL a failed
     * statement inside the shop's own transaction would poison it.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        if (!$this->connection->createSchemaManager()->tablesExist([self::TABLE])) {
            return [];
        }

        return array_map('strval', $this->connection->fetchAllKeyValue('SELECT name, value FROM ' . self::TABLE));
    }

    public function set(string $name, ?string $value): void
    {
        if ($value === null) {
            $this->connection->delete(self::TABLE, ['name' => $name]);

            return;
        }
        $this->connection->transactional(function (Connection $c) use ($name, $value): void {
            $c->delete(self::TABLE, ['name' => $name]);
            $c->insert(self::TABLE, ['name' => $name, 'value' => $value]);
        });
    }
}
