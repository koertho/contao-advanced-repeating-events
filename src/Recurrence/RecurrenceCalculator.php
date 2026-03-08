<?php

declare(strict_types=1);

namespace Koertho\AdvancedRepeatingEventsBundle\Recurrence;

use Recurr\Exception\InvalidWeekday;
use Recurr\Rule;
use Recurr\Transformer\ArrayTransformer;
use Recurr\Transformer\ArrayTransformerConfig;
use Recurr\Transformer\Constraint\BetweenConstraint;

final readonly class RecurrenceCalculator
{
    public function __construct(
        private Rule          $rule,
        private \DateTimeZone $timezone,
        private int           $originalStartTs,
        private int           $durationInSeconds,
    ) {}

    /**
     * @return list<array{start: int, end: int}>
     */
    public function listOccurrencesInRange(\DateTimeInterface $rangeStart, \DateTimeInterface $rangeEnd, ?int $limit = null, bool $excludeOriginal = true): array
    {
        if (null !== $limit && $limit <= 0) {
            return [];
        }

        $rangeStartTs = $rangeStart->getTimestamp();
        $rangeEndTs = $rangeEnd->getTimestamp();

        if ($rangeEndTs < $rangeStartTs) {
            return [];
        }

        $config = new ArrayTransformerConfig();

        if (null !== $limit && $limit > 0) {
            $config->setVirtualLimit($limit + 1);
        } else {
            $config->setVirtualLimit(10000);
        }

        $transformer = new ArrayTransformer($config);
        $constraint = new BetweenConstraint(
            \DateTimeImmutable::createFromTimestamp($rangeStartTs)->setTimezone($this->timezone),
            \DateTimeImmutable::createFromTimestamp($rangeEndTs)->setTimezone($this->timezone),
            true
        );

        $occurrences = [];

        try {
            foreach ($transformer->transform($this->rule, $constraint) as $recurrence) {
                $startTs = $recurrence->getStart()->getTimestamp();
                $endTs = $recurrence->getEnd()->getTimestamp();

                if ($excludeOriginal && $startTs <= $this->originalStartTs) {
                    continue;
                }

                $occurrences[] = ['start' => $startTs, 'end' => $endTs];

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
     * @return array{start: int, end: int}|null
     */
    public function resolveCurrentOrUpcomingOccurrence(int $nowTs, bool $hideRunning = false): ?array
    {
        $lookupStartTs = $nowTs - max(0, $this->durationInSeconds) - 1;
        $lookupEndTs = $nowTs + 315360000; // 10 years

        $config = new ArrayTransformerConfig();
        $config->setVirtualLimit(10000);

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

                if ($comparisonTs < $nowTs) {
                    continue;
                }

                return ['start' => $startTs, 'end' => $endTs];
            }
        } catch (InvalidWeekday) {
            return null;
        }

        return null;
    }
}
