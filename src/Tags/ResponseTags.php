<?php

declare(strict_types=1);

namespace Qoliber\TridentSymfony\Tags;

use Symfony\Contracts\Service\ResetInterface;

/**
 * The tags the current response is tagged with (collected while it renders;
 * written to the header, bounded, by the response policy). Reset per request.
 */
final class ResponseTags implements ResetInterface
{
    /** @var array<string, true> */
    private array $tags = [];

    public function add(string ...$tags): void
    {
        foreach ($tags as $tag) {
            if ($tag !== '') {
                $this->tags[$tag] = true;
            }
        }
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_map('strval', array_keys($this->tags));
    }

    public function reset(): void
    {
        $this->tags = [];
    }
}
