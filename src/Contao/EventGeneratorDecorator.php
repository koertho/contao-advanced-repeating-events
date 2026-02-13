<?php

namespace Koertho\AdvancedRepeatingEventsBundle\Contao;

use Contao\Calendar;
use Contao\CalendarBundle\Generator\CalendarEventsGenerator;
use Contao\CalendarEventsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\PageFinder;
use Contao\Date;
use Contao\Module;
use Contao\StringUtil;
use FOS\HttpCache\ResponseTagger;
use Symfony\Contracts\Translation\TranslatorInterface;

class EventGeneratorDecorator extends CalendarEventsGenerator
{
    private array $getAllEventsCalled;

    public function __construct(
        private readonly ContaoFramework     $contaoFramework,
        private readonly PageFinder          $pageFinder,
        private readonly ContentUrlGenerator $contentUrlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly ResponseTagger|null $responseTagger = null,
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
        $event = parent::getAllEvents(...func_get_args());
        unset($this->getAllEventsCalled[$cacheKey]);
        return $event;
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

        $this->addRecurringInformation($eventData, $eventModel, $start, $end);

        $events[$key][$start][] = $eventData;

        if (!$noSpan) {
            $timestamp = $start;
            $span = Calendar::calculateSpan($start, $end);
            // Multi-day event
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

        $repeatContext = $this->getAllEventsCalled[$rangeEnd . '_' . $noSpan] ?? null;
        if (is_array($repeatContext)) {
            $this->applyRecurrences($events, $eventModel, $repeatContext);
        }
    }

    public function buildEventData(CalendarEventsModel $eventModel, int $start, int $end): array
    {
        $args = func_get_args();
        $eventModel->recurring = false;

        $tmpEvents = [];
        $args[0] = &$tmpEvents;
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
        $eventData = $timeData[$eventKey];

        return $eventData;
    }

    /**
     * @param \Koertho\AdvancedRepeatingEventsBundle\Model\CalendarEventsModel $eventModel
     */
    private function applyRecurrences(array &$events, CalendarEventsModel $eventModel, array $repeatContext): void
    {
        $repeat = [
            'unit' => $eventModel->repeatEachUnit,
            'value' => $eventModel->repeatEachValue,
        ];

        if (!isset($repeat['unit'], $repeat['value']) || $repeat['value'] < 1) {
            return;
        }

        $rangeStart = $repeatContext['rangeStart'];
        $rangeEnd = $repeatContext['rangeEnd'];
        $noSpan = $repeatContext['noSpan'];
        $recurrenceLimit = $repeatContext['recurrenceLimit'];

        $count = 0;
        $eventStartTime = (new \DateTime())->setTimestamp($eventModel->startTime);
        $eventEndTime = (new \DateTime())->setTimestamp($eventModel->endTime);
        $modifier = '+ ' . $repeat['value'] . ' ' . $repeat['unit'];

        while ($eventEndTime < $rangeEnd) {
            ++$count;

            if (($eventModel->recurrences > 0 && $count > $eventModel->recurrences) || (null !== $recurrenceLimit && $count > $recurrenceLimit)) {
                break;
            }

            $eventStartTime->modify($modifier);
            $eventEndTime->modify($modifier);

            // Skip events outside the scope
            if ($eventEndTime < $rangeStart || $eventStartTime > $rangeEnd) {
                continue;
            }

            $this->addEvent(
                $events,
                $eventModel,
                $eventStartTime->getTimestamp(),
                $eventEndTime->getTimestamp(),
                $rangeEnd->getTimestamp(),
                $eventModel->pid,
                $noSpan,
                recursion: true
            );
        }
    }

    /**
     * @param \Koertho\AdvancedRepeatingEventsBundle\Model\CalendarEventsModel $eventModel
     */
    private function addRecurringInformation(array &$eventData, CalendarEventsModel $eventModel, int $start, int $end): void
    {
        if (!$eventModel->repeatEachUnit || !$eventModel->repeatEachValue) {
            return;
        }

        $domain = 'koertho_advanced_repeating_events';
        $dateFormat = Date::getNumericDateFormat();
        $startLabel = trim(($eventData['date'] ?? '') . ' ' . ($eventData['time'] ?? ''));

        if ('' === $startLabel) {
            $format = $eventModel->addTime ? Date::getNumericDatimFormat() : $dateFormat;
            $startLabel = Date::parse($format, $start);
        }

        $repeat = $this->buildRepeatLabel($eventModel, $start, $domain);
        $until = '';

        if ($eventModel->recurrences > 0) {
            $until = $this->translator->trans(
                'recurring.until.date',
                ['date' => Date::parse($dateFormat, (int) $eventModel->repeatEnd)],
                $domain
            );
        }

        $untilPart = '' !== $until ? ', ' . $until : '';

        if ($eventModel->recurrences > 0 && $end <= time()) {
            $recurring = $this->translator->trans(
                'recurring.message.ended',
                [
                    'repeat' => $repeat,
                    'until' => $untilPart,
                ],
                $domain
            );
        } else {
            $recurring = $this->translator->trans(
                'recurring.message.active',
                [
                    'repeat' => $repeat,
                    'until' => $untilPart,
                    'start_iso' => date('Y-m-d\TH:i:sP', $start),
                    'start_label' => $startLabel,
                ],
                $domain
            );
        }

        $eventData['recurring'] = $recurring;
        $eventData['until'] = $until;
    }

    /**
     * @param \Koertho\AdvancedRepeatingEventsBundle\Model\CalendarEventsModel $eventModel
     */
    private function buildRepeatLabel(CalendarEventsModel $eventModel, int $start, string $domain): string
    {
        $patternConfig = StringUtil::deserialize($eventModel->repeatPattern, true);
        $patternType = isset($patternConfig[0]) && \is_string($patternConfig[0]) ? $patternConfig[0] : null;
        $interval = $this->translator->trans(
            'recurring.interval.' . $eventModel->repeatEachUnit,
            ['count' => (int) $eventModel->repeatEachValue],
            $domain
        );
        $pattern = $this->buildRepeatPatternLabel($eventModel, $start, $domain);

        if ('' === $pattern) {
            return $interval;
        }

        if ($eventModel->repeatEachUnit === 'months' && 'dayOfWeek' === $patternType && 1 === (int) $eventModel->repeatEachValue) {
            return $this->translator->trans(
                'recurring.repeat.months.dayOfWeek.single',
                ['pattern' => $pattern],
                $domain
            );
        }

        return $interval . ' ' . $pattern;
    }

    /**
     * @param \Koertho\AdvancedRepeatingEventsBundle\Model\CalendarEventsModel $eventModel
     */
    private function buildRepeatPatternLabel(CalendarEventsModel $eventModel, int $start, string $domain): string
    {
        $pattern = StringUtil::deserialize($eventModel->repeatPattern, true);

        if ($eventModel->repeatEachUnit === 'weeks' && [] !== $pattern) {
            $weekdayLabels = [];

            foreach ($pattern as $weekday) {
                if (!\is_string($weekday)) {
                    continue;
                }

                $weekdayLabels[] = $this->translator->trans('recurring.weekday.' . $weekday, [], $domain);
            }

            if ([] === $weekdayLabels) {
                return '';
            }

            return $this->translator->trans(
                'recurring.pattern.weeks',
                ['days' => $this->joinWithConjunction($weekdayLabels, $domain)],
                $domain
            );
        }

        if ($eventModel->repeatEachUnit === 'months' && isset($pattern[0]) && \is_string($pattern[0])) {
            if ('dayOfMonth' === $pattern[0]) {
                return $this->translator->trans(
                    'recurring.pattern.months.dayOfMonth',
                    ['day' => (int) date('j', $start)],
                    $domain
                );
            }

            if ('dayOfWeek' === $pattern[0]) {
                $dayOfMonth = (int) date('j', $start);
                $occurrence = max(1, min(5, (int) ceil($dayOfMonth / 7)));
                $weekdayKey = match ((int) date('N', $start)) {
                    1 => 'monday',
                    2 => 'tuesday',
                    3 => 'wednesday',
                    4 => 'thursday',
                    5 => 'friday',
                    6 => 'saturday',
                    default => 'sunday',
                };

                return $this->translator->trans(
                    'recurring.pattern.months.dayOfWeek',
                    [
                        'ordinal' => $this->translator->trans('recurring.ordinal.' . $occurrence, [], $domain),
                        'weekday' => $this->translator->trans('recurring.weekday.' . $weekdayKey, [], $domain),
                    ],
                    $domain
                );
            }
        }

        return '';
    }

    /**
     * @param list<string> $items
     */
    private function joinWithConjunction(array $items, string $domain): string
    {
        $count = \count($items);

        if (0 === $count) {
            return '';
        }

        if (1 === $count) {
            return $items[0];
        }

        if (2 === $count) {
            return $items[0] . ' ' . $this->translator->trans('recurring.list.and', [], $domain) . ' ' . $items[1];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' ' . $this->translator->trans('recurring.list.and', [], $domain) . ' ' . $last;
    }
}
