<?php

declare(strict_types=1);

namespace Koertho\AdvancedRepeatingEventsBundle\Recurrence;

use Contao\CalendarEventsModel;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

final readonly class RecurringOccurrenceCache
{
    private const int TTL = 2592000; // 30 days

    public function __construct(
        private TagAwareCacheInterface $cache,
    ) {
    }

    /**
     * @param callable(): list<array{start: int, end: int}> $generator
     * @return list<array{start: int, end: int}>
     */
    public function get(
        CalendarEventsModel $eventModel,
        \DateTimeInterface $rangeStart,
        \DateTimeInterface $rangeEnd,
        ?int $recurrenceLimit,
        callable $generator,
    ): array {
        return $this->cache->get(
            $this->buildKey($eventModel, $rangeStart, $rangeEnd, $recurrenceLimit),
            function (ItemInterface $item) use ($eventModel, $generator): array {
                $item->expiresAfter(self::TTL);
                $item->tag($this->buildTags($eventModel));

                return $generator();
            }
        );
    }

    /**
     * @return list<string>
     */
    public function buildTags(CalendarEventsModel $eventModel): array
    {
        return ['contao.db.tl_calendar_events.'.(int) $eventModel->id];
    }

    private function buildKey(
        CalendarEventsModel $eventModel,
        \DateTimeInterface $rangeStart,
        \DateTimeInterface $rangeEnd,
        ?int $recurrenceLimit,
    ): string {
        $payload = [
            'id' => (int) $eventModel->id,
            'rrule' => (string) $eventModel->rrule,
            'startTime' => (int) $eventModel->startTime,
            'endTime' => (int) $eventModel->endTime,
            'repeatEnd' => (int) $eventModel->repeatEnd,
            'rangeStart' => $rangeStart->getTimestamp(),
            'rangeEnd' => $rangeEnd->getTimestamp(),
            'recurrenceLimit' => $recurrenceLimit,
        ];

        return 'are_occurrences_'.hash('sha256', json_encode($payload, \JSON_THROW_ON_ERROR));
    }
}
