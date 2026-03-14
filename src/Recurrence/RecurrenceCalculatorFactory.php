<?php

declare(strict_types=1);

namespace Koertho\AdvancedRepeatingEventsBundle\Recurrence;

use Contao\CalendarEventsModel;
use Recurr\Exception\InvalidRRule;
use Recurr\Rule;
use Symfony\Contracts\Translation\TranslatorInterface;

final class RecurrenceCalculatorFactory
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    public function createForEvent(CalendarEventsModel $event): ?RecurrenceCalculator
    {
        return $this->createForRawData(
            (bool) $event->areRecurring,
            (string) $event->rrule,
            (int) $event->startTime,
            (int) $event->endTime
        );
    }

    public function createForRawData(bool $areRecurring, string $rrule, int $startTs, int $endTs): ?RecurrenceCalculator
    {
        if (!$areRecurring) {
            return null;
        }

        $normalizedRrule = $this->normalizeRrule($rrule);

        if (null === $normalizedRrule) {
            return null;
        }

        $timezone = new \DateTimeZone((string) date_default_timezone_get());
        $durationInSeconds = max(0, $endTs - $startTs);
        $eventStart = \DateTimeImmutable::createFromTimestamp($startTs)->setTimezone($timezone);
        $eventEnd = \DateTimeImmutable::createFromTimestamp($endTs)->setTimezone($timezone);

        try {
            $rule = new Rule($normalizedRrule, $eventStart, $eventEnd, $timezone->getName());
        } catch (InvalidRRule) {
            return null;
        }

        return new RecurrenceCalculator($rule, $timezone, $startTs, $durationInSeconds, $this->translator->getLocale());
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
