<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

/**
 * The purge outbox and the admin settings, with Doctrine's Schema API
 * (portable: MySQL/MariaDB, PostgreSQL, SQLite).
 */
final class Version20260927000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trident: purge outbox (trident_purge_outbox) and admin settings (trident_settings)';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('trident_purge_outbox')) {
            $t = $schema->createTable('trident_purge_outbox');
            $t->addColumn('id', Types::BIGINT, ['autoincrement' => true]);
            // Case-sensitive: instance names are compared exactly (MySQL/MariaDB
            // compare case-insensitively by default; PostgreSQL and SQLite do not).
            $t->addColumn('instance', Types::STRING, ['length' => 64]
                + ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? ['platformOptions' => ['collation' => 'utf8mb4_bin']] : []));
            $t->addColumn('tags', Types::TEXT);
            $t->addColumn('attempts', Types::INTEGER, ['default' => 0]);
            $t->addColumn('created_at', Types::BIGINT);
            $t->addColumn('next_attempt_at', Types::BIGINT);
            // A second delivery (the editor race): scheduled, not owed, until due.
            $t->addColumn('scheduled', Types::BOOLEAN, ['default' => false]);
            $t->addColumn('last_error', Types::STRING, ['length' => 1000, 'notnull' => false]);
            $t->addColumn('last_error_at', Types::BIGINT, ['notnull' => false]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['next_attempt_at', 'id'], 'idx_trident_outbox_due');
            $t->addIndex(['instance'], 'idx_trident_outbox_instance');
        }
        if (!$schema->hasTable('trident_settings')) {
            $t = $schema->createTable('trident_settings');
            $t->addColumn('name', Types::STRING, ['length' => 64]);
            $t->addColumn('value', Types::TEXT);
            $t->setPrimaryKey(['name']);
        }
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('trident_purge_outbox');
        $schema->dropTable('trident_settings');
    }

    public function isTransactional(): bool
    {
        return false;
    }
}
