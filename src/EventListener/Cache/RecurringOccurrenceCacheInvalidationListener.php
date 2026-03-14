<?php

declare(strict_types=1);

namespace Koertho\AdvancedRepeatingEventsBundle\EventListener\Cache;

use Contao\CoreBundle\Event\InvalidateCacheTagsEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

#[AsEventListener]
final readonly class RecurringOccurrenceCacheInvalidationListener
{
    public function __construct(
        private TagAwareCacheInterface $cache,
    ) {
    }

    public function __invoke(InvalidateCacheTagsEvent $event): void
    {
        $tags = array_values(array_filter(
            $event->getTags(),
            static fn (string $tag): bool => str_starts_with($tag, 'contao.db.tl_calendar_events.')
        ));

        if ([] === $tags) {
            return;
        }

        $this->cache->invalidateTags($tags);
    }
}
