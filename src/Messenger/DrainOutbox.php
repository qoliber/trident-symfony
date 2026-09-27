<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Messenger;

/**
 * Deliver due purges from the outbox. Dispatched every minute by the bundle's
 * Symfony Scheduler provider (when symfony/scheduler is installed), or by
 * anyone who wants to (`trident:purge:drain` does the same from cron).
 */
final class DrainOutbox
{
    public function __construct(public readonly int $limit = 1000)
    {
    }
}
