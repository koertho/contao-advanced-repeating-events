<?php

declare(strict_types=1);

namespace Koertho\AdvancedRepeatingEventsBundle\Contao;

use Contao\CalendarEventsModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Date;
use Koertho\AdvancedRepeatingEventsBundle\Recurrence\RecurrenceCalculatorFactory;

final readonly class RecurringEventLoader
{
    public function __construct(
        private ContaoFramework $contaoFramework,
        private RecurrenceCalculatorFactory $recurrenceCalculatorFactory,
    ) {
    }

    /**
     * @return list<array{model: CalendarEventsModel, start: int, end: int, calendar: int}>
     */
    public function loadOccurrences(
        array $calendars,
        \DateTimeInterface $rangeStart,
        \DateTimeInterface $rangeEnd,
        ?bool $featured = null,
        ?int $recurrenceLimit = null,
    ): array {
        $calendarIds = array_values(array_unique(array_filter(array_map('\intval', $calendars))));

        if ([] === $calendarIds) {
            return [];
        }

        $rangeStart = \DateTimeImmutable::createFromInterface($rangeStart)->setTime(0, 0);
        $candidateModels = $this->findCandidateEvents(
            $calendarIds,
            $rangeStart->getTimestamp(),
            $rangeEnd->getTimestamp(),
            $featured
        );

        if ([] === $candidateModels) {
            return [];
        }

        $occurrences = [];

        foreach ($candidateModels as $eventModel) {
            $occurrences[] = [
                'model' => $eventModel,
                'start' => (int) $eventModel->startTime,
                'end' => (int) $eventModel->endTime,
                'calendar' => (int) $eventModel->pid,
            ];

            if ($eventModel->areRecurring) {
                array_push(
                    $occurrences,
                    ...$this->expandRecurrences($eventModel, $rangeStart, $rangeEnd, $recurrenceLimit)
                );
            }
        }

        return $occurrences;
    }

    /**
     * @param list<int> $calendarIds
     * @return list<CalendarEventsModel>
     */
    private function findCandidateEvents(
        array $calendarIds,
        int $rangeStart,
        int $rangeEnd,
        ?bool $featured = null,
    ): array {
        $table = 'tl_calendar_events';
        $eventModelAdapter = $this->contaoFramework->getAdapter(CalendarEventsModel::class);

        $rangeOverlap = "(($table.startTime>=$rangeStart AND $table.startTime<=$rangeEnd) OR ($table.endTime>=$rangeStart AND $table.endTime<=$rangeEnd) OR ($table.startTime<=$rangeStart AND $table.endTime>=$rangeEnd))";
        $advancedRecurring = "($table.areRecurring=1 AND $table.startTime<=$rangeEnd AND $table.rrule IS NOT NULL AND $table.rrule!='')";

        $columns = [
            "$table.pid IN(".implode(',', $calendarIds).") AND ($rangeOverlap OR $advancedRecurring)",
        ];

        if (true === $featured) {
            $columns[] = "$table.featured=1";
        } elseif (false === $featured) {
            $columns[] = "$table.featured=0";
        }

        $time = Date::floorToMinute();
        $columns[] = "$table.published=1 AND ($table.start='' OR $table.start<=$time) AND ($table.stop='' OR $table.stop>$time)";

        $collection = $eventModelAdapter->findBy($columns, [], ['order' => "$table.startTime"]);

        if (null === $collection) {
            return [];
        }

        $models = [];

        foreach ($collection as $eventModel) {
            $models[] = $eventModel;
        }

        return $models;
    }

    /**
     * @return list<array{model: CalendarEventsModel, start: int, end: int, calendar: int}>
     */
    private function expandRecurrences(
        CalendarEventsModel $eventModel,
        \DateTimeInterface $rangeStart,
        \DateTimeInterface $rangeEnd,
        ?int $recurrenceLimit = null,
    ): array {
        $calculator = $this->recurrenceCalculatorFactory->createForEvent($eventModel);

        if (null === $calculator) {
            return [];
        }

        $occurrences = [];

        foreach ($calculator->listOccurrencesInRange($rangeStart, $rangeEnd, $recurrenceLimit, true) as $occurrence) {
            $occurrences[] = [
                'model' => $eventModel,
                'start' => $occurrence['start'],
                'end' => $occurrence['end'],
                'calendar' => (int) $eventModel->pid,
            ];
        }

        return $occurrences;
    }
}
