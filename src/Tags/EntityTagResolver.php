<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Tags;

/**
 * Which cache tags a Doctrine change purges. Platform bundles implement it
 * (Sylius: a product purges its page, its taxons' listings and the home page)
 * and are collected by the `trident.entity_tag_resolver` tag
 * (autoconfigured).
 */
interface EntityTagResolver
{
    public const INSERT = 'insert';
    public const UPDATE = 'update';
    public const DELETE = 'delete';

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet For an update, Doctrine's change
     *        set (field => [old, new]); lets a resolver ignore changes no page shows.
     * @return iterable<string> unprefixed tags; empty when the entity is not this resolver's
     */
    public function tagsFor(object $entity, string $change, array $changeSet = []): iterable;
}
