<?php

declare(strict_types=1);

namespace Koertho\AdvancedRepeatingEventsBundle\Command;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Koertho\AdvancedRepeatingEventsBundle\Recurrence\RecurrenceCalculatorFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'are:migrate-recurrences',
    description: 'Migrates recurring rules in tl_calendar_events to RRULE format.'
)]
final class MigrateRecurringRulesCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RecurrenceCalculatorFactory $recurrenceCalculatorFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show changes without writing to database.')
            ->addOption('overwrite-existing', null, InputOption::VALUE_NONE, 'Overwrite existing RRULE values.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit number of processed records.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $overwriteExisting = (bool) $input->getOption('overwrite-existing');
        $limitOption = $input->getOption('limit');

        if (!$this->hasRequiredColumns()) {
            $io->error('Required columns "rrule" and/or "areRecurring" are missing in tl_calendar_events.');

            return Command::FAILURE;
        }

        if (null !== $limitOption && (!is_numeric($limitOption) || (int) $limitOption < 1)) {
            $io->error('The --limit option must be a positive integer.');

            return Command::INVALID;
        }

        $queryBuilder = $this->connection->createQueryBuilder();
        $queryBuilder
            ->select('id', 'startTime', 'endTime', 'repeatEach', 'repeatEnd', 'recurrences', 'rrule')
            ->from('tl_calendar_events')
            ->where('recurring = 1')
            ->orderBy('id', 'ASC');

        if (null !== $limitOption) {
            $queryBuilder->setMaxResults((int) $limitOption);
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        $checked = 0;
        $migratable = 0;
        $written = 0;
        $skippedExisting = 0;
        $invalid = 0;
        $invalidIds = [];

        foreach ($rows as $row) {
            ++$checked;
            $rrule = $this->buildRrule($row);

            if (null === $rrule) {
                ++$invalid;
                $invalidIds[] = (int) $row['id'];

                continue;
            }

            ++$migratable;
            $currentRrule = trim((string) ($row['rrule'] ?? ''));

            if (!$overwriteExisting && '' !== $currentRrule) {
                ++$skippedExisting;

                continue;
            }

            ++$written;

            if ($dryRun) {
                continue;
            }

            $this->connection->update(
                'tl_calendar_events',
                [
                    'rrule' => $rrule,
                    'areRecurring' => true,
                    'repeatEnd' => $this->recurrenceCalculatorFactory->createForRawData(
                        true,
                        $rrule,
                        (int) ($row['startTime'] ?? 0),
                        (int) ($row['endTime'] ?? 0)
                    )?->resolveRepeatEnd() ?? 0,
                ],
                ['id' => (int) $row['id']]
            );
        }

        $io->title('Recurring rules migration');
        $io->listing([
            sprintf('Mode: %s', $dryRun ? 'dry-run (no writes)' : 'write'),
            sprintf('Candidates checked: %d', $checked),
            sprintf('Migratable: %d', $migratable),
            sprintf('%s: %d', $dryRun ? 'Would write' : 'Written', $written),
            sprintf('Skipped (existing rrule): %d', $skippedExisting),
            sprintf('Skipped (invalid legacy rule): %d', $invalid),
        ]);

        if ([] !== $invalidIds) {
            $previewIds = array_slice($invalidIds, 0, 20);
            $io->warning(sprintf('Invalid legacy rules in event IDs: %s', implode(', ', $previewIds)));
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildRrule(array $row): ?string
    {
        $repeatEach = StringUtil::deserialize((string) ($row['repeatEach'] ?? ''), true);

        if (!is_array($repeatEach)) {
            return null;
        }

        $unit = $repeatEach['unit'] ?? null;
        $interval = (int) ($repeatEach['value'] ?? 0);

        if (!is_string($unit) || $interval < 1) {
            return null;
        }

        $frequencies = [
            'days' => 'DAILY',
            'weeks' => 'WEEKLY',
            'months' => 'MONTHLY',
            'years' => 'YEARLY',
        ];

        $frequency = $frequencies[$unit] ?? null;

        if (null === $frequency) {
            return null;
        }

        $parts = [
            sprintf('FREQ=%s', $frequency),
            sprintf('INTERVAL=%d', $interval),
        ];

        $recurrences = (int) ($row['recurrences'] ?? 0);

        if ($recurrences > 0) {
            $parts[] = sprintf('COUNT=%d', $recurrences);
        }

        $repeatEnd = (int) ($row['repeatEnd'] ?? 0);

        if ($repeatEnd > 0) {
            $parts[] = 'UNTIL='.$this->formatUntilFromLocalEndDate($repeatEnd);
        }

        return implode(';', $parts);
    }

    private function formatUntilFromLocalEndDate(int $timestamp): string
    {
        $timezone = new \DateTimeZone(date_default_timezone_get());

        $localEndDate = \DateTimeImmutable::createFromTimestamp($timestamp)
            ->setTimezone($timezone)
            ->setTime(23, 59, 59);

        return $localEndDate
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');
    }

    private function hasRequiredColumns(): bool
    {
        try {
            $columns = $this->connection->createSchemaManager()->listTableColumns('tl_calendar_events');
        } catch (\Throwable) {
            return false;
        }

        $normalizedColumns = array_change_key_case($columns, \CASE_LOWER);

        return isset($normalizedColumns['rrule'], $normalizedColumns['arerecurring']);
    }
}
