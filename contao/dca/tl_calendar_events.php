<?php

use Contao\CoreBundle\DataContainer\PaletteManipulator;

$dca = &$GLOBALS['TL_DCA']['tl_calendar_events'];

$dca['palettes']['__selector__'][] = 'areRecurring';
$dca['subpalettes']['areRecurring'] = 'rrule';

PaletteManipulator::create()
    ->addField('areRecurring', 'recurring_legend', PaletteManipulator::POSITION_APPEND)
    ->removeField('recurring')
    ->applyToPalette('default', 'tl_calendar_events');

$dca['fields']['areRecurring'] = [
    'inputType' => 'checkbox',
    'eval' => ['submitOnChange' => true],
    'sql' => ['type' => 'boolean', 'default' => false]
];

$dca['fields']['rrule'] = [
    'exclude' => true,
    'inputType' => 'rruleBuilder',
    'eval' => [
        'decodeEntities' => true,
        'tl_class' => 'w50 clr',
//        'basicEntities' => true,
    ],
    'sql' => ['type' => 'text', 'default' => ''],
];
