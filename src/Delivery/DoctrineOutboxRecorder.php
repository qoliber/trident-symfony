<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Delivery;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\PersistentCollection;
use Psr\Log\LoggerInterface;
use Qoliber\Trident\Delivery\Packer;
use Qoliber\Trident\Delivery\Purger;
use Qoliber\TridentSymfony\Delivery\Entity\OutboxRow;
use Qoliber\TridentSymfony\Tags\EntityTagResolver;

/**
 * Records the purge a Doctrine change owes, IN the change's transaction.
 *
 * Doctrine dispatches `onFlush` before it opens the flush transaction, so a
 * DBAL insert there would commit on its own. Instead the rows are added to the
 * unit of work as {@see OutboxRow} entities: Doctrine inserts them in the same
 * transaction as the change. A rolled-back save leaves no row; a committed one
 * can never lose its purge (no window between commit and record). `postFlush`
 * hands the new rows' ids to the request's {@see Delivery}, which delivers
 * them at the end of the request; until then they are due after the library's
 * grace period, so a concurrent drain does not race the request.
 */
final class DoctrineOutboxRecorder
{
    /** @var list<OutboxRow> */
    private array $pending = [];
    private ?bool $tableExists = null;

    /**
     * @param iterable<EntityTagResolver> $resolvers
     */
    public function __construct(
        private readonly iterable $resolvers,
        private readonly DeliveryFactory $deliveries,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $tags = [];
        $collect = function (object $entity, string $change) use (&$tags, $uow): void {
            if ($entity instanceof OutboxRow) {
                return;
            }
            $changeSet = $change === EntityTagResolver::UPDATE ? $uow->getEntityChangeSet($entity) : [];
            foreach ($this->resolvers as $resolver) {
                foreach ($resolver->tagsFor($entity, $change, $changeSet) as $tag) {
                    $tags[(string) $tag] = true;
                }
            }
        };
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $collect($entity, EntityTagResolver::INSERT);
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $collect($entity, EntityTagResolver::UPDATE);
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $collect($entity, EntityTagResolver::DELETE);
        }
        foreach ([...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()] as $collection) {
            if ($collection instanceof PersistentCollection && is_object($owner = $collection->getOwner())) {
                $collect($owner, EntityTagResolver::UPDATE);
            }
        }
        if ($tags === []) {
            return;
        }
        try {
            $delivery = $this->deliveries->delivery();
        } catch (\Throwable $e) {
            $this->logger?->error('Trident: settings unavailable, a change is not recorded for purging', ['error' => $e->getMessage()]);

            return;
        }
        $settings = $delivery->settings();
        // Settings unreadable (a transient database error): record for the
        // admin instance's name rather than lose the purge.
        $names = $settings->enabled() ? $settings->instanceNames() : ($settings->readFailed ? [\Qoliber\Trident\Delivery\Instances::DEFAULT_NAME] : []);
        if ($names === []) {
            return;
        }
        // Installed but not migrated: never make the shop's own save fail on
        // a missing table — log it (once per process) and record nothing.
        if (!($this->tableExists ??= $this->outboxTableExists($em))) {
            $this->logger?->critical('Trident: table trident_purge_outbox is missing (run doctrine:migrations:migrate); changes are not purged');

            return;
        }
        $final = $delivery->policy()->purgeTags(array_keys($tags));
        $now = $this->clock !== null ? (int) ($this->clock)() : time();
        $meta = $em->getClassMetadata(OutboxRow::class);
        foreach ($names as $name) {
            foreach (Packer::chunk($final) as $chunk) {
                $row = OutboxRow::create($name, $chunk, $now, $now + Purger::DEFAULT_GRACE);
                $em->persist($row);
                $uow->computeChangeSet($meta, $row);
                $this->pending[] = $row;
            }
        }
    }

    private function outboxTableExists(EntityManagerInterface $em): bool
    {
        try {
            return $em->getConnection()->createSchemaManager()->tablesExist(['trident_purge_outbox']);
        } catch (\Throwable) {
            return false;
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pending === []) {
            return;
        }
        $ids = [];
        $em = $args->getObjectManager();
        foreach ($this->pending as $row) {
            if (($id = $row->id()) !== null) {
                $ids[] = $id;
            }
            // Delivered and removed through DBAL; never flush them again.
            if ($em instanceof EntityManagerInterface && $em->contains($row)) {
                $em->detach($row);
            }
        }
        $this->pending = [];
        $this->deliveries->delivery()->addOwn($ids);
    }

    public function onClear(): void
    {
        $this->pending = [];
    }
}
