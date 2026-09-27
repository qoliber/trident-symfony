<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Messenger;

use Qoliber\TridentSymfony\Delivery\DeliveryFactory;

final class DrainOutboxHandler
{
    public function __construct(private readonly DeliveryFactory $deliveries)
    {
    }

    public function __invoke(DrainOutbox $message): void
    {
        $this->deliveries->delivery()->drain($message->limit);
    }
}
