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
use FOS\HttpCache\ResponseTagger;
use Koertho\AdvancedRepeatingEventsBundle\Recurrence\RecurrenceCalculatorFactory;
use Symfony\Contracts\Translation\TranslatorInterface;

class EventGeneratorDecorator extends CalendarEventsGenerator
{
    private array $getAllEventsCalled = [];

    public function __construct(
        ContaoFramework     $contaoFramework,
        PageFinder          $pageFinder,
        ContentUrlGenerator $contentUrlGenerator,
        TranslatorInterface $translator,
        private readonly RecurrenceCalculatorFactory $recurrenceCalculatorFactory,
        ResponseTagger|null $responseTagger = null,
    )
    {
        parent::__construct($contaoFramework, $pageFinder, $contentUrlGenerator, $translator, $responseTagger);
    }

    public function getAllEvents(array $calendars, \DateTimeInterface $rangeStart, \DateTimeInterface $rangeEnd, ?bool $featured = null, bool $noSpan = false, ?int $recurrenceLimit = null, ?Module $module = null): array
    {
        $cacheKey = $rangeEnd->getTimestamp() . '_' . (int)$noSpan;
        $this->getAllEventsCalled[$cacheKey] = [
            'rangeStart' => $rangeStart,
            'rangeEnd' => $rangeEnd,
            'noSpan' => $noSpan,
            'recurrenceLimit' => $recurrenceLimit,
        ];

        $events = parent::getAllEvents(...func_get_args());

        unset($this->getAllEventsCalled[$cacheKey]);

        return $events;
    }

    /**
     * @param \Koertho\AdvancedRepeatingEventsBundle\Model\CalendarEventsModel $eventModel
     */
    public function addEvent(array &$events, CalendarEventsModel $eventModel, int $start, int $end, int $rangeEnd, int $calendar, bool $noSpan, bool $recursion = false): void
    {
        if (!$eventModel->recurring && !$eventModel->areRecurring) {
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

        $repeatContext = $this->getAllEventsCalled[$rangeEnd . '_' . (int)$noSpan] ?? null;

        if (\is_array($repeatContext)) {
            $this->applyRecurrences($events, $eventModel, $repeatContext);
        }
    }

    public function buildEventData(CalendarEventsModel $eventModel, int $start, int $end): array
    {
        $eventModel->recurring = false;

        $tmpEvents = [];
        parent::addEvent(
            events: $tmpEvents,
            eventModel: $eventModel,
            start: $start,
            end: $end,
            rangeEnd: 0,
            calendar: $eventModel->pid,
            noSpan: true
        );

        $dateKey = array_key_first($tmpEvents);
        $dateData = $tmpEvents[$dateKey];
        $timeKey = array_key_first($dateData);
        $timeData = $dateData[$timeKey];
        $eventKey = array_key_first($timeData);

        return $timeData[$eventKey];
    }

    /**
     * @param \Koertho\AdvancedRepeatingEventsBundle\Model\CalendarEventsModel $eventModel
     */
    private function applyRecurrences(array &$events, CalendarEventsModel $eventModel, array $repeatContext): void
    {
        $rangeStart = $repeatContext['rangeStart'] ?? null;
        $rangeEnd = $repeatContext['rangeEnd'] ?? null;
        $noSpan = (bool)($repeatContext['noSpan'] ?? false);
        $recurrenceLimit = isset($repeatContext['recurrenceLimit']) ? (int)$repeatContext['recurrenceLimit'] : null;

        if (!$rangeStart instanceof \DateTimeInterface || !$rangeEnd instanceof \DateTimeInterface) {
            return;
        }

        $calculator = $this->recurrenceCalculatorFactory->createForEvent($eventModel);

        if (null === $calculator) {
            return;
        }

        $rangeStartTs = $rangeStart->getTimestamp();
        $rangeEndTs = $rangeEnd->getTimestamp();
        $occurrences = $calculator->listOccurrencesInRange($rangeStart, $rangeEnd, $recurrenceLimit, true);

        foreach ($occurrences as $occurrence) {
            $occurrenceStartTs = $occurrence['start'];
            $occurrenceEndTs = $occurrence['end'];

            if ($occurrenceEndTs < $rangeStartTs || $occurrenceStartTs > $rangeEndTs) {
                continue;
            }

            $this->addEvent(
                $events,
                $eventModel,
                $occurrenceStartTs,
                $occurrenceEndTs,
                $rangeEndTs,
                $eventModel->pid,
                $noSpan,
                recursion: true
            );
        }
    }
}
