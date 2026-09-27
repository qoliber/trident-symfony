<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Delivery\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * `trident_settings` (name => value). Mapped only so Doctrine's schema tools
 * know the table; it is read and written through
 * {@see \Qoliber\TridentSymfony\Storage\DbalSettingsStore}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'trident_settings')]
class SettingRow
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 64)]
        private string $name,
        #[ORM\Column(type: Types::TEXT)]
        private string $value,
    ) {
    }
}
