<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Messenger;

use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * `messenger:consume scheduler_trident` drains the outbox every minute
 * (registered only when symfony/scheduler is installed).
 */
final class DrainSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(RecurringMessage::every('1 minute', new DrainOutbox()));
    }
}
