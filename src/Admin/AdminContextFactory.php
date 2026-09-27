<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Admin;

use Qoliber\Trident\Admin\AdminContext;
use Qoliber\Trident\Admin\PurgeOutbox;
use Qoliber\TridentSymfony\Config\SettingsProvider;
use Qoliber\TridentSymfony\Delivery\DeliveryFactory;

/**
 * This request's configuration for the shared admin screens
 * (qoliber/trident-php's AdminService).
 */
final class AdminContextFactory
{
    public function __construct(private readonly SettingsProvider $settings, private readonly DeliveryFactory $deliveries)
    {
    }

    public function __invoke(): AdminContext
    {
        $settings = $this->settings->get();
        $deliveries = $this->deliveries;

        return new class ($settings, $deliveries) implements AdminContext {
            public function __construct(private readonly \Qoliber\Trident\Config\Settings $s, private readonly DeliveryFactory $d)
            {
            }

            public function instances(): array
            {
                return $this->s->instances;
            }

            public function mode(): string
            {
                return $this->s->mode;
            }

            public function view(): array
            {
                return [
                    'source' => $this->s->source,
                    'instances' => array_map(static fn ($i) => ['name' => $i->name, 'api_url' => $i->apiUrl, 'has_token' => $i->apiToken !== ''], $this->s->instances),
                    'errors' => $this->s->errors,
                    'mode' => $this->s->mode,
                    'tag_prefix' => $this->s->tagPrefix,
                ];
            }

            public function outbox(): PurgeOutbox
            {
                return $this->d->delivery();
            }
        };
    }
}
