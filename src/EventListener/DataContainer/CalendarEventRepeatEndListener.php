<?php

declare(strict_types=1);

namespace Koertho\AdvancedRepeatingEventsBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Koertho\AdvancedRepeatingEventsBundle\Recurrence\RecurrenceCalculatorFactory;

#[AsCallback(table: 'tl_calendar_events', target: 'config.onsubmit')]
final readonly class CalendarEventRepeatEndListener
{
    public function __construct(
        private Connection $connection,
        private RecurrenceCalculatorFactory $recurrenceCalculatorFactory,
    ) {
    }

    public function __invoke(DataContainer $dc): void
    {
        if (!$dc->id) {
            return;
        }

        $record = $this->connection->fetchAssociative(
            'SELECT areRecurring, rrule, startTime, endTime, repeatEnd FROM tl_calendar_events WHERE id = ?',
            [(int) $dc->id]
        );

        if (!\is_array($record)) {
            return;
        }

        $repeatEnd = $this->recurrenceCalculatorFactory->createForRawData(
            (bool) ($record['areRecurring'] ?? false),
            (string) ($record['rrule'] ?? ''),
            (int) ($record['startTime'] ?? 0),
            (int) ($record['endTime'] ?? 0)
        )?->resolveRepeatEnd() ?? 0;

        if ((int) ($record['repeatEnd'] ?? 0) === $repeatEnd) {
            return;
        }

        $this->connection->update(
            'tl_calendar_events',
            [
                'repeatEnd' => $repeatEnd,
            ],
            [
                'id' => (int) $dc->id,
            ]
        );
    }
}
