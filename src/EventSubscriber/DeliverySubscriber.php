<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\EventSubscriber;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Qoliber\TridentSymfony\Delivery\DeliveryFactory;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Delivers the purges a request (or console command, or worker message)
 * recorded once it is over — after the response was sent, so a slow Trident
 * never slows the shop. Then, at most every {@see DRAIN_EVERY} seconds per
 * application, it drains a few due rows, so a failed purge is retried even
 * without a cron job. Never throws.
 */
final class DeliverySubscriber implements EventSubscriberInterface
{
    public const DRAIN_EVERY = 30;
    public const DRAIN_LIMIT = 50;

    public function __construct(
        private readonly DeliveryFactory $deliveries,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        $events = [
            KernelEvents::TERMINATE => ['deliver', -1024],
            ConsoleEvents::TERMINATE => ['deliver', -1024],
        ];
        if (class_exists(WorkerMessageHandledEvent::class)) {
            $events[WorkerMessageHandledEvent::class] = ['deliver', -1024];
            $events[WorkerMessageFailedEvent::class] = ['deliver', -1024];
        }

        return $events;
    }

    public function deliver(): void
    {
        $delivery = $this->deliveries->current();
        try {
            if ($delivery !== null && $delivery->hasOwn()) {
                $delivery->flush();
            }
            if ($this->drainDue()) {
                $this->deliveries->delivery()->drain(self::DRAIN_LIMIT);
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Trident: end-of-request delivery failed; the outbox keeps the purges', ['error' => $e->getMessage()]);
        } finally {
            $this->deliveries->reset();
        }
    }

    private function drainDue(): bool
    {
        if ($this->cache === null) {
            return false;
        }
        try {
            $item = $this->cache->getItem('trident_outbox_drain');
            if ($item->isHit()) {
                return false;
            }
            $item->set(time())->expiresAfter(self::DRAIN_EVERY);
            $this->cache->save($item);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
