<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Delivery;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Qoliber\Trident\Delivery\Backoff;
use Qoliber\Trident\Delivery\OutboxEntry;
use Qoliber\Trident\Delivery\ScheduledStore;

/**
 * The purge outbox on Doctrine DBAL: the library's OutboxStore contract on
 * `trident_purge_outbox` (created by this bundle's migration; the ORM mapping
 * is {@see Entity\OutboxRow}). A row is removed only when its instance
 * acknowledged the purge; a failure keeps it with a backoff.
 */
class DbalOutboxStore implements ScheduledStore
{
    public const TABLE = 'trident_purge_outbox';
    /** Ids per query: a long outbox never builds one unbounded IN (...). */
    public const CHUNK = 1000;
    /** Scheduled (a second delivery), never tried, not yet due: not owed. */
    private const SCHEDULED = 'scheduled = :yes AND attempts = 0 AND next_attempt_at > :now';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(string $instance, array $tags, int $now, int $dueAt): int
    {
        $this->connection->insert(self::TABLE, [
            'instance' => $instance,
            'tags' => (string) json_encode(array_values($tags), \JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'created_at' => $now,
            'next_attempt_at' => $dueAt,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function recordScheduled(string $instance, array $tags, int $now, int $dueAt): int
    {
        $json = (string) json_encode(array_values($tags), \JSON_THROW_ON_ERROR);
        $exists = $this->connection->fetchOne(
            'SELECT id FROM ' . self::TABLE . ' WHERE instance = :instance AND ' . self::SCHEDULED . ' AND tags = :tags',
            ['instance' => $instance, 'now' => $now, 'tags' => $json, 'yes' => true],
            ['yes' => \Doctrine\DBAL\ParameterType::BOOLEAN],
        );
        if ($exists !== false) {
            return 0;
        }
        $this->connection->insert(self::TABLE, [
            'instance' => $instance,
            'tags' => $json,
            'attempts' => 0,
            'created_at' => $now,
            'next_attempt_at' => $dueAt,
            'scheduled' => true,
        ], ['scheduled' => \Doctrine\DBAL\ParameterType::BOOLEAN]);

        return 1;
    }

    public function byIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            array_push($out, ...$this->entries($this->connection->fetchAllAssociative(
                'SELECT id, instance, tags, attempts FROM ' . self::TABLE . ' WHERE id IN (:ids) ORDER BY id ASC',
                ['ids' => $chunk],
                ['ids' => ArrayParameterType::INTEGER],
            )));
        }

        return $out;
    }

    public function due(int $limit, int $now, bool $ignoreBackoff, array $instances): array
    {
        if ($instances === []) {
            return [];
        }
        $where = $ignoreBackoff ? '(next_attempt_at <= :now OR attempts > 0)' : 'next_attempt_at <= :now';

        return $this->entries($this->connection->fetchAllAssociative(
            'SELECT id, instance, tags, attempts FROM ' . self::TABLE
            . ' WHERE instance IN (:instances) AND ' . $where
            . ' ORDER BY id ASC LIMIT ' . max(1, $limit),
            ['instances' => array_values($instances), 'now' => $now],
            ['instances' => ArrayParameterType::STRING],
        ));
    }

    public function remove(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $this->connection->executeStatement(
                'DELETE FROM ' . self::TABLE . ' WHERE id IN (:ids)',
                ['ids' => $chunk],
                ['ids' => ArrayParameterType::INTEGER],
            );
        }
    }

    public function fail(array $entries, string $reason, int $now): void
    {
        // One UPDATE per attempt count: each entry keeps its own schedule.
        $byAttempts = [];
        foreach ($entries as $entry) {
            $byAttempts[$entry->attempts][] = $entry->id;
        }
        foreach ($byAttempts as $attempts => $ids) {
            $failures = $attempts + 1;
            $this->connection->executeStatement(
                'UPDATE ' . self::TABLE . ' SET attempts = :attempts, next_attempt_at = :next, last_error = :error, last_error_at = :now WHERE id IN (:ids)',
                [
                    'attempts' => $failures,
                    'next' => Backoff::nextAttemptAt($failures, $now),
                    'error' => mb_substr($reason, 0, 1000),
                    'now' => $now,
                    'ids' => $ids,
                ],
                ['ids' => ArrayParameterType::INTEGER],
            );
        }
    }

    public function forget(string $instance): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE instance = :instance',
            ['instance' => $instance],
        );
    }

    public function stats(int $now): array
    {
        $p = ['now' => $now, 'yes' => true];
        $t = ['yes' => \Doctrine\DBAL\ParameterType::BOOLEAN];
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS pending, MIN(created_at) AS oldest FROM ' . self::TABLE . ' WHERE NOT (' . self::SCHEDULED . ')', $p, $t
        ) ?: [];
        $scheduled = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . self::TABLE . ' WHERE ' . self::SCHEDULED, $p, $t);
        $failure = $this->connection->fetchAssociative(
            'SELECT last_error, last_error_at FROM ' . self::TABLE . ' WHERE last_error IS NOT NULL ORDER BY last_error_at DESC, id DESC'
        ) ?: [];
        $byInstance = [];
        foreach ($this->connection->fetchAllAssociative('SELECT instance, COUNT(*) AS n FROM ' . self::TABLE . ' WHERE NOT (' . self::SCHEDULED . ') GROUP BY instance', $p, $t) as $group) {
            $byInstance[(string) $group['instance']] = (int) $group['n'];
        }
        ksort($byInstance);

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'scheduled' => $scheduled,
            'oldest_age' => isset($row['oldest']) ? max(0, $now - (int) $row['oldest']) : null,
            'last_error' => isset($failure['last_error']) ? (string) $failure['last_error'] : null,
            'last_error_at' => isset($failure['last_error_at']) ? (int) $failure['last_error_at'] : null,
            'by_instance' => $byInstance,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<OutboxEntry>
     */
    private function entries(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $tags = json_decode((string) $row['tags'], true);
            $entries[] = new OutboxEntry(
                (int) $row['id'],
                (string) $row['instance'],
                is_array($tags) ? array_values(array_map('strval', $tags)) : [],
                (int) $row['attempts'],
            );
        }

        return $entries;
    }
}
