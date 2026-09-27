<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Delivery;

use Qoliber\Trident\Delivery\OutboxDelivery as Delivery;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Qoliber\Trident\Http\TransportFactory;
use Qoliber\TridentSymfony\Config\SettingsProvider;
use Qoliber\Trident\Tags\TagPolicy;
use Symfony\Contracts\Service\ResetInterface;

/**
 * One {@see Delivery} per request / worker message: the rows it recorded are
 * the rows it flushes. `kernel.reset` drops it (after the terminate flush).
 */
class DeliveryFactory implements ResetInterface
{
    private ?Delivery $delivery = null;

    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly DbalOutboxStore $store,
        private readonly TransportFactory $transports,
        private readonly Connection $connection,
        private readonly TagPolicy $policy,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $redeliverAfter = Delivery::REDELIVER_AFTER,
    ) {
    }

    public function delivery(): Delivery
    {
        if ($this->delivery === null) {
            $settings = $this->settings->get();
            $this->delivery = new Delivery(
                $this->store,
                $settings,
                $this->policy->withPrefix($settings->tagPrefix),
                $this->transports->transport(),
                fn (callable $work): mixed => $this->connection->transactional(static fn (): mixed => $work()),
                null,
                $this->logger,
                $this->redeliverAfter,
            );
        }

        return $this->delivery;
    }

    public function current(): ?Delivery
    {
        return $this->delivery;
    }

    public function reset(): void
    {
        $this->delivery = null;
    }
}
