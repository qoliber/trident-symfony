<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Delivery\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One purge owed to one Trident instance (`trident_purge_outbox`).
 *
 * Mapped for Doctrine ORM so the recorder can add rows to the flush that made
 * the change ({@see \Qoliber\TridentSymfony\Delivery\DoctrineOutboxRecorder}):
 * the row is inserted in the SAME transaction — a rolled-back save leaves no
 * row, a committed one can never lose its purge. Everything else reads and
 * writes the table through DBAL ({@see \Qoliber\TridentSymfony\Delivery\DbalOutboxStore}).
 */
#[ORM\Entity]
#[ORM\Table(name: 'trident_purge_outbox')]
#[ORM\Index(name: 'idx_trident_outbox_due', columns: ['next_attempt_at', 'id'])]
#[ORM\Index(name: 'idx_trident_outbox_instance', columns: ['instance'])]
class OutboxRow
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $attempts = 0;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $scheduled = false;

    #[ORM\Column(name: 'last_error', type: Types::STRING, length: 1000, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'last_error_at', type: Types::BIGINT, nullable: true)]
    private ?string $lastErrorAt = null;

    /**
     * @param list<string> $tags
     */
    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $instance,
        #[ORM\Column(type: Types::TEXT)]
        private string $tags,
        #[ORM\Column(name: 'created_at', type: Types::BIGINT)]
        private string $createdAt,
        #[ORM\Column(name: 'next_attempt_at', type: Types::BIGINT)]
        private string $nextAttemptAt,
    ) {
    }

    /**
     * @param list<string> $tags
     */
    public static function create(string $instance, array $tags, int $now, int $dueAt): self
    {
        return new self($instance, (string) json_encode(array_values($tags), \JSON_THROW_ON_ERROR), (string) $now, (string) $dueAt);
    }

    public function id(): ?int
    {
        return $this->id !== null ? (int) $this->id : null;
    }
}
