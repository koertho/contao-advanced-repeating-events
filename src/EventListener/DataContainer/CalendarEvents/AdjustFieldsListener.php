<?php

namespace Koertho\AdvancedRepeatingEventsBundle\EventListener\DataContainer\CalendarEvents;

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;


class AdjustFieldsListener
{
    #[AsCallback(table: 'tl_calendar_events', target: 'config.onload')]
    public function onLoad(?DataContainer $dc = null): void
    {
        if (!$dc?->id) {
            return;
        }

        $row = $dc->getCurrentRecord();
        if (null === $row || 1 !== (int)$row['recurring']) {
            return;
        }

        $repeatUnit = $row['repeatEachUnit'] ?? null;
        $dca = &$GLOBALS['TL_DCA']['tl_calendar_events'];

        switch ($repeatUnit) {
            case 'weeks':
                $dca['fields']['repeatPattern']['inputType'] = 'checkbox';
                $dca['fields']['repeatPattern']['eval']['multiple'] = true;
                break;
            case 'months':
                $dca['fields']['repeatPattern']['inputType'] = 'radio';
                break;
            default:
                PaletteManipulator::create()
                    ->removeField('repeatPattern')
                    ->applyToSubpalette('recurring', 'tl_calendar_events');

        }
    }

    #[AsCallback(table: 'tl_calendar_events', target: 'fields.repeatPattern.options')]
    public function onOptions(?DataContainer $dc): array
    {

        if (!$dc) {
            return [];
        }

        $row = $dc->getCurrentRecord();
        if (1 !== (int)$row['recurring']) {
            return [];
        }

        $repeatUnit = $row['repeatEachUnit'] ?? null;

        if ($repeatUnit === 'weeks') {
            return [
                'monday',
                'tuesday',
                'wednesday',
                'thursday',
                'friday',
                'saturday',
                'sunday',
            ];
        }

        if ($repeatUnit === 'months') {
            return [
                'dayOfMonth',
                'dayOfWeek',
            ];
        }

        return [];
    }
}