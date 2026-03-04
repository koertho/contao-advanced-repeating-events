<?php

namespace Koertho\AdvancedRepeatingEventsBundle\Recurrence;

use Contao\CalendarEventsModel;
use Recurr\Rule;

class RecurrenceCalculator
{
    private Rule $rule;

    public function __construct(
        /** @var \Koertho\AdvancedRepeatingEventsBundle\Model\CalendarEventsModel */
        private readonly CalendarEventsModel $model,
    ) {
        if (!$this->model->areRecurring || empty($this->model->rrule)) {
            throw new \RuntimeException("Event is not recurring or has no rrule");
        }

        $eventStart = \DateTime::createFromTimestamp($this->model->startTime);
        $eventEnd = $this->model->endTime ?? null;
        $timezone = new \DateTimeZone(date_default_timezone_get());

        $this->rule = Rule($this->model->rrule, $eventStart, $eventEnd, $timezone->getName());
    }
}