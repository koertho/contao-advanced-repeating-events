<?php

declare(strict_types=1);

namespace Koertho\AdvancedRepeatingEventsBundle\Recurrence;

use Contao\CalendarEventsModel;
use Recurr\Exception\InvalidRRule;
use Recurr\Rule;

final class RecurrenceCalculatorFactory
{
    public function createForEvent(CalendarEventsModel $event): ?RecurrenceCalculator
    {
        if (!$event->areRecurring) {
            return null;
        }

        $normalizedRrule = $this->normalizeRrule((string) $event->rrule);

        if (null === $normalizedRrule) {
            return null;
        }

        $timezone = new \DateTimeZone((string) date_default_timezone_get());
        $startTs = (int) $event->startTime;
        $endTs = (int) $event->endTime;
        $durationInSeconds = max(0, $endTs - $startTs);
        $eventStart = \DateTimeImmutable::createFromTimestamp($startTs)->setTimezone($timezone);
        $eventEnd = \DateTimeImmutable::createFromTimestamp($endTs)->setTimezone($timezone);

        try {
            $rule = new Rule($normalizedRrule, $eventStart, $eventEnd, $timezone->getName());
        } catch (InvalidRRule) {
            return null;
        }

        return new RecurrenceCalculator($rule, $timezone, $startTs, $durationInSeconds);
    }

    private function normalizeRrule(string $raw): ?string
    {
        $value = trim($raw);

        if ('' === $value) {
            return null;
        }

        if (str_starts_with(strtoupper($value), 'RRULE:')) {
            $value = trim(substr($value, 6));
        }

        if (!str_contains(strtoupper($value), 'FREQ=')) {
            return null;
        }

        return preg_replace('/\s+/', '', $value) ?: null;
    }
}
