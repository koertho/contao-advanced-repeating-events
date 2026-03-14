<?php

declare(strict_types=1);

namespace Koertho\AdvancedRepeatingEventsBundle\Contao;

use Contao\Calendar;
use Contao\CalendarBundle\Generator\CalendarEventsGenerator;
use Contao\CalendarEventsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\PageFinder;
use Contao\Module;
use Contao\System;
use FOS\HttpCache\ResponseTagger;
use Symfony\Contracts\Translation\TranslatorInterface;

class EventGeneratorDecorator extends CalendarEventsGenerator
{
    public function __construct(
        ContaoFramework $contaoFramework,
        PageFinder $pageFinder,
        ContentUrlGenerator $contentUrlGenerator,
        TranslatorInterface $translator,
        private readonly RecurringEventLoader $recurringEventLoader,
        ?ResponseTagger $responseTagger = null,
    ) {
        parent::__construct($contaoFramework, $pageFinder, $contentUrlGenerator, $translator, $responseTagger);
    }

    #[\Override]
    public function getAllEvents(array $calendars, \DateTimeInterface $rangeStart, \DateTimeInterface $rangeEnd, ?bool $featured = null, bool $noSpan = false, ?int $recurrenceLimit = null, ?Module $module = null): array
    {
        if ([] === $calendars) {
            return [];
        }

        $events = [];
        $rangeStart = \DateTimeImmutable::createFromInterface($rangeStart)->setTime(0, 0);
        $rangeEndTs = $rangeEnd->getTimestamp();
        $occurrences = $this->recurringEventLoader->loadOccurrences(
            $calendars,
            $rangeStart,
            $rangeEnd,
            $featured,
            $recurrenceLimit
        );

        foreach ($occurrences as $occurrence) {
            $this->addEvent(
                $events,
                $occurrence['model'],
                $occurrence['start'],
                $occurrence['end'],
                $rangeEndTs,
                $occurrence['calendar'],
                $noSpan,
                recursion: true
            );
        }

        foreach (array_keys($events) as $key) {
            ksort($events[$key]);
        }

        if ($module && isset($GLOBALS['TL_HOOKS']['getAllEvents']) && \is_array($GLOBALS['TL_HOOKS']['getAllEvents'])) {
            foreach ($GLOBALS['TL_HOOKS']['getAllEvents'] as $callback) {
                $events = System::importStatic($callback[0])->{$callback[1]}(
                    $events,
                    $calendars,
                    $rangeStart->getTimestamp(),
                    $rangeEndTs,
                    $module
                );
            }
        }

        return $events;
    }

    #[\Override]
    public function addEvent(array &$events, CalendarEventsModel $eventModel, int $start, int $end, int $rangeEnd, int $calendar, bool $noSpan, bool $recursion = false): void
    {
        if (!$eventModel->areRecurring) {
            parent::addEvent($events, $eventModel, $start, $end, $rangeEnd, $calendar, $noSpan);

            return;
        }

        $key = date('Ymd', $start);
        $eventData = $this->buildEventData($eventModel, $start, $end);
        $events[$key][$start][] = $eventData;

        if (!$noSpan) {
            $timestamp = $start;
            $span = Calendar::calculateSpan($start, $end);

            for ($i = 1; $i <= $span; ++$i) {
                $timestamp = strtotime('+1 day', $timestamp);

                if ($timestamp > $rangeEnd) {
                    break;
                }

                $events[date('Ymd', $timestamp)][$timestamp][] = $eventData;
            }
        }

        if ($recursion) {
            return;
        }
    }

    public function buildEventData(CalendarEventsModel $eventModel, int $start, int $end): array
    {
        $isRecurring = (bool) $eventModel->recurring;
        $eventModel->recurring = false;

        $tmpEvents = [];
        try {
            parent::addEvent(
                events: $tmpEvents,
                eventModel: $eventModel,
                start: $start,
                end: $end,
                rangeEnd: 0,
                calendar: $eventModel->pid,
                noSpan: true
            );
        } finally {
            $eventModel->recurring = $isRecurring;
        }

        $dateKey = array_key_first($tmpEvents);
        $dateData = $tmpEvents[$dateKey];
        $timeKey = array_key_first($dateData);
        $timeData = $dateData[$timeKey];
        $eventKey = array_key_first($timeData);

        return $timeData[$eventKey];
    }
}
