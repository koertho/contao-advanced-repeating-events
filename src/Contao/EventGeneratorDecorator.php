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
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use Recurr\Transformer\Constraint\BetweenConstraint;
use Symfony\Contracts\Translation\TranslatorInterface;

class EventGeneratorDecorator extends CalendarEventsGenerator
{
    private array $getAllEventsCalled = [];

    public function __construct(
        ContaoFramework $contaoFramework,
        PageFinder $pageFinder,
        ContentUrlGenerator $contentUrlGenerator,
        TranslatorInterface $translator,
        ResponseTagger|null $responseTagger = null,
    ) {
        parent::__construct($contaoFramework, $pageFinder, $contentUrlGenerator, $translator, $responseTagger);
    }

    public function getAllEvents(array $calendars, \DateTimeInterface $rangeStart, \DateTimeInterface $rangeEnd, ?bool $featured = null, bool $noSpan = false, ?int $recurrenceLimit = null, ?Module $module = null): array
    {
        $cacheKey = $rangeEnd->getTimestamp().'_'.(int) $noSpan;
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
        if (!$eventModel->areRecurring || '' === trim((string) $eventModel->rrule)) {
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

        $repeatContext = $this->getAllEventsCalled[$rangeEnd.'_'.(int) $noSpan] ?? null;

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
        $normalizedRrule = $this->normalizeRrule((string) $eventModel->rrule);
        if (null === $normalizedRrule) {
            return;
        }

        $rangeStart = $repeatContext['rangeStart'] ?? null;
        $rangeEnd = $repeatContext['rangeEnd'] ?? null;
        $noSpan = (bool) ($repeatContext['noSpan'] ?? false);
        $recurrenceLimit = isset($repeatContext['recurrenceLimit']) ? (int) $repeatContext['recurrenceLimit'] : null;

        if (!$rangeStart instanceof \DateTimeInterface || !$rangeEnd instanceof \DateTimeInterface) {
            return;
        }

        $rangeStartTs = $rangeStart->getTimestamp();
        $rangeEndTs = $rangeEnd->getTimestamp();
        $timezone = new \DateTimeZone(date_default_timezone_get());
        $eventStart = (new \DateTimeImmutable('@'.(int) $eventModel->startTime))->setTimezone($timezone);
        $eventEnd = (new \DateTimeImmutable('@'.(int) $eventModel->endTime))->setTimezone($timezone);

        try {
            $rule = new Rule($normalizedRrule, $eventStart, $eventEnd, $timezone->getName());
        } catch (\Throwable) {
            return;
        }

        $config = new ArrayTransformerConfig();

        $transformer = new ArrayTransformer($config);
        $constraint = new BetweenConstraint(
            (new \DateTimeImmutable('@'.$rangeStartTs))->setTimezone($timezone),
            (new \DateTimeImmutable('@'.$rangeEndTs))->setTimezone($timezone),
            true
        );

        $generated = 0;
        $originalStartTs = (int) $eventModel->startTime;

        /**
         * @var \Recurr\Recurrence $recurrence
         */
        foreach ($transformer->transform($rule, $constraint) as $recurrence) {
            $occurrenceStart = $recurrence->getStart();
            $occurrenceEnd = $recurrence->getEnd();

            $occurrenceStartTs = $occurrenceStart->getTimestamp();
            $occurrenceEndTs = $occurrenceEnd->getTimestamp();

            if ($occurrenceStartTs <= $originalStartTs) {
                continue;
            }

            if (null !== $recurrenceLimit && $generated >= $recurrenceLimit) {
                return;
            }

            ++$generated;

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

    private function normalizeRrule(string $raw): ?string
    {
        $raw = trim($raw);

        if ('' === $raw) {
            return null;
        }

        $lines = preg_split('/\R+/', $raw) ?: [];
        $candidate = null;

        foreach ($lines as $line) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            if (str_starts_with(strtoupper($line), 'RRULE:')) {
                $candidate = trim(substr($line, 6));
                break;
            }

            if (null === $candidate) {
                $candidate = $line;
            }
        }

        if (!\is_string($candidate) || '' === $candidate) {
            return null;
        }

        if (!str_contains(strtoupper($candidate), 'FREQ=')) {
            return null;
        }

        return preg_replace('/\s+/', '', $candidate);
    }
}
