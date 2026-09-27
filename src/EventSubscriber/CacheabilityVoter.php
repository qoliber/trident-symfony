<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\EventSubscriber;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A platform's veto on sharing a page (e.g. Sylius: a logged-in customer).
 * Collected by the `trident.cacheability_voter` tag (autoconfigured).
 */
interface CacheabilityVoter
{
    /**
     * @return string|null why this response must not be shared; null when it may be
     */
    public function refuse(Request $request, Response $response): ?string;
}
