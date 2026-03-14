<?php

declare(strict_types=1);

namespace Koertho\AdvancedRepeatingEventsBundle\Recurrence;

use Recurr\Exception\InvalidWeekday;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use Recurr\Transformer\Constraint\AfterConstraint;
use Recurr\Transformer\Constraint\BeforeConstraint;
use Recurr\Transformer\Constraint\BetweenConstraint;
use Recurr\Transformer\TextTransformer;
use Recurr\Transformer\Translator;
use Recurr\Transformer\TranslatorInterface;

final readonly class RecurrenceCalculator
{
    /**
     * @internal Use Factory to create instances
     */
    public function __construct(
        private Rule $rule,
        private \DateTimeZone $timezone,
        private int $originalStartTs,
        private int $durationInSeconds,
        private string $lang,
    ) {
    }

    /**
     * @return list<array{start: int, end: int}>
     */
    public function listOccurrencesInRange(?\DateTimeInterface $rangeStart = null, ?\DateTimeInterface $rangeEnd = null, ?int $limit = null, bool $excludeOriginal = true): array
    {
        if (null !== $limit && $limit <= 0) {
            return [];
        }

        if ($rangeStart && $rangeEnd) {
            if ($rangeEnd < $rangeStart) {
                return [];
            }
        }

        $constraint = null;
        if ($rangeStart && $rangeEnd) {
            $constraint = new BetweenConstraint($rangeStart, $rangeEnd, true);
        } elseif ($rangeStart && !$rangeEnd) {
            $constraint = new AfterConstraint($rangeStart, true);
        } elseif (!$rangeStart && $rangeEnd) {
            $constraint = new BeforeConstraint($rangeEnd, true);
        }

        $config = new ArrayTransformerConfig();

        if (null !== $limit && $limit > 0) {
            $config->setVirtualLimit($limit + 1);
        }

        $transformer = new ArrayTransformer($config);
        $occurrences = [];

        try {
            foreach ($transformer->transform($this->rule, $constraint) as $recurrence) {
                $startTs = $recurrence->getStart()->getTimestamp();
                $endTs = $recurrence->getEnd()->getTimestamp();

                if ($excludeOriginal && $startTs <= $this->originalStartTs) {
                    continue;
                }

                $occurrences[] = [
                    'start' => $startTs,
                    'end' => $endTs,
                ];

                if (null !== $limit && \count($occurrences) >= $limit) {
                    break;
                }
            }
        } catch (InvalidWeekday) {
            return [];
        }

        return $occurrences;
    }

    /**
     * @param int  $now         Unix timestamp. If 0, the current time will be used.
     * @param bool $hideRunning if true, currently running occurrences will be ignored and the next upcoming occurrence will be returned
     *
     * @return array{start: int, end: int}|null
     */
    public function resolveCurrentOrUpcomingOccurrence(int $now = 0, bool $hideRunning = false): ?array
    {
        if (0 === $now) {
            $now = time();
        }
        $lookupStartTs = $now - max(0, $this->durationInSeconds) - 1;
        $lookupEndTs = $now + 315360000; // 10 years

        $config = new ArrayTransformerConfig();

        $transformer = new ArrayTransformer($config);
        $constraint = new BetweenConstraint(
            \DateTimeImmutable::createFromTimestamp($lookupStartTs)->setTimezone($this->timezone),
            \DateTimeImmutable::createFromTimestamp($lookupEndTs)->setTimezone($this->timezone),
            true
        );

        try {
            foreach ($transformer->transform($this->rule, $constraint) as $recurrence) {
                $startTs = $recurrence->getStart()->getTimestamp();
                $endTs = $recurrence->getEnd()->getTimestamp();
                $comparisonTs = $hideRunning ? $startTs : $endTs;

                if ($comparisonTs < $now) {
                    continue;
                }

                return [
                    'start' => $startTs,
                    'end' => $endTs,
                ];
            }
        } catch (InvalidWeekday) {
            return null;
        }

        return null;
    }

    /**
     * @return array{start: int, end: int}|null
     */
    public function resolveLastOccurrence(): ?array
    {
        if (!$this->rule->getUntil() && !$this->rule->getCount()) {
            return null;
        }

        $config = new ArrayTransformerConfig();

        if ($this->rule->getCount()) {
            $config->setVirtualLimit((int) $this->rule->getCount());
        }

        $transformer = new ArrayTransformer($config);
        $lastOccurrence = null;

        try {
            foreach ($transformer->transform($this->rule) as $recurrence) {
                $lastOccurrence = [
                    'start' => $recurrence->getStart()->getTimestamp(),
                    'end' => $recurrence->getEnd()->getTimestamp(),
                ];
            }
        } catch (InvalidWeekday) {
            return null;
        }

        return $lastOccurrence;
    }

    public function resolveRepeatEnd(): int
    {
        return $this->resolveLastOccurrence()['end'] ?? 0;
    }

    public function toText(?string $lang = null, ?TranslatorInterface $translator = null): string
    {
        $textTransformer = new TextTransformer(
            $translator ?: new Translator($lang ?: $this->lang)
        );

        return $textTransformer->transform($this->rule);
    }

    public function toSchemaOrgData(): array
    {
        $jsonLd = [
            '@type' => 'Schedule',
            'startDate' => $this->rule->getStartDate()->format('Y-m-d'),
        ];

        if ($this->rule->getUntil()) {
            $jsonLd['endDate'] = $this->rule->getUntil()->format('Y-m-d');
        }

        // repeatFrequency als ISO 8601 Duration
        $interval = $this->rule->getInterval();
        $freqText = $this->rule->getFreqAsText();

        $jsonLd['repeatFrequency'] = match ($freqText) {
            'YEARLY' => "P{$interval}Y",
            'MONTHLY' => "P{$interval}M",
            'WEEKLY' => "P{$interval}W",
            'DAILY' => "P{$interval}D",
            'HOURLY' => "PT{$interval}H",
            'MINUTELY' => "PT{$interval}M",
            'SECONDLY' => "PT{$interval}S",
            default => null,
        };

        if (null === $jsonLd['repeatFrequency']) {
            unset($jsonLd['repeatFrequency']);
        }

        // Optional: byDay für wöchentliche Wiederholungen
        $byDay = $this->rule->getByDay();
        if (!empty($byDay)) {
            $dayMap = [
                'MO' => 'Monday',
                'TU' => 'Tuesday',
                'WE' => 'Wednesday',
                'TH' => 'Thursday',
                'FR' => 'Friday',
                'SA' => 'Saturday',
                'SU' => 'Sunday',
            ];
            $jsonLd['byDay'] = array_map(
                fn ($day) => $dayMap[$day] ?? $day,
                $byDay
            );
        }

        if ($this->rule->getCount()) {
            $jsonLd['repeatCount'] = $this->rule->getCount();
        }

        return $jsonLd;
    }
}
