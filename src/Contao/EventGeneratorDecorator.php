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
    private ?int $recurrenceLimit = null;
    private \DateTimeInterface $rangeStart;
    private \DateTimeInterface $rangeEnd;
    /**
     * @var true
     */
    private bool $isGetAllEvents = false;

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
        $this->isGetAllEvents = true;
        $this->recurrenceLimit = $recurrenceLimit;
        $this->rangeStart = $rangeStart;
        $this->rangeEnd = $rangeEnd;
        $event = parent::getAllEvents(...func_get_args());
        $this->isGetAllEvents = false;
        return $event;
    }

    /**
     * @param \Koertho\AdvancedRepeatingEventsBundle\Model\CalendarEventsModel $eventModel
     */
    public function addEvent(array &$events, CalendarEventsModel $eventModel, int $start, int $end, int $rangeEnd, int $calendar, bool $noSpan): void
    {
        if (!$eventModel->recurring) {
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

//        $this->applyRecurrences($events, $eventModel, $noSpan);
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
    private function applyRecurrences(array &$events, CalendarEventsModel $eventModel, int $calendar, bool $noSpan): void
    {
        // run this only when getAllEvents is called since this is not needed when addEvent is called for a single event
        if (!$this->isGetAllEvents) {
            return;
        }

        $repeat = [
            'unit' => $eventModel->repeatEachUnit,
            'value' => $eventModel->repeatEachValue,
        ];

        if (!isset($repeat['unit'], $repeat['value']) || $repeat['value'] < 1) {
            return;
        }

        $count = 0;
        $eventStartTime = (new \DateTime())->setTimestamp($eventModel->startTime);
        $eventEndTime = (new \DateTime())->setTimestamp($eventModel->endTime);
        $modifier = '+ ' . $repeat['value'] . ' ' . $repeat['unit'];
        $rangeStart = $this->rangeStart;
        $rangeEnd = $this->rangeEnd;

        while ($eventEndTime < $rangeEnd) {
            ++$count;

            if (($eventModel->recurrences > 0 && $count > $eventModel->recurrences) || (null !== $this->recurrenceLimit && $count > $this->recurrenceLimit)) {
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
                $calendar,
                $noSpan
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

        $range = [
            'unit' => $eventModel->repeatEachUnit,
            'value' => $eventModel->repeatEachValue,
        ];
        $recurring = '';
        $until = '';


        $formattedDate = $eventData['date'];
        $formattedTime = $eventData['time'];

        if (1 === $range['value']) {
            $repeat = $this->translator->trans('MSC.cal_single_' . $range['unit'], [], 'contao_default');
        } else {
            $repeat = $this->translator->trans('MSC.cal_multiple_' . $range['unit'], [$range['value']], 'contao_default');
        }

        if ($eventModel->recurrences > 0) {
            $until = ' ' . $this->translator->trans('MSC.cal_until', [Date::parse($page->dateFormat, $eventModel->repeatEnd)], 'contao_default');
        }

        if ($eventModel->recurrences > 0 && $end < time()) {
            $recurring = $this->translator->trans('MSC.cal_repeat_ended', [$repeat, $until], 'contao_default');
        } elseif ($eventModel->addTime) {
            $recurring = $this->translator->trans(
                'MSC.cal_repeat',
                [$repeat, $until, date('Y-m-d\TH:i:sP', $start), $formattedDate . ($formattedTime ? ' ' . $formattedTime : '')],
                'contao_default'
            );
        } else {
            $recurring = $this->translator->trans(
                'MSC.cal_repeat',
                [$repeat, $until, date('Y-m-d', $start), $formattedDate],
                'contao_default'
            );
        }


        $eventData['recurring'] = $recurring;
        $eventData['until'] = $until;
    }

}